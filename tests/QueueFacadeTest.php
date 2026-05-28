<?php

namespace WebFiori\Queue\Tests;

use PHPUnit\Framework\TestCase;
use WebFiori\Queue\FileQueueStorage;
use WebFiori\Queue\Queue;
use WebFiori\Queue\QueueFacade;

class QueueFacadeTest extends TestCase {
    protected function setUp(): void {
        QueueFacade::reset();
    }
    /**
     * @test
     */
    public function testGetInstanceReturnsQueue() {
        $this->assertInstanceOf(Queue::class, QueueFacade::getInstance());
    }
    /**
     * @test
     */
    public function testSetInstance() {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wf_qf_test_'.getmypid();
        $custom = new Queue(new FileQueueStorage($dir));
        QueueFacade::setInstance($custom);
        $this->assertSame($custom, QueueFacade::getInstance());

        // Cleanup
        $this->removeDir($dir);
    }
    /**
     * @test
     */
    public function testFacadeDispatchAndProcess() {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wf_qf_dp_'.getmypid();
        QueueFacade::setInstance(new Queue(new FileQueueStorage($dir)));

        $id = QueueFacade::dispatch(new SuccessJob());
        $this->assertNotEmpty($id);
        $this->assertEquals(1, QueueFacade::getPendingCount());

        $processed = QueueFacade::process();
        $this->assertEquals(1, $processed);
        $this->assertEquals(0, QueueFacade::getPendingCount());

        $this->removeDir($dir);
    }
    /**
     * @test
     */
    public function testFacadeFlush() {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wf_qf_fl_'.getmypid();
        QueueFacade::setInstance(new Queue(new FileQueueStorage($dir)));

        QueueFacade::dispatch(new FailingJob());
        QueueFacade::process();
        QueueFacade::process();

        $this->assertCount(1, QueueFacade::getFailed());
        QueueFacade::flush();
        $this->assertCount(0, QueueFacade::getFailed());

        $this->removeDir($dir);
    }
    /**
     * @test
     */
    public function testFacadeRetry() {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wf_qf_rt_'.getmypid();
        QueueFacade::setInstance(new Queue(new FileQueueStorage($dir)));

        QueueFacade::dispatch(new FailingJob());
        QueueFacade::process();
        QueueFacade::process();

        $failed = QueueFacade::getFailed();
        QueueFacade::retry($failed[0]['id']);
        $this->assertEquals(1, QueueFacade::getPendingCount());

        $this->removeDir($dir);
    }
    /**
     * @test
     */
    public function testResetCreatesNewInstance() {
        $first = QueueFacade::getInstance();
        QueueFacade::reset();
        $second = QueueFacade::getInstance();
        $this->assertNotSame($first, $second);
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
