<?php

namespace OnlyPHP\Database\Drivers;

use OnlyPHP\Database\Interface\DatabaseInterface;

class OCIDriver implements DatabaseInterface
{
    private $connection = null;
    private array $config;
    private $statement = null;

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
            $connectionString = sprintf(
                "(DESCRIPTION=(ADDRESS=(PROTOCOL=TCP)(HOST=%s)(PORT=%s))(CONNECT_DATA=(SERVICE_NAME=%s)))",
                $this->config['host'],
                $this->config['port'] ?? 1521,
                $this->config['service_name']
            );

            $this->connection = oci_connect(
                $this->config['username'],
                $this->config['password'],
                $connectionString,
                $this->config['charset'] ?? 'AL32UTF8'
            );

            if (!$this->connection) {
                $error = oci_error();
                throw new \Exception("Connection failed: " . ($error['message'] ?? 'Unknown error'));
            }
        } catch (\Exception $e) {
            throw new \Exception("Connection failed: " . $e->getMessage());
        }
    }

    public function disconnect(): void
    {
        if ($this->statement) {
            oci_free_statement($this->statement);
            $this->statement = null;
        }

        if ($this->connection) {
            oci_close($this->connection);
            $this->connection = null;
        }
    }

    public function beginTransaction(): bool
    {
        return true; // Oracle automatically starts transaction on first query
    }

    public function commit(): bool
    {
        return oci_commit($this->connection);
    }

    public function rollback(): bool
    {
        return oci_rollback($this->connection);
    }

    public function execute(string $query, array $params = []): bool
    {
        try {
            $this->statement = oci_parse($this->connection, $this->preparePlaceholders($query));

            if (!$this->statement) {
                $error = oci_error($this->connection);
                throw new \Exception("Parse failed: " . ($error['message'] ?? 'Unknown error'));
            }

            $this->bindParams($params);

            $success = oci_execute($this->statement, OCI_DEFAULT);

            if (!$success) {
                $error = oci_error($this->statement);
                throw new \Exception("Execute failed: " . ($error['message'] ?? 'Unknown error'));
            }

            return true;
        } catch (\Exception $e) {
            throw new \Exception("Query execution failed: " . $e->getMessage());
        }
    }

    public function query(string $query, array $params = []): array
    {
        try {
            $this->statement = oci_parse($this->connection, $this->preparePlaceholders($query));

            if (!$this->statement) {
                $error = oci_error($this->connection);
                throw new \Exception("Parse failed: " . ($error['message'] ?? 'Unknown error'));
            }

            $this->bindParams($params);

            $success = oci_execute($this->statement, OCI_DEFAULT);

            if (!$success) {
                $error = oci_error($this->statement);
                throw new \Exception("Execute failed: " . ($error['message'] ?? 'Unknown error'));
            }

            $results = [];
            while ($row = oci_fetch_assoc($this->statement)) {
                $results[] = array_change_key_case($row, CASE_LOWER);
            }

            return $results;
        } catch (\Exception $e) {
            throw new \Exception("Query failed: " . $e->getMessage());
        }
    }

    private function preparePlaceholders(string $query): string
    {
        // Convert ? placeholders to :param1, :param2, etc.
        $index = 0;
        return preg_replace_callback('/\?/', function () use (&$index) {
            return ':param' . (++$index);
        }, $query);
    }

    private function bindParams(array $params): void
    {
        foreach ($params as $index => $value) {
            $paramName = ':param' . ($index + 1);
            oci_bind_by_name($this->statement, $paramName, $params[$index]);
        }
    }

    public function insertData(string $table, array $data = []): bool
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $query = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";

        return $this->execute($query, array_values($data));
    }

    public function updateData(string $table, int $id, array $data = []): bool
    {
        $sets = implode(', ', array_map(fn($key) => "{$key} = ?", array_keys($data)));
        $query = "UPDATE {$table} SET {$sets} WHERE id = ?";

        $params = array_values($data);
        $params[] = $id;

        return $this->execute($query, $params);
    }

    public function deleteData(string $table, int $id, string $column = 'id'): bool
    {
        $query = "DELETE FROM {$table} WHERE {$column} = ?";
        return $this->execute($query, [$id]);
    }

    public function getLastInsertId(): int
    {
        // Oracle requires a sequence for auto-incrementing
        // This method assumes a sequence named table_name_seq exists
        $result = $this->query("SELECT CURRENT_SEQUENCE_VALUE as id FROM dual");
        return (int) ($result[0]['id'] ?? 0);
    }

    public function escapeString(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    public function tableExists(string $tableName): bool
    {
        try {
            $result = $this->query(
                "SELECT 1 FROM user_tables WHERE table_name = ?",
                [strtoupper($tableName)]
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
            $type = $this->getOracleDataType($definition['type']);
            $constraint = $definition['constraint'] ?? '';
            $null = isset($definition['null']) && $definition['null'] === false ? 'NOT NULL' : 'NULL';

            // Handle auto-increment through sequence
            $autoIncrement = !empty($definition['auto_increment']);
            if ($autoIncrement) {
                $seqName = strtoupper($tableName) . '_SEQ';
                $this->execute("CREATE SEQUENCE {$seqName} START WITH 1 INCREMENT BY 1");
                $default = "DEFAULT {$seqName}.NEXTVAL";
            } else {
                $default = isset($definition['default']) ?
                    "DEFAULT " . ($definition['default'] === 'CURRENT_TIMESTAMP' ? 'CURRENT_TIMESTAMP' : "'{$definition['default']}'") : '';
            }

            $columnDefinitions[] = trim(
                "{$name} {$type}" .
                ($constraint ? "({$constraint})" : '') .
                " {$null} {$default}"
            );
        }

        $query = sprintf(
            "CREATE TABLE %s (%s)",
            $tableName,
            implode(', ', $columnDefinitions)
        );

        return $this->execute($query);
    }

    public function dropTable(string $tableName): bool
    {
        // Also drop the sequence if it exists
        $seqName = strtoupper($tableName) . '_SEQ';
        $this->execute("DROP SEQUENCE IF EXISTS {$seqName}");
        return $this->execute("DROP TABLE {$tableName} CASCADE CONSTRAINTS");
    }

    public function truncateTable(string $tableName): bool
    {
        return $this->execute("TRUNCATE TABLE {$tableName}");
    }

    /**
     * Convert MySQL data types to Oracle data types
     *
     * @param string $mysqlType
     * @return string
     */
    private function getOracleDataType(string $mysqlType): string
    {
        $typeMap = [
            'TINYINT' => 'NUMBER(3)',
            'SMALLINT' => 'NUMBER(5)',
            'MEDIUMINT' => 'NUMBER(7)',
            'INT' => 'NUMBER(10)',
            'BIGINT' => 'NUMBER(19)',
            'FLOAT' => 'FLOAT',
            'DOUBLE' => 'FLOAT',
            'DECIMAL' => 'NUMBER',
            'CHAR' => 'CHAR',
            'VARCHAR' => 'VARCHAR2',
            'TEXT' => 'CLOB',
            'MEDIUMTEXT' => 'CLOB',
            'LONGTEXT' => 'CLOB',
            'DATETIME' => 'TIMESTAMP',
            'TIMESTAMP' => 'TIMESTAMP',
            'DATE' => 'DATE',
            'TIME' => 'TIMESTAMP',
            'YEAR' => 'NUMBER(4)',
            'BOOLEAN' => 'NUMBER(1)',
            'BLOB' => 'BLOB',
            'MEDIUMBLOB' => 'BLOB',
            'LONGBLOB' => 'BLOB'
        ];

        return $typeMap[strtoupper($mysqlType)] ?? 'VARCHAR2(255)';
    }

    /**
     * Get the underlying Oracle PDO connection
     *
     * @return null
     */
    public function getConnection()
    {
        return $this->connection;
    }
}