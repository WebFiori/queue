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
 * File-based queue storage.
 *
 * Stores each job as a JSON file. Pending jobs are in the 'pending' subdirectory,
 * failed jobs in the 'failed' subdirectory.
 */
class FileQueueStorage implements QueueStorage {
    private string $baseDir;

    /**
     * Creates a new file queue storage instance.
     *
     * @param string $baseDir Path to the queue storage directory.
     */
    public function __construct(string $baseDir) {
        $this->baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR);
        $this->ensureDir($this->getPendingDir());
        $this->ensureDir($this->getFailedDir());
    }
    /**
     * {@inheritDoc}
     */
    public function flush(): void {
        $files = glob($this->getFailedDir().DIRECTORY_SEPARATOR.'*.json');

        foreach ($files as $file) {
            unlink($file);
        }
    }
    /**
     * Returns the path to the base directory.
     *
     * @return string
     */
    public function getBaseDir(): string {
        return $this->baseDir;
    }
    /**
     * {@inheritDoc}
     */
    public function getFailed(): array {
        $files = glob($this->getFailedDir().DIRECTORY_SEPARATOR.'*.json');
        $failed = [];

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);

            if ($data !== null) {
                $failed[] = new QueuedJob(
                    $data['id'],
                    $data['payload'] ?? '',
                    $data['priority'] ?? 0,
                    $data['attempts'] ?? 0,
                    0,
                    $data['created_at'] ?? 0,
                    $data['reason'] ?? null
                );
            }
        }

        return $failed;
    }
    /**
     * {@inheritDoc}
     */
    public function getPendingCount(): int {
        return count(glob($this->getPendingDir().DIRECTORY_SEPARATOR.'*.json'));
    }
    /**
     * {@inheritDoc}
     */
    public function markComplete(string $id): void {
        $file = $this->getPendingDir().DIRECTORY_SEPARATOR.$id.'.json';

        if (file_exists($file)) {
            unlink($file);
        }
    }
    /**
     * {@inheritDoc}
     */
    public function markFailed(QueuedJob $job): void {
        $pendingFile = $this->getPendingDir().DIRECTORY_SEPARATOR.$job->getId().'.json';

        if (file_exists($pendingFile)) {
            unlink($pendingFile);
        }

        $data = [
            'id' => $job->getId(),
            'payload' => $job->getPayload(),
            'priority' => $job->getPriority(),
            'attempts' => $job->getAttempts(),
            'reason' => $job->getFailReason(),
            'failed_at' => time(),
        ];
        file_put_contents(
            $this->getFailedDir().DIRECTORY_SEPARATOR.$job->getId().'.json',
            json_encode($data),
            LOCK_EX
        );
    }
    /**
     * {@inheritDoc}
     */
    public function pop(int $limit = 10): array {
        $files = glob($this->getPendingDir().DIRECTORY_SEPARATOR.'*.json');
        $jobs = [];

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);

            if ($data === null) {
                continue;
            }

            $job = new QueuedJob(
                $data['id'],
                $data['payload'],
                $data['priority'] ?? 0,
                $data['attempts'] ?? 0,
                $data['available_at'] ?? 0,
                $data['created_at'] ?? 0
            );

            if (!$job->isAvailable()) {
                continue;
            }
            $jobs[] = $job;
        }

        usort($jobs, function (QueuedJob $a, QueuedJob $b) {
            if ($a->getPriority() !== $b->getPriority()) {
                return $b->getPriority() - $a->getPriority();
            }

            return $a->getCreatedAt() - $b->getCreatedAt();
        });

        return array_slice($jobs, 0, $limit);
    }
    /**
     * {@inheritDoc}
     */
    public function push(QueuedJob $job): void {
        $data = [
            'id' => $job->getId(),
            'payload' => $job->getPayload(),
            'priority' => $job->getPriority(),
            'attempts' => $job->getAttempts(),
            'available_at' => $job->getAvailableAt(),
            'created_at' => $job->getCreatedAt(),
        ];
        file_put_contents(
            $this->getPendingDir().DIRECTORY_SEPARATOR.$job->getId().'.json',
            json_encode($data),
            LOCK_EX
        );
    }
    /**
     * {@inheritDoc}
     */
    public function retry(string $id): void {
        $failedFile = $this->getFailedDir().DIRECTORY_SEPARATOR.$id.'.json';

        if (!file_exists($failedFile)) {
            return;
        }

        $data = json_decode(file_get_contents($failedFile), true);
        unlink($failedFile);

        $job = new QueuedJob(
            $data['id'],
            $data['payload'],
            $data['priority'] ?? 0,
            $data['attempts'] ?? 0,
            time(),
            $data['created_at'] ?? time()
        );
        $this->push($job);
    }

    private function ensureDir(string $dir): void {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private function getFailedDir(): string {
        return $this->baseDir.DIRECTORY_SEPARATOR.'failed';
    }

    private function getPendingDir(): string {
        return $this->baseDir.DIRECTORY_SEPARATOR.'pending';
    }
}
