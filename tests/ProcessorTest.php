<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Outbox\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
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
        Assert::same($this->publisher->publishedIds, []);
    }

    public function logsTheExhaustedRetriesWarning(): void
    {
        $logger = new SpyLogger();
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

        Assert::same($logger->records, [[
            'level' => 'warning',
            'message' => 'Outbox message exhausted its retries',
            'context' => ['messageId' => 'msg-1', 'attempts' => 1],
        ]]);
    }

    public function logsTheUnexpectedExceptionWithItsClass(): void
    {
        $logger = new SpyLogger();
        $processor = new Processor(
            storage: $this->storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 0),
            clock: $this->clock,
            logger: $logger,
        );
        $this->publisher->throwUnexpected = new \RuntimeException('connection reset');

        $this->storage->save(
            OutboxMessageBuilder::create()->withId('msg-1')->withStatus(OutboxStatus::Pending)->build(),
        );

        try {
            $processor->process();
        } catch (\RuntimeException) {
        }

        Assert::same($logger->records, [[
            'level' => 'error',
            'message' => 'Outbox publisher threw an unexpected exception',
            'context' => [
                'messageId' => 'msg-1',
                'attempts' => 1,
                'exception' => \RuntimeException::class,
                'error' => 'connection reset',
            ],
        ]]);
    }

    public function releasesTheRestOfTheBatchWhenThePublisherThrowsSomethingElse(): void
    {
        // Anything that is not a PublishException is a bug in the publisher and
        // is rethrown — but the rows this batch claimed must not be stranded in
        // Processing, because no API can move them back.
        $this->publisher->throwUnexpected = new \RuntimeException('connection reset');

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
        Assert::same($this->publisher->publishedIds, ['msg-1']);

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

    public function marksFailedWhenAnUnexpectedExceptionSpendsTheLastAttempt(): void
    {
        $processor = new Processor(
            storage: $this->storage,
            publisher: $this->publisher,
            retryPolicy: new RetryPolicy(maxAttempts: 1, delaySeconds: 0),
            clock: $this->clock,
        );
        $this->publisher->throwUnexpected = new \TypeError('bad argument');

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
        Assert::same($this->storage->getById('msg-1')?->getStatus(), OutboxStatus::Pending);
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

        Assert::same($result->published, 1);
        Assert::same($result->skipped, 1);
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
        $publisher = new StubPublisher();
        $publisher->shouldFail = $publisherBehaviour === 'publishException';
        $publisher->throwUnexpected = $publisherBehaviour === 'unexpected' ? new \RuntimeException('boom') : null;

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
            clock: new StubClock($now),
        );

        $threw = false;

        try {
            $result = $processor->process();

            Assert::same($result->total(), \count($specs));
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
}
