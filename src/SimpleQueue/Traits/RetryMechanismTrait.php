<?php

namespace OnlyPHP\SimpleQueue\Traits;

use OnlyPHP\Database\TableDefinition\JobSchema;
use OnlyPHP\Database\TableDefinition\FailedJobSchema;

trait RetryMechanismTrait
{
    /**
     * Retry a failed job
     *
     * @param string $jobId
     * @return bool
     * @throws \Exception
     */
    public function retryJob(string $jobId): bool
    {
        try {
            $this->db->beginTransaction();

            // Get job information
            $job = $this->getJobById($jobId);
            if (!$job) {
                throw new \RuntimeException("Job not found: {$jobId}");
            }

            // Check if job can be retried
            if ($job['retry_count'] >= $job['max_retries']) {
                throw new \RuntimeException("Maximum retry attempts reached for job: {$jobId}");
            }

            // Update job for retry
            $updateData = [
                'status' => 'pending',
                'retry_count' => $job['retry_count'] + 1,
                'pid' => null,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $success = $this->db->updateData(JobSchema::getTableName(), $jobId, $updateData);

            if ($success) {
                $this->db->commit();
                return true;
            }

            $this->db->rollback();
            return false;

        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Retry all failed jobs
     *
     * @return int Number of jobs queued for retry
     * @throws \Exception
     */
    public function retryAllFailed(): int
    {
        try {
            $this->db->beginTransaction();

            // Get all failed jobs that can be retried
            $query = "
                SELECT j.* 
                FROM " . JobSchema::getTableName() . " j
                WHERE j.status = 'failed' 
                AND j.retry_count < j.max_retries
                ORDER BY j.created_at ASC
            ";

            $failedJobs = $this->db->query($query);
            $retryCount = 0;

            foreach ($failedJobs as $job) {
                $updateData = [
                    'status' => 'pending',
                    'retry_count' => $job['retry_count'] + 1,
                    'pid' => null,
                    'updated_at' => date('Y-m-d H:i:s')
                ];

                if ($this->db->updateData(JobSchema::getTableName(), $job['id'], $updateData)) {
                    $retryCount++;
                }
            }

            $this->db->commit();
            return $retryCount;

        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Clear failed jobs history
     *
     * @param int $daysOld Clear jobs older than this many days
     * @return int Number of records cleared
     */
    public function clearFailedJobs(int $daysOld = 30): int
    {
        $query = "
            DELETE FROM " . FailedJobSchema::getTableName() . "
            WHERE failed_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ";

        $this->db->execute($query, [$daysOld]);
        return $this->db->getAffectedRows();
    }

    /**
     * Handle job error
     *
     * @param array $job
     * @param \Throwable $error
     * @return void
     */
    protected function handleJobError(array $job, \Throwable $error): void
    {
        try {
            $this->db->beginTransaction();

            // Update job status
            $this->db->updateData(JobSchema::getTableName(), $job['id'], [
                'status' => 'failed',
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // Log failure
            $this->db->insertData(FailedJobSchema::getTableName(), [
                'uuid' => $job['uuid'],
                'job_id' => $job['id'],
                'exception' => $error->getMessage() . "\n" . $error->getTraceAsString(),
                'payload' => serialize($job)
            ]);

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollback();
            // Log secondary error
            error_log("Error handling job failure: " . $e->getMessage());
        }
    }
}