<?php

/**
 * AnalyticsService Test
 *
 * Unit tests for the dashboard view-analytics aggregation service
 * (REQ-ANLT-001..010).
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

use InvalidArgumentException;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\DashboardView;
use OCA\MyDash\Db\DashboardViewMapper;
use OCA\MyDash\Service\AnalyticsService;
use OCA\MyDash\Service\UniqueViewerDedup;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class AnalyticsServiceTest extends TestCase
{
    private DashboardViewMapper $viewMapper;
    private DashboardMapper $dashboardMapper;
    private UniqueViewerDedup $dedup;
    private IAppConfig $appConfig;
    private IConfig $config;
    private AnalyticsService $service;

    protected function setUp(): void
    {
        $this->viewMapper      = $this->createMock(
            originalClassName: DashboardViewMapper::class
        );
        $this->dashboardMapper = $this->createMock(
            originalClassName: DashboardMapper::class
        );
        $this->dedup           = $this->createMock(
            originalClassName: UniqueViewerDedup::class
        );
        $this->appConfig       = $this->createMock(
            originalClassName: IAppConfig::class
        );
        $this->config          = $this->createMock(
            originalClassName: IConfig::class
        );

        $this->service = new AnalyticsService(
            viewMapper: $this->viewMapper,
            dashboardMapper: $this->dashboardMapper,
            dedup: $this->dedup,
            appConfig: $this->appConfig,
            config: $this->config,
        );
    }

    public function testIsGloballyEnabledDefaultsTrue(): void
    {
        $this->appConfig->method('getValueBool')->willReturn(true);

        $this->assertTrue($this->service->isGloballyEnabled());
    }

    public function testIsGloballyEnabledFalseWhenSetFalse(): void
    {
        $this->appConfig->method('getValueBool')->willReturn(false);

        $this->assertFalse($this->service->isGloballyEnabled());
    }

    public function testIsUserOptedOutDefaultsFalse(): void
    {
        $this->config->method('getUserValue')->willReturn('false');

        $this->assertFalse($this->service->isUserOptedOut(userId: 'alice'));
    }

    public function testGetRetentionDaysClampsBelowMinimum(): void
    {
        $this->appConfig->method('getValueInt')->willReturn(10);

        $this->assertSame(
            AnalyticsService::MIN_RETENTION_DAYS,
            $this->service->getRetentionDays()
        );
    }

    public function testGetRetentionDaysClampsAboveMaximum(): void
    {
        $this->appConfig->method('getValueInt')->willReturn(99999);

        $this->assertSame(
            AnalyticsService::MAX_RETENTION_DAYS,
            $this->service->getRetentionDays()
        );
    }

    public function testGetRetentionDaysReturnsValueInRange(): void
    {
        $this->appConfig->method('getValueInt')->willReturn(100);

        $this->assertSame(100, $this->service->getRetentionDays());
    }

    public function testSetRetentionDaysClampsAndPersists(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueInt')
            ->with(
                $this->equalTo('mydash'),
                $this->equalTo(AnalyticsService::CONFIG_KEY_RETENTION_DAYS),
                $this->equalTo(30)
            );

        $clamped = $this->service->setRetentionDays(days: 5);

        $this->assertSame(30, $clamped);
    }

    public function testRecordViewEventThrowsWhenDashboardMissing(): void
    {
        $this->dashboardMapper
            ->method('findByUuid')
            ->willThrowException(
                exception: new DoesNotExistException(msg: 'gone')
            );

        $this->expectException(exception: DoesNotExistException::class);

        $this->service->recordViewEvent(
            dashboardUuid: 'no-such-uuid',
            userId: 'alice'
        );
    }

    public function testRecordViewEventSkipsWhenGloballyDisabled(): void
    {
        $this->dashboardMapper
            ->method('findByUuid')
            ->willReturn(value: new Dashboard());
        $this->appConfig->method('getValueBool')->willReturn(false);
        $this->viewMapper->expects($this->never())->method('upsertView');

        $result = $this->service->recordViewEvent(
            dashboardUuid: 'uuid-1',
            userId: 'alice'
        );

        $this->assertFalse($result);
    }

    public function testRecordViewEventSkipsWhenUserOptedOut(): void
    {
        $this->dashboardMapper
            ->method('findByUuid')
            ->willReturn(value: new Dashboard());
        $this->appConfig->method('getValueBool')->willReturn(true);
        $this->config->method('getUserValue')->willReturn('true');
        $this->viewMapper->expects($this->never())->method('upsertView');

        $result = $this->service->recordViewEvent(
            dashboardUuid: 'uuid-1',
            userId: 'alice'
        );

        $this->assertFalse($result);
    }

    public function testRecordViewEventIncrementsBothCountersForNewViewer(): void
    {
        $this->dashboardMapper
            ->method('findByUuid')
            ->willReturn(value: new Dashboard());
        $this->appConfig->method('getValueBool')->willReturn(true);
        $this->config->method('getUserValue')->willReturn('false');
        $this->dedup->method('isNewUniqueViewer')->willReturn(true);

        $this->viewMapper->expects($this->once())
            ->method('upsertView')
            ->with(
                $this->isType(type: 'string'),
                $this->isType(type: 'string'),
                1,
                1
            );

        $result = $this->service->recordViewEvent(
            dashboardUuid: 'uuid-1',
            userId: 'alice'
        );

        $this->assertTrue($result);
    }

    public function testRecordViewEventLeavesUniqueAtZeroForRepeatViewer(): void
    {
        $this->dashboardMapper
            ->method('findByUuid')
            ->willReturn(value: new Dashboard());
        $this->appConfig->method('getValueBool')->willReturn(true);
        $this->config->method('getUserValue')->willReturn('false');
        $this->dedup->method('isNewUniqueViewer')->willReturn(false);

        $this->viewMapper->expects($this->once())
            ->method('upsertView')
            ->with(
                $this->isType(type: 'string'),
                $this->isType(type: 'string'),
                1,
                0
            );

        $result = $this->service->recordViewEvent(
            dashboardUuid: 'uuid-1',
            userId: 'alice'
        );

        $this->assertTrue($result);
    }

    public function testPeriodToDateRangeRejectsUnknownPeriod(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);

        AnalyticsService::periodToDateRange(period: '42d');
    }

    public function testPeriodToDateRangeReturnsTwoDates(): void
    {
        [$start, $end] = AnalyticsService::periodToDateRange(period: '7d');

        $this->assertMatchesRegularExpression(
            pattern: '/^\d{4}-\d{2}-\d{2}$/',
            string: $start
        );
        $this->assertMatchesRegularExpression(
            pattern: '/^\d{4}-\d{2}-\d{2}$/',
            string: $end
        );
        $this->assertLessThanOrEqual($end, $start);
    }

    public function testGetTopDashboardsAttachesNames(): void
    {
        $this->viewMapper->method('findTopDashboardsInRange')
            ->willReturn(
                value: [
                    [
                        'dashboardUuid'     => 'uuid-1',
                        'viewCount'         => 50,
                        'uniqueViewerCount' => 20,
                    ],
                ]
            );
        $dashboard = new Dashboard();
        $dashboard->setName('Marketing');
        $this->dashboardMapper->method('findByUuid')->willReturn(
            value: $dashboard
        );

        $rows = $this->service->getTopDashboards(period: '7d', limit: 10);

        $this->assertCount(1, $rows);
        $this->assertSame('Marketing', $rows[0]['name']);
        $this->assertSame(50, $rows[0]['viewCount']);
    }

    public function testGetTopDashboardsHandlesOrphanRows(): void
    {
        $this->viewMapper->method('findTopDashboardsInRange')
            ->willReturn(
                value: [
                    [
                        'dashboardUuid'     => 'orphan-uuid',
                        'viewCount'         => 5,
                        'uniqueViewerCount' => 2,
                    ],
                ]
            );
        $this->dashboardMapper->method('findByUuid')
            ->willThrowException(
                exception: new DoesNotExistException(msg: 'gone')
            );

        $rows = $this->service->getTopDashboards(period: '30d', limit: 10);

        $this->assertNull($rows[0]['name']);
        $this->assertSame(5, $rows[0]['viewCount']);
    }

    public function testGetDashboardDetailReturnsArrayOfDailyRecords(): void
    {
        $this->dashboardMapper->method('findByUuid')->willReturn(
            value: new Dashboard()
        );
        $row = new DashboardView();
        $row->setDashboardUuid('uuid-1');
        $row->setViewBucket('2026-05-01');
        $row->setViewCount(8);
        $row->setUniqueViewerCount(4);
        $this->viewMapper->method('findByDashboardInRange')->willReturn(
            value: [$row]
        );

        $rows = $this->service->getDashboardDetail(
            dashboardUuid: 'uuid-1',
            period: '7d'
        );

        $this->assertCount(1, $rows);
        $this->assertSame('2026-05-01', $rows[0]['viewBucket']);
        $this->assertSame(8, $rows[0]['viewCount']);
    }

    public function testGetDashboardDetailThrowsWhenDashboardMissing(): void
    {
        $this->dashboardMapper->method('findByUuid')->willThrowException(
            exception: new DoesNotExistException(msg: 'gone')
        );

        $this->expectException(exception: DoesNotExistException::class);

        $this->service->getDashboardDetail(
            dashboardUuid: 'no-such',
            period: '7d'
        );
    }

    public function testGenerateCsvExportProducesHeaderAndRows(): void
    {
        $row = new DashboardView();
        $row->setDashboardUuid('uuid-1');
        $row->setViewBucket('2026-05-01');
        $row->setViewCount(2);
        $row->setUniqueViewerCount(1);
        $this->viewMapper->method('findAllInRange')->willReturn(
            value: [$row]
        );
        $dashboard = new Dashboard();
        $dashboard->setName('Marketing');
        $this->dashboardMapper->method('findByUuid')->willReturn(
            value: $dashboard
        );

        $csv = $this->service->generateCsvExport(period: '7d');

        $this->assertStringContainsString(
            needle: 'dashboardUuid',
            haystack: $csv
        );
        $this->assertStringContainsString(
            needle: 'Marketing',
            haystack: $csv
        );
        $this->assertStringContainsString(
            needle: '2026-05-01',
            haystack: $csv
        );
    }

    public function testCsvExportFilenameMatchesExpectedPattern(): void
    {
        $name = $this->service->csvExportFilename();

        $this->assertMatchesRegularExpression(
            pattern: '/^dashboard-analytics-\d{4}-\d{2}-\d{2}\.csv$/',
            string: $name
        );
    }

    /**
     * M1: CSV injection — dashboard names starting with formula trigger
     * characters (=, +, -, @, tab, CR) MUST be prefixed with a
     * single-quote so spreadsheet apps treat them as text.
     */
    public function testCsvExportPrefixesFormulaTriggerCharacters(): void
    {
        $formulaNames = [
            '=SUM(A1)',
            '+profit',
            '-loss',
            '@domain',
        ];

        foreach ($formulaNames as $name) {
            $row = new \OCA\MyDash\Db\DashboardView();
            $row->setDashboardUuid('uuid-formula');
            $row->setViewBucket('2026-05-01');
            $row->setViewCount(1);
            $row->setUniqueViewerCount(1);
            $this->viewMapper->method('findAllInRange')->willReturn([$row]);

            $dashboard = new \OCA\MyDash\Db\Dashboard();
            $dashboard->setName($name);
            $this->dashboardMapper->method('findByUuid')->willReturn($dashboard);

            $csv = $this->service->generateCsvExport(period: '7d');

            // The cell must NOT contain the raw trigger character directly
            // after the opening double-quote.
            $trigger = $name[0];
            $this->assertStringNotContainsString(
                needle: '"'.$trigger,
                haystack: $csv,
                message: "Formula trigger '{$trigger}' must be prefixed with single-quote in CSV"
            );
            // Reset mock state.
            $this->viewMapper = $this->createMock(\OCA\MyDash\Db\DashboardViewMapper::class);
            $this->dashboardMapper = $this->createMock(\OCA\MyDash\Db\DashboardMapper::class);
            $this->setUp();
        }
    }
}
