<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Creates the weekly session skeleton for a group. Existing demo/imported
 * sessions are left untouched; generation is idempotent per (group, date).
 */
final class SessionGenerator
{
    private const DEFAULT_DAYS = ['Mon', 'Wed', 'Fri'];
    private const START_TIME = '16:00:00';
    private const END_TIME = '17:30:00';

    public static function forGroup(int $groupId, int $seasonId): int
    {
        $group = Database::fetchOne(
            'SELECT id, center_id, schedule_days FROM `groups` WHERE id = :id',
            ['id' => $groupId],
        );
        if (! $group) {
            return 0;
        }

        $weeks = Database::fetchAll(
            'SELECT id, term_id, week_number_global, week_type, start_date, end_date
             FROM `weeks` WHERE season_id = :sid ORDER BY week_number_global',
            ['sid' => $seasonId],
        );
        if ($weeks === []) {
            return 0;
        }

        $existing = [];
        foreach (Database::fetchAll(
            'SELECT week_id, planned_date FROM `sessions` WHERE group_id = :gid AND season_id = :sid',
            ['gid' => $groupId, 'sid' => $seasonId],
        ) as $row) {
            $existing[(int) $row['week_id'] . '|' . (string) $row['planned_date']] = true;
        }

        $global = (int) Database::fetchValue(
            'SELECT COALESCE(MAX(session_number_global), 0) FROM `sessions` WHERE group_id = :gid AND season_id = :sid',
            ['gid' => $groupId, 'sid' => $seasonId],
        );
        $days = self::scheduleDays((string) ($group['schedule_days'] ?? ''));
        $rows = [];

        foreach ($weeks as $week) {
            if (empty($week['start_date'])) {
                continue;
            }
            $start = new \DateTimeImmutable((string) $week['start_date']);
            $weekStart = $start->format('Y-m-d');
            $weekEnd = (string) ($week['end_date'] ?? '');
            $inWeek = (int) Database::fetchValue(
                'SELECT COUNT(*) FROM `sessions` WHERE group_id = :gid AND week_id = :wid',
                ['gid' => $groupId, 'wid' => (int) $week['id']],
            );

            foreach ($days as $day) {
                $delta = ((int) $day - (int) $start->format('N') + 7) % 7;
                $date = $start->modify('+' . $delta . ' days')->format('Y-m-d');
                if ($date < $weekStart || ($weekEnd !== '' && $date > $weekEnd)) {
                    continue;
                }
                $key = (int) $week['id'] . '|' . $date;
                if (isset($existing[$key])) {
                    continue;
                }

                $global++;
                $inWeek++;
                $rows[] = [
                    $seasonId,
                    (int) $week['term_id'],
                    (int) $week['id'],
                    $groupId,
                    $global,
                    $inWeek,
                    $week['week_type'] === 'review' ? 'revision' : 'memorization',
                    $date,
                    'planned',
                    self::START_TIME,
                    self::END_TIME,
                ];
                $existing[$key] = true;
            }
        }

        self::insertRows($rows);

        return count($rows);
    }

    /**
     * Insert generated sessions in bounded chunks instead of issuing one
     * round-trip per row.
     *
     * @param array<int, array<int, mixed>> $rows
     */
    private static function insertRows(array $rows): void
    {
        foreach (array_chunk($rows, 200) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'));
            $params = [];
            foreach ($chunk as $row) {
                array_push($params, ...$row);
            }
            Database::execute(
                'INSERT INTO `sessions`
                    (`season_id`, `term_id`, `week_id`, `group_id`, `session_number_global`,
                     `session_number_in_week`, `session_type`, `planned_date`, `status`, `start_time`, `end_time`)
                 VALUES ' . $placeholders,
                $params,
            );
        }
    }

    public static function forSeason(int $seasonId, ?int $centerId = null): int
    {
        $sql = 'SELECT id FROM `groups` WHERE 1 = 1';
        $params = [];
        if ($centerId !== null) {
            $sql .= ' AND center_id = :cid';
            $params['cid'] = $centerId;
        }
        $created = 0;
        foreach (Database::fetchAll($sql, $params) as $group) {
            $created += self::forGroup((int) $group['id'], $seasonId);
        }

        return $created;
    }

    private static function scheduleDays(string $raw): array
    {
        $map = ['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6, 'Sun' => 7];
        $days = [];
        foreach (array_map('trim', explode(',', $raw)) as $day) {
            if (isset($map[$day])) {
                $days[] = $map[$day];
            }
        }
        $days = array_values(array_unique($days));
        sort($days);

        return $days === [] ? array_map(static fn (string $d): int => $map[$d], self::DEFAULT_DAYS) : $days;
    }
}
