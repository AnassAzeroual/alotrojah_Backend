<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// teacher_type is meaningful only for teachers. Non-teacher rows carrying
// 'both' were meaningless noise (M5): make the columns nullable, backfill,
// and lock the invariant with CHECKs. A model-level saving hook normalizes
// every write path (API, seeds, factories), so the CHECKs are a backstop
// that application traffic can never trip.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `users` MODIFY `teacher_type` ENUM('hifz','murajaa','both') NULL DEFAULT 'both'");
        DB::statement("ALTER TABLE `registration_requests` MODIFY `teacher_type` ENUM('hifz','murajaa','both') NULL DEFAULT 'both'");

        DB::table('users')->where('role', '!=', 'teacher')->update(['teacher_type' => null]);
        DB::table('registration_requests')->where('role', '!=', 'teacher')->update(['teacher_type' => null]);

        DB::statement("ALTER TABLE `users` ADD CONSTRAINT `chk_users_teacher_type` CHECK ((`role` = 'teacher' AND `teacher_type` IS NOT NULL) OR (`role` != 'teacher' AND `teacher_type` IS NULL))");
        DB::statement("ALTER TABLE `registration_requests` ADD CONSTRAINT `chk_regrequests_teacher_type` CHECK ((`role` = 'teacher' AND `teacher_type` IS NOT NULL) OR (`role` != 'teacher' AND `teacher_type` IS NULL))");
    }

    public function down(): void
    {
        // DROP CONSTRAINT (MariaDB) vs DROP CHECK (MySQL): same lock, two dialects.
        $version = (string) (DB::selectOne('SELECT VERSION() AS v')->v ?? '');
        $keyword = str_contains($version, 'MariaDB') ? 'CONSTRAINT' : 'CHECK';
        DB::statement("ALTER TABLE `users` DROP {$keyword} `chk_users_teacher_type`");
        DB::statement("ALTER TABLE `registration_requests` DROP {$keyword} `chk_regrequests_teacher_type`");
        DB::table('users')->whereNull('teacher_type')->update(['teacher_type' => 'both']);
        DB::table('registration_requests')->whereNull('teacher_type')->update(['teacher_type' => 'both']);
        DB::statement("ALTER TABLE `users` MODIFY `teacher_type` ENUM('hifz','murajaa','both') NOT NULL DEFAULT 'both'");
        DB::statement("ALTER TABLE `registration_requests` MODIFY `teacher_type` ENUM('hifz','murajaa','both') NOT NULL DEFAULT 'both'");
    }
};
