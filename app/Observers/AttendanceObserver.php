<?php

namespace App\Observers;

use App\Models\Attendance;
use App\Services\DashboardService;
use App\Services\ReportingSummaryService;

class AttendanceObserver
{
    public function saved(Attendance $attendance): void
    {
        app(ReportingSummaryService::class)->refreshAttendanceTotals(
            $attendance->student_id,
            $attendance->season_id,
            $attendance->term_id,
        );
        app(DashboardService::class)->bust($attendance->student_id, $attendance->season_id);
    }

    public function deleted(Attendance $attendance): void
    {
        app(ReportingSummaryService::class)->refreshAttendanceTotals(
            $attendance->student_id,
            $attendance->season_id,
            $attendance->term_id,
        );
        app(DashboardService::class)->bust($attendance->student_id, $attendance->season_id);
    }
}
