<?php

declare(strict_types=1);

/**
 * One-off migration of legacy cycle scores into session_scores.
 *
 * Each murajaa_reviews row is anchored to its existing session and copied to
 * the murajaa module score. The legacy table is kept read-only.
 *
 * Usage:
 *   php deploy_script/backfill-murajaa-scores.php
 *   php deploy_script/backfill-murajaa-scores.php --dry-run
 */

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $file = BASE_PATH.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use App\Core\Config;
use App\Core\Database;
use App\Core\MurajaaSummary;

Config::load(BASE_PATH.'/.env');

$dry = in_array('--dry-run', $argv, true);
$module = Database::fetchOne("SELECT id FROM `scoring_modules` WHERE code = 'murajaa' ORDER BY id ASC LIMIT 1");
if (! $module) {
    fwrite(STDERR, "Murajaa module not found.\n");
    exit(1);
}

$moduleId = (int) $module['id'];
$reviews = Database::fetchAll('SELECT * FROM `murajaa_reviews` ORDER BY id');
$copied = 0;
$skipped = 0;
$missing = 0;

foreach ($reviews as $review) {
    $sessionId = (int) ($review['session_id'] ?? 0);
    $studentId = (int) ($review['student_id'] ?? 0);
    $session = $sessionId > 0
        ? Database::fetchOne('SELECT * FROM `sessions` WHERE id = :id', ['id' => $sessionId])
        : null;
    if (! $session || $studentId <= 0) {
        $missing++;
        continue;
    }

    $exists = Database::fetchValue(
        'SELECT 1 FROM `session_scores` WHERE student_id = :sid AND session_id = :session AND module_id = :module',
        ['sid' => $studentId, 'session' => $sessionId, 'module' => $moduleId],
    );
    if ($exists) {
        $skipped++;
        continue;
    }

    if (! $dry) {
        Database::insert('session_scores', [
            'student_id' => $studentId,
            'season_id' => (int) $session['season_id'],
            'term_id' => (int) $session['term_id'],
            'week_id' => (int) $session['week_id'],
            'session_id' => $sessionId,
            'log_date' => $session['planned_date'] ?: ($review['reviewed_at'] ?? date('Y-m-d')),
            'module_id' => $moduleId,
            'score' => (float) $review['score'],
            'entered_by' => $review['entered_by'] ?: null,
        ]);
    }
    $copied++;
}

if (! $dry) {
    $pairs = Database::fetchAll(
        'SELECT DISTINCT student_id, season_id FROM `session_scores` WHERE module_id = :module',
        ['module' => $moduleId],
    );
    foreach ($pairs as $pair) {
        MurajaaSummary::recomputeStudentSeason((int) $pair['student_id'], (int) $pair['season_id']);
    }
}

printf(
    "murajaa backfill: copied=%d skipped=%d missing_session=%d%s\n",
    $copied,
    $skipped,
    $missing,
    $dry ? ' (dry-run)' : '',
);
