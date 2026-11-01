<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

class NotificationController extends BaseController
{
    /**
     * GET /v1/notifications
     * List notification logs (50/page, newest first).
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $this->forbidden();
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 50;
        $status = $request->query('status');
        $channel = $request->query('channel');

        $db = Database::getInstance();

        $where = [];
        $params = [];

        if ($user['role'] !== 'admin') {
            $where[] = 'u.center_id = :cid';
            $params['cid'] = (int) $user['center_id'];
        }

        if ($status) {
            $where[] = 'n.status = :st';
            $params['st'] = $status;
        }

        if ($channel) {
            $where[] = 'n.channel = :ch';
            $params['ch'] = $channel;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countRow = $db->query(
            "SELECT COUNT(*) as total FROM notifications_log n LEFT JOIN users u ON u.id = n.sent_by {$whereSql}",
            $params
        )->fetch();
        $total = (int) ($countRow['total'] ?? 0);

        $offset = ($page - 1) * $perPage;
        $rows = $db->query(
            "SELECT n.* FROM notifications_log n
             LEFT JOIN users u ON u.id = n.sent_by
             {$whereSql}
             ORDER BY n.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        $data = array_map([$this, 'formatNotification'], $rows);

        return $this->paginated($data, $total, $page, $perPage, '/v1/notifications');
    }

    /**
     * POST /v1/notifications
     * Queue a WhatsApp message; teacher taps the wa_link on their phone to send.
     */
    public function store(Request $request): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $this->forbidden();
        }

        $v = (new Validator($request->json()))
            ->required('recipient_phone')->max('recipient_phone', 30)
            ->required('message')->max('message', 2000);

        if ($v->fails()) {
            return $this->unprocessable($v->errors());
        }

        $body = $request->json();
        $channel = (string) ($body['channel'] ?? 'whatsapp');

        $db = Database::getInstance();
        $insertId = $db->insert('notifications_log', [
            'recipient_phone' => (string) $body['recipient_phone'],
            'channel' => $channel,
            'message' => (string) $body['message'],
            'status' => 'queued',
            'sent_by' => (int) $user['id'],
            'sent_at' => null,
        ]);

        $r = $db->query('SELECT * FROM notifications_log WHERE id = :id LIMIT 1', ['id' => $insertId])->fetch();

        return $this->created($this->formatNotification($r));
    }

    /**
     * GET /v1/notifications/{notification}
     * Show one notification log.
     */
    public function show(Request $request): Response
    {
        $id = (int) $request->param('notification');
        $db = Database::getInstance();

        $r = $db->query('SELECT * FROM notifications_log WHERE id = :id LIMIT 1', ['id' => $id])->fetch();
        if (!$r) {
            return $this->notFound('Notification not found.');
        }

        return $this->ok($this->formatNotification($r));
    }

    /**
     * PATCH /v1/notifications/{notification}/status
     * Mark a notification queued/sent/failed.
     */
    public function mark(Request $request): Response
    {
        $id = (int) $request->param('notification');
        $db = Database::getInstance();

        $r = $db->query('SELECT * FROM notifications_log WHERE id = :id LIMIT 1', ['id' => $id])->fetch();
        if (!$r) {
            return $this->notFound('Notification not found.');
        }

        $v = (new Validator($request->json()))
            ->required('status')->in('status', ['queued', 'sent', 'failed']);

        if ($v->fails()) {
            return $this->unprocessable($v->errors());
        }

        $status = (string) $request->json('status');
        $sentAt = $status === 'sent' ? date('Y-m-d H:i:s') : null;

        $db->update('notifications_log', [
            'status' => $status,
            'sent_at' => $sentAt,
        ], 'id = :id', ['id' => $id]);

        $updated = $db->query('SELECT * FROM notifications_log WHERE id = :id LIMIT 1', ['id' => $id])->fetch();

        return $this->ok($this->formatNotification($updated));
    }

    /**
     * DELETE /v1/notifications/{notification}
     * Delete a notification log.
     */
    public function destroy(Request $request): Response
    {
        $id = (int) $request->param('notification');
        $db = Database::getInstance();

        $r = $db->query('SELECT * FROM notifications_log WHERE id = :id LIMIT 1', ['id' => $id])->fetch();
        if (!$r) {
            return $this->notFound('Notification not found.');
        }

        $db->delete('notifications_log', 'id = :id', ['id' => $id]);

        return $this->ok(null, 'Deleted.');
    }

    private function formatNotification(array $r): array
    {
        $digits = preg_replace('/\D/', '', (string) $r['recipient_phone']);

        return [
            'id' => (int) $r['id'],
            'recipient_phone' => (string) $r['recipient_phone'],
            'wa_link' => 'https://wa.me/' . $digits . '?text=' . urlencode((string) $r['message']),
            'channel' => (string) $r['channel'],
            'status' => (string) $r['status'],
            'sent_at' => $r['sent_at'] !== null ? (string) $r['sent_at'] : null,
        ];
    }
}
