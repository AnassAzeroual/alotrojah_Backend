-- ============================================================
-- Quran Memorization Logbook - MySQL 8 (CONSOLIDATED FINAL)
-- DB: quran_memorization | utf8mb4 | InnoDB
-- Source: book "البرنامج المقترح لحفظ القرآن الكريم (فئة غير المتفرغ)"
-- + manager/teachers meeting decisions (see questions.md, final section)
-- Quran units: 60 hizb = 30 juz | 1 hizb = 8 thumn | total 480 thumn
-- Design: every fact carries student>season>term>week>session + date
--  (Chart.js GROUP BY). Scores live in session_scores per MODULE
--  (manager adds books like السراج without code changes).
-- Default template: 6 terms x 7 weeks (6 study + 1 review) = 42 weeks,
--  editable rows (manager can do 4+1 etc). Template button lives in UI.
-- ============================================================

-- CREATE DATABASE IF NOT EXISTS `quran_memorization`
--   CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE `quran_memorization`;

-- SET FOREIGN_KEY_CHECKS = 0;

-- ================= A. ORGANIZATION & PEOPLE =================
CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `password_hash` VARCHAR(255) NULL,
  `role` ENUM('admin','supervisor','teacher','guardian','student','board') NOT NULL DEFAULT 'teacher',
  `phone` VARCHAR(30) NULL,
  `center_id` BIGINT UNSIGNED NULL COMMENT 'NULL = global (system admin)',
  `teacher_type` ENUM('hifz','murajaa','both') NOT NULL DEFAULT 'both' COMMENT 'تحفيظ / مراجعة / كلاهما — enables page inputs',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_center` (`center_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `centers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `city` VARCHAR(100) NULL,
  `address` VARCHAR(255) NULL,
  `phone` VARCHAR(30) NULL,
  `manager_name` VARCHAR(150) NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `levels` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` ENUM('L1','L2','L3') NOT NULL DEFAULT 'L1',
  `name_ar` VARCHAR(100) NOT NULL,
  `sessions_per_week` TINYINT UNSIGNED NOT NULL DEFAULT 3,
  `thumn_per_session_label` VARCHAR(50) NOT NULL,
  `thumn_per_session_value` DECIMAL(5,2) NOT NULL,
  `thumn_per_week_value` DECIMAL(5,2) NOT NULL,
  `ahzab_per_term` DECIMAL(5,2) NOT NULL,
  `ahzab_per_dawra` DECIMAL(5,2) NOT NULL,
  `duration_label` VARCHAR(100) NOT NULL,
  `total_ahzab` TINYINT UNSIGNED NOT NULL DEFAULT 60,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_levels_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `groups` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `center_id` BIGINT UNSIGNED NOT NULL,
  `level_id` INT UNSIGNED NOT NULL,
  `teacher_id` BIGINT UNSIGNED NULL,
  `name` VARCHAR(120) NOT NULL,
  `academic_year` VARCHAR(20) NULL,
  `capacity` SMALLINT UNSIGNED NULL DEFAULT 20,
  `schedule_days` VARCHAR(60) NOT NULL DEFAULT 'Mon,Wed,Fri' COMMENT 'أيام الحصص — editable by teacher',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_groups_center` (`center_id`),
  KEY `idx_groups_level` (`level_id`),
  KEY `idx_groups_teacher` (`teacher_id`),
  CONSTRAINT `fk_groups_center` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`),
  CONSTRAINT `fk_groups_level` FOREIGN KEY (`level_id`) REFERENCES `levels` (`id`),
  CONSTRAINT `fk_groups_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `guardians` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NULL COMMENT 'student+guardian share one account (same interface)',
  `full_name` VARCHAR(150) NOT NULL,
  `phone` VARCHAR(30) NULL COMMENT 'used for wa.me links',
  `relation` ENUM('father','mother','other') NOT NULL DEFAULT 'father',
  PRIMARY KEY (`id`),
  KEY `idx_guardians_user` (`user_id`),
  CONSTRAINT `fk_guardians_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `students` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NULL,
  `guardian_id` BIGINT UNSIGNED NULL,
  `group_id` BIGINT UNSIGNED NULL,
  `center_id` BIGINT UNSIGNED NULL,
  `level_id` INT UNSIGNED NULL,
  `full_name` VARCHAR(150) NOT NULL,
  `birth_date` DATE NULL,
  `gender` ENUM('male','female') NULL,
  `enrollment_date` DATE NULL,
  `status` ENUM('active','paused','graduated','left') NOT NULL DEFAULT 'active',
  `student_type` ENUM('child','adult') NULL DEFAULT NULL COMMENT 'أطفال +4 / كبار +18',
  `memorization_mode` ENUM('surah','thumn') NOT NULL DEFAULT 'thumn' COMMENT 'الحفظ بالسورة أو بالثمن',
  `start_hizb` DECIMAL(4,1) NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_students_group` (`group_id`),
  KEY `idx_students_level` (`level_id`),
  KEY `idx_students_center` (`center_id`),
  KEY `idx_students_status` (`status`),
  CONSTRAINT `fk_students_guardian` FOREIGN KEY (`guardian_id`) REFERENCES `guardians` (`id`),
  CONSTRAINT `fk_students_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`),
  CONSTRAINT `fk_students_level` FOREIGN KEY (`level_id`) REFERENCES `levels` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================= B. ACADEMIC CALENDAR (default 42w / 126s / 6t) ======
CREATE TABLE IF NOT EXISTS `academic_seasons` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `hijri_year` VARCHAR(20) NULL,
  `start_date` DATE NULL,
  `end_date` DATE NULL,
  `total_weeks` SMALLINT UNSIGNED NOT NULL DEFAULT 42,
  `total_sessions` SMALLINT UNSIGNED NOT NULL DEFAULT 126,
  `is_current` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_seasons_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `terms` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `season_id` BIGINT UNSIGNED NOT NULL,
  `term_number` TINYINT UNSIGNED NOT NULL COMMENT '1-6 default, N allowed',
  `name_ar` VARCHAR(50) NOT NULL,
  `start_week` SMALLINT UNSIGNED NOT NULL,
  `end_week` SMALLINT UNSIGNED NOT NULL,
  `start_session_no` SMALLINT UNSIGNED NOT NULL,
  `end_session_no` SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_terms_season_no` (`season_id`,`term_number`),
  CONSTRAINT `fk_terms_season` FOREIGN KEY (`season_id`) REFERENCES `academic_seasons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `weeks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `season_id` BIGINT UNSIGNED NOT NULL,
  `term_id` BIGINT UNSIGNED NOT NULL,
  `week_number_global` SMALLINT UNSIGNED NOT NULL,
  `week_number_in_term` SMALLINT UNSIGNED NOT NULL,
  `week_type` ENUM('study','review') NOT NULL DEFAULT 'study' COMMENT '7th week = review+quiz',
  `start_date` DATE NULL,
  `end_date` DATE NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_weeks_season_global` (`season_id`,`week_number_global`),
  KEY `idx_weeks_term` (`term_id`),
  CONSTRAINT `fk_weeks_season` FOREIGN KEY (`season_id`) REFERENCES `academic_seasons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_weeks_term` FOREIGN KEY (`term_id`) REFERENCES `terms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `season_id` BIGINT UNSIGNED NOT NULL,
  `term_id` BIGINT UNSIGNED NOT NULL,
  `week_id` BIGINT UNSIGNED NOT NULL,
  `session_number_global` SMALLINT UNSIGNED NOT NULL,
  `session_number_in_week` TINYINT UNSIGNED NOT NULL,
  `session_type` ENUM('memorization','revision','exam') NOT NULL DEFAULT 'memorization',
  `planned_date` DATE NULL,
  `status` ENUM('planned','done','cancelled') NOT NULL DEFAULT 'planned',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sessions_season_global` (`season_id`,`session_number_global`),
  KEY `idx_sessions_week` (`week_id`),
  KEY `idx_sessions_term` (`term_id`),
  CONSTRAINT `fk_sessions_season` FOREIGN KEY (`season_id`) REFERENCES `academic_seasons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sessions_term` FOREIGN KEY (`term_id`) REFERENCES `terms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sessions_week` FOREIGN KEY (`week_id`) REFERENCES `weeks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================= C. PLANNING =================
CREATE TABLE IF NOT EXISTS `term_plans` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `season_id` BIGINT UNSIGNED NOT NULL,
  `term_id` BIGINT UNSIGNED NOT NULL,
  `goal_text` TEXT NULL,
  `plan_mode` ENUM('thumn','surah') NOT NULL DEFAULT 'thumn',
  `start_hizb` DECIMAL(4,1) NULL,
  `end_hizb` DECIMAL(4,1) NULL,
  `plan_surah_from` SMALLINT UNSIGNED NULL,
  `plan_ayah_from` SMALLINT UNSIGNED NULL,
  `plan_surah_to` SMALLINT UNSIGNED NULL,
  `plan_ayah_to` SMALLINT UNSIGNED NULL,
  `expected_hifz_week_thumn` DECIMAL(5,2) NULL,
  `expected_hifz_term_ahzab` DECIMAL(5,2) NULL,
  `expected_hifz_season_ahzab` DECIMAL(5,2) NULL,
  `khatm_expected_at` VARCHAR(100) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_termplans_student_term` (`student_id`,`term_id`),
  KEY `idx_termplans_season` (`season_id`),
  CONSTRAINT `fk_termplans_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_termplans_season` FOREIGN KEY (`season_id`) REFERENCES `academic_seasons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_termplans_term` FOREIGN KEY (`term_id`) REFERENCES `terms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_termplans_range` CHECK (`end_hizb` IS NULL OR `start_hizb` IS NULL OR `end_hizb` >= `start_hizb`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `weekly_goals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `season_id` BIGINT UNSIGNED NOT NULL,
  `week_id` BIGINT UNSIGNED NOT NULL,
  `target_text` VARCHAR(300) NULL,
  `is_completed` TINYINT(1) NULL DEFAULT NULL,
  `checked_by` BIGINT UNSIGNED NULL,
  `checked_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_weeklygoal_student_week` (`student_id`,`week_id`),
  CONSTRAINT `fk_wgoal_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wgoal_week` FOREIGN KEY (`week_id`) REFERENCES `weeks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================= D. MODULAR SCORING =================
CREATE TABLE IF NOT EXISTS `scoring_modules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(40) NOT NULL COMMENT 'hifz / mowathaba / tajwid / sarraj ...',
  `name_ar` VARCHAR(120) NOT NULL,
  `max_points` DECIMAL(4,1) NOT NULL COMMENT 'manager-adjustable',
  `scope` ENUM('weekly','murajaa') NOT NULL DEFAULT 'weekly',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'inactive = grayed in UI, excluded everywhere',
  `is_in_weekly_total` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = own separate /20 (sarraj)',
  `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scoringmodules_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `session_scores` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `season_id` BIGINT UNSIGNED NOT NULL,
  `term_id` BIGINT UNSIGNED NOT NULL,
  `week_id` BIGINT UNSIGNED NOT NULL,
  `session_id` BIGINT UNSIGNED NOT NULL,
  `log_date` DATE NOT NULL,
  `module_id` BIGINT UNSIGNED NOT NULL,
  `score` DECIMAL(4,1) NOT NULL DEFAULT 0 COMMENT 'manual per-session input by hifz teacher',
  `entered_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scores_student_session_module` (`student_id`,`session_id`,`module_id`),
  KEY `idx_scores_week` (`student_id`,`week_id`),
  KEY `idx_scores_module` (`module_id`),
  CONSTRAINT `fk_scores_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_scores_session` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_scores_module` FOREIGN KEY (`module_id`) REFERENCES `scoring_modules` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `memorization_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `season_id` BIGINT UNSIGNED NOT NULL,
  `term_id` BIGINT UNSIGNED NOT NULL,
  `week_id` BIGINT UNSIGNED NOT NULL,
  `session_id` BIGINT UNSIGNED NOT NULL,
  `log_date` DATE NOT NULL,
  `log_mode` ENUM('thumn','surah') NOT NULL DEFAULT 'thumn',
  `hizb_no` DECIMAL(4,1) NULL,
  `thumn_no` TINYINT UNSIGNED NULL,
  `thumn_amount` DECIMAL(5,2) NOT NULL DEFAULT 1.00,
  `hizb_from` DECIMAL(4,1) NULL,
  `hizb_to` DECIMAL(4,1) NULL,
  `surah_from` SMALLINT UNSIGNED NULL,
  `ayah_from` SMALLINT UNSIGNED NULL,
  `surah_to` SMALLINT UNSIGNED NULL,
  `ayah_to` SMALLINT UNSIGNED NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_memolog_student_session` (`student_id`,`session_id`),
  KEY `idx_memolog_date` (`log_date`),
  KEY `idx_memolog_week` (`student_id`,`week_id`),
  KEY `idx_memolog_term` (`student_id`,`term_id`),
  CONSTRAINT `fk_memolog_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_memolog_session` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `revision_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `season_id` BIGINT UNSIGNED NOT NULL,
  `term_id` BIGINT UNSIGNED NOT NULL,
  `week_id` BIGINT UNSIGNED NOT NULL,
  `session_id` BIGINT UNSIGNED NOT NULL,
  `log_date` DATE NOT NULL,
  `hizb_from` DECIMAL(4,1) NULL,
  `hizb_to` DECIMAL(4,1) NULL,
  `murajaa_score` DECIMAL(4,1) NULL COMMENT 'practice note /20 (official = murajaa_reviews)',
  `entered_by` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  KEY `idx_revlog_student_session` (`student_id`,`session_id`),
  KEY `idx_revlog_date` (`log_date`),
  CONSTRAINT `fk_revlog_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_revlog_session` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_revlog_enteredby` FOREIGN KEY (`entered_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `murajaa_reviews` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `season_id` BIGINT UNSIGNED NOT NULL,
  `term_id` BIGINT UNSIGNED NOT NULL,
  `week_from` SMALLINT UNSIGNED NOT NULL,
  `week_to` SMALLINT UNSIGNED NOT NULL,
  `weeks_covered` TINYINT UNSIGNED NULL COMMENT 'computed by API: week_to-week_from+1',
  `session_id` BIGINT UNSIGNED NULL COMMENT 'review session in agenda',
  `hizb_from` DECIMAL(4,1) NULL,
  `hizb_to` DECIMAL(4,1) NULL,
  `surah_from` SMALLINT UNSIGNED NULL,
  `ayah_from` SMALLINT UNSIGNED NULL,
  `surah_to` SMALLINT UNSIGNED NULL,
  `ayah_to` SMALLINT UNSIGNED NULL,
  `score` DECIMAL(4,1) NOT NULL COMMENT 'official review note /20',
  `entered_by` BIGINT UNSIGNED NULL COMMENT 'murajaa teacher',
  `reviewed_at` DATE NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_murajaa_student_term` (`student_id`,`term_id`),
  CONSTRAINT `chk_murajaa_span` CHECK (`week_to` >= `week_from` AND (`week_to`-`week_from`+1) BETWEEN 1 AND 3),
  CONSTRAINT `chk_murajaa_score` CHECK (`score` BETWEEN 0 AND 20),
  CONSTRAINT `fk_murajaa_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_murajaa_session` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_murajaa_enteredby` FOREIGN KEY (`entered_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attendance` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `season_id` BIGINT UNSIGNED NOT NULL,
  `term_id` BIGINT UNSIGNED NOT NULL,
  `week_id` BIGINT UNSIGNED NOT NULL,
  `session_id` BIGINT UNSIGNED NOT NULL,
  `status` ENUM('present','absent','late','excused') NOT NULL DEFAULT 'present',
  `marked_by` BIGINT UNSIGNED NULL,
  `marked_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_att_student_session` (`student_id`,`session_id`),
  KEY `idx_att_week` (`student_id`,`week_id`),
  KEY `idx_att_status` (`status`),
  CONSTRAINT `fk_att_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_att_session` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================= E. EXAMS & REPORTS =================
CREATE TABLE IF NOT EXISTS `exams` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `season_id` BIGINT UNSIGNED NOT NULL,
  `term_id` BIGINT UNSIGNED NULL COMMENT 'NULL = final season exam',
  `exam_type` ENUM('hizb_completion','term_batch','final_season') NOT NULL,
  `exam_date` DATE NULL,
  `examiner_id` BIGINT UNSIGNED NULL,
  `overall_avg` DECIMAL(4,2) NULL,
  `examiner_report` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_exams_student_season` (`student_id`,`season_id`),
  KEY `idx_exams_type` (`exam_type`),
  CONSTRAINT `fk_exams_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_exams_examiner` FOREIGN KEY (`examiner_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_questions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_id` BIGINT UNSIGNED NOT NULL,
  `question_no` TINYINT UNSIGNED NOT NULL,
  `prompt_text` VARCHAR(500) NULL,
  `hizb_ref` DECIMAL(4,1) NULL,
  `surah_ref` SMALLINT UNSIGNED NULL,
  `ayah_from` SMALLINT UNSIGNED NULL,
  `ayah_to` SMALLINT UNSIGNED NULL,
  `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'teacher reorders',
  `model_type` ENUM('model1','model2_full','single') NULL DEFAULT 'single',
  `score` DECIMAL(4,2) NULL,
  `notes` VARCHAR(500) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_exq_exam_qno` (`exam_id`,`question_no`),
  CONSTRAINT `fk_exq_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_exq_score` CHECK (`score` IS NULL OR (`score` BETWEEN 0 AND 20))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `term_results` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `season_id` BIGINT UNSIGNED NOT NULL,
  `term_id` BIGINT UNSIGNED NOT NULL,
  `hifz_total` DECIMAL(4,2) NULL,
  `murajaa_total` DECIMAL(4,2) NULL,
  `exam_score` DECIMAL(4,2) NULL,
  `general_avg` DECIMAL(4,2) NULL,
  `teacher_notes` TEXT NULL,
  `guardian_notes` TEXT NULL,
  `supervisor_note` TEXT NULL,
  `honor_flag` ENUM('none','tashji3','intibah') NOT NULL DEFAULT 'none',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_termres_student_term` (`student_id`,`term_id`),
  CONSTRAINT `fk_termres_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `season_results` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id` BIGINT UNSIGNED NOT NULL,
  `season_id` BIGINT UNSIGNED NOT NULL,
  `total_memorized_label` VARCHAR(200) NULL,
  `total_memorized_thumn` DECIMAL(7,2) NULL,
  `hifz_total` DECIMAL(4,2) NULL,
  `murajaa_total` DECIMAL(4,2) NULL,
  `overall_avg` DECIMAL(4,2) NULL,
  `board_report` TEXT NULL,
  `honor_flag` ENUM('none','tashji3','intibah') NOT NULL DEFAULT 'none',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_seasonres_student_season` (`student_id`,`season_id`),
  CONSTRAINT `fk_seasonres_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_seasonres_season` FOREIGN KEY (`season_id`) REFERENCES `academic_seasons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================= F. APP FEATURES =================
CREATE TABLE IF NOT EXISTS `delegation_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id` BIGINT UNSIGNED NOT NULL,
  `granter_teacher_id` BIGINT UNSIGNED NOT NULL,
  `token` CHAR(64) NOT NULL,
  `duration_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  `expires_at` DATETIME NOT NULL,
  `used_by_teacher_id` BIGINT UNSIGNED NULL,
  `used_at` DATETIME NULL,
  `is_revoked` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_delegation_token` (`token`),
  KEY `idx_delegation_group` (`group_id`),
  CONSTRAINT `fk_delegation_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_delegation_granter` FOREIGN KEY (`granter_teacher_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_delegation_usedby` FOREIGN KEY (`used_by_teacher_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `announcements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `author_id` BIGINT UNSIGNED NOT NULL,
  `audience` ENUM('all','teachers','manager','my_students') NOT NULL DEFAULT 'all',
  `group_id` BIGINT UNSIGNED NULL,
  `title` VARCHAR(200) NOT NULL,
  `body` TEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ann_audience` (`audience`),
  CONSTRAINT `fk_ann_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_ann_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipient_phone` VARCHAR(30) NOT NULL,
  `channel` ENUM('whatsapp','other') NOT NULL DEFAULT 'whatsapp' COMMENT 'wa.me links, no paid API',
  `message` TEXT NOT NULL,
  `status` ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  `sent_by` BIGINT UNSIGNED NULL,
  `sent_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notif_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================= G. QURAN REFERENCE =================
CREATE TABLE IF NOT EXISTS `surahs` (
  `id` SMALLINT UNSIGNED NOT NULL COMMENT '1-114',
  `name_ar` VARCHAR(60) NOT NULL,
  `ayahs_count` SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quran_hizb_reference` (
  `hizb_no` TINYINT UNSIGNED NOT NULL,
  `juz_no` TINYINT UNSIGNED NOT NULL,
  `label_ar` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`hizb_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ================= SEEDS =================
INSERT INTO `levels` (`code`,`name_ar`,`sessions_per_week`,`thumn_per_session_label`,`thumn_per_session_value`,`thumn_per_week_value`,`ahzab_per_term`,`ahzab_per_dawra`,`duration_label`) VALUES
('L1','المستوى الأول — نصف في الأسبوع',3,'ثمن ويزيد',1.25,4.00,2.00,15.00,'4 سنوات (أربع دورات)'),
('L2','المستوى الثاني — 3 أثمان في الأسبوع',3,'ثمن',1.00,3.00,2.25,11.50,'5 سنوات وشهرين (خمس دورات)'),
('L3','المستوى الثالث — ربع في الأسبوع',3,'أقل من ثمن',0.66,2.00,1.50,7.50,'8 سنوات (ثمان دورات)')
ON DUPLICATE KEY UPDATE `name_ar`=VALUES(`name_ar`);

-- scoring modules: weekly 14+4+2=20 ; sarraj separate /20 active ; murajaa /20
INSERT INTO `scoring_modules` (`id`,`code`,`name_ar`,`max_points`,`scope`,`is_active`,`is_in_weekly_total`,`sort_order`) VALUES
(1,'hifz','الحفظ',14.0,'weekly',1,1,1),
(2,'mowathaba','المواظبة / الحضور',4.0,'weekly',1,1,2),
(3,'tajwid','التجويد',2.0,'weekly',1,1,3),
(4,'murajaa','المراجعة',20.0,'murajaa',1,1,4),
(5,'sarraj','السراج في بيان غريب القرآن',20.0,'weekly',1,0,5)
ON DUPLICATE KEY UPDATE `name_ar`=VALUES(`name_ar`),`max_points`=VALUES(`max_points`),
  `is_active`=VALUES(`is_active`),`is_in_weekly_total`=VALUES(`is_in_weekly_total`);

INSERT IGNORE INTO `surahs` (`id`,`name_ar`,`ayahs_count`) VALUES
(1,'الفاتحة',7),(2,'البقرة',286),(3,'آل عمران',200),(4,'النساء',176),
(5,'المائدة',120),(6,'الأنعام',165),(7,'الأعراف',206),(8,'الأنفال',75),
(9,'التوبة',129),(10,'يونس',109),(11,'هود',123),(12,'يوسف',111),
(13,'الرعد',43),(14,'إبراهيم',52),(15,'الحجر',99),(16,'النحل',128),
(17,'الإسراء',111),(18,'الكهف',110),(19,'مريم',98),(20,'طه',135),
(21,'الأنبياء',112),(22,'الحج',78),(23,'المؤمنون',118),(24,'النور',64),
(25,'الفرقان',77),(26,'الشعراء',227),(27,'النمل',93),(28,'القصص',88),
(29,'العنكبوت',69),(30,'الروم',60),(31,'لقمان',34),(32,'السجدة',30),
(33,'الأحزاب',73),(34,'سبأ',54),(35,'فاطر',45),(36,'يس',83),
(37,'الصافات',182),(38,'ص',88),(39,'الزمر',75),(40,'غافر',85),
(41,'فصلت',54),(42,'الشورى',53),(43,'الزخرف',89),(44,'الدخان',59),
(45,'الجاثية',37),(46,'الأحقاف',35),(47,'محمد',38),(48,'الفتح',29),
(49,'الحجرات',18),(50,'ق',45),(51,'الذاريات',60),(52,'الطور',49),
(53,'النجم',62),(54,'القمر',55),(55,'الرحمن',78),(56,'الواقعة',96),
(57,'الحديد',29),(58,'المجادلة',22),(59,'الحشر',24),(60,'الممتحنة',13),
(61,'الصف',14),(62,'الجمعة',11),(63,'المنافقون',11),(64,'التغابن',18),
(65,'الطلاق',12),(66,'التحريم',12),(67,'الملك',30),(68,'القلم',52),
(69,'الحاقة',52),(70,'المعارج',44),(71,'نوح',28),(72,'الجن',28),
(73,'المزمل',20),(74,'المدثر',56),(75,'القيامة',40),(76,'الإنسان',31),
(77,'المرسلات',50),(78,'النبأ',40),(79,'النازعات',46),(80,'عبس',42),
(81,'التكوير',29),(82,'الانفطار',19),(83,'المطففين',36),(84,'الانشقاق',25),
(85,'البروج',22),(86,'الطارق',17),(87,'الأعلى',19),(88,'الغاشية',26),
(89,'الفجر',30),(90,'البلد',20),(91,'الشمس',15),(92,'الليل',21),
(93,'الضحى',11),(94,'الشرح',8),(95,'التين',8),(96,'العلق',19),
(97,'القدر',5),(98,'البينة',8),(99,'الزلزلة',8),(100,'العاديات',11),
(101,'القارعة',11),(102,'التكاثر',8),(103,'العصر',3),(104,'الهمزة',9),
(105,'الفيل',5),(106,'قريش',4),(107,'الماعون',7),(108,'الكوثر',3),
(109,'الكافرون',6),(110,'النصر',3),(111,'المسد',5),(112,'الإخلاص',4),
(113,'الفلق',5),(114,'الناس',6);

DELIMITER //
CREATE PROCEDURE IF NOT EXISTS `seed_hizb_reference`()
BEGIN
  DECLARE n INT DEFAULT 1;
  WHILE n <= 60 DO
    INSERT IGNORE INTO `quran_hizb_reference` (`hizb_no`,`juz_no`,`label_ar`)
    VALUES (n, CEIL(n/2), CONCAT('الحزب ', n));
    SET n = n + 1;
  END WHILE;
END //
DELIMITER ;
CALL `seed_hizb_reference`();
DROP PROCEDURE IF EXISTS `seed_hizb_reference`;

-- default season skeleton: 6 terms x 7 weeks (42 weeks / 126 sessions)
INSERT IGNORE INTO `academic_seasons` (`id`,`name`,`hijri_year`,`start_date`,`end_date`,`total_weeks`,`total_sessions`,`is_current`)
VALUES (1,'2025/2026','1447','2025-09-01','2026-07-31',42,126,1);

INSERT IGNORE INTO `terms` (`id`,`season_id`,`term_number`,`name_ar`,`start_week`,`end_week`,`start_session_no`,`end_session_no`) VALUES
(1,1,1,'الفصل الأول',1,7,1,21),
(2,1,2,'الفصل الثاني',8,14,22,42),
(3,1,3,'الفصل الثالث',15,21,43,63),
(4,1,4,'الفصل الرابع',22,28,64,84),
(5,1,5,'الفصل الخامس',29,35,85,105),
(6,1,6,'الفصل السادس',36,42,106,126);

INSERT IGNORE INTO `centers` (`id`,`name`,`city`) VALUES (1,'المركز النموذجي','—');
INSERT IGNORE INTO `users` (`id`,`full_name`,`email`,`role`,`is_active`)
VALUES (1,'المشرف العام','admin@example.org','admin',1);

-- ================= VIEWS (Chart.js feeds) =================
CREATE OR REPLACE VIEW `v_session_totals` AS
SELECT sc.student_id, sc.season_id, sc.term_id, sc.week_id, sc.session_id, sc.log_date,
  ROUND(SUM(sc.score),1) AS total_score, COUNT(*) AS modules_scored
FROM session_scores sc
JOIN scoring_modules mo ON mo.id=sc.module_id AND mo.is_active=1 AND mo.is_in_weekly_total=1
GROUP BY sc.student_id, sc.season_id, sc.term_id, sc.week_id, sc.session_id, sc.log_date;

CREATE OR REPLACE VIEW `v_weekly_progress` AS
SELECT m.student_id, m.season_id, m.term_id, m.week_id,
  SUM(m.thumn_amount) AS total_thumn,
  ROUND(SUM(m.thumn_amount)/8, 2) AS total_ahzab,
  (SELECT ROUND(AVG(t.total_score),2) FROM v_session_totals t
    WHERE t.student_id=m.student_id AND t.week_id=m.week_id) AS avg_score,
  COUNT(*) AS sessions_logged
FROM memorization_logs m
GROUP BY m.student_id, m.season_id, m.term_id, m.week_id;

CREATE OR REPLACE VIEW `v_weekly_murajaa` AS
SELECT student_id, season_id, term_id, week_id,
  ROUND(AVG(murajaa_score),2) AS avg_murajaa, COUNT(*) AS sessions_n
FROM revision_logs GROUP BY student_id, season_id, term_id, week_id;

CREATE OR REPLACE VIEW `v_attendance_rate` AS
SELECT a.student_id, a.season_id, a.term_id,
  COUNT(*) AS total_sessions,
  SUM(a.status='present') AS present_count,
  SUM(a.status='absent') AS absent_count,
  SUM(a.status='late') AS late_count,
  SUM(a.status='excused') AS excused_count,
  ROUND(100*SUM(a.status IN ('present','late'))/COUNT(*),1) AS attendance_rate_pct
FROM attendance a GROUP BY a.student_id, a.season_id, a.term_id;

-- one row per (student, term, exam_type): any number of terms
CREATE OR REPLACE VIEW `v_term_quiz_avgs` AS
SELECT e.student_id, e.season_id, e.term_id, e.exam_type,
  ROUND(AVG(q.score),2) AS avg_score, COUNT(*) AS questions_count
FROM exams e JOIN exam_questions q ON q.exam_id=e.id
GROUP BY e.student_id, e.season_id, e.term_id, e.exam_type;

CREATE OR REPLACE VIEW `v_separate_module_avgs` AS
SELECT sc.student_id, sc.season_id, sc.week_id, mo.code AS module_code, mo.name_ar AS module_name,
  ROUND(AVG(sc.score),2) AS avg_score, COUNT(*) AS sessions_scored
FROM session_scores sc JOIN scoring_modules mo ON mo.id=sc.module_id
WHERE mo.is_active=1 AND mo.is_in_weekly_total=0
GROUP BY sc.student_id, sc.season_id, sc.week_id, mo.code, mo.name_ar;

-- season inputs for final formula (app divides by 2 + n_terms; default /8):
-- final = (avg_murajaa + avg_weekly + SUM(term quiz avgs incl. final)) / (2 + n)
CREATE OR REPLACE VIEW `v_student_season_avgs` AS
SELECT s.id AS student_id, s.full_name,
  (SELECT ROUND(AVG(mr.score),2) FROM murajaa_reviews mr
    WHERE mr.student_id=s.id AND mr.season_id=1) AS avg_murajaa,
  (SELECT ROUND(AVG(t.total_score),2) FROM v_session_totals t
    WHERE t.student_id=s.id AND t.season_id=1) AS avg_weekly,
  (SELECT ROUND(AVG(x.avg_score),2) FROM v_separate_module_avgs x
    WHERE x.student_id=s.id AND x.season_id=1 AND x.module_code='sarraj') AS avg_sarraj
FROM students s;

CREATE OR REPLACE VIEW `v_murajaa_cycles` AS
SELECT mr.id, mr.student_id, s.full_name, mr.season_id, mr.term_id,
  mr.week_from, mr.week_to, mr.weeks_covered, mr.hizb_from, mr.hizb_to,
  mr.score, u.full_name AS reviewer_name, mr.reviewed_at
FROM murajaa_reviews mr JOIN students s ON s.id=mr.student_id
LEFT JOIN users u ON u.id=mr.entered_by
ORDER BY mr.student_id, mr.week_from;

CREATE OR REPLACE VIEW `v_scoring_check` AS
SELECT 'weekly_total' AS track, ROUND(SUM(max_points),1) AS active_total,
  GROUP_CONCAT(CONCAT(code,':',max_points) SEPARATOR ' + ') AS breakdown
FROM scoring_modules WHERE is_active=1 AND is_in_weekly_total=1 AND scope='weekly'
UNION ALL
SELECT 'murajaa', ROUND(SUM(max_points),1), GROUP_CONCAT(CONCAT(code,':',max_points) SEPARATOR ' + ')
FROM scoring_modules WHERE is_active=1 AND scope='murajaa'
UNION ALL
SELECT CONCAT('separate:',code), max_points, name_ar
FROM scoring_modules WHERE is_active=1 AND is_in_weekly_total=0;
-- expected: weekly_total = 20.0 | murajaa = 20.0 | separate:sarraj = 20.0

CREATE OR REPLACE VIEW `v_season_dashboard` AS
SELECT s.id AS student_id, s.full_name, s.level_id, s.group_id,
  sp.season_id,
  (SELECT COALESCE(SUM(m.thumn_amount),0) FROM memorization_logs m
    WHERE m.student_id=s.id AND m.season_id=sp.season_id) AS season_thumn,
  (SELECT ROUND(COALESCE(AVG(t.total_score),0),2) FROM v_session_totals t
    WHERE t.student_id=s.id AND t.season_id=sp.season_id) AS season_avg_score,
  (SELECT ROUND(AVG(x.avg_score),2) FROM v_separate_module_avgs x
    WHERE x.student_id=s.id AND x.season_id=sp.season_id AND x.module_code='sarraj') AS season_avg_sarraj,
  (SELECT ROUND(100*SUM(a.status IN ('present','late'))/COUNT(*),1) FROM attendance a
    WHERE a.student_id=s.id AND a.season_id=sp.season_id) AS season_attendance_pct,
  r.overall_avg AS season_overall_avg, r.honor_flag
FROM students s
JOIN (SELECT DISTINCT student_id, season_id FROM term_plans
      UNION SELECT DISTINCT student_id, season_id FROM memorization_logs
      UNION SELECT DISTINCT student_id, season_id FROM attendance) sp ON sp.student_id=s.id
LEFT JOIN season_results r ON r.student_id=s.id AND r.season_id=sp.season_id;

CREATE OR REPLACE VIEW `v_announcements_feed` AS
SELECT a.id, a.author_id, u.full_name AS author_name, a.audience, a.group_id,
  g.name AS group_name, a.title, a.body, a.created_at
FROM announcements a JOIN users u ON u.id=a.author_id
LEFT JOIN `groups` g ON g.id=a.group_id
ORDER BY a.created_at DESC;

-- ================= S12 PERFORMANCE INDEXES =================
-- Composite lookups used by season aggregates, ordering and filters.
CREATE INDEX `idx_s12_exams_lookup` ON `exams`(`student_id`,`season_id`,`term_id`,`exam_type`);
CREATE INDEX `idx_s12_exq_order` ON `exam_questions`(`exam_id`,`sort_order`,`question_no`);
CREATE INDEX `idx_s12_scores_season` ON `session_scores`(`student_id`,`season_id`);
CREATE INDEX `idx_s12_revlog_season` ON `revision_logs`(`student_id`,`season_id`);
CREATE INDEX `idx_s12_murajaa_season` ON `murajaa_reviews`(`student_id`,`season_id`);
CREATE INDEX `idx_s12_att_season` ON `attendance`(`student_id`,`season_id`,`term_id`);
CREATE INDEX `idx_s12_termres_season` ON `term_results`(`student_id`,`season_id`);
CREATE INDEX `idx_s12_memolog_season` ON `memorization_logs`(`student_id`,`season_id`);
