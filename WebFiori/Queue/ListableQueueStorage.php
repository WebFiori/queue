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
 * Extended storage interface for backends that support listing pending jobs.
 *
 * Not all queue backends can enumerate their contents (e.g. SQS, RabbitMQ).
 * Backends that support random access and listing (files, database, Redis)
 * should implement this interface to enable admin dashboards and inspection.
 *
 * Backends that only support push/pop semantics should implement the base
 * QueueStorage interface instead.
 */
interface ListableQueueStorage extends QueueStorage {
    /**
     * Returns all pending jobs, including those not yet available due to delay.
     *
     * Unlike pop(), this method:
     * - Does NOT filter by availableAt (delayed jobs are included)
     * - Does NOT remove jobs from the queue
     * - Returns jobs sorted by priority descending, then createdAt ascending
     *
     * @return QueuedJob[] Array of all pending queued jobs.
     */
    public function getPending(): array;
}
