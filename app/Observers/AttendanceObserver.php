<?php

namespace App\Observers;

use App\Models\Attendance;
use App\Services\DashboardService;

class AttendanceObserver
{
    public function saved(Attendance $attendance): void
    {
        app(DashboardService::class)->bust($attendance->student_id, $attendance->season_id);
    }

    public function deleted(Attendance $attendance): void
    {
        app(DashboardService::class)->bust($attendance->student_id, $attendance->season_id);
    }
}
