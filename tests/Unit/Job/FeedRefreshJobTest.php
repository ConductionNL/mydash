<?php

/**
 * FeedRefreshJobTest
 *
 * Unit tests for the {@see \OCA\MyDash\Job\FeedRefreshJob} TimedJob —
 * interval clamping (REQ-FRJ-002), global lock acquisition and release
 * (REQ-FRJ-007), and graceful skip on a held lock.
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Job
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Job;

use OCA\MyDash\Job\FeedRefreshJob;
use OCA\MyDash\Service\FeedRefreshService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for FeedRefreshJob.
 */
class FeedRefreshJobTest extends TestCase
{

    /** @var ITimeFactory&MockObject */
    private $time;

    /** @var IConfig&MockObject */
    private $config;

    /** @var FeedRefreshService&MockObject */
    private $refreshService;

    /** @var ILockingProvider&MockObject */
    private $lockingProvider;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /**
     * Wire mocks per test (interval is read in the constructor so it
     * must be settable per case).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->time            = $this->createMock(ITimeFactory::class);
        $this->config          = $this->createMock(IConfig::class);
        $this->refreshService  = $this->createMock(FeedRefreshService::class);
        $this->lockingProvider = $this->createMock(ILockingProvider::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
    }//end setUp()

    /**
     * Constructor reads the configured interval and clamps to MIN.
     * (60 seconds requested → 300 seconds applied.)
     *
     * @return void
     */
    public function testConstructorClampsBelowMinimum(): void
    {
        $this->config->method('getAppValue')
            ->willReturnCallback(static function (string $app, string $key, string $default): string {
                if ($key === FeedRefreshJob::CONFIG_KEY_INTERVAL) {
                    return '60';
                }
                return $default;
            });

        $job = $this->buildJob();
        $this->assertSame(
            expected: FeedRefreshJob::MIN_INTERVAL,
            actual: $job->getInterval()
        );
    }//end testConstructorClampsBelowMinimum()

    /**
     * Constructor clamps above MAX. (172800 → 86400.)
     *
     * @return void
     */
    public function testConstructorClampsAboveMaximum(): void
    {
        $this->config->method('getAppValue')
            ->willReturnCallback(static function (string $app, string $key, string $default): string {
                if ($key === FeedRefreshJob::CONFIG_KEY_INTERVAL) {
                    return '172800';
                }
                return $default;
            });

        $job = $this->buildJob();
        $this->assertSame(
            expected: FeedRefreshJob::MAX_INTERVAL,
            actual: $job->getInterval()
        );
    }//end testConstructorClampsAboveMaximum()

    /**
     * Default interval is honoured when no app-config value is set.
     *
     * @return void
     */
    public function testConstructorAppliesDefaultWhenUnset(): void
    {
        $this->config->method('getAppValue')
            ->willReturnCallback(static function (string $app, string $key, string $default): string {
                return $default;
            });

        $job = $this->buildJob();
        $this->assertSame(
            expected: FeedRefreshJob::DEFAULT_INTERVAL,
            actual: $job->getInterval()
        );
    }//end testConstructorAppliesDefaultWhenUnset()

    /**
     * `run` acquires the global lock, calls the refresh service, and
     * releases the lock in the `finally` block — even when the service
     * throws (REQ-FRJ-007 "Lock released on unhandled exception").
     *
     * @return void
     */
    public function testRunAcquiresAndReleasesLockOnException(): void
    {
        $this->config->method('getAppValue')
            ->willReturnCallback(static function (string $app, string $key, string $default): string {
                return $default;
            });

        $this->lockingProvider->expects($this->once())
            ->method('acquireLock')
            ->with(FeedRefreshJob::LOCK_ID, ILockingProvider::LOCK_EXCLUSIVE);

        $this->refreshService->expects($this->once())
            ->method('refreshAll')
            ->willThrowException(new RuntimeException('downstream failure'));

        $this->lockingProvider->expects($this->once())
            ->method('releaseLock')
            ->with(FeedRefreshJob::LOCK_ID, ILockingProvider::LOCK_EXCLUSIVE);

        $job = $this->buildJob();
        $this->invokeRun(job: $job);
        $this->assertTrue(condition: true);
    }//end testRunAcquiresAndReleasesLockOnException()

    /**
     * `run` skips work when the lock is already held — no refresh, no
     * release of a lock we never acquired (REQ-FRJ-007 "Second
     * concurrent invocation exits without processing").
     *
     * @return void
     */
    public function testRunSkipsWhenLockHeld(): void
    {
        $this->config->method('getAppValue')
            ->willReturnCallback(static function (string $app, string $key, string $default): string {
                return $default;
            });

        $this->lockingProvider->expects($this->once())
            ->method('acquireLock')
            ->willThrowException(new LockedException(path: FeedRefreshJob::LOCK_ID));

        $this->refreshService->expects($this->never())->method('refreshAll');
        $this->lockingProvider->expects($this->never())->method('releaseLock');

        $job = $this->buildJob();
        $this->invokeRun(job: $job);
        $this->assertTrue(condition: true);
    }//end testRunSkipsWhenLockHeld()

    /**
     * `run` releases the lock cleanly on the happy path with the
     * aggregate summary logged at INFO level.
     *
     * @return void
     */
    public function testRunHappyPathReleasesLock(): void
    {
        $this->config->method('getAppValue')
            ->willReturnCallback(static function (string $app, string $key, string $default): string {
                return $default;
            });

        $this->lockingProvider->expects($this->once())->method('acquireLock');
        $this->refreshService->expects($this->once())
            ->method('refreshAll')
            ->willReturn([
                'processedCount' => 3,
                'successCount'   => 2,
                'failureCount'   => 1,
                'durationMs'     => 42,
            ]);
        $this->lockingProvider->expects($this->once())->method('releaseLock');

        $job = $this->buildJob();
        $this->invokeRun(job: $job);
        $this->assertTrue(condition: true);
    }//end testRunHappyPathReleasesLock()

    /**
     * Build the job under test using the current mock state.
     *
     * @return FeedRefreshJob
     */
    private function buildJob(): FeedRefreshJob
    {
        return new FeedRefreshJob(
            time: $this->time,
            config: $this->config,
            refreshService: $this->refreshService,
            lockingProvider: $this->lockingProvider,
            logger: $this->logger,
        );
    }//end buildJob()

    /**
     * Invoke the protected `run` method via reflection.
     *
     * @param FeedRefreshJob $job The job under test.
     *
     * @return void
     */
    private function invokeRun(FeedRefreshJob $job): void
    {
        $ref    = new \ReflectionClass(objectOrClass: $job);
        $method = $ref->getMethod(name: 'run');
        $method->setAccessible(accessible: true);
        $method->invoke($job, null);
    }//end invokeRun()
}//end class
