-- Per-student, per-session, per-module qualitative notes.
-- Scores stay in session_scores; notes never participate in averages.
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
