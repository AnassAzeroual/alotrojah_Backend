<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Levels are admin-created inside a season; there is no static level seed.
 *
 * Before this migration the shared NULL-season rows (seeded by 000012) were a
 * template set every center's first season cloned. That is removed: a first
 * season starts empty, and later seasons copy the previous season of the same
 * center (see Level::cloneIntoSeason).
 *
 * Deletion is limited to rows nothing references. A legacy NULL-season row
 * still pointed at by a pupil (a center that never had a season) cannot be
 * deleted without breaking fk_students_level / fk_student_levels_level; it is
 * kept as that pupil's legacy level anchor and is no longer treated as a
 * template by any code or feed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $referenced = DB::table('students')->select('level_id')
            ->union(DB::table('student_levels')->select('level_id'))
            ->pluck('level_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $seeds = DB::table('levels')->whereNull('season_id');
        if ($referenced !== []) {
            $seeds->whereNotIn('id', $referenced);
        }
        $seeds->delete();
    }

    public function down(): void
    {
        // The removed rows were static seed data; there is nothing to restore.
    }
};
