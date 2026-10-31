<?php

// This file connects the app to the MySQL database.
// The code is simple so beginners can change host, database, user, and password easily.
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'vegetable_system';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPassword = getenv('DB_PASSWORD') ?: '';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $connection = new mysqli($dbHost, $dbUser, $dbPassword, $dbName);
    $connection->set_charset('utf8mb4');
} catch (mysqli_sql_exception $exception) {
    error_log('MySQL connection failed with error code ' . $exception->getCode());
    http_response_code(500);
    exit(
        'Database connection failed. Check the settings in config/database.php '
        . 'and import database.sql.'
    );
}

// These small helpers keep database access readable while using MySQLi prepared statements.
class MySQLiDatabase
{
    private mysqli $connection;
    private bool $transactionActive = false;

    public function __construct(mysqli $connection)
    {
        $this->connection = $connection;
    }

    public function query(string $sql): MySQLiStatement
    {
        $statement = new MySQLiStatement($this->connection, $sql);
        $statement->execute();

        return $statement;
    }

    public function prepare(string $sql): MySQLiStatement
    {
        return new MySQLiStatement($this->connection, $sql);
    }

    public function lastInsertId(): string
    {
        return (string) $this->connection->insert_id;
    }

    public function beginTransaction(): bool
    {
        $started = $this->connection->begin_transaction();
        $this->transactionActive = true;

        return $started;
    }

    public function commit(): bool
    {
        $committed = $this->connection->commit();
        $this->transactionActive = false;

        return $committed;
    }

    public function rollBack(): bool
    {
        $rolledBack = $this->connection->rollback();
        $this->transactionActive = false;

        return $rolledBack;
    }

    public function inTransaction(): bool
    {
        return $this->transactionActive;
    }
}

class MySQLiStatement
{
    private mysqli_stmt $statement;
    private ?mysqli_result $result = null;

    public function __construct(mysqli $connection, string $sql)
    {
        $this->statement = $connection->prepare($sql);
    }

    public function execute(array $parameters = []): bool
    {
        if ($this->result) {
            $this->result->free();
            $this->result = null;
        }

        if ($parameters) {
            $values = array_values($parameters);
            $types = '';
            foreach ($values as $value) {
                if (is_int($value)) {
                    $types .= 'i';
                } elseif (is_float($value)) {
                    $types .= 'd';
                } else {
                    $types .= 's';
                }
            }

            $bindings = [$types];
            foreach ($values as $index => &$value) {
                $bindings[] = &$value;
            }
            unset($value);

            call_user_func_array([$this->statement, 'bind_param'], $bindings);
        }

        $this->statement->execute();
        $this->result = $this->statement->get_result() ?: null;

        return true;
    }

    public function fetch()
    {
        return $this->result ? $this->result->fetch_assoc() : false;
    }

    public function fetchAll(): array
    {
        return $this->result ? $this->result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function fetchColumn(int $column = 0)
    {
        if (!$this->result) {
            return false;
        }

        $row = $this->result->fetch_row();
        return $row[$column] ?? false;
    }

    public function rowCount(): int
    {
        return $this->statement->affected_rows;
    }
}

$db = new MySQLiDatabase($connection);
