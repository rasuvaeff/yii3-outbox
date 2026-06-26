<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\ProcessingResult;
use Rasuvaeff\Yii3Outbox\Processor;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(Processor::class)]
#[Covers(ProcessingResult::class)]
final class ProcessorTest
{
    private InMemoryStorage $storage;
    private Processor $processor;
    private StubPublisher $publisher;
    private StubClock $clock;

    #[BeforeTest]
    public function setUp(): void
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

    public function publishesPendingMessage(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withId('msg-1')
            ->withStatus(OutboxStatus::Pending)
            ->build();

        $this->storage->save($message);

        $result = $this->processor->process();

        Assert::same($result->published, 1);
        Assert::same($result->failed, 0);
        Assert::same($result->skipped, 0);
        Assert::same($this->storage->getById('msg-1')?->getStatus(), OutboxStatus::Published);
        Assert::same($this->publisher->lastPublished?->getId(), 'msg-1');
    }

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

        Assert::same($result->published, 3);
        Assert::same($result->failed, 0);
    }

    public function keepsAsPendingWhenPublishFailsButRetriesRemain(): void
    {
        $this->publisher->shouldFail = true;

        $message = OutboxMessageBuilder::create()
            ->withId('msg-1')
            ->withStatus(OutboxStatus::Pending)
            ->build();

        $this->storage->save($message);

        $result = $this->processor->process();

        Assert::same($result->failed, 1);
        Assert::same($this->storage->getById('msg-1')?->getStatus(), OutboxStatus::Pending);
        Assert::same($this->storage->getById('msg-1')?->getAttempts(), 1);
    }

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

        Assert::same($result->failed, 1);
        Assert::same($this->storage->getById('msg-1')?->getStatus(), OutboxStatus::Failed);
    }

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

        Assert::true($logger->warningCalled);
        Assert::same($logger->warningContext['messageId'], 'msg-1');
        Assert::same($logger->warningContext['attempts'], 1);
        Assert::same($logger->warningContext['error'], 'Publish failed');
    }

    public function skipsAlreadyPublished(): void
    {
        $this->storage->save(
            OutboxMessageBuilder::create()
                ->withId('msg-1')
                ->withStatus(OutboxStatus::Published)
                ->build(),
        );

        $result = $this->processor->process();

        Assert::same($result->total(), 0);
    }

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

        Assert::same($result->published, 0);
        Assert::same($result->skipped, 1);
    }

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
            ->withLastAttemptAt(new DateTimeImmutable('2026-06-01 11:59:30'))
            ->build();

        $this->storage->save($message);

        $result = $processor->process();

        Assert::same($result->published, 0);
        Assert::same($result->skipped, 1);
    }

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

        Assert::same($result->published, 2);
    }

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

        Assert::notNull($updated);
        Assert::same($updated->getAttempts(), 1);
    }

    public function setsLastAttemptAtFromClock(): void
    {
        $message = OutboxMessageBuilder::create()
            ->withId('msg-1')
            ->withStatus(OutboxStatus::Pending)
            ->build();

        $this->storage->save($message);

        $this->processor->process();

        $updated = $this->storage->getById('msg-1');

        Assert::notNull($updated);
        Assert::same(
            $updated->getLastAttemptAt()?->format('Y-m-d H:i:s'),
            '2026-06-01 12:00:00',
        );
    }

    public function throwsOnInvalidBatchSize(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new Processor(
            storage: $this->storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(),
            clock: $this->clock,
            batchSize: 0,
        );
    }

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

        Assert::same($processor->process()->published, 1);
    }

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

        Assert::same($result->published, 1);
        Assert::same($result->skipped, 1);
        Assert::same($this->storage->getById('ready')?->getStatus(), OutboxStatus::Published);
        Assert::notSame($this->storage->getById('not-ready')?->getStatus(), OutboxStatus::Published);
    }

    public function returnsZeroResultWhenNoPendingMessages(): void
    {
        $result = $this->processor->process();

        Assert::same($result->total(), 0);
    }
}
