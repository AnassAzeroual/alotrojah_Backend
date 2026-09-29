<?php

namespace App\Observers;

use App\Models\SessionScore;
use App\Services\DashboardService;

class SessionScoreObserver
{
    public function saved(SessionScore $score): void
    {
        app(DashboardService::class)->bust($score->student_id, $score->season_id);
    }

    public function deleted(SessionScore $score): void
    {
        app(DashboardService::class)->bust($score->student_id, $score->season_id);
    }
}
