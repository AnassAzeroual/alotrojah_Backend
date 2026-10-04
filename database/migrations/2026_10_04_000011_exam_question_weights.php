<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Item 6 (2026-10-04): exam scoring switches mean → weighted sum.
// - exam_questions.max_score: per-question weight (backfilled 20 so no old
//   score turns invalid; pre-change exams may total above 20 until the wipe).
// - v_term_quiz_avgs: AVG(q.score) → SUM(q.score) (SUM skips NULLs like AVG).
// - overall_avg recomputed under the new rule (SUM of scored questions).
// No prod patch: prod will be wiped fresh later (owner decision).
return new class extends Migration
{
    public function up(): void
    {
        // overall_avg was sized for means (≤20); sums need headroom.
        DB::statement('ALTER TABLE `exams` MODIFY `overall_avg` DECIMAL(5,2) NULL');
        Schema::table('exam_questions', function (Blueprint $table) {
            $table->decimal('max_score', 4, 2)->default(20);
        });
        DB::statement('ALTER TABLE `exam_questions` ADD CONSTRAINT `chk_exq_max` CHECK (`max_score` BETWEEN 0 AND 20)');

        DB::statement('CREATE OR REPLACE VIEW `v_term_quiz_avgs` AS
SELECT e.student_id, e.season_id, e.term_id, e.exam_type,
  ROUND(SUM(q.score),2) AS avg_score, COUNT(*) AS questions_count
FROM exams e JOIN exam_questions q ON q.exam_id=e.id
GROUP BY e.student_id, e.season_id, e.term_id, e.exam_type');

        DB::statement('UPDATE `exams` e SET `overall_avg` =
          (SELECT ROUND(SUM(q.score),2) FROM `exam_questions` q
           WHERE q.exam_id=e.id AND q.score IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('UPDATE `exams` e SET `overall_avg` =
          (SELECT ROUND(AVG(q.score),2) FROM `exam_questions` q
           WHERE q.exam_id=e.id AND q.score IS NOT NULL)');
        DB::statement('CREATE OR REPLACE VIEW `v_term_quiz_avgs` AS
SELECT e.student_id, e.season_id, e.term_id, e.exam_type,
  ROUND(AVG(q.score),2) AS avg_score, COUNT(*) AS questions_count
FROM exams e JOIN exam_questions q ON q.exam_id=e.id
GROUP BY e.student_id, e.season_id, e.term_id, e.exam_type');
        DB::statement('ALTER TABLE `exam_questions` DROP CONSTRAINT `chk_exq_max`');
        Schema::table('exam_questions', function (Blueprint $table) {
            $table->dropColumn('max_score');
        });
    }
};
