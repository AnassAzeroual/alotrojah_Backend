<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bridge already-saved term exams into the official term_results table.
 *
 * Only the latest scored `term_batch` exam per student/term is used.
 * `exam_score` is authoritative; `general_avg` is filled only when empty or
 * still equal to the previous auto-derived exam score, so teacher-entered
 * averages are preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exams = DB::table('exams')
            ->where('exam_type', 'term_batch')
            ->whereNotNull('term_id')
            ->whereNotNull('overall_avg')
            ->orderByDesc('exam_date')
            ->orderByDesc('id')
            ->get(['student_id', 'season_id', 'term_id', 'overall_avg'])
            ->unique(fn ($exam) => $exam->student_id.':'.$exam->term_id);

        foreach ($exams as $exam) {
            $result = DB::table('term_results')
                ->where('student_id', $exam->student_id)
                ->where('term_id', $exam->term_id)
                ->first();

            $examScore = round((float) $exam->overall_avg, 2);

            if ($result === null) {
                DB::table('term_results')->insert([
                    'student_id' => $exam->student_id,
                    'season_id' => $exam->season_id,
                    'term_id' => $exam->term_id,
                    'exam_score' => $examScore,
                    'general_avg' => $examScore,
                    'honor_flag' => 'none',
                ]);

                continue;
            }

            $previousExam = $result->exam_score;
            $general = $result->general_avg;
            if ($general === null || (
                $previousExam !== null
                && abs((float) $general - (float) $previousExam) < 0.005
            )) {
                $general = $examScore;
            }

            DB::table('term_results')->where('id', $result->id)->update([
                'season_id' => $exam->season_id,
                'exam_score' => $examScore,
                'general_avg' => $general,
            ]);
        }
    }

    public function down(): void
    {
        // Data backfill: no safe reverse.
    }
};
