<?php

namespace App\Observers;

use App\Models\ScoringModule;
use App\Models\SessionScore;
use App\Services\ReportingSummaryService;

/**
 * Active / weekly-total flags are part of every summary definition. A module
 * change therefore invalidates score-derived rows; defer one rebuild until the
 * surrounding rebalance transaction commits. Changes that cannot affect the
 * summaries (a module with no recorded scores, or only max_points/sort_order)
 * are skipped.
 */
class ScoringModuleObserver
{
    public function saved(ScoringModule $module): void
    {
        if (! $module->wasChanged(['code', 'name_ar', 'is_active', 'is_in_weekly_total'])) {
            return;
        }

        if (! SessionScore::where('module_id', $module->id)->exists()) {
            return;
        }

        app(ReportingSummaryService::class)->queueRebuild();
    }

    public function deleted(ScoringModule $module): void
    {
        if (! SessionScore::where('module_id', $module->id)->exists()) {
            return;
        }

        app(ReportingSummaryService::class)->queueRebuild();
    }
}
