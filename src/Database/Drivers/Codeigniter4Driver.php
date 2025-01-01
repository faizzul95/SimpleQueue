<?php

namespace OnlyPHP\Database\Drivers;

use OnlyPHP\Database\Interface\DatabaseInterface;
use CodeIgniter\Database\BaseConnection;

class Codeigniter4Driver implements DatabaseInterface
{
    private ?BaseConnection $connection = null;

    /**
     * Constructor
     *
     * @param BaseConnection $db CodeIgniter database instance
     */
    public function __construct(BaseConnection $db)
    {
        $this->connection = $db;
    }

    public function connect(): void
    {
        // Connection is already handled by CodeIgniter
    }

    public function disconnect(): void
    {
        if ($this->connection) {
            $this->connection->close();
        }
    }

    public function beginTransaction(): bool
    {
        return $this->connection->transBegin();
    }

    public function commit(): bool
    {
        return $this->connection->transCommit();
    }

    public function rollback(): bool
    {
        return $this->connection->transRollback();
    }

    public function execute(string $query, array $params = []): bool
    {
        try {
            $result = $this->connection->query($query, $params);
            return $result !== false;
        } catch (\Exception $e) {
            throw new \Exception("Query execution failed: " . $e->getMessage());
        }
    }

    public function query(string $query, array $params = []): array
    {
        try {
            $result = $this->connection->query($query, $params);
            return $result->getResultArray();
        } catch (\Exception $e) {
            throw new \Exception("Query failed: " . $e->getMessage());
        }
    }

    public function insertData(string $table, array $data = []): bool
    {
        try {
            $builder = $this->connection->table($table);
            return $builder->insert($data);
        } catch (\Exception $e) {
            throw new \Exception("Insert failed: " . $e->getMessage());
        }
    }

    public function updateData(string $table, int $id, array $data = []): bool
    {
        try {
            $builder = $this->connection->table($table);
            return $builder->where('id', $id)->update($data);
        } catch (\Exception $e) {
            throw new \Exception("Update failed: " . $e->getMessage());
        }
    }

    public function deleteData(string $table, int $id, string $column = 'id'): bool
    {
        try {
            $builder = $this->connection->table($table);
            return $builder->where($column, $id)->delete();
        } catch (\Exception $e) {
            throw new \Exception("Delete failed: " . $e->getMessage());
        }
    }

    public function getLastInsertId(): int
    {
        return $this->connection->insertID();
    }

    public function escapeString(string $value): string
    {
        return $this->connection->escapeString($value);
    }

    public function tableExists(string $tableName): bool
    {
        return $this->connection->tableExists($tableName);
    }

    public function createTable(string $tableName, array $columns): bool
    {
        if ($this->tableExists($tableName)) {
            return true;
        }

        $forge = \Config\Database::forge();

        $fields = [];
        foreach ($columns as $name => $definition) {
            $type = $definition['type'];
            $constraint = $definition['constraint'] ?? '';
            $unsigned = !empty($definition['unsigned']) ? 'UNSIGNED' : '';
            $null = isset($definition['null']) && $definition['null'] === false ? false : true;
            $auto_increment = !empty($definition['auto_increment']);
            $default = $definition['default'] ?? null;

            $fields[$name] = [
                'type' => $type . ($constraint ? "({$constraint})" : ''),
                'unsigned' => $unsigned === 'UNSIGNED',
                'null' => $null,
                'auto_increment' => $auto_increment,
            ];

            if (isset($default)) {
                $fields[$name]['default'] = $default;
            }
        }

        $forge->addField($fields);
        return $forge->createTable($tableName, true);
    }

    public function dropTable(string $tableName): bool
    {
        $forge = \Config\Database::forge();
        return $forge->dropTable($tableName, true);
    }

    public function truncateTable(string $tableName): bool
    {
        try {
            $builder = $this->connection->table($tableName);
            return $builder->truncate();
        } catch (\Exception $e) {
            throw new \Exception("Truncate failed: " . $e->getMessage());
        }
    }

    /**
     * Get the underlying CodeIgniter 4 connection
     *
     * @return \BaseConnection|null
     */
    public function getConnection(): ?\BaseConnection
    {
        return $this->connection;
    }
}