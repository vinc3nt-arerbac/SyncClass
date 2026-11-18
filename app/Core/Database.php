<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * PDO wrapper (Phase 6).
 *
 * The only class in the application that talks to a database connection. It
 * exists to make three things non-negotiable:
 *
 *   1. **Prepared statements.** Every value is bound, never interpolated. The
 *      update()/insert() helpers build the column list from application keys
 *      and the *values* from bindings.
 *   2. **Identifier validation.** Table and column names cannot be bound as
 *      parameters, so they are validated against a strict pattern. Anything
 *      that is not a plain identifier throws before SQL is built.
 *   3. **Transactions with one exit.** transaction() commits on success and
 *      rolls back on any Throwable, then rethrows — no partially written
 *      schedule.
 *
 * Repositories own SQL; controllers and views never see this class. MySQL is
 * the production driver; sqlite is supported so the test suite can exercise
 * the wrapper without a server.
 */
final class Database
{
    private ?PDO $pdo = null;

    /**
     * @param array<string, mixed> $config The `database` section of config.
     */
    public function __construct(private readonly array $config = [])
    {
    }

    /**
     * Lazy connection: a request that never touches the database (health
     * check, login page) must not pay for — or fail on — a connection.
     */
    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        // Driver-specific options are only applied where the driver accepts
        // them: ATTR_EMULATE_PREPARES is MySQL-only and throws on sqlite.
        if ($this->driver() === 'mysql') {
            $options[PDO::ATTR_EMULATE_PREPARES] = false;

            /** @var array<int, mixed> $configured */
            $configured = (array) ($this->config['options'] ?? []);
            $options = array_replace($options, $configured);
        }

        try {
            $this->pdo = new PDO($this->dsn(), (string) ($this->config['username'] ?? ''), (string) ($this->config['password'] ?? ''), $options);
        } catch (PDOException $exception) {
            // The message names host/database but never the password, so it is
            // safe to show in a local development log.
            throw new RuntimeException(sprintf(
                'Could not connect to the "%s" database on %s:%s. Check config/local.php and that MySQL is running. (%s)',
                (string) ($this->config['database'] ?? ''),
                (string) ($this->config['host'] ?? 'localhost'),
                (string) ($this->config['port'] ?? '3306'),
                $exception->getMessage()
            ), 0, $exception);
        }

        return $this->pdo;
    }

    public function isConnected(): bool
    {
        return $this->pdo instanceof PDO;
    }

    public function disconnect(): void
    {
        $this->pdo = null;
    }

    /**
     * Run a prepared statement and return it.
     *
     * @param array<string|int, mixed> $bindings
     */
    public function statement(string $sql, array $bindings = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($bindings);

        return $statement;
    }

    /**
     * @param array<string|int, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->statement($sql, $bindings)->fetchAll();
    }

    /**
     * @param array<string|int, mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->statement($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * First column of the first row — COUNT(*), SUM(), EXISTS queries.
     *
     * @param array<string|int, mixed> $bindings
     */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $value = $this->statement($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * Insert one row and return the auto-increment id.
     *
     * Column names come from the application, values from bindings. The return
     * is 0 for tables whose primary key is not auto-increment.
     *
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        if ($data === []) {
            throw new InvalidArgumentException('Cannot insert an empty row.');
        }

        $columns = array_map($this->identifier(...), array_keys($data));
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $this->identifier($table),
            implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns)),
            implode(', ', $placeholders)
        );

        $this->statement($sql, $this->prefixlessBindings($data));

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Update rows matching a developer-authored WHERE clause.
     *
     * The clause is SQL written in a repository, with placeholders for every
     * value: `update('schedules', $data, 'id = :id', ['id' => $id])`. SET
     * columns are validated; WHERE values are bound.
     *
     * @param array<string, mixed>     $data
     * @param array<string|int, mixed> $where
     */
    public function update(string $table, array $data, string $where, array $whereBindings = []): int
    {
        if ($data === []) {
            throw new InvalidArgumentException('Cannot update a row with no values.');
        }

        if (trim($where) === '') {
            throw new InvalidArgumentException('Refusing to update without a WHERE clause.');
        }

        $assignments = [];
        $bindings = [];

        foreach ($data as $column => $value) {
            $name = $this->identifier($column);
            // Prefixed so a SET column called `id` cannot collide with a
            // `:id` placeholder in the WHERE clause.
            $assignments[] = sprintf('`%s` = :set_%s', $name, $name);
            $bindings['set_' . $name] = $value;
        }

        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $this->identifier($table), implode(', ', $assignments), $where);

        return $this->statement($sql, array_replace($bindings, $whereBindings))->rowCount();
    }

    /**
     * Delete rows matching a developer-authored WHERE clause.
     *
     * @param array<string|int, mixed> $whereBindings
     */
    public function delete(string $table, string $where, array $whereBindings = []): int
    {
        if (trim($where) === '') {
            throw new InvalidArgumentException('Refusing to delete without a WHERE clause.');
        }

        $sql = sprintf('DELETE FROM `%s` WHERE %s', $this->identifier($table), $where);

        return $this->statement($sql, $whereBindings)->rowCount();
    }

    /**
     * Run a callback inside a transaction.
     *
     * Nested calls join the outer transaction (MySQL has no nested
     * transactions without savepoints, and a half-committed schedule is worse
     * than a coarse one). The callback receives this wrapper so it can run
     * further statements inside the same transaction.
     *
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();

        if ($pdo->inTransaction()) {
            return $callback($this);
        }

        $pdo->beginTransaction();

        try {
            $result = $callback($this);
            $pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function beginTransaction(): bool
    {
        return $this->pdo()->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo()->commit();
    }

    public function rollBack(): bool
    {
        return $this->pdo()->rollBack();
    }

    /**
     * Refuse anything that is not a plain identifier.
     *
     * This is the guard that makes table/column interpolation safe: a name
     * containing a backtick, space, dash, dot or comment marker never reaches
     * the SQL string.
     */
    public function identifier(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid SQL identifier "%s".', $name));
        }

        return $name;
    }

    private function driver(): string
    {
        $driver = strtolower((string) ($this->config['driver'] ?? 'mysql'));

        return $driver === 'mariadb' ? 'mysql' : $driver;
    }

    private function dsn(): string
    {
        $driver = $this->driver();

        return match ($driver) {
            'mysql' => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                (string) ($this->config['host'] ?? '127.0.0.1'),
                (int) ($this->config['port'] ?? 3306),
                (string) ($this->config['database'] ?? ''),
                (string) ($this->config['charset'] ?? 'utf8mb4')
            ),
            'sqlite' => 'sqlite:' . (string) ($this->config['database'] ?? ':memory:'),
            default => throw new RuntimeException(sprintf('Unsupported database driver "%s".', $driver)),
        };
    }

    /**
     * PDO accepts named parameters with or without the leading colon; the
     * helpers build `:column` placeholders from bare keys.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prefixlessBindings(array $data): array
    {
        $bindings = [];

        foreach ($data as $column => $value) {
            $bindings[':' . $this->identifier($column)] = $value;
        }

        return $bindings;
    }
}
