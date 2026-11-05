<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Splits simple MySQL scripts into statements without breaking on semicolons
 * inside quoted strings or comments.
 *
 * DELIMITER blocks (stored procedures/triggers) are intentionally unsupported.
 */
final class SqlStatementSplitter
{
    /**
     * @return list<string>
     */
    public function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $index = 0;

        while ($index < $length) {
            [$chunk, $index] = $this->readChunk($sql, $index);
            $current .= $chunk;

            if ($chunk === ';') {
                $this->flush($current, $statements);
            }
        }

        $this->flush($current, $statements);

        return $statements;
    }

    /**
     * @return array{0:string,1:int} chunk and next index
     */
    private function readChunk(string $sql, int $index): array
    {
        $char = $sql[$index];
        $next = $index + 1 < strlen($sql) ? $sql[$index + 1] : '';

        if ($this->isQuote($char)) {
            return $this->readQuoted($sql, $index, $char);
        }
        if ($this->isLineCommentStart($sql, $index, $char, $next)) {
            return $this->readLineComment($sql, $index);
        }
        if ($char === '/' && $next === '*') {
            return $this->readBlockComment($sql, $index);
        }

        return [$char, $index + 1];
    }

    private function isQuote(string $char): bool
    {
        return $char === "'" || $char === '"' || $char === '`';
    }

    private function isLineCommentStart(string $sql, int $index, string $char, string $next): bool
    {
        if ($char === '#') {
            return true;
        }

        return $char === '-'
            && $next === '-'
            && ($index + 2 >= strlen($sql) || ctype_space($sql[$index + 2]));
    }

    /**
     * @return array{0:string,1:int}
     */
    private function readQuoted(string $sql, int $start, string $quote): array
    {
        $length = strlen($sql);
        $index = $start + 1;
        $out = $quote;

        while ($index < $length) {
            $char = $sql[$index];
            $out .= $char;

            if ($char === '\\' && $index + 1 < $length) {
                $out .= $sql[$index + 1];
                $index += 2;

                continue;
            }

            $index++;
            if ($char === $quote) {
                break;
            }
        }

        return [$out, $index];
    }

    /**
     * @return array{0:string,1:int}
     */
    private function readLineComment(string $sql, int $start): array
    {
        $length = strlen($sql);
        $index = $start;
        $out = '';

        while ($index < $length) {
            $char = $sql[$index];
            $out .= $char;
            $index++;
            if ($char === "\n") {
                break;
            }
        }

        return [$out, $index];
    }

    /**
     * @return array{0:string,1:int}
     */
    private function readBlockComment(string $sql, int $start): array
    {
        $length = strlen($sql);
        $index = $start;
        $out = '';

        while ($index < $length) {
            $char = $sql[$index];
            $out .= $char;

            if ($char === '*' && $index + 1 < $length && $sql[$index + 1] === '/') {
                $out .= '/';
                $index += 2;

                break;
            }

            $index++;
        }

        return [$out, $index];
    }

    /**
     * @param list<string> $statements
     */
    private function flush(string &$current, array &$statements): void
    {
        $statement = trim($current);
        if ($statement !== '') {
            $statements[] = $statement;
        }
        $current = '';
    }
}
