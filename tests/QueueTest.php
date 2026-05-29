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
}
