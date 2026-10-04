<?php

namespace App\Services;

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
    public function currentSeason(): ?object
    {
        return DB::table('academic_seasons')->where('is_current', 1)->first(['id', 'name']);
    }

    /**
     * @param  Collection<int, \App\Models\Group>  $groups  with teacher + level loaded
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

        $score = $this->perGroup($this->scoreQuery($ids, $seasonId), 'avg_score');
        $att = $this->perGroup($this->attendanceQuery($ids, $seasonId), 'att');
        $thumn = $this->perGroup($this->thumnQuery($ids, $seasonId), 'thumn');

        $rows = $groups->map(function ($g) use ($score, $att, $thumn, $perGroupBreakdown) {
            $count = (int) ($g->students_count ?? 0);

            return [
                'id' => $g->id,
                'name' => $g->name,
                'center_id' => $g->center_id,
                'level' => $g->level ? ['id' => $g->level->id, 'name_ar' => $g->level->name_ar] : null,
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
                'avg_score' => $this->scalar($this->scoreQuery($ids, $seasonId), 'avg_score', false),
                'attendance_pct' => $this->scalar($this->attendanceQuery($ids, $seasonId), 'att', false),
                'fill_pct' => $capacity > 0 ? round(100 * $total / $capacity, 1) : null,
            ],
            'breakdown' => $breakdown,
            'groups' => $rows,
        ];
    }

    /** One group: info + every student with season metrics + weekly trend. */
    public function detail(\App\Models\Group $group, ?int $seasonId): array
    {
        $gid = [$group->id];
        $students = DB::table('students')->where('group_id', $group->id)->orderBy('full_name')
            ->get(['id', 'full_name', 'gender', 'status', 'student_type', 'memorization_mode',
                'start_hizb', 'birth_date', 'enrollment_date', 'level_id', 'notes']);
        $sid = $students->pluck('id')->all();

        $score = $sid && $seasonId ? DB::table('v_session_totals')->whereIn('student_id', $sid)
            ->where('season_id', $seasonId)->selectRaw('student_id, ROUND(AVG(total_score),2) AS v')
            ->groupBy('student_id')->pluck('v', 'student_id') : collect();
        $att = $sid && $seasonId ? DB::table('attendance')->whereIn('student_id', $sid)
            ->where('season_id', $seasonId)
            ->selectRaw("student_id, ROUND(100*SUM(status IN ('present','late'))/COUNT(*),1) AS v")
            ->groupBy('student_id')->pluck('v', 'student_id') : collect();
        $thumn = $sid && $seasonId ? DB::table('memorization_logs')->whereIn('student_id', $sid)
            ->where('season_id', $seasonId)->selectRaw('student_id, ROUND(SUM(thumn_amount),2) AS v')
            ->groupBy('student_id')->pluck('v', 'student_id') : collect();

        $trend = $seasonId ? DB::table('v_session_totals as t')
            ->join('students as s', 's.id', '=', 't.student_id')
            ->join('weeks as w', 'w.id', '=', 't.week_id')
            ->whereIn('s.group_id', $gid)->where('t.season_id', $seasonId)
            ->selectRaw('w.week_number_global AS week, ROUND(AVG(t.total_score),2) AS avg_score')
            ->groupBy('w.week_number_global')->orderBy('w.week_number_global')->get() : collect();

        $summary = $this->overview(collect([$group]), $seasonId);

        return [
            'group' => $summary['groups'][0],
            'breakdown' => $summary['breakdown'],
            'trend' => $trend->all(),
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

    // ---- query builders (no season => empty result, never "all seasons") ----

    private function scoreQuery(array $ids, ?int $seasonId)
    {
        return DB::table('v_session_totals as t')->join('students as s', 's.id', '=', 't.student_id')
            ->whereIn('s.group_id', $ids ?: [0])->where('t.season_id', $seasonId ?? 0)
            ->selectRaw('ROUND(AVG(t.total_score),2) AS avg_score');
    }

    private function attendanceQuery(array $ids, ?int $seasonId)
    {
        return DB::table('attendance as a')->join('students as s', 's.id', '=', 'a.student_id')
            ->whereIn('s.group_id', $ids ?: [0])->where('a.season_id', $seasonId ?? 0)
            ->selectRaw("ROUND(100*SUM(a.status IN ('present','late'))/COUNT(*),1) AS att");
    }

    private function thumnQuery(array $ids, ?int $seasonId)
    {
        return DB::table('memorization_logs as m')->join('students as s', 's.id', '=', 'm.student_id')
            ->whereIn('s.group_id', $ids ?: [0])->where('m.season_id', $seasonId ?? 0)
            ->selectRaw('ROUND(SUM(m.thumn_amount),2) AS thumn');
    }

    /** @return array<int, float> group_id => value */
    private function perGroup($query, string $alias): array
    {
        return $query->addSelect('s.group_id')->groupBy('s.group_id')->get()
            ->mapWithKeys(fn ($r) => [$r->group_id => (float) $r->{$alias}])->all();
    }

    private function scalar($query, string $alias, mixed $default): ?float
    {
        $v = $query->first()?->{$alias};

        return $v === null ? $default : (float) $v;
    }
}
