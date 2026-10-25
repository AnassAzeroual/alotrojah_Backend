<?php

use App\Services\TermMurajaaResultSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Backfill Murajaa = average of official review cycles for existing term rows. */
return new class extends Migration
{
    public function up(): void
    {
        $sync = app(TermMurajaaResultSync::class);

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
