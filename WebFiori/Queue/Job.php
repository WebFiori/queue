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
 * Interface for classes that represent a unit of work to be processed in the background.
 */
interface Job {
    /**
     * Returns the maximum number of times this job should be attempted.
     *
     * @return int
     */
    public function getMaxAttempts(): int;
    /**
     * Returns the number of seconds to wait before retrying a failed job.
     *
     * @return int
     */
    public function getRetryDelaySeconds(): int;
    /**
     * Execute the job logic.
     */
    public function handle(): void;
}
