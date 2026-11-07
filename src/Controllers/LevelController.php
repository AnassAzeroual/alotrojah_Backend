<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

final class LevelController extends BaseController
{
    private function computeTermGoals(array $level, array $terms): array
    {
        $goals = [];
        $sessionsPerWeek = (int) $level['sessions_per_week'];
        $thumnPerSession = (float) $level['thumn_per_session_value'];

        foreach ($terms as $term) {
            $studyWeeks = (int) $term['study_weeks'];
            $reviewWeeks = (int) $term['review_weeks'];
            $ahzab = round($studyWeeks * $sessionsPerWeek * $thumnPerSession / 8, 2);

            $goals[] = [
                'term_id' => (int) $term['id'],
                'term_number' => (int) $term['term_number'],
                'name_ar' => $term['name_ar'],
                'study_weeks' => $studyWeeks,
                'review_weeks' => $reviewWeeks,
                'ahzab' => $ahzab,
            ];
        }
        return $goals;
    }

    private function formatLevel(array $level, array $terms): array
    {
        $goals = $this->computeTermGoals($level, $terms);
        $firstTermAhzab = !empty($goals) ? $goals[0]['ahzab'] : null;

        return [
            'id' => (int) $level['id'],
            'season_id' => $level['season_id'] !== null ? (int) $level['season_id'] : null,
            'center_id' => $level['center_id'] !== null ? (int) $level['center_id'] : null,
            'code' => (string) $level['code'],
            'name_ar' => (string) $level['name_ar'],
            'sessions_per_week' => (int) $level['sessions_per_week'],
            'thumn_per_session_label' => (string) $level['thumn_per_session_label'],
            'thumn_per_session_value' => (float) $level['thumn_per_session_value'],
            'thumn_per_week_value' => (float) $level['thumn_per_week_value'],
            'ahzab_per_term' => (float) $level['ahzab_per_term'],
            'ahzab_per_dawra' => (float) $level['ahzab_per_dawra'],
            'duration_label' => (string) $level['duration_label'],
            'total_ahzab' => (int) $level['total_ahzab'],
            'ahzab_first_term' => $firstTermAhzab,
            'term_goals' => $goals,
        ];
    }

    private function getSeasonTerms(?int $seasonId): array
    {
        if (!$seasonId) {
            return [];
        }

        return Database::fetchAll("
            SELECT t.id, t.term_number, t.name_ar,
                   (SELECT COUNT(*) FROM `weeks` w WHERE w.term_id = t.id AND w.week_type = 'study') AS study_weeks,
                   (SELECT COUNT(*) FROM `weeks` w WHERE w.term_id = t.id AND w.week_type = 'review') AS review_weeks
            FROM `terms` t
            WHERE t.season_id = :sid
            ORDER BY t.term_number ASC
        ", ['sid' => $seasonId]);
    }

    public function index(Request $request): Response
    {
        $seasonId = $request->query('season_id');
        $centerId = $request->query('center_id');

        if (!empty($seasonId)) {
            $season = Database::fetchOne("SELECT id, center_id FROM `academic_seasons` WHERE id = :id", ['id' => (int) $seasonId]);
        } elseif (!empty($centerId)) {
            $season = Database::fetchOne("SELECT id, center_id FROM `academic_seasons` WHERE center_id = :cid AND is_current = 1 LIMIT 1", ['cid' => (int) $centerId])
                ?: Database::fetchOne("SELECT id, center_id FROM `academic_seasons` WHERE center_id = :cid ORDER BY id DESC LIMIT 1", ['cid' => (int) $centerId])
                ?: Database::fetchOne("SELECT id, center_id FROM `academic_seasons` WHERE is_current = 1 LIMIT 1");
        } else {
            return $this->ok([]);
        }

        if (!$season) {
            return $this->ok([]);
        }

        $sid = (int) $season['id'];
        $terms = $this->getSeasonTerms($sid);

        $levels = Database::fetchAll("SELECT * FROM `levels` WHERE season_id = :sid ORDER BY id ASC", ['sid' => $sid]);
        $formatted = array_map(fn($lvl) => $this->formatLevel($lvl, $terms), $levels);

        return $this->ok($formatted);
    }

    public function store(Request $request): Response
    {
        $this->authorizeRole($request, ['admin']);

        $v = Validator::make($request->all(), [
            'season_id' => 'required|integer',
            'name_ar' => 'required|string',
            'sessions_per_week' => 'required|integer|min:1|max:7',
            'thumn_per_session_label' => 'required|string',
            'thumn_per_session_value' => 'required|numeric|min:1|max:480',
            'thumn_per_week_value' => 'required|numeric',
            'ahzab_per_term' => 'required|numeric',
            'ahzab_per_dawra' => 'required|numeric',
            'duration_label' => 'required|string',
            'total_ahzab' => 'required|integer',
        ]);
        if ($v->fails()) {
            return $this->fail('The given data was invalid.', 422, $v->errors());
        }

        $paceError = $this->quranPaceError(
            (int) $request->input('sessions_per_week'),
            (float) $request->input('thumn_per_session_value'),
            (float) $request->input('thumn_per_week_value'),
            (float) $request->input('ahzab_per_dawra'),
        );
        if ($paceError !== null) {
            return $paceError;
        }

        $seasonId = (int) $request->input('season_id');
        $season = Database::fetchOne("SELECT id, center_id FROM `academic_seasons` WHERE id = :id", ['id' => $seasonId]);
        if (!$season) {
            return $this->fail('Season not found.', 404);
        }

        $twin = Database::fetchValue("
            SELECT 1 FROM `levels`
            WHERE season_id = :sid
              AND sessions_per_week = :spw
              AND thumn_per_session_value = :tps
              AND thumn_per_week_value = :tpw
              AND ahzab_per_term = :apt
              AND ahzab_per_dawra = :apd
              AND total_ahzab = :ta
            LIMIT 1
        ", [
            'sid' => $seasonId,
            'spw' => (int) $request->input('sessions_per_week'),
            'tps' => (float) $request->input('thumn_per_session_value'),
            'tpw' => (float) $request->input('thumn_per_week_value'),
            'apt' => (float) $request->input('ahzab_per_term'),
            'apd' => (float) $request->input('ahzab_per_dawra'),
            'ta' => (int) $request->input('total_ahzab'),
        ]);

        if ($twin) {
            return $this->fail('A level with identical values already exists.', 422, ['code' => 'LEVEL_EXISTS']);
        }

        $created = Database::transaction(function () use ($request, $season, $seasonId) {
            $newId = Database::insert('levels', [
                'season_id' => $seasonId,
                'center_id' => $season['center_id'],
                'code' => 'L0',
                'name_ar' => trim((string) $request->input('name_ar')),
                'sessions_per_week' => (int) $request->input('sessions_per_week'),
                'thumn_per_session_label' => trim((string) $request->input('thumn_per_session_label')),
                'thumn_per_session_value' => (float) $request->input('thumn_per_session_value'),
                'thumn_per_week_value' => (float) $request->input('thumn_per_week_value'),
                'ahzab_per_term' => (float) $request->input('ahzab_per_term'),
                'ahzab_per_dawra' => (float) $request->input('ahzab_per_dawra'),
                'duration_label' => trim((string) $request->input('duration_label')),
                'total_ahzab' => (int) $request->input('total_ahzab'),
            ]);

            Database::update('levels', ['code' => "L{$newId}"], 'id = :id', ['id' => $newId]);
            return Database::fetchOne("SELECT * FROM `levels` WHERE id = :id", ['id' => $newId]);
        });

        $terms = $this->getSeasonTerms($seasonId);
        return $this->created($this->formatLevel($created, $terms));
    }

    public function update(Request $request, array $params): Response
    {
        $this->authorizeRole($request, ['admin']);
        $id = (int) ($params['id'] ?? $params['level'] ?? 0);

        $level = Database::fetchOne("SELECT * FROM `levels` WHERE id = :id", ['id' => $id]);
        if (!$level) {
            return $this->fail('Level not found.', 404);
        }

        $v = Validator::make($request->all(), [
            'sessions_per_week' => 'integer|min:1|max:7',
            'thumn_per_session_value' => 'numeric|min:1|max:480',
        ]);
        if ($v->fails()) {
            return $this->unprocessable($v->errors());
        }

        $paceError = $this->quranPaceError(
            (int) ($request->input('sessions_per_week') ?? $level['sessions_per_week']),
            (float) ($request->input('thumn_per_session_value') ?? $level['thumn_per_session_value']),
            (float) ($request->input('thumn_per_week_value') ?? $level['thumn_per_week_value']),
            (float) ($request->input('ahzab_per_dawra') ?? $level['ahzab_per_dawra']),
        );
        if ($paceError !== null) {
            return $paceError;
        }

        $fields = [
            'name_ar', 'sessions_per_week', 'thumn_per_session_label',
            'thumn_per_session_value', 'thumn_per_week_value', 'ahzab_per_term',
            'ahzab_per_dawra', 'duration_label', 'total_ahzab'
        ];

        $update = [];
        foreach ($fields as $f) {
            if ($request->input($f) !== null) {
                $update[$f] = $request->input($f);
            }
        }

        if (!empty($update)) {
            Database::update('levels', $update, 'id = :id', ['id' => $id]);
        }

        $fresh = Database::fetchOne("SELECT * FROM `levels` WHERE id = :id", ['id' => $id]);
        $terms = $this->getSeasonTerms($fresh['season_id'] ? (int) $fresh['season_id'] : null);

        return $this->ok($this->formatLevel($fresh, $terms));
    }

    public function destroy(Request $request, array $params): Response
    {
        $this->authorizeRole($request, ['admin']);
        $id = (int) ($params['id'] ?? $params['level'] ?? 0);

        $level = Database::fetchOne("SELECT * FROM `levels` WHERE id = :id", ['id' => $id]);
        if (!$level) {
            return $this->fail('Level not found.', 404);
        }

        $inUse = Database::fetchValue("SELECT 1 FROM `students` WHERE level_id = :id LIMIT 1", ['id' => $id])
            || Database::fetchValue("SELECT 1 FROM `student_levels` WHERE level_id = :id LIMIT 1", ['id' => $id]);

        if ($inUse) {
            return $this->fail('Level is used by pupils and cannot be deleted.', 422, ['code' => 'LEVEL_IN_USE']);
        }

        Database::delete('levels', 'id = :id', ['id' => $id]);
        return $this->ok(null, 'Deleted.');
    }

    public function reset(Request $request): Response
    {
        $this->authorizeRole($request, ['admin']);
        $seasonId = (int) $request->input('season_id');

        $season = Database::fetchOne("SELECT * FROM `academic_seasons` WHERE id = :id", ['id' => $seasonId]);
        if (!$season) {
            return $this->fail('Season not found.', 404);
        }

        $levels = Database::fetchAll("SELECT id FROM `levels` WHERE season_id = :sid", ['sid' => $seasonId]);
        $ids = array_column($levels, 'id');

        if (!empty($ids)) {
            $inList = implode(',', $ids);
            $inUse = Database::fetchValue("SELECT 1 FROM `students` WHERE level_id IN ({$inList}) LIMIT 1")
                || Database::fetchValue("SELECT 1 FROM `student_levels` WHERE level_id IN ({$inList}) LIMIT 1");
            if ($inUse) {
                return $this->fail('Level is used by pupils and cannot be deleted.', 422, ['code' => 'LEVEL_IN_USE']);
            }
        }

        // Find previous season of same center lane
        $prevSeasonId = Database::fetchValue("
            SELECT season_id FROM `levels`
            WHERE center_id = :cid AND season_id < :sid
            ORDER BY season_id DESC LIMIT 1
        ", ['cid' => $season['center_id'], 'sid' => $seasonId]);

        if (!$prevSeasonId) {
            return $this->fail('No previous season to copy levels from.', 422, ['code' => 'LEVEL_SOURCE_MISSING']);
        }

        $sourceLevels = Database::fetchAll("SELECT * FROM `levels` WHERE season_id = :sid ORDER BY id ASC", ['sid' => $prevSeasonId]);

        return Database::transaction(function () use ($ids, $seasonId, $season, $sourceLevels) {
            $deletedCount = count($ids);
            if ($deletedCount > 0) {
                Database::delete('levels', 'season_id = :sid', ['sid' => $seasonId]);
            }

            $createdCount = 0;
            foreach ($sourceLevels as $row) {
                Database::insert('levels', [
                    'season_id' => $seasonId,
                    'center_id' => $season['center_id'],
                    'code' => $row['code'],
                    'name_ar' => $row['name_ar'],
                    'sessions_per_week' => $row['sessions_per_week'],
                    'thumn_per_session_label' => $row['thumn_per_session_label'],
                    'thumn_per_session_value' => $row['thumn_per_session_value'],
                    'thumn_per_week_value' => $row['thumn_per_week_value'],
                    'ahzab_per_term' => $row['ahzab_per_term'],
                    'ahzab_per_dawra' => $row['ahzab_per_dawra'],
                    'duration_label' => $row['duration_label'],
                    'total_ahzab' => $row['total_ahzab'],
                ]);
                $createdCount++;
            }

            return $this->ok(['deleted' => $deletedCount, 'created' => $createdCount]);
        });
    }

    /**
     * Quran-aware pace guard: a level is one 60-hizb cycle (480 thumn).
     * Rejects inconsistent/tampered derived values as well as impossible pace.
     */
    private function quranPaceError(int $sessions, float $unit, float $week, float $dawra): ?Response
    {
        $expectedWeek = round($sessions * $unit, 2);
        if (abs($week - $expectedWeek) > 0.01) {
            return $this->unprocessable([
                'thumn_per_week_value' => ['Weekly thumn must equal sessions × thumn per session.'],
            ]);
        }

        if ($week > 480.01) {
            return $this->unprocessable([
                'thumn_per_week_value' => ['Weekly thumn cannot exceed 480 (the whole Quran).'],
            ]);
        }

        if ($dawra > 60.01) {
            return $this->unprocessable([
                'ahzab_per_dawra' => ['A level cycle cannot exceed the Quran\'s 60 ahzab.'],
            ]);
        }

        return null;
    }
}
