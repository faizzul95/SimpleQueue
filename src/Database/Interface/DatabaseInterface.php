<?php

namespace OnlyPHP\Database\Interface;

interface DatabaseInterface
{
    /**
     * Establishes database connection
     *
     * @return void
     * @throws \Exception If connection fails
     */
    public function connect(): void;

    /**
     * Closes database connection
     *
     * @return void
     */
    public function disconnect(): void;

    /**
     * Starts a database transaction
     *
     * @return bool
     */
    public function beginTransaction(): bool;

    /**
     * Commits the current transaction
     *
     * @return bool
     */
    public function commit(): bool;

    /**
     * Rolls back the current transaction
     *
     * @return bool
     */
    public function rollback(): bool;

    /**
     * Executes a query without returning results
     *
     * @param string $query SQL query
     * @param array $params Query parameters
     * @return bool
     */
    public function execute(string $query, array $params = []): bool;

    /**
     * Executes a query and returns results
     *
     * @param string $query SQL query
     * @param array $params Query parameters
     * @return array
     */
    public function query(string $query, array $params = []): array;

    /**
     * Inserts data into a table
     *
     * @param string $table Table name
     * @param array $data Associative array of column => value
     * @return bool
     */
    public function insertData(string $table, array $data = []): bool;

    /**
     * Updates data in a table
     *
     * @param string $table Table name
     * @param string|int $id Record ID
     * @param array $data Associative array of column => value
     * @return bool
     */
    public function updateData(string $table, int $id, array $data = []): bool;

    /**
     * Deletes data from a table
     *
     * @param string $table Table name
     * @param string|int $id Record ID
     * @param string $column Column name for condition
     * @return bool
     */
    public function deleteData(string $table, int $id, string $column = 'id'): bool;

    /**
     * Gets the last inserted ID
     *
     * @return int
     */
    public function getLastInsertId(): int;

    /**
     * Escapes a string for safe use in queries
     *
     * @param string $value Value to escape
     * @return string
     */
    public function escapeString(string $value): string;

    /**
     * Checks if a table exists
     *
     * @param string $tableName Table name
     * @return bool
     */
    public function tableExists(string $tableName): bool;

    /**
     * Creates a new table
     *
     * @param string $tableName Table name
     * @param array $columns Column definitions
     * @return bool
     */
    public function createTable(string $tableName, array $columns): bool;

    /**
     * Drops a table
     *
     * @param string $tableName Table name
     * @return bool
     */
    public function dropTable(string $tableName): bool;

    /**
     * Truncates a table
     *
     * @param string $tableName Table name
     * @return bool
     */
    public function truncateTable(string $tableName): bool;

    /**
     * Get the underlying database connection
     *
     * @return mixed The database connection object
     */
    public function getConnection();
}