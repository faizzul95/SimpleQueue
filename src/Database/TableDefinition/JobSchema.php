<?php

namespace OnlyPHP\Database\TableDefinition;

class JobSchema
{
    /**
     * Get the job table name
     *
     * @return string
     */
    public static function getTableName(): string
    {
        return 'jobs';
    }

    /**
     * Get the job table schema definition
     *
     * @return array
     */
    public static function getDefinition(): array
    {
        return [
            'id' => [
                'type' => 'BIGINT',
                'unsigned' => true,
                'auto_increment' => true,
                'null' => false
            ],
            'uuid' => [
                'type' => 'VARCHAR',
                'constraint' => 36,
                'null' => false,
                'comment' => 'Unique identifier for the job'
            ],
            'name' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => false,
                'comment' => 'Name/description of the job'
            ],
            'callable_type' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'null' => true,
                'comment' => 'Type of callable: closure, class-method, function'
            ],
            'callable' => [
                'type' => 'LONGTEXT',
                'null' => true,
                'comment' => 'Serialized callable or function name'
            ],
            'namespace' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
                'comment' => 'Namespace for class-based callables'
            ],
            'object_instance' => [
                'type' => 'LONGTEXT',
                'null' => true,
                'comment' => 'Serialized object instance if needed'
            ],
            'path_files' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
                'comment' => 'Path to include additional files'
            ],
            'params' => [
                'type' => 'TEXT',
                'null' => true,
                'comment' => 'Serialized parameters for the job'
            ],
            'status' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'null' => false,
                'default' => 'pending',
                'comment' => 'Job status: pending, processing, completed, failed'
            ],
            'priority' => [
                'type' => 'VARCHAR',
                'constraint' => 10,
                'null' => false,
                'default' => 'normal',
                'comment' => 'Job priority: urgent, high, normal, low'
            ],
            'pid' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => true,
                'comment' => 'Process ID of the worker handling the job'
            ],
            'timeout' => [
                'type' => 'INT',
                'null' => false,
                'default' => 7200,
                'comment' => 'Maximum execution time in seconds'
            ],
            'retry_count' => [
                'type' => 'INT',
                'null' => false,
                'default' => 0,
                'comment' => 'Number of retry attempts made'
            ],
            'max_retries' => [
                'type' => 'INT',
                'null' => false,
                'default' => 3,
                'comment' => 'Maximum number of retry attempts'
            ],
            'retry_delay' => [
                'type' => 'INT',
                'null' => false,
                'default' => 5,
                'comment' => 'Delay between retries in seconds'
            ],
            'started_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
                'comment' => 'Job start timestamp'
            ],
            'completed_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
                'comment' => 'Job completion timestamp'
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
                'default' => 'CURRENT_TIMESTAMP',
                'comment' => 'Job creation timestamp'
            ],
            'updated_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
                'comment' => 'Last update timestamp'
            ]
        ];
    }

    /**
     * Get the indexes for the jobs table
     *
     * @return array
     */
    public static function getIndexes(): array
    {
        return [
            'PRIMARY KEY' => ['id'],
            'KEY' => [
                'idx_uuid' => ['uuid'],
                'idx_status_priority' => ['status', 'priority'],
                'idx_pid' => ['pid']
            ],
        ];
    }
}