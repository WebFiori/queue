# WebFiori Queue

A lightweight job queue library for PHP with file-based storage, priority ordering, and retry logic.

<p align="center">
  <a href="https://github.com/WebFiori/queue/actions"><img src="https://github.com/WebFiori/queue/actions/workflows/php84.yaml/badge.svg?branch=main"></a>
  <a href="https://codecov.io/gh/WebFiori/queue">
    <img src="https://codecov.io/gh/WebFiori/queue/branch/main/graph/badge.svg" />
  </a>
  <a href="https://sonarcloud.io/dashboard?id=WebFiori_queue">
      <img src="https://sonarcloud.io/api/project_badges/measure?project=WebFiori_queue&metric=alert_status" />
  </a>
  <a href="https://github.com/WebFiori/queue/releases">
      <img src="https://img.shields.io/github/release/WebFiori/queue.svg?label=latest" />
  </a>
  <a href="https://packagist.org/packages/webfiori/queue">
      <img src="https://img.shields.io/packagist/dt/webfiori/queue?color=light-green">
  </a>
</p>

## Supported PHP Versions

This library requires **PHP 8.1 or higher**.

|                                                                                        Build Status                                                                                        |
|:------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------:|
| <a target="_blank" href="https://github.com/WebFiori/queue/actions/workflows/php81.yaml"><img src="https://github.com/WebFiori/queue/actions/workflows/php81.yaml/badge.svg?branch=main"></a>  |
| <a target="_blank" href="https://github.com/WebFiori/queue/actions/workflows/php82.yaml"><img src="https://github.com/WebFiori/queue/actions/workflows/php82.yaml/badge.svg?branch=main"></a>  |
| <a target="_blank" href="https://github.com/WebFiori/queue/actions/workflows/php83.yaml"><img src="https://github.com/WebFiori/queue/actions/workflows/php83.yaml/badge.svg?branch=main"></a>  |
| <a target="_blank" href="https://github.com/WebFiori/queue/actions/workflows/php84.yaml"><img src="https://github.com/WebFiori/queue/actions/workflows/php84.yaml/badge.svg?branch=main"></a>  |
| <a target="_blank" href="https://github.com/WebFiori/queue/actions/workflows/php85.yaml"><img src="https://github.com/WebFiori/queue/actions/workflows/php85.yaml/badge.svg?branch=main"></a>  |

## Features

- **Job interface** — define units of work with retry configuration
- **FileQueueStorage** — file-based backend, zero infrastructure needed
- **Priority ordering** — higher priority jobs processed first
- **Delayed dispatch** — schedule jobs to run after a delay
- **Automatic retry** — failed jobs re-queued with configurable backoff
- **Failed job tracking** — inspect and retry failed jobs
- **Static facade** (`QueueFacade`) for quick usage without DI
- **Zero dependencies** — requires only PHP 8.1+

## Installation

```bash
composer require webfiori/queue
```

## Usage

### Define a Job

```php
use WebFiori\Queue\Job;

class SendEmailJob implements Job {
    public function __construct(
        private string $to,
        private string $subject
    ) {}

    public function handle(): void {
        // Send the email
        mail($this->to, $this->subject, 'Hello!');
    }

    public function getMaxAttempts(): int {
        return 3;
    }

    public function getRetryDelaySeconds(): int {
        return 60; // wait 60s × attempt number before retry
    }
}
```

### Dispatch and Process

```php
use WebFiori\Queue\FileQueueStorage;
use WebFiori\Queue\Queue;

$queue = new Queue(new FileQueueStorage('/path/to/storage'));

// Dispatch jobs
$queue->dispatch(new SendEmailJob('user@example.com', 'Welcome!'));
$queue->dispatch(new SendEmailJob('vip@example.com', 'Priority!'), priority: 10);
$queue->dispatch(new SendEmailJob('later@example.com', 'Delayed'), delaySeconds: 300);

// Process pending jobs (call this from a scheduler or worker)
$processed = $queue->process(limit: 50);
```

### Static Facade

```php
use WebFiori\Queue\QueueFacade;

QueueFacade::dispatch(new SendEmailJob('user@example.com', 'Hello'));
QueueFacade::process();
```

### Failed Jobs

```php
// View failed jobs
$failed = $queue->getFailed();

// Retry a specific failed job
$queue->retry($failed[0]['id']);

// Clear all failed jobs
$queue->flush();
```

## API

### `Job` (interface)

| Method | Description |
|--------|-------------|
| `handle(): void` | Execute the job logic |
| `getMaxAttempts(): int` | Maximum retry attempts |
| `getRetryDelaySeconds(): int` | Base delay between retries (multiplied by attempt number) |

### `Queue`

| Method | Description |
|--------|-------------|
| `__construct(QueueStorage $storage)` | Create queue with storage backend |
| `dispatch(Job $job, int $priority = 0, int $delaySeconds = 0): string` | Add job to queue, returns job ID |
| `process(int $limit = 10): int` | Process pending jobs, returns count processed |
| `retry(string $id): void` | Retry a failed job |
| `getPendingCount(): int` | Number of pending jobs |
| `getFailed(): array` | All failed jobs |
| `flush(): void` | Remove all failed jobs |
| `getStorage(): QueueStorage` | Get the storage backend |

### `QueueStorage` (interface)

| Method | Description |
|--------|-------------|
| `push(string $id, string $payload, int $priority, int $availableAt): void` | Store a job |
| `pop(int $limit): array` | Retrieve available jobs |
| `markComplete(string $id): void` | Remove completed job |
| `markFailed(string $id, string $reason, int $attempts): void` | Move to failed |
| `setAttempts(string $id, int $attempts): void` | Update attempt count |
| `retry(string $id): void` | Move failed job back to pending |
| `getPendingCount(): int` | Count pending jobs |
| `getFailed(): array` | Get all failed jobs |
| `flush(): void` | Clear failed jobs |

### `QueueFacade`

Static wrapper. Same methods as `Queue` plus `getInstance()`, `setInstance()`, `reset()`.

## License

MIT
