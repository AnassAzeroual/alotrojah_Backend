<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// NOT NULL everywhere (user-directed 2026-10-07): the 9 nullable FK columns
// become NOT NULL. Precondition is zero NULL rows — deleted explicitly per DB
// before applying, so the constraint fails loudly if a DB is still dirty.
// murajaa_reviews.session_id was ON DELETE SET NULL, which is incompatible
// with NOT NULL: re-pointed to RESTRICT (a session with reviews can no longer
// be deleted; delete its reviews first).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE announcements MODIFY group_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE delegation_tokens MODIFY used_by_teacher_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE exams MODIFY examiner_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE groups MODIFY teacher_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE murajaa_reviews MODIFY entered_by BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE revision_logs MODIFY entered_by BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE students MODIFY group_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE students MODIFY level_id INT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE murajaa_reviews DROP FOREIGN KEY fk_murajaa_session');
        DB::statement('ALTER TABLE murajaa_reviews MODIFY session_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE murajaa_reviews ADD CONSTRAINT fk_murajaa_session FOREIGN KEY (session_id) REFERENCES sessions (id) ON DELETE RESTRICT ON UPDATE RESTRICT');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE announcements MODIFY group_id BIGINT UNSIGNED NULL DEFAULT NULL');
        DB::statement('ALTER TABLE delegation_tokens MODIFY used_by_teacher_id BIGINT UNSIGNED NULL DEFAULT NULL');
        DB::statement('ALTER TABLE exams MODIFY examiner_id BIGINT UNSIGNED NULL DEFAULT NULL');
        DB::statement('ALTER TABLE groups MODIFY teacher_id BIGINT UNSIGNED NULL DEFAULT NULL');
        DB::statement('ALTER TABLE murajaa_reviews MODIFY entered_by BIGINT UNSIGNED NULL DEFAULT NULL');
        DB::statement('ALTER TABLE revision_logs MODIFY entered_by BIGINT UNSIGNED NULL DEFAULT NULL');
        DB::statement('ALTER TABLE students MODIFY group_id BIGINT UNSIGNED NULL DEFAULT NULL');
        DB::statement('ALTER TABLE students MODIFY level_id INT UNSIGNED NULL DEFAULT NULL');
        DB::statement('ALTER TABLE murajaa_reviews DROP FOREIGN KEY fk_murajaa_session');
        DB::statement('ALTER TABLE murajaa_reviews MODIFY session_id BIGINT UNSIGNED NULL DEFAULT NULL');
        DB::statement('ALTER TABLE murajaa_reviews ADD CONSTRAINT fk_murajaa_session FOREIGN KEY (session_id) REFERENCES sessions (id) ON DELETE SET NULL ON UPDATE RESTRICT');
    }
};
