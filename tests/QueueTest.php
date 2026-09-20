<?php

namespace WebFiori\Queue\Tests;

use PHPUnit\Framework\TestCase;
use WebFiori\Queue\FileQueueStorage;
use WebFiori\Queue\Job;
use WebFiori\Queue\Queue;
use WebFiori\Queue\QueuedJob;
use WebFiori\Queue\QueueFacade;

class SuccessJob implements Job {
    public bool $handled = false;

    public function handle(): void {
        $this->handled = true;
    }

    public function getMaxAttempts(): int {
        return 3;
    }

    public function getRetryDelaySeconds(): int {
        return 1;
    }
}

class FailingJob implements Job {
    public int $handleCount = 0;

    public function handle(): void {
        $this->handleCount++;

        throw new \RuntimeException('Job failed');
    }

    public function getMaxAttempts(): int {
        return 2;
    }

    public function getRetryDelaySeconds(): int {
        return 0;
    }
}


class AlwaysFailsJob implements Job {
    public function handle(): void { throw new \RuntimeException('Always fails'); }
    public function getMaxAttempts(): int { return 2; }
    public function getRetryDelaySeconds(): int { return 0; }
}

class CountingJob implements Job {
    public static int $count = 0;
    public function handle(): void { self::$count++; }
    public function getMaxAttempts(): int { return 1; }
    public function getRetryDelaySeconds(): int { return 0; }
}

class LowPriorityJob implements Job {
    public function handle(): void { PriorityLog::$log[] = 'low'; }
    public function getMaxAttempts(): int { return 1; }
    public function getRetryDelaySeconds(): int { return 0; }
}

class HighPriorityJob implements Job {
    public function handle(): void { PriorityLog::$log[] = 'high'; }
    public function getMaxAttempts(): int { return 1; }
    public function getRetryDelaySeconds(): int { return 0; }
}

class PriorityLog {
    public static array $log = [];
}

class QueueTest extends TestCase {
    private string $storageDir;
    private Queue $queue;

    protected function setUp(): void {
        $this->storageDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wf_queue_test_'.getmypid();
        $this->queue = new Queue(new FileQueueStorage($this->storageDir));
    }

    protected function tearDown(): void {
        $this->removeDir($this->storageDir);
    }
    /**
     * @test
     */
    public function testDispatchAddsJobToQueue() {
        $this->queue->dispatch(new SuccessJob());
        $this->assertEquals(1, $this->queue->getPendingCount());
    }
    /**
     * @test
     */
    public function testDispatchReturnsId() {
        $id = $this->queue->dispatch(new SuccessJob());
        $this->assertNotEmpty($id);
        $this->assertEquals(32, strlen($id));
    }
    /**
     * @test
     */
    public function testProcessExecutesJob() {
        $this->queue->dispatch(new SuccessJob());
        $processed = $this->queue->process();

        $this->assertEquals(1, $processed);
        $this->assertEquals(0, $this->queue->getPendingCount());
    }
    /**
     * @test
     */
    public function testProcessMultipleJobs() {
        $this->queue->dispatch(new SuccessJob());
        $this->queue->dispatch(new SuccessJob());
        $this->queue->dispatch(new SuccessJob());

        $processed = $this->queue->process(10);
        $this->assertEquals(3, $processed);
        $this->assertEquals(0, $this->queue->getPendingCount());
    }
    /**
     * @test
     */
    public function testProcessRespectsLimit() {
        $this->queue->dispatch(new SuccessJob());
        $this->queue->dispatch(new SuccessJob());
        $this->queue->dispatch(new SuccessJob());

        $processed = $this->queue->process(2);
        $this->assertEquals(2, $processed);
        $this->assertEquals(1, $this->queue->getPendingCount());
    }
    /**
     * @test
     */
    public function testFailingJobMovesToFailed() {
        $this->queue->dispatch(new FailingJob());

        // First attempt
        $this->queue->process();
        // Job re-queued with delay=0, still pending
        $this->assertEquals(1, $this->queue->getPendingCount());

        // Second attempt (max=2), should fail permanently
        $this->queue->process();
        $this->assertEquals(0, $this->queue->getPendingCount());

        $failed = $this->queue->getFailed();
        $this->assertCount(1, $failed);
        $this->assertStringContainsString('Job failed', $failed[0]->getFailReason());
    }
    /**
     * @test
     */
    public function testRetryMovesFailedToPending() {
        $this->queue->dispatch(new FailingJob());
        $this->queue->process();
        $this->queue->process();

        $failed = $this->queue->getFailed();
        $this->assertCount(1, $failed);

        $this->queue->retry($failed[0]->getId());
        $this->assertEquals(1, $this->queue->getPendingCount());
        $this->assertCount(0, $this->queue->getFailed());
    }
    /**
     * @test
     */
    public function testFlushClearsFailedJobs() {
        $this->queue->dispatch(new FailingJob());
        $this->queue->process();
        $this->queue->process();

        $this->assertCount(1, $this->queue->getFailed());
        $this->queue->flush();
        $this->assertCount(0, $this->queue->getFailed());
    }
    /**
     * @test
     */
    public function testPriorityOrdering() {
        $this->queue->dispatch(new SuccessJob(), 1);
        $this->queue->dispatch(new SuccessJob(), 10);
        $this->queue->dispatch(new SuccessJob(), 5);

        $storage = $this->queue->getStorage();
        $jobs = $storage->pop(3);

        $this->assertEquals(10, $jobs[0]->getPriority());
        $this->assertEquals(5, $jobs[1]->getPriority());
        $this->assertEquals(1, $jobs[2]->getPriority());
    }
    /**
     * @test
     */
    public function testDelayedJobNotProcessedEarly() {
        $this->queue->dispatch(new SuccessJob(), 0, 3600);

        $processed = $this->queue->process();
        $this->assertEquals(0, $processed);
        $this->assertEquals(1, $this->queue->getPendingCount());
    }
    /**
     * @test
     */
    public function testGetStorage() {
        $this->assertInstanceOf(FileQueueStorage::class, $this->queue->getStorage());
    }
    /**
     * @test
     */
    public function testEmptyQueueProcessReturnsZero() {
        $this->assertEquals(0, $this->queue->process());
    }

    /**
     * @test
     */
    public function testEncryptedPayload() {
        putenv('QUEUE_KEY=abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789');
        $encDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wf_queue_enc_'.getmypid();
        $queue = new Queue(new FileQueueStorage($encDir));

        $queue->dispatch(new SuccessJob());

        // Verify file content is encrypted (not readable serialized PHP)
        $files = glob($encDir.DIRECTORY_SEPARATOR.'pending'.DIRECTORY_SEPARATOR.'*.json');
        $data = json_decode(file_get_contents($files[0]), true);
        $this->assertStringNotContainsString('SuccessJob', $data['payload']);

        // But processing still works (decrypts transparently)
        $processed = $queue->process();
        $this->assertEquals(1, $processed);

        putenv('QUEUE_KEY');
        $this->removeDir($encDir);
    }
    /**
     * @test
     */
    public function testNoKeyMeansNoEncryption() {
        putenv('QUEUE_KEY');
        $plainDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wf_queue_plain_'.getmypid();
        $queue = new Queue(new FileQueueStorage($plainDir));

        $queue->dispatch(new SuccessJob());

        $files = glob($plainDir.DIRECTORY_SEPARATOR.'pending'.DIRECTORY_SEPARATOR.'*.json');
        $data = json_decode(file_get_contents($files[0]), true);
        $this->assertStringContainsString('SuccessJob', $data['payload']);

        $this->removeDir($plainDir);
    }

    private function removeDir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        rmdir($dir);
    }
    /**
     * @test
     */
    public function testMaxAttemptsExhaustedMovesToFailed() {
        $this->queue->dispatch(new AlwaysFailsJob());
        $this->queue->process(); // attempt 1 → re-queued
        $this->queue->process(); // attempt 2 → failed

        $this->assertEquals(0, $this->queue->getPendingCount());
        $failed = $this->queue->getFailed();
        $this->assertCount(1, $failed);
        $this->assertStringContainsString('RuntimeException', $failed[0]->getFailReason());
        $this->assertStringContainsString('Always fails', $failed[0]->getFailReason());
        $this->assertEquals(2, $failed[0]->getAttempts());
    }
    /**
     * @test
     */
    public function testGetPendingCountAccurate() {
        CountingJob::$count = 0;
        $this->assertEquals(0, $this->queue->getPendingCount());
        $this->queue->dispatch(new CountingJob());
        $this->assertEquals(1, $this->queue->getPendingCount());
        $this->queue->dispatch(new CountingJob());
        $this->assertEquals(2, $this->queue->getPendingCount());
        $this->queue->process();
        $this->assertEquals(0, $this->queue->getPendingCount());
        $this->assertEquals(2, CountingJob::$count);
    }
    /**
     * @test
     */
    public function testGetFailedPreservesReason() {
        $this->queue->dispatch(new AlwaysFailsJob());
        $this->queue->process();
        $this->queue->process();

        $failed = $this->queue->getFailed();
        $this->assertCount(1, $failed);
        $this->assertStringContainsString('Always fails', $failed[0]->getFailReason());
        $this->assertNotEmpty($failed[0]->getId());
    }
    /**
     * @test
     */
    public function testPriorityOrderingHighFirst() {
        PriorityLog::$log = [];
        $this->queue->dispatch(new LowPriorityJob(), 1);
        $this->queue->dispatch(new HighPriorityJob(), 10);
        $this->queue->process();

        $this->assertEquals(['high', 'low'], PriorityLog::$log);
    }
    /**
     * @test
     */
    public function testProcessReturnsCorrectCount() {
        CountingJob::$count = 0;
        $this->queue->dispatch(new CountingJob());
        $this->queue->dispatch(new CountingJob());
        $this->queue->dispatch(new CountingJob());

        $processed = $this->queue->process();
        $this->assertEquals(3, $processed);
    }
    /**
     * @test
     */
    public function testFlushOnlyRemovesFailed() {
        CountingJob::$count = 0;
        $this->queue->dispatch(new CountingJob());
        $this->queue->dispatch(new AlwaysFailsJob());
        $this->queue->process();
        $this->queue->process(); // exhaust retries

        $this->assertCount(1, $this->queue->getFailed());
        $this->queue->flush();
        $this->assertCount(0, $this->queue->getFailed());
    }
    /**
     * @test
     */
    public function testGetPendingReturnsAllJobs() {
        $this->queue->dispatch(new SuccessJob());
        $this->queue->dispatch(new SuccessJob());
        $this->queue->dispatch(new SuccessJob());

        $pending = $this->queue->getPending();
        $this->assertCount(3, $pending);

        foreach ($pending as $job) {
            $this->assertInstanceOf(QueuedJob::class, $job);
        }
    }
    /**
     * @test
     */
    public function testGetPendingIncludesDelayedJobs() {
        $this->queue->dispatch(new SuccessJob(), 0, 0);
        $this->queue->dispatch(new SuccessJob(), 0, 3600); // delayed 1 hour

        $pending = $this->queue->getPending();
        $this->assertCount(2, $pending);
    }
    /**
     * @test
     */
    public function testGetPendingReturnsEmptyWhenNoJobs() {
        $pending = $this->queue->getPending();
        $this->assertCount(0, $pending);
        $this->assertSame([], $pending);
    }
    /**
     * @test
     */
    public function testGetPendingDoesNotRemoveJobs() {
        $this->queue->dispatch(new SuccessJob());
        $this->queue->dispatch(new SuccessJob());

        $this->queue->getPending();
        $this->assertEquals(2, $this->queue->getPendingCount());

        // Call again — still 2
        $this->queue->getPending();
        $this->assertEquals(2, $this->queue->getPendingCount());
    }
    /**
     * @test
     */
    public function testGetPendingSortedByPriority() {
        $this->queue->dispatch(new LowPriorityJob(), 1);
        $this->queue->dispatch(new HighPriorityJob(), 10);
        $this->queue->dispatch(new CountingJob(), 5);

        $pending = $this->queue->getPending();
        $this->assertCount(3, $pending);
        $this->assertEquals(10, $pending[0]->getPriority());
        $this->assertEquals(5, $pending[1]->getPriority());
        $this->assertEquals(1, $pending[2]->getPriority());
    }
    /**
     * @test
     */
    public function testGetPendingThrowsOnNonListableStorage() {
        $mockStorage = new class implements \WebFiori\Queue\QueueStorage {
            public function flush(): void {}
            public function getFailed(): array { return []; }
            public function getPendingCount(): int { return 0; }
            public function markComplete(string $id): void {}
            public function markFailed(QueuedJob $job): void {}
            public function pop(int $limit = 10): array { return []; }
            public function push(QueuedJob $job): void {}
            public function retry(string $id): void {}
        };

        $queue = new Queue($mockStorage);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('does not support listing pending jobs');
        $queue->getPending();
    }

    /**
     * @test
     * The onError callback fires on a terminal failure with willRetry = false.
     */
    public function testOnErrorInvokedOnTerminalFailure() {
        $calls = [];
        $this->queue->setOnError(function ($job, $e, $attempts, $willRetry) use (&$calls) {
            $calls[] = [$job, $e, $attempts, $willRetry];
        });

        $this->queue->dispatch(new AlwaysFailsJob()); // maxAttempts = 2
        $this->queue->process(); // attempt 1 -> retry
        $this->queue->process(); // attempt 2 -> terminal

        $this->assertCount(2, $calls);

        // First call: intermediate retry.
        $this->assertInstanceOf(AlwaysFailsJob::class, $calls[0][0]);
        $this->assertInstanceOf(\RuntimeException::class, $calls[0][1]);
        $this->assertSame(1, $calls[0][2]);
        $this->assertTrue($calls[0][3]);

        // Second call: terminal.
        $this->assertSame(2, $calls[1][2]);
        $this->assertFalse($calls[1][3]);
        $this->assertSame('Always fails', $calls[1][1]->getMessage());
    }

    /**
     * @test
     * With no callback configured, processing behaves exactly as before.
     */
    public function testNoOnErrorCallbackKeepsDefaultBehavior() {
        $this->queue->dispatch(new AlwaysFailsJob());
        $this->queue->process();
        $this->queue->process();

        $this->assertEquals(0, $this->queue->getPendingCount());
        $this->assertCount(1, $this->queue->getFailed());
    }

    /**
     * @test
     * A non-Job payload is reported to the callback as a terminal failure
     * with a null job.
     */
    public function testOnErrorInvokedForInvalidPayload() {
        $captured = null;
        $this->queue->setOnError(function ($job, $e, $attempts, $willRetry) use (&$captured) {
            $captured = [$job, $willRetry];
        });

        // Push a payload that is not a serialized Job.
        $this->queue->getStorage()->push(new QueuedJob('bad-id', serialize('not a job'), 0, 0, 0));
        $this->queue->process();

        $this->assertNotNull($captured);
        $this->assertNull($captured[0]);
        $this->assertFalse($captured[1]);
        $this->assertCount(1, $this->queue->getFailed());
    }

    /**
     * @test
     * setOnError is chainable and exposed via getOnError.
     */
    public function testSetOnErrorChainableAndGettable() {
        $cb = function () {
        };
        $this->assertSame($this->queue, $this->queue->setOnError($cb));
        $this->assertSame($cb, $this->queue->getOnError());
        $this->queue->setOnError(null);
        $this->assertNull($this->queue->getOnError());
    }

    /**
     * @test
     * The facade exposes setOnError, delegating to the default instance.
     */
    public function testFacadeSetOnErrorPassthrough() {
        QueueFacade::reset();
        $cb = function () {
        };
        QueueFacade::setOnError($cb);
        $this->assertSame($cb, QueueFacade::getInstance()->getOnError());
        QueueFacade::reset();
    }
}
