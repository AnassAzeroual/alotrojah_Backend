<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class ReferenceController extends BaseController
{
    public function levels(Request $request): Response
    {
        $season = null;
        if ($request->query('season_id')) {
            $season = Database::fetchOne("SELECT id, center_id FROM `academic_seasons` WHERE id = :id", ['id' => (int) $request->query('season_id')]);
        } elseif ($request->query('center_id')) {
            $cid = (int) $request->query('center_id');
            $season = Database::fetchOne("SELECT id, center_id FROM `academic_seasons` WHERE center_id = :cid AND is_current = 1 LIMIT 1", ['cid' => $cid])
                ?: Database::fetchOne("SELECT id, center_id FROM `academic_seasons` WHERE center_id = :cid ORDER BY id DESC LIMIT 1", ['cid' => $cid])
                ?: Database::fetchOne("SELECT id, center_id FROM `academic_seasons` WHERE is_current = 1 LIMIT 1");
        }

        if (!$season) {
            return $this->ok([]);
        }

        $seasonId = (int) $season['id'];
        $terms = Database::fetchAll("
            SELECT t.id, t.term_number, t.name_ar,
                   (SELECT COUNT(*) FROM `weeks` w WHERE w.term_id = t.id AND w.week_type = 'study') AS study_weeks,
                   (SELECT COUNT(*) FROM `weeks` w WHERE w.term_id = t.id AND w.week_type = 'review') AS review_weeks
            FROM `terms` t
            WHERE t.season_id = :sid
            ORDER BY t.term_number ASC
        ", ['sid' => $seasonId]);

        $levels = Database::fetchAll("SELECT * FROM `levels` WHERE season_id = :sid ORDER BY id ASC", ['sid' => $seasonId]);

        $out = [];
        foreach ($levels as $l) {
            $goals = [];
            $spw = (int) $l['sessions_per_week'];
            $tps = (float) $l['thumn_per_session_value'];
            foreach ($terms as $t) {
                $sw = (int) $t['study_weeks'];
                $rw = (int) $t['review_weeks'];
                $goals[] = [
                    'term_id' => (int) $t['id'],
                    'term_number' => (int) $t['term_number'],
                    'name_ar' => $t['name_ar'],
                    'study_weeks' => $sw,
                    'review_weeks' => $rw,
                    'ahzab' => round($sw * $spw * $tps / 8, 2),
                ];
            }

            $out[] = [
                'id' => (int) $l['id'],
                'season_id' => $l['season_id'] !== null ? (int) $l['season_id'] : null,
                'center_id' => $l['center_id'] !== null ? (int) $l['center_id'] : null,
                'code' => (string) $l['code'],
                'name_ar' => (string) $l['name_ar'],
                'sessions_per_week' => $spw,
                'thumn_per_session_label' => (string) $l['thumn_per_session_label'],
                'thumn_per_session_value' => $tps,
                'thumn_per_week_value' => (float) $l['thumn_per_week_value'],
                'ahzab_per_term' => (float) $l['ahzab_per_term'],
                'ahzab_per_dawra' => (float) $l['ahzab_per_dawra'],
                'duration_label' => (string) $l['duration_label'],
                'total_ahzab' => (int) $l['total_ahzab'],
                'ahzab_first_term' => !empty($goals) ? $goals[0]['ahzab'] : null,
                'term_goals' => $goals,
            ];
        }

        return $this->ok($out);
    }

    public function surahs(Request $request): Response
    {
        $q = $request->query('q');
        $params = [];
        $where = '';
        if ($q !== null && $q !== '') {
            $where = "WHERE surah_name LIKE :q";
            $params['q'] = "%{$q}%";
        }

        $sql = "
            SELECT sura_no AS id,
                   MIN(surah_name) AS name_ar,
                   COUNT(*) AS ayahs_count
            FROM `quran_verses`
            {$where}
            GROUP BY sura_no
            ORDER BY sura_no ASC
        ";

        $rows = Database::fetchAll($sql, $params);
        $out = array_map(function ($r) {
            return [
                'id' => (int) $r['id'],
                'name_ar' => (string) $r['name_ar'],
                'ayahs_count' => (int) $r['ayahs_count'],
            ];
        }, $rows);

        return $this->ok($out);
    }
}
