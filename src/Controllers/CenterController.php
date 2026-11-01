<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

final class CenterController extends BaseController
{
    private function formatCenter(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'city' => $row['city'] ?? null,
            'address' => $row['address'] ?? null,
            'phone' => $row['phone'] ?? null,
            'manager_name' => $row['manager_name'] ?? null,
            'groups_count' => isset($row['groups_count']) ? (int) $row['groups_count'] : 0,
            'students_count' => isset($row['students_count']) ? (int) $row['students_count'] : 0,
        ];
    }

    public function index(Request $request): Response
    {
        $role = $this->role($request);
        $conditions = [];
        $params = [];

        if ($role !== 'admin' && $role !== 'board') {
            $conditions[] = "c.id = :cid";
            $params['cid'] = (int) $this->centerId($request, true);
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "
            SELECT c.*,
                   (SELECT COUNT(*) FROM `groups` g WHERE g.center_id = c.id) AS groups_count,
                   (SELECT COUNT(*) FROM `students` s WHERE s.center_id = c.id) AS students_count
            FROM `centers` c
            {$whereClause}
            ORDER BY c.id ASC
        ";

        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);

        $paginated = Database::paginate($sql, $params, $page, $perPage);
        $paginated['data'] = array_map([$this, 'formatCenter'], $paginated['data']);

        return $this->ok($paginated);
    }

    public function store(Request $request): Response
    {
        $this->authorizeRole($request, ['admin']);

        $v = Validator::make($request->all(), [
            'name' => 'required|string',
            'city' => 'nullable|string',
            'address' => 'nullable|string',
            'phone' => 'nullable|string',
            'manager_name' => 'nullable|string',
        ]);
        if ($v->fails()) {
            return $this->fail('The given data was invalid.', 422, $v->errors());
        }

        $id = Database::insert('centers', [
            'name' => trim((string) $request->input('name')),
            'city' => $request->input('city'),
            'address' => $request->input('address'),
            'phone' => $request->input('phone'),
            'manager_name' => $request->input('manager_name'),
        ]);

        $created = Database::fetchOne("
            SELECT c.*,
                   0 AS groups_count,
                   0 AS students_count
            FROM `centers` c
            WHERE c.id = :id
        ", ['id' => $id]);

        return $this->created($this->formatCenter($created));
    }

    public function show(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? $params['center'] ?? 0);
        $row = Database::fetchOne("
            SELECT c.*,
                   (SELECT COUNT(*) FROM `groups` g WHERE g.center_id = c.id) AS groups_count,
                   (SELECT COUNT(*) FROM `students` s WHERE s.center_id = c.id) AS students_count
            FROM `centers` c
            WHERE c.id = :id
        ", ['id' => $id]);

        if (!$row) {
            return $this->fail('Center not found.', 404);
        }

        $role = $this->role($request);
        if ($role !== 'admin' && $role !== 'board' && (int) $row['id'] !== (int) $request->centerId()) {
            return $this->fail('This action is unauthorized.', 403);
        }

        return $this->ok($this->formatCenter($row));
    }

    public function update(Request $request, array $params): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor']);
        $id = (int) ($params['id'] ?? $params['center'] ?? 0);

        $row = Database::fetchOne("SELECT * FROM `centers` WHERE id = :id", ['id' => $id]);
        if (!$row) {
            return $this->fail('Center not found.', 404);
        }

        $role = $this->role($request);
        if ($role !== 'admin' && (int) $row['id'] !== (int) $request->centerId()) {
            return $this->fail('This action is unauthorized.', 403);
        }

        $fields = ['name', 'city', 'address', 'phone', 'manager_name'];
        $update = [];
        foreach ($fields as $f) {
            if ($request->input($f) !== null) {
                $update[$f] = trim((string) $request->input($f));
            }
        }

        if (!empty($update)) {
            Database::update('centers', $update, 'id = :id', ['id' => $id]);
        }

        $fresh = Database::fetchOne("
            SELECT c.*,
                   (SELECT COUNT(*) FROM `groups` g WHERE g.center_id = c.id) AS groups_count,
                   (SELECT COUNT(*) FROM `students` s WHERE s.center_id = c.id) AS students_count
            FROM `centers` c
            WHERE c.id = :id
        ", ['id' => $id]);

        return $this->ok($this->formatCenter($fresh));
    }

    public function destroy(Request $request, array $params): Response
    {
        return $this->fail('Centers cannot be deleted.', 422);
    }
}
