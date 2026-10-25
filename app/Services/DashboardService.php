<?php

namespace App\Services;

use App\Http\Resources\SeasonDashboardRow;
use App\Http\Resources\WeeklyProgressRow;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Chart.js feeds. Aggregates cached 5 min; busted by SessionScoreObserver.
 *
 * The reporting views are read through the query builder, so their DECIMAL
 * columns arrive as strings; the row classes below are what turn them into the
 * numbers the API contract promises.
 */
class DashboardService
{
    public function seasonRow(int $studentId, int $seasonId): ?SeasonDashboardRow
    {
        $row = Cache::remember("dash:season:$seasonId:student:$studentId", 300, function () use ($studentId, $seasonId) {
            $student = DB::table('students')->where('id', $studentId)
                ->first(['id', 'full_name', 'level_id', 'group_id']);
            if ($student === null) {
                return null;
            }

            // The view only exposes pupils who have a plan/log/attendance row
            // for the season; keep that contract without materialising it.
            $hasSeasonActivity = DB::table('term_plans')
                ->where('student_id', $studentId)->where('season_id', $seasonId)->exists()
                || DB::table('memorization_logs')
                    ->where('student_id', $studentId)->where('season_id', $seasonId)->exists()
                || DB::table('attendance_totals')
                    ->where('student_id', $studentId)->where('season_id', $seasonId)->exists();
            if (! $hasSeasonActivity) {
                return null;
            }

            $thumn = DB::table('memorization_logs')
                ->where('student_id', $studentId)->where('season_id', $seasonId)
                ->sum('thumn_amount');

            $avgScore = DB::query()->fromSub(ReportingQueries::sessionTotals([$studentId], $seasonId), 't')
                ->avg('total_score');
            $avgSarraj = DB::query()->fromSub(ReportingQueries::sarrajWeekly([$studentId], $seasonId), 'x')
                ->avg('avg_score');

            $attendance = DB::table('attendance_totals')
                ->where('student_id', $studentId)->where('season_id', $seasonId)
                ->selectRaw('ROUND(100*SUM(present_count+late_count)/SUM(total_sessions),1) AS attendance_rate_pct')
                ->first();

            $result = DB::table('season_results')
                ->where('student_id', $studentId)->where('season_id', $seasonId)
                ->first(['overall_avg', 'honor_flag']);

            return (object) [
                'student_id' => $student->id,
                'full_name' => $student->full_name,
                'level_id' => $student->level_id,
                'group_id' => $student->group_id,
                'season_id' => $seasonId,
                'season_thumn' => (float) $thumn,
                'season_avg_score' => $avgScore === null ? null : round((float) $avgScore, 2),
                'season_avg_sarraj' => $avgSarraj === null ? null : round((float) $avgSarraj, 2),
                'season_attendance_pct' => $attendance?->attendance_rate_pct === null
                    ? null : (float) $attendance->attendance_rate_pct,
                'season_overall_avg' => $result?->overall_avg,
                'honor_flag' => $result?->honor_flag,
            ];
        });

        return is_object($row) ? new SeasonDashboardRow($row) : null;
    }

    /** @return list<WeeklyProgressRow> */
    public function weeklyProgress(int $studentId, int $seasonId, ?int $termId = null): array
    {
        $logs = DB::table('memorization_logs')
            ->where('student_id', $studentId)->where('season_id', $seasonId);
        if ($termId !== null) {
            $logs->where('term_id', $termId);
        }
        $rows = $logs
            ->selectRaw('student_id, season_id, term_id, week_id, SUM(thumn_amount) AS total_thumn,
                ROUND(SUM(thumn_amount)/8,2) AS total_ahzab, COUNT(*) AS sessions_logged')
            ->groupBy('student_id', 'season_id', 'term_id', 'week_id')
            ->orderBy('week_id')->get();

        $scores = DB::query()->fromSub(ReportingQueries::sessionTotals([$studentId], $seasonId, $termId), 't')
            ->selectRaw('t.week_id, ROUND(AVG(t.total_score),2) AS avg_score')
            ->groupBy('t.week_id')->pluck('avg_score', 'week_id');

        return $rows->map(function (object $row) use ($scores): WeeklyProgressRow {
            $row->avg_score = $scores[$row->week_id] ?? null;

            return new WeeklyProgressRow($row);
        })->all();
    }

    /**
     * @return array{students: int, avg_score: float|null, avg_sarraj: float|null, avg_attendance: float|null}
     */
    public function centerCards(int $centerId, int $seasonId): array
    {
        $studentIds = $this->activeStudentIds($centerId, $seasonId);
        if ($studentIds === []) {
            return ['students' => 0, 'avg_score' => null, 'avg_sarraj' => null, 'avg_attendance' => null];
        }

        // v_season_dashboard averages each pupil's rounded season value; keep
        // the same nesting so the center KPI matches the old feed.
        $scorePerStudent = DB::query()->fromSub(ReportingQueries::sessionTotals($studentIds, $seasonId), 't')
            ->selectRaw('t.student_id, ROUND(AVG(t.total_score),2) AS value')
            ->groupBy('t.student_id');

        $sarrajPerStudent = DB::query()->fromSub(ReportingQueries::sarrajWeekly($studentIds, $seasonId), 'x')
            ->selectRaw('x.student_id, ROUND(AVG(x.avg_score),2) AS value')
            ->groupBy('x.student_id');

        $attendancePerStudent = DB::table('attendance_totals')
            ->whereIn('student_id', $studentIds)->where('season_id', $seasonId)
            ->selectRaw('student_id, ROUND(100*SUM(present_count+late_count)/SUM(total_sessions),1) AS value')
            ->groupBy('student_id');

        return [
            'students' => count($studentIds),
            'avg_score' => $this->averageOfPerStudent($scorePerStudent, 2),
            'avg_sarraj' => $this->averageOfPerStudent($sarrajPerStudent, 2),
            'avg_attendance' => $this->averageOfPerStudent($attendancePerStudent, 1),
        ];
    }

    public function bust(int $studentId, int $seasonId): void
    {
        Cache::forget("dash:season:$seasonId:student:$studentId");
    }

    /**
     * Pupils the old season-dashboard view included: anyone with a plan,
     * memorization log or attendance record in the season.
     *
     * @return list<int>
     */
    private function activeStudentIds(int $centerId, int $seasonId): array
    {
        $ids = DB::table('students')->where('center_id', $centerId)->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        return DB::table('term_plans')->where('season_id', $seasonId)->whereIn('student_id', $ids)
            ->pluck('student_id')
            ->merge(DB::table('memorization_logs')->where('season_id', $seasonId)->whereIn('student_id', $ids)
                ->pluck('student_id'))
            ->merge(DB::table('attendance_totals')->where('season_id', $seasonId)->whereIn('student_id', $ids)
                ->pluck('student_id'))
            ->unique()->values()->all();
    }

    private function averageOfPerStudent(Builder $perStudent, int $precision): ?float
    {
        $row = DB::query()->fromSub($perStudent, 'd')->selectRaw('AVG(d.value) AS avg')->first();

        return $row === null || $row->avg === null ? null : round((float) $row->avg, $precision);
    }
}
