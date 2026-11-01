<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

class DelegationController extends BaseController
{
    /**
     * GET /v1/groups/{group}/delegations
     * List a group's delegation tokens (newest 50).
     */
    public function index(Request $request): Response
    {
        $groupId = (int) $request->param('group');
        $db = Database::getInstance();

        $rows = $db->query(
            'SELECT * FROM delegation_tokens WHERE group_id = :gid ORDER BY id DESC LIMIT 50',
            ['gid' => $groupId]
        )->fetchAll();

        $data = array_map(function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'group_id' => (int) $r['group_id'],
                'duration_minutes' => (int) $r['duration_minutes'],
                'expires_at' => (string) $r['expires_at'],
                'used_by_teacher_id' => (int) $r['used_by_teacher_id'],
                'is_revoked' => (bool) $r['is_revoked'],
            ];
        }, $rows);

        return $this->ok($data);
    }

    /**
     * POST /v1/groups/{group}/delegations
     * Responsible teacher opens time-boxed access; link goes via WhatsApp.
     */
    public function generate(Request $request): Response
    {
        $user = $request->user();
        $groupId = (int) $request->param('group');
        $db = Database::getInstance();

        $group = $db->query('SELECT * FROM `groups` WHERE id = :id LIMIT 1', ['id' => $groupId])->fetch();
        if (!$group) {
            return $this->notFound('Group not found.');
        }

        $v = (new Validator($request->json()))
            ->required('minutes')->in('minutes', [15, 30, 60, 120, '15', '30', '60', '120']);

        if ($v->fails()) {
            return $this->unprocessable($v->errors());
        }

        $minutes = (int) $request->json('minutes');
        $token = bin2hex(random_bytes(32)); // 64 hex characters
        $expiresAt = date('Y-m-d H:i:s', time() + ($minutes * 60));

        $insertId = $db->insert('delegation_tokens', [
            'group_id' => $groupId,
            'granter_teacher_id' => (int) $user['id'],
            'used_by_teacher_id' => (int) $user['id'],
            'token' => $token,
            'duration_minutes' => $minutes,
            'expires_at' => $expiresAt,
            'used_at' => null,
            'is_revoked' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $frontendUrl = rtrim(Config::get('FRONTEND_URL', 'http://localhost:4200'), '/');
        $shareLink = $frontendUrl . '/delegate/redeem?token=' . $token;

        return $this->created([
            'delegation' => [
                'id' => $insertId,
                'group_id' => $groupId,
                'duration_minutes' => $minutes,
                'expires_at' => $expiresAt,
                'used_by_teacher_id' => (int) $user['id'],
                'is_revoked' => false,
            ],
            'token' => $token,
            'link' => $shareLink,
            'expires_at' => $expiresAt,
        ], 'Share this link via WhatsApp.');
    }

    /**
     * POST /v1/delegations/redeem
     * Redeem a delegation token: gain time-boxed mark-entry rights for its group.
     */
    public function redeem(Request $request): Response
    {
        $user = $request->user();
        if ($user['role'] !== 'teacher') {
            return $this->forbidden('Only teachers can redeem delegation links.');
        }

        $v = (new Validator($request->json()))
            ->required('token');

        if ($v->fails()) {
            return $this->unprocessable($v->errors());
        }

        $tokenStr = (string) $request->json('token');
        $db = Database::getInstance();

        $d = $db->query('SELECT * FROM delegation_tokens WHERE token = :tok LIMIT 1', ['tok' => $tokenStr])->fetch();
        if (!$d) {
            return $this->unprocessable(['token' => ['Invalid link.']]);
        }

        if ((bool) $d['is_revoked']) {
            return $this->fail('Link revoked.', 410);
        }

        if (strtotime($d['expires_at']) < time()) {
            return $this->fail('Link expired.', 410);
        }

        if ($d['used_at'] === null) {
            $db->update('delegation_tokens', [
                'used_by_teacher_id' => (int) $user['id'],
                'used_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $d['id']]);
            $d['used_by_teacher_id'] = $user['id'];
        }

        if ((int) $d['used_by_teacher_id'] !== (int) $user['id']) {
            return $this->fail('Link bound to another teacher.', 403);
        }

        $group = $db->query('SELECT name FROM `groups` WHERE id = :id LIMIT 1', ['id' => $d['group_id']])->fetch();

        return $this->ok([
            'group_id' => (int) $d['group_id'],
            'group_name' => $group['name'] ?? null,
            'expires_at' => (string) $d['expires_at'],
        ], 'Access granted. You may now enter marks for this group.');
    }

    /**
     * DELETE /v1/delegations/{delegation}
     * Revoke a delegation token (granter or admin).
     */
    public function revoke(Request $request): Response
    {
        $user = $request->user();
        $id = (int) $request->param('delegation');
        $db = Database::getInstance();

        $d = $db->query('SELECT * FROM delegation_tokens WHERE id = :id LIMIT 1', ['id' => $id])->fetch();
        if (!$d) {
            return $this->notFound('Delegation token not found.');
        }

        if ($user['role'] !== 'admin' && (int) $d['granter_teacher_id'] !== (int) $user['id']) {
            return $this->forbidden('Only granter or admin can revoke.');
        }

        $db->update('delegation_tokens', ['is_revoked' => 1], 'id = :id', ['id' => $id]);

        return $this->ok(null, 'Revoked.');
    }
}
