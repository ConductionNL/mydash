<?php

/**
 * FeedServiceTest
 *
 * Unit tests for the {@see FeedService} (REQ-FEED-004..007): RSS / Atom
 * rendering, ACL filtering via DashboardService, item cap enforcement,
 * description escaping, and reverse-chronological ordering.
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

use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\FeedToken;
use OCA\MyDash\Service\DashboardService;
use OCA\MyDash\Service\FeedService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for FeedService (REQ-FEED-004..007).
 */
class FeedServiceTest extends TestCase
{

    /** @var DashboardService&MockObject */
    private $dashboardService;

    /** @var IUserManager&MockObject */
    private $userManager;

    /** @var IURLGenerator&MockObject */
    private $urlGenerator;

    /** @var IConfig&MockObject */
    private $config;

    /** @var IFactory&MockObject */
    private $l10nFactory;

    /** @var LoggerInterface&MockObject */
    private $logger;

    private FeedService $service;

    /**
     * Wire up mocks and a token-bearing IL10N stub that returns the
     * untranslated source string.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dashboardService = $this->createMock(DashboardService::class);
        $this->userManager      = $this->createMock(IUserManager::class);
        $this->urlGenerator     = $this->createMock(IURLGenerator::class);
        $this->config           = $this->createMock(IConfig::class);
        $this->l10nFactory      = $this->createMock(IFactory::class);
        $this->logger           = $this->createMock(LoggerInterface::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static function (string $text, array $params=[]): string {
                if ($params === []) {
                    return $text;
                }
                return vsprintf(format: $text, values: $params);
            }
        );
        $this->l10nFactory->method('get')->willReturn($l10n);

        $this->urlGenerator->method('linkToRouteAbsolute')
            ->willReturn('https://example.test/index.php/apps/mydash/');

        $this->service = new FeedService(
            dashboardService: $this->dashboardService,
            userManager: $this->userManager,
            urlGenerator: $this->urlGenerator,
            config: $this->config,
            l10nFactory: $this->l10nFactory,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * `xmlEscape` rewrites `&`, `<`, `>`, and `"` into entities.
     * (REQ-FEED-005 — "Dashboard description with special XML
     * characters".)
     *
     * @return void
     */
    public function testXmlEscapeReplacesSpecialChars(): void
    {
        $result = FeedService::xmlEscape(value: 'Profits & losses <Q2> "wow"');
        $this->assertStringContainsString(needle: '&amp;', haystack: $result);
        $this->assertStringContainsString(needle: '&lt;', haystack: $result);
        $this->assertStringContainsString(needle: '&gt;', haystack: $result);
        $this->assertStringContainsString(needle: '&quot;', haystack: $result);
    }//end testXmlEscapeReplacesSpecialChars()

    /**
     * `xmlEscape` returns an empty string for null inputs.
     *
     * @return void
     */
    public function testXmlEscapeReturnsEmptyForNull(): void
    {
        $this->assertSame(
            expected: '',
            actual: FeedService::xmlEscape(value: null)
        );
    }//end testXmlEscapeReturnsEmptyForNull()

    /**
     * `getOwnerDisplayName` falls back to the user ID when the user
     * record cannot be loaded. (REQ-FEED-005 — author always present.)
     *
     * @return void
     */
    public function testGetOwnerDisplayNameFallsBackWhenUserMissing(): void
    {
        $this->userManager->method('get')->willReturn(null);
        $this->assertSame(
            expected: 'unknown-user',
            actual: $this->service->getOwnerDisplayName(userId: 'unknown-user')
        );
    }//end testGetOwnerDisplayNameFallsBackWhenUserMissing()

    /**
     * `getOwnerDisplayName` returns the friendly display name when
     * the user exists.
     *
     * @return void
     */
    public function testGetOwnerDisplayNameReturnsFriendlyName(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getDisplayName')->willReturn('Alice Wonderland');
        $this->userManager->method('get')->willReturn($user);

        $this->assertSame(
            expected: 'Alice Wonderland',
            actual: $this->service->getOwnerDisplayName(userId: 'alice')
        );
    }//end testGetOwnerDisplayNameReturnsFriendlyName()

    /**
     * `renderFeed` enforces the item cap from `IConfig`. (REQ-FEED-007.)
     *
     * @return void
     */
    public function testRenderFeedEnforcesItemCap(): void
    {
        // Build 12 dashboards, set cap to 3 → expect exactly 3 <item> tags.
        $entries = [];
        for ($idx = 1; $idx <= 12; $idx++) {
            $entries[] = $this->buildVisibleEntry(
                uuid: 'uuid-'.$idx,
                name: 'Dashboard '.$idx,
                updatedAt: sprintf('2026-04-%02d 10:00:00', (30 - $idx))
            );
        }

        $this->dashboardService->method('getVisibleToUser')->willReturn($entries);
        $this->config->method('getAppValue')
            ->with('mydash', FeedService::CONFIG_KEY_ITEM_CAP, '50')
            ->willReturn('3');
        $this->stubUserManager(displayName: 'Owner Name');

        $token = $this->buildToken(userId: 'owner');
        $xml   = $this->service->renderFeed(token: $token);

        $this->assertSame(
            expected: 3,
            actual: substr_count(haystack: $xml, needle: '<item>')
        );
    }//end testRenderFeedEnforcesItemCap()

    /**
     * `renderFeed` orders items reverse-chronologically by updatedAt.
     * (REQ-FEED-005 — "Feed with multiple accessible dashboards".)
     *
     * @return void
     */
    public function testRenderFeedOrdersNewestFirst(): void
    {
        $entries = [
            $this->buildVisibleEntry(
                uuid: 'old',
                name: 'Old Dashboard',
                updatedAt: '2026-03-01 10:00:00'
            ),
            $this->buildVisibleEntry(
                uuid: 'newer',
                name: 'Newer Dashboard',
                updatedAt: '2026-04-15 10:00:00'
            ),
            $this->buildVisibleEntry(
                uuid: 'newest',
                name: 'Newest Dashboard',
                updatedAt: '2026-04-20 10:00:00'
            ),
        ];

        $this->dashboardService->method('getVisibleToUser')->willReturn($entries);
        $this->config->method('getAppValue')->willReturn('50');
        $this->stubUserManager(displayName: 'Iris');

        $xml = $this->service->renderFeed(
            token: $this->buildToken(userId: 'iris')
        );

        $newestPos = strpos(haystack: $xml, needle: 'Newest Dashboard');
        $newerPos  = strpos(haystack: $xml, needle: 'Newer Dashboard');
        $oldPos    = strpos(haystack: $xml, needle: 'Old Dashboard');

        $this->assertNotFalse(condition: $newestPos);
        $this->assertNotFalse(condition: $newerPos);
        $this->assertNotFalse(condition: $oldPos);
        $this->assertLessThan(expected: $newerPos, actual: $newestPos);
        $this->assertLessThan(expected: $oldPos, actual: $newerPos);
    }//end testRenderFeedOrdersNewestFirst()

    /**
     * `renderFeed` escapes special characters in dashboard
     * descriptions. (REQ-FEED-005.)
     *
     * @return void
     */
    public function testRenderFeedEscapesSpecialCharactersInDescription(): void
    {
        $entries = [
            $this->buildVisibleEntry(
                uuid: 'special',
                name: 'Special',
                updatedAt: '2026-04-20 10:00:00',
                description: 'Profits & losses <Q2>'
            ),
        ];

        $this->dashboardService->method('getVisibleToUser')->willReturn($entries);
        $this->config->method('getAppValue')->willReturn('50');
        $this->stubUserManager(displayName: 'Owner');

        $xml = $this->service->renderFeed(
            token: $this->buildToken(userId: 'owner')
        );

        $this->assertStringContainsString(
            needle: '<description>Profits &amp; losses &lt;Q2&gt;</description>',
            haystack: $xml
        );
    }//end testRenderFeedEscapesSpecialCharactersInDescription()

    /**
     * `renderFeed` produces a valid RSS 2.0 channel skeleton.
     *
     * @return void
     */
    public function testRenderFeedProducesRssRoot(): void
    {
        $this->dashboardService->method('getVisibleToUser')->willReturn([]);
        $this->config->method('getAppValue')->willReturn('50');
        $this->stubUserManager(displayName: 'Owner');

        $xml = $this->service->renderFeed(
            token: $this->buildToken(userId: 'owner')
        );

        $this->assertStringStartsWith(
            prefix: '<?xml version="1.0" encoding="UTF-8"?>',
            string: $xml
        );
        $this->assertStringContainsString(
            needle: '<rss version="2.0"',
            haystack: $xml
        );
        $this->assertStringContainsString(
            needle: '<channel>',
            haystack: $xml
        );
    }//end testRenderFeedProducesRssRoot()

    /**
     * `renderFeed` returns Atom XML when the format flag is "atom".
     * (Design D4.)
     *
     * @return void
     */
    public function testRenderFeedReturnsAtomWhenRequested(): void
    {
        $this->dashboardService->method('getVisibleToUser')->willReturn([]);
        $this->config->method('getAppValue')->willReturn('50');
        $this->stubUserManager(displayName: 'Owner');

        $xml = $this->service->renderFeed(
            token: $this->buildToken(userId: 'owner'),
            format: FeedService::FORMAT_ATOM
        );

        $this->assertStringContainsString(
            needle: '<feed xmlns="http://www.w3.org/2005/Atom">',
            haystack: $xml
        );
    }//end testRenderFeedReturnsAtomWhenRequested()

    /**
     * Build a `{dashboard, source}` entry shaped like the output of
     * {@see DashboardService::getVisibleToUser()}.
     *
     * @param string $uuid        The UUID.
     * @param string $name        The name.
     * @param string $updatedAt   The `Y-m-d H:i:s` updatedAt.
     * @param string $description The description (defaults to '').
     *
     * @return array{dashboard: Dashboard, source: string}
     */
    private function buildVisibleEntry(
        string $uuid,
        string $name,
        string $updatedAt,
        string $description=''
    ): array {
        $dashboard = new Dashboard();
        $dashboard->setUuid($uuid);
        $dashboard->setName($name);
        $dashboard->setDescription($description);
        $dashboard->setUserId('owner');
        $dashboard->setUpdatedAt($updatedAt);

        return [
            'dashboard' => $dashboard,
            'source'    => Dashboard::SOURCE_USER,
        ];
    }//end buildVisibleEntry()

    /**
     * Build a feed-token entity for the given user.
     *
     * @param string $userId The user ID.
     *
     * @return FeedToken The token.
     */
    private function buildToken(string $userId): FeedToken
    {
        $token = new FeedToken();
        $token->setUserId($userId);
        $token->setToken('opaque');
        return $token;
    }//end buildToken()

    /**
     * Stub IUserManager::get to return an IUser with the given
     * display name for any UID.
     *
     * @param string $displayName The display name to return.
     *
     * @return void
     */
    private function stubUserManager(string $displayName): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getDisplayName')->willReturn($displayName);
        $this->userManager->method('get')->willReturn($user);
    }//end stubUserManager()
}//end class
