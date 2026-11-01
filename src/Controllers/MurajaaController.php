<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

final class MurajaaController extends BaseController
{
    private function formatReview(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'student_id' => (int) $row['student_id'],
            'season_id' => (int) $row['season_id'],
            'term_id' => (int) $row['term_id'],
            'week_from' => (int) $row['week_from'],
            'week_to' => (int) $row['week_to'],
            'weeks_covered' => (int) $row['weeks_covered'],
            'session_id' => $row['session_id'] !== null ? (int) $row['session_id'] : null,
            'hizb_from' => $row['hizb_from'] !== null ? (float) $row['hizb_from'] : null,
            'hizb_to' => $row['hizb_to'] !== null ? (float) $row['hizb_to'] : null,
            'surah_from' => $row['surah_from'] !== null ? (int) $row['surah_from'] : null,
            'ayah_from' => $row['ayah_from'] !== null ? (int) $row['ayah_from'] : null,
            'surah_to' => $row['surah_to'] !== null ? (int) $row['surah_to'] : null,
            'ayah_to' => $row['ayah_to'] !== null ? (int) $row['ayah_to'] : null,
            'score' => (float) $row['score'],
            'entered_by' => $row['entered_by'] !== null ? (int) $row['entered_by'] : null,
            'reviewed_at' => $row['reviewed_at'] ?? null,
        ];
    }

    private function formatLog(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'student_id' => (int) $row['student_id'],
            'season_id' => (int) $row['season_id'],
            'term_id' => (int) $row['term_id'],
            'week_id' => (int) $row['week_id'],
            'session_id' => (int) $row['session_id'],
            'log_date' => $row['log_date'],
            'hizb_from' => $row['hizb_from'] !== null ? (float) $row['hizb_from'] : null,
            'hizb_to' => $row['hizb_to'] !== null ? (float) $row['hizb_to'] : null,
            'murajaa_score' => $row['murajaa_score'] !== null ? (float) $row['murajaa_score'] : null,
            'entered_by' => $row['entered_by'] !== null ? (int) $row['entered_by'] : null,
        ];
    }

    public function indexReviews(Request $request): Response
    {
        $me = $request->getUser();
        $role = $me['role'] ?? 'teacher';

        $conditions = [];
        $params = [];

        if ($role !== 'admin' && $role !== 'board') {
            $conditions[] = "s.center_id = :cid";
            $params['cid'] = (int) $me['center_id'];
        }

        if ($request->query('student_id')) {
            $conditions[] = "mr.student_id = :sid";
            $params['sid'] = (int) $request->query('student_id');
        }

        if ($request->query('term_id')) {
            $conditions[] = "mr.term_id = :tid";
            $params['tid'] = (int) $request->query('term_id');
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "
            SELECT mr.*
            FROM `murajaa_reviews` mr
            JOIN `students` s ON s.id = mr.student_id
            {$whereClause}
            ORDER BY mr.week_from ASC
        ";

        $page = (int) $request->query('page', 1);
        $perPage = min(max((int) $request->query('per_page', 50), 1), 100);

        $paginated = Database::paginate($sql, $params, $page, $perPage);
        $paginated['data'] = array_map([$this, 'formatReview'], $paginated['data']);

        return $this->ok($paginated);
    }

    public function storeReview(Request $request): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor', 'teacher']);
        $me = $request->getUser();

        if ($me['role'] !== 'admin' && ($me['teacher_type'] ?? '') === 'hifz') {
            return $this->fail('This action is unauthorized.', 403);
        }

        $v = Validator::make($request->all(), [
            'student_id' => 'required|integer',
            'term_id' => 'required|integer',
            'week_from' => 'required|integer',
            'week_to' => 'required|integer',
            'score' => 'required|numeric|min:0|max:20',
        ]);
        if ($v->fails()) {
            return $this->fail('The given data was invalid.', 422, $v->errors());
        }

        $studentId = (int) $request->input('student_id');
        $student = Database::fetchOne("SELECT id, center_id FROM `students` WHERE id = :id", ['id' => $studentId]);
        if (!$student) {
            return $this->fail('Student not found.', 404);
        }

        if ($me['role'] !== 'admin' && (int) $student['center_id'] !== (int) $me['center_id']) {
            return $this->fail('Student is in another center.', 403);
        }

        $weekFrom = (int) $request->input('week_from');
        $weekTo = (int) $request->input('week_to');
        $span = $weekTo - $weekFrom + 1;

        if ($weekTo < $weekFrom || $span < 1 || $span > 3) {
            return $this->fail('Review must cover 1 to 3 weeks.', 422, ['code' => 'SPAN_INVALID']);
        }

        $termId = (int) $request->input('term_id');
        $term = Database::fetchOne("SELECT id, season_id FROM `terms` WHERE id = :id", ['id' => $termId]);
        if (!$term) {
            return $this->fail('Term not found.', 404);
        }

        $seasonId = (int) $term['season_id'];

        $reviewId = Database::insert('murajaa_reviews', [
            'student_id' => $studentId,
            'season_id' => $seasonId,
            'term_id' => $termId,
            'week_from' => $weekFrom,
            'week_to' => $weekTo,
            'weeks_covered' => $span,
            'session_id' => $request->input('session_id') ? (int) $request->input('session_id') : null,
            'hizb_from' => $request->input('hizb_from'),
            'hizb_to' => $request->input('hizb_to'),
            'surah_from' => $request->input('surah_from'),
            'ayah_from' => $request->input('ayah_from'),
            'surah_to' => $request->input('surah_to'),
            'ayah_to' => $request->input('ayah_to'),
            'score' => (float) $request->input('score'),
            'entered_by' => (int) $me['id'],
            'reviewed_at' => $request->input('reviewed_at') ?: date('Y-m-d'),
        ]);

        $fresh = Database::fetchOne("SELECT * FROM `murajaa_reviews` WHERE id = :id", ['id' => $reviewId]);
        return $this->created($this->formatReview($fresh));
    }

    public function showReview(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? $params['murajaaReview'] ?? 0);
        $review = Database::fetchOne("SELECT * FROM `murajaa_reviews` WHERE id = :id", ['id' => $id]);
        if (!$review) {
            return $this->fail('Review not found.', 404);
        }

        return $this->ok($this->formatReview($review));
    }

    public function destroyReview(Request $request, array $params): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor']);
        $id = (int) ($params['id'] ?? $params['murajaaReview'] ?? 0);

        Database::delete('murajaa_reviews', 'id = :id', ['id' => $id]);
        return $this->ok(null, 'Deleted.');
    }

    public function indexLogs(Request $request): Response
    {
        $me = $request->getUser();
        $role = $me['role'] ?? 'teacher';

        $conditions = [];
        $params = [];

        if ($role !== 'admin' && $role !== 'board') {
            $conditions[] = "s.center_id = :cid";
            $params['cid'] = (int) $me['center_id'];
        }

        foreach (['student_id', 'session_id', 'week_id'] as $f) {
            if ($request->query($f) !== null && $request->query($f) !== '') {
                $conditions[] = "rl.{$f} = :{$f}";
                $params[$f] = (int) $request->query($f);
            }
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "
            SELECT rl.*
            FROM `revision_logs` rl
            JOIN `students` s ON s.id = rl.student_id
            {$whereClause}
            ORDER BY rl.id DESC
        ";

        $page = (int) $request->query('page', 1);
        $perPage = min(max((int) $request->query('per_page', 100), 1), 100);

        $paginated = Database::paginate($sql, $params, $page, $perPage);
        $paginated['data'] = array_map([$this, 'formatLog'], $paginated['data']);

        return $this->ok($paginated);
    }

    public function storeLog(Request $request): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor', 'teacher']);
        $me = $request->getUser();

        $v = Validator::make($request->all(), [
            'student_id' => 'required|integer',
            'session_id' => 'required|integer',
            'murajaa_score' => 'nullable|numeric|min:0|max:20',
        ]);
        if ($v->fails()) {
            return $this->fail('The given data was invalid.', 422, $v->errors());
        }

        $studentId = (int) $request->input('student_id');
        $student = Database::fetchOne("SELECT id, center_id FROM `students` WHERE id = :id", ['id' => $studentId]);
        if (!$student) {
            return $this->fail('Student not found.', 404);
        }

        if ($me['role'] !== 'admin' && (int) $student['center_id'] !== (int) $me['center_id']) {
            return $this->fail('Student is in another center.', 403);
        }

        $sessionId = (int) $request->input('session_id');
        $session = Database::fetchOne("SELECT * FROM `sessions` WHERE id = :id", ['id' => $sessionId]);
        if (!$session) {
            return $this->fail('Session not found.', 404);
        }

        $logId = Database::insert('revision_logs', [
            'student_id' => $studentId,
            'season_id' => (int) $session['season_id'],
            'term_id' => (int) $session['term_id'],
            'week_id' => (int) $session['week_id'],
            'session_id' => $sessionId,
            'log_date' => $session['planned_date'] ?: date('Y-m-d'),
            'hizb_from' => $request->input('hizb_from'),
            'hizb_to' => $request->input('hizb_to'),
            'murajaa_score' => $request->input('murajaa_score') !== null ? (float) $request->input('murajaa_score') : null,
            'entered_by' => (int) $me['id'],
        ]);

        $fresh = Database::fetchOne("SELECT * FROM `revision_logs` WHERE id = :id", ['id' => $logId]);
        return $this->created($this->formatLog($fresh));
    }

    public function showLog(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? $params['revisionLog'] ?? 0);
        $log = Database::fetchOne("SELECT * FROM `revision_logs` WHERE id = :id", ['id' => $id]);
        if (!$log) {
            return $this->fail('Revision log not found.', 404);
        }

        return $this->ok($this->formatLog($log));
    }

    public function destroyLog(Request $request, array $params): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor']);
        $id = (int) ($params['id'] ?? $params['revisionLog'] ?? 0);

        Database::delete('revision_logs', 'id = :id', ['id' => $id]);
        return $this->ok(null, 'Deleted.');
    }
}
