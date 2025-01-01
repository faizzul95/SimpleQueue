<?php

namespace OnlyPHP\Database\Drivers;

use mysqli;
use OnlyPHP\Database\Interface\DatabaseInterface;

class MySQLDriver implements DatabaseInterface
{
    private ?mysqli $connection = null;
    private array $config;

    /**
     * Constructor
     *
     * @param $config Database configuration
     */
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function connect(): void
    {
        try {
            $this->connection = new mysqli(
                $this->config['host'],
                $this->config['username'],
                $this->config['password'],
                $this->config['database'],
                $this->config['port'] ?? 3306
            );

            if ($this->connection->connect_error) {
                throw new \Exception("Connection failed: " . $this->connection->connect_error);
            }

            $this->connection->set_charset($this->config['charset'] ?? 'utf8mb4');
        } catch (\Exception $e) {
            throw new \Exception("Connection failed: " . $e->getMessage());
        }
    }

    public function disconnect(): void
    {
        if ($this->connection) {
            $this->connection->close();
            $this->connection = null;
        }
    }

    public function beginTransaction(): bool
    {
        return $this->connection->begin_transaction();
    }

    public function commit(): bool
    {
        return $this->connection->commit();
    }

    public function rollback(): bool
    {
        return $this->connection->rollback();
    }

    public function execute(string $query, array $params = []): bool
    {
        try {
            $stmt = $this->prepareStatement($query, $params);
            return $stmt->execute();
        } catch (\Exception $e) {
            throw new \Exception("Query execution failed: " . $e->getMessage());
        }
    }

    public function query(string $query, array $params = []): array
    {
        try {
            $stmt = $this->prepareStatement($query, $params);
            $stmt->execute();
            $result = $stmt->get_result();
            return $result->fetch_all(MYSQLI_ASSOC);
        } catch (\Exception $e) {
            throw new \Exception("Query failed: " . $e->getMessage());
        }
    }

    private function prepareStatement(string $query, array $params = []): \mysqli_stmt
    {
        $stmt = $this->connection->prepare($query);

        if (!empty($params)) {
            $types = '';
            foreach ($params as $param) {
                if (is_int($param)) {
                    $types .= 'i';
                } elseif (is_float($param)) {
                    $types .= 'd';
                } elseif (is_string($param)) {
                    $types .= 's';
                } else {
                    $types .= 'b';
                }
            }

            $stmt->bind_param($types, ...$params);
        }

        return $stmt;
    }

    public function insertData(string $table, array $data = []): bool
    {
        $columns = implode('`, `', array_keys($data));
        $values = implode(', ', array_fill(0, count($data), '?'));
        $query = "INSERT INTO `{$table}` (`{$columns}`) VALUES ({$values})";

        return $this->execute($query, array_values($data));
    }

    public function updateData(string $table, int $id, array $data = []): bool
    {
        $sets = implode(', ', array_map(fn($key) => "`{$key}` = ?", array_keys($data)));
        $query = "UPDATE `{$table}` SET {$sets} WHERE `id` = ?";

        $params = array_values($data);
        $params[] = $id;

        return $this->execute($query, $params);
    }

    public function deleteData(string $table, int $id, string $column = 'id'): bool
    {
        $query = "DELETE FROM `{$table}` WHERE `{$column}` = ?";
        return $this->execute($query, [$id]);
    }

    public function getLastInsertId(): int
    {
        return $this->connection->insert_id;
    }

    public function escapeString(string $value): string
    {
        return $this->connection->real_escape_string($value);
    }

    public function tableExists(string $tableName): bool
    {
        try {
            $result = $this->query(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ?",
                [$this->config['database'], $tableName]
            );
            return !empty($result);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function createTable(string $tableName, array $columns): bool
    {
        $columnDefinitions = [];
        foreach ($columns as $name => $definition) {
            $type = $definition['type'];
            $constraint = $definition['constraint'] ?? '';
            $unsigned = !empty($definition['unsigned']) ? 'UNSIGNED' : '';
            $null = isset($definition['null']) && $definition['null'] === false ? 'NOT NULL' : 'NULL';
            $autoIncrement = !empty($definition['auto_increment']) ? 'AUTO_INCREMENT' : '';
            $default = isset($definition['default']) ? "DEFAULT '{$definition['default']}'" : '';

            $columnDefinitions[] = trim(
                "`{$name}` {$type}" .
                ($constraint ? "({$constraint})" : '') .
                " {$unsigned} {$null} {$autoIncrement} {$default}"
            );
        }

        $query = sprintf(
            "CREATE TABLE IF NOT EXISTS `%s` (%s) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            $tableName,
            implode(', ', $columnDefinitions)
        );

        return $this->execute($query);
    }

    public function dropTable(string $tableName): bool
    {
        return $this->execute("DROP TABLE IF EXISTS `{$tableName}`");
    }

    public function truncateTable(string $tableName): bool
    {
        return $this->execute("TRUNCATE TABLE `{$tableName}`");
    }

    /**
     * Get the underlying MySQL PDO connection
     *
     * @return mysqli|null
     */
    public function getConnection(): ?mysqli
    {
        return $this->connection;
    }
}