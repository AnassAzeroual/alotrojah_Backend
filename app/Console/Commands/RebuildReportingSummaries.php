<?php

namespace App\Console\Commands;

use App\Services\ReportingSummaryService;
use Illuminate\Console\Command;

/**
 * Repair/backfill command for the maintained reporting rollups.
 *
 * The tables are updated on writes and by the migration backfill; this command
 * is the operational escape hatch after manual SQL edits or a module change
 * made outside the application.
 */
class RebuildReportingSummaries extends Command
{
    protected $signature = 'reporting:rebuild';

    protected $description = 'Rebuild session, attendance and separate-module reporting summaries';

    public function handle(ReportingSummaryService $summary): int
    {
        $this->info('Rebuilding reporting summaries...');
        $counts = $summary->rebuildAll();

        $this->table(
            ['Summary table', 'Rows'],
            collect($counts)->map(fn (int $rows, string $table): array => [$table, $rows])->values()->all(),
        );
        $this->info('Reporting summaries rebuilt.');

        return self::SUCCESS;
    }
}
