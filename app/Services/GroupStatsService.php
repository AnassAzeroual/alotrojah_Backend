<?php

namespace App\Services;

use App\Models\Group;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregates for the groups overview / group detail pages.
 * Raw DB queries bypass CenterScope, so every query is bound to group ids
 * that the caller already resolved through the scoped Group model.
 * All metrics follow the CURRENT season (academic_seasons.is_current = 1).
 */
class GroupStatsService
{
    /**
     * Current season for a center lane: the center's own current season,
     * falling back to the legacy shared (NULL-center) one. A null center is
     * the global/admin view, which keeps the first current season.
     */
    public function currentSeason(?int $centerId = null): ?object
    {
        $query = DB::table('academic_seasons')->where('is_current', 1);
        if ($centerId !== null) {
            $query->where(fn ($lane) => $lane->where('center_id', $centerId)->orWhereNull('center_id'))
                ->orderByRaw('`center_id` IS NULL');
        }

        return $query->orderBy('id')->first(['id', 'name']);
    }

    /**
     * @param  Collection<int, Group>  $groups  with teacher loaded
     */
    public function overview(Collection $groups, ?int $seasonId): array
    {
        $ids = $groups->pluck('id')->all();

        $breakdown = ['status' => [], 'gender' => [], 'student_type' => [], 'memorization_mode' => []];
        $perGroupBreakdown = [];
        $total = 0;

        if ($ids) {
            $rows = DB::table('students')->whereIn('group_id', $ids)
                ->selectRaw('group_id, status, gender, student_type, memorization_mode, COUNT(*) AS n')
                ->groupBy('group_id', 'status', 'gender', 'student_type', 'memorization_mode')
                ->get();
            foreach ($rows as $r) {
                $total += $r->n;
                foreach (array_keys($breakdown) as $dim) {
                    $key = $r->{$dim} ?? 'unknown';
                    $breakdown[$dim][$key] = ($breakdown[$dim][$key] ?? 0) + $r->n;
                    $perGroupBreakdown[$r->group_id][$dim][$key] =
                        ($perGroupBreakdown[$r->group_id][$dim][$key] ?? 0) + $r->n;
                }
            }
        }

        $studentIds = $ids ? DB::table('students')->whereIn('group_id', $ids)->pluck('id')->all() : [];

        $scoreRows = $studentIds && $seasonId
            ? DB::query()->fromSub(ReportingQueries::sessionTotals($studentIds, $seasonId), 't')
                ->join('students as s', 's.id', '=', 't.student_id')
                ->selectRaw('s.group_id, ROUND(AVG(t.total_score),2) AS avg_score, SUM(t.total_score) AS sum_score, COUNT(*) AS n')
                ->groupBy('s.group_id')->get()
            : collect();

        $attRows = $studentIds && $seasonId
            ? DB::table('attendance_totals as a')
                ->join('students as s', 's.id', '=', 'a.student_id')
                ->whereIn('a.student_id', $studentIds)->where('a.season_id', $seasonId)
                ->selectRaw('s.group_id, ROUND(100*SUM(a.present_count+a.late_count)/SUM(a.total_sessions),1) AS att,
                    SUM(a.present_count+a.late_count) AS present_late, SUM(a.total_sessions) AS n')
                ->groupBy('s.group_id')->get()
            : collect();

        $thumnRows = $studentIds && $seasonId
            ? DB::table('memorization_logs as m')
                ->join('students as s', 's.id', '=', 'm.student_id')
                ->whereIn('m.student_id', $studentIds)->where('m.season_id', $seasonId)
                ->selectRaw('s.group_id, ROUND(SUM(m.thumn_amount),2) AS thumn')
                ->groupBy('s.group_id')->get()
            : collect();

        $score = $scoreRows->mapWithKeys(fn ($r) => [$r->group_id => (float) $r->avg_score])->all();
        $att = $attRows->mapWithKeys(fn ($r) => [$r->group_id => (float) $r->att])->all();
        $thumn = $thumnRows->mapWithKeys(fn ($r) => [$r->group_id => (float) $r->thumn])->all();

        $scoreN = (int) $scoreRows->sum('n');
        $attN = (int) $attRows->sum('n');

        $rows = $groups->map(function ($g) use ($score, $att, $thumn, $perGroupBreakdown) {
            $count = (int) ($g->students_count ?? 0);

            return [
                'id' => $g->id,
                'name' => $g->name,
                'center_id' => $g->center_id,
                'teacher' => $g->teacher ? [
                    'id' => $g->teacher->id,
                    'full_name' => $g->teacher->full_name,
                    'phone' => $g->teacher->phone,
                    'teacher_type' => $g->teacher->teacher_type,
                ] : null,
                'academic_year' => $g->academic_year,
                'schedule_days' => $g->schedule_days,
                'is_active' => (bool) $g->is_active,
                'capacity' => $g->capacity,
                'students_count' => $count,
                'fill_pct' => $g->capacity ? round(100 * $count / $g->capacity, 1) : null,
                'avg_score' => $score[$g->id] ?? null,
                'attendance_pct' => $att[$g->id] ?? null,
                'thumn_total' => $thumn[$g->id] ?? 0.0,
                'breakdown' => $perGroupBreakdown[$g->id] ?? new \stdClass,
            ];
        })->values()->all();

        $capacity = (int) $groups->sum(fn ($g) => (int) $g->capacity);

        return [
            'season' => $seasonId ? ['id' => $seasonId] : null,
            'kpis' => [
                'groups' => $groups->count(),
                'students' => $total,
                'avg_score' => $scoreN > 0 ? round((float) $scoreRows->sum('sum_score') / $scoreN, 2) : null,
                'attendance_pct' => $attN > 0 ? round(100 * (float) $attRows->sum('present_late') / $attN, 1) : null,
                'fill_pct' => $capacity > 0 ? round(100 * $total / $capacity, 1) : null,
            ],
            'breakdown' => $breakdown,
            'groups' => $rows,
        ];
    }

    /** One group: info + every student with season metrics + weekly trend. */
    public function detail(Group $group, ?int $seasonId): array
    {
        $students = DB::table('students')->where('group_id', $group->id)->orderBy('full_name')
            ->get(['id', 'full_name', 'gender', 'status', 'student_type', 'memorization_mode',
                'start_hizb', 'birth_date', 'enrollment_date', 'level_id', 'notes']);
        $sid = $students->pluck('id')->all();

        $score = $sid && $seasonId
            ? DB::query()->fromSub(ReportingQueries::sessionTotals($sid, $seasonId), 't')
                ->selectRaw('t.student_id, ROUND(AVG(t.total_score),2) AS v')
                ->groupBy('t.student_id')->pluck('v', 'student_id')
            : collect();

        $att = $sid && $seasonId ? DB::table('attendance_totals')->whereIn('student_id', $sid)
            ->where('season_id', $seasonId)
            ->selectRaw('student_id, ROUND(100*SUM(present_count+late_count)/SUM(total_sessions),1) AS v')
            ->groupBy('student_id')->pluck('v', 'student_id') : collect();

        $thumn = $sid && $seasonId ? DB::table('memorization_logs')->whereIn('student_id', $sid)
            ->where('season_id', $seasonId)->selectRaw('student_id, ROUND(SUM(thumn_amount),2) AS v')
            ->groupBy('student_id')->pluck('v', 'student_id') : collect();

        $trend = $sid && $seasonId ? DB::query()->fromSub(ReportingQueries::sessionTotals($sid, $seasonId), 't')
            ->join('weeks as w', 'w.id', '=', 't.week_id')
            ->selectRaw('w.week_number_global AS week, ROUND(AVG(t.total_score),2) AS avg_score')
            ->groupBy('w.week_number_global')->orderBy('w.week_number_global')->get() : collect();

        $summary = $this->overview(collect([$group]), $seasonId);

        return [
            'group' => $summary['groups'][0],
            'breakdown' => $summary['breakdown'],
            'trend' => $trend->map(static fn ($r) => [
                'week' => (int) $r->week,
                'avg_score' => (float) $r->avg_score,
            ])->values()->all(),
            'students' => $students->map(fn ($s) => [
                'id' => $s->id,
                'full_name' => $s->full_name,
                'gender' => $s->gender,
                'status' => $s->status,
                'student_type' => $s->student_type,
                'memorization_mode' => $s->memorization_mode,
                'start_hizb' => $s->start_hizb !== null ? (float) $s->start_hizb : null,
                'birth_date' => $s->birth_date,
                'enrollment_date' => $s->enrollment_date,
                'level_id' => $s->level_id,
                'notes' => $s->notes,
                'avg_score' => isset($score[$s->id]) ? (float) $score[$s->id] : null,
                'attendance_pct' => isset($att[$s->id]) ? (float) $att[$s->id] : null,
                'thumn_total' => (float) ($thumn[$s->id] ?? 0),
            ])->all(),
        ];
    }
}
