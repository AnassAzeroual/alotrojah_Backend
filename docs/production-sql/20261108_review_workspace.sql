-- AlOtrojah — unified Hifz/Murajaa workspace production patch.
-- Run in phpMyAdmin (FTP production host); idempotent, safe to re-run.
-- 1. session notes
CREATE TABLE IF NOT EXISTS `session_notes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id` BIGINT UNSIGNED NOT NULL,
    `session_id` BIGINT UNSIGNED NOT NULL,
    `module_code` VARCHAR(32) NOT NULL,
    `note` TEXT NOT NULL,
    `tone` ENUM('good', 'focus', 'neutral') NULL,
    `visibility` ENUM('staff', 'student') NOT NULL DEFAULT 'staff',
    `entered_by` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_session_notes_student_session_module` (`student_id`, `session_id`, `module_code`),
    KEY `idx_session_notes_session` (`session_id`),
    KEY `idx_session_notes_student` (`student_id`),
    CONSTRAINT `fk_session_notes_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_session_notes_session` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_session_notes_user` FOREIGN KEY (`entered_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. two teachers per group
CREATE TABLE IF NOT EXISTS `group_teachers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `group_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `role` ENUM('hifz', 'murajaa', 'both') NOT NULL DEFAULT 'both',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_group_teachers` (`group_id`, `user_id`),
    KEY `idx_group_teachers_user` (`user_id`),
    CONSTRAINT `fk_group_teachers_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_group_teachers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. groups.schedule_days (conditional; works on MySQL 5.7/8.x)
SET @has_schedule_days := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'groups' AND COLUMN_NAME = 'schedule_days'
);
SET @ddl := IF(
    @has_schedule_days = 0,
    'ALTER TABLE `groups` ADD COLUMN `schedule_days` VARCHAR(64) NOT NULL DEFAULT ''Mon,Wed,Fri'' AFTER `capacity`',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. preserve current lead teachers in the pivot
INSERT IGNORE INTO `group_teachers` (`group_id`, `user_id`, `role`)
SELECT g.`id`, g.`teacher_id`,
       CASE WHEN u.`teacher_type` IN ('hifz', 'murajaa', 'both') THEN u.`teacher_type` ELSE 'both' END
FROM `groups` g
JOIN `users` u ON u.`id` = g.`teacher_id`
WHERE g.`teacher_id` IS NOT NULL;

-- 5. copy legacy murajaa reviews into per-session scores
INSERT INTO `session_scores`
    (`student_id`, `season_id`, `term_id`, `week_id`, `session_id`, `log_date`, `module_id`, `score`, `entered_by`)
SELECT r.`student_id`, s.`season_id`, s.`term_id`, s.`week_id`, s.`id`,
       COALESCE(s.`planned_date`, DATE(r.`reviewed_at`), CURDATE()),
       m.`id`, r.`score`, r.`entered_by`
FROM `murajaa_reviews` r
JOIN `sessions` s ON s.`id` = r.`session_id`
JOIN `scoring_modules` m ON m.`id` = (SELECT MIN(`id`) FROM `scoring_modules` WHERE `code` = 'murajaa')
WHERE NOT EXISTS (
    SELECT 1 FROM `session_scores` sc
    WHERE sc.`student_id` = r.`student_id`
      AND sc.`session_id` = r.`session_id`
      AND sc.`module_id` = m.`id`
);

-- 6. term Murajaa = scored-session average; null when no scored session
UPDATE `term_results` tr
LEFT JOIN (
    SELECT sc.`student_id`, sc.`term_id`, ROUND(AVG(sc.`score`), 2) AS avg_score
    FROM `session_scores` sc
    JOIN `scoring_modules` m ON m.`id` = sc.`module_id`
    JOIN `sessions` s ON s.`id` = sc.`session_id`
    WHERE m.`id` = (SELECT MIN(`id`) FROM `scoring_modules` WHERE `code` = 'murajaa') AND s.`status` <> 'cancelled'
    GROUP BY sc.`student_id`, sc.`term_id`
) x ON x.`student_id` = tr.`student_id` AND x.`term_id` = tr.`term_id`
SET tr.`murajaa_total` = x.`avg_score`;

-- create missing term rows for students who have scored sessions
INSERT INTO `term_results` (`student_id`, `season_id`, `term_id`, `murajaa_total`, `honor_flag`)
SELECT x.`student_id`, t.`season_id`, x.`term_id`, x.`avg_score`, 'none'
FROM (
    SELECT sc.`student_id`, sc.`term_id`, ROUND(AVG(sc.`score`), 2) AS avg_score
    FROM `session_scores` sc
    JOIN `scoring_modules` m ON m.`id` = sc.`module_id`
    JOIN `sessions` s ON s.`id` = sc.`session_id`
    WHERE m.`id` = (SELECT MIN(`id`) FROM `scoring_modules` WHERE `code` = 'murajaa') AND s.`status` <> 'cancelled'
    GROUP BY sc.`student_id`, sc.`term_id`
) x
JOIN `terms` t ON t.`id` = x.`term_id`
LEFT JOIN `term_results` tr ON tr.`student_id` = x.`student_id` AND tr.`term_id` = x.`term_id`
WHERE tr.`id` IS NULL;

-- 7. season Murajaa = average of scored term averages (Option B)
INSERT INTO `season_results` (`student_id`, `season_id`, `murajaa_total`, `honor_flag`)
SELECT tr.`student_id`, tr.`season_id`, ROUND(AVG(tr.`murajaa_total`), 2), 'none'
FROM `term_results` tr
WHERE tr.`murajaa_total` IS NOT NULL
GROUP BY tr.`student_id`, tr.`season_id`
ON DUPLICATE KEY UPDATE `murajaa_total` = VALUES(`murajaa_total`);
