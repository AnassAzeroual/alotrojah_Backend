<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

final class ExamController extends BaseController
{
    private function formatExam(array $row, array $questions = []): array
    {
        return [
            'id' => (int) $row['id'],
            'student_id' => (int) $row['student_id'],
            'season_id' => (int) $row['season_id'],
            'term_id' => $row['term_id'] !== null ? (int) $row['term_id'] : null,
            'exam_type' => $row['exam_type'],
            'exam_date' => $row['exam_date'] ?? null,
            'examiner_id' => $row['examiner_id'] !== null ? (int) $row['examiner_id'] : null,
            'overall_avg' => $row['overall_avg'] !== null ? (float) $row['overall_avg'] : null,
            'examiner_report' => $row['examiner_report'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'student' => !empty($row['student_name']) ? [
                'id' => (int) $row['student_id'],
                'full_name' => $row['student_name'],
            ] : null,
            'questions' => $questions,
        ];
    }

    private function formatQuestion(array $q): array
    {
        return [
            'id' => (int) $q['id'],
            'exam_id' => (int) $q['exam_id'],
            'question_no' => (int) $q['question_no'],
            'prompt_text' => $q['prompt_text'] ?? null,
            'hizb_ref' => $q['hizb_ref'] !== null ? (float) $q['hizb_ref'] : null,
            'surah_ref' => $q['surah_ref'] !== null ? (int) $q['surah_ref'] : null,
            'ayah_from' => $q['ayah_from'] !== null ? (int) $q['ayah_from'] : null,
            'ayah_to' => $q['ayah_to'] !== null ? (int) $q['ayah_to'] : null,
            'sort_order' => (int) $q['sort_order'],
            'model_type' => $q['model_type'] ?? 'single',
            'score' => $q['score'] !== null ? (float) $q['score'] : null,
            'max_score' => isset($q['max_score']) ? (float) $q['max_score'] : 20.0,
            'notes' => $q['notes'] ?? null,
            'thumn_ref' => isset($q['thumn_ref']) && $q['thumn_ref'] !== null ? (int) $q['thumn_ref'] : null,
        ];
    }

    public function index(Request $request): Response
    {
        $me = $request->getUser();
        $role = $me['role'] ?? 'teacher';

        $conditions = [];
        $params = [];

        if ($role === 'student') {
            $conditions[] = "s.user_id = :my_uid";
            $params['my_uid'] = (int) $me['id'];
        } elseif ($role !== 'admin' && $role !== 'board') {
            $conditions[] = "s.center_id = :my_cid";
            $params['my_cid'] = (int) $me['center_id'];
        }

        foreach (['student_id', 'term_id', 'season_id', 'exam_type'] as $f) {
            if ($request->query($f) !== null && $request->query($f) !== '') {
                $conditions[] = "e.{$f} = :{$f}";
                $params[$f] = $request->query($f);
            }
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "
            SELECT e.*, s.full_name AS student_name
            FROM `exams` e
            JOIN `students` s ON s.id = e.student_id
            {$whereClause}
            ORDER BY e.exam_date DESC, e.id DESC
        ";

        $page = (int) $request->query('page', 1);
        $perPage = min(max((int) $request->query('per_page', 50), 1), 100);

        $paginated = Database::paginate($sql, $params, $page, $perPage);
        $paginated['data'] = array_map(fn($row) => $this->formatExam($row), $paginated['data']);

        return $this->ok($paginated);
    }

    public function store(Request $request): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor', 'teacher']);
        $me = $request->getUser();

        $v = Validator::make($request->all(), [
            'student_id' => 'required|integer',
            'exam_type' => 'required|in:hizb_completion,term_batch,final_season,weekly',
        ]);
        if ($v->fails()) {
            return $this->fail('The given data was invalid.', 422, $v->errors());
        }

        $studentId = (int) $request->input('student_id');
        $student = Database::fetchOne("SELECT id, center_id, full_name FROM `students` WHERE id = :id", ['id' => $studentId]);
        if (!$student) {
            return $this->fail('Student not found.', 404);
        }

        if ($me['role'] !== 'admin' && (int) $student['center_id'] !== (int) $me['center_id']) {
            return $this->fail('Student is in another center.', 403);
        }

        $examType = (string) $request->input('exam_type');
        $termId = null;
        $seasonId = null;

        if (in_array($examType, ['final_season', 'weekly'], true)) {
            $seasonId = $request->input('season_id') ? (int) $request->input('season_id') : null;
            if (!$seasonId) {
                $seasonId = (int) Database::fetchValue("SELECT id FROM `academic_seasons` WHERE center_id = :cid AND is_current = 1 LIMIT 1", ['cid' => $student['center_id']])
                    ?: (int) Database::fetchValue("SELECT id FROM `academic_seasons` WHERE is_current = 1 LIMIT 1");
            }
        } else {
            $termId = (int) $request->input('term_id');
            $term = Database::fetchOne("SELECT id, season_id FROM `terms` WHERE id = :id", ['id' => $termId]);
            if (!$term) {
                return $this->fail('Term not found.', 404);
            }
            $seasonId = (int) $term['season_id'];
        }

        $examinerId = $me['role'] === 'teacher' ? (int) $me['id'] : ($request->input('examiner_id') ? (int) $request->input('examiner_id') : (int) $me['id']);
        $questions = (array) ($request->input('questions') ?? []);

        $examId = Database::transaction(function () use ($studentId, $seasonId, $termId, $examType, $examinerId, $request, $questions) {
            $eid = Database::insert('exams', [
                'student_id' => $studentId,
                'season_id' => $seasonId,
                'term_id' => $termId,
                'exam_type' => $examType,
                'exam_date' => $request->input('exam_date') ?: date('Y-m-d'),
                'examiner_id' => $examinerId,
                'examiner_report' => $request->input('examiner_report'),
            ]);

            $totalScore = 0.0;
            $scoredCount = 0;

            foreach ($questions as $q) {
                $qNo = (int) ($q['question_no'] ?? 1);
                $score = isset($q['score']) && $q['score'] !== null ? (float) $q['score'] : null;
                $maxScore = isset($q['max_score']) ? (float) $q['max_score'] : 20.0;

                Database::insert('exam_questions', [
                    'exam_id' => $eid,
                    'question_no' => $qNo,
                    'prompt_text' => $q['prompt_text'] ?? null,
                    'hizb_ref' => $q['hizb_ref'] ?? null,
                    'surah_ref' => $q['surah_ref'] ?? null,
                    'ayah_from' => $q['ayah_from'] ?? null,
                    'ayah_to' => $q['ayah_to'] ?? null,
                    'sort_order' => $q['sort_order'] ?? $qNo,
                    'model_type' => $q['model_type'] ?? 'single',
                    'score' => $score,
                    'notes' => $q['notes'] ?? null,
                    'thumn_ref' => $q['thumn_ref'] ?? null,
                ]);

                if ($score !== null) {
                    $totalScore += $score;
                    $scoredCount++;
                }
            }

            if ($scoredCount > 0) {
                Database::update('exams', ['overall_avg' => round($totalScore, 2)], 'id = :id', ['id' => $eid]);
            }

            return $eid;
        });

        $created = Database::fetchOne("
            SELECT e.*, s.full_name AS student_name
            FROM `exams` e
            JOIN `students` s ON s.id = e.student_id
            WHERE e.id = :id
        ", ['id' => $examId]);

        $createdQuestions = Database::fetchAll("SELECT * FROM `exam_questions` WHERE exam_id = :eid ORDER BY sort_order ASC, question_no ASC", ['eid' => $examId]);
        return $this->created($this->formatExam($created, array_map([$this, 'formatQuestion'], $createdQuestions)));
    }

    public function show(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? $params['exam'] ?? 0);
        $exam = Database::fetchOne("
            SELECT e.*, s.full_name AS student_name
            FROM `exams` e
            JOIN `students` s ON s.id = e.student_id
            WHERE e.id = :id
        ", ['id' => $id]);

        if (!$exam) {
            return $this->fail('Exam not found.', 404);
        }

        $questions = Database::fetchAll("SELECT * FROM `exam_questions` WHERE exam_id = :eid ORDER BY sort_order ASC, question_no ASC", ['eid' => $id]);
        return $this->ok($this->formatExam($exam, array_map([$this, 'formatQuestion'], $questions)));
    }

    public function update(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? $params['exam'] ?? 0);
        $exam = Database::fetchOne("SELECT * FROM `exams` WHERE id = :id", ['id' => $id]);
        if (!$exam) {
            return $this->fail('Exam not found.', 404);
        }

        $input = $request->all();
        $type = $input['exam_type'] ?? $exam['exam_type'];
        $update = [];

        if (in_array($type, ['final_season', 'weekly'], true)) {
            $update['term_id'] = null;
        } else {
            $termId = $input['term_id'] ?? $exam['term_id'];
            if (!$termId) {
                return $this->fail('A term is required for this exam type.', 422, ['code' => 'TERM_REQUIRED']);
            }
            $term = Database::fetchOne("SELECT season_id FROM `terms` WHERE id = :id", ['id' => (int) $termId]);
            if ($term) {
                $update['season_id'] = (int) $term['season_id'];
                $update['term_id'] = (int) $termId;
            }
        }

        foreach (['exam_type', 'exam_date', 'examiner_id', 'overall_avg', 'examiner_report'] as $f) {
            if (array_key_exists($f, $input)) {
                $update[$f] = $input[$f];
            }
        }

        if (!empty($update)) {
            Database::update('exams', $update, 'id = :id', ['id' => $id]);
        }

        $fresh = Database::fetchOne("
            SELECT e.*, s.full_name AS student_name
            FROM `exams` e
            JOIN `students` s ON s.id = e.student_id
            WHERE e.id = :id
        ", ['id' => $id]);

        $questions = Database::fetchAll("SELECT * FROM `exam_questions` WHERE exam_id = :eid ORDER BY sort_order ASC, question_no ASC", ['eid' => $id]);
        return $this->ok($this->formatExam($fresh, array_map([$this, 'formatQuestion'], $questions)));
    }

    public function destroy(Request $request, array $params): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor', 'teacher']);
        $id = (int) ($params['id'] ?? $params['exam'] ?? 0);

        Database::delete('exam_questions', 'exam_id = :id', ['id' => $id]);
        Database::delete('exams', 'id = :id', ['id' => $id]);
        return $this->ok(null, 'Deleted.');
    }

    public function weeklyTemplate(Request $request): Response
    {
        $groupId = (int) $request->query('group_id');
        $group = Database::fetchOne("SELECT id, center_id FROM `groups` WHERE id = :id", ['id' => $groupId]);
        if (!$group) {
            return $this->fail('Group not found.', 404);
        }

        $me = $request->getUser();
        if ($me['role'] !== 'admin' && (int) $group['center_id'] !== (int) $me['center_id']) {
            return $this->fail('Group belongs to another center.', 403);
        }

        $centerId = (int) $group['center_id'];
        $seasonId = Database::fetchValue("SELECT id FROM `academic_seasons` WHERE center_id = :cid AND is_current = 1 LIMIT 1", ['cid' => $centerId])
            ?: Database::fetchValue("SELECT id FROM `academic_seasons` WHERE is_current = 1 LIMIT 1");

        if (!$seasonId) {
            return $this->fail('A current season is required for weekly exams.', 422, ['code' => 'SEASON_REQUIRED']);
        }

        $modules = Database::fetchAll("
            SELECT code, name_ar, max_points
            FROM `scoring_modules`
            WHERE is_active = 1 AND scope = 'weekly' AND is_in_weekly_total = 1
              AND (center_id = :cid OR center_id IS NULL)
            ORDER BY center_id IS NULL ASC, sort_order ASC
        ", ['cid' => $centerId]);

        $byCode = [];
        foreach ($modules as $m) {
            $byCode[$m['code']] = [
                'code' => $m['code'],
                'name_ar' => $m['name_ar'],
                'max_points' => (float) $m['max_points'],
            ];
        }

        $pupils = Database::fetchAll("
            SELECT id, full_name, memorization_mode
            FROM `students`
            WHERE group_id = :gid AND status IN ('active', 'paused')
            ORDER BY full_name ASC
        ", ['gid' => $groupId]);

        return $this->ok([
            'season_id' => (int) $seasonId,
            'modules' => array_values($byCode),
            'pupils' => array_map(function ($p) {
                return [
                    'id' => (int) $p['id'],
                    'full_name' => $p['full_name'],
                    'memorization_mode' => $p['memorization_mode'] ?? 'thumn',
                ];
            }, $pupils),
        ]);
    }

    public function weeklyBatch(Request $request): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor', 'teacher']);

        $v = Validator::make($request->all(), [
            'group_id' => 'required|integer',
            'exam_date' => 'required|date',
            'rows' => 'required',
        ]);
        if ($v->fails()) {
            return $this->fail('The given data was invalid.', 422, $v->errors());
        }

        $groupId = (int) $request->input('group_id');
        $group = Database::fetchOne("SELECT id, center_id FROM `groups` WHERE id = :id", ['id' => $groupId]);
        if (!$group) {
            return $this->fail('Group not found.', 404);
        }

        $me = $request->getUser();
        if ($me['role'] !== 'admin' && (int) $group['center_id'] !== (int) $me['center_id']) {
            return $this->fail('Group belongs to another center.', 403);
        }

        $centerId = (int) $group['center_id'];
        $seasonId = Database::fetchValue("SELECT id FROM `academic_seasons` WHERE center_id = :cid AND is_current = 1 LIMIT 1", ['cid' => $centerId])
            ?: Database::fetchValue("SELECT id FROM `academic_seasons` WHERE is_current = 1 LIMIT 1");

        if (!$seasonId) {
            return $this->fail('A current season is required for weekly exams.', 422, ['code' => 'SEASON_REQUIRED']);
        }

        $modules = Database::fetchAll("
            SELECT id, code, name_ar, max_points
            FROM `scoring_modules`
            WHERE is_active = 1 AND scope = 'weekly' AND is_in_weekly_total = 1
              AND (center_id = :cid OR center_id IS NULL)
            ORDER BY center_id IS NULL ASC, sort_order ASC
        ", ['cid' => $centerId]);

        $byCode = [];
        foreach ($modules as $m) {
            $byCode[$m['code']] = $m;
        }

        $examDate = (string) $request->input('exam_date');
        $rows = (array) $request->input('rows');
        $examinerId = (int) $me['id'];

        $createdExamIds = [];

        Database::transaction(function () use ($rows, $groupId, $seasonId, $examDate, $examinerId, $byCode, &$createdExamIds) {
            foreach ($rows as $r) {
                $studentId = (int) ($r['student_id'] ?? 0);
                if (!$studentId) continue;

                $eid = Database::insert('exams', [
                    'student_id' => $studentId,
                    'season_id' => $seasonId,
                    'term_id' => null,
                    'exam_type' => 'weekly',
                    'exam_date' => $examDate,
                    'examiner_id' => $examinerId,
                ]);

                $total = 0.0;
                $qNo = 1;
                $scores = (array) ($r['scores'] ?? []);

                foreach ($byCode as $code => $mod) {
                    $scoreVal = isset($scores[$code]) ? (float) $scores[$code] : 0.0;
                    $total += $scoreVal;

                    $qData = [
                        'exam_id' => $eid,
                        'question_no' => $qNo,
                        'prompt_text' => $mod['name_ar'],
                        'sort_order' => $qNo,
                        'model_type' => 'single',
                        'score' => $scoreVal,
                    ];

                    if ($qNo === 1) {
                        $qData['hizb_ref'] = $r['hizb_ref'] ?? null;
                        $qData['thumn_ref'] = $r['thumn_ref'] ?? null;
                        $qData['surah_ref'] = $r['surah_ref'] ?? null;
                        $qData['ayah_from'] = $r['ayah_from'] ?? null;
                        $qData['ayah_to'] = $r['ayah_to'] ?? null;
                    }

                    Database::insert('exam_questions', $qData);
                    $qNo++;
                }

                Database::update('exams', ['overall_avg' => round($total, 2)], 'id = :id', ['id' => $eid]);
                $createdExamIds[] = $eid;
            }
        });

        return $this->created(['exam_ids' => $createdExamIds], 'Weekly exams recorded.');
    }
}
