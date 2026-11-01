<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

final class ExamQuestionController extends BaseController
{
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

    private function recomputeExamAvg(int $examId): void
    {
        $avg = Database::fetchValue("
            SELECT ROUND(SUM(score), 2)
            FROM `exam_questions`
            WHERE exam_id = :eid AND score IS NOT NULL
        ", ['eid' => $examId]);

        Database::update('exams', ['overall_avg' => $avg !== null ? (float) $avg : null], 'id = :id', ['id' => $examId]);
    }

    public function bulk(Request $request, array $params): Response
    {
        $examId = (int) ($params['exam'] ?? $params['id'] ?? 0);
        $exam = Database::fetchOne("SELECT * FROM `exams` WHERE id = :id", ['id' => $examId]);
        if (!$exam) {
            return $this->fail('Exam not found.', 404);
        }

        $questions = (array) $request->input('questions');
        if (empty($questions)) {
            return $this->fail('Questions array is required.', 422);
        }

        $created = [];
        Database::transaction(function () use ($examId, $questions, &$created) {
            foreach ($questions as $row) {
                $qNo = (int) ($row['question_no'] ?? 1);
                $qid = Database::insert('exam_questions', [
                    'exam_id' => $examId,
                    'question_no' => $qNo,
                    'prompt_text' => $row['prompt_text'] ?? null,
                    'hizb_ref' => $row['hizb_ref'] ?? null,
                    'surah_ref' => $row['surah_ref'] ?? null,
                    'ayah_from' => $row['ayah_from'] ?? null,
                    'ayah_to' => $row['ayah_to'] ?? null,
                    'sort_order' => $row['sort_order'] ?? $qNo,
                    'model_type' => $row['model_type'] ?? 'single',
                    'score' => isset($row['score']) ? (float) $row['score'] : null,
                    'notes' => $row['notes'] ?? null,
                    'thumn_ref' => $row['thumn_ref'] ?? null,
                ]);

                $fresh = Database::fetchOne("SELECT * FROM `exam_questions` WHERE id = :id", ['id' => $qid]);
                $created[] = $this->formatQuestion($fresh);
            }

            $this->recomputeExamAvg($examId);
        });

        return $this->created($created);
    }

    public function reweight(Request $request, array $params): Response
    {
        $examId = (int) ($params['exam'] ?? $params['id'] ?? 0);
        $exam = Database::fetchOne("SELECT * FROM `exams` WHERE id = :id", ['id' => $examId]);
        if (!$exam) {
            return $this->fail('Exam not found.', 404);
        }

        $weights = (array) $request->input('weights');
        if (empty($weights)) {
            return $this->fail('Weights array is required.', 422);
        }

        $ids = array_column($weights, 'id');
        $existing = Database::fetchAll("SELECT id, score FROM `exam_questions` WHERE exam_id = :eid AND id IN (" . implode(',', $ids) . ")", ['eid' => $examId]);

        if (count($existing) !== count($ids)) {
            return $this->fail('Every weight must belong to this exam.', 422, ['code' => 'WEIGHTS_FOREIGN']);
        }

        $existingById = [];
        foreach ($existing as $e) {
            $existingById[(int) $e['id']] = $e;
        }

        $totalWeight = 0.0;
        foreach ($weights as $w) {
            $maxScore = (float) $w['max_score'];
            $totalWeight += $maxScore;
            $qid = (int) $w['id'];
            $currentScore = $existingById[$qid]['score'] !== null ? (float) $existingById[$qid]['score'] : null;

            if ($currentScore !== null && $currentScore > $maxScore) {
                return $this->fail('A recorded score exceeds its new weight.', 422, ['code' => 'SCORE_OVER_MAX']);
            }
        }

        if (abs($totalWeight - 20.0) > 0.01) {
            return $this->fail('Weights must total 20', 422, ['code' => 'WEIGHTS_TOTAL']);
        }

        Database::transaction(function () use ($weights, $examId) {
            foreach ($weights as $w) {
                Database::update('exam_questions', [
                    'sort_order' => $w['sort_order'] ?? null,
                ], 'id = :id', ['id' => (int) $w['id']]);
            }
            $this->recomputeExamAvg($examId);
        });

        $questions = Database::fetchAll("SELECT * FROM `exam_questions` WHERE exam_id = :eid ORDER BY sort_order ASC", ['eid' => $examId]);
        return $this->ok(array_map([$this, 'formatQuestion'], $questions));
    }

    public function show(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? $params['examQuestion'] ?? 0);
        $q = Database::fetchOne("SELECT * FROM `exam_questions` WHERE id = :id", ['id' => $id]);
        if (!$q) {
            return $this->fail('Exam question not found.', 404);
        }

        return $this->ok($this->formatQuestion($q));
    }

    public function update(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? $params['examQuestion'] ?? 0);
        $q = Database::fetchOne("SELECT * FROM `exam_questions` WHERE id = :id", ['id' => $id]);
        if (!$q) {
            return $this->fail('Exam question not found.', 404);
        }

        $input = $request->all();
        $fields = [
            'prompt_text', 'hizb_ref', 'surah_ref', 'ayah_from', 'ayah_to',
            'sort_order', 'model_type', 'score', 'notes', 'thumn_ref'
        ];

        $update = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $input)) {
                $update[$f] = $input[$f];
            }
        }

        Database::transaction(function () use ($id, $update, $q) {
            if (!empty($update)) {
                Database::update('exam_questions', $update, 'id = :id', ['id' => $id]);
            }
            $this->recomputeExamAvg((int) $q['exam_id']);
        });

        $fresh = Database::fetchOne("SELECT * FROM `exam_questions` WHERE id = :id", ['id' => $id]);
        return $this->ok($this->formatQuestion($fresh));
    }

    public function destroy(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? $params['examQuestion'] ?? 0);
        $q = Database::fetchOne("SELECT * FROM `exam_questions` WHERE id = :id", ['id' => $id]);
        if (!$q) {
            return $this->fail('Exam question not found.', 404);
        }

        $examId = (int) $q['exam_id'];
        Database::transaction(function () use ($id, $examId) {
            Database::delete('exam_questions', 'id = :id', ['id' => $id]);
            $this->recomputeExamAvg($examId);
        });

        return $this->ok(null, 'Deleted.');
    }
}
