<?php

namespace OnlyPHP\SimpleQueue\Traits;

use function Opis\Closure\{unserialize};

trait JobExecutionTrait
{
    /**
     * Execute a job with proper error handling and timeout
     *
     * @param array $job Job data
     * @return mixed
     * @throws \Exception
     */
    protected function executeJob(array $job)
    {
        try {
            // Set execution time limit
            $previousTimeout = ini_get('max_execution_time');
            set_time_limit($job['timeout']);

            // Include any required files
            if (!empty($job['path_files'])) {
                if (!file_exists($job['path_files'])) {
                    throw new \RuntimeException("Required file not found: {$job['path_files']}");
                }
                require_once $job['path_files'];
            }

            // Prepare and execute the callable
            $callable = $this->prepareCallable($job);
            $params = unserialize($job['params']);
            $result = $this->executeCallable($callable, $params);

            // Restore original timeout
            set_time_limit($previousTimeout);

            return $result;

        } catch (\Throwable $e) {
            $this->handleJobError($job, $e);
            throw $e;
        }
    }

    /**
     * Prepare a callable for execution
     *
     * @param array $job
     * @return callable
     * @throws \RuntimeException
     */
    protected function prepareCallable(array $job): callable
    {
        $callable = unserialize($job['callable']);

        switch ($job['callable_type']) {
            case 'closure':
                if (!($callable instanceof \Closure)) {
                    throw new \RuntimeException('Invalid closure callable');
                }
                return $callable;

            case 'class-method':
                if (!is_array($callable) || count($callable) !== 2) {
                    throw new \RuntimeException('Invalid class-method callable');
                }

                // Handle static vs instance methods
                if (is_string($callable[0])) {
                    // Static method call
                    if (!class_exists($callable[0])) {
                        throw new \RuntimeException("Class {$callable[0]} not found");
                    }
                } else {
                    // Instance method call
                    $instance = unserialize($job['object_instance']);
                    $callable[0] = $instance;
                }

                if (!is_callable($callable)) {
                    throw new \RuntimeException('Invalid callable configuration');
                }
                return $callable;

            case 'function':
                if (!is_string($callable) || !function_exists($callable)) {
                    throw new \RuntimeException("Function {$callable} not found");
                }
                return $callable;

            default:
                throw new \RuntimeException("Unsupported callable type: {$job['callable_type']}");
        }
    }

    /**
     * Execute a callable with its parameters
     *
     * @param callable $callable
     * @param array $params
     * @return mixed
     */
    protected function executeCallable(callable $callable, array $params)
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->executeWindowsCallable($callable, $params);
        }
        return $this->executeUnixCallable($callable, $params);
    }

    /**
     * Execute callable on Windows
     *
     * @param callable $callable
     * @param array $params
     * @return mixed
     */
    private function executeWindowsCallable(callable $callable, array $params)
    {
        // Windows doesn't support async signals, use simple execution
        return call_user_func_array($callable, $params);
    }

    /**
     * Execute callable on Unix systems
     *
     * @param callable $callable
     * @param array $params
     * @return mixed
     */
    private function executeUnixCallable(callable $callable, array $params)
    {
        // Set up error handler
        $previousErrorHandler = set_error_handler(function ($severity, $message, $file, $line) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            // Execute the callable
            $result = call_user_func_array($callable, $params);

            // Restore error handler
            if ($previousErrorHandler) {
                set_error_handler($previousErrorHandler);
            }

            return $result;
        } catch (\Throwable $e) {
            // Restore error handler
            if ($previousErrorHandler) {
                set_error_handler($previousErrorHandler);
            }
            throw $e;
        }
    }
}