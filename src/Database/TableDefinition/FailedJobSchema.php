<?php

namespace OnlyPHP\Database\TableDefinition;

class FailedJobSchema
{
    /**
     * Get the failed jobs table name
     *
     * @return string
     */
    public static function getTableName(): string
    {
        return 'failed_jobs';
    }

    /**
     * Get the failed jobs table schema definition
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
                'comment' => 'Unique identifier for the failed job'
            ],
            'job_id' => [
                'type' => 'BIGINT',
                'unsigned' => true,
                'null' => false,
                'comment' => 'Reference to the original job ID'
            ],
            'exception' => [
                'type' => 'LONGTEXT',
                'null' => false,
                'comment' => 'Exception details and stack trace'
            ],
            'payload' => [
                'type' => 'LONGTEXT',
                'null' => false,
                'comment' => 'Complete job payload at time of failure'
            ],
            'failed_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
                'default' => 'CURRENT_TIMESTAMP',
                'comment' => 'Timestamp of failure'
            ]
        ];
    }

    /**
     * Get the indexes for the failed jobs table
     *
     * @return array
     */
    public static function getIndexes(): array
    {
        return [
            'PRIMARY KEY' => ['id'],
            'KEY' => [
                'idx_uuid' => ['uuid'],
                'idx_job_id' => ['job_id']
            ],
            'FOREIGN KEY' => [
                'fk_job_id' => [
                    'columns' => ['job_id'],
                    'references' => ['jobs(id)'],
                    'on_delete' => 'CASCADE',
                    'on_update' => 'CASCADE'
                ]
            ]
        ];
    }
}