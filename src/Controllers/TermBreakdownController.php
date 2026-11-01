<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

class TermBreakdownController extends BaseController
{
    /**
     * GET /v1/term-results/breakdown
     * Read-only proof for the four term-result boxes.
     */
    public function show(Request $request): Response
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

        $student = $db->query('SELECT id, full_name, center_id FROM students WHERE id = :id LIMIT 1', ['id' => $studentId])->fetch();
        if (!$student) {
            return $this->notFound('Student not found.');
        }

        $term = $db->query('SELECT id, name_ar, season_id FROM terms WHERE id = :id LIMIT 1', ['id' => $termId])->fetch();
        if (!$term) {
            return $this->notFound('Term not found.');
        }

        // Active weekly-total modules for the student's center (or global center_id IS NULL)
        $centerId = (int) $student['center_id'];
        $modules = $db->query(
            "SELECT * FROM scoring_modules
             WHERE (center_id = :cid OR center_id IS NULL)
             AND scope = 'weekly' AND is_in_weekly_total = 1 AND is_active = 1
             ORDER BY sort_order ASC, id ASC",
            ['cid' => $centerId]
        )->fetchAll();

        // If center-specific modules exist, we use them; otherwise global
        $grouped = [];
        foreach ($modules as $m) {
            $code = $m['code'];
            if (!isset($grouped[$code]) || $m['center_id'] !== null) {
                $grouped[$code] = $m;
            }
        }
        $modules = array_values($grouped);
        usort($modules, fn ($a, $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));

        $moduleDefs = array_map(function (array $m): array {
            return [
                'code' => (string) $m['code'],
                'name_ar' => (string) $m['name_ar'],
                'max_points' => (float) $m['max_points'],
            ];
        }, $modules);

        // Sessions in term
        $sessions = $db->query(
            "SELECT id, session_number_global, planned_date, status
             FROM sessions
             WHERE term_id = :tid AND status != 'cancelled'
             ORDER BY session_number_global ASC",
            ['tid' => $termId]
        )->fetchAll();

        // Scores for student
        $sessionScores = $db->query(
            "SELECT sc.session_id, sc.module_id, sc.score
             FROM session_scores sc
             JOIN sessions s ON s.id = sc.session_id
             WHERE sc.student_id = :stuid AND s.term_id = :tid",
            ['stuid' => $studentId, 'tid' => $termId]
        )->fetchAll();

        $scoresBySession = [];
        foreach ($sessionScores as $sc) {
            $scoresBySession[$sc['session_id']][$sc['module_id']] = (float) $sc['score'];
        }

        $hifzRows = [];
        $scoredCount = 0;
        $totalSum = 0.0;

        foreach ($sessions as $s) {
            $sid = (int) $s['id'];
            $sessModules = [];
            $rowTotal = 0.0;
            $hasScore = false;

            foreach ($modules as $m) {
                $mid = (int) $m['id'];
                $score = $scoresBySession[$sid][$mid] ?? null;
                if ($score !== null) {
                    $hasScore = true;
                    $rowTotal += $score;
                }
                $sessModules[] = [
                    'code' => (string) $m['code'],
                    'name_ar' => (string) $m['name_ar'],
                    'max_points' => (float) $m['max_points'],
                    'score' => $score !== null ? (float) $score : null,
                ];
            }

            if ($hasScore) {
                $scoredCount++;
                $totalSum += $rowTotal;
            }

            $hifzRows[] = [
                'session_id' => $sid,
                'session_number' => (int) $s['session_number_global'],
                'date' => $s['planned_date'],
                'status' => (string) $s['status'],
                'modules' => $sessModules,
                'total' => round($rowTotal, 2),
                'has_score' => $hasScore,
            ];
        }

        $hifzAverage = $scoredCount > 0 ? round($totalSum / $scoredCount, 2) : null;

        // Murajaa reviews
        $reviews = $db->query(
            'SELECT * FROM murajaa_reviews WHERE student_id = :stuid AND term_id = :tid ORDER BY week_from ASC',
            ['stuid' => $studentId, 'tid' => $termId]
        )->fetchAll();

        $murajaaSum = 0.0;
        $murajaaRows = [];
        foreach ($reviews as $rev) {
            $sc = (float) $rev['score'];
            $murajaaSum += $sc;
            $murajaaRows[] = [
                'id' => (int) $rev['id'],
                'week_from' => (int) $rev['week_from'],
                'week_to' => (int) $rev['week_to'],
                'weeks_covered' => $rev['weeks_covered'] !== null ? (int) $rev['weeks_covered'] : null,
                'score' => $sc,
                'reviewed_at' => $rev['reviewed_at'],
                'hizb_from' => $rev['hizb_from'] !== null ? (float) $rev['hizb_from'] : null,
                'hizb_to' => $rev['hizb_to'] !== null ? (float) $rev['hizb_to'] : null,
            ];
        }
        $murajaaCount = count($murajaaRows);
        $murajaaAvg = $murajaaCount > 0 ? round($murajaaSum / $murajaaCount, 2) : null;

        // Exam: latest term_batch exam and questions
        $latestExam = $db->query(
            "SELECT * FROM exams
             WHERE student_id = :stuid AND term_id = :tid AND exam_type = 'term_batch'
             ORDER BY id DESC LIMIT 1",
            ['stuid' => $studentId, 'tid' => $termId]
        )->fetch();

        $examData = null;
        if ($latestExam) {
            $eid = (int) $latestExam['id'];
            $questions = $db->query(
                'SELECT * FROM exam_questions WHERE exam_id = :eid ORDER BY question_no ASC',
                ['eid' => $eid]
            )->fetchAll();

            $qSum = 0.0;
            $qMax = 0.0;
            $qRows = [];
            foreach ($questions as $q) {
                $qScore = $q['score'] !== null ? (float) $q['score'] : null;
                $maxScore = $q['max_score'] !== null ? (float) $q['max_score'] : null;
                if ($qScore !== null) {
                    $qSum += $qScore;
                }
                if ($maxScore !== null) {
                    $qMax += $maxScore;
                }
                $qRows[] = [
                    'question_no' => (int) $q['question_no'],
                    'prompt_text' => $q['prompt_text'],
                    'model_type' => $q['model_type'],
                    'max_score' => $maxScore,
                    'score' => $qScore,
                ];
            }

            $examData = [
                'id' => $eid,
                'exam_date' => $latestExam['exam_date'],
                'overall_avg' => $latestExam['overall_avg'] !== null ? (float) $latestExam['overall_avg'] : null,
                'sum' => round($qSum, 2),
                'max' => round($qMax, 2),
                'questions' => $qRows,
            ];
        }

        // General box: stored official value from term_results
        $termResult = $db->query(
            'SELECT general_avg FROM term_results WHERE student_id = :stuid AND term_id = :tid LIMIT 1',
            ['stuid' => $studentId, 'tid' => $termId]
        )->fetch();

        $generalVal = $termResult && $termResult['general_avg'] !== null ? (float) $termResult['general_avg'] : null;
        $generalSource = 'manual';
        if ($examData && $generalVal !== null && abs($generalVal - (float) ($examData['overall_avg'] ?? -999)) < 0.01) {
            $generalSource = 'exam';
        }

        return $this->ok([
            'student' => ['id' => (int) $student['id'], 'full_name' => (string) $student['full_name']],
            'term' => ['id' => (int) $term['id'], 'name_ar' => (string) $term['name_ar']],
            'hifz' => [
                'modules' => $moduleDefs,
                'sessions' => count($sessions),
                'scored_sessions' => $scoredCount,
                'sum' => round($totalSum, 2),
                'average' => $hifzAverage,
                'rows' => $hifzRows,
            ],
            'murajaa' => [
                'count' => $murajaaCount,
                'average' => $murajaaAvg,
                'rows' => $murajaaRows,
            ],
            'exam' => $examData,
            'general' => [
                'value' => $generalVal,
                'source' => $generalSource,
            ],
        ]);
    }
}
