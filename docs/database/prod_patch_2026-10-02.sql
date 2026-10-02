-- ============================================================
-- PROD PATCH — 2026-10-02 (run in phpMyAdmin on alotr15q_prod)
-- Brings prod schema in line with the fix/priority-review branch.
-- Prod was imported from the guardians-era dump (27 tables);
-- since then the schema changed twice:
--   1) guardians removed (commit d6e01f9): table dropped,
--      students.guardian_id + term_results.guardian_notes dropped,
--      role ENUM loses 'guardian', 7 accounts become 'student'.
--   2) v_student_season_avgs now follows is_current season (f9302f3).
-- ============================================================

-- STEP 0 — VERIFY current prod state first (run these, expect):
--   SHOW TABLES LIKE 'guardians';                 -> 1 row (proves patch needed)
--   SHOW COLUMNS FROM users LIKE 'role';          -> ENUM(...,'guardian',...)
--   SHOW CREATE VIEW v_student_season_avgs;       -> contains 'season_id=1'
-- If SHOW TABLES LIKE 'guardians' returns NOTHING, only run STEP 2.

-- STEP 1 — remove guardians structure (order matters: FK before table)
ALTER TABLE `students` DROP FOREIGN KEY `fk_students_guardian`;
ALTER TABLE `students` DROP COLUMN `guardian_id`;
ALTER TABLE `term_results` DROP COLUMN `guardian_notes`;
DROP TABLE `guardians`;

-- STEP 2 — view fix: follow the CURRENT season, not hardcoded id 1
CREATE OR REPLACE VIEW `v_student_season_avgs` AS
SELECT s.id AS student_id, s.full_name, se.id AS season_id,
  (SELECT ROUND(AVG(mr.score),2) FROM murajaa_reviews mr
    WHERE mr.student_id=s.id AND mr.season_id=se.id) AS avg_murajaa,
  (SELECT ROUND(AVG(t.total_score),2) FROM v_session_totals t
    WHERE t.student_id=s.id AND t.season_id=se.id) AS avg_weekly,
  (SELECT ROUND(AVG(x.avg_score),2) FROM v_separate_module_avgs x
    WHERE x.student_id=s.id AND x.season_id=se.id AND x.module_code='sarraj') AS avg_sarraj
FROM students s JOIN academic_seasons se ON se.is_current=1;

-- STEP 3 — convert the 7 former guardian accounts to student role
-- (must run BEFORE shrinking the ENUM; mirrors dev ids 12-18)
UPDATE `users` SET `role` = 'student' WHERE `role` = 'guardian';

-- STEP 4 — shrink the role ENUM to match the dump exactly
ALTER TABLE `users`
  MODIFY `role` ENUM('admin','supervisor','teacher','student','board')
  NOT NULL DEFAULT 'teacher';

-- STEP 5 — verify afterwards (expect):
--   SHOW TABLES LIKE 'guardians';                          -> empty
--   SELECT role, COUNT(*) FROM users GROUP BY role;        -> no 'guardian'
--   SELECT COUNT(*) FROM information_schema.tables
--     WHERE table_schema = DATABASE()
--       AND table_type = 'BASE TABLE';                     -> 26
--   SELECT * FROM v_student_season_avgs;                   -> rows with real averages
