<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Captor;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\ProcessingResult;
use Rasuvaeff\Yii3Outbox\Processor;
use Rasuvaeff\Yii3Outbox\PublisherInterface;
use Rasuvaeff\Yii3Outbox\PublishException;
use Rasuvaeff\Yii3Outbox\RetryAwareStorageInterface;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Rasuvaeff\Yii3Outbox\StorageInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(Processor::class)]
#[Covers(ProcessingResult::class)]
final class ProcessorTest
{
    private InMemoryStorage $storage;
    private Processor $processor;
    private PublisherInterface $publisher;
    private ClockInterface $clock;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->publisher = Understudy::for(PublisherInterface::class);
        $this->clock = $this->fixedClock('2026-06-01 12:00:00');
        $this->processor = new Processor(
            storage: $this->storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 0),
            clock: $this->clock,
        );
    }

    private Captor $warningMessages;

    private Captor $warningContexts;

    private Captor $errorMessages;

    private Captor $errorContexts;

    private function logger(): LoggerInterface
    {
        $logger = Understudy::for(LoggerInterface::class);
        $this->warningMessages = Arg::captor();
        $this->warningContexts = Arg::captor();
        $this->errorMessages = Arg::captor();
        $this->errorContexts = Arg::captor();

        when(fn() => $logger->warning($this->warningMessages->capture(), $this->warningContexts->capture()));
        when(fn() => $logger->error($this->errorMessages->capture(), $this->errorContexts->capture()));

        return $logger;
    }

    private function fixedClock(string $now): ClockInterface
    {
        $clock = Understudy::for(ClockInterface::class);
        when(fn() => $clock->now())->returns(new DateTimeImmutable($now));

        return $clock;
    }

    private function failEveryPublish(): void
    {
        when(fn() => $this->publisher->publish(Arg::any()))
            ->answers(
                fn(Invocation $call) => throw new PublishException(
                    message: 'Publish failed',
                    outboxMessage: $call->args[0],
                ),
            );
    }

    private function throwOnEveryPublish(\Throwable $exception): void
    {
        when(fn() => $this->publisher->publish(Arg::any()))
            ->throws($exception);
    }

    /**
     * @return list<string>
     */
    private function publishedIds(): array
    {
        return array_map(
            static fn(Invocation $call): string => $call->args[0]->getId(),
            Understudy::calls(fn() => $this->publisher->publish(Arg::any())),
        );
    }

    /**
     * @return array{0: StorageInterface, 1: InMemoryStorage}
     */
    private function plainStorage(): array
    {
        $inner = new InMemoryStorage();

        return [Understudy::delegate(StorageInterface::class, $inner), $inner];
    }

    /**
     * @return array{0: RetryAwareStorageInterface, 1: InMemoryStorage}
     */
    private function retryAwareStorage(): array
    {
        $inner = new InMemoryStorage();

        return [Understudy::delegate(RetryAwareStorageInterface::class, $inner), $inner];
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
        Assert::same($this->publishedIds(), ['msg-1']);
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
        $this->failEveryPublish();

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

        $this->failEveryPublish();

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
        $this->failEveryPublish();

        $message = OutboxMessageBuilder::create()
            ->withId('msg-1')
            ->withStatus(OutboxStatus::Pending)
            ->build();

        $this->storage->save($message);

        $logger = $this->logger();

        $processor = new Processor(
            storage: $this->storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 0),
            clock: $this->clock,
            logger: $logger,
        );

        $processor->process();

        verify(fn() => $logger->warning(Arg::any(), Arg::any()), times: 1);
        Assert::same($this->warningContexts->last(), [
            'messageId' => 'msg-1',
            'attempts' => 1,
            'terminal' => false,
            'error' => 'Publish failed',
        ]);
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

    public function marksFailedWhenClaimingAMessageWithNoAttemptsLeft(): void
    {
        // A Pending row whose attempts are already spent used to be saved back
        // as Pending on every run — claimed, skipped, saved, forever. It cannot
        // be retried and nothing else would ever terminate it.
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
        Assert::same($result->skipped, 0);
        Assert::same($result->failed, 1);
        $stored = $this->storage->getById('msg-1');
        Assert::notNull($stored);
        Assert::same($stored->getStatus(), OutboxStatus::Failed);
        Assert::same($this->publishedIds(), []);
    }

    public function logsTheExhaustedRetriesWarning(): void
    {
        $logger = $this->logger();
        $processor = new Processor(
            storage: $this->storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 1, delaySeconds: 0),
            clock: $this->clock,
            logger: $logger,
        );

        $this->storage->save(
            OutboxMessageBuilder::create()
                ->withId('msg-1')
                ->withStatus(OutboxStatus::Pending)
                ->withAttempts(1)
                ->build(),
        );

        $processor->process();

        Assert::same($this->warningMessages->all(), ['Outbox message exhausted its retries']);
        Assert::same($this->warningContexts->all(), [['messageId' => 'msg-1', 'attempts' => 1]]);
    }

    public function logsTheUnexpectedExceptionWithItsClass(): void
    {
        $logger = $this->logger();
        $processor = new Processor(
            storage: $this->storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 0),
            clock: $this->clock,
            logger: $logger,
        );
        $this->throwOnEveryPublish(new \RuntimeException('connection reset'));

        $this->storage->save(
            OutboxMessageBuilder::create()->withId('msg-1')->withStatus(OutboxStatus::Pending)->build(),
        );

        try {
            $processor->process();
        } catch (\RuntimeException) {
        }

        Assert::same($this->errorMessages->all(), ['Outbox publisher threw an unexpected exception']);
        Assert::same($this->errorContexts->all(), [[
            'messageId' => 'msg-1',
            'attempts' => 1,
            'exception' => \RuntimeException::class,
            'error' => 'connection reset',
        ]]);
    }

    public function releasesTheRestOfTheBatchWhenThePublisherThrowsSomethingElse(): void
    {
        // Anything that is not a PublishException is a bug in the publisher and
        // is rethrown — but the rows this batch claimed must not be stranded in
        // Processing, because no API can move them back.
        $this->throwOnEveryPublish(new \RuntimeException('connection reset'));

        foreach (['msg-1', 'msg-2', 'msg-3'] as $id) {
            $this->storage->save(
                OutboxMessageBuilder::create()->withId($id)->withStatus(OutboxStatus::Pending)->build(),
            );
        }

        $thrown = null;

        try {
            $this->processor->process();
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown);
        Assert::same($thrown->getMessage(), 'connection reset');
        Assert::same($this->publishedIds(), ['msg-1']);

        foreach (['msg-1', 'msg-2', 'msg-3'] as $id) {
            $stored = $this->storage->getById($id);
            Assert::notNull($stored);
            Assert::same($stored->getStatus(), OutboxStatus::Pending);
        }

        // The message that actually failed spent an attempt; the released ones
        // did not.
        Assert::same($this->storage->getById('msg-1')?->getAttempts(), 1);
        Assert::same($this->storage->getById('msg-2')?->getAttempts(), 0);
    }

    public function requeuesTheMessageWhenMarkingItPublishedFails(): void
    {
        // publish() succeeded and markPublished() did not: the message reached
        // its consumer, only the record of that did not. It goes back to
        // Pending and a later run publishes it again — this package delivers at
        // least once and the id is the consumer's deduplication key. Leaving it
        // Processing would be a row nothing in the API can move.
        $logger = $this->logger();
        [$storage] = $this->plainStorage();
        $processor = new Processor(
            storage: $storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 0),
            clock: $this->clock,
            logger: $logger,
        );

        foreach (['msg-1', 'msg-2'] as $id) {
            $storage->save(
                OutboxMessageBuilder::create()->withId($id)->withStatus(OutboxStatus::Pending)->build(),
            );
        }

        when(fn() => $storage->markPublished(Arg::any()))->throws(new \RuntimeException('deadlock detected'));

        $thrown = null;

        try {
            $processor->process();
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown);
        Assert::same($thrown->getMessage(), 'deadlock detected');
        Assert::same($this->publishedIds(), ['msg-1']);

        foreach (['msg-1', 'msg-2'] as $id) {
            $stored = $storage->getById($id);
            Assert::notNull($stored);
            Assert::same($stored->getStatus(), OutboxStatus::Pending);
        }

        Assert::same($storage->getById('msg-1')?->getAttempts(), 1);
        Assert::same($storage->getById('msg-2')?->getAttempts(), 0);
    }

    public function doesNotBlameThePublisherWhenMarkingPublishedFails(): void
    {
        // The old shape ran markPublished() inside the publisher's try, so a
        // storage incident logged "publisher threw an unexpected exception" and
        // sent an operator to debug the wrong component.
        $logger = $this->logger();
        [$storage] = $this->plainStorage();
        $processor = new Processor(
            storage: $storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 0),
            clock: $this->clock,
            logger: $logger,
        );

        $storage->save(
            OutboxMessageBuilder::create()->withId('msg-1')->withStatus(OutboxStatus::Pending)->build(),
        );
        when(fn() => $storage->markPublished(Arg::any()))->throws(new \RuntimeException('deadlock detected'));

        try {
            $processor->process();
        } catch (\RuntimeException) {
        }

        Assert::same($this->errorMessages->all(), ['Outbox message was published but could not be marked published']);
        Assert::same($this->errorContexts->all(), [[
            'messageId' => 'msg-1',
            'attempts' => 1,
            'exception' => \RuntimeException::class,
            'error' => 'deadlock detected',
        ]]);
    }

    public function aFailedReleaseDoesNotReplaceTheExceptionThatAbortedTheBatch(): void
    {
        // The release reaches for the storage that may well be why the batch
        // aborted. If its own failure propagated, the caller would receive a
        // symptom instead of the cause.
        $logger = $this->logger();
        [$storage] = $this->plainStorage();
        $processor = new Processor(
            storage: $storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 0),
            clock: $this->clock,
            logger: $logger,
        );

        foreach (['msg-1', 'msg-2'] as $id) {
            $storage->save(
                OutboxMessageBuilder::create()->withId($id)->withStatus(OutboxStatus::Pending)->build(),
            );
        }

        $this->throwOnEveryPublish(new \RuntimeException('connection reset'));
        when(fn() => $storage->save(Arg::any()))->throws(new \LogicException('storage is down'));

        $thrown = null;

        try {
            $processor->process();
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown);
        Assert::instanceOf($thrown, \RuntimeException::class);
        Assert::same($thrown->getMessage(), 'connection reset');

        Assert::same($this->errorMessages->all(), [
            'Outbox publisher threw an unexpected exception',
            'Failed to persist an outbox message while aborting the batch',
            'Failed to release a claimed outbox message',
        ]);
        Assert::same($this->errorContexts->all(), [
            [
                'messageId' => 'msg-1',
                'attempts' => 1,
                'exception' => \RuntimeException::class,
                'error' => 'connection reset',
            ],
            [
                'messageId' => 'msg-1',
                'exception' => \LogicException::class,
                'error' => 'storage is down',
            ],
            [
                'messageId' => 'msg-2',
                'exception' => \LogicException::class,
                'error' => 'storage is down',
            ],
        ]);
    }

    public function continuesProcessingAfterTerminatingAnExhaustedMessage(): void
    {
        // The loop moves on to the next message; it does not stop at the first
        // one it terminates, which would leave the rest claimed in Processing.
        $this->storage->save(
            OutboxMessageBuilder::create()
                ->withId('msg-1')
                ->withStatus(OutboxStatus::Pending)
                ->withAttempts(3)
                ->build(),
        );
        $this->storage->save(
            OutboxMessageBuilder::create()->withId('msg-2')->withStatus(OutboxStatus::Pending)->build(),
        );

        $result = $this->processor->process();

        Assert::same($result->published, 1);
        Assert::same($result->failed, 1);
        Assert::same($this->publishedIds(), ['msg-2']);
        Assert::same($this->storage->getById('msg-1')?->getStatus(), OutboxStatus::Failed);
        Assert::same($this->storage->getById('msg-2')?->getStatus(), OutboxStatus::Published);
    }

    public function continuesProcessingAfterAPublishFailure(): void
    {
        // The specific stub is registered after the broad one, so it wins for
        // 'msg-1' and the broad one answers for the rest of the batch.
        when(fn() => $this->publisher->publish(Arg::which('getId', 'msg-1')))->answers(
            fn(Invocation $call) => throw new PublishException(
                message: 'Publish failed',
                outboxMessage: $call->args[0],
            ),
        );

        foreach (['msg-1', 'msg-2'] as $id) {
            $this->storage->save(
                OutboxMessageBuilder::create()->withId($id)->withStatus(OutboxStatus::Pending)->build(),
            );
        }

        $result = $this->processor->process();

        Assert::same($result->published, 1);
        Assert::same($result->failed, 1);
        Assert::same($this->publishedIds(), ['msg-1', 'msg-2']);
        Assert::same($this->storage->getById('msg-1')?->getStatus(), OutboxStatus::Pending);
        Assert::same($this->storage->getById('msg-2')?->getStatus(), OutboxStatus::Published);
    }

    public function marksFailedWhenAnUnexpectedExceptionSpendsTheLastAttempt(): void
    {
        $processor = new Processor(
            storage: $this->storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 1, delaySeconds: 0),
            clock: $this->clock,
        );
        $this->throwOnEveryPublish(new \TypeError('bad argument'));

        $this->storage->save(
            OutboxMessageBuilder::create()->withId('msg-1')->withStatus(OutboxStatus::Pending)->build(),
        );

        try {
            $processor->process();
        } catch (\TypeError) {
        }

        $stored = $this->storage->getById('msg-1');
        Assert::notNull($stored);
        Assert::same($stored->getStatus(), OutboxStatus::Failed);
    }

    public function skipsWhenDelayNotElapsed(): void
    {
        [$storage] = $this->plainStorage();
        $policy = new RetryPolicy(maxAttempts: 3, delaySeconds: 60);
        $processor = new Processor(
            storage: $storage,
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

        $storage->save($message);

        $result = $processor->process();

        // The storage double is a plain StorageInterface, so the batch arrives
        // unfiltered and the loop is what discards this message.
        Assert::same($result->published, 0);
        Assert::same($result->skipped, 1);
        Assert::same($storage->getById('msg-1')?->getStatus(), OutboxStatus::Pending);
    }

    /**
     * What #20 was about: a message waiting out its backoff used to be claimed
     * and then written straight back as `Pending` — two writes and one wasted
     * slot in the batch, every iteration, for every backing-off message.
     */
    public function doesNotClaimMessagesStillWaitingOutTheirBackoff(): void
    {
        [$storage, $innerStorage] = $this->retryAwareStorage();
        $processor = new Processor(
            storage: $storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 60),
            clock: $this->clock,
        );

        $innerStorage->save(
            OutboxMessageBuilder::create()
                ->withId('backing-off')
                ->withStatus(OutboxStatus::Pending)
                ->withAttempts(1)
                ->withLastAttemptAt(new DateTimeImmutable('2026-06-01 11:59:30'))
                ->build(),
        );

        $result = $processor->process();

        verify(fn() => $storage->claimReady(Arg::any(), Arg::any(), Arg::any(), Arg::any()), times: 1);
        verify(fn() => $storage->claim(Arg::any(), Arg::any()), never: true);
        verify(fn() => $storage->save(Arg::any()), never: true);
        Assert::same($result->skipped, 0);
        Assert::same($result->published, 0);
        Assert::same($storage->getById('backing-off')?->getStatus(), OutboxStatus::Pending);
        Assert::same($storage->getById('backing-off')?->getAttempts(), 1);
    }

    /**
     * The readiness pushdown must not hide an exhausted message. Nothing but
     * `markFailed()` can terminate one, and the caller can only fail a message
     * the storage handed it — so a message out of attempts stays claimable
     * whether or not its backoff has elapsed.
     */
    public function claimsExhaustedMessagesInsideTheBackoffWindow(): void
    {
        [$storage, $innerStorage] = $this->retryAwareStorage();
        $processor = new Processor(
            storage: $storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 60),
            clock: $this->clock,
        );

        $innerStorage->save(
            OutboxMessageBuilder::create()
                ->withId('exhausted')
                ->withStatus(OutboxStatus::Pending)
                ->withAttempts(3)
                ->withLastAttemptAt(new DateTimeImmutable('2026-06-01 11:59:59'))
                ->build(),
        );

        $result = $processor->process();

        Assert::same($result->failed, 1);
        Assert::same($storage->getById('exhausted')?->getStatus(), OutboxStatus::Failed);
    }

    /**
     * On the fallback path the loop must keep going past a message that is not
     * ready: it is one entry in a batch, not the end of one.
     */
    public function plainStorageBatchContinuesPastANotReadyMessage(): void
    {
        [$storage] = $this->plainStorage();
        $processor = new Processor(
            storage: $storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 60),
            clock: $this->clock,
        );

        $storage->save(
            OutboxMessageBuilder::create()
                ->withId('not-ready')
                ->withStatus(OutboxStatus::Pending)
                ->withAttempts(1)
                ->withLastAttemptAt(new DateTimeImmutable('2026-06-01 11:59:30'))
                ->build(),
        );
        $storage->save(
            OutboxMessageBuilder::create()
                ->withId('ready')
                ->withStatus(OutboxStatus::Pending)
                ->build(),
        );

        $result = $processor->process();

        Assert::same($result->skipped, 1);
        Assert::same($result->published, 1);
        Assert::same($storage->getById('ready')?->getStatus(), OutboxStatus::Published);
        Assert::same($storage->getById('not-ready')?->getStatus(), OutboxStatus::Pending);
    }

    public function fallsBackToPlainClaimWhenStorageIsNotRetryAware(): void
    {
        [$storage] = $this->plainStorage();
        $processor = new Processor(
            storage: $storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 0),
            clock: $this->clock,
        );

        $storage->save(
            OutboxMessageBuilder::create()
                ->withId('msg-1')
                ->withStatus(OutboxStatus::Pending)
                ->build(),
        );

        $result = $processor->process();

        Assert::same($result->published, 1);
        Assert::same($storage->getById('msg-1')?->getStatus(), OutboxStatus::Published);
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
        // Not ready because its backoff has not elapsed — a message with
        // attempts left, unlike one that has spent them all and gets failed.
        $processor = new Processor(
            storage: $this->storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 60),
            clock: $this->clock,
        );
        $notReady = OutboxMessageBuilder::create()
            ->withId('not-ready')
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts(1)
            ->withLastAttemptAt(new DateTimeImmutable('2026-06-01 11:59:30'))
            ->build();
        $ready = OutboxMessageBuilder::create()
            ->withId('ready')
            ->withStatus(OutboxStatus::Pending)
            ->withAttempts(0)
            ->build();

        $this->storage->save($notReady);
        $this->storage->save($ready);

        $result = $processor->process();

        // InMemoryStorage is retry-aware, so 'not-ready' never enters the
        // batch at all — hence a skipped count of zero rather than one.
        Assert::same($result->published, 1);
        Assert::same($result->skipped, 0);
        Assert::same($this->storage->getById('ready')?->getStatus(), OutboxStatus::Published);
        Assert::notSame($this->storage->getById('not-ready')?->getStatus(), OutboxStatus::Published);
    }

    /**
     * The contract a worker relies on: whatever the publisher does, no claimed
     * message is left in `Processing`, and no message is left `Pending` with
     * its attempts already spent. Those are the two states nothing in the API
     * can recover from — the first strands a message when a worker dies
     * mid-batch, the second makes it circle claim -> skip -> save forever.
     *
     * @param list<array{attempts: int, dueOffset: int}> $specs
     */
    #[Property(runs: 200, timeoutMs: 5000)]
    public function noBatchLeavesAMessageStrandedOrUnretryable(array $specs, string $publisherBehaviour): void
    {
        $maxAttempts = 3;
        $storage = new InMemoryStorage();
        $publisher = Understudy::for(PublisherInterface::class);

        if ($publisherBehaviour === 'publishException') {
            when(fn() => $publisher->publish(Arg::any()))->answers(
                fn(Invocation $call) => throw new PublishException(
                    message: 'Publish failed',
                    outboxMessage: $call->args[0],
                ),
            );
        } elseif ($publisherBehaviour === 'unexpected') {
            when(fn() => $publisher->publish(Arg::any()))->throws(new \RuntimeException('boom'));
        }

        $now = new DateTimeImmutable('2026-06-01 12:00:00');

        foreach ($specs as $index => $spec) {
            $storage->save(
                OutboxMessageBuilder::create()
                    ->withId('m' . $index)
                    ->withStatus(OutboxStatus::Pending)
                    ->withAttempts($spec['attempts'])
                    ->withLastAttemptAt($spec['attempts'] === 0 ? null : $now->modify('-' . $spec['dueOffset'] . ' seconds'))
                    ->build(),
            );
        }

        $processor = new Processor(
            storage: $storage,
            publisher: $publisher,
            retryPolicy: new RetryPolicy(maxAttempts: $maxAttempts, delaySeconds: 30),
            clock: $this->fixedClock($now->format('Y-m-d H:i:s')),
        );

        // Exactly the messages the readiness pushdown lets through: never
        // attempted, past their backoff, or out of attempts. The rest are not
        // in the batch at all, which is the point of #20 — they used to be
        // claimed and written straight back.
        $claimable = 0;

        foreach ($specs as $spec) {
            if ($spec['attempts'] === 0 || $spec['attempts'] >= $maxAttempts || $spec['dueOffset'] >= 30) {
                $claimable++;
            }
        }

        $threw = false;

        try {
            $result = $processor->process();

            Assert::same($result->total(), $claimable);
            Assert::same($result->skipped, 0);
        } catch (\RuntimeException) {
            $threw = true;
        }

        // Both exits must be exercised: the ordinary one and the one where the
        // publisher throws something the processor rethrows.
        Classify::cover($threw, 'publisher threw an unexpected exception', 10.0);
        Classify::cover(!$threw && $specs !== [], 'batch completed with messages', 20.0);
        Classify::when($specs === [], 'empty batch');

        foreach (array_keys($specs) as $index) {
            $message = $storage->getById('m' . $index);
            Assert::notNull($message);
            Assert::true($message->getStatus() !== OutboxStatus::Processing);

            if ($message->getStatus() === OutboxStatus::Pending) {
                Assert::true($message->getAttempts() < $maxAttempts);
            }
        }
    }

    /** @return array<string, ArbitraryInterface> */
    public static function noBatchLeavesAMessageStrandedOrUnretryableGenerators(): array
    {
        return [
            'specs' => Gen::arrayOf(
                Gen::record([
                    'attempts' => Gen::intBetween(0, 4),
                    // delaySeconds is 30, so 0 and 10 are "not due yet".
                    'dueOffset' => Gen::elements([0, 10, 45, 90]),
                ]),
                maxSize: 6,
            ),
            'publisherBehaviour' => Gen::elements(['ok', 'publishException', 'unexpected']),
        ];
    }

    /** @return iterable<string, array{list<array{attempts: int, dueOffset: int}>, string}> */
    public static function noBatchLeavesAMessageStrandedOrUnretryableExamples(): iterable
    {
        yield 'exhausted message with a healthy publisher' => [[['attempts' => 3, 'dueOffset' => 90]], 'ok'];
        yield 'last attempt spent by a failure' => [[['attempts' => 2, 'dueOffset' => 90]], 'publishException'];
        yield 'unexpected exception mid-batch' => [
            [['attempts' => 0, 'dueOffset' => 0], ['attempts' => 0, 'dueOffset' => 0]],
            'unexpected',
        ];
        yield 'empty outbox' => [[], 'ok'];
    }

    public function returnsZeroResultWhenNoPendingMessages(): void
    {
        $result = $this->processor->process();

        Assert::same($result->total(), 0);
    }

    // --- type scope -------------------------------------------------------

    private function saveTyped(string $id, string $type, InMemoryStorage $storage): void
    {
        $storage->save(OutboxMessageBuilder::create()->withId($id)->withType($type)->withStatus(OutboxStatus::Pending)->build());
    }

    public function rejectsAnEmptyTypeInTheScope(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessage('Message type must not be empty');

        new Processor(
            storage: $this->storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 0),
            clock: $this->clock,
            types: ['order.created', ''],
        );
    }

    public function withoutAScopeEveryTypeIsClaimed(): void
    {
        [$storage, $inner] = $this->plainStorage();
        $this->saveTyped('a', 'order.created', $inner);
        $this->saveTyped('b', 'ab.exposure', $inner);
        $processor = new Processor(
            storage: $storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 0),
            clock: $this->clock,
        );

        $result = $processor->process();

        verify(fn() => $storage->claim([], 100), times: 1);
        Assert::same($result->published, 2);
        Assert::same($this->publishedIds(), ['a', 'b']);
    }

    public function aScopedProcessorClaimsOnlyItsTypesFromAPlainStorage(): void
    {
        [$storage, $inner] = $this->plainStorage();
        $this->saveTyped('a', 'order.created', $inner);
        $this->saveTyped('b', 'ab.exposure', $inner);
        $this->saveTyped('c', 'order.paid', $inner);
        $processor = new Processor(
            storage: $storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 0),
            clock: $this->clock,
            batchSize: 7,
            types: ['order.created', 'order.paid'],
        );

        $result = $processor->process();

        verify(fn() => $storage->claim(['order.created', 'order.paid'], 7), times: 1);
        Assert::same($result->published, 2);
        Assert::same($this->publishedIds(), ['a', 'c']);
        Assert::same($inner->getById('b')?->getStatus(), OutboxStatus::Pending);
        Assert::same($inner->getById('a')?->getStatus(), OutboxStatus::Published);
    }

    public function aScopedProcessorClaimsOnlyItsTypesFromARetryAwareStorage(): void
    {
        [$storage, $inner] = $this->retryAwareStorage();
        $this->saveTyped('a', 'order.created', $inner);
        $this->saveTyped('b', 'ab.exposure', $inner);
        $processor = new Processor(
            storage: $storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 0),
            clock: $this->clock,
            batchSize: 7,
            types: ['order.created'],
        );

        $result = $processor->process();

        verify(fn() => $storage->claimReady(Arg::any(), 3, ['order.created'], 7), times: 1);
        Assert::same($result->published, 1);
        Assert::same($this->publishedIds(), ['a']);
        Assert::same($inner->getById('b')?->getStatus(), OutboxStatus::Pending);
    }

    /**
     * Two processors over one storage, each scoped to its own types, together
     * publish everything exactly once and neither touches the other's
     * messages — the whole point of the scope.
     *
     * @param list<string> $types every message's type, in save order
     */
    #[Property(runs: 200)]
    public function disjointScopesPartitionTheStorage(array $types, bool $retryAware): void
    {
        $inner = new InMemoryStorage();
        foreach ($types as $index => $type) {
            $this->saveTyped('m' . $index, $type, $inner);
        }
        $storage = $retryAware ? $inner : Understudy::delegate(StorageInterface::class, $inner);

        $published = [];
        $publisher = Understudy::for(PublisherInterface::class);
        when(fn() => $publisher->publish(Arg::any()))->answers(static function (Invocation $call) use (&$published): void {
            $published[] = $call->args[0]->getType();
        });

        $scopes = [['a'], ['b', 'c']];
        $results = [];
        foreach ($scopes as $scope) {
            $results[] = (new Processor(
                storage: $storage,
                publisher: $publisher,
                retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 0),
                clock: $this->clock,
                batchSize: 100,
                types: $scope,
            ))->process();
        }

        $counted = array_count_values($types);
        Classify::cover(($counted['d'] ?? 0) > 0, 'unowned type present', 30.0);

        Assert::same($results[0]->published, $counted['a'] ?? 0);
        Assert::same($results[1]->published, ($counted['b'] ?? 0) + ($counted['c'] ?? 0));
        $expectedPublished = $counted;
        unset($expectedPublished['d']);
        $actualPublished = array_count_values($published);
        ksort($expectedPublished);
        ksort($actualPublished);
        Assert::same($actualPublished, $expectedPublished);

        foreach ($types as $index => $type) {
            $status = $inner->getById('m' . $index)?->getStatus();
            Assert::same($status, $type === 'd' ? OutboxStatus::Pending : OutboxStatus::Published);
        }
    }

    /** @return array<string, ArbitraryInterface> */
    public static function disjointScopesPartitionTheStorageGenerators(): array
    {
        return [
            'types' => Gen::arrayOf(Gen::elements(['a', 'b', 'c', 'd']), maxSize: 12),
            'retryAware' => Gen::bool(),
        ];
    }

    // --- terminal failures ------------------------------------------------

    public function aTerminalFailureMarksTheMessageFailedWithAttemptsToSpare(): void
    {
        when(fn() => $this->publisher->publish(Arg::any()))->answers(
            fn(Invocation $call) => throw PublishException::terminal(message: '410 Gone', outboxMessage: $call->args[0]),
        );
        $this->storage->save(OutboxMessageBuilder::create()->withId('msg-1')->withStatus(OutboxStatus::Pending)->build());
        $this->storage->save(OutboxMessageBuilder::create()->withId('msg-2')->withStatus(OutboxStatus::Pending)->build());
        $logger = $this->logger();
        $processor = new Processor(
            storage: $this->storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 0),
            clock: $this->clock,
            logger: $logger,
        );

        $result = $processor->process();

        Assert::same($result->failed, 2);
        Assert::same($result->published, 0);
        Assert::same($this->storage->getById('msg-1')?->getStatus(), OutboxStatus::Failed);
        Assert::same($this->storage->getById('msg-1')?->getAttempts(), 1);
        Assert::same($this->storage->getById('msg-2')?->getStatus(), OutboxStatus::Failed);
        Assert::same($this->warningMessages->all(), ['Failed to publish outbox message', 'Failed to publish outbox message']);
        Assert::same($this->warningContexts->all()[0], [
            'messageId' => 'msg-1',
            'attempts' => 1,
            'terminal' => true,
            'error' => '410 Gone',
        ]);
        verify(fn() => $logger->error(Arg::any(), Arg::any()), never: true);
    }

    public function aTerminalFailureIsNotRetriedOnTheNextRun(): void
    {
        when(fn() => $this->publisher->publish(Arg::any()))->answers(
            fn(Invocation $call) => throw PublishException::terminal(message: '410 Gone', outboxMessage: $call->args[0]),
        );
        $this->storage->save(OutboxMessageBuilder::create()->withId('msg-1')->withStatus(OutboxStatus::Pending)->build());

        $this->processor->process();
        $second = $this->processor->process();

        Assert::same($second->total(), 0);
        Assert::same($this->publishedIds(), ['msg-1']);
    }

    public function aNonTerminalFailureStillGoesThroughTheRetryPolicy(): void
    {
        $this->failEveryPublish();
        $this->storage->save(OutboxMessageBuilder::create()->withId('msg-1')->withStatus(OutboxStatus::Pending)->build());

        $this->processor->process();

        Assert::same($this->storage->getById('msg-1')?->getStatus(), OutboxStatus::Pending);
        Assert::same($this->storage->getById('msg-1')?->getAttempts(), 1);
    }

    public function aTerminalFailureUsesMarkFailedNotSave(): void
    {
        [$storage, $inner] = $this->plainStorage();
        when(fn() => $this->publisher->publish(Arg::any()))->answers(
            fn(Invocation $call) => throw PublishException::terminal(message: '410 Gone', outboxMessage: $call->args[0]),
        );
        $inner->save(OutboxMessageBuilder::create()->withId('msg-1')->withStatus(OutboxStatus::Pending)->build());
        $processor = new Processor(
            storage: $storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 0),
            clock: $this->clock,
        );

        $processor->process();

        verify(fn() => $storage->markFailed(Arg::which('getId', 'msg-1')), times: 1);
        verify(fn() => $storage->save(Arg::any()), never: true);
        verify(fn() => $storage->markPublished(Arg::any()), never: true);
    }
}
