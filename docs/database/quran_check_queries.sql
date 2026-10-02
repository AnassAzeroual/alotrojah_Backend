-- ============================================================
-- CHECK QUERIES — simulate app pages (Laravel/Angular later)
-- Run: mysql -u root -p quran_memorization < quran_check_queries.sql
-- Or copy/paste blocks one by one.
-- ============================================================
USE `quran_memorization`;

-- ================= A. ISOLATION AUDITS (must all pass) =================
-- A0. row counts per table
SELECT 'centers' t, COUNT(*) c FROM centers UNION ALL
SELECT 'users', COUNT(*) FROM users UNION ALL
SELECT 'groups', COUNT(*) FROM `groups` UNION ALL
SELECT 'students', COUNT(*) FROM students UNION ALL
SELECT 'weeks', COUNT(*) FROM weeks UNION ALL
SELECT 'sessions', COUNT(*) FROM sessions UNION ALL
SELECT 'memorization_logs', COUNT(*) FROM memorization_logs UNION ALL
SELECT 'session_scores', COUNT(*) FROM session_scores UNION ALL
SELECT 'murajaa_reviews', COUNT(*) FROM murajaa_reviews UNION ALL
SELECT 'attendance', COUNT(*) FROM attendance UNION ALL
SELECT 'exams', COUNT(*) FROM exams UNION ALL
SELECT 'exam_questions', COUNT(*) FROM exam_questions;
-- expect: centers=3 users=12 groups=5 students=7 weeks=42 sessions=126
-- memologs=42 scores=168 murajaa_reviews=3 attendance=42 exams=10 questions=44

-- A1. centers with their own totals (page: قائمة المراكز)
SELECT c.id, c.name, c.city,
  (SELECT COUNT(*) FROM `groups` g WHERE g.center_id=c.id) AS groups_count,
  (SELECT COUNT(*) FROM students s WHERE s.center_id=c.id) AS students_count,
  (SELECT COUNT(*) FROM users u WHERE u.center_id=c.id AND u.role='teacher') AS teachers_count
FROM centers c ORDER BY c.id;

-- A2. mismatch students.center vs groups.center (must return 0 rows)
SELECT s.id, s.full_name, s.center_id AS s_center, g.center_id AS g_center
FROM students s JOIN `groups` g ON g.id=s.group_id
WHERE s.center_id <> g.center_id;

-- A3. teachers working in >1 center (must return 0 rows)
SELECT u.id, u.full_name, COUNT(DISTINCT g.center_id) AS centers_n
FROM users u JOIN `groups` g ON g.teacher_id=u.id
GROUP BY u.id, u.full_name HAVING centers_n > 1;

-- A4. students without a group (must return 0 rows)
SELECT s.id, s.full_name, s.center_id
FROM students s WHERE s.group_id IS NULL;

-- A5. users per center (page: طاقم كل مركز — one user = one center_id by design)
SELECT center_id, GROUP_CONCAT(CONCAT(id,':',full_name,'[',role,']') SEPARATOR ' | ') AS staff
FROM users WHERE center_id IS NOT NULL GROUP BY center_id ORDER BY center_id;

-- ================= B. PAGE SIMULATIONS =================
-- B1. page: الحلقات + filter by center (Angular filter: ?center_id=1)
SELECT g.id, g.name, c.name AS center, l.name_ar AS level, u.full_name AS teacher,
  g.schedule_days,
  (SELECT COUNT(*) FROM students s WHERE s.group_id=g.id) AS students_n
FROM `groups` g JOIN centers c ON c.id=g.center_id
JOIN levels l ON l.id=g.level_id LEFT JOIN users u ON u.id=g.teacher_id
/* add: WHERE g.center_id=1 */ ORDER BY g.center_id, g.id;

-- B2. page: الطلاب + filters (center / group / level / mode / status)
SELECT s.id, s.full_name, c.name AS center, g.name AS grp, l.name_ar AS level,
  s.student_type, s.memorization_mode, s.status
FROM students s LEFT JOIN centers c ON c.id=s.center_id
LEFT JOIN `groups` g ON g.id=s.group_id LEFT JOIN levels l ON l.id=s.level_id
/* filters: AND s.center_id=2 AND s.level_id=1 AND s.status='active' */
ORDER BY s.center_id, s.id;

-- B3. page: الخطة التفصيلية للموسم (student 3 = surah mode example)
SELECT t.term_number, t.name_ar, p.plan_mode, p.goal_text,
  p.start_hizb, p.end_hizb, p.plan_surah_from, p.plan_ayah_from, p.plan_surah_to, p.plan_ayah_to
FROM term_plans p JOIN terms t ON t.id=p.term_id
WHERE p.student_id=3 AND p.season_id=1 ORDER BY t.term_number;

-- B4. page: المتابعة الأسبوعية (student 1, week 1: memorization + total + revision + attendance)
SELECT m.session_id, m.log_date, m.hizb_from, m.hizb_to, m.thumn_amount,
  t.total_score,
  r.murajaa_score AS murajaa, a.status AS attendance
FROM memorization_logs m
LEFT JOIN v_session_totals t ON t.student_id=m.student_id AND t.session_id=m.session_id
LEFT JOIN revision_logs r ON r.student_id=m.student_id AND r.session_id=m.session_id
LEFT JOIN attendance a ON a.student_id=m.student_id AND a.session_id=m.session_id
WHERE m.student_id=1 AND m.week_id=1 ORDER BY m.session_id;

-- B4b. module breakdown per session (hifz/mowathaba/tajwid/sarraj inputs)
SELECT sc.session_id, mo.code AS module, sc.score, mo.max_points, mo.is_in_weekly_total
FROM session_scores sc JOIN scoring_modules mo ON mo.id=sc.module_id
WHERE sc.student_id=1 AND sc.week_id=1 AND mo.is_active=1
ORDER BY sc.session_id, mo.sort_order;

-- B4c. surah-mode logs (students 3+6: Baqarah + Nas)
SELECT m.student_id, s.full_name, m.session_id, m.surah_from, m.ayah_from, m.surah_to, m.ayah_to,
  su.name_ar AS surah_name
FROM memorization_logs m JOIN students s ON s.id=m.student_id
LEFT JOIN surahs su ON su.id=m.surah_from
WHERE m.log_mode='surah' ORDER BY m.student_id, m.session_id;

-- B5. page: جدول المواظبة (student 1, sessions 1-21 = term 1 now)
SELECT ses.session_number_global AS hissa, w.week_number_global AS week_no,
  a.status FROM attendance a
JOIN sessions ses ON ses.id=a.session_id JOIN weeks w ON w.id=a.week_id
WHERE a.student_id=1 AND a.season_id=1 ORDER BY ses.session_number_global;

-- B6. page: اختبار الفصل (term_batch detail, exam 2)
SELECT e.id, e.exam_date, q.question_no, q.prompt_text, q.score, q.notes
FROM exams e JOIN exam_questions q ON q.exam_id=e.id
WHERE e.id=2 ORDER BY q.question_no;

-- B7. page: الاختبار النهائي (final, exam 10, 7 questions)
SELECT q.question_no, q.prompt_text, q.score FROM exam_questions q
WHERE q.exam_id=10 ORDER BY q.question_no;

-- B8. page: تقرير الفصل (student 1, term 1)
SELECT s.full_name, t.name_ar, r.hifz_total, r.murajaa_total, r.exam_score,
  r.general_avg, r.honor_flag, r.teacher_notes, r.supervisor_note
FROM term_results r JOIN students s ON s.id=r.student_id JOIN terms t ON t.id=r.term_id
WHERE r.student_id=1 AND r.term_id=1;

-- B9. page: تقرير الموسم (all students, season 1)
SELECT s.full_name, c.name AS center, sr.total_memorized_label, sr.overall_avg,
  sr.honor_flag, sr.board_report
FROM season_results sr JOIN students s ON s.id=sr.student_id
LEFT JOIN centers c ON c.id=s.center_id
WHERE sr.season_id=1 ORDER BY sr.overall_avg DESC;

-- B10. page: لوحة الشرف (filter tashji3 / intibah)
SELECT s.full_name, c.name AS center, r.term_id, r.general_avg, r.honor_flag
FROM term_results r JOIN students s ON s.id=r.student_id
LEFT JOIN centers c ON c.id=s.center_id
WHERE r.honor_flag <> 'none' ORDER BY r.honor_flag, r.general_avg DESC;

-- ================= C. CHART.JS FEEDS =================
-- C1. line/bar: weekly thumn + avg per student (uses view)
SELECT * FROM v_weekly_progress WHERE student_id=1 AND season_id=1 ORDER BY week_id;

-- C2. compare centers: avg session total per center (bar chart)
SELECT c.name AS center, ROUND(AVG(t.total_score),2) AS avg_score,
  ROUND(SUM(m.thumn_amount),1) AS total_thumn, COUNT(*) AS logs
FROM memorization_logs m JOIN students s ON s.id=m.student_id
JOIN centers c ON c.id=s.center_id
LEFT JOIN v_session_totals t ON t.student_id=m.student_id AND t.session_id=m.session_id
GROUP BY c.name ORDER BY avg_score DESC;
-- expect: center1 highest, center3 lowest -> proves separation works

-- C3. doughnut: attendance distribution per center
SELECT c.name AS center, a.status, COUNT(*) AS n
FROM attendance a JOIN students s ON s.id=a.student_id
JOIN centers c ON c.id=s.center_id
GROUP BY c.name, a.status ORDER BY c.name, a.status;

-- C4. radar/bars: exam averages per student per type (any term count)
SELECT * FROM v_term_quiz_avgs WHERE season_id=1 ORDER BY student_id, term_id;

-- C5. dashboard cards per center (uses fixed view)
SELECT c.name AS center, COUNT(*) AS students,
  ROUND(AVG(d.season_avg_score),2) AS avg_score,
  ROUND(AVG(d.season_avg_sarraj),2) AS avg_sarraj,
  ROUND(AVG(d.season_attendance_pct),1) AS avg_attendance
FROM v_season_dashboard d JOIN students s ON s.id=d.student_id
JOIN centers c ON c.id=s.center_id WHERE d.season_id=1 GROUP BY c.name;

-- C6. honor counts per center (stacked bar: تشجيع vs انتبه)
SELECT c.name AS center, r.honor_flag, COUNT(*) AS n
FROM term_results r JOIN students s ON s.id=r.student_id
JOIN centers c ON c.id=s.center_id
GROUP BY c.name, r.honor_flag ORDER BY c.name, r.honor_flag;

-- ================= D. NEW MODULES / REVIEWS / FEATURES =================
-- D1. manager guard: weekly_total must be 20.0 (sarraj separate)
SELECT * FROM v_scoring_check;

-- D2. sarraj weekly averages (separate /20 track)
SELECT * FROM v_separate_module_avgs WHERE module_code='sarraj' ORDER BY student_id, week_id;

-- D3. official review cycles with reviewer names
SELECT * FROM v_murajaa_cycles;

-- D4. season inputs for final formula: app computes
-- final = (avg_murajaa + avg_weekly + SUM(term avgs incl final)) / (2 + n_terms)
SELECT * FROM v_student_season_avgs ORDER BY student_id;

-- D5. delegation token + announcements + goals + notifications
SELECT d.id, g.name AS grp, u.full_name AS granter, d.duration_minutes, d.expires_at,
  d.used_by_teacher_id, d.is_revoked
FROM delegation_tokens d JOIN `groups` g ON g.id=d.group_id
JOIN users u ON u.id=d.granter_teacher_id;
SELECT * FROM v_announcements_feed;
SELECT wg.student_id, s.full_name, wg.week_id, wg.target_text, wg.is_completed
FROM weekly_goals wg JOIN students s ON s.id=wg.student_id ORDER BY wg.student_id, wg.week_id;
SELECT id, recipient_phone, channel, LEFT(message,40) AS msg, status FROM notifications_log;

-- D6. review weeks in calendar (7,14,21,28,35,42) + their exam/revision sessions
SELECT w.week_number_global, w.week_type, t.name_ar
FROM weeks w JOIN terms t ON t.id=w.term_id WHERE w.week_type='review' ORDER BY 1;
SELECT session_number_global, session_type, planned_date, status FROM sessions
WHERE week_id=7 ORDER BY session_number_global;
