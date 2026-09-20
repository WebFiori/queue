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

use LogicException;
use Throwable;
use UnexpectedValueException;

/**
 * Core queue class that dispatches and processes jobs.
 *
 * Handles serialization, encryption, and retry logic. The storage layer
 * only deals with QueuedJob value objects containing opaque payloads.
 */
class Queue {
    /**
     * Optional callback invoked for every throwable caught while processing a job.
     *
     * Signature: fn(?Job $job, Throwable $e, int $attempts, bool $willRetry): void
     *
     * @var callable|null
     */
    private $onError = null;
    private QueueStorage $storage;

    /**
     * Creates a new Queue instance.
     *
     * @param QueueStorage $storage The storage backend for the queue.
     */
    public function __construct(QueueStorage $storage) {
        $this->storage = $storage;
    }
    /**
     * Dispatch a job to the queue.
     *
     * The job is serialized, optionally encrypted, and stored via the storage backend.
     *
     * @param Job $job The job to dispatch.
     * @param int $priority Job priority (higher = processed first).
     * @param int $delaySeconds Seconds to wait before the job becomes available.
     *
     * @return string The unique job ID.
     */
    public function dispatch(Job $job, int $priority = 0, int $delaySeconds = 0): string {
        $id = $this->generateId();
        $payload = $this->encrypt(serialize($job));
        $availableAt = $delaySeconds > 0 ? time() + $delaySeconds : 0;

        $queuedJob = new QueuedJob($id, $payload, $priority, 0, $availableAt);
        $this->storage->push($queuedJob);

        return $id;
    }
    /**
     * Remove all failed jobs.
     */
    public function flush(): void {
        $this->storage->flush();
    }
    /**
     * Returns all failed jobs.
     *
     * @return QueuedJob[]
     */
    public function getFailed(): array {
        return $this->storage->getFailed();
    }
    /**
     * Returns the configured error callback, if any.
     *
     * @return callable|null
     */
    public function getOnError(): ?callable {
        return $this->onError;
    }
    /**
     * Returns all pending jobs, including delayed ones not yet available.
     *
     * This method requires a storage backend that implements ListableQueueStorage.
     * If the backend does not support listing, a LogicException is thrown.
     *
     * @return QueuedJob[] Array of all pending queued jobs.
     *
     * @throws LogicException If the storage backend does not implement ListableQueueStorage.
     */
    public function getPending(): array {
        if (!($this->storage instanceof ListableQueueStorage)) {
            throw new LogicException(
                'The configured storage backend does not support listing pending jobs. '
                .'Use a ListableQueueStorage implementation (e.g. FileQueueStorage).'
            );
        }

        return $this->storage->getPending();
    }
    /**
     * Returns the number of pending jobs.
     *
     * @return int
     */
    public function getPendingCount(): int {
        return $this->storage->getPendingCount();
    }
    /**
     * Returns the storage backend.
     *
     * @return QueueStorage
     */
    public function getStorage(): QueueStorage {
        return $this->storage;
    }
    /**
     * Process pending jobs from the queue.
     *
     * Retrieves available jobs from storage, decrypts and deserializes them,
     * then calls handle(). Failed jobs are retried or moved to the failed queue.
     *
     * @param int $limit Maximum number of jobs to process in this run.
     *
     * @return int Number of jobs successfully processed.
     */
    public function process(int $limit = 10): int {
        $pending = $this->storage->pop($limit);
        $processed = 0;

        foreach ($pending as $queuedJob) {
            $id = $queuedJob->getId();
            $attempts = $queuedJob->getAttempts() + 1;
            $job = null;

            try {
                $job = unserialize($this->decrypt($queuedJob->getPayload()));

                if (!($job instanceof Job)) {
                    $job = null;

                    throw new UnexpectedValueException('Payload is not a valid Job instance.');
                }

                $job->handle();
                $this->storage->markComplete($id);
                $processed++;
            } catch (Throwable $e) {
                // A non-Job payload cannot be retried; treat it as terminal.
                $maxAttempts = $job !== null ? $job->getMaxAttempts() : 1;
                $willRetry = $attempts < $maxAttempts;

                if (!$willRetry) {
                    $queuedJob->setAttempts($attempts);
                    $queuedJob->setFailReason(get_class($e).': '.$e->getMessage());
                    $this->storage->markFailed($queuedJob);
                } else {
                    // Re-queue with updated attempt count and delay
                    $this->storage->markComplete($id);
                    $delay = $job->getRetryDelaySeconds() * $attempts;
                    $queuedJob->setAttempts($attempts);
                    $queuedJob->setAvailableAt(time() + $delay);
                    $this->storage->push($queuedJob);
                }

                $this->invokeOnError($job, $e, $attempts, $willRetry);
            }
        }

        return $processed;
    }
    /**
     * Retry a specific failed job.
     *
     * @param string $id The job identifier.
     */
    public function retry(string $id): void {
        $this->storage->retry($id);
    }
    /**
     * Sets an optional callback invoked whenever a throwable is caught while
     * processing a job.
     *
     * The callback lets applications observe, log, or react to job failures
     * (e.g. bridge them into a globally registered error handler) with full
     * exception context. It is invoked for both terminal failures (attempts
     * exhausted) and intermediate failures that will be retried.
     *
     * Signature: fn(?Job $job, Throwable $e, int $attempts, bool $willRetry): void
     * ($job is null when the stored payload is not a valid Job instance.)
     *
     * @param callable|null $callback The error callback, or null to disable.
     *
     * @return Queue This instance, for chaining.
     */
    public function setOnError(?callable $callback): Queue {
        $this->onError = $callback;

        return $this;
    }
    /**
     * Decrypts data if it was encrypted.
     *
     * @param string $data The potentially encrypted data.
     *
     * @return string The plaintext data.
     */
    private function decrypt(string $data): string {
        $key = getenv('QUEUE_KEY');

        if ($key === false || $key === '') {
            return $data;
        }

        $raw = base64_decode($data, true);

        if ($raw === false || strlen($raw) < 29) {
            return $data;
        }

        $encKey = hash('sha256', $key, true);
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $encKey, OPENSSL_RAW_DATA, $iv, $tag);

        return $plaintext !== false ? $plaintext : $data;
    }
    /**
     * Encrypts data if QUEUE_KEY environment variable is set.
     *
     * @param string $data The plaintext data.
     *
     * @return string Encrypted (base64) or plaintext if no key.
     */
    private function encrypt(string $data): string {
        $key = getenv('QUEUE_KEY');

        if ($key === false || $key === '') {
            return $data;
        }

        $encKey = hash('sha256', $key, true);
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($data, 'aes-256-gcm', $encKey, OPENSSL_RAW_DATA, $iv, $tag);

        return base64_encode($iv.$tag.$ciphertext);
    }

    private function generateId(): string {
        return bin2hex(random_bytes(16));
    }
    /**
     * Invokes the error callback if one is configured.
     *
     * @param Job|null $job The job that failed, or null for an invalid payload.
     * @param Throwable $e The caught throwable.
     * @param int $attempts The attempt count at the time of failure.
     * @param bool $willRetry Whether the job will be retried.
     */
    private function invokeOnError(?Job $job, Throwable $e, int $attempts, bool $willRetry): void {
        if ($this->onError !== null) {
            ($this->onError)($job, $e, $attempts, $willRetry);
        }
    }
}
