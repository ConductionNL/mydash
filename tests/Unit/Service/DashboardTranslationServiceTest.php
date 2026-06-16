<?php

/**
 * DashboardTranslationService unit tests.
 *
 * Covers seed/create/update/delete/promote-primary plus the locale
 * resolver. REQ-DASH-038..044 (dashboard-language-content).
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

use Exception;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardTranslation;
use OCA\MyDash\Db\DashboardTranslationMapper;
use OCA\MyDash\Service\DashboardTranslationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DashboardTranslationServiceTest extends TestCase
{
    /**
     * @var DashboardTranslationMapper&MockObject
     */
    private $translationMapper;

    /**
     * @var IConfig&MockObject
     */
    private $config;

    /**
     * @var IDBConnection&MockObject
     */
    private $db;

    private DashboardTranslationService $service;

    protected function setUp(): void
    {
        $this->translationMapper = $this->createMock(DashboardTranslationMapper::class);
        $this->db                = $this->createMock(IDBConnection::class);
        $this->config            = $this->createMock(IConfig::class);

        $this->service = new DashboardTranslationService(
            translationMapper: $this->translationMapper,
            db: $this->db,
            config: $this->config,
        );
    }

    public function testSeedPrimaryUsesOwnerLocale(): void
    {
        $dashboard = new Dashboard();
        $dashboard->setUuid('uuid-1');
        $dashboard->setUserId('alice');
        $dashboard->setName('Marketing');
        $dashboard->setDescription('Top-level marketing hub');

        $this->translationMapper
            ->method('findPrimaryByDashboardUuid')
            ->with(dashboardUuid: 'uuid-1')
            ->willReturn(null);

        $this->config
            ->expects($this->once())
            ->method('getUserValue')
            ->with('alice', 'core', 'lang', '')
            ->willReturn('nl_NL');

        $this->translationMapper
            ->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (DashboardTranslation $translation) {
                $this->assertSame('uuid-1', $translation->getDashboardUuid());
                $this->assertSame('nl', $translation->getLanguageCode());
                $this->assertSame('Marketing', $translation->getName());
                $this->assertSame(1, $translation->getIsPrimary());
                return $translation;
            });

        $result = $this->service->seedPrimaryFor(dashboard: $dashboard);

        $this->assertSame('nl', $result->getLanguageCode());
        $this->assertSame(1, $result->getIsPrimary());
    }

    public function testSeedPrimaryFallsBackToEnglish(): void
    {
        $dashboard = new Dashboard();
        $dashboard->setUuid('uuid-2');
        $dashboard->setUserId('bob');
        $dashboard->setName('Operations');

        $this->translationMapper
            ->method('findPrimaryByDashboardUuid')
            ->willReturn(null);

        $this->config
            ->method('getUserValue')
            ->willReturn('');

        $this->translationMapper
            ->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (DashboardTranslation $t) {
                $this->assertSame('en', $t->getLanguageCode());
                return $t;
            });

        $this->service->seedPrimaryFor(dashboard: $dashboard);
    }

    public function testSeedPrimaryIdempotent(): void
    {
        $dashboard = new Dashboard();
        $dashboard->setUuid('uuid-3');

        $existing = new DashboardTranslation();
        $existing->setLanguageCode('en');
        $existing->setIsPrimary(1);

        $this->translationMapper
            ->method('findPrimaryByDashboardUuid')
            ->willReturn($existing);

        $this->translationMapper
            ->expects($this->never())
            ->method('insert');

        $result = $this->service->seedPrimaryFor(dashboard: $dashboard);
        $this->assertSame($existing, $result);
    }

    public function testCreateVariantNormalisesLanguageCode(): void
    {
        $this->translationMapper
            ->method('findByDashboardUuidAndLanguage')
            ->with('uuid-x', 'nl')
            ->willReturn(null);

        $this->translationMapper
            ->method('findPrimaryByDashboardUuid')
            ->willReturn(null);

        $this->translationMapper
            ->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (DashboardTranslation $t) {
                $this->assertSame('nl', $t->getLanguageCode());
                $this->assertSame(0, $t->getIsPrimary());
                return $t;
            });

        $this->service->createVariant(
            dashboardUuid: 'uuid-x',
            languageCode: 'nl_NL',
            name: 'Mijn',
        );
    }

    public function testCreateVariantThrowsOnDuplicate(): void
    {
        $existing = new DashboardTranslation();
        $existing->setLanguageCode('en');

        $this->translationMapper
            ->method('findByDashboardUuidAndLanguage')
            ->willReturn($existing);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage(DashboardTranslationService::ERR_LANGUAGE_EXISTS);

        $this->service->createVariant(
            dashboardUuid: 'uuid-x',
            languageCode: 'en',
        );
    }

    public function testCreateVariantSeedsFromPrimary(): void
    {
        $primary = new DashboardTranslation();
        $primary->setLanguageCode('en');
        $primary->setName('Marketing');
        $primary->setDescription('English desc');
        $primary->setWidgetTreeJson('{"widgets":[1]}');

        $this->translationMapper
            ->method('findByDashboardUuidAndLanguage')
            ->willReturn(null);

        $this->translationMapper
            ->method('findPrimaryByDashboardUuid')
            ->willReturn($primary);

        $this->translationMapper
            ->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (DashboardTranslation $t) {
                $this->assertSame('Marketing', $t->getName());
                $this->assertSame('English desc', $t->getDescription());
                $this->assertSame('{"widgets":[1]}', $t->getWidgetTreeJson());
                $this->assertSame('de', $t->getLanguageCode());
                return $t;
            });

        $this->service->createVariant(
            dashboardUuid: 'uuid-x',
            languageCode: 'de',
        );
    }

    public function testDeleteVariantRejectsLast(): void
    {
        $only = new DashboardTranslation();
        $only->setLanguageCode('en');
        $only->setIsPrimary(0);

        $this->translationMapper
            ->method('findByDashboardUuidAndLanguage')
            ->willReturn($only);

        $this->translationMapper
            ->method('countByDashboardUuid')
            ->willReturn(1);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage(DashboardTranslationService::ERR_LAST_VARIANT);

        $this->service->deleteVariant(
            dashboardUuid: 'uuid-x',
            languageCode: 'en',
        );
    }

    public function testDeleteVariantRejectsPrimary(): void
    {
        $primary = new DashboardTranslation();
        $primary->setLanguageCode('en');
        $primary->setIsPrimary(1);

        $this->translationMapper
            ->method('findByDashboardUuidAndLanguage')
            ->willReturn($primary);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage(DashboardTranslationService::ERR_DELETE_PRIMARY);

        $this->service->deleteVariant(
            dashboardUuid: 'uuid-x',
            languageCode: 'en',
        );
    }

    public function testDeleteVariantSucceedsForSecondary(): void
    {
        $secondary = new DashboardTranslation();
        $secondary->setLanguageCode('nl');
        $secondary->setIsPrimary(0);

        $this->translationMapper
            ->method('findByDashboardUuidAndLanguage')
            ->willReturn($secondary);

        $this->translationMapper
            ->method('countByDashboardUuid')
            ->willReturn(2);

        $this->translationMapper
            ->expects($this->once())
            ->method('delete')
            ->with($secondary);

        $this->service->deleteVariant(
            dashboardUuid: 'uuid-x',
            languageCode: 'nl',
        );
    }

    public function testPromoteVariantToPrimaryFlipsPrimary(): void
    {
        $secondary = new DashboardTranslation();
        $secondary->setLanguageCode('nl');
        $secondary->setIsPrimary(0);
        $secondary->setId(2);

        $this->translationMapper
            ->method('findByDashboardUuidAndLanguage')
            ->willReturn($secondary);

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');

        $this->translationMapper
            ->expects($this->once())
            ->method('clearPrimary')
            ->with(dashboardUuid: 'uuid-x', exceptId: 2);

        $this->translationMapper
            ->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (DashboardTranslation $t) {
                $this->assertSame(1, $t->getIsPrimary());
                return $t;
            });

        $this->service->promoteVariantToPrimary(
            dashboardUuid: 'uuid-x',
            languageCode: 'nl',
        );
    }

    public function testPromoteVariantToPrimaryIdempotent(): void
    {
        $primary = new DashboardTranslation();
        $primary->setIsPrimary(1);

        $this->translationMapper
            ->method('findByDashboardUuidAndLanguage')
            ->willReturn($primary);

        $this->db->expects($this->never())->method('beginTransaction');
        $this->translationMapper->expects($this->never())->method('clearPrimary');

        $result = $this->service->promoteVariantToPrimary(
            dashboardUuid: 'uuid-x',
            languageCode: 'en',
        );

        $this->assertSame($primary, $result);
    }

    public function testRequireVariantThrowsWhenMissing(): void
    {
        $this->translationMapper
            ->method('findByDashboardUuidAndLanguage')
            ->willReturn(null);

        $this->expectException(DoesNotExistException::class);

        $this->service->updateVariant(
            dashboardUuid: 'uuid-x',
            languageCode: 'ja',
            patch: ['name' => 'X'],
        );
    }

    public function testResolveForLocaleExactMatch(): void
    {
        $exact = new DashboardTranslation();
        $exact->setLanguageCode('nl');

        $this->translationMapper
            ->method('findByDashboardUuidAndLanguage')
            ->with('uuid-x', 'nl')
            ->willReturn($exact);

        $result = $this->service->resolveForLocale(
            dashboardUuid: 'uuid-x',
            preferredLanguage: 'nl_NL',
        );

        $this->assertNotNull($result);
        $this->assertSame($exact, $result['translation']);
        $this->assertFalse($result['isFallback']);
    }

    public function testResolveForLocaleFallsBackToPrimary(): void
    {
        $primary = new DashboardTranslation();
        $primary->setLanguageCode('en');
        $primary->setIsPrimary(1);

        $this->translationMapper
            ->method('findByDashboardUuidAndLanguage')
            ->willReturn(null);

        $this->translationMapper
            ->method('findPrimaryByDashboardUuid')
            ->willReturn($primary);

        $result = $this->service->resolveForLocale(
            dashboardUuid: 'uuid-x',
            preferredLanguage: 'ja',
        );

        $this->assertNotNull($result);
        $this->assertSame($primary, $result['translation']);
        $this->assertTrue($result['isFallback']);
    }

    public function testResolveForLocaleReturnsNullWhenNoVariants(): void
    {
        $this->translationMapper
            ->method('findByDashboardUuidAndLanguage')
            ->willReturn(null);

        $this->translationMapper
            ->method('findPrimaryByDashboardUuid')
            ->willReturn(null);

        $result = $this->service->resolveForLocale(
            dashboardUuid: 'uuid-x',
            preferredLanguage: 'nl',
        );

        $this->assertNull($result);
    }

    public function testListAvailableLanguagesSorts(): void
    {
        $en = new DashboardTranslation();
        $en->setLanguageCode('en');
        $de = new DashboardTranslation();
        $de->setLanguageCode('de');
        $nl = new DashboardTranslation();
        $nl->setLanguageCode('nl');

        $this->translationMapper
            ->method('findByDashboardUuid')
            ->willReturn([$nl, $en, $de]);

        $result = $this->service->listAvailableLanguages(dashboardUuid: 'uuid-x');

        $this->assertSame(['de', 'en', 'nl'], $result);
    }

    public function testMaterialiseLegacyVariantUsesDashboardFields(): void
    {
        $dashboard = new Dashboard();
        $dashboard->setUuid('uuid-leg');
        $dashboard->setUserId('alice');
        $dashboard->setName('Legacy Name');
        $dashboard->setDescription('Legacy Desc');

        $this->config
            ->method('getUserValue')
            ->willReturn('en_GB');

        $variant = $this->service->materialiseLegacyVariant(dashboard: $dashboard);

        $this->assertSame('uuid-leg', $variant->getDashboardUuid());
        $this->assertSame('en', $variant->getLanguageCode());
        $this->assertSame('Legacy Name', $variant->getName());
        $this->assertSame('Legacy Desc', $variant->getDescription());
        $this->assertSame(1, $variant->getIsPrimary());
    }

    public function testDeleteAllForDashboardForwardsToMapper(): void
    {
        $this->translationMapper
            ->expects($this->once())
            ->method('deleteByDashboardUuid')
            ->with(dashboardUuid: 'uuid-z')
            ->willReturn(3);

        $count = $this->service->deleteAllForDashboard(dashboardUuid: 'uuid-z');
        $this->assertSame(3, $count);
    }
}
