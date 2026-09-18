<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

/**
 * A storage that can report how many messages it holds per status.
 *
 * The numbers are what an operator alerts on — `Processing` that only grows
 * means workers die mid-batch, `Failed` that only grows means a publisher is
 * broken — and a backend that keeps them in one table answers with one
 * aggregate query. Optional, like every capability beyond
 * {@see StorageInterface}: a storage that cannot count cheaply does not
 * implement it.
 *
 * @api
 */
interface StatsAwareStorageInterface extends StorageInterface
{
    /**
     * A point-in-time snapshot; two calls around a concurrent write may
     * disagree, and that is fine for a gauge.
     */
    public function stats(): OutboxStats;
}
