<?php

namespace OnlyPHP\Database\Drivers;

use OnlyPHP\Database\Interface\DatabaseInterface;
use Illuminate\Database\Connection;
use Exception;

class LaravelDriver implements DatabaseInterface
{
    private ?Connection $connection = null;

    /**
     * Constructor
     *
     * @param Connection $connection Laravel database connection
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function connect(): void
    {
        // Connection is already handled by Laravel
    }

    public function disconnect(): void
    {
        if ($this->connection) {
            $this->connection->disconnect();
        }
    }

    public function beginTransaction(): bool
    {
        $this->connection->beginTransaction();
        return true;
    }

    public function commit(): bool
    {
        $this->connection->commit();
        return true;
    }

    public function rollback(): bool
    {
        $this->connection->rollBack();
        return true;
    }

    public function execute(string $query, array $params = []): bool
    {
        try {
            return $this->connection->statement($query, $params);
        } catch (Exception $e) {
            throw new Exception("Query execution failed: " . $e->getMessage());
        }
    }

    public function query(string $query, array $params = []): array
    {
        try {
            return $this->connection->select($query, $params);
        } catch (Exception $e) {
            throw new Exception("Query failed: " . $e->getMessage());
        }
    }

    public function insertData(string $table, array $data = []): bool
    {
        try {
            return $this->connection->table($table)->insert($data);
        } catch (Exception $e) {
            throw new Exception("Insert failed: " . $e->getMessage());
        }
    }

    public function updateData(string $table, int $id, array $data = []): bool
    {
        try {
            return $this->connection->table($table)
                ->where('id', $id)
                ->update($data);
        } catch (Exception $e) {
            throw new Exception("Update failed: " . $e->getMessage());
        }
    }

    public function deleteData(string $table, int $id, string $column = 'id'): bool
    {
        try {
            return $this->connection->table($table)
                ->where($column, $id)
                ->delete();
        } catch (Exception $e) {
            throw new Exception("Delete failed: " . $e->getMessage());
        }
    }

    public function getLastInsertId(): int
    {
        return (int) $this->connection->getPdo()->lastInsertId();
    }

    public function escapeString(string $value): string
    {
        return $this->connection->getPdo()->quote($value);
    }

    public function tableExists(string $tableName): bool
    {
        return $this->connection->getSchemaBuilder()->hasTable($tableName);
    }

    public function createTable(string $tableName, array $columns): bool
    {
        if ($this->tableExists($tableName)) {
            return true;
        }

        try {
            $this->connection->getSchemaBuilder()->create($tableName, function ($table) use ($columns) {
                foreach ($columns as $name => $definition) {
                    $type = strtolower($definition['type']);
                    $column = null;

                    // Handle different column types
                    switch ($type) {
                        case 'int':
                            $column = $table->integer($name);
                            break;
                        case 'bigint':
                            $column = $table->bigInteger($name);
                            break;
                        case 'varchar':
                            $constraint = $definition['constraint'] ?? 255;
                            $column = $table->string($name, $constraint);
                            break;
                        case 'text':
                            $column = $table->text($name);
                            break;
                        case 'datetime':
                            $column = $table->datetime($name);
                            break;
                        case 'timestamp':
                            $column = $table->timestamp($name);
                            break;
                        default:
                            $column = $table->string($name);
                    }

                    // Handle column modifiers
                    if (!empty($definition['unsigned'])) {
                        $column->unsigned();
                    }
                    if (!empty($definition['auto_increment'])) {
                        $column->autoIncrement();
                    }
                    if (isset($definition['null']) && $definition['null'] === false) {
                        $column->nullable(false);
                    } else {
                        $column->nullable();
                    }
                    if (isset($definition['default'])) {
                        $column->default($definition['default']);
                    }
                }
            });
            return true;
        } catch (Exception $e) {
            throw new Exception("Create table failed: " . $e->getMessage());
        }
    }

    public function dropTable(string $tableName): bool
    {
        try {
            $this->connection->getSchemaBuilder()->dropIfExists($tableName);
            return true;
        } catch (Exception $e) {
            throw new Exception("Drop table failed: " . $e->getMessage());
        }
    }

    public function truncateTable(string $tableName): bool
    {
        try {
            return $this->connection->table($tableName)->truncate();
        } catch (Exception $e) {
            throw new Exception("Truncate failed: " . $e->getMessage());
        }
    }

    /**
     * Get the underlying Laravel connection
     *
     * @return \Connection|null
     */
    public function getConnection(): ?\Connection
    {
        return $this->connection;
    }
}