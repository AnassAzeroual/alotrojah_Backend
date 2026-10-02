<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Chart.js feeds, must match docs/database/quran_memorization_db.sql exactly.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE OR REPLACE VIEW `v_session_totals` AS
SELECT sc.student_id, sc.season_id, sc.term_id, sc.week_id, sc.session_id, sc.log_date,
  ROUND(SUM(sc.score),1) AS total_score, COUNT(*) AS modules_scored
FROM session_scores sc
JOIN scoring_modules mo ON mo.id=sc.module_id AND mo.is_active=1 AND mo.is_in_weekly_total=1
GROUP BY sc.student_id, sc.season_id, sc.term_id, sc.week_id, sc.session_id, sc.log_date');

        DB::statement('CREATE OR REPLACE VIEW `v_weekly_progress` AS
SELECT m.student_id, m.season_id, m.term_id, m.week_id,
  SUM(m.thumn_amount) AS total_thumn,
  ROUND(SUM(m.thumn_amount)/8, 2) AS total_ahzab,
  (SELECT ROUND(AVG(t.total_score),2) FROM v_session_totals t
    WHERE t.student_id=m.student_id AND t.week_id=m.week_id) AS avg_score,
  COUNT(*) AS sessions_logged
FROM memorization_logs m
GROUP BY m.student_id, m.season_id, m.term_id, m.week_id');

        DB::statement('CREATE OR REPLACE VIEW `v_weekly_murajaa` AS
SELECT student_id, season_id, term_id, week_id,
  ROUND(AVG(murajaa_score),2) AS avg_murajaa, COUNT(*) AS sessions_n
FROM revision_logs GROUP BY student_id, season_id, term_id, week_id');

        DB::statement('CREATE OR REPLACE VIEW `v_attendance_rate` AS
SELECT a.student_id, a.season_id, a.term_id,
  COUNT(*) AS total_sessions,
  SUM(a.status=\'present\') AS present_count,
  SUM(a.status=\'absent\') AS absent_count,
  SUM(a.status=\'late\') AS late_count,
  SUM(a.status=\'excused\') AS excused_count,
  ROUND(100*SUM(a.status IN (\'present\',\'late\'))/COUNT(*),1) AS attendance_rate_pct
FROM attendance a GROUP BY a.student_id, a.season_id, a.term_id');

        DB::statement('CREATE OR REPLACE VIEW `v_term_quiz_avgs` AS
SELECT e.student_id, e.season_id, e.term_id, e.exam_type,
  ROUND(AVG(q.score),2) AS avg_score, COUNT(*) AS questions_count
FROM exams e JOIN exam_questions q ON q.exam_id=e.id
GROUP BY e.student_id, e.season_id, e.term_id, e.exam_type');

        DB::statement('CREATE OR REPLACE VIEW `v_separate_module_avgs` AS
SELECT sc.student_id, sc.season_id, sc.week_id, mo.code AS module_code, mo.name_ar AS module_name,
  ROUND(AVG(sc.score),2) AS avg_score, COUNT(*) AS sessions_scored
FROM session_scores sc JOIN scoring_modules mo ON mo.id=sc.module_id
WHERE mo.is_active=1 AND mo.is_in_weekly_total=0
GROUP BY sc.student_id, sc.season_id, sc.week_id, mo.code, mo.name_ar');

        // season inputs for final formula (app divides by 2 + n_terms; default /8):
        // final = (avg_murajaa + avg_weekly + SUM(term quiz avgs incl. final)) / (2 + n)
        // inputs follow the CURRENT season (academic_seasons.is_current=1) — never a hardcoded id
        DB::statement('CREATE OR REPLACE VIEW `v_student_season_avgs` AS
SELECT s.id AS student_id, s.full_name, se.id AS season_id,
  (SELECT ROUND(AVG(mr.score),2) FROM murajaa_reviews mr
    WHERE mr.student_id=s.id AND mr.season_id=se.id) AS avg_murajaa,
  (SELECT ROUND(AVG(t.total_score),2) FROM v_session_totals t
    WHERE t.student_id=s.id AND t.season_id=se.id) AS avg_weekly,
  (SELECT ROUND(AVG(x.avg_score),2) FROM v_separate_module_avgs x
    WHERE x.student_id=s.id AND x.season_id=se.id AND x.module_code=\'sarraj\') AS avg_sarraj
FROM students s JOIN academic_seasons se ON se.is_current=1');

        DB::statement('CREATE OR REPLACE VIEW `v_murajaa_cycles` AS
SELECT mr.id, mr.student_id, s.full_name, mr.season_id, mr.term_id,
  mr.week_from, mr.week_to, mr.weeks_covered, mr.hizb_from, mr.hizb_to,
  mr.score, u.full_name AS reviewer_name, mr.reviewed_at
FROM murajaa_reviews mr JOIN students s ON s.id=mr.student_id
LEFT JOIN users u ON u.id=mr.entered_by
ORDER BY mr.student_id, mr.week_from');

        DB::statement('CREATE OR REPLACE VIEW `v_scoring_check` AS
SELECT \'weekly_total\' AS track, ROUND(SUM(max_points),1) AS active_total,
  GROUP_CONCAT(CONCAT(code,\':\',max_points) SEPARATOR \' + \') AS breakdown
FROM scoring_modules WHERE is_active=1 AND is_in_weekly_total=1 AND scope=\'weekly\'
UNION ALL
SELECT \'murajaa\', ROUND(SUM(max_points),1), GROUP_CONCAT(CONCAT(code,\':\',max_points) SEPARATOR \' + \')
FROM scoring_modules WHERE is_active=1 AND scope=\'murajaa\'
UNION ALL
SELECT CONCAT(\'separate:\',code), max_points, name_ar
FROM scoring_modules WHERE is_active=1 AND is_in_weekly_total=0');

        DB::statement('CREATE OR REPLACE VIEW `v_season_dashboard` AS
SELECT s.id AS student_id, s.full_name, s.level_id, s.group_id,
  sp.season_id,
  (SELECT COALESCE(SUM(m.thumn_amount),0) FROM memorization_logs m
    WHERE m.student_id=s.id AND m.season_id=sp.season_id) AS season_thumn,
  (SELECT ROUND(COALESCE(AVG(t.total_score),0),2) FROM v_session_totals t
    WHERE t.student_id=s.id AND t.season_id=sp.season_id) AS season_avg_score,
  (SELECT ROUND(AVG(x.avg_score),2) FROM v_separate_module_avgs x
    WHERE x.student_id=s.id AND x.season_id=sp.season_id AND x.module_code=\'sarraj\') AS season_avg_sarraj,
  (SELECT ROUND(100*SUM(a.status IN (\'present\',\'late\'))/COUNT(*),1) FROM attendance a
    WHERE a.student_id=s.id AND a.season_id=sp.season_id) AS season_attendance_pct,
  r.overall_avg AS season_overall_avg, r.honor_flag
FROM students s
JOIN (SELECT DISTINCT student_id, season_id FROM term_plans
      UNION SELECT DISTINCT student_id, season_id FROM memorization_logs
      UNION SELECT DISTINCT student_id, season_id FROM attendance) sp ON sp.student_id=s.id
LEFT JOIN season_results r ON r.student_id=s.id AND r.season_id=sp.season_id');

        DB::statement('CREATE OR REPLACE VIEW `v_announcements_feed` AS
SELECT a.id, a.author_id, u.full_name AS author_name, a.audience, a.group_id,
  g.name AS group_name, a.title, a.body, a.created_at
FROM announcements a JOIN users u ON u.id=a.author_id
LEFT JOIN `groups` g ON g.id=a.group_id
ORDER BY a.created_at DESC');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS `v_announcements_feed`');
        DB::statement('DROP VIEW IF EXISTS `v_season_dashboard`');
        DB::statement('DROP VIEW IF EXISTS `v_scoring_check`');
        DB::statement('DROP VIEW IF EXISTS `v_murajaa_cycles`');
        DB::statement('DROP VIEW IF EXISTS `v_student_season_avgs`');
        DB::statement('DROP VIEW IF EXISTS `v_separate_module_avgs`');
        DB::statement('DROP VIEW IF EXISTS `v_term_quiz_avgs`');
        DB::statement('DROP VIEW IF EXISTS `v_attendance_rate`');
        DB::statement('DROP VIEW IF EXISTS `v_weekly_murajaa`');
        DB::statement('DROP VIEW IF EXISTS `v_weekly_progress`');
        DB::statement('DROP VIEW IF EXISTS `v_session_totals`');
    }
};
