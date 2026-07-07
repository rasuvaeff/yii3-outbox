<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests\Support;

use Rasuvaeff\PropertyTesting\StateMachine\Command;

/**
 * Model-based command: save a fresh Pending message. The model is the list of
 * message statuses in save order.
 */
final readonly class SaveCommand implements Command
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

        return [...$model, 'pending'];
    }

    #[\Override]
    public function run(mixed $model, mixed $system): mixed
    {
        \assert($system instanceof OutboxHarness);

        $system->save();

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
        return 'Save';
    }
}
