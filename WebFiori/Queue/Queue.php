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
 * Core queue class that dispatches and processes jobs.
 */
class Queue {
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
        $this->storage->push($id, $payload, $priority, $availableAt);

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
     * @return array
     */
    public function getFailed(): array {
        return $this->storage->getFailed();
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
     * @param int $limit Maximum number of jobs to process in this run.
     *
     * @return int Number of jobs successfully processed.
     */
    public function process(int $limit = 10): int {
        $pending = $this->storage->pop($limit);
        $processed = 0;

        foreach ($pending as $item) {
            $id = $item['id'];
            $attempts = ($item['attempts'] ?? 0) + 1;

            try {
                $job = unserialize($this->decrypt($item['payload']));

                if (!($job instanceof Job)) {
                    $this->storage->markFailed($id, 'Payload is not a valid Job instance.', $attempts);

                    continue;
                }

                $job->handle();
                $this->storage->markComplete($id);
                $processed++;
            } catch (\Throwable $e) {
                if ($attempts >= $job->getMaxAttempts()) {
                    $this->storage->markFailed($id, $e->getMessage(), $attempts);
                } else {
                    // Re-queue with updated attempt count and delay
                    $this->storage->markComplete($id);
                    $delay = $job->getRetryDelaySeconds() * $attempts;
                    $this->storage->push($id, $item['payload'], $item['priority'] ?? 0, time() + $delay);
                    $this->storage->setAttempts($id, $attempts);
                }
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
}
