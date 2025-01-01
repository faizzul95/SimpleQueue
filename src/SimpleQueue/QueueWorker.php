<?php

namespace OnlyPHP\SimpleQueue;

use OnlyPHP\Database\Interface\DatabaseInterface;
use OnlyPHP\Database\TableDefinition\JobSchema;
use OnlyPHP\Database\TableDefinition\FailedJobSchema;
use OnlyPHP\Helpers\SerializableClosure;

use RuntimeException;
use Exception;
use Throwable;

class QueueWorker
{
    private DatabaseInterface $db;
    private array $config;
    private string $lockFile;
    private bool $shouldRun = true;

    /**
     * Constructor
     *
     * @param DatabaseInterface $db
     * @param array $config
     */
    public function __construct(DatabaseInterface $db, array $config = [])
    {
        $this->db = $db;
        $this->config = array_merge([
            'process_check_interval' => 1000000, // 1 second in microseconds
            'worker_timeout' => 3600,
            'max_workers' => 1,
            'lock_dir' => sys_get_temp_dir(),
        ], $config);

        $this->lockFile = $this->config['lock_dir'] . '/queue_worker.lock';
    }

    /**
     * Start the worker process
     *
     * @return void
     */
    public function start(): void
    {
        if (!$this->acquireLock()) {
            throw new RuntimeException("Could not acquire worker lock");
        }

        // Register shutdown function to clean up
        register_shutdown_function([$this, 'cleanup']);

        // Handle termination signals if on Linux
        if (PHP_OS_FAMILY !== 'Windows') {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
        }

        $this->processJobs();
    }

    /**
     * Process jobs in the queue
     *
     * @return void
     */
    private function processJobs(): void
    {
        while ($this->shouldRun) {
            try {
                $job = $this->getNextJob();

                if ($job) {
                    $this->processJob($job);
                } else {
                    // No jobs available, wait before checking again
                    usleep($this->config['process_check_interval']);
                }

                // Check if we should continue running
                $this->checkWorkerTimeout();

            } catch (Exception $e) {
                // Log error and continue
                error_log("Worker error: " . $e->getMessage());
                usleep($this->config['process_check_interval']);
            }
        }
    }

    /**
     * Get the next job from the queue
     *
     * @return array|null
     */
    private function getNextJob(): ?array
    {
        try {
            $this->db->beginTransaction();

            // Get next job based on priority
            $query = "
                SELECT * FROM " . JobSchema::getTableName() . "
                WHERE status = 'pending'
                AND (retry_count < max_retries OR retry_count = 0)
                ORDER BY FIELD(priority, 'urgent', 'high', 'normal', 'low'), created_at
                LIMIT 1 FOR UPDATE
            ";

            $result = $this->db->query($query);

            if (empty($result)) {
                $this->db->commit();
                return null;
            }

            $job = $result[0];

            // Update job status
            $this->db->updateData(JobSchema::getTableName(), $job['id'], [
                'status' => 'processing',
                'pid' => getmypid(),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            $this->db->commit();
            return $job;

        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Process a single job
     *
     * @param array $job
     * @return void
     */
    private function processJob(array $job): void
    {
        try {
            // Include additional files if specified
            if (!empty($job['path_files'])) {
                require_once $job['path_files'];
            }

            // Prepare callable
            $callable = $this->prepareCallable($job);
            $params = unserialize($job['params']);

            // Execute with timeout
            $result = $this->executeWithTimeout($callable, $params, $job['timeout']);

            // Mark job as completed
            $this->db->updateData(JobSchema::getTableName(), $job['id'], [
                'status' => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

        } catch (Throwable $e) {
            $this->handleJobError($job, $e);
        }
    }

    /**
     * Prepare callable from job data
     *
     * @param array $job
     * @return callable
     */
    private function prepareCallable(array $job): callable
    {
        $callable = unserialize($job['callable']);

        switch ($job['callable_type']) {
            case 'closure':
                return $callable->getClosure();

            case 'class-method':
                if (!empty($job['object_instance'])) {
                    $callable[0] = unserialize($job['object_instance']);
                }
                return $callable;

            case 'function':
                return $callable;

            default:
                throw new RuntimeException("Invalid callable type: {$job['callable_type']}");
        }
    }

    /**
     * Execute callable with timeout
     *
     * @param callable $callable
     * @param array $params
     * @param int $timeout
     * @return mixed
     */
    private function executeWithTimeout(callable $callable, array $params, int $timeout)
    {
        // Set time limit
        set_time_limit($timeout);

        // Execute callable
        return call_user_func_array($callable, $params);
    }

    /**
     * Handle job execution error
     *
     * @param array $job
     * @param Throwable $error
     * @return void
     */
    private function handleJobError(array $job, Throwable $error): void
    {
        $retryCount = $job['retry_count'] + 1;

        if ($retryCount < $job['max_retries']) {
            // Update retry count and reset status
            $this->db->updateData(JobSchema::getTableName(), $job['id'], [
                'status' => 'pending',
                'retry_count' => $retryCount,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // Wait for retry delay
            sleep($job['retry_delay']);

        } else {
            // Mark as failed and log to failed_jobs
            $this->db->updateData(JobSchema::getTableName(), $job['id'], [
                'status' => 'failed',
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            $this->db->insertData(FailedJobSchema::getTableName(), [
                'uuid' => $job['uuid'],
                'job_id' => $job['id'],
                'exception' => $error->getMessage() . "\n" . $error->getTraceAsString(),
                'payload' => serialize($job)
            ]);
        }
    }

    /**
     * Check if worker should timeout
     *
     * @return void
     */
    private function checkWorkerTimeout(): void
    {
        static $startTime;

        if (!isset($startTime)) {
            $startTime = time();
        }

        if (time() - $startTime > $this->config['worker_timeout']) {
            $this->shouldRun = false;
        }
    }

    /**
     * Acquire process lock
     *
     * @return bool
     */
    private function acquireLock(): bool
    {
        $pid = getmypid();

        if (file_put_contents($this->lockFile, $pid)) {
            chmod($this->lockFile, 0644);
            return true;
        }

        return false;
    }

    /**
     * Release process lock
     *
     * @return void
     */
    private function releaseLock(): void
    {
        if (file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }
    }

    /**
     * Handle termination signal
     *
     * @param int $signal
     * @return void
     */
    public function handleSignal(int $signal): void
    {
        $this->shouldRun = false;
    }

    /**
     * Cleanup on shutdown
     *
     * @return void
     */
    public function cleanup(): void
    {
        $this->releaseLock();
    }
}