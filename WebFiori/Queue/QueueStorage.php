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
 */
interface QueueStorage {
    /**
     * Remove all failed jobs.
     */
    public function flush(): void;
    /**
     * Returns all failed jobs.
     *
     * @return array Array of associative arrays with keys: id, payload, reason, attempts.
     */
    public function getFailed(): array;
    /**
     * Returns the number of pending jobs.
     *
     * @return int
     */
    public function getPendingCount(): int;
    /**
     * Mark a job as completed and remove it from the queue.
     *
     * @param string $id The job identifier.
     */
    public function markComplete(string $id): void;
    /**
     * Mark a job as failed.
     *
     * @param string $id The job identifier.
     * @param string $reason The failure reason.
     * @param int $attempts Number of attempts made.
     */
    public function markFailed(string $id, string $reason, int $attempts): void;
    /**
     * Pop the next available jobs from the queue.
     *
     * @param int $limit Maximum number of jobs to retrieve.
     *
     * @return array Array of associative arrays with keys: id, payload, attempts, priority.
     */
    public function pop(int $limit = 10): array;
    /**
     * Push a job payload onto the queue.
     *
     * @param string $id Unique job identifier.
     * @param string $payload Serialized job data.
     * @param int $priority Job priority (higher = processed first).
     * @param int $availableAt Unix timestamp when the job becomes available.
     */
    public function push(string $id, string $payload, int $priority = 0, int $availableAt = 0): void;
    /**
     * Retry a failed job by moving it back to the pending queue.
     *
     * @param string $id The job identifier.
     */
    public function retry(string $id): void;
    /**
     * Update the attempt count for a pending job.
     *
     * @param string $id The job identifier.
     * @param int $attempts The new attempt count.
     */
    public function setAttempts(string $id, int $attempts): void;
}
