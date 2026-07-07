<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests\Support;

use Rasuvaeff\PropertyTesting\StateMachine\Command;

/**
 * Model-based command: claim every Pending message (Pending -> Processing).
 */
final readonly class ClaimCommand implements Command
{
    #[\Override]
    public function preCondition(mixed $model): bool
    {
        return true;
    }

    #[\Override]
    public function nextState(mixed $model): mixed
    {
        \assert(is_array($model));

        return array_map(
            static fn(mixed $status): string => $status === 'pending' ? 'processing' : (string) $status,
            $model,
        );
    }

    #[\Override]
    public function run(mixed $model, mixed $system): mixed
    {
        \assert($system instanceof OutboxHarness);

        $system->claim();

        return $system->statuses();
    }

    #[\Override]
    public function postCondition(mixed $model, mixed $result): bool
    {
        return $result === $this->nextState($model);
    }

    #[\Override]
    public function __toString(): string
    {
        return 'Claim';
    }
}
