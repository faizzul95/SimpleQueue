<?php

namespace OnlyPHP\Database\Drivers;

use OnlyPHP\Database\Interface\DatabaseInterface;

class Codeigniter3Driver implements DatabaseInterface
{
    private $CI = null;
    private $connection = null;

    /**
     * Constructor
     *
     * @param $db CodeIgniter database instance
     */
    public function __construct($db)
    {
        $this->connection = $db;
        $this->CI = &get_instance(); // Get the global CI instance
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
        return $this->connection->trans_begin();
    }

    public function commit(): bool
    {
        $this->connection->trans_commit();
        return true;
    }

    public function rollback(): bool
    {
        $this->connection->trans_rollback();
        return true;
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
            return $result->result_array();
        } catch (\Exception $e) {
            throw new \Exception("Query failed: " . $e->getMessage());
        }
    }

    public function insertData(string $table, array $data = []): bool
    {
        try {
            return $this->connection->insert($table, $data);
        } catch (\Exception $e) {
            throw new \Exception("Insert failed: " . $e->getMessage());
        }
    }

    public function updateData(string $table, int $id, array $data = []): bool
    {
        try {
            $this->connection->where('id', $id);
            return $this->connection->update($table, $data);
        } catch (\Exception $e) {
            throw new \Exception("Update failed: " . $e->getMessage());
        }
    }

    public function deleteData(string $table, int $id, string $column = 'id'): bool
    {
        try {
            $this->connection->where($column, $id);
            return $this->connection->delete($table);
        } catch (\Exception $e) {
            throw new \Exception("Delete failed: " . $e->getMessage());
        }
    }

    public function getLastInsertId(): int
    {
        return $this->connection->insert_id();
    }

    public function escapeString(string $value): string
    {
        return $this->connection->escape_str($value);
    }

    public function tableExists(string $tableName): bool
    {
        return $this->connection->table_exists($tableName);
    }

    public function createTable(string $tableName, array $columns): bool
    {
        if ($this->tableExists($tableName)) {
            return true;
        }

        $fields = [];
        $pk = null;
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

            if ($auto_increment) {
                $pk = $name;
            }
        }

        $this->CI->load->dbforge();
        $dbforge = $this->CI->dbforge;

        if (!empty($pk)) {
            $dbforge->add_key($pk, TRUE);
        }

        $dbforge->add_field($fields);
        return $dbforge->create_table($tableName, FALSE, ['ENGINE' => 'InnoDB', 'COLLATE' => 'utf8mb4_general_ci']);
    }

    public function dropTable(string $tableName): bool
    {
        $this->CI->load->dbforge();
        $dbforge = $this->CI->dbforge;
        return $dbforge->drop_table($tableName, TRUE);
    }

    public function truncateTable(string $tableName): bool
    {
        return $this->connection->truncate($tableName);
    }

    /**
     * Get the underlying connection
     *
     * @return object|null
     */
    public function getConnection(): ?object
    {
        return $this->connection;
    }
}
