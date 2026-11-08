<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Canonical Murajaa calculations:
 *   term = average of scored murajaa sessions in the term
 *   season = average of scored term averages (Option B, equal term weight)
 *
 * Notes, cancelled sessions and missing scores never enter the denominator.
 */
final class MurajaaSummary
{
    public static function recomputeStudentSeason(int $studentId, int $seasonId): void
    {
        $terms = Database::fetchAll(
            'SELECT id FROM `terms` WHERE season_id = :sid ORDER BY term_number',
            ['sid' => $seasonId],
        );
        $termAverages = [];
        foreach ($terms as $term) {
            $average = self::recomputeTerm($studentId, $seasonId, (int) $term['id']);
            if ($average !== null) {
                $termAverages[] = $average;
            }
        }

        $seasonAverage = $termAverages === []
            ? null
            : round(array_sum($termAverages) / count($termAverages), 2);

        $existing = Database::fetchOne(
            'SELECT id FROM `season_results` WHERE student_id = :sid AND season_id = :season LIMIT 1',
            ['sid' => $studentId, 'season' => $seasonId],
        );
        if ($existing) {
            Database::update(
                'season_results',
                ['murajaa_total' => $seasonAverage],
                'student_id = :sid AND season_id = :season',
                ['sid' => $studentId, 'season' => $seasonId],
            );
        } elseif ($seasonAverage !== null) {
            Database::insert('season_results', [
                'student_id' => $studentId,
                'season_id' => $seasonId,
                'murajaa_total' => $seasonAverage,
                'honor_flag' => 'none',
            ]);
        }
    }

    public static function recomputeTerm(int $studentId, int $seasonId, int $termId): ?float
    {
        $row = Database::fetchOne(
            "SELECT COUNT(*) AS n, ROUND(AVG(sc.score), 2) AS avg
             FROM `session_scores` sc
             JOIN `scoring_modules` m ON m.id = sc.module_id
             JOIN `sessions` ses ON ses.id = sc.session_id
             WHERE sc.student_id = :sid AND sc.season_id = :season AND sc.term_id = :term
               AND m.id = (SELECT MIN(id) FROM `scoring_modules` WHERE code = 'murajaa')
               AND ses.status <> 'cancelled'",
            ['sid' => $studentId, 'season' => $seasonId, 'term' => $termId],
        );
        $count = (int) ($row['n'] ?? 0);
        $average = $count > 0 && $row['avg'] !== null ? (float) $row['avg'] : null;

        $existing = Database::fetchOne(
            'SELECT id FROM `term_results` WHERE student_id = :sid AND term_id = :term LIMIT 1',
            ['sid' => $studentId, 'term' => $termId],
        );
        if ($existing) {
            Database::update(
                'term_results',
                ['murajaa_total' => $average],
                'student_id = :sid AND term_id = :term',
                ['sid' => $studentId, 'term' => $termId],
            );
        } elseif ($average !== null) {
            Database::insert('term_results', [
                'student_id' => $studentId,
                'season_id' => $seasonId,
                'term_id' => $termId,
                'murajaa_total' => $average,
                'honor_flag' => 'none',
            ]);
        }

        return $average;
    }
}
