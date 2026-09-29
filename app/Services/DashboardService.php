<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Chart.js feeds. Aggregates cached 5 min; busted by SessionScoreObserver.
 */
class DashboardService
{
    public function seasonRow(int $studentId, int $seasonId): ?object
    {
        return Cache::remember("dash:season:$seasonId:student:$studentId", 300, fn () =>
            DB::table('v_season_dashboard')->where('student_id', $studentId)->where('season_id', $seasonId)->first()
        );
    }

    public function weeklyProgress(int $studentId, int $seasonId): array
    {
        return DB::table('v_weekly_progress')
            ->where('student_id', $studentId)->where('season_id', $seasonId)
            ->orderBy('week_id')->get()->all();
    }

    public function centerCards(int $centerId, int $seasonId): array
    {
        $row = DB::table('v_season_dashboard as d')
            ->join('students as s', 's.id', '=', 'd.student_id')
            ->where('s.center_id', $centerId)->where('d.season_id', $seasonId)
            ->selectRaw('COUNT(*) as students, ROUND(AVG(d.season_avg_score),2) as avg_score,
                ROUND(AVG(d.season_avg_sarraj),2) as avg_sarraj,
                ROUND(AVG(d.season_attendance_pct),1) as avg_attendance')
            ->first();

        return $row ? (array) $row : [];
    }

    public function bust(int $studentId, int $seasonId): void
    {
        Cache::forget("dash:season:$seasonId:student:$studentId");
    }
}
