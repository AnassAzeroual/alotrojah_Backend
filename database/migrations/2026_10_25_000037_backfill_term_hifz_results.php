<?php

use App\Services\TermHifzResultSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill Hifz for term results that already exist:
 * average of each session's weekly /20 total across all non-cancelled sessions
 * in the term (missing session score = 0).
 */
return new class extends Migration
{
    public function up(): void
    {
        $sync = app(TermHifzResultSync::class);

        DB::table('term_results')
            ->select('id', 'student_id', 'term_id')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($sync): void {
                foreach ($rows as $row) {
                    $sync->apply((int) $row->student_id, (int) $row->term_id);
                }
            });
    }

    public function down(): void
    {
        // Data backfill: no safe reverse.
    }
};
