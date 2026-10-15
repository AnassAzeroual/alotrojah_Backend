<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Group-level removal (user-directed 2026-10-15): a group is a schedule
// container (teacher + weekdays + time) and every pupil carries their own
// weekly-goal level. `groups.level_id` was never read by scoring or
// generation — `students.level_id` is the sole owner and stays.
// Dropping the column also drops `idx_groups_level` (MySQL removes a
// column's indexes with it); only the FK needs an explicit drop first.
// down() restores the column NULLABLE (the original was NOT NULL): a
// faithful NOT NULL re-add fails on any DB that already holds groups, and
// every down() must work — re-populating values is a manual repair.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `groups` DROP FOREIGN KEY `fk_groups_level`');
        DB::statement('ALTER TABLE `groups` DROP COLUMN `level_id`');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `groups` ADD COLUMN `level_id` INT UNSIGNED NULL DEFAULT NULL AFTER `center_id`');
        DB::statement('ALTER TABLE `groups` ADD INDEX `idx_groups_level` (`level_id`)');
        DB::statement('ALTER TABLE `groups` ADD CONSTRAINT `fk_groups_level` FOREIGN KEY (`level_id`) REFERENCES `levels` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT');
    }
};
