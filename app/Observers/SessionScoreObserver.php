<?php

namespace App\Observers;

use App\Models\SessionScore;
use App\Services\DashboardService;
use App\Services\ReportingSummaryService;

class SessionScoreObserver
{
    public function saved(SessionScore $score): void
    {
        app(ReportingSummaryService::class)->refreshSessionScore($score->student_id, $score->session_id);
        app(DashboardService::class)->bust($score->student_id, $score->season_id);
    }

    public function deleted(SessionScore $score): void
    {
        app(ReportingSummaryService::class)->refreshSessionScore($score->student_id, $score->session_id);
        app(DashboardService::class)->bust($score->student_id, $score->season_id);
    }
}
