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

## Table of Contents

- [Supported PHP Versions](#supported-php-versions)
- [Features](#features)
- [Installation](#installation)
- [Usage](#usage)
- [API](#api)
- [Testing](#testing)
- [Examples](#examples)
- [Contributing](#contributing)
- [License](#license)
- [Support](#support)
- [Changelog](#changelog)

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
// View failed jobs (each is a QueuedJob)
$failed = $queue->getFailed();

// Retry a specific failed job
$queue->retry($failed[0]->getId());

// Clear all failed jobs
$queue->flush();
```

### Observing Failures

By default, exceptions thrown by a job's `handle()` are caught and turned into
retries or a failed-job record. Register an **opt-in error callback** to observe
every caught throwable — with full exception context — for both intermediate
retries and terminal failures. This makes it easy to bridge queue failures into
a centralized logger (e.g. a globally registered `WebFiori\Error\Handler`).

```php
$queue->setOnError(function (?Job $job, \Throwable $e, int $attempts, bool $willRetry): void {
    // $job is null if the stored payload was not a valid Job instance.
    error_log(sprintf(
        '[queue] %s failed on attempt %d (%s): %s',
        $job !== null ? get_class($job) : 'invalid-payload',
        $attempts,
        $willRetry ? 'will retry' : 'terminal',
        $e->getMessage()
    ));
});
```

The failed `QueuedJob` also records richer context — its `getFailReason()` now
includes the exception class in addition to the message. Behavior is unchanged
when no callback is registered. The same callback can be set on the facade via
`QueueFacade::setOnError(...)`.

### Inspecting Pending Jobs

```php
// List all pending jobs (including delayed ones not yet available)
$pending = $queue->getPending();

foreach ($pending as $queuedJob) {
    echo sprintf(
        "ID: %s | Priority: %d | Attempts: %d | Available: %s\n",
        $queuedJob->getId(),
        $queuedJob->getPriority(),
        $queuedJob->getAttempts(),
        date('Y-m-d H:i:s', $queuedJob->getAvailableAt())
    );
}

// Quick count (works with any storage backend)
$count = $queue->getPendingCount();
```

> **Note:** `getPending()` requires a storage backend that implements `ListableQueueStorage` (e.g. `FileQueueStorage`). Backends like SQS that don't support listing will throw a `LogicException`.

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
| `getPending(): array` | All pending jobs (requires `ListableQueueStorage`) |
| `getFailed(): array` | All failed jobs |
| `flush(): void` | Remove all failed jobs |
| `getStorage(): QueueStorage` | Get the storage backend |
| `setOnError(?callable $cb): Queue` | Register a callback invoked on every caught throwable: `fn(?Job $job, \Throwable $e, int $attempts, bool $willRetry)` |
| `getOnError(): ?callable` | Get the registered error callback |

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

### `ListableQueueStorage` (interface, extends `QueueStorage`)

| Method | Description |
|--------|-------------|
| `getPending(): array` | Returns all pending jobs (including delayed), sorted by priority desc |

Backends that support listing (files, database, Redis) implement `ListableQueueStorage`. Backends that only support push/pop semantics (SQS, RabbitMQ) implement the base `QueueStorage`.

### `QueueFacade`

Static wrapper. Same methods as `Queue` plus `getInstance()`, `setInstance()`, `reset()`.

## Testing

Run the test suite with:

```bash
composer test
```

## Examples

Runnable examples are available in the [`examples/`](examples) directory:

- [`examples/01-basic-queue.php`](examples/01-basic-queue.php) — dispatching and processing jobs
- [`examples/02-custom-storage.php`](examples/02-custom-storage.php) — implementing a custom storage backend

## Contributing

Contributions are welcome. Please open an issue to discuss significant changes,
follow [Conventional Commits](https://www.conventionalcommits.org/) for commit
messages, and ensure `composer test` passes before opening a pull request.

## License

MIT

## Support

- **Issues**: [GitHub Issues](https://github.com/WebFiori/queue/issues)

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a full history of changes and releases.
