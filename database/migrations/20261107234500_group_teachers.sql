-- A group can have a Hifz teacher and a Murajaa teacher (or one both-role teacher).
-- groups.teacher_id stays as the lead teacher for backward compatibility.
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
