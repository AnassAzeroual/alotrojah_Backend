<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

class AnnouncementController extends BaseController
{
    /**
     * GET /v1/announcements
     * List announcements visible to the caller (20/page).
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $this->forbidden();
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 20;
        $audience = $request->query('audience');

        $db = Database::getInstance();

        $where = [];
        $params = [];

        // Visibility scoping
        if ($user['role'] !== 'admin' && $user['role'] !== 'board') {
            if ($user['role'] === 'supervisor') {
                $where[] = "(
                    a.audience = 'all'
                    OR u.center_id = :sup_cid
                    OR u.center_id IS NULL
                )";
                $params['sup_cid'] = (int) $user['center_id'];
            } elseif ($user['role'] === 'teacher') {
                $where[] = "(
                    a.audience = 'all'
                    OR (u.center_id = :tea_cid AND a.audience IN ('teachers', 'my_students'))
                    OR (u.center_id IS NULL AND a.audience = 'teachers')
                )";
                $params['tea_cid'] = (int) $user['center_id'];
            } elseif ($user['role'] === 'student') {
                // Find student's group
                $studentGroup = $db->query(
                    'SELECT group_id FROM students WHERE user_id = :uid LIMIT 1',
                    ['uid' => (int) $user['id']]
                )->fetchColumn();

                if ($studentGroup) {
                    $where[] = "(a.audience = 'all' OR (a.audience = 'my_students' AND a.group_id = :s_gid))";
                    $params['s_gid'] = (int) $studentGroup;
                } else {
                    $where[] = "a.audience = 'all'";
                }
            } else {
                $where[] = "a.audience = 'all'";
            }
        }

        if ($audience) {
            $where[] = 'a.audience = :aud';
            $params['aud'] = $audience;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countRow = $db->query(
            "SELECT COUNT(*) as total FROM announcements a LEFT JOIN users u ON u.id = a.author_id {$whereSql}",
            $params
        )->fetch();
        $total = (int) ($countRow['total'] ?? 0);

        $offset = ($page - 1) * $perPage;
        $rows = $db->query(
            "SELECT a.*, u.id as u_id, u.full_name as u_name
             FROM announcements a
             LEFT JOIN users u ON u.id = a.author_id
             {$whereSql}
             ORDER BY a.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        $data = array_map(function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'audience' => $r['audience'],
                'group_id' => $r['group_id'] !== null ? (int) $r['group_id'] : null,
                'title' => $r['title'],
                'body' => $r['body'],
                'author' => [
                    'id' => (int) ($r['u_id'] ?? $r['author_id']),
                    'full_name' => (string) ($r['u_name'] ?? ''),
                ],
                'created_at' => (string) $r['created_at'],
            ];
        }, $rows);

        return $this->paginated($data, $total, $page, $perPage, '/v1/announcements');
    }

    /**
     * POST /v1/announcements
     * Publish an announcement (author = caller).
     */
    public function store(Request $request): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $this->forbidden();
        }

        $v = (new Validator($request->json()))
            ->required('audience')->in('audience', ['all', 'teachers', 'manager', 'my_students'])
            ->required('title')->max('title', 200)
            ->required('body');

        if ($v->fails()) {
            return $this->unprocessable($v->errors());
        }

        $body = $request->json();
        $audience = (string) $body['audience'];
        $groupId = !empty($body['group_id']) ? (int) $body['group_id'] : null;

        $db = Database::getInstance();

        if ($audience === 'my_students') {
            if (!$groupId) {
                return $this->fail('Group is required for my_students audience.', 422);
            }
            $group = $db->query('SELECT * FROM `groups` WHERE id = :id LIMIT 1', ['id' => $groupId])->fetch();
            if (!$group) {
                return $this->notFound('Group not found.');
            }

            if ($user['role'] === 'teacher' && (int) $group['teacher_id'] !== (int) $user['id']) {
                return $this->fail('You may only address your own students.', 403);
            }
            if ($user['role'] === 'supervisor' && (int) $group['center_id'] !== (int) $user['center_id']) {
                return $this->fail('Group is in another center.', 403);
            }
        }

        $insertId = $db->insert('announcements', [
            'author_id' => (int) $user['id'],
            'audience' => $audience,
            'group_id' => $groupId,
            'title' => (string) $body['title'],
            'body' => (string) $body['body'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $r = $db->query(
            'SELECT a.*, u.id as u_id, u.full_name as u_name
             FROM announcements a
             LEFT JOIN users u ON u.id = a.author_id
             WHERE a.id = :id LIMIT 1',
            ['id' => $insertId]
        )->fetch();

        return $this->created([
            'id' => (int) $r['id'],
            'audience' => $r['audience'],
            'group_id' => $r['group_id'] !== null ? (int) $r['group_id'] : null,
            'title' => $r['title'],
            'body' => $r['body'],
            'author' => [
                'id' => (int) ($r['u_id'] ?? $r['author_id']),
                'full_name' => (string) ($r['u_name'] ?? ''),
            ],
            'created_at' => (string) $r['created_at'],
        ]);
    }

    /**
     * GET /v1/announcements/{announcement}
     * Show one announcement with its author.
     */
    public function show(Request $request): Response
    {
        $id = (int) $request->param('announcement');
        $db = Database::getInstance();

        $r = $db->query(
            'SELECT a.*, u.id as u_id, u.full_name as u_name
             FROM announcements a
             LEFT JOIN users u ON u.id = a.author_id
             WHERE a.id = :id LIMIT 1',
            ['id' => $id]
        )->fetch();

        if (!$r) {
            return $this->notFound('Announcement not found.');
        }

        return $this->ok([
            'id' => (int) $r['id'],
            'audience' => $r['audience'],
            'group_id' => $r['group_id'] !== null ? (int) $r['group_id'] : null,
            'title' => $r['title'],
            'body' => $r['body'],
            'author' => [
                'id' => (int) ($r['u_id'] ?? $r['author_id']),
                'full_name' => (string) ($r['u_name'] ?? ''),
            ],
            'created_at' => (string) $r['created_at'],
        ]);
    }

    /**
     * PUT /v1/announcements/{announcement}
     * Update an announcement's text/audience (author or admin).
     */
    public function update(Request $request): Response
    {
        $user = $request->user();
        $id = (int) $request->param('announcement');
        $db = Database::getInstance();

        $r = $db->query('SELECT * FROM announcements WHERE id = :id LIMIT 1', ['id' => $id])->fetch();
        if (!$r) {
            return $this->notFound('Announcement not found.');
        }

        if ($user['role'] !== 'admin' && (int) $r['author_id'] !== (int) $user['id']) {
            return $this->forbidden('Only author or admin can update.');
        }

        $body = $request->json();
        $updates = [];
        if (isset($body['audience'])) {
            $updates['audience'] = (string) $body['audience'];
        }
        if (array_key_exists('group_id', $body)) {
            $updates['group_id'] = $body['group_id'] !== null ? (int) $body['group_id'] : null;
        }
        if (isset($body['title'])) {
            $updates['title'] = (string) $body['title'];
        }
        if (isset($body['body'])) {
            $updates['body'] = (string) $body['body'];
        }

        if (!empty($updates)) {
            $db->update('announcements', $updates, 'id = :id', ['id' => $id]);
        }

        $updated = $db->query(
            'SELECT a.*, u.id as u_id, u.full_name as u_name
             FROM announcements a
             LEFT JOIN users u ON u.id = a.author_id
             WHERE a.id = :id LIMIT 1',
            ['id' => $id]
        )->fetch();

        return $this->ok([
            'id' => (int) $updated['id'],
            'audience' => $updated['audience'],
            'group_id' => $updated['group_id'] !== null ? (int) $updated['group_id'] : null,
            'title' => $updated['title'],
            'body' => $updated['body'],
            'author' => [
                'id' => (int) ($updated['u_id'] ?? $updated['author_id']),
                'full_name' => (string) ($updated['u_name'] ?? ''),
            ],
            'created_at' => (string) $updated['created_at'],
        ]);
    }

    /**
     * DELETE /v1/announcements/{announcement}
     * Delete an announcement (author or admin).
     */
    public function destroy(Request $request): Response
    {
        $user = $request->user();
        $id = (int) $request->param('announcement');
        $db = Database::getInstance();

        $r = $db->query('SELECT * FROM announcements WHERE id = :id LIMIT 1', ['id' => $id])->fetch();
        if (!$r) {
            return $this->notFound('Announcement not found.');
        }

        if ($user['role'] !== 'admin' && (int) $r['author_id'] !== (int) $user['id']) {
            return $this->forbidden('Only author or admin can delete.');
        }

        $db->delete('announcements', 'id = :id', ['id' => $id]);

        return $this->ok(null, 'Deleted.');
    }
}
