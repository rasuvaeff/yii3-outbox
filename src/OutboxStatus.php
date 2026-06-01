<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox;

/**
 * @api
 */
enum OutboxStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Failed = 'failed';
}
