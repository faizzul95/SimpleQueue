<?php

namespace OnlyPHP\Database\Drivers;

use PDO;
use PDOException;
use OnlyPHP\Database\Interface\DatabaseInterface;

class MSSQLDriver implements DatabaseInterface
{
    private ?PDO $connection = null;
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
            $dsn = sprintf(
                'sqlsrv:Server=%s;Database=%s',
                $this->config['host'],
                $this->config['database']
            );

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ];

            $this->connection = new PDO(
                $dsn,
                $this->config['username'],
                $this->config['password'],
                $options
            );
        } catch (PDOException $e) {
            throw new \Exception("Connection failed: " . $e->getMessage());
        }
    }

    public function disconnect(): void
    {
        $this->connection = null;
    }

    public function beginTransaction(): bool
    {
        return $this->connection->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->connection->commit();
    }

    public function rollback(): bool
    {
        return $this->connection->rollBack();
    }

    public function execute(string $query, array $params = []): bool
    {
        try {
            $stmt = $this->connection->prepare($query);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            throw new \Exception("Query execution failed: " . $e->getMessage());
        }
    }

    public function query(string $query, array $params = []): array
    {
        try {
            $stmt = $this->connection->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            throw new \Exception("Query failed: " . $e->getMessage());
        }
    }

    public function insertData(string $table, array $data = []): bool
    {
        $columns = implode(', ', array_keys($data));
        $values = implode(', ', array_fill(0, count($data), '?'));
        $query = "INSERT INTO [{$table}] ({$columns}) VALUES ({$values})";

        return $this->execute($query, array_values($data));
    }

    public function updateData(string $table, int $id, array $data = []): bool
    {
        $sets = implode(', ', array_map(fn($key) => "[{$key}] = ?", array_keys($data)));
        $query = "UPDATE [{$table}] SET {$sets} WHERE [id] = ?";

        $params = array_values($data);
        $params[] = $id;

        return $this->execute($query, $params);
    }

    public function deleteData(string $table, int $id, string $column = 'id'): bool
    {
        $query = "DELETE FROM [{$table}] WHERE [{$column}] = ?";
        return $this->execute($query, [$id]);
    }

    public function getLastInsertId(): int
    {
        return (int) $this->connection->lastInsertId();
    }

    public function escapeString(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    public function tableExists(string $tableName): bool
    {
        try {
            $result = $this->query(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?",
                [$tableName]
            );
            return !empty($result);
        } catch (PDOException $e) {
            return false;
        }
    }

    public function createTable(string $tableName, array $columns): bool
    {
        $columnDefinitions = [];
        foreach ($columns as $name => $definition) {
            $type = $this->getMSSQLDataType($definition['type']);
            $constraint = $definition['constraint'] ?? '';
            $unsigned = !empty($definition['unsigned']) ? '' : ''; // MSSQL doesn't support UNSIGNED
            $null = isset($definition['null']) && $definition['null'] === false ? 'NOT NULL' : 'NULL';
            $autoIncrement = !empty($definition['auto_increment']) ? 'IDENTITY(1,1)' : '';
            $default = isset($definition['default']) ?
                "DEFAULT " . ($definition['default'] === 'CURRENT_TIMESTAMP' ? 'GETDATE()' : "'{$definition['default']}'") : '';

            $columnDefinitions[] = trim(
                "[{$name}] {$type}" .
                ($constraint ? "({$constraint})" : '') .
                " {$unsigned} {$autoIncrement} {$null} {$default}"
            );
        }

        $query = sprintf(
            "IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = N'%s') 
            CREATE TABLE [%s] (%s)",
            $tableName,
            $tableName,
            implode(', ', $columnDefinitions)
        );

        return $this->execute($query);
    }

    public function dropTable(string $tableName): bool
    {
        return $this->execute("IF OBJECT_ID(N'{$tableName}', N'U') IS NOT NULL DROP TABLE [{$tableName}]");
    }

    public function truncateTable(string $tableName): bool
    {
        return $this->execute("TRUNCATE TABLE [{$tableName}]");
    }

    /**
     * Convert MySQL data types to MSSQL data types
     *
     * @param string $mysqlType
     * @return string
     */
    private function getMSSQLDataType(string $mysqlType): string
    {
        $typeMap = [
            'TINYINT' => 'TINYINT',
            'SMALLINT' => 'SMALLINT',
            'MEDIUMINT' => 'INT',
            'INT' => 'INT',
            'BIGINT' => 'BIGINT',
            'FLOAT' => 'FLOAT',
            'DOUBLE' => 'FLOAT',
            'DECIMAL' => 'DECIMAL',
            'CHAR' => 'CHAR',
            'VARCHAR' => 'VARCHAR',
            'TEXT' => 'TEXT',
            'MEDIUMTEXT' => 'TEXT',
            'LONGTEXT' => 'TEXT',
            'DATETIME' => 'DATETIME',
            'TIMESTAMP' => 'DATETIME',
            'DATE' => 'DATE',
            'TIME' => 'TIME',
            'YEAR' => 'SMALLINT',
            'BOOLEAN' => 'BIT',
            'BLOB' => 'VARBINARY(MAX)',
            'MEDIUMBLOB' => 'VARBINARY(MAX)',
            'LONGBLOB' => 'VARBINARY(MAX)'
        ];

        return $typeMap[strtoupper($mysqlType)] ?? 'VARCHAR(255)';
    }

    /**
     * Get the underlying MSSQL PDO connection
     *
     * @return PDO|null
     */
    public function getConnection(): ?PDO
    {
        return $this->connection;
    }
}