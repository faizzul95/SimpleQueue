# PHP Simple Queue

A framework-agnostic queue system for PHP that seamlessly integrates with multiple database systems and frameworks. This package offers Laravel-inspired queue functionality without the need for cron jobs, enabling you to effortlessly dispatch jobs with various processing types, including closures, static class methods, object methods, global functions, and invokable classes. This package supports databases such as PDO, MySQL, MSSQL, Oracle and is compatible with CodeIgniter 3, CodeIgniter 4, and Laravel framework.

## ⚠️ Warning

<strong>DO NOT USE THIS PACKAGE</strong>.

This package is under active development and may contain critical bugs. It is primarily intended for personal use and testing within my own projects.

This version has not undergone rigorous testing and may be unstable or unreliable.

🛠️ Primary Focus (Testing & Development):
- CodeIgniter 3 

⏭️ Supported (Experimental):
- PDO
- MySQL
- MSSQL
- Oracle
- CodeIgniter 4
- Laravel

## ✨ Features

- 🚀 No cron jobs required - automatic worker process management
- 🔄 Automatic table creation and management
- 💪 Multiple database support (PDO, MySQL, MSSQL, Oracle, CodeIgniter 3/4, Laravel)
- ⚡ Priority queues (urgent, high, normal, low)
- 🔁 Automatic retry mechanism with customizable attempts
- ⏱️ Job timeout handling
- 📊 Job status monitoring
- 🔍 Failed job handling

## 📝 Requirements

- PHP >= 8.0

## 🔧 Installation

Install via Composer:

```bash
composer require onlyphp/simple-queue
```

## 🚀 Basic Usage

### Initialize with Different Databases

```php
use OnlyPHP\SimpleQueue\JobProcessor;

// PDO MySQL
$pdo = new PDO('mysql:host=localhost;dbname=your_database', 'username', 'password');
$queue = new JobProcessor($pdo);

// PDO MSSQL
$pdoMssql = new PDO('sqlsrv:Server=localhost;Database=your_database', 'username', 'password');
$queueMssql = new JobProcessor($pdoMssql);

// PDO Oracle
$pdoOracle = new PDO('oci:dbname=your_database', 'username', 'password');
$queueOracle = new JobProcessor($pdoOracle);

// CodeIgniter 3
$queue = new JobProcessor($this->db);

// CodeIgniter 4
$db = \Config\Database::connect();
$queue = new JobProcessor($db);

// Laravel
$queue = new JobProcessor(DB::connection());
```
## 🖥️ Priority Levels

- `urgent`: Highest priority
- `high`: High priority
- `normal`: Default priority
- `low`: Low priority

## 🎯 Examples

### Simple Jobs

```php
// Dispatch a closure
$queue->job(function() {
    echo "Processing job...";
})->dispatch();

// Dispatch a function
$queue->job('process_data', ['param1', 'param2'])->setIncludePathFile('/path/to/file.php')->dispatch();

// Dispatch a class method
$queue->job([new YourClass(), 'methodName'], $params)->dispatch();

// Execute job immediately without queueing (foreground processed) using closure/callable
$result = $queue->job($callable)->dispatchNow();
```

### Advanced Jobs

### 1. Email Processing Queue

```php
class EmailService {
    public function sendBulkEmails(array $recipients, string $template) {
        foreach ($recipients as $recipient) {
            // Process each email
        }
    }
}

// Queue the email job
$emailService = new EmailService();
$recipients = ['user1@example.com', 'user2@example.com'];

$queue->job([$emailService, 'sendBulkEmails'], $recipients)
    ->setName('bulk-email-campaign')
    ->setPriority('high')
    ->setMaxRetries(3)
    ->setRetryDelay(60)
    ->dispatch();
```

### 2. File Processing Queue

```php
class FileProcessor {
    public function processLargeFile(string $filePath) {
        // Process large file
    }
}

// Queue file processing
$processor = new FileProcessor();
$queue->job([$processor, 'processLargeFile'], $filePath)
    ->setName('large-file-processing')
    ->setTimeout(3600) // 1 hour timeout
    ->dispatch();
```

### 3. Order Processing System

```php
class OrderProcessor {
    public function processOrder(int $orderId, array $items) {
        // Process order logic
    }
}

// Queue order processing
$processor = new OrderProcessor();
$order = [
    'id' => 1234,
    'items' => ['item1', 'item2']
];

$queue->job([$processor, 'processOrder'], $order)
    ->setName("process-order-{$order['id']}")
    ->setPriority('urgent')
    ->setMaxRetries(5)
    ->setRetryDelay(30)
    ->dispatch();
```

### 4. Data Export Jobs

```php
class DataExporter {
    public function exportToCSV(string $query, string $filename) {
        // Export logic
    }
}

// Queue export job
$exporter = new DataExporter();
$queue->job([$exporter, 'exportToCSV'], $params)
    ->setName('monthly-report-export')
    ->setPriority('normal')
    ->setTimeout(7200) // 2 hours
    ->dispatch();
```

### 5. Image Processing Queue

```php
class ImageProcessor {
    public function processImage(string $path, array $options) {
        // Image processing logic
    }
}

// Queue image processing
$processor = new ImageProcessor();
$options = [
    'resize' => true,
    'width' => 800,
    'height' => 600,
    'optimize' => true
];

$queue->job([$processor, 'processImage'], $options)
    ->setName('image-processing')
    ->setPriority('low')
    ->setMaxRetries(2)
    ->dispatch();
```

### 6. Calling global function without classes

```php
function exportCsvData(string $filePath) {
    // Process to export data
}

// Queue file export processing
$queue->job('exportCsvData', $filePath)
    ->setIncludePathFile('/path/to/csv_helpers.php') // Include the file before called the function
    ->dispatch();
```
### 7. Invokable Classes

```php
class MyInvokableClass {
    public function __invoke($param1, $param2) {
        // Invokable class logic
        echo "Processing invokable class with params: $param1, $param2";
    }
}

$invokable = new MyInvokableClass();
$processor->job($invokable, ['param1', 'param2'])
    ->dispatch();
```

## ⚙️ Configuration Options

```php
$config = [
    'process_check_interval' => 1000000,  // Worker check interval (microseconds)
    'worker_timeout' => 3600,            // Worker timeout (seconds)
    'max_workers' => 1,                  // Maximum number of concurrent workers
    'lock_dir' => '/tmp'                 // Directory for worker lock files
];

$queue = new JobProcessor($connection, $config);
```

## 📊 Monitoring and Management

```php

$processor = new JobProcessor($connection, $config);

// Get queue statistics
$stats = $processor->getJobStats();
print_r($stats);
/* Output:
[
    'total_jobs' => 100,
    'pending_jobs' => 10,
    'processing_jobs' => 5,
    'completed_jobs' => 80,
    'failed_jobs' => 5,
    'avg_processing_time' => 45.5
]
*/

// Get specific job status
$jobUuId = $processor->job($callable)->dispatch();
$status = $processor->getJobStatus($jobUuId);

// Retry failed jobs
$processor->retryAllFailed();

// Clear old failed jobs
$processor->clearFailedJobs(30); // Clear jobs older than 30 days
```

## 📅 Database Tables

The package automatically creates two tables:
- `jobs` - Stores queue jobs
- `failed_jobs` - Stores failed job information

## 👍 Best Practices

1. **Job Naming**: Always set meaningful names for jobs using `setName()` for better tracking.
2. **Timeouts**: Set appropriate timeouts based on job complexity.
3. **Retries**: Configure max retries based on job criticality.
4. **Priority**: Use priorities wisely - reserve 'urgent' for truly time-critical tasks.

## 🔍 Error Handling

The package handles various types of errors:
- Connection failures
- Execution timeouts
- Runtime errors
- Worker process failures

Failed jobs are automatically logged with full stack traces in the `failed_jobs` table.

## 📛 Limitations

- Windows support is limited for signal handling
- Maximum execution time is subject to PHP's `max_execution_time` setting
- Single-server deployment only (no distributed queue support)

## 📌 To-do Improvements

1. Implement job events/hooks
2. Add support for job batching
3. Add Redis/memory queue support
4. Add job progress tracking
5. Implement job middleware support
6. Add support for unique jobs
7. Add job chaining capabilities

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

1. Fork the Project
2. Create your Feature Branch (`git checkout -b feature/AmazingFeature`)
3. Commit your Changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the Branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## 📄 License

This project is licensed under the MIT License.

## 🏷️ Changelog

<details> 
<summary>Click to view the changelog</summary>

### v1.0.0
*  Initial release.

### v1.0.1
*  Removed `SerializableClosure` helper.
*  Fixed CodeIgniter 3 table creation issues.
*  Added support for `MY_Model` as a custom model for CodeIgniter 3.
*  Added `opis/closure` library to support serializable closures.

</details>