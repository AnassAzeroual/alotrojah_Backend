<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?self $instance = null;
    private static ?PDO $pdo = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::getPdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function getPdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $host = (string) Config::get('db_host', '127.0.0.1');
        $port = (int) Config::get('db_port', 3306);
        $dbname = (string) Config::get('db_database', 'alotrojah_dev');
        $username = (string) Config::get('db_username', 'alotrojah');
        $password = (string) Config::get('db_password', '');

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

        try {
            self::$pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                (defined('\Pdo\Mysql::ATTR_INIT_COMMAND') ? \Pdo\Mysql::ATTR_INIT_COMMAND : \PDO::MYSQL_ATTR_INIT_COMMAND) => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException("Database connection failure: " . $e->getMessage(), 500, $e);
        }

        return self::$pdo;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::getPdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = self::getPdo()->prepare($sql);
        $stmt->execute($params);
        $res = $stmt->fetch();
        return $res !== false ? $res : null;
    }

    public static function fetchValue(string $sql, array $params = []): mixed
    {
        $stmt = self::getPdo()->prepare($sql);
        $stmt->execute($params);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : null;
    }

    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::getPdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        if (empty($data)) {
            throw new RuntimeException("Cannot insert empty data into {$table}");
        }

        $columns = array_keys($data);
        $colList = implode(', ', array_map(fn($c) => "`$c`", $columns));
        $placeholders = implode(', ', array_map(fn($c) => ":$c", $columns));

        $sql = "INSERT INTO `{$table}` ({$colList}) VALUES ({$placeholders})";
        self::execute($sql, $data);
        return (int) self::getPdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if (empty($data)) {
            return 0;
        }

        $sets = [];
        $params = [];
        foreach ($data as $col => $val) {
            $sets[] = "`$col` = :set_$col";
            $params["set_$col"] = $val;
        }
        $setClause = implode(', ', $sets);

        $sql = "UPDATE `{$table}` SET {$setClause} WHERE {$where}";
        return self::execute($sql, array_merge($params, $whereParams));
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        $sql = "DELETE FROM `{$table}` WHERE {$where}";
        return self::execute($sql, $params);
    }

    public static function lastInsertId(): int
    {
        return (int) self::getPdo()->lastInsertId();
    }

    public static function beginTransaction(): void
    {
        self::getPdo()->beginTransaction();
    }

    public static function commit(): void
    {
        self::getPdo()->commit();
    }

    public static function rollBack(): void
    {
        if (self::getPdo()->inTransaction()) {
            self::getPdo()->rollBack();
        }
    }

    public static function transaction(callable $callback): mixed
    {
        self::beginTransaction();
        try {
            $result = $callback(self::getPdo());
            self::commit();
            return $result;
        } catch (\Throwable $e) {
            self::rollBack();
            throw $e;
        }
    }

    public static function paginate(
        string $baseQuery,
        array $params = [],
        int $page = 1,
        int $perPage = 20,
        ?string $countQuery = null
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        if ($countQuery === null) {
            // Smart count extraction
            $countSql = "SELECT COUNT(*) FROM (" . $baseQuery . ") AS __count_table";
            $total = (int) self::fetchValue($countSql, $params);
        } else {
            $total = (int) self::fetchValue($countQuery, $params);
        }

        $dataSql = $baseQuery . " LIMIT {$perPage} OFFSET {$offset}";
        $items = self::fetchAll($dataSql, $params);

        $lastPage = (int) max(1, ceil($total / $perPage));
        $from = $total > 0 ? $offset + 1 : null;
        $to = $total > 0 ? min($offset + count($items), $total) : null;

        return [
            'data' => $items,
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
            'from' => $from,
            'to' => $to,
        ];
    }
}
