<?php

namespace OnlyPHP\Database\Drivers;

use PDO;
use PDOException;
use OnlyPHP\Database\Interface\DatabaseInterface;

class PDODriver implements DatabaseInterface
{
    private ?PDO $connection = null;
    private array $config;
    private string $driverType;

    /**
     * Constructor
     *
     * @param $config Database configuration
     */
    public function __construct($config)
    {
        $this->config = $config;
        $this->driverType = $config['driver'] ?? 'mysql';
    }

    public function connect(): void
    {
        try {
            $dsn = $this->buildDsn();

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ];

            // Add specific options based on driver
            switch ($this->driverType) {
                case 'mysql':
                    $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4";
                    break;
                case 'pgsql':
                    $options[PDO::ATTR_PERSISTENT] = true;
                    break;
            }

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

    private function buildDsn(): string
    {
        switch ($this->driverType) {
            case 'mysql':
                return sprintf(
                    'mysql:host=%s;dbname=%s;port=%s;charset=%s',
                    $this->config['host'],
                    $this->config['database'],
                    $this->config['port'] ?? 3306,
                    $this->config['charset'] ?? 'utf8mb4'
                );

            case 'pgsql':
                return sprintf(
                    'pgsql:host=%s;dbname=%s;port=%s',
                    $this->config['host'],
                    $this->config['database'],
                    $this->config['port'] ?? 5432
                );

            case 'sqlsrv':
                return sprintf(
                    'sqlsrv:Server=%s;Database=%s',
                    $this->config['host'],
                    $this->config['database']
                );

            case 'oci':
                return sprintf(
                    'oci:dbname=//%s:%s/%s;charset=%s',
                    $this->config['host'],
                    $this->config['port'] ?? 1521,
                    $this->config['service_name'],
                    $this->config['charset'] ?? 'AL32UTF8'
                );

            case 'sqlite':
                return sprintf('sqlite:%s', $this->config['database']);

            default:
                throw new \Exception("Unsupported PDO driver: {$this->driverType}");
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
        $columns = implode(', ', array_map([$this, 'quoteIdentifier'], array_keys($data)));
        $values = implode(', ', array_fill(0, count($data), '?'));
        $query = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $this->quoteIdentifier($table),
            $columns,
            $values
        );

        return $this->execute($query, array_values($data));
    }

    public function updateData(string $table, int $id, array $data = []): bool
    {
        $sets = implode(', ', array_map(
            fn($key) => $this->quoteIdentifier($key) . " = ?",
            array_keys($data)
        ));

        $query = sprintf(
            "UPDATE %s SET %s WHERE %s = ?",
            $this->quoteIdentifier($table),
            $sets,
            $this->quoteIdentifier('id')
        );

        $params = array_values($data);
        $params[] = $id;

        return $this->execute($query, $params);
    }

    public function deleteData(string $table, int $id, string $column = 'id'): bool
    {
        $query = sprintf(
            "DELETE FROM %s WHERE %s = ?",
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($column)
        );

        return $this->execute($query, [$id]);
    }

    public function getLastInsertId(): int
    {
        return (int) $this->connection->lastInsertId();
    }

    public function escapeString(string $value): string
    {
        return $this->connection->quote($value);
    }

    public function tableExists(string $tableName): bool
    {
        try {
            $query = '';
            $params = [$tableName];

            switch ($this->driverType) {
                case 'mysql':
                    $query = "SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ?";
                    $params = [$this->config['database'], $tableName];
                    break;
                case 'pgsql':
                    $query = "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?";
                    break;
                case 'sqlsrv':
                    $query = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?";
                    break;
                case 'oci':
                    $query = "SELECT 1 FROM user_tables WHERE table_name = ?";
                    $params = [strtoupper($tableName)];
                    break;
                case 'sqlite':
                    $query = "SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?";
                    break;
                default:
                    throw new \Exception("Unsupported driver for table check");
            }

            $result = $this->query($query, $params);
            return !empty($result);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function createTable(string $table, array $columns): bool
    {
        $columnDefinitions = [];
        foreach ($columns as $name => $definition) {
            $type = $this->getDataType($definition['type']);
            $constraint = $definition['constraint'] ?? '';
            $unsigned = !empty($definition['unsigned']) && $this->driverType === 'mysql' ? 'UNSIGNED' : '';
            $null = isset($definition['null']) && $definition['null'] === false ? 'NOT NULL' : 'NULL';

            // Handle auto increment based on driver
            $autoIncrement = !empty($definition['auto_increment']) ?
                $this->getAutoIncrementSyntax() : '';

            $default = isset($definition['default']) ?
                $this->getDefaultValueSyntax($definition['default']) : '';

            $columnDefinitions[] = trim(sprintf(
                "%s %s%s %s %s %s %s",
                $this->quoteIdentifier($name),
                $type,
                $constraint ? "({$constraint})" : '',
                $unsigned,
                $autoIncrement,
                $null,
                $default
            ));
        }

        $query = $this->getCreateTableQuery($table, $columnDefinitions);
        return $this->execute($query);
    }

    private function getCreateTableQuery(string $table, array $columnDefinitions): string
    {
        $quotedTable = $this->quoteIdentifier($table);

        switch ($this->driverType) {
            case 'mysql':
                return sprintf(
                    "CREATE TABLE IF NOT EXISTS %s (%s) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                    $quotedTable,
                    implode(', ', $columnDefinitions)
                );

            case 'pgsql':
                return sprintf(
                    "CREATE TABLE IF NOT EXISTS %s (%s)",
                    $quotedTable,
                    implode(', ', $columnDefinitions)
                );

            case 'sqlsrv':
                return sprintf(
                    "IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = N'%s') CREATE TABLE %s (%s)",
                    $table,
                    $quotedTable,
                    implode(', ', $columnDefinitions)
                );

            default:
                return sprintf(
                    "CREATE TABLE %s (%s)",
                    $quotedTable,
                    implode(', ', $columnDefinitions)
                );
        }
    }

    private function getAutoIncrementSyntax(): string
    {
        switch ($this->driverType) {
            case 'mysql':
                return 'AUTO_INCREMENT';
            case 'pgsql':
                return 'GENERATED ALWAYS AS IDENTITY';
            case 'sqlsrv':
                return 'IDENTITY(1,1)';
            case 'sqlite':
                return 'AUTOINCREMENT';
            default:
                return '';
        }
    }

    private function getDefaultValueSyntax(string $default): string
    {
        if ($default === 'CURRENT_TIMESTAMP') {
            switch ($this->driverType) {
                case 'sqlsrv':
                    return 'DEFAULT GETDATE()';
                case 'pgsql':
                default:
                    return 'DEFAULT CURRENT_TIMESTAMP';
            }
        }

        return "DEFAULT '{$default}'";
    }

    private function quoteIdentifier(string $identifier): string
    {
        $char = '`';
        $endChar = '`';

        switch ($this->driverType) {
            case 'mysql':
                $char = '`';
                $endChar = '`';
                break;
            case 'sqlsrv':
                $char = '[';
                $endChar = ']';
                break;
            default:
                $char = '"';
                $endChar = '"';
        }

        return $char . str_replace($char, $char . $char, $identifier) . $endChar;
    }

    public function dropTable(string $table): bool
    {
        $query = '';
        switch ($this->driverType) {
            case 'mysql':
            case 'pgsql':
            case 'sqlite':
                $query = sprintf("DROP TABLE IF EXISTS %s", $this->quoteIdentifier($table));
                break;
            case 'sqlsrv':
                $query = sprintf("IF OBJECT_ID(N'%s', N'U') IS NOT NULL DROP TABLE %s", $table, $this->quoteIdentifier($table));
                break;
            case 'oci':
                $query = sprintf("DROP TABLE %s CASCADE CONSTRAINTS", $this->quoteIdentifier($table));
                break;
            default:
                $query = sprintf("DROP TABLE %s", $this->quoteIdentifier($table));
        }

        return $this->execute($query);
    }

    public function truncateTable(string $table): bool
    {
        $quotedTable = $this->quoteIdentifier($table);

        if ($this->driverType === 'sqlite') {
            return $this->execute("DELETE FROM {$quotedTable}");
        }

        return $this->execute("TRUNCATE TABLE {$quotedTable}");
    }

    private function getDataType(string $type): string
    {
        $type = strtoupper($type);

        switch ($this->driverType) {
            case 'mysql':
                return $type;
            case 'pgsql':
                return $this->getPostgresDataType($type);
            case 'sqlsrv':
                return $this->getSqlServerDataType($type);
            case 'oci':
                return $this->getOracleDataType($type);
            case 'sqlite':
                return $this->getSqliteDataType($type);
            default:
                return $type;
        }
    }

    private function getPostgresDataType(string $mysqlType): string
    {
        $typeMap = [
            'TINYINT' => 'SMALLINT',
            'MEDIUMINT' => 'INTEGER',
            'INT' => 'INTEGER',
            'DATETIME' => 'TIMESTAMP',
            'DOUBLE' => 'DOUBLE PRECISION',
            'LONGTEXT' => 'TEXT',
            'MEDIUMTEXT' => 'TEXT',
            'BOOLEAN' => 'BOOLEAN',
        ];

        return isset($typeMap[$mysqlType]) ? $typeMap[$mysqlType] : $mysqlType;
    }

    private function getSqlServerDataType(string $mysqlType): string
    {
        $typeMap = [
            'TINYINT' => 'TINYINT',
            'MEDIUMINT' => 'INT',
            'INT' => 'INT',
            'LONGTEXT' => 'TEXT',
            'MEDIUMTEXT' => 'TEXT',
            'DOUBLE' => 'FLOAT',
            'DATETIME' => 'DATETIME',
            'BOOLEAN' => 'BIT',
        ];

        return isset($typeMap[$mysqlType]) ? $typeMap[$mysqlType] : $mysqlType;
    }

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
            'VARCHAR' => 'VARCHAR2',
            'TEXT' => 'CLOB',
            'MEDIUMTEXT' => 'CLOB',
            'LONGTEXT' => 'CLOB',
            'DATETIME' => 'TIMESTAMP',
            'BOOLEAN' => 'NUMBER(1)',
            'BLOB' => 'BLOB',
        ];

        return isset($typeMap[$mysqlType]) ? $typeMap[$mysqlType] : $mysqlType;
    }

    private function getSqliteDataType(string $mysqlType): string
    {
        $typeMap = [
            'TINYINT' => 'INTEGER',
            'SMALLINT' => 'INTEGER',
            'MEDIUMINT' => 'INTEGER',
            'INT' => 'INTEGER',
            'BIGINT' => 'INTEGER',
            'FLOAT' => 'REAL',
            'DOUBLE' => 'REAL',
            'DECIMAL' => 'REAL',
            'DATETIME' => 'TEXT',
            'TIMESTAMP' => 'TEXT',
            'DATE' => 'TEXT',
            'TIME' => 'TEXT',
            'YEAR' => 'INTEGER',
            'CHAR' => 'TEXT',
            'VARCHAR' => 'TEXT',
            'BLOB' => 'BLOB',
            'TEXT' => 'TEXT',
            'MEDIUMTEXT' => 'TEXT',
            'LONGTEXT' => 'TEXT',
            'BOOLEAN' => 'INTEGER'
        ];

        return isset($typeMap[$mysqlType]) ? $typeMap[$mysqlType] : 'TEXT';
    }

    /**
     * Get the underlying PDO connection
     *
     * @return PDO|null
     */
    public function getConnection(): ?PDO
    {
        return $this->connection;
    }
}