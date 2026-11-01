<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

final class CalendarController extends BaseController
{
    private function formatTerm(array $t): array
    {
        return [
            'id' => (int) $t['id'],
            'season_id' => (int) $t['season_id'],
            'term_number' => (int) $t['term_number'],
            'name_ar' => $t['name_ar'],
            'start_week' => (int) $t['start_week'],
            'end_week' => (int) $t['end_week'],
            'start_session_no' => (int) $t['start_session_no'],
            'end_session_no' => (int) $t['end_session_no'],
            'weeks_count' => isset($t['weeks_count']) ? (int) $t['weeks_count'] : 0,
        ];
    }

    private function formatWeek(array $w): array
    {
        return [
            'id' => (int) $w['id'],
            'season_id' => (int) $w['season_id'],
            'term_id' => (int) $w['term_id'],
            'week_number_global' => (int) $w['week_number_global'],
            'week_number_in_term' => (int) $w['week_number_in_term'],
            'week_type' => $w['week_type'],
            'start_date' => $w['start_date'] ?? null,
            'end_date' => $w['end_date'] ?? null,
            'term' => !empty($w['term_id']) ? ['id' => (int) $w['term_id'], 'name_ar' => $w['term_name'] ?? ''] : null,
            'season' => !empty($w['season_id']) ? ['id' => (int) $w['season_id'], 'name' => $w['season_name'] ?? ''] : null,
        ];
    }

    private function formatSession(array $s): array
    {
        return [
            'id' => (int) $s['id'],
            'season_id' => (int) $s['season_id'],
            'term_id' => (int) $s['term_id'],
            'week_id' => (int) $s['week_id'],
            'group_id' => $s['group_id'] !== null ? (int) $s['group_id'] : null,
            'session_number_global' => (int) $s['session_number_global'],
            'session_number_in_week' => (int) $s['session_number_in_week'],
            'session_type' => $s['session_type'],
            'planned_date' => $s['planned_date'] ?? null,
            'start_time' => $s['start_time'] ? substr((string) $s['start_time'], 0, 5) : null,
            'end_time' => $s['end_time'] ? substr((string) $s['end_time'], 0, 5) : null,
            'status' => $s['status'],
            'group' => !empty($s['group_id']) ? ['id' => (int) $s['group_id'], 'name' => $s['group_name'] ?? ''] : null,
            'term' => !empty($s['term_id']) ? ['id' => (int) $s['term_id'], 'name_ar' => $s['term_name'] ?? ''] : null,
            'week' => !empty($s['week_id']) ? ['id' => (int) $s['week_id'], 'week_number_global' => (int) ($s['week_number_global'] ?? 0), 'week_type' => $s['week_type'] ?? 'study'] : null,
        ];
    }

    public function terms(Request $request, array $params): Response
    {
        $seasonId = (int) ($params['season'] ?? $params['id'] ?? 0);
        $terms = Database::fetchAll("
            SELECT t.*,
                   (SELECT COUNT(*) FROM `weeks` w WHERE w.term_id = t.id) AS weeks_count
            FROM `terms` t
            WHERE t.season_id = :sid
            ORDER BY t.term_number ASC
        ", ['sid' => $seasonId]);

        return $this->ok(array_map([$this, 'formatTerm'], $terms));
    }

    public function showTerm(Request $request, array $params): Response
    {
        $termId = (int) ($params['term'] ?? $params['id'] ?? 0);
        $term = Database::fetchOne("
            SELECT t.*,
                   (SELECT COUNT(*) FROM `weeks` w WHERE w.term_id = t.id) AS weeks_count
            FROM `terms` t
            WHERE t.id = :id
        ", ['id' => $termId]);

        if (!$term) {
            return $this->fail('Term not found.', 404);
        }

        $weeks = Database::fetchAll("
            SELECT w.*
            FROM `weeks` w
            WHERE w.term_id = :tid
            ORDER BY w.week_number_global ASC
        ", ['tid' => $termId]);

        $formattedWeeks = [];
        foreach ($weeks as $w) {
            $sessions = Database::fetchAll("
                SELECT s.*, g.name AS group_name
                FROM `sessions` s
                LEFT JOIN `groups` g ON g.id = s.group_id
                WHERE s.week_id = :wid
                ORDER BY s.session_number_global ASC
            ", ['wid' => $w['id']]);

            $fw = $this->formatWeek($w);
            $fw['sessions'] = array_map([$this, 'formatSession'], $sessions);
            $formattedWeeks[] = $fw;
        }

        $res = $this->formatTerm($term);
        $res['weeks'] = $formattedWeeks;

        return $this->ok($res);
    }

    public function updateTerm(Request $request, array $params): Response
    {
        $termId = (int) ($params['term'] ?? $params['id'] ?? 0);
        $term = Database::fetchOne("SELECT t.*, s.center_id FROM `terms` t JOIN `academic_seasons` s ON s.id = t.season_id WHERE t.id = :id", ['id' => $termId]);
        if (!$term) {
            return $this->fail('Term not found.', 404);
        }

        $me = $request->getUser();
        if ($me['role'] !== 'admin' && ($term['center_id'] === null || (int) $term['center_id'] !== (int) $me['center_id'])) {
            return $this->fail('Season belongs to another center.', 403);
        }

        $fields = ['name_ar', 'start_week', 'end_week', 'start_session_no', 'end_session_no'];
        $update = [];
        foreach ($fields as $f) {
            if ($request->input($f) !== null) {
                $update[$f] = $request->input($f);
            }
        }

        if (!empty($update)) {
            Database::update('terms', $update, 'id = :id', ['id' => $termId]);
        }

        $fresh = Database::fetchOne("
            SELECT t.*, (SELECT COUNT(*) FROM `weeks` w WHERE w.term_id = t.id) AS weeks_count
            FROM `terms` t WHERE t.id = :id
        ", ['id' => $termId]);

        return $this->ok($this->formatTerm($fresh));
    }

    public function weeks(Request $request): Response
    {
        $conditions = [];
        $params = [];

        if ($request->query('term_id')) {
            $conditions[] = "w.term_id = :tid";
            $params['tid'] = (int) $request->query('term_id');
        }

        if ($request->query('season_id')) {
            $conditions[] = "w.season_id = :sid";
            $params['sid'] = (int) $request->query('season_id');
        } elseif ($request->query('center_id')) {
            $cid = (int) $request->query('center_id');
            $seasonId = Database::fetchValue("
                SELECT id FROM `academic_seasons`
                WHERE (center_id = :cid OR center_id IS NULL) AND is_current = 1
                ORDER BY center_id IS NULL ASC LIMIT 1
            ", ['cid' => $cid]) ?: Database::fetchValue("
                SELECT s.id FROM `academic_seasons` s
                JOIN `weeks` w ON w.season_id = s.id
                WHERE (s.center_id = :cid OR s.center_id IS NULL)
                ORDER BY s.center_id IS NULL ASC, s.id DESC LIMIT 1
            ", ['cid' => $cid]);

            if ($seasonId) {
                $conditions[] = "w.season_id = :sid";
                $params['sid'] = (int) $seasonId;
            } else {
                $conditions[] = "1 = 0";
            }
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "
            SELECT w.*,
                   t.name_ar AS term_name,
                   s.name AS season_name
            FROM `weeks` w
            JOIN `terms` t ON t.id = w.term_id
            JOIN `academic_seasons` s ON s.id = w.season_id
            {$whereClause}
            ORDER BY w.week_number_global ASC
        ";

        $page = (int) $request->query('page', 1);
        $perPage = min(max((int) $request->query('per_page', 50), 1), 100);

        $paginated = Database::paginate($sql, $params, $page, $perPage);
        $paginated['data'] = array_map([$this, 'formatWeek'], $paginated['data']);

        return $this->ok($paginated);
    }

    public function updateWeek(Request $request, array $params): Response
    {
        $weekId = (int) ($params['week'] ?? $params['id'] ?? 0);
        $week = Database::fetchOne("SELECT w.*, s.center_id FROM `weeks` w JOIN `academic_seasons` s ON s.id = w.season_id WHERE w.id = :id", ['id' => $weekId]);
        if (!$week) {
            return $this->fail('Week not found.', 404);
        }

        $me = $request->getUser();
        if ($me['role'] !== 'admin' && ($week['center_id'] === null || (int) $week['center_id'] !== (int) $me['center_id'])) {
            return $this->fail('Season belongs to another center.', 403);
        }

        $fields = ['week_type', 'start_date', 'end_date'];
        $update = [];
        foreach ($fields as $f) {
            if ($request->input($f) !== null) {
                $update[$f] = $request->input($f);
            }
        }

        if (!empty($update)) {
            Database::update('weeks', $update, 'id = :id', ['id' => $weekId]);
        }

        $fresh = Database::fetchOne("
            SELECT w.*, t.name_ar AS term_name, s.name AS season_name
            FROM `weeks` w
            JOIN `terms` t ON t.id = w.term_id
            JOIN `academic_seasons` s ON s.id = w.season_id
            WHERE w.id = :id
        ", ['id' => $weekId]);

        return $this->ok($this->formatWeek($fresh));
    }

    public function sessions(Request $request): Response
    {
        $conditions = [];
        $params = [];

        foreach (['season_id', 'term_id', 'week_id', 'session_type', 'status'] as $f) {
            if ($request->query($f) !== null && $request->query($f) !== '') {
                $conditions[] = "ses.{$f} = :{$f}";
                $params[$f] = $request->query($f);
            }
        }

        if ($request->query('group_id')) {
            $gid = (int) $request->query('group_id');
            $group = Database::fetchOne("SELECT id, center_id FROM `groups` WHERE id = :id", ['id' => $gid]);
            $me = $request->getUser();
            if ($me && $me['role'] !== 'admin' && $group && (int) $group['center_id'] !== (int) $me['center_id']) {
                return $this->fail('Group belongs to another center.', 403);
            }
            $conditions[] = "ses.group_id = :gid";
            $params['gid'] = $gid;
        }

        if ($request->query('from')) {
            $conditions[] = "ses.planned_date >= :from_date";
            $params['from_date'] = $request->query('from');
        }

        if ($request->query('to')) {
            $conditions[] = "ses.planned_date <= :to_date";
            $params['to_date'] = $request->query('to');
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "
            SELECT ses.*,
                   g.name AS group_name,
                   t.name_ar AS term_name,
                   w.week_number_global, w.week_type
            FROM `sessions` ses
            LEFT JOIN `groups` g ON g.id = ses.group_id
            LEFT JOIN `terms` t ON t.id = ses.term_id
            LEFT JOIN `weeks` w ON w.id = ses.week_id
            {$whereClause}
            ORDER BY ses.session_number_global ASC
        ";

        $page = (int) $request->query('page', 1);
        $perPage = min(max((int) $request->query('per_page', 50), 1), 100);

        $paginated = Database::paginate($sql, $params, $page, $perPage);
        $paginated['data'] = array_map([$this, 'formatSession'], $paginated['data']);

        return $this->ok($paginated);
    }

    public function updateSession(Request $request, array $params): Response
    {
        $sessionId = (int) ($params['session'] ?? $params['id'] ?? 0);
        $session = Database::fetchOne("
            SELECT ses.*, s.center_id
            FROM `sessions` ses
            JOIN `academic_seasons` s ON s.id = ses.season_id
            WHERE ses.id = :id
        ", ['id' => $sessionId]);

        if (!$session) {
            return $this->fail('Session not found.', 404);
        }

        $me = $request->getUser();
        if ($me['role'] !== 'admin' && ($session['center_id'] === null || (int) $session['center_id'] !== (int) $me['center_id'])) {
            return $this->fail('Season belongs to another center.', 403);
        }

        $startTime = $request->input('start_time') ?: substr((string) ($session['start_time'] ?? ''), 0, 5);
        $endTime = $request->input('end_time') ?: substr((string) ($session['end_time'] ?? ''), 0, 5);

        if (!empty($startTime) && !empty($endTime) && $endTime <= $startTime) {
            return $this->fail('End time must be after start time.', 422, ['code' => 'SESSION_TIME_ORDER']);
        }

        $fields = ['planned_date', 'start_time', 'end_time', 'status', 'session_type'];
        $update = [];
        foreach ($fields as $f) {
            if ($request->input($f) !== null) {
                $update[$f] = $request->input($f);
            }
        }

        if (!empty($update)) {
            Database::update('sessions', $update, 'id = :id', ['id' => $sessionId]);
        }

        $fresh = Database::fetchOne("
            SELECT ses.*, g.name AS group_name, t.name_ar AS term_name, w.week_number_global, w.week_type
            FROM `sessions` ses
            LEFT JOIN `groups` g ON g.id = ses.group_id
            LEFT JOIN `terms` t ON t.id = ses.term_id
            LEFT JOIN `weeks` w ON w.id = ses.week_id
            WHERE ses.id = :id
        ", ['id' => $sessionId]);

        return $this->ok($this->formatSession($fresh));
    }

    public function feed(Request $request): Response
    {
        $v = Validator::make($request->all(), [
            'season_id' => 'required|integer',
            'from' => 'required|date',
            'to' => 'required|date',
        ]);
        if ($v->fails()) {
            return $this->fail('The given data was invalid.', 422, $v->errors());
        }

        $seasonId = (int) $request->input('season_id');
        $from = (string) $request->input('from');
        $to = (string) $request->input('to');

        $me = $request->getUser();
        $role = $me['role'] ?? 'teacher';

        $conditions = [
            "ses.season_id = :sid",
            "ses.planned_date >= :from_date",
            "ses.planned_date <= :to_date",
        ];
        $params = [
            'sid' => $seasonId,
            'from_date' => $from,
            'to_date' => $to,
        ];

        if ($role === 'student') {
            $conditions[] = "ses.group_id IN (SELECT group_id FROM `students` WHERE user_id = :uid AND group_id IS NOT NULL)";
            $params['uid'] = (int) $me['id'];
        } elseif ($role !== 'admin' && $role !== 'board') {
            $conditions[] = "(ses.group_id IS NULL OR g.center_id = :cid)";
            $params['cid'] = (int) $me['center_id'];
        }

        $whereClause = 'WHERE ' . implode(' AND ', $conditions);

        $sql = "
            SELECT ses.*,
                   g.name AS group_name,
                   t.name_ar AS term_name,
                   w.week_number_global, w.week_type
            FROM `sessions` ses
            LEFT JOIN `groups` g ON g.id = ses.group_id
            LEFT JOIN `terms` t ON t.id = ses.term_id
            LEFT JOIN `weeks` w ON w.id = ses.week_id
            {$whereClause}
            ORDER BY ses.session_number_global ASC
        ";

        $page = (int) $request->query('page', 1);
        $perPage = min(max((int) $request->query('per_page', 50), 1), 100);

        $paginated = Database::paginate($sql, $params, $page, $perPage);
        $paginated['data'] = array_map([$this, 'formatSession'], $paginated['data']);

        return $this->ok($paginated);
    }
}
