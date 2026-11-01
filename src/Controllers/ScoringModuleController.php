<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

final class ScoringModuleController extends BaseController
{
    private function formatModule(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'code' => $row['code'],
            'name_ar' => $row['name_ar'],
            'max_points' => (float) $row['max_points'],
            'scope' => $row['scope'],
            'is_active' => (bool) $row['is_active'],
            'is_in_weekly_total' => (bool) $row['is_in_weekly_total'],
            'sort_order' => (int) $row['sort_order'],
            'center_id' => isset($row['center_id']) && $row['center_id'] !== null ? (int) $row['center_id'] : null,
        ];
    }

    private function checkWeeklyTotal(?int $centerId): array
    {
        $modules = Database::fetchAll("
            SELECT * FROM `scoring_modules`
            WHERE is_active = 1 AND scope = 'weekly' AND is_in_weekly_total = 1
              AND (center_id = :cid OR center_id IS NULL)
            ORDER BY center_id IS NULL ASC
        ", ['cid' => $centerId]);

        // Override set wins per code
        $byCode = [];
        foreach ($modules as $m) {
            $byCode[$m['code']] = (float) $m['max_points'];
        }

        $total = array_sum($byCode);
        return [
            'valid' => abs($total - 20.0) < 0.01,
            'total' => round($total, 1),
        ];
    }

    public function index(Request $request): Response
    {
        $centerId = $request->query('center_id') ? (int) $request->query('center_id') : null;

        $modules = Database::fetchAll("
            SELECT * FROM `scoring_modules`
            WHERE center_id = :cid OR center_id IS NULL
            ORDER BY center_id IS NULL ASC, sort_order ASC, id ASC
        ", ['cid' => $centerId]);

        // Key by code so center override replaces null-center default
        $effective = [];
        foreach ($modules as $m) {
            $effective[$m['code']] = $this->formatModule($m);
        }

        return $this->ok(array_values($effective));
    }

    public function store(Request $request): Response
    {
        $this->authorizeRole($request, ['admin']);

        $v = Validator::make($request->all(), [
            'code' => 'required|string',
            'name_ar' => 'required|string',
            'max_points' => 'required|numeric',
            'scope' => 'required|in:weekly,murajaa',
            'is_active' => 'nullable|boolean',
            'is_in_weekly_total' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
            'center_id' => 'nullable|integer',
        ]);
        if ($v->fails()) {
            return $this->fail('The given data was invalid.', 422, $v->errors());
        }

        $centerId = $request->input('center_id') ? (int) $request->input('center_id') : null;

        $createdId = Database::transaction(function () use ($request, $centerId) {
            $id = Database::insert('scoring_modules', [
                'code' => trim((string) $request->input('code')),
                'name_ar' => trim((string) $request->input('name_ar')),
                'max_points' => (float) $request->input('max_points'),
                'scope' => $request->input('scope'),
                'is_active' => $request->input('is_active') !== null ? (int) filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN) : 1,
                'is_in_weekly_total' => $request->input('is_in_weekly_total') !== null ? (int) filter_var($request->input('is_in_weekly_total'), FILTER_VALIDATE_BOOLEAN) : 1,
                'sort_order' => $request->input('sort_order') ? (int) $request->input('sort_order') : 0,
                'center_id' => $centerId,
            ]);

            $check = $this->checkWeeklyTotal($centerId);
            if (!$check['valid']) {
                Database::rollBack();
                throw new \RuntimeException("Weekly total would be {$check['total']}, must stay 20.");
            }

            return $id;
        });

        $fresh = Database::fetchOne("SELECT * FROM `scoring_modules` WHERE id = :id", ['id' => $createdId]);
        return $this->created($this->formatModule($fresh));
    }

    public function update(Request $request, array $params): Response
    {
        $this->authorizeRole($request, ['admin']);
        $id = (int) ($params['id'] ?? $params['scoringModule'] ?? 0);

        $module = Database::fetchOne("SELECT * FROM `scoring_modules` WHERE id = :id", ['id' => $id]);
        if (!$module) {
            return $this->fail('Scoring module not found.', 404);
        }

        $centerId = $module['center_id'] !== null ? (int) $module['center_id'] : null;

        $fields = ['name_ar', 'max_points', 'scope', 'is_active', 'is_in_weekly_total', 'sort_order'];
        $update = [];
        foreach ($fields as $f) {
            if ($request->input($f) !== null) {
                if (in_array($f, ['is_active', 'is_in_weekly_total'], true)) {
                    $update[$f] = (int) filter_var($request->input($f), FILTER_VALIDATE_BOOLEAN);
                } else {
                    $update[$f] = $request->input($f);
                }
            }
        }

        Database::transaction(function () use ($id, $update, $centerId) {
            if (!empty($update)) {
                Database::update('scoring_modules', $update, 'id = :id', ['id' => $id]);
            }
            $check = $this->checkWeeklyTotal($centerId);
            if (!$check['valid']) {
                Database::rollBack();
                throw new \RuntimeException("Weekly total would be {$check['total']}, must stay 20.");
            }
        });

        $fresh = Database::fetchOne("SELECT * FROM `scoring_modules` WHERE id = :id", ['id' => $id]);
        return $this->ok($this->formatModule($fresh));
    }

    public function bulk(Request $request): Response
    {
        $this->authorizeRole($request, ['admin', 'supervisor']);
        $centerId = $request->input('center_id') ? (int) $request->input('center_id') : null;
        $modules = (array) $request->input('modules');

        if (empty($modules)) {
            return $this->fail('Modules array is required.', 422);
        }

        Database::transaction(function () use ($modules, $centerId) {
            foreach ($modules as $row) {
                if (empty($row['code'])) continue;
                $code = $row['code'];

                $update = [];
                foreach (['max_points', 'sort_order'] as $f) {
                    if (isset($row[$f])) $update[$f] = $row[$f];
                }
                foreach (['is_active', 'is_in_weekly_total'] as $f) {
                    if (isset($row[$f])) $update[$f] = (int) filter_var($row[$f], FILTER_VALIDATE_BOOLEAN);
                }

                if (!empty($update)) {
                    if ($centerId !== null) {
                        $exists = Database::fetchOne("SELECT id FROM `scoring_modules` WHERE code = :code AND center_id = :cid", [
                            'code' => $code, 'cid' => $centerId,
                        ]);
                        if ($exists) {
                            Database::update('scoring_modules', $update, 'id = :id', ['id' => $exists['id']]);
                        } else {
                            $orig = Database::fetchOne("SELECT * FROM `scoring_modules` WHERE code = :code AND center_id IS NULL", ['code' => $code]);
                            if ($orig) {
                                Database::insert('scoring_modules', array_merge($orig, $update, [
                                    'id' => null,
                                    'center_id' => $centerId,
                                ]));
                            }
                        }
                    } else {
                        Database::update('scoring_modules', $update, 'code = :code AND center_id IS NULL', ['code' => $code]);
                    }
                }
            }

            $check = $this->checkWeeklyTotal($centerId);
            if (!$check['valid']) {
                Database::rollBack();
                throw new \RuntimeException("Weekly total would be {$check['total']}, must stay 20.");
            }
        });

        $check = $this->checkWeeklyTotal($centerId);
        $freshModules = Database::fetchAll("
            SELECT * FROM `scoring_modules`
            WHERE center_id = :cid OR center_id IS NULL
            ORDER BY center_id IS NULL ASC, sort_order ASC
        ", ['cid' => $centerId]);

        $effective = [];
        foreach ($freshModules as $m) {
            $effective[$m['code']] = $this->formatModule($m);
        }

        return $this->ok([
            'modules' => array_values($effective),
            'check' => $check,
        ], 'Scoring updated.');
    }

    public function scoringCheck(Request $request): Response
    {
        $centerId = $request->query('center_id') ? (int) $request->query('center_id') : null;
        return $this->ok($this->checkWeeklyTotal($centerId));
    }

    public function reset(Request $request): Response
    {
        $this->authorizeRole($request, ['admin']);
        $centerId = (int) $request->input('center_id');

        $ids = Database::fetchAll("SELECT id FROM `scoring_modules` WHERE center_id = :cid", ['cid' => $centerId]);
        if (empty($ids)) {
            return $this->ok(['deleted' => 0]);
        }

        $idList = implode(',', array_column($ids, 'id'));
        $hasScores = Database::fetchValue("SELECT 1 FROM `session_scores` WHERE module_id IN ({$idList}) LIMIT 1");
        if ($hasScores) {
            return $this->fail('Module has recorded scores and cannot be deleted.', 422, ['code' => 'MODULE_HAS_SCORES']);
        }

        $deleted = Database::delete('scoring_modules', 'center_id = :cid', ['cid' => $centerId]);
        return $this->ok(['deleted' => $deleted]);
    }

    public function destroy(Request $request, array $params): Response
    {
        $this->authorizeRole($request, ['admin']);
        $id = (int) ($params['id'] ?? $params['scoringModule'] ?? 0);

        $hasScores = Database::fetchValue("SELECT 1 FROM `session_scores` WHERE module_id = :id LIMIT 1", ['id' => $id]);
        if ($hasScores) {
            return $this->fail('Module has recorded scores and cannot be deleted.', 422, ['code' => 'MODULE_HAS_SCORES']);
        }

        Database::delete('scoring_modules', 'id = :id', ['id' => $id]);
        return $this->ok(null, 'Deleted.');
    }
}
