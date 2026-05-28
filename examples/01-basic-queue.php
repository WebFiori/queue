<?php

/**
 * Example: Dispatching and processing jobs.
 */
require_once __DIR__.'/../vendor/autoload.php';

use WebFiori\Queue\FileQueueStorage;
use WebFiori\Queue\Job;
use WebFiori\Queue\Queue;

class SendEmailJob implements Job {
    private string $subject;
    private string $to;

    public function __construct(string $to, string $subject) {
        $this->to = $to;
        $this->subject = $subject;
    }

    public function getMaxAttempts(): int {
        return 3;
    }

    public function getRetryDelaySeconds(): int {
        return 60;
    }

    public function handle(): void {
        echo "Sending email to {$this->to}: {$this->subject}\n";
    }
}

// Create queue with file storage
$queue = new Queue(new FileQueueStorage(__DIR__.'/queue-storage'));

// Dispatch jobs
$queue->dispatch(new SendEmailJob('user@example.com', 'Welcome!'));
$queue->dispatch(new SendEmailJob('admin@example.com', 'New signup'), 10); // high priority
$queue->dispatch(new SendEmailJob('support@example.com', 'Ticket update'));

echo "Pending jobs: ".$queue->getPendingCount()."\n\n";

// Process all pending jobs
echo "Processing...\n";
$processed = $queue->process();
echo "Processed: $processed jobs\n";
echo "Remaining: ".$queue->getPendingCount()."\n";

// Cleanup
array_map('unlink', glob(__DIR__.'/queue-storage/pending/*.json'));
array_map('unlink', glob(__DIR__.'/queue-storage/failed/*.json'));
rmdir(__DIR__.'/queue-storage/pending');
rmdir(__DIR__.'/queue-storage/failed');
rmdir(__DIR__.'/queue-storage');
