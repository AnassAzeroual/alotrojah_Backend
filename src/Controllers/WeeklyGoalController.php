<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

final class WeeklyGoalController extends BaseController
{
    private function formatGoal(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'student_id' => (int) $row['student_id'],
            'season_id' => (int) $row['season_id'],
            'week_id' => (int) $row['week_id'],
            'target_text' => $row['target_text'] ?? null,
            'is_completed' => $row['is_completed'] !== null ? (bool) $row['is_completed'] : null,
            'checked_by' => $row['checked_by'] !== null ? (int) $row['checked_by'] : null,
            'checked_at' => $row['checked_at'] ?? null,
        ];
    }

    public function index(Request $request): Response
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
            $conditions[] = "wg.student_id = :sid";
            $params['sid'] = (int) $request->query('student_id');
        }

        if ($request->query('week_id')) {
            $conditions[] = "wg.week_id = :wid";
            $params['wid'] = (int) $request->query('week_id');
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "
            SELECT wg.*
            FROM `weekly_goals` wg
            JOIN `students` s ON s.id = wg.student_id
            {$whereClause}
            ORDER BY wg.id DESC
        ";

        $page = (int) $request->query('page', 1);
        $perPage = min(max((int) $request->query('per_page', 50), 1), 100);

        $paginated = Database::paginate($sql, $params, $page, $perPage);
        $paginated['data'] = array_map([$this, 'formatGoal'], $paginated['data']);

        return $this->ok($paginated);
    }

    public function upsert(Request $request): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor', 'teacher']);
        $me = $request->getUser();

        $v = Validator::make($request->all(), [
            'student_id' => 'required|integer',
            'week_id' => 'required|integer',
            'target_text' => 'nullable|string',
            'is_completed' => 'nullable|boolean',
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

        $weekId = (int) $request->input('week_id');
        $week = Database::fetchOne("SELECT id, season_id FROM `weeks` WHERE id = :id", ['id' => $weekId]);
        if (!$week) {
            return $this->fail('Week not found.', 404);
        }

        $seasonId = (int) $week['season_id'];
        $targetText = $request->input('target_text');
        $isCompleted = $request->input('is_completed') !== null
            ? (int) filter_var($request->input('is_completed'), FILTER_VALIDATE_BOOLEAN)
            : null;

        $existing = Database::fetchOne("SELECT id FROM `weekly_goals` WHERE student_id = :sid AND week_id = :wid", [
            'sid' => $studentId, 'wid' => $weekId,
        ]);

        if ($existing) {
            Database::update('weekly_goals', [
                'target_text' => $targetText,
                'is_completed' => $isCompleted,
                'checked_by' => (int) $me['id'],
                'checked_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $existing['id']]);
            $goalId = (int) $existing['id'];
        } else {
            $goalId = Database::insert('weekly_goals', [
                'student_id' => $studentId,
                'season_id' => $seasonId,
                'week_id' => $weekId,
                'target_text' => $targetText,
                'is_completed' => $isCompleted,
                'checked_by' => (int) $me['id'],
                'checked_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $fresh = Database::fetchOne("SELECT * FROM `weekly_goals` WHERE id = :id", ['id' => $goalId]);
        return $this->created($this->formatGoal($fresh));
    }

    public function show(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? $params['weeklyGoal'] ?? 0);
        $goal = Database::fetchOne("SELECT * FROM `weekly_goals` WHERE id = :id", ['id' => $id]);
        if (!$goal) {
            return $this->fail('Weekly goal not found.', 404);
        }

        return $this->ok($this->formatGoal($goal));
    }

    public function destroy(Request $request, array $params): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor']);
        $id = (int) ($params['id'] ?? $params['weeklyGoal'] ?? 0);

        Database::delete('weekly_goals', 'id = :id', ['id' => $id]);
        return $this->ok(null, 'Deleted.');
    }
}
