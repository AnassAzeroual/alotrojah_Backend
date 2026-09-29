<?php

namespace App\Observers;

use App\Models\MurajaaReview;
use App\Services\DashboardService;

class MurajaaReviewObserver
{
    public function saved(MurajaaReview $review): void
    {
        app(DashboardService::class)->bust($review->student_id, $review->season_id);
    }

    public function deleted(MurajaaReview $review): void
    {
        app(DashboardService::class)->bust($review->student_id, $review->season_id);
    }
}
