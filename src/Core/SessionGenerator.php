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
        $created = 0;

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
                Database::insert('sessions', [
                    'season_id' => $seasonId,
                    'term_id' => (int) $week['term_id'],
                    'week_id' => (int) $week['id'],
                    'group_id' => $groupId,
                    'session_number_global' => $global,
                    'session_number_in_week' => $inWeek,
                    'session_type' => $week['week_type'] === 'review' ? 'revision' : 'memorization',
                    'planned_date' => $date,
                    'status' => 'planned',
                    'start_time' => self::START_TIME,
                    'end_time' => self::END_TIME,
                ]);
                $existing[$key] = true;
                $created++;
            }
        }

        return $created;
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
