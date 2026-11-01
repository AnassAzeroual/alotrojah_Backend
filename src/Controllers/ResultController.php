<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

final class ResultController extends BaseController
{
    private function formatTermResult(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'student_id' => (int) $row['student_id'],
            'season_id' => (int) $row['season_id'],
            'term_id' => (int) $row['term_id'],
            'hifz_total' => $row['hifz_total'] !== null ? (float) $row['hifz_total'] : null,
            'murajaa_total' => $row['murajaa_total'] !== null ? (float) $row['murajaa_total'] : null,
            'exam_score' => $row['exam_score'] !== null ? (float) $row['exam_score'] : null,
            'general_avg' => $row['general_avg'] !== null ? (float) $row['general_avg'] : null,
            'teacher_notes' => $row['teacher_notes'] ?? null,
            'supervisor_note' => $row['supervisor_note'] ?? null,
            'honor_flag' => $row['honor_flag'] ?? 'none',
        ];
    }

    private function formatSeasonResult(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'student_id' => (int) $row['student_id'],
            'season_id' => (int) $row['season_id'],
            'total_memorized_label' => $row['total_memorized_label'] ?? null,
            'total_memorized_thumn' => $row['total_memorized_thumn'] !== null ? (float) $row['total_memorized_thumn'] : null,
            'hifz_total' => $row['hifz_total'] !== null ? (float) $row['hifz_total'] : null,
            'murajaa_total' => $row['murajaa_total'] !== null ? (float) $row['murajaa_total'] : null,
            'overall_avg' => $row['overall_avg'] !== null ? (float) $row['overall_avg'] : null,
            'board_report' => $row['board_report'] ?? null,
            'honor_flag' => $row['honor_flag'] ?? 'none',
        ];
    }

    public function indexTerms(Request $request): Response
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

        if ($request->query('student_id')) {
            $conditions[] = "tr.student_id = :sid";
            $params['sid'] = (int) $request->query('student_id');
        }

        if ($request->query('term_id')) {
            $conditions[] = "tr.term_id = :tid";
            $params['tid'] = (int) $request->query('term_id');
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "
            SELECT tr.*
            FROM `term_results` tr
            JOIN `students` s ON s.id = tr.student_id
            {$whereClause}
            ORDER BY tr.id DESC
        ";

        $page = (int) $request->query('page', 1);
        $perPage = min(max((int) $request->query('per_page', 50), 1), 100);

        $paginated = Database::paginate($sql, $params, $page, $perPage);
        $paginated['data'] = array_map([$this, 'formatTermResult'], $paginated['data']);

        return $this->ok($paginated);
    }

    public function upsertTerm(Request $request): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor', 'teacher']);
        $me = $request->getUser();

        $v = Validator::make($request->all(), [
            'student_id' => 'required|integer',
            'term_id' => 'required|integer',
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

        $termId = (int) $request->input('term_id');
        $term = Database::fetchOne("SELECT id, season_id FROM `terms` WHERE id = :id", ['id' => $termId]);
        if (!$term) {
            return $this->fail('Term not found.', 404);
        }

        $seasonId = (int) $term['season_id'];
        $data = [
            'student_id' => $studentId,
            'season_id' => $seasonId,
            'term_id' => $termId,
            'hifz_total' => $request->input('hifz_total'),
            'murajaa_total' => $request->input('murajaa_total'),
            'exam_score' => $request->input('exam_score'),
            'general_avg' => $request->input('general_avg'),
            'teacher_notes' => $request->input('teacher_notes'),
            'supervisor_note' => $request->input('supervisor_note'),
            'honor_flag' => $request->input('honor_flag') ?: 'none',
        ];

        $existing = Database::fetchOne("SELECT id FROM `term_results` WHERE student_id = :sid AND term_id = :tid", [
            'sid' => $studentId, 'tid' => $termId,
        ]);

        if ($existing) {
            Database::update('term_results', $data, 'id = :id', ['id' => $existing['id']]);
            $resId = (int) $existing['id'];
        } else {
            $resId = Database::insert('term_results', $data);
        }

        $fresh = Database::fetchOne("SELECT * FROM `term_results` WHERE id = :id", ['id' => $resId]);
        return $this->created($this->formatTermResult($fresh));
    }

    public function indexSeasons(Request $request): Response
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

        if ($request->query('student_id')) {
            $conditions[] = "sr.student_id = :sid";
            $params['sid'] = (int) $request->query('student_id');
        }

        if ($request->query('season_id')) {
            $conditions[] = "sr.season_id = :seid";
            $params['seid'] = (int) $request->query('season_id');
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "
            SELECT sr.*
            FROM `season_results` sr
            JOIN `students` s ON s.id = sr.student_id
            {$whereClause}
            ORDER BY sr.id DESC
        ";

        $page = (int) $request->query('page', 1);
        $perPage = min(max((int) $request->query('per_page', 50), 1), 100);

        $paginated = Database::paginate($sql, $params, $page, $perPage);
        $paginated['data'] = array_map([$this, 'formatSeasonResult'], $paginated['data']);

        return $this->ok($paginated);
    }

    public function upsertSeason(Request $request): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor', 'teacher']);
        $me = $request->getUser();

        $v = Validator::make($request->all(), [
            'student_id' => 'required|integer',
            'season_id' => 'required|integer',
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

        $seasonId = (int) $request->input('season_id');
        $data = [
            'student_id' => $studentId,
            'season_id' => $seasonId,
            'total_memorized_label' => $request->input('total_memorized_label'),
            'total_memorized_thumn' => $request->input('total_memorized_thumn'),
            'hifz_total' => $request->input('hifz_total'),
            'murajaa_total' => $request->input('murajaa_total'),
            'overall_avg' => $request->input('overall_avg'),
            'board_report' => $request->input('board_report'),
            'honor_flag' => $request->input('honor_flag') ?: 'none',
        ];

        $existing = Database::fetchOne("SELECT id FROM `season_results` WHERE student_id = :sid AND season_id = :seid", [
            'sid' => $studentId, 'seid' => $seasonId,
        ]);

        if ($existing) {
            Database::update('season_results', $data, 'id = :id', ['id' => $existing['id']]);
            $resId = (int) $existing['id'];
        } else {
            $resId = Database::insert('season_results', $data);
        }

        $fresh = Database::fetchOne("SELECT * FROM `season_results` WHERE id = :id", ['id' => $resId]);
        return $this->created($this->formatSeasonResult($fresh));
    }
}
