<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\Processor;
use Rasuvaeff\Yii3Outbox\RetryPolicy;

#[CoversClass(Processor::class)]
final class ProcessorTest extends TestCase
{
    private InMemoryStorage $storage;
    private Processor $processor;
    private StubPublisher $publisher;

    #[\Override]
    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->publisher = new StubPublisher();
        $this->processor = new Processor(
            storage: $this->storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 0),
        );
    }

    #[Test]
    public function publishesPendingMessage(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withId('msg-1')
            ->withStatus(OutboxStatus::Pending)
            ->build();

        $this->storage->save($message);

        $processed = $this->processor->process();

        $this->assertSame(1, $processed);
        $this->assertSame(OutboxStatus::Published, $this->storage->getById('msg-1')?->getStatus());
        $this->assertSame('msg-1', $this->publisher->lastPublished?->getId());
    }

    #[Test]
    public function publishesMultiplePendingMessages(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->storage->save(
                OutboxMessageBuilder::create()
                    ->withId('msg-' . $i)
                    ->withStatus(OutboxStatus::Pending)
                    ->build(),
            );
        }

        $processed = $this->processor->process();

        $this->assertSame(3, $processed);
    }

    #[Test]
    public function marksFailedOnPublishException(): void
    {
        $this->publisher->shouldFail = true;

        $message = OutboxMessageBuilder::create()
            ->withId('msg-1')
            ->withStatus(OutboxStatus::Pending)
            ->build();

        $this->storage->save($message);

        $processed = $this->processor->process();

        $this->assertSame(1, $processed);
        $this->assertSame(OutboxStatus::Failed, $this->storage->getById('msg-1')?->getStatus());
    }

    #[Test]
    public function skipsAlreadyPublished(): void
    {
        $this->storage->save(
            OutboxMessageBuilder::create()
                ->withId('msg-1')
                ->withStatus(OutboxStatus::Published)
                ->build(),
        );

        $processed = $this->processor->process();

        $this->assertSame(0, $processed);
    }

    #[Test]
    public function skipsFailedWithExhaustedRetries(): void
    {
        $policy = new RetryPolicy(maxAttempts: 1, delaySeconds: 0);
        $processor = new Processor(
            storage: $this->storage,
            publisher: $this->publisher,
            retryPolicy: $policy,
        );

        $message = OutboxMessageBuilder::create()
            ->withId('msg-1')
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts(1)
            ->build();

        $this->storage->save($message);

        $processed = $processor->process();

        $this->assertSame(0, $processed);
    }

    #[Test]
    public function respectsBatchSize(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->storage->save(
                OutboxMessageBuilder::create()
                    ->withId('msg-' . $i)
                    ->withStatus(OutboxStatus::Pending)
                    ->build(),
            );
        }

        $processor = new Processor(
            storage: $this->storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 0),
            batchSize: 2,
        );

        $processed = $processor->process();

        $this->assertSame(2, $processed);
    }

    #[Test]
    public function incrementsAttemptsOnPublish(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withId('msg-1')
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts(0)
            ->build();

        $this->storage->save($message);

        $this->processor->process();

        $updated = $this->storage->getById('msg-1');

        $this->assertNotNull($updated);
        $this->assertSame(1, $updated->getAttempts());
    }

    #[Test]
    public function throwsOnInvalidBatchSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Batch size must be at least 1');

        new Processor(
            storage: $this->storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(),
            batchSize: 0,
        );
    }

    #[Test]
    public function returnsZeroWhenNoPendingMessages(): void
    {
        $processed = $this->processor->process();

        $this->assertSame(0, $processed);
    }
}
