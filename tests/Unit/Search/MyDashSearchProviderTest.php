<?php

/**
 * MyDashSearchProviderTest
 *
 * Unit coverage for the Nextcloud unified-search provider
 * (REQ-SRCH-001..012). All collaborators are mocked so the test
 * exercises orchestration only — no DB or HTTP I/O.
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Search
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Search;

use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Search\MyDashSearchProvider;
use OCA\MyDash\Service\DashboardService;
use OCA\MyDash\Service\MetadataService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\L10N\IFactory;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MyDashSearchProviderTest extends TestCase
{
    /** @var DashboardService&MockObject */
    private DashboardService $dashboardService;

    /** @var WidgetPlacementMapper&MockObject */
    private WidgetPlacementMapper $placementMapper;

    /** @var MetadataService&MockObject */
    private MetadataService $metadataService;

    /** @var IFactory&MockObject */
    private IFactory $l10nFactory;

    /** @var IURLGenerator&MockObject */
    private IURLGenerator $urlGenerator;

    private MyDashSearchProvider $provider;

    protected function setUp(): void
    {
        $this->dashboardService = $this->createMock(DashboardService::class);
        $this->placementMapper  = $this->createMock(WidgetPlacementMapper::class);
        $this->metadataService  = $this->createMock(MetadataService::class);
        $this->l10nFactory      = $this->createMock(IFactory::class);
        $this->urlGenerator     = $this->createMock(IURLGenerator::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static function (string $text, array $params = []): string {
                if ($params === []) {
                    return $text;
                }
                return vsprintf($text, $params);
            }
        );
        $this->l10nFactory->method('get')->willReturn($l10n);

        $this->urlGenerator->method('linkToRouteAbsolute')
            ->willReturnCallback(
                static fn (string $routeName): string => 'https://nc.example/apps/mydash/'
            );
        $this->urlGenerator->method('imagePath')
            ->willReturnCallback(
                static fn (string $appName, string $file): string => '/apps/' . $appName . '/img/' . $file
            );
        $this->urlGenerator->method('getAbsoluteURL')
            ->willReturnCallback(
                static fn (string $url): string => 'https://nc.example' . $url
            );

        $this->provider = new MyDashSearchProvider(
            dashboardService: $this->dashboardService,
            placementMapper: $this->placementMapper,
            metadataService: $this->metadataService,
            l10nFactory: $this->l10nFactory,
            urlGenerator: $this->urlGenerator,
        );
    }

    private function makeDashboard(int $id, string $uuid, string $name, string $description = ''): Dashboard
    {
        $dashboard = new Dashboard();
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $dashboard->setId($id);
        $dashboard->setUuid($uuid);
        $dashboard->setName($name);
        $dashboard->setDescription($description);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        return $dashboard;
    }

    private function makePlacement(int $id, int $dashboardId, ?string $styleConfig): WidgetPlacement
    {
        $placement = new WidgetPlacement();
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $placement->setId($id);
        $placement->setDashboardId($dashboardId);
        $placement->setWidgetId('text-display');
        $placement->setStyleConfig($styleConfig);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        return $placement;
    }

    private function makeQuery(string $term): ISearchQuery
    {
        $query = $this->createMock(ISearchQuery::class);
        $query->method('getTerm')->willReturn($term);
        $query->method('getCursor')->willReturn(null);
        $query->method('getLimit')->willReturn(20);
        return $query;
    }

    private function makeUser(string $uid): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }

    public function testGetIdReturnsMydash(): void
    {
        self::assertSame('mydash', $this->provider->getId());
    }

    public function testGetNameIsTranslated(): void
    {
        self::assertSame('Dashboards', $this->provider->getName());
    }

    public function testGetOrderIsMidRange(): void
    {
        self::assertSame(50, $this->provider->getOrder('files.list', []));
    }

    public function testGetOrderBoostsMyDashRoute(): void
    {
        self::assertSame(5, $this->provider->getOrder('mydash.page.index', []));
    }

    public function testEmptyTermReturnsEmptyResult(): void
    {
        $this->dashboardService->expects(self::never())->method('getVisibleToUser');

        $result = $this->provider->search(
            $this->makeUser('alice'),
            $this->makeQuery('   ')
        );

        self::assertInstanceOf(SearchResult::class, $result);
        $payload = $result->jsonSerialize();
        self::assertSame([], $payload['entries']);
    }

    public function testDashboardNameMatchAppears(): void
    {
        $dashboard = $this->makeDashboard(1, 'uuid-1', 'Marketing Campaign 2026', 'Q1 launch');
        $this->dashboardService->method('getVisibleToUser')->with('alice')->willReturn([
            ['dashboard' => $dashboard, 'source' => Dashboard::SOURCE_USER],
        ]);
        $this->placementMapper->method('findByDashboardId')->willReturn([]);
        $this->metadataService->method('getMetadataForDashboard')->willReturn([]);

        $result  = $this->provider->search($this->makeUser('alice'), $this->makeQuery('mark'));
        $entries = $result->jsonSerialize()['entries'];

        self::assertCount(1, $entries);
        self::assertSame('Marketing Campaign 2026', $entries[0]->jsonSerialize()['title']);
        self::assertStringContainsString('uuid-1', $entries[0]->jsonSerialize()['resourceUrl']);
    }

    public function testDashboardDescriptionMatchAppears(): void
    {
        $dashboard = $this->makeDashboard(2, 'uuid-2', 'Q1 Metrics', 'Quarterly performance overview');
        $this->dashboardService->method('getVisibleToUser')->willReturn([
            ['dashboard' => $dashboard, 'source' => Dashboard::SOURCE_USER],
        ]);
        $this->placementMapper->method('findByDashboardId')->willReturn([]);
        $this->metadataService->method('getMetadataForDashboard')->willReturn([]);

        $entries = $this->provider->search(
            $this->makeUser('alice'),
            $this->makeQuery('quarterly')
        )->jsonSerialize()['entries'];

        self::assertCount(1, $entries);
        self::assertSame('Q1 Metrics', $entries[0]->jsonSerialize()['title']);
    }

    public function testCaseInsensitiveSubstringMatch(): void
    {
        $dashboard = $this->makeDashboard(3, 'uuid-3', 'Sales Pipeline Analysis');
        $this->dashboardService->method('getVisibleToUser')->willReturn([
            ['dashboard' => $dashboard, 'source' => Dashboard::SOURCE_USER],
        ]);
        $this->placementMapper->method('findByDashboardId')->willReturn([]);
        $this->metadataService->method('getMetadataForDashboard')->willReturn([]);

        $entries = $this->provider->search(
            $this->makeUser('alice'),
            $this->makeQuery('PIPE')
        )->jsonSerialize()['entries'];

        self::assertCount(1, $entries);
    }

    public function testNoMatchReturnsEmptyResult(): void
    {
        $dashboard = $this->makeDashboard(4, 'uuid-4', 'Sales');
        $this->dashboardService->method('getVisibleToUser')->willReturn([
            ['dashboard' => $dashboard, 'source' => Dashboard::SOURCE_USER],
        ]);
        $this->placementMapper->method('findByDashboardId')->willReturn([]);
        $this->metadataService->method('getMetadataForDashboard')->willReturn([]);

        $entries = $this->provider->search(
            $this->makeUser('alice'),
            $this->makeQuery('engineering')
        )->jsonSerialize()['entries'];

        self::assertSame([], $entries);
    }

    public function testWidgetContentMatchProducesDeepLink(): void
    {
        $dashboard = $this->makeDashboard(5, 'uuid-5', 'Analytics');
        $placement = $this->makePlacement(
            id: 42,
            dashboardId: 5,
            styleConfig: json_encode([
                'type'    => 'text',
                'content' => ['text' => 'Budget proposal for Q2'],
            ])
        );
        $this->dashboardService->method('getVisibleToUser')->willReturn([
            ['dashboard' => $dashboard, 'source' => Dashboard::SOURCE_USER],
        ]);
        $this->placementMapper->method('findByDashboardId')->with(5)->willReturn([$placement]);
        $this->metadataService->method('getMetadataForDashboard')->willReturn([]);

        $entries = $this->provider->search(
            $this->makeUser('alice'),
            $this->makeQuery('budget')
        )->jsonSerialize()['entries'];

        self::assertCount(1, $entries);
        $entry = $entries[0]->jsonSerialize();
        self::assertSame('Analytics', $entry['title']);
        self::assertSame('Widget content on Analytics', $entry['subline']);
        self::assertStringContainsString('uuid-5', $entry['resourceUrl']);
        self::assertStringContainsString('widget=42', $entry['resourceUrl']);
    }

    public function testWidgetMatchStripsHtmlTags(): void
    {
        $dashboard = $this->makeDashboard(6, 'uuid-6', 'Reports');
        $placement = $this->makePlacement(
            id: 7,
            dashboardId: 6,
            styleConfig: json_encode([
                'type'    => 'text',
                'content' => ['text' => '<h1>Revenue Up 15%</h1>'],
            ])
        );
        $this->dashboardService->method('getVisibleToUser')->willReturn([
            ['dashboard' => $dashboard, 'source' => Dashboard::SOURCE_USER],
        ]);
        $this->placementMapper->method('findByDashboardId')->willReturn([$placement]);
        $this->metadataService->method('getMetadataForDashboard')->willReturn([]);

        $entries = $this->provider->search(
            $this->makeUser('alice'),
            $this->makeQuery('revenue')
        )->jsonSerialize()['entries'];

        self::assertCount(1, $entries);
    }

    public function testNonTextWidgetIsSkipped(): void
    {
        $dashboard = $this->makeDashboard(7, 'uuid-7', 'Misc');
        // styleConfig with a non-`text` discriminator must NOT be searched.
        $placement = $this->makePlacement(
            id: 9,
            dashboardId: 7,
            styleConfig: json_encode([
                'type'    => 'image',
                'content' => ['caption' => 'budget alt text'],
            ])
        );
        $this->dashboardService->method('getVisibleToUser')->willReturn([
            ['dashboard' => $dashboard, 'source' => Dashboard::SOURCE_USER],
        ]);
        $this->placementMapper->method('findByDashboardId')->willReturn([$placement]);
        $this->metadataService->method('getMetadataForDashboard')->willReturn([]);

        $entries = $this->provider->search(
            $this->makeUser('alice'),
            $this->makeQuery('budget')
        )->jsonSerialize()['entries'];

        self::assertSame([], $entries);
    }

    public function testMetadataValueMatchAppears(): void
    {
        $dashboard = $this->makeDashboard(8, 'uuid-8', 'Project Tracker');
        $this->dashboardService->method('getVisibleToUser')->willReturn([
            ['dashboard' => $dashboard, 'source' => Dashboard::SOURCE_USER],
        ]);
        $this->placementMapper->method('findByDashboardId')->willReturn([]);
        $this->metadataService->method('getMetadataForDashboard')->with('uuid-8')->willReturn([
            'year'       => '2026',
            'department' => 'Sales',
        ]);

        $entries = $this->provider->search(
            $this->makeUser('alice'),
            $this->makeQuery('2026')
        )->jsonSerialize()['entries'];

        self::assertCount(1, $entries);
        self::assertSame('Project Tracker', $entries[0]->jsonSerialize()['title']);
        self::assertSame('Metadata: year = 2026', $entries[0]->jsonSerialize()['subline']);
    }

    public function testMetadataKeyIsNotSearched(): void
    {
        $dashboard = $this->makeDashboard(9, 'uuid-9', 'X');
        $this->dashboardService->method('getVisibleToUser')->willReturn([
            ['dashboard' => $dashboard, 'source' => Dashboard::SOURCE_USER],
        ]);
        $this->placementMapper->method('findByDashboardId')->willReturn([]);
        $this->metadataService->method('getMetadataForDashboard')->willReturn([
            'projectStatus' => 'In Progress',
        ]);

        $entries = $this->provider->search(
            $this->makeUser('alice'),
            $this->makeQuery('projectStatus')
        )->jsonSerialize()['entries'];

        self::assertSame([], $entries);
    }

    public function testPermissionDeniedDashboardsAreNotReturned(): void
    {
        // The visibility boundary is the canonical permission gate
        // (REQ-SRCH-005). Dashboards bob cannot view never reach the
        // provider's match logic.
        $alicesDashboard = $this->makeDashboard(10, 'uuid-10', 'Alices Dash');
        $this->dashboardService->method('getVisibleToUser')
            ->with('bob')
            ->willReturn([]);

        $entries = $this->provider->search(
            $this->makeUser('bob'),
            $this->makeQuery('alices')
        )->jsonSerialize()['entries'];

        self::assertSame([], $entries);
    }

    public function testGetVisibleToUserFailureReturnsEmpty(): void
    {
        $this->dashboardService->method('getVisibleToUser')
            ->willThrowException(new \RuntimeException('db gone'));

        $entries = $this->provider->search(
            $this->makeUser('alice'),
            $this->makeQuery('anything')
        )->jsonSerialize()['entries'];

        self::assertSame([], $entries);
    }

    public function testDashboardResultsCappedAtTen(): void
    {
        $visible = [];
        for ($i = 1; $i <= 25; $i++) {
            $visible[] = [
                'dashboard' => $this->makeDashboard($i, 'uuid-' . $i, 'Dash ' . $i),
                'source'    => Dashboard::SOURCE_USER,
            ];
        }
        $this->dashboardService->method('getVisibleToUser')->willReturn($visible);
        $this->placementMapper->method('findByDashboardId')->willReturn([]);
        $this->metadataService->method('getMetadataForDashboard')->willReturn([]);

        $entries = $this->provider->search(
            $this->makeUser('alice'),
            $this->makeQuery('dash')
        )->jsonSerialize()['entries'];

        self::assertCount(10, $entries);
    }

    public function testMalformedStyleConfigDoesNotCrash(): void
    {
        $dashboard = $this->makeDashboard(20, 'uuid-20', 'Edge cases');
        $placements = [
            $this->makePlacement(1, 20, '{not-json'),
            $this->makePlacement(2, 20, ''),
            $this->makePlacement(3, 20, null),
            $this->makePlacement(4, 20, json_encode(['type' => 'text', 'content' => null])),
        ];
        $this->dashboardService->method('getVisibleToUser')->willReturn([
            ['dashboard' => $dashboard, 'source' => Dashboard::SOURCE_USER],
        ]);
        $this->placementMapper->method('findByDashboardId')->willReturn($placements);
        $this->metadataService->method('getMetadataForDashboard')->willReturn([]);

        $entries = $this->provider->search(
            $this->makeUser('alice'),
            $this->makeQuery('edge')
        )->jsonSerialize()['entries'];

        // The dashboard name matches; widget placements degrade silently.
        self::assertCount(1, $entries);
        self::assertSame('Edge cases', $entries[0]->jsonSerialize()['title']);
    }
}
