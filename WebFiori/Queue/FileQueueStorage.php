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
                $failed[] = $data;
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
    public function markFailed(string $id, string $reason, int $attempts): void {
        $pendingFile = $this->getPendingDir().DIRECTORY_SEPARATOR.$id.'.json';
        $data = [];

        if (file_exists($pendingFile)) {
            $data = json_decode(file_get_contents($pendingFile), true) ?? [];
            unlink($pendingFile);
        }

        $data['reason'] = $reason;
        $data['attempts'] = $attempts;
        $data['failed_at'] = time();

        file_put_contents(
            $this->getFailedDir().DIRECTORY_SEPARATOR.$id.'.json',
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

            if ($data['available_at'] > time()) {
                continue;
            }
            $jobs[] = $data;
        }

        // Sort by priority descending, then by created_at ascending
        usort($jobs, function ($a, $b) {
            if ($a['priority'] !== $b['priority']) {
                return $b['priority'] - $a['priority'];
            }

            return $a['created_at'] - $b['created_at'];
        });

        return array_slice($jobs, 0, $limit);
    }
    /**
     * {@inheritDoc}
     */
    public function push(string $id, string $payload, int $priority = 0, int $availableAt = 0): void {
        $data = [
            'id' => $id,
            'payload' => $payload,
            'priority' => $priority,
            'attempts' => 0,
            'available_at' => $availableAt > 0 ? $availableAt : time(),
            'created_at' => time(),
        ];
        file_put_contents(
            $this->getPendingDir().DIRECTORY_SEPARATOR.$id.'.json',
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

        unset($data['reason'], $data['failed_at']);
        $data['available_at'] = time();

        file_put_contents(
            $this->getPendingDir().DIRECTORY_SEPARATOR.$id.'.json',
            json_encode($data),
            LOCK_EX
        );
    }
    /**
     * {@inheritDoc}
     */
    public function setAttempts(string $id, int $attempts): void {
        $file = $this->getPendingDir().DIRECTORY_SEPARATOR.$id.'.json';

        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            $data['attempts'] = $attempts;
            file_put_contents($file, json_encode($data), LOCK_EX);
        }
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
