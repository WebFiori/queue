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
 * A value object representing a job entry in the queue.
 *
 * This is the data structure that flows between the Queue and QueueStorage.
 * It holds the serialized (and possibly encrypted) job payload along with
 * metadata needed for scheduling, prioritization, and retry tracking.
 *
 * The storage layer works exclusively with QueuedJob objects — it never
 * sees or interacts with the actual Job instances.
 */
class QueuedJob {
    /**
     * @var int Number of times this job has been attempted.
     */
    private int $attempts;
    /**
     * @var int Unix timestamp when the job becomes available for processing.
     * Jobs with availableAt in the future are skipped during pop().
     */
    private int $availableAt;
    /**
     * @var int Unix timestamp when the job was first queued.
     */
    private int $createdAt;
    /**
     * @var string|null Failure reason (only set for failed jobs).
     */
    private ?string $failReason;
    /**
     * @var string Unique identifier for this queued job.
     */
    private string $id;
    /**
     * @var string The serialized (and possibly encrypted) job data.
     * The storage should treat this as an opaque string.
     */
    private string $payload;
    /**
     * @var int Job priority. Higher values are processed first.
     */
    private int $priority;

    /**
     * Creates a new QueuedJob instance.
     *
     * @param string $id Unique job identifier.
     * @param string $payload Serialized job data (opaque to storage).
     * @param int $priority Higher = processed first. Default 0.
     * @param int $attempts Number of attempts made so far. Default 0.
     * @param int $availableAt Unix timestamp when job becomes available. 0 = immediately.
     * @param int $createdAt Unix timestamp when job was created. 0 = now.
     * @param string|null $failReason Reason for failure (null if not failed).
     */
    public function __construct(
        string $id,
        string $payload,
        int $priority = 0,
        int $attempts = 0,
        int $availableAt = 0,
        int $createdAt = 0,
        ?string $failReason = null
    ) {
        $this->id = $id;
        $this->payload = $payload;
        $this->priority = $priority;
        $this->attempts = $attempts;
        $this->availableAt = $availableAt > 0 ? $availableAt : time();
        $this->createdAt = $createdAt > 0 ? $createdAt : time();
        $this->failReason = $failReason;
    }
    /**
     * Returns the number of times this job has been attempted.
     *
     * @return int
     */
    public function getAttempts(): int {
        return $this->attempts;
    }
    /**
     * Returns the Unix timestamp when this job becomes available for processing.
     *
     * @return int
     */
    public function getAvailableAt(): int {
        return $this->availableAt;
    }
    /**
     * Returns the Unix timestamp when this job was first queued.
     *
     * @return int
     */
    public function getCreatedAt(): int {
        return $this->createdAt;
    }
    /**
     * Returns the failure reason, or null if the job has not failed.
     *
     * @return string|null
     */
    public function getFailReason(): ?string {
        return $this->failReason;
    }
    /**
     * Returns the unique identifier of this job.
     *
     * @return string
     */
    public function getId(): string {
        return $this->id;
    }
    /**
     * Returns the serialized job payload.
     *
     * This is an opaque string — the storage should not attempt to
     * interpret, parse, or modify it.
     *
     * @return string
     */
    public function getPayload(): string {
        return $this->payload;
    }
    /**
     * Returns the job priority.
     *
     * Higher values indicate higher priority. Jobs with higher priority
     * are returned first by QueueStorage::pop().
     *
     * @return int
     */
    public function getPriority(): int {
        return $this->priority;
    }
    /**
     * Checks if this job is currently available for processing.
     *
     * @return bool True if availableAt <= current time.
     */
    public function isAvailable(): bool {
        return $this->availableAt <= time();
    }
    /**
     * Sets the number of attempts.
     *
     * @param int $attempts The new attempt count.
     */
    public function setAttempts(int $attempts): void {
        $this->attempts = $attempts;
    }
    /**
     * Sets when the job becomes available.
     *
     * @param int $timestamp Unix timestamp.
     */
    public function setAvailableAt(int $timestamp): void {
        $this->availableAt = $timestamp;
    }
    /**
     * Sets the failure reason.
     *
     * @param string|null $reason The failure message.
     */
    public function setFailReason(?string $reason): void {
        $this->failReason = $reason;
    }
}
