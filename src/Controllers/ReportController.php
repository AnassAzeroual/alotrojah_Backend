<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

class ReportController extends BaseController
{
    /**
     * GET /v1/reports/term
     * Printable term report for one pupil: result, plan, quiz averages,
     * attendance rate, weekly progress, murajaa cycles.
     */
    public function term(Request $request): Response
    {
        $v = (new Validator($request->query()))
            ->required('student_id')->integer('student_id')
            ->required('term_id')->integer('term_id');

        if ($v->fails()) {
            return $this->unprocessable($v->errors());
        }

        $studentId = (int) $request->query('student_id');
        $termId = (int) $request->query('term_id');
        $db = Database::getInstance();

        $student = $db->query('SELECT id, full_name FROM students WHERE id = :id LIMIT 1', ['id' => $studentId])->fetch();
        if (!$student) {
            return $this->notFound('Student not found.');
        }

        $term = $db->query('SELECT id, name_ar, season_id FROM terms WHERE id = :id LIMIT 1', ['id' => $termId])->fetch();
        if (!$term) {
            return $this->notFound('Term not found.');
        }
        $seasonId = (int) $term['season_id'];

        // Result
        $termResult = $db->query(
            'SELECT * FROM term_results WHERE student_id = :stuid AND term_id = :tid LIMIT 1',
            ['stuid' => $studentId, 'tid' => $termId]
        )->fetch();

        $resultData = $termResult ? [
            'id' => (int) $termResult['id'],
            'student_id' => (int) $termResult['student_id'],
            'season_id' => (int) $termResult['season_id'],
            'term_id' => (int) $termResult['term_id'],
            'hifz_total' => $termResult['hifz_total'] !== null ? (float) $termResult['hifz_total'] : null,
            'murajaa_total' => $termResult['murajaa_total'] !== null ? (float) $termResult['murajaa_total'] : null,
            'exam_score' => $termResult['exam_score'] !== null ? (float) $termResult['exam_score'] : null,
            'general_avg' => $termResult['general_avg'] !== null ? (float) $termResult['general_avg'] : null,
            'teacher_notes' => $termResult['teacher_notes'],
            'supervisor_note' => $termResult['supervisor_note'],
            'honor_flag' => (string) $termResult['honor_flag'],
        ] : null;

        // Plan
        $termPlan = $db->query(
            'SELECT * FROM term_plans WHERE student_id = :stuid AND term_id = :tid LIMIT 1',
            ['stuid' => $studentId, 'tid' => $termId]
        )->fetch();

        $planData = $termPlan ? [
            'id' => (int) $termPlan['id'],
            'student_id' => (int) $termPlan['student_id'],
            'season_id' => (int) $termPlan['season_id'],
            'term_id' => (int) $termPlan['term_id'],
            'goal_text' => $termPlan['goal_text'],
            'plan_mode' => (string) $termPlan['plan_mode'],
            'start_hizb' => $termPlan['start_hizb'] !== null ? (float) $termPlan['start_hizb'] : null,
            'end_hizb' => $termPlan['end_hizb'] !== null ? (float) $termPlan['end_hizb'] : null,
            'plan_surah_from' => $termPlan['plan_surah_from'] !== null ? (int) $termPlan['plan_surah_from'] : null,
            'plan_ayah_from' => $termPlan['plan_ayah_from'] !== null ? (int) $termPlan['plan_ayah_from'] : null,
            'plan_surah_to' => $termPlan['plan_surah_to'] !== null ? (int) $termPlan['plan_surah_to'] : null,
            'plan_ayah_to' => $termPlan['plan_ayah_to'] !== null ? (int) $termPlan['plan_ayah_to'] : null,
            'expected_hifz_week_thumn' => $termPlan['expected_hifz_week_thumn'] !== null ? (float) $termPlan['expected_hifz_week_thumn'] : null,
            'expected_hifz_term_ahzab' => $termPlan['expected_hifz_term_ahzab'] !== null ? (float) $termPlan['expected_hifz_term_ahzab'] : null,
            'expected_hifz_season_ahzab' => $termPlan['expected_hifz_season_ahzab'] !== null ? (float) $termPlan['expected_hifz_season_ahzab'] : null,
            'khatm_expected_at' => $termPlan['khatm_expected_at'],
        ] : null;

        // Quizzes
        $quizzes = $db->query(
            "SELECT e.student_id, e.season_id, e.term_id, e.exam_type,
                    ROUND(SUM(q.score), 2) AS avg_score, COUNT(*) AS questions_count
             FROM exams e
             JOIN exam_questions q ON q.exam_id = e.id
             WHERE e.student_id = :stuid AND e.season_id = :sid AND e.term_id = :tid
             GROUP BY e.student_id, e.season_id, e.term_id, e.exam_type
             ORDER BY e.term_id",
            ['stuid' => $studentId, 'sid' => $seasonId, 'tid' => $termId]
        )->fetchAll();

        $quizzesData = array_map(function (array $r): array {
            return [
                'student_id' => (int) $r['student_id'],
                'season_id' => (int) $r['season_id'],
                'term_id' => (int) $r['term_id'],
                'exam_type' => (string) $r['exam_type'],
                'avg_score' => (float) $r['avg_score'],
                'questions_count' => (int) $r['questions_count'],
            ];
        }, $quizzes);

        // Attendance
        $attendance = $db->query(
            'SELECT * FROM v_attendance_rate WHERE student_id = :stuid AND season_id = :sid AND term_id = :tid LIMIT 1',
            ['stuid' => $studentId, 'sid' => $seasonId, 'tid' => $termId]
        )->fetch();

        $attendanceData = $attendance ? [
            'student_id' => (int) $attendance['student_id'],
            'season_id' => (int) $attendance['season_id'],
            'term_id' => (int) $attendance['term_id'],
            'total_sessions' => (int) $attendance['total_sessions'],
            'present_count' => $attendance['present_count'] !== null ? (int) $attendance['present_count'] : null,
            'absent_count' => $attendance['absent_count'] !== null ? (int) $attendance['absent_count'] : null,
            'late_count' => $attendance['late_count'] !== null ? (int) $attendance['late_count'] : null,
            'excused_count' => $attendance['excused_count'] !== null ? (int) $attendance['excused_count'] : null,
            'attendance_rate_pct' => $attendance['attendance_rate_pct'] !== null ? (float) $attendance['attendance_rate_pct'] : null,
        ] : null;

        // Weekly progress
        $weeklyRows = $db->query(
            'SELECT * FROM v_weekly_progress WHERE student_id = :stuid AND season_id = :sid AND term_id = :tid ORDER BY week_id',
            ['stuid' => $studentId, 'sid' => $seasonId, 'tid' => $termId]
        )->fetchAll();

        $weeklyData = array_map(function (array $r): array {
            return [
                'student_id' => (int) $r['student_id'],
                'season_id' => (int) $r['season_id'],
                'term_id' => (int) $r['term_id'],
                'week_id' => (int) $r['week_id'],
                'total_thumn' => (float) $r['total_thumn'],
                'total_ahzab' => (float) $r['total_ahzab'],
                'avg_score' => $r['avg_score'] !== null ? (float) $r['avg_score'] : null,
                'sessions_logged' => (int) $r['sessions_logged'],
            ];
        }, $weeklyRows);

        // Murajaa cycles
        $cycles = $db->query(
            'SELECT * FROM murajaa_reviews WHERE student_id = :stuid AND term_id = :tid ORDER BY week_from',
            ['stuid' => $studentId, 'tid' => $termId]
        )->fetchAll();

        $cyclesData = array_map(function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'student_id' => (int) $r['student_id'],
                'season_id' => (int) $r['season_id'],
                'term_id' => (int) $r['term_id'],
                'week_from' => (int) $r['week_from'],
                'week_to' => (int) $r['week_to'],
                'weeks_covered' => $r['weeks_covered'] !== null ? (int) $r['weeks_covered'] : null,
                'hizb_from' => $r['hizb_from'] !== null ? (float) $r['hizb_from'] : null,
                'hizb_to' => $r['hizb_to'] !== null ? (float) $r['hizb_to'] : null,
                'score' => (float) $r['score'],
                'reviewed_at' => $r['reviewed_at'],
            ];
        }, $cycles);

        return $this->ok([
            'student' => ['id' => (int) $student['id'], 'full_name' => (string) $student['full_name']],
            'term' => ['id' => (int) $term['id'], 'name_ar' => (string) $term['name_ar']],
            'result' => $resultData,
            'plan' => $planData,
            'quizzes' => $quizzesData,
            'attendance' => $attendanceData,
            'weekly' => $weeklyData,
            'murajaa_cycles' => $cyclesData,
        ]);
    }

    /**
     * GET /v1/reports/season
     * Printable season report for one pupil: season/term results, scoring
     * inputs, final average, quiz averages, honor flag.
     */
    public function season(Request $request): Response
    {
        $v = (new Validator($request->query()))
            ->required('student_id')->integer('student_id')
            ->required('season_id')->integer('season_id');

        if ($v->fails()) {
            return $this->unprocessable($v->errors());
        }

        $studentId = (int) $request->query('student_id');
        $seasonId = (int) $request->query('season_id');
        $db = Database::getInstance();

        $student = $db->query('SELECT id, full_name FROM students WHERE id = :id LIMIT 1', ['id' => $studentId])->fetch();
        if (!$student) {
            return $this->notFound('Student not found.');
        }

        $seasonResult = $db->query(
            'SELECT * FROM season_results WHERE student_id = :stuid AND season_id = :sid LIMIT 1',
            ['stuid' => $studentId, 'sid' => $seasonId]
        )->fetch();

        $resultData = $seasonResult ? [
            'id' => (int) $seasonResult['id'],
            'student_id' => (int) $seasonResult['student_id'],
            'season_id' => (int) $seasonResult['season_id'],
            'total_memorized_label' => $seasonResult['total_memorized_label'],
            'total_memorized_thumn' => $seasonResult['total_memorized_thumn'] !== null ? (float) $seasonResult['total_memorized_thumn'] : null,
            'hifz_total' => $seasonResult['hifz_total'] !== null ? (float) $seasonResult['hifz_total'] : null,
            'murajaa_total' => $seasonResult['murajaa_total'] !== null ? (float) $seasonResult['murajaa_total'] : null,
            'overall_avg' => $seasonResult['overall_avg'] !== null ? (float) $seasonResult['overall_avg'] : null,
            'board_report' => $seasonResult['board_report'],
            'honor_flag' => (string) $seasonResult['honor_flag'],
        ] : null;

        // Term results
        $termResults = $db->query(
            'SELECT * FROM term_results WHERE student_id = :stuid AND season_id = :sid ORDER BY term_id',
            ['stuid' => $studentId, 'sid' => $seasonId]
        )->fetchAll();

        $termResultsData = array_map(function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'student_id' => (int) $r['student_id'],
                'season_id' => (int) $r['season_id'],
                'term_id' => (int) $r['term_id'],
                'hifz_total' => $r['hifz_total'] !== null ? (float) $r['hifz_total'] : null,
                'murajaa_total' => $r['murajaa_total'] !== null ? (float) $r['murajaa_total'] : null,
                'exam_score' => $r['exam_score'] !== null ? (float) $r['exam_score'] : null,
                'general_avg' => $r['general_avg'] !== null ? (float) $r['general_avg'] : null,
                'teacher_notes' => $r['teacher_notes'],
                'supervisor_note' => $r['supervisor_note'],
                'honor_flag' => (string) $r['honor_flag'],
            ];
        }, $termResults);

        // Inputs
        $avgsRow = $db->query(
            'SELECT * FROM v_student_season_avgs WHERE student_id = :stuid AND season_id = :sid LIMIT 1',
            ['stuid' => $studentId, 'sid' => $seasonId]
        )->fetch();

        $inputs = [
            'avg_murajaa' => $avgsRow && $avgsRow['avg_murajaa'] !== null ? (float) $avgsRow['avg_murajaa'] : null,
            'avg_weekly' => $avgsRow && $avgsRow['avg_weekly'] !== null ? (float) $avgsRow['avg_weekly'] : null,
            'avg_sarraj' => $avgsRow && $avgsRow['avg_sarraj'] !== null ? (float) $avgsRow['avg_sarraj'] : null,
        ];

        // Quizzes
        $quizzes = $db->query(
            "SELECT e.student_id, e.season_id, e.term_id, e.exam_type,
                    ROUND(SUM(q.score), 2) AS avg_score, COUNT(*) AS questions_count
             FROM exams e
             JOIN exam_questions q ON q.exam_id = e.id
             WHERE e.student_id = :stuid AND e.season_id = :sid
             GROUP BY e.student_id, e.season_id, e.term_id, e.exam_type
             ORDER BY e.term_id",
            ['stuid' => $studentId, 'sid' => $seasonId]
        )->fetchAll();

        $quizzesData = array_map(function (array $r): array {
            return [
                'student_id' => (int) $r['student_id'],
                'season_id' => (int) $r['season_id'],
                'term_id' => (int) $r['term_id'],
                'exam_type' => (string) $r['exam_type'],
                'avg_score' => (float) $r['avg_score'],
                'questions_count' => (int) $r['questions_count'],
            ];
        }, $quizzes);

        // Final average
        $finalScore = null;
        if ($inputs['avg_murajaa'] !== null && $inputs['avg_weekly'] !== null) {
            $quizExams = $db->query(
                "SELECT overall_avg FROM exams
                 WHERE student_id = :stuid AND season_id = :sid
                 AND exam_type IN ('term_batch', 'final_season')
                 AND overall_avg IS NOT NULL",
                ['stuid' => $studentId, 'sid' => $seasonId]
            )->fetchAll();

            if (!empty($quizExams)) {
                $sum = 0.0;
                foreach ($quizExams as $qe) {
                    $sum += (float) $qe['overall_avg'];
                }
                $divisor = 2 + count($quizExams);
                $finalScore = round(($inputs['avg_murajaa'] + $inputs['avg_weekly'] + $sum) / $divisor, 2);
            }
        }

        return $this->ok([
            'student' => ['id' => (int) $student['id'], 'full_name' => (string) $student['full_name']],
            'result' => $resultData,
            'term_results' => $termResultsData,
            'inputs' => $inputs,
            'final' => $finalScore,
            'quizzes' => $quizzesData,
            'honor' => $seasonResult ? (string) $seasonResult['honor_flag'] : null,
        ]);
    }
}
