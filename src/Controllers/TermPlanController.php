<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

final class TermPlanController extends BaseController
{
    private function formatTermPlan(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'student_id' => (int) $row['student_id'],
            'season_id' => (int) $row['season_id'],
            'term_id' => (int) $row['term_id'],
            'goal_text' => $row['goal_text'] ?? null,
            'plan_mode' => $row['plan_mode'] ?? 'thumn',
            'start_hizb' => $row['start_hizb'] !== null ? (float) $row['start_hizb'] : null,
            'end_hizb' => $row['end_hizb'] !== null ? (float) $row['end_hizb'] : null,
            'plan_surah_from' => $row['plan_surah_from'] !== null ? (int) $row['plan_surah_from'] : null,
            'plan_ayah_from' => $row['plan_ayah_from'] !== null ? (int) $row['plan_ayah_from'] : null,
            'plan_surah_to' => $row['plan_surah_to'] !== null ? (int) $row['plan_surah_to'] : null,
            'plan_ayah_to' => $row['plan_ayah_to'] !== null ? (int) $row['plan_ayah_to'] : null,
            'expected_hifz_week_thumn' => $row['expected_hifz_week_thumn'] !== null ? (float) $row['expected_hifz_week_thumn'] : null,
            'expected_hifz_term_ahzab' => $row['expected_hifz_term_ahzab'] !== null ? (float) $row['expected_hifz_term_ahzab'] : null,
            'expected_hifz_season_ahzab' => $row['expected_hifz_season_ahzab'] !== null ? (float) $row['expected_hifz_season_ahzab'] : null,
            'khatm_expected_at' => $row['khatm_expected_at'] ?? null,
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

        if ($request->query('student_id')) {
            $conditions[] = "tp.student_id = :sid";
            $params['sid'] = (int) $request->query('student_id');
        }

        if ($request->query('term_id')) {
            $conditions[] = "tp.term_id = :tid";
            $params['tid'] = (int) $request->query('term_id');
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "
            SELECT tp.*
            FROM `term_plans` tp
            JOIN `students` s ON s.id = tp.student_id
            {$whereClause}
            ORDER BY tp.id DESC
        ";

        $page = (int) $request->query('page', 1);
        $perPage = min(max((int) $request->query('per_page', 50), 1), 100);

        $paginated = Database::paginate($sql, $params, $page, $perPage);
        $paginated['data'] = array_map([$this, 'formatTermPlan'], $paginated['data']);

        return $this->ok($paginated);
    }

    public function upsert(Request $request): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor', 'teacher']);
        $me = $request->getUser();

        $v = Validator::make($request->all(), [
            'student_id' => 'required|integer',
            'term_id' => 'required|integer',
            'plan_mode' => 'required|in:thumn,surah',
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
        $planMode = (string) $request->input('plan_mode');

        $data = [
            'student_id' => $studentId,
            'season_id' => $seasonId,
            'term_id' => $termId,
            'plan_mode' => $planMode,
            'goal_text' => $request->input('goal_text'),
            'expected_hifz_week_thumn' => $request->input('expected_hifz_week_thumn'),
            'expected_hifz_term_ahzab' => $request->input('expected_hifz_term_ahzab'),
            'expected_hifz_season_ahzab' => $request->input('expected_hifz_season_ahzab'),
            'khatm_expected_at' => $request->input('khatm_expected_at'),
        ];

        if ($planMode === 'thumn') {
            $startHizb = $request->input('start_hizb') !== null ? (float) $request->input('start_hizb') : null;
            $endHizb = $request->input('end_hizb') !== null ? (float) $request->input('end_hizb') : null;

            if ($startHizb !== null && $endHizb !== null && $endHizb < $startHizb) {
                return $this->fail('End hizb must be >= start hizb.', 422, ['code' => 'RANGE_INVALID']);
            }

            $data['start_hizb'] = $startHizb;
            $data['end_hizb'] = $endHizb;
            $data['plan_surah_from'] = null;
            $data['plan_ayah_from'] = null;
            $data['plan_surah_to'] = null;
            $data['plan_ayah_to'] = null;
        } else {
            $sFrom = $request->input('plan_surah_from');
            $aFrom = $request->input('plan_ayah_from');
            $sTo = $request->input('plan_surah_to');
            $aTo = $request->input('plan_ayah_to');

            if ($sFrom === null || $aFrom === null || $sTo === null || $aTo === null) {
                return $this->fail('Surah range needs surah + ayah on both ends.', 422, ['code' => 'RANGE_INVALID']);
            }

            $sFrom = (int) $sFrom;
            $aFrom = (int) $aFrom;
            $sTo = (int) $sTo;
            $aTo = (int) $aTo;

            if ($sTo < $sFrom || ($sTo === $sFrom && $aTo < $aFrom)) {
                return $this->fail('Range end must be after range start.', 422, ['code' => 'RANGE_INVALID']);
            }

            $data['start_hizb'] = null;
            $data['end_hizb'] = null;
            $data['plan_surah_from'] = $sFrom;
            $data['plan_ayah_from'] = $aFrom;
            $data['plan_surah_to'] = $sTo;
            $data['plan_ayah_to'] = $aTo;
        }

        $existing = Database::fetchOne("SELECT id FROM `term_plans` WHERE student_id = :sid AND term_id = :tid", [
            'sid' => $studentId, 'tid' => $termId,
        ]);

        if ($existing) {
            Database::update('term_plans', $data, 'id = :id', ['id' => $existing['id']]);
            $planId = (int) $existing['id'];
        } else {
            $planId = Database::insert('term_plans', $data);
        }

        $fresh = Database::fetchOne("SELECT * FROM `term_plans` WHERE id = :id", ['id' => $planId]);
        return $this->created($this->formatTermPlan($fresh));
    }

    public function show(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? $params['termPlan'] ?? 0);
        $plan = Database::fetchOne("SELECT * FROM `term_plans` WHERE id = :id", ['id' => $id]);
        if (!$plan) {
            return $this->fail('Term plan not found.', 404);
        }

        return $this->ok($this->formatTermPlan($plan));
    }

    public function destroy(Request $request, array $params): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor']);
        $id = (int) ($params['id'] ?? $params['termPlan'] ?? 0);

        Database::delete('term_plans', 'id = :id', ['id' => $id]);
        return $this->ok(null, 'Deleted.');
    }
}
