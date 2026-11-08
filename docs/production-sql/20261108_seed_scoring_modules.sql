SET NAMES utf8mb4;

-- Baseline scoring modules (reference data, not demo data).
-- Safe to run repeatedly; each block only inserts when the code has no global default row.
-- Run in phpMyAdmin after the schema patch if the deploy runner is unavailable.

INSERT INTO `scoring_modules`
    (`center_id`, `code`, `name_ar`, `max_points`, `scope`, `is_active`, `is_in_weekly_total`, `sort_order`, `created_by`)
SELECT NULL, 'hifz', 'الحفظ', 14.0, 'weekly', 1, 1, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM `scoring_modules` WHERE `code` = 'hifz' AND `center_id` IS NULL);

INSERT INTO `scoring_modules`
    (`center_id`, `code`, `name_ar`, `max_points`, `scope`, `is_active`, `is_in_weekly_total`, `sort_order`, `created_by`)
SELECT NULL, 'mowathaba', 'المواظبة / الحضور', 4.0, 'weekly', 1, 1, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM `scoring_modules` WHERE `code` = 'mowathaba' AND `center_id` IS NULL);

INSERT INTO `scoring_modules`
    (`center_id`, `code`, `name_ar`, `max_points`, `scope`, `is_active`, `is_in_weekly_total`, `sort_order`, `created_by`)
SELECT NULL, 'tajwid', 'التجويد', 2.0, 'weekly', 1, 1, 3, NULL
WHERE NOT EXISTS (SELECT 1 FROM `scoring_modules` WHERE `code` = 'tajwid' AND `center_id` IS NULL);

INSERT INTO `scoring_modules`
    (`center_id`, `code`, `name_ar`, `max_points`, `scope`, `is_active`, `is_in_weekly_total`, `sort_order`, `created_by`)
SELECT NULL, 'murajaa', 'المراجعة', 20.0, 'murajaa', 1, 1, 4, NULL
WHERE NOT EXISTS (SELECT 1 FROM `scoring_modules` WHERE `code` = 'murajaa' AND `center_id` IS NULL);

INSERT INTO `scoring_modules`
    (`center_id`, `code`, `name_ar`, `max_points`, `scope`, `is_active`, `is_in_weekly_total`, `sort_order`, `created_by`)
SELECT NULL, 'sarraj', 'السراج في بيان غريب القرآن', 20.0, 'weekly', 1, 0, 5, NULL
WHERE NOT EXISTS (SELECT 1 FROM `scoring_modules` WHERE `code` = 'sarraj' AND `center_id` IS NULL);
