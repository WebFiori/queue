<?php

/**
 * Example: Dispatching and processing jobs.
 *
 * This example demonstrates the basic queue workflow:
 * 1. Create a job class that implements the Job interface
 * 2. Create a queue with a storage backend
 * 3. Dispatch jobs to the queue
 * 4. Process pending jobs (typically done by a scheduler or worker)
 */
require_once __DIR__.'/../vendor/autoload.php';

use WebFiori\Queue\FileQueueStorage;
use WebFiori\Queue\Job;
use WebFiori\Queue\Queue;

/**
 * A job that simulates sending an email.
 *
 * Every job must implement the Job interface which requires three methods:
 * - handle(): The actual work the job performs
 * - getMaxAttempts(): How many times to retry if handle() throws an exception
 * - getRetryDelaySeconds(): Base delay between retries (multiplied by attempt number)
 */
class SendEmailJob implements Job {
    private string $to;
    private string $subject;

    /**
     * Constructor receives the data needed to perform the job.
     * This data is serialized when the job is stored in the queue,
     * and deserialized when the job is processed.
     */
    public function __construct(string $to, string $subject) {
        $this->to = $to;
        $this->subject = $subject;
    }

    /**
     * This method contains the actual work.
     * It is called by Queue::process() when the job is picked up.
     * If this method throws an exception, the job will be retried
     * up to getMaxAttempts() times.
     */
    public function handle(): void {
        echo "Sending email to {$this->to}: {$this->subject}\n";
    }

    /**
     * If handle() fails, the queue will retry up to this many times.
     * After all attempts are exhausted, the job moves to the failed queue.
     */
    public function getMaxAttempts(): int {
        return 3;
    }

    /**
     * Delay between retries in seconds.
     * The actual delay is: getRetryDelaySeconds() * attemptNumber
     * So with 60 seconds: 1st retry after 60s, 2nd after 120s, 3rd after 180s.
     */
    public function getRetryDelaySeconds(): int {
        return 60;
    }
}

// --- Step 1: Create a queue with file-based storage ---
// FileQueueStorage stores each job as a JSON file in the given directory.
// Two subdirectories are created automatically: 'pending/' and 'failed/'
$queue = new Queue(new FileQueueStorage(__DIR__.'/queue-storage'));

// --- Step 2: Dispatch jobs ---
// dispatch() serializes the job object and stores it in the queue.
// It returns a unique job ID (32-character hex string).
// The job is NOT executed here — it's just stored for later processing.
$queue->dispatch(new SendEmailJob('user@example.com', 'Welcome!'));

// Jobs can have priority. Higher priority = processed first.
// Default priority is 0.
$queue->dispatch(new SendEmailJob('admin@example.com', 'New signup'), 10);

// Jobs can be delayed. This job won't be available for processing
// until 300 seconds (5 minutes) from now.
$queue->dispatch(new SendEmailJob('support@example.com', 'Ticket update'), 0, 0);

echo "Pending jobs: ".$queue->getPendingCount()."\n\n";

// --- Step 3: Process pending jobs ---
// process() picks up available jobs (sorted by priority), deserializes them,
// and calls handle() on each one.
// The $limit parameter controls how many jobs to process in one call.
// In production, this is typically called by a scheduler task every minute.
echo "Processing...\n";
$processed = $queue->process();
echo "Processed: $processed jobs\n";
echo "Remaining: ".$queue->getPendingCount()."\n";

// --- Cleanup (for this example only) ---
array_map('unlink', glob(__DIR__.'/queue-storage/pending/*.json'));
array_map('unlink', glob(__DIR__.'/queue-storage/failed/*.json'));
rmdir(__DIR__.'/queue-storage/pending');
rmdir(__DIR__.'/queue-storage/failed');
rmdir(__DIR__.'/queue-storage');
