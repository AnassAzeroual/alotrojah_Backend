<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Per-group sessions (user-directed 2026-10-07): sessions stop being shared
// calendar days and belong to exactly one group, with clock times. Sessions
// are still generated per season, but one set per active group on THAT
// group's weekdays (08:00-09:00 default) — so no session ever serves nobody.
// Backfill on non-empty DBs: children (scores/attendance/reviews/logs) point
// at pupils, and every pupil has exactly one group — the session row stays
// with the first group and copies fan out for the rest, children moved by
// pupil group. Childless sessions cannot serve any group and are deleted.
// Empty DBs (fresh installs) skip all of this silently.
return new class extends Migration
{
    private const CHILDREN = [
        'session_scores', 'attendance', 'murajaa_reviews', 'revision_logs', 'memorization_logs',
    ];

    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
        });

        // The old grain was one row per season+number; fan-out needs one row
        // per season+group+number instead. The season FK rides on that unique
        // index (leftmost column), so it comes off first and goes back after.
        // The old unique may already be gone (down() never restores it —
        // re-running up() after a rollback must not trip over that).
        DB::statement('ALTER TABLE sessions DROP FOREIGN KEY fk_sessions_season');
        $hasOldUq = DB::select(
            'SELECT 1 AS x FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [DB::getDatabaseName(), 'sessions', 'uq_sessions_season_global']
        );
        if (count($hasOldUq) > 0) {
            DB::statement('ALTER TABLE sessions DROP KEY uq_sessions_season_global');
        }

        DB::table('sessions')->update(['start_time' => '08:00:00', 'end_time' => '09:00:00']);

        $pupilGroup = DB::table('students')->pluck('group_id', 'id');
        foreach (DB::table('sessions')->select('id')->orderBy('id')->get() as $s) {
            $groups = [];
            foreach (self::CHILDREN as $t) {
                foreach (DB::table($t)->where('session_id', $s->id)->pluck('student_id') as $pid) {
                    $gid = $pupilGroup[$pid] ?? null;
                    if ($gid !== null) {
                        $groups[$gid] = true;
                    }
                }
            }
            $groups = array_keys($groups);
            if (count($groups) === 0) {
                DB::table('sessions')->where('id', $s->id)->delete();

                continue;
            }
            DB::table('sessions')->where('id', $s->id)->update(['group_id' => $groups[0]]);
            foreach (array_slice($groups, 1) as $gid) {
                $row = (array) DB::table('sessions')->where('id', $s->id)->first();
                unset($row['id']);
                $row['group_id'] = $gid;
                $newId = DB::table('sessions')->insertGetId($row);
                $pids = DB::table('students')->where('group_id', $gid)->pluck('id');
                foreach (self::CHILDREN as $t) {
                    DB::table($t)->where('session_id', $s->id)->whereIn('student_id', $pids)->update(['session_id' => $newId]);
                }
            }
        }

        DB::statement('ALTER TABLE sessions MODIFY start_time TIME NOT NULL');
        DB::statement('ALTER TABLE sessions MODIFY end_time TIME NOT NULL');
        DB::statement('ALTER TABLE sessions MODIFY group_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE sessions ADD UNIQUE uq_sessions_group_global (season_id, group_id, session_number_global)');
        DB::statement('ALTER TABLE sessions ADD CONSTRAINT fk_sessions_season FOREIGN KEY (season_id) REFERENCES academic_seasons (id) ON DELETE CASCADE ON UPDATE RESTRICT');
        DB::statement('ALTER TABLE sessions ADD CONSTRAINT fk_sessions_group FOREIGN KEY (group_id) REFERENCES groups (id) ON DELETE CASCADE ON UPDATE RESTRICT');
    }

    public function down(): void
    {
        // Lossy by nature: fan-out duplicated (season, number) pairs across
        // groups and deleted childless rows, so the old UNIQUE(season, number)
        // grain cannot be restored — rollback drops the columns (forward-fix
        // only afterwards).
        // The season FK rides on the new unique's leftmost column: both FKs
        // come off before the unique can drop (mirrors up()).
        DB::statement('ALTER TABLE sessions DROP FOREIGN KEY fk_sessions_group');
        DB::statement('ALTER TABLE sessions DROP FOREIGN KEY fk_sessions_season');
        DB::statement('ALTER TABLE sessions DROP KEY uq_sessions_group_global');
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropColumn(['start_time', 'end_time', 'group_id']);
        });
        DB::statement('ALTER TABLE sessions ADD CONSTRAINT fk_sessions_season FOREIGN KEY (season_id) REFERENCES academic_seasons (id) ON DELETE CASCADE ON UPDATE RESTRICT');
    }
};
