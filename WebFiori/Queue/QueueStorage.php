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
 * Interface for queue storage backends.
 *
 * Implementations persist QueuedJob objects and retrieve them for processing.
 * The storage layer never interprets the job payload — it treats it as an
 * opaque string. Serialization and encryption are handled by the Queue class.
 */
interface QueueStorage {
    /**
     * Remove all failed jobs permanently.
     */
    public function flush(): void;
    /**
     * Returns all failed jobs.
     *
     * @return QueuedJob[] Array of failed queued jobs.
     */
    public function getFailed(): array;
    /**
     * Returns the number of pending jobs.
     *
     * @return int
     */
    public function getPendingCount(): int;
    /**
     * Remove a completed job from the pending queue.
     *
     * Called after a job's handle() method succeeds.
     *
     * @param string $id The job identifier.
     */
    public function markComplete(string $id): void;
    /**
     * Move a job from pending to the failed queue.
     *
     * Called when all retry attempts are exhausted.
     *
     * @param QueuedJob $job The failed job with failReason and attempts set.
     */
    public function markFailed(QueuedJob $job): void;
    /**
     * Retrieve the next available jobs from the pending queue.
     *
     * Must return jobs where:
     * 1. availableAt <= current time (not delayed)
     * 2. Sorted by priority descending (highest first)
     * 3. Limited to $limit count
     *
     * @param int $limit Maximum number of jobs to retrieve.
     *
     * @return QueuedJob[] Array of available queued jobs.
     */
    public function pop(int $limit = 10): array;
    /**
     * Store a queued job in the pending queue.
     *
     * @param QueuedJob $job The job entry to store.
     */
    public function push(QueuedJob $job): void;
    /**
     * Move a failed job back to the pending queue for reprocessing.
     *
     * @param string $id The job identifier.
     */
    public function retry(string $id): void;
}
