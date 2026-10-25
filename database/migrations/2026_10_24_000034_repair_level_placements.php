<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Repairs the per-season pupil placement written by 000033.
 *
 * 000033 resolved a center's "current" season as the FIRST season of its lane
 * (oldest id) when that lane had no `is_current` row, while every runtime
 * reader (AcademicSeason::currentFor) takes the LATEST season of the lane.
 * A center with several seasons and no active one therefore had its pupils
 * placed into the OLDEST season's level clones (and `students.level_id`
 * pointed there).
 *
 * This migration re-resolves each pupil's current season exactly like
 * currentFor() — own current, else own latest, else the shared lane — and
 * upserts the matching level clone by `code`, then re-points
 * `students.level_id` at it. Idempotent, and a no-op on a correct database.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One pass per pupil center lane (NULL center included).
        $centers = DB::table('students')->select('center_id')->distinct()->pluck('center_id');

        foreach ($centers as $centerId) {
            $season = $this->currentSeason($centerId === null ? null : (int) $centerId);
            if ($season === null) {
                continue;
            }

            // 1. Upsert the placement by level code (an existing row wins).
            DB::statement(
                'INSERT IGNORE INTO `student_levels` (student_id, season_id, level_id, created_at)
                 SELECT s.id, ?, target.id, NOW()
                 FROM `students` s
                 JOIN `levels` source ON source.id = s.level_id
                 JOIN `levels` target ON target.season_id = ? AND target.code = source.code
                 WHERE s.center_id <=> ?',
                [$season->id, $season->id, $centerId]
            );

            // 2. Point the compatibility pointer at the current season's clone.
            DB::statement(
                'UPDATE `students` s
                 JOIN `student_levels` sl ON sl.student_id = s.id AND sl.season_id = ?
                 SET s.level_id = sl.level_id
                 WHERE s.center_id <=> ?',
                [$season->id, $centerId]
            );
        }
    }

    public function down(): void
    {
        // Data repair only: the previous (wrong) pointers are not recoverable,
        // and the correct mapping is the desired state on rollback too.
    }

    private function currentSeason(?int $centerId): ?object
    {
        if ($centerId !== null) {
            $own = DB::table('academic_seasons')
                ->where('center_id', $centerId)
                ->orderByDesc('is_current')
                ->orderByDesc('id')
                ->first();
            if ($own !== null) {
                return $own;
            }
        }

        return DB::table('academic_seasons')
            ->whereNull('center_id')
            ->orderByDesc('is_current')
            ->orderByDesc('id')
            ->first();
    }
};
