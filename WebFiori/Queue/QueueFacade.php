<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026 WebFiori Framework
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/.github/blob/main/LICENSE
 *
 */
namespace WebFiori\Queue;

/**
 * A static facade for the Queue class.
 *
 * Provides a convenient static API that delegates to a default Queue instance.
 */
class QueueFacade {
    private static ?Queue $inst = null;
    /**
     * @see Queue::dispatch()
     */
    public static function dispatch(Job $job, int $priority = 0, int $delaySeconds = 0): string {
        return self::getInstance()->dispatch($job, $priority, $delaySeconds);
    }
    /**
     * @see Queue::flush()
     */
    public static function flush(): void {
        self::getInstance()->flush();
    }
    /**
     * @see Queue::getFailed()
     */
    public static function getFailed(): array {
        return self::getInstance()->getFailed();
    }
    /**
     * Returns the default Queue instance.
     *
     * @return Queue
     */
    public static function getInstance(): Queue {
        if (self::$inst === null) {
            self::$inst = new Queue(
                new FileQueueStorage(sys_get_temp_dir().DIRECTORY_SEPARATOR.'webfiori-queue')
            );
        }

        return self::$inst;
    }
    /**
     * @see Queue::getPendingCount()
     */
    public static function getPendingCount(): int {
        return self::getInstance()->getPendingCount();
    }
    /**
     * @see Queue::process()
     */
    public static function process(int $limit = 10): int {
        return self::getInstance()->process($limit);
    }
    /**
     * Destroys the default Queue instance.
     */
    public static function reset(): void {
        self::$inst = null;
    }
    /**
     * @see Queue::retry()
     */
    public static function retry(string $id): void {
        self::getInstance()->retry($id);
    }
    /**
     * Replaces the default Queue instance.
     *
     * @param Queue $queue The queue instance to use as default.
     */
    public static function setInstance(Queue $queue): void {
        self::$inst = $queue;
    }
}
