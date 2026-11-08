-- Season-level planning value. Term/session ranges are derived from this;
-- actual per-group generation still follows each group's schedule_days.
ALTER TABLE `academic_seasons`
    ADD COLUMN `sessions_per_week` TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER `total_weeks`;
