<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\ProcessingResult;
use Rasuvaeff\Yii3Outbox\Processor;
use Rasuvaeff\Yii3Outbox\RetryPolicy;

#[CoversClass(Processor::class)]
#[CoversClass(ProcessingResult::class)]
final class ProcessorTest extends TestCase
{
    private InMemoryStorage $storage;
    private Processor $processor;
    private StubPublisher $publisher;
    private StubClock $clock;

    #[\Override]
    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->publisher = new StubPublisher();
        $this->clock = new StubClock(new DateTimeImmutable('2026-06-01 12:00:00'));
        $this->processor = new Processor(
            storage: $this->storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 0),
            clock: $this->clock,
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

        $result = $this->processor->process();

        $this->assertSame(1, $result->published);
        $this->assertSame(0, $result->failed);
        $this->assertSame(0, $result->skipped);
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

        $result = $this->processor->process();

        $this->assertSame(3, $result->published);
        $this->assertSame(0, $result->failed);
    }

    #[Test]
    public function keepsAsPendingWhenPublishFailsButRetriesRemain(): void
    {
        $this->publisher->shouldFail = true;

        $message = OutboxMessageBuilder::create()
            ->withId('msg-1')
            ->withStatus(OutboxStatus::Pending)
            ->build();

        $this->storage->save($message);

        $result = $this->processor->process();

        $this->assertSame(1, $result->failed);
        $this->assertSame(OutboxStatus::Pending, $this->storage->getById('msg-1')?->getStatus());
        $this->assertSame(1, $this->storage->getById('msg-1')?->getAttempts());
    }

    #[Test]
    public function marksFailedWhenRetriesExhausted(): void
    {
        $processor = new Processor(
            storage: $this->storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 1, delaySeconds: 0),
            clock: $this->clock,
        );

        $this->publisher->shouldFail = true;

        $message = OutboxMessageBuilder::create()
            ->withId('msg-1')
            ->withStatus(OutboxStatus::Pending)
            ->build();

        $this->storage->save($message);

        $result = $processor->process();

        $this->assertSame(1, $result->failed);
        $this->assertSame(OutboxStatus::Failed, $this->storage->getById('msg-1')?->getStatus());
    }

    #[Test]
    public function logsWarningOnPublishFailure(): void
    {
        $this->publisher->shouldFail = true;

        $message = OutboxMessageBuilder::create()
            ->withId('msg-1')
            ->withStatus(OutboxStatus::Pending)
            ->build();

        $this->storage->save($message);

        $logger = new SpyLogger();

        $processor = new Processor(
            storage: $this->storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 0),
            clock: $this->clock,
            logger: $logger,
        );

        $processor->process();

        $this->assertTrue($logger->warningCalled);
        $this->assertSame('msg-1', $logger->warningContext['messageId']);
        $this->assertSame(1, $logger->warningContext['attempts']);
        $this->assertSame('Publish failed', $logger->warningContext['error']);
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

        $result = $this->processor->process();

        $this->assertSame(0, $result->total());
    }

    #[Test]
    public function skipsWhenAttemptsExhausted(): void
    {
        $policy = new RetryPolicy(maxAttempts: 1, delaySeconds: 0);
        $processor = new Processor(
            storage: $this->storage,
            publisher: $this->publisher,
            retryPolicy: $policy,
            clock: $this->clock,
        );

        $message = OutboxMessageBuilder::create()
            ->withId('msg-1')
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts(1)
            ->build();

        $this->storage->save($message);

        $result = $processor->process();

        $this->assertSame(0, $result->published);
        $this->assertSame(1, $result->skipped);
    }

    #[Test]
    public function skipsWhenDelayNotElapsed(): void
    {
        $policy = new RetryPolicy(maxAttempts: 3, delaySeconds: 60);
        $processor = new Processor(
            storage: $this->storage,
            publisher: $this->publisher,
            retryPolicy: $policy,
            clock: $this->clock,
        );

        $message = OutboxMessageBuilder::create()
            ->withId('msg-1')
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts(1)
            ->withLastAttemptAt(new DateTimeImmutable('2026-06-01 11:59:30')) // 30s before clock
            ->build();

        $this->storage->save($message);

        $result = $processor->process();

        $this->assertSame(0, $result->published);
        $this->assertSame(1, $result->skipped);
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
            clock: $this->clock,
            batchSize: 2,
        );

        $result = $processor->process();

        $this->assertSame(2, $result->published);
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
    public function setsLastAttemptAtFromClock(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withId('msg-1')
            ->withStatus(OutboxStatus::Pending)
            ->build();

        $this->storage->save($message);

        $this->processor->process();

        $updated = $this->storage->getById('msg-1');

        $this->assertNotNull($updated);
        $this->assertSame(
            '2026-06-01 12:00:00',
            $updated->getLastAttemptAt()?->format('Y-m-d H:i:s'),
        );
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
            clock: $this->clock,
            batchSize: 0,
        );
    }

    #[Test]
    public function acceptsBatchSizeOfOne(): void
    {
        for ($i = 1; $i <= 3; $i++) {
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
            clock: $this->clock,
            batchSize: 1,
        );

        $this->assertSame(1, $processor->process()->published);
    }

    #[Test]
    public function continuesProcessingAfterSkippingNotReadyMessage(): void
    {
        $notReady = OutboxMessageBuilder::create()
            ->withId('not-ready')
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts(3)
            ->build();
        $ready = OutboxMessageBuilder::create()
            ->withId('ready')
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts(0)
            ->build();

        $this->storage->save($notReady);
        $this->storage->save($ready);

        $result = $this->processor->process();

        $this->assertSame(1, $result->published);
        $this->assertSame(1, $result->skipped);
        $this->assertSame(OutboxStatus::Published, $this->storage->getById('ready')?->getStatus());
        $this->assertNotSame(OutboxStatus::Published, $this->storage->getById('not-ready')?->getStatus());
    }

    #[Test]
    public function returnsZeroResultWhenNoPendingMessages(): void
    {
        $result = $this->processor->process();

        $this->assertSame(0, $result->total());
    }
}
