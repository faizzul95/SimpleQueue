<?php

namespace OnlyPHP\SimpleQueue;

use OnlyPHP\Database\Interface\DatabaseInterface;
use OnlyPHP\Database\TableDefinition\JobSchema;
use OnlyPHP\Database\TableDefinition\FailedJobSchema;

use OnlyPHP\SimpleQueue\Traits\JobExecutionTrait;
use OnlyPHP\SimpleQueue\Traits\RetryMechanismTrait;

use OnlyPHP\Database\Drivers\PDODriver;
use OnlyPHP\Database\Drivers\MySQLDriver;
use OnlyPHP\Database\Drivers\MSSQLDriver;
use OnlyPHP\Database\Drivers\OciDriver;
use OnlyPHP\Database\Drivers\Codeigniter3Driver;
use OnlyPHP\Database\Drivers\Codeigniter4Driver;
use OnlyPHP\Database\Drivers\LaravelDriver;

use OnlyPHP\Helpers\SerializableClosure;

use RuntimeException;
use Exception;
use PDO;

class JobProcessor
{
    use JobExecutionTrait;
    use RetryMechanismTrait;

    private DatabaseInterface $db;
    private string $dbDriver;
    private string $dbType;
    private array $config;
    private $callable;
    private array $params = [];
    private string $priority = 'normal';
    private int $maxRetries = 3;
    private int $timeout = 14400;
    private int $retryDelay = 5;
    private ?string $includeFile = null;
    private string $name = '';

    /**
     * Constructor
     *
     * @param mixed $connection Database connection
     * @param array $config Configuration options
     */
    public function __construct($connection, array $config = [])
    {
        $this->db = $this->detectAndInitializeDriver($connection);
        $this->config = array_merge([
            'process_check_interval' => 1000000, // 1 second in microseconds
            'worker_timeout' => 3600,
            'max_workers' => 1,
            'lock_dir' => sys_get_temp_dir(),
        ], $config);

        $this->initializeTables();
    }

    /**
     * Detect and initialize appropriate database driver
     *
     * @param array $connection
     * @return DatabaseInterface
     * @throws Exception
     */
    private function detectAndInitializeDriver($connection): DatabaseInterface
    {
        // PDO Detection
        if ($connection instanceof PDO) {
            $driverName = $connection->getAttribute(PDO::ATTR_DRIVER_NAME);
            switch (strtolower($driverName)) {
                case 'mysql':
                    $this->dbType = 'mysql';
                    $this->dbDriver = MySQLDriver::class;
                    return new MySQLDriver($connection);
                case 'sqlsrv':
                case 'mssql':
                    $this->dbType = 'mssql';
                    $this->dbDriver = MSSQLDriver::class;
                    return new MSSQLDriver($connection);
                case 'oci':
                    $this->dbType = 'oci';
                    $this->dbDriver = OciDriver::class;
                    return new OciDriver($connection);
                default:
                    $this->dbType = 'pdo';
                    $this->dbDriver = PDODriver::class;
                    return new PDODriver($connection);
            }
        }

        // CodeIgniter 3 Detection
        if (
            is_object($connection) && (
                get_class($connection) === 'CI_DB' ||
                strpos(get_class($connection), 'CI_DB_') === 0
            )
        ) {
            $this->dbType = 'codeigniter3';
            $this->dbDriver = Codeigniter3Driver::class;
            return new Codeigniter3Driver($connection);
        }

        // CodeIgniter 4 Detection
        if (
            is_object($connection) && (
                strpos(get_class($connection), 'CodeIgniter\\Database\\') === 0 ||
                $connection instanceof \CodeIgniter\Database\BaseConnection
            )
        ) {
            $this->dbType = 'codeigniter4';
            $this->dbDriver = Codeigniter4Driver::class;
            return new Codeigniter4Driver($connection);
        }

        // Laravel Detection
        if (
            is_object($connection) && (
                strpos(get_class($connection), 'Illuminate\\Database\\') === 0 ||
                $connection instanceof \Illuminate\Database\Connection
            )
        ) {
            $this->dbType = 'laravel';
            $this->dbDriver = LaravelDriver::class;
            return new LaravelDriver($connection);
        }

        throw new Exception('Unsupported database connection type: ' . get_class($connection));
    }

    /**
     * Get current database driver class name
     *
     * @return string
     */
    public function getDriverName(): string
    {
        return $this->dbDriver;
    }

    /**
     * Get current database driver type
     *
     * @return string
     */
    public function getDriverType(): string
    {
        return $this->dbType;
    }

    /**
     * Initialize job tables if they don't exist
     *
     * @return void
     */
    private function initializeTables(): void
    {
        if (!$this->db->tableExists(JobSchema::getTableName())) {
            $this->db->createTable(JobSchema::getTableName(), JobSchema::getDefinition());
        }

        if (!$this->db->tableExists(FailedJobSchema::getTableName())) {
            $this->db->createTable(FailedJobSchema::getTableName(), FailedJobSchema::getDefinition());
        }
    }

    /**
     * Set the job to be executed
     *
     * @param mixed $callable The callable to execute
     * @param array $params Parameters for the callable
     * @return self
     */
    public function job($callable, array $params = []): self
    {
        $this->callable = $callable;
        $this->params = $params;
        return $this;
    }

    /**
     * Set job name
     *
     * @param string $name
     * @return self
     */
    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Set job priority
     *
     * @param string $priority
     * @return self
     */
    public function setPriority(string $priority): self
    {
        if (!in_array($priority, ['urgent', 'high', 'normal', 'low'])) {
            throw new RuntimeException("Invalid priority level: {$priority}");
        }
        $this->priority = $priority;
        return $this;
    }

    /**
     * Set maximum retries
     *
     * @param int $maxRetries
     * @return self
     */
    public function setMaxRetries(int $maxRetries): self
    {
        $this->maxRetries = $maxRetries;
        return $this;
    }

    /**
     * Set timeout
     *
     * @param int $timeout
     * @return self
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }

    /**
     * Set retry delay
     *
     * @param int $seconds
     * @return self
     */
    public function setRetryDelay(int $seconds): self
    {
        $this->retryDelay = $seconds;
        return $this;
    }

    /**
     * Set include file path
     *
     * @param string $filePath
     * @return self
     */
    public function setIncludePathFile(string $filePath): self
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("Include file not found: {$filePath}");
        }
        $this->includeFile = $filePath;
        return $this;
    }

    /**
     * Dispatch job to queue
     *
     * @return string Job UUID
     */
    public function dispatch(): string
    {
        $uuid = $this->generateUuid();
        $job = $this->prepareJob($uuid);

        try {
            $this->db->beginTransaction();
            $this->db->insertData(JobSchema::getTableName(), $job);
            $this->db->commit();

            // Start worker process if not already running
            $this->startWorker();

            return $uuid;
        } catch (Exception $e) {
            $this->db->rollback();
            throw new RuntimeException("Failed to dispatch job: " . $e->getMessage());
        }
    }

    /**
     * Execute job immediately
     *
     * @return mixed
     */
    public function dispatchNow()
    {
        return $this->executeJob($this->prepareJob($this->generateUuid()));
    }

    /**
     * Prepare job data
     *
     * @param string $uuid
     * @return array
     */
    private function prepareJob(string $uuid): array
    {
        return [
            'uuid' => $uuid,
            'name' => $this->name ?: get_class($this->callable),
            'callable_type' => $this->getCallableType(),
            'callable' => $this->serializeCallable(),
            'namespace' => $this->getCallableNamespace(),
            'object_instance' => $this->serializeObjectInstance(),
            'path_files' => $this->includeFile,
            'params' => serialize($this->params),
            'status' => 'pending',
            'priority' => $this->priority,
            'timeout' => $this->timeout,
            'max_retries' => $this->maxRetries,
            'retry_delay' => $this->retryDelay
        ];
    }

    /**
     * Get job statistics
     *
     * @return array
     */
    public function getJobStats(): array
    {
        $query = "
            SELECT 
                COUNT(*) as total_jobs,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_jobs,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_jobs,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_jobs,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_jobs,
                AVG(CASE 
                    WHEN completed_at IS NOT NULL 
                    THEN TIMESTAMPDIFF(SECOND, created_at, completed_at) 
                    ELSE NULL 
                END) as avg_processing_time
            FROM " . JobSchema::getTableName();

        $result = $this->db->query($query);
        return $result[0];
    }

    /**
     * Get job status by using uuid
     *
     * @return array
     */
    public function getJobStatus($uuid): array
    {
        $query = "SELECT * FROM " . JobSchema::getTableName() . " WHERE uuid = ?";
        $result = $this->db->query($query, [$uuid]);
        return $result[0];
    }

    /**
     * Get callable type
     *
     * @return string
     */
    private function getCallableType(): string
    {
        if ($this->callable instanceof \Closure) {
            return 'closure';
        } elseif (is_array($this->callable)) {
            return 'class-method';
        } elseif (is_string($this->callable) && function_exists($this->callable)) {
            return 'function';
        }
        throw new RuntimeException('Invalid callable type');
    }

    /**
     * Get callable namespace
     *
     * @return string|null
     */
    private function getCallableNamespace(): ?string
    {
        if (is_array($this->callable) && is_string($this->callable[0])) {
            return $this->callable[0];
        }
        return null;
    }

    /**
     * Serialize callable
     *
     * @return string
     */
    private function serializeCallable(): string
    {
        if ($this->callable instanceof \Closure) {
            return serialize(new SerializableClosure($this->callable));
        } elseif (is_array($this->callable)) {
            return serialize($this->callable);
        }
        return serialize($this->callable);
    }

    /**
     * Serialize object instance if needed
     *
     * @return string|null
     */
    private function serializeObjectInstance(): ?string
    {
        if (is_array($this->callable) && is_object($this->callable[0])) {
            return serialize($this->callable[0]);
        }
        return null;
    }

    /**
     * Generate UUID v4
     *
     * @return string
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Start worker process if not already running
     *
     * @return void
     */
    private function startWorker(): void
    {
        $lockFile = $this->config['lock_dir'] . '/queue_worker.lock';

        if (file_exists($lockFile)) {
            $pid = file_get_contents($lockFile);
            if ($this->isProcessRunning($pid)) {
                return;
            }
            unlink($lockFile);
        }

        $this->spawnWorker();
    }

    /**
     * Check if a process is running
     *
     * @param string $pid Process ID
     * @return bool
     */
    private function isProcessRunning(string $pid): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $result = shell_exec("tasklist /FI \"PID eq $pid\" 2>nul");
            return str_contains($result, $pid);
        }
        return file_exists("/proc/$pid");
    }

    /**
     * Spawn worker process
     *
     * @return void
     */
    private function spawnWorker(): void
    {
        $phpBinary = PHP_BINARY;
        $scriptPath = $this->ensureWorkerScript();
        $command = $this->buildWorkerCommand($scriptPath);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, null, null, ['bypass_shell' => true]);
        // $process = proc_open("$phpBinary $scriptPath", $descriptorSpec, $pipes, null, null, ['bypass_shell' => true]);

        if (is_resource($process)) {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
    }

    private function ensureWorkerScript()
    {
        $workerPath = __DIR__ . '/SimpleQueueWorker.php';
        if (!file_exists($workerPath)) {
            $this->createWorkerScript($workerPath);
        }
        return $workerPath;
    }

    /**
     * Create the worker script file
     *
     * @param string $path
     * @return string
     */
    private function createWorkerScript($path): string
    {
        $dbType = strtolower($this->getDriverType());

        // Map database types to their corresponding namespaces
        $driverMap = [
            'mysql' => 'OnlyPHP\Database\Drivers\MySQLDriver',
            'mssql' => 'OnlyPHP\Database\Drivers\MSSQLDriver',
            'oci' => 'OnlyPHP\Database\Drivers\OciDriver',
            'codeigniter3' => 'OnlyPHP\Database\Drivers\Codeigniter3Driver',
            'codeigniter4' => 'OnlyPHP\Database\Drivers\Codeigniter4Driver',
            'laravel' => 'OnlyPHP\Database\Drivers\LaravelDriver',
            'pdo' => 'OnlyPHP\Database\Drivers\PDODriver'
        ];

        // Get the namespace or default to PDODriver
        $namespace = $driverMap[$dbType] ?? $driverMap['pdo'];

        // Generate only the relevant driver initialization code
        $driverInitCode = $this->generateDriverInitCode($dbType);

        $script = <<<PHP
    <?php
    // Autoloader paths
    \$autoloaders = [
        __DIR__ . '/vendor/autoload.php',
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/../../../vendor/autoload.php',
    ];
    
    foreach (\$autoloaders as \$autoloader) {
        if (file_exists(\$autoloader)) {
            require \$autoloader;
            break;
        }
    }
    
    use OnlyPHP\SimpleQueue\QueueWorker;
    use {$namespace};
    
    try {
        // Parse command line options
        \$options = getopt('', ['driver-config:', 'config:']);
        
        if (!isset(\$options['driver-config']) || !isset(\$options['config'])) {
            throw new Exception('Missing required options: driver-config and config');
        }
    
        \$config = json_decode(\$options['config'], true);
        \$driverConfig = json_decode(\$options['driver-config'], true);
        
        if (!isset(\$driverConfig['driver']) || !isset(\$driverConfig['connection'])) {
            throw new Exception('Invalid driver configuration');
        }
    
        \$connConfig = json_decode(\$driverConfig['connection'], true);
    
        {$driverInitCode}
    
        // Initialize and start the queue worker
        \$worker = new QueueWorker(\$driver, \$config);
        \$worker->start();
    
    } catch (Exception \$e) {
        error_log('Worker Error: ' . \$e->getMessage());
        exit(1);
    }
    PHP;

        file_put_contents($path, $script);
        chmod($path, 0755);
        return $path;
    }

    private function generateDriverInitCode(string $dbType): string
    {
        switch ($dbType) {
            case 'mysql':
            case 'mssql':
            case 'oci':
            case 'pdo':
                return <<<'CODE'
        $connection = new PDO(
            $connConfig['dsn'],
            $connConfig['username'] ?? null,
            $connConfig['password'] ?? null
        );
        $driver = new $driverConfig['driver']($connection);
    CODE;

            case 'codeigniter3':
                return <<<'CODE'
        // Load CI3 environment
        define('BASEPATH', realpath(__DIR__ . '/../../system/'));
        define('APPPATH', realpath(__DIR__ . '/../../application/'));
        require_once BASEPATH . 'core/CodeIgniter.php';
        
        $CI = &get_instance();
        $CI->load->database([
            'hostname' => $connConfig['hostname'],
            'username' => $connConfig['username'],
            'password' => $connConfig['password'],
            'database' => $connConfig['database'],
            'dbdriver' => $connConfig['dbdriver'],
            'port'     => $connConfig['port']
        ]);
        $driver = new Codeigniter3Driver($CI->db);
    CODE;

            case 'codeigniter4':
                return <<<'CODE'
        // Load CI4 environment
        $app = require __DIR__ . '/../../app/Config/Paths.php';
        $config = new \Config\Database();
        $config->default = [
            'hostname' => $connConfig['hostname'],
            'username' => $connConfig['username'],
            'password' => $connConfig['password'],
            'database' => $connConfig['database'],
            'DBDriver' => $connConfig['driver'],
            'port'     => $connConfig['port']
        ];
        $db = \CodeIgniter\Database\Config::connect('default');
        $driver = new Codeigniter4Driver($db);
    CODE;

            case 'laravel':
                return <<<'CODE'
        // Load Laravel environment
        $app = require __DIR__ . '/../../bootstrap/app.php';
        $app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
        
        config(['database.connections.worker' => [
            'driver'    => $connConfig['driver'],
            'host'      => $connConfig['host'],
            'port'      => $connConfig['port'],
            'database'  => $connConfig['database'],
            'username'  => $connConfig['username'],
            'password'  => $connConfig['password']
        ]]);
    
        $db = $app->make('db')->connection('worker');
        $driver = new LaravelDriver($db);
    CODE;

            default:
                throw new Exception("Unsupported database type: {$dbType}");
        }
    }

    private function buildWorkerCommand($workerScript)
    {
        $config = escapeshellarg(json_encode($this->config));
        $driverConfig = escapeshellarg(json_encode([
            'driver' => $this->dbDriver,
            'connection' => $this->getConnectionString()
        ]));

        return sprintf(
            'php %s --driver-config=%s --config=%s > /dev/null 2>&1 & echo $!',
            escapeshellarg($workerScript),
            $driverConfig,
            $config
        );
    }

    /**
     * Get connection string based on driver type
     *
     * @return string
     */
    private function getConnectionString(): string
    {
        $connection = $this->db->getConnection();
        if (!$connection) {
            throw new RuntimeException('No active database connection');
        }

        switch ($this->dbDriver) {
            case PDODriver::class:
            case MySQLDriver::class:
            case MSSQLDriver::class:
            case OciDriver::class:
                // For PDO-based drivers, extract DSN and credentials
                return json_encode([
                    'dsn' => $connection->getAttribute(PDO::ATTR_CONNECTION_STATUS),
                    'username' => $this->config['username'] ?? null,
                    'password' => $this->config['password'] ?? null,
                ]);

            case Codeigniter3Driver::class:
                // Extract CI3 database settings
                return json_encode([
                    'hostname' => $connection->hostname,
                    'username' => $connection->username,
                    'password' => $connection->password,
                    'database' => $connection->database,
                    'dbdriver' => $connection->dbdriver,
                    'port' => $connection->port,
                ]);

            case Codeigniter4Driver::class:
                // Extract CI4 database settings
                $dbConfig = $connection->getConfig();
                return json_encode([
                    'hostname' => $dbConfig['hostname'],
                    'username' => $dbConfig['username'],
                    'password' => $dbConfig['password'],
                    'database' => $dbConfig['database'],
                    'driver' => $dbConfig['DBDriver'],
                    'port' => $dbConfig['port'],
                ]);

            case LaravelDriver::class:
                // Extract Laravel connection settings
                $config = $connection->getConfig();
                return json_encode([
                    'driver' => $config['driver'],
                    'host' => $config['host'],
                    'port' => $config['port'],
                    'database' => $config['database'],
                    'username' => $config['username'],
                    'password' => $config['password'],
                ]);

            default:
                throw new RuntimeException('Unsupported driver for connection string generation');
        }
    }
}