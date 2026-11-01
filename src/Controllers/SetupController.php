<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

class SetupController extends BaseController
{
    /**
     * GET /v1/setup/status
     * Admin setup checklist progress.
     */
    public function status(Request $request): Response
    {
        $user = $request->user();
        if ($user === null || $user['role'] !== 'admin') {
            return $this->forbidden('Admin only.');
        }

        $db = Database::getInstance();
        $centers = $db->query('SELECT id, name FROM centers ORDER BY id ASC')->fetchAll();

        $selectedCenter = null;
        foreach ($centers as $candidate) {
            if (!$this->isComplete((int) $candidate['id'])) {
                $selectedCenter = $candidate;
                break;
            }
        }
        $selectedCenter ??= ($centers[0] ?? null);

        $season = null;
        $levelsCount = 0;
        $teachersCount = 0;
        $groupsCount = 0;
        $pupilsCount = 0;

        if ($selectedCenter !== null) {
            $cid = (int) $selectedCenter['id'];
            $season = $db->query(
                'SELECT id, name, is_current FROM academic_seasons
                 WHERE center_id = :cid
                 ORDER BY is_current DESC, id DESC LIMIT 1',
                ['cid' => $cid]
            )->fetch();

            if ($season) {
                $levelsCount = (int) $db->query(
                    'SELECT COUNT(*) FROM levels WHERE season_id = :sid',
                    ['sid' => (int) $season['id']]
                )->fetchColumn();
            }

            $teachersCount = (int) $db->query(
                "SELECT COUNT(*) FROM users WHERE role = 'teacher' AND center_id = :cid",
                ['cid' => $cid]
            )->fetchColumn();

            $groupsCount = (int) $db->query(
                'SELECT COUNT(*) FROM `groups` WHERE center_id = :cid',
                ['cid' => $cid]
            )->fetchColumn();

            $pupilsCount = (int) $db->query(
                'SELECT COUNT(*) FROM students WHERE center_id = :cid',
                ['cid' => $cid]
            )->fetchColumn();
        }

        $steps = [
            ['key' => 'centers', 'done' => !empty($centers), 'count' => count($centers), 'target' => '/centers'],
            ['key' => 'season', 'done' => $season !== null, 'count' => $season !== null ? 1 : 0, 'target' => '/planning/new'],
            ['key' => 'levels', 'done' => $levelsCount > 0, 'count' => $levelsCount, 'target' => '/levels'],
            ['key' => 'teachers', 'done' => $teachersCount > 0, 'count' => $teachersCount, 'target' => '/users'],
            ['key' => 'groups', 'done' => $groupsCount > 0, 'count' => $groupsCount, 'target' => '/groups/new'],
            ['key' => 'pupils', 'done' => $pupilsCount > 0, 'count' => $pupilsCount, 'target' => '/students/new'],
        ];

        $current = null;
        $doneSteps = 0;
        foreach ($steps as $step) {
            if ($step['done']) {
                $doneSteps++;
            } elseif ($current === null) {
                $current = $step['key'];
            }
        }

        return $this->ok([
            'center' => $selectedCenter ? ['id' => (int) $selectedCenter['id'], 'name' => (string) $selectedCenter['name']] : null,
            'season' => $season ? [
                'id' => (int) $season['id'],
                'name' => (string) $season['name'],
                'is_current' => (bool) $season['is_current'],
            ] : null,
            'total_steps' => count($steps),
            'done_steps' => $doneSteps,
            'current' => $current,
            'steps' => $steps,
        ]);
    }

    private function isComplete(int $centerId): bool
    {
        $db = Database::getInstance();
        $season = $db->query(
            'SELECT id FROM academic_seasons WHERE center_id = :cid ORDER BY is_current DESC, id DESC LIMIT 1',
            ['cid' => $centerId]
        )->fetch();

        if (!$season) {
            return false;
        }

        $levels = $db->query(
            'SELECT COUNT(*) FROM levels WHERE season_id = :sid',
            ['sid' => (int) $season['id']]
        )->fetchColumn();
        if ((int) $levels === 0) {
            return false;
        }

        $teachers = $db->query(
            "SELECT COUNT(*) FROM users WHERE role = 'teacher' AND center_id = :cid",
            ['cid' => $centerId]
        )->fetchColumn();
        if ((int) $teachers === 0) {
            return false;
        }

        $groups = $db->query(
            'SELECT COUNT(*) FROM `groups` WHERE center_id = :cid',
            ['cid' => $centerId]
        )->fetchColumn();
        if ((int) $groups === 0) {
            return false;
        }

        $students = $db->query(
            'SELECT COUNT(*) FROM students WHERE center_id = :cid',
            ['cid' => $centerId]
        )->fetchColumn();

        return (int) $students > 0;
    }
}
