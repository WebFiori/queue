<?php

/**
 * Example: Creating a custom queue storage backend.
 *
 * This example shows an in-memory storage useful for testing.
 * The same pattern applies for database, Redis, or any other backend.
 */
require_once __DIR__.'/../vendor/autoload.php';

use WebFiori\Queue\Job;
use WebFiori\Queue\Queue;
use WebFiori\Queue\QueueStorage;

/**
 * An in-memory queue storage. Jobs are lost when the process ends.
 * Useful for testing or short-lived CLI scripts.
 */
class InMemoryQueueStorage implements QueueStorage {
    private array $pending = [];
    private array $failed = [];

    public function push(string $id, string $payload, int $priority = 0, int $availableAt = 0): void {
        $this->pending[$id] = [
            'id' => $id,
            'payload' => $payload,
            'priority' => $priority,
            'attempts' => 0,
            'available_at' => $availableAt > 0 ? $availableAt : time(),
        ];
    }

    public function pop(int $limit = 10): array {
        $available = array_filter($this->pending, fn ($j) => $j['available_at'] <= time());
        usort($available, fn ($a, $b) => $b['priority'] - $a['priority']);

        return array_slice($available, 0, $limit);
    }

    public function markComplete(string $id): void {
        unset($this->pending[$id]);
    }

    public function markFailed(string $id, string $reason, int $attempts): void {
        $data = $this->pending[$id] ?? [];
        unset($this->pending[$id]);
        $data['reason'] = $reason;
        $data['attempts'] = $attempts;
        $this->failed[$id] = $data;
    }

    public function setAttempts(string $id, int $attempts): void {
        if (isset($this->pending[$id])) {
            $this->pending[$id]['attempts'] = $attempts;
        }
    }

    public function retry(string $id): void {
        if (isset($this->failed[$id])) {
            $data = $this->failed[$id];
            unset($this->failed[$id], $data['reason']);
            $data['available_at'] = time();
            $this->pending[$id] = $data;
        }
    }

    public function getPendingCount(): int {
        return count($this->pending);
    }

    public function getFailed(): array {
        return array_values($this->failed);
    }

    public function flush(): void {
        $this->failed = [];
    }
}

// --- Usage ---

class PrintJob implements Job {
    public function __construct(private string $message) {
    }

    public function handle(): void {
        echo "Processing: {$this->message}\n";
    }

    public function getMaxAttempts(): int {
        return 3;
    }

    public function getRetryDelaySeconds(): int {
        return 5;
    }
}

$queue = new Queue(new InMemoryQueueStorage());

$queue->dispatch(new PrintJob('First task'));
$queue->dispatch(new PrintJob('High priority task'), priority: 10);
$queue->dispatch(new PrintJob('Normal task'));

echo "Pending: ".$queue->getPendingCount()."\n\n";

$processed = $queue->process();
echo "\nProcessed: $processed jobs\n";
echo "Remaining: ".$queue->getPendingCount()."\n";
