<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// §2.14a (2026-10-05, per owner decision): scoreless pupils must NOT drag the
// center average down. v_season_dashboard.season_avg_score yielded 0 for pupils
// with no session totals (COALESCE); it now yields NULL like season_avg_sarraj
// already did, so AVG() skips them. Every frontend consumer null-handles
// (`?? '—'`, `!== null` filters, `number | null` types) — verified by grep.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE OR REPLACE VIEW `v_season_dashboard` AS
SELECT s.id AS student_id, s.full_name, s.level_id, s.group_id,
  sp.season_id,
  (SELECT COALESCE(SUM(m.thumn_amount),0) FROM memorization_logs m
    WHERE m.student_id=s.id AND m.season_id=sp.season_id) AS season_thumn,
  (SELECT ROUND(AVG(t.total_score),2) FROM v_session_totals t
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
    }

    public function down(): void
    {
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
    }
};
