<?php

namespace App\Services;

use App\Http\Resources\SeasonDashboardRow;
use App\Http\Resources\WeeklyProgressRow;
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
        $row = Cache::remember("dash:season:$seasonId:student:$studentId", 300, fn () => DB::table('v_season_dashboard')->where('student_id', $studentId)->where('season_id', $seasonId)->first()
        );

        return is_object($row) ? new SeasonDashboardRow($row) : null;
    }

    /** @return list<WeeklyProgressRow> */
    public function weeklyProgress(int $studentId, int $seasonId): array
    {
        return array_map(
            static fn (object $row): WeeklyProgressRow => new WeeklyProgressRow($row),
            DB::table('v_weekly_progress')
                ->where('student_id', $studentId)->where('season_id', $seasonId)
                ->orderBy('week_id')->get()->all(),
        );
    }

    /**
     * @return array{students: int, avg_score: float|null, avg_sarraj: float|null, avg_attendance: float|null}
     */
    public function centerCards(int $centerId, int $seasonId): array
    {
        $row = DB::table('v_season_dashboard as d')
            ->join('students as s', 's.id', '=', 'd.student_id')
            ->where('s.center_id', $centerId)->where('d.season_id', $seasonId)
            ->selectRaw('COUNT(*) as students, ROUND(AVG(d.season_avg_score),2) as avg_score,
                ROUND(AVG(d.season_avg_sarraj),2) as avg_sarraj,
                ROUND(AVG(d.season_attendance_pct),1) as avg_attendance')
            ->first();

        if ($row === null) {
            return ['students' => 0, 'avg_score' => null, 'avg_sarraj' => null, 'avg_attendance' => null];
        }

        return [
            'students' => (int) $row->students,
            'avg_score' => $row->avg_score === null ? null : (float) $row->avg_score,
            'avg_sarraj' => $row->avg_sarraj === null ? null : (float) $row->avg_sarraj,
            'avg_attendance' => $row->avg_attendance === null ? null : (float) $row->avg_attendance,
        ];
    }

    public function bust(int $studentId, int $seasonId): void
    {
        Cache::forget("dash:season:$seasonId:student:$studentId");
    }
}
