<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use Throwable;

/**
 * Minimal, up-only SQL migrations for the native PHP backend.
 *
 * - Files live in database/migrations and are named <version>_<name>.sql.
 * - Applied versions/checksums live in schema_migrations.
 * - A MySQL advisory lock prevents concurrent runs.
 * - DDL is not transactional in MySQL; a failed file is reported and left
 *   unapplied, but its earlier statements may already have run.
 */
final class Migrator
{
    private const LOCK_NAME = 'alotrojah_schema_migrations';
    private const HISTORY_TABLE = 'schema_migrations';

    public function __construct(
        private readonly string $directory,
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @return array{applied:list<string>, skipped:int}
     */
    public function run(): array
    {
        if (! is_dir($this->directory)) {
            throw new MigrationException("Migrations directory not found: {$this->directory}");
        }

        $this->ensureHistoryTable();
        $this->acquireLock();

        try {
            return $this->applyPending();
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * @return array{applied:list<string>, pending:list<string>}
     */
    public function status(): array
    {
        $this->ensureHistoryTable();
        $applied = array_keys($this->appliedVersions());
        $pending = array_values(array_diff(array_keys($this->migrationFiles()), $applied));

        return ['applied' => $applied, 'pending' => $pending];
    }

    /**
     * @return array{applied:list<string>, skipped:int}
     */
    private function applyPending(): array
    {
        $applied = $this->appliedVersions();
        $files = $this->migrationFiles();
        $appliedNow = [];
        $skipped = 0;

        foreach ($files as $version => $path) {
            if ($this->isApplied($version, $path, $applied)) {
                $skipped++;

                continue;
            }

            $this->applyFile($version, $path);
            $appliedNow[] = $version;
        }

        return ['applied' => $appliedNow, 'skipped' => $skipped];
    }

    /**
     * @param array<string,string> $applied
     */
    private function isApplied(string $version, string $path, array $applied): bool
    {
        if (! isset($applied[$version])) {
            return false;
        }

        if (! hash_equals($applied[$version], $this->checksum($path))) {
            throw new MigrationException("Applied migration was modified: {$version}");
        }

        return true;
    }

    private function ensureHistoryTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS `'.self::HISTORY_TABLE.'` (
                `version` varchar(191) NOT NULL,
                `checksum` char(64) NOT NULL,
                `applied_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`version`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    private function acquireLock(): void
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:name, 10)');
        $stmt->execute(['name' => self::LOCK_NAME]);

        if ((int) $stmt->fetchColumn() !== 1) {
            throw new MigrationException('Another migration run is in progress.');
        }
    }

    private function releaseLock(): void
    {
        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $stmt->execute(['name' => self::LOCK_NAME]);
        } catch (Throwable $error) {
            // Closing the connection also releases the advisory lock.
            error_log('Migration lock release failed: '.$error->getMessage());
        }
    }

    /**
     * @return array<string,string> version => checksum
     */
    private function appliedVersions(): array
    {
        $stmt = $this->pdo->query(
            'SELECT `version`, `checksum` FROM `'.self::HISTORY_TABLE.'`',
        );
        $out = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['version']] = (string) $row['checksum'];
        }

        return $out;
    }

    /**
     * @return array<string,string> version => absolute path, sorted
     */
    private function migrationFiles(): array
    {
        $files = glob($this->directory.'/*.sql');
        if ($files === false) {
            throw new MigrationException("Cannot read migrations directory: {$this->directory}");
        }

        sort($files, SORT_STRING);
        $out = [];

        foreach ($files as $path) {
            $out[$this->migrationName($path)] = $path;
        }

        return $out;
    }

    private function migrationName(string $path): string
    {
        $name = basename($path, '.sql');
        if (preg_match('/^\d{14}_[a-z0-9_]+$/', $name) !== 1) {
            throw new MigrationException(
                "Invalid migration filename: {$name}.sql (expected YYYYMMDDHHMMSS_name.sql)",
            );
        }

        return $name;
    }

    private function applyFile(string $version, string $path): void
    {
        $sql = $this->readSql($version, $path);
        (new MigrationSafetyPolicy)->assertSafe($version, $sql);
        $splitter = new SqlStatementSplitter;

        foreach ($splitter->split($sql) as $index => $statement) {
            $this->executeStatement($version, $index, $statement);
        }

        $this->recordApplied($version, $this->checksum($path));
    }

    private function readSql(string $version, string $path): string
    {
        $sql = file_get_contents($path);
        if (! is_string($sql) || trim($sql) === '') {
            throw new MigrationException("Migration is empty: {$version}");
        }

        return $sql;
    }

    private function executeStatement(string $version, int $index, string $statement): void
    {
        try {
            $this->pdo->exec($statement);
        } catch (Throwable $error) {
            throw new MigrationException(
                sprintf(
                    'Migration %s failed at statement %d: %s',
                    $version,
                    $index + 1,
                    $error->getMessage(),
                ),
                0,
                $error,
            );
        }
    }

    private function recordApplied(string $version, string $checksum): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO `'.self::HISTORY_TABLE.'` (`version`, `checksum`) VALUES (:version, :checksum)',
        );
        $stmt->execute(['version' => $version, 'checksum' => $checksum]);
    }

    private function checksum(string $path): string
    {
        $checksum = hash_file('sha256', $path);
        if (! is_string($checksum)) {
            throw new MigrationException("Cannot checksum migration: {$path}");
        }

        return $checksum;
    }
}
