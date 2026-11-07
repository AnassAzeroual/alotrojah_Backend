<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Default-deny policy for automatic production migrations.
 *
 * Automatic migrations are schema-additive only. Data deletion, table/column
 * removal and DML are rejected before any statement executes. Destructive
 * changes must be reviewed and applied manually with a database backup.
 */
final class MigrationSafetyPolicy
{
    public function assertSafe(string $version, string $sql): void
    {
        $statements = (new SqlStatementSplitter)->split($sql);

        foreach ($statements as $index => $statement) {
            if (! $this->isAllowed($statement)) {
                throw new MigrationException(sprintf(
                    'Unsafe migration %s at statement %d: only additive schema changes are allowed.',
                    $version,
                    $index + 1,
                ));
            }
        }
    }

    private function isAllowed(string $statement): bool
    {
        $normalized = $this->normalize($statement);

        if (preg_match('/^CREATE\s+TABLE\s+/', $normalized) === 1) {
            return true;
        }
        if (preg_match('/^CREATE\s+(?:UNIQUE\s+)?INDEX\s+/', $normalized) === 1) {
            return true;
        }
        if (preg_match('/^ALTER\s+TABLE\s+\S+\s+ADD\s+(?:COLUMN|INDEX|KEY|CONSTRAINT|PRIMARY\s+KEY|UNIQUE|FOREIGN\s+KEY)\b/', $normalized) !== 1) {
            return false;
        }

        // ADD is additive, but a comma-separated ALTER could also contain DROP.
        $withoutReferenceActions = preg_replace('/\bON\s+(?:DELETE|UPDATE)\b/', 'ON ', $normalized) ?? $normalized;

        return preg_match(
            '/\b(?:DROP|TRUNCATE|DELETE|UPDATE|INSERT|REPLACE|RENAME|MODIFY|CHANGE)\b/',
            $withoutReferenceActions,
        ) !== 1;
    }

    private function normalize(string $statement): string
    {
        $withoutComments = preg_replace(
            ['#/\*.*?\*/#s', '/(?:--|#)[^\n]*/'],
            ' ',
            $statement,
        ) ?? $statement;

        $withoutStrings = preg_replace(
            ["/'(?:\\\\.|''|[^'])*'/s", '/"(?:\\\\.|""|[^"])*"/s'],
            "''",
            $withoutComments,
        ) ?? $withoutComments;

        return strtoupper(trim($withoutStrings));
    }
}
