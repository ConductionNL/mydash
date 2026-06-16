<?php

/**
 * UniqueViewerDedup Test
 *
 * Unit tests for the privacy-preserving unique-viewer dedup
 * service (REQ-ANLT-003).
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\MyDash\Service\UniqueViewerDedup;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

class UniqueViewerDedupTest extends TestCase
{
    private IAppConfig $appConfig;
    private ICacheFactory $cacheFactory;
    private ICache $cache;
    private UniqueViewerDedup $dedup;

    protected function setUp(): void
    {
        $this->appConfig    = $this->createMock(originalClassName: IAppConfig::class);
        $this->cacheFactory = $this->createMock(originalClassName: ICacheFactory::class);
        $this->cache        = $this->createMock(originalClassName: ICache::class);

        $this->cacheFactory->method('createDistributed')->willReturn(
            $this->cache
        );

        $this->dedup = new UniqueViewerDedup(
            cacheFactory: $this->cacheFactory,
            appConfig: $this->appConfig,
        );
    }

    public function testUtcDateForFormatsCorrectly(): void
    {
        $when = new DateTimeImmutable(
            datetime: '2026-05-01 23:30:00',
            timezone: new DateTimeZone(timezone: 'UTC')
        );

        $this->assertSame(
            '2026-05-01',
            UniqueViewerDedup::utcDateFor(when: $when)
        );
    }

    public function testSecondsUntilNextUtcMidnightIsPositive(): void
    {
        $when = new DateTimeImmutable(
            datetime: '2026-05-01 23:30:00',
            timezone: new DateTimeZone(timezone: 'UTC')
        );

        $delta = UniqueViewerDedup::secondsUntilNextUtcMidnight(when: $when);

        // 30 minutes = 1800 seconds.
        $this->assertSame(1800, $delta);
    }

    public function testSecondsUntilNextUtcMidnightFloorsAtOne(): void
    {
        // Exactly midnight — the modify('tomorrow') still goes a day
        // forward so this is 86400 not 0; check at one second before
        // midnight.
        $when = new DateTimeImmutable(
            datetime: '2026-05-01 23:59:59',
            timezone: new DateTimeZone(timezone: 'UTC')
        );

        $delta = UniqueViewerDedup::secondsUntilNextUtcMidnight(when: $when);

        $this->assertSame(1, $delta);
    }

    public function testGetSaltForDateReturnsExistingWhenDateMatches(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturnMap(
                valueMap: [
                    [
                        'mydash',
                        UniqueViewerDedup::CONFIG_KEY_SALT,
                        '',
                        false,
                        'cafef00d',
                    ],
                    [
                        'mydash',
                        UniqueViewerDedup::CONFIG_KEY_SALT_DATE,
                        '',
                        false,
                        '2026-05-01',
                    ],
                ]
            );
        $this->appConfig->expects($this->never())->method('setValueString');

        $salt = $this->dedup->getSaltForDate(viewBucketDate: '2026-05-01');

        $this->assertSame('cafef00d', $salt);
    }

    public function testGetSaltForDateRotatesWhenDateIsStale(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturnMap(
                valueMap: [
                    [
                        'mydash',
                        UniqueViewerDedup::CONFIG_KEY_SALT,
                        '',
                        false,
                        'oldsalt',
                    ],
                    [
                        'mydash',
                        UniqueViewerDedup::CONFIG_KEY_SALT_DATE,
                        '',
                        false,
                        '2026-04-30',
                    ],
                ]
            );

        $captured = [];
        $this->appConfig
            ->expects($this->exactly(2))
            ->method('setValueString')
            ->willReturnCallback(
                static function (
                    string $app,
                    string $key,
                    string $value
                ) use (&$captured): bool {
                    $captured[$key] = $value;

                    return true;
                }
            );

        $salt = $this->dedup->getSaltForDate(viewBucketDate: '2026-05-01');

        $this->assertNotSame('oldsalt', $salt);
        $this->assertSame(64, strlen(string: $salt));
        $this->assertSame(
            $salt,
            $captured[UniqueViewerDedup::CONFIG_KEY_SALT]
        );
        $this->assertSame(
            '2026-05-01',
            $captured[UniqueViewerDedup::CONFIG_KEY_SALT_DATE]
        );
    }

    public function testIsNewUniqueViewerReturnsTrueOnFirstView(): void
    {
        $this->appConfig->method('getValueString')->willReturnCallback(
            static function (string $a, string $k, string $d): string {
                if ($k === UniqueViewerDedup::CONFIG_KEY_SALT) {
                    return 'fixed-salt-value';
                }
                if ($k === UniqueViewerDedup::CONFIG_KEY_SALT_DATE) {
                    return '2026-05-01';
                }
                return $d;
            }
        );
        $this->cache->method('hasKey')->willReturn(false);
        $this->cache->expects($this->once())->method('set');

        $result = $this->dedup->isNewUniqueViewer(
            userId: 'alice',
            viewBucketDate: '2026-05-01',
            dashboardUuid: 'uuid-1'
        );

        $this->assertTrue($result);
    }

    public function testIsNewUniqueViewerReturnsFalseOnRepeat(): void
    {
        $this->appConfig->method('getValueString')->willReturnCallback(
            static function (string $a, string $k, string $d): string {
                if ($k === UniqueViewerDedup::CONFIG_KEY_SALT) {
                    return 'fixed-salt-value';
                }
                if ($k === UniqueViewerDedup::CONFIG_KEY_SALT_DATE) {
                    return '2026-05-01';
                }
                return $d;
            }
        );
        $this->cache->method('hasKey')->willReturn(true);
        $this->cache->expects($this->never())->method('set');


        $result = $this->dedup->isNewUniqueViewer(
            userId: 'alice',
            viewBucketDate: '2026-05-01',
            dashboardUuid: 'uuid-1'
        );

        $this->assertFalse($result);
    }

    public function testHashUserForDateIsDeterministicWithSameSalt(): void
    {
        $this->appConfig->method('getValueString')->willReturnCallback(
            static function (string $a, string $k, string $d): string {
                if ($k === UniqueViewerDedup::CONFIG_KEY_SALT) {
                    return 'fixed-salt';
                }
                if ($k === UniqueViewerDedup::CONFIG_KEY_SALT_DATE) {
                    return '2026-05-01';
                }
                return $d;
            }
        );

        $first  = $this->dedup->hashUserForDate(
            userId: 'alice',
            viewBucketDate: '2026-05-01'
        );
        $second = $this->dedup->hashUserForDate(
            userId: 'alice',
            viewBucketDate: '2026-05-01'
        );

        $this->assertSame($first, $second);
        $this->assertSame(64, strlen(string: $first));
    }

    public function testRotateSaltOverwritesPreviousValueWithoutHistory(): void
    {
        $writes = [];
        $this->appConfig
            ->expects($this->exactly(2))
            ->method('setValueString')
            ->willReturnCallback(
                static function (string $a, string $k, string $v) use (&$writes): bool {
                    $writes[$k] = $v;
                    return true;
                }
            );

        $newSalt = $this->dedup->rotateSalt(viewBucketDate: '2026-05-02');

        $this->assertSame(64, strlen(string: $newSalt));
        $this->assertSame(
            $newSalt,
            $writes[UniqueViewerDedup::CONFIG_KEY_SALT]
        );
        $this->assertSame(
            '2026-05-02',
            $writes[UniqueViewerDedup::CONFIG_KEY_SALT_DATE]
        );
    }
}
