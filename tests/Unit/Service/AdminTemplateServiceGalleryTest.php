<?php

/**
 * AdminTemplateServiceGalleryTest
 *
 * Unit tests for the template-discovery + save-as-template behaviour added
 * by the `dashboard-templates` change (REQ-TMPL-014..017). Covers:
 *   - REQ-TMPL-014: gallery serialisation includes the seven mandated
 *     fields (`uuid`, `name`, `description`, `category`, `previewImage`,
 *     `gridColumns`, `widgetCount`, `lastUpdatedAt`) per template.
 *   - REQ-TMPL-014: empty input → empty list (no error).
 *   - REQ-TMPL-015: ownership rejection raises `ForbiddenException` when
 *     the source dashboard is not owned by the caller.
 *   - REQ-TMPL-015: missing name → `InvalidArgumentException`.
 *   - REQ-TMPL-015: happy-path deep copy persists a new admin_template
 *     row with `userId = null`, `isActive = 0`, `basedOnTemplate = null`,
 *     and triggers `cloneToDashboard` exactly once.
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
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Exception\ForbiddenException;
use OCA\MyDash\Service\AdminSettingsService;
use OCA\MyDash\Service\AdminTemplateService;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Gallery + save-as-template scenarios for AdminTemplateService.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Mirrors constructor.
 */
class AdminTemplateServiceGalleryTest extends TestCase
{
    /** @var DashboardMapper&MockObject */
    private $dashboardMapper;

    /** @var WidgetPlacementMapper&MockObject */
    private $placementMapper;

    /** @var AdminSettingsService&MockObject */
    private $settingsService;

    /** @var IGroupManager&MockObject */
    private $groupManager;

    /** @var IUserManager&MockObject */
    private $userManager;

    private AdminTemplateService $service;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dashboardMapper = $this->createMock(DashboardMapper::class);
        $this->placementMapper = $this->createMock(WidgetPlacementMapper::class);
        $this->settingsService = $this->createMock(AdminSettingsService::class);
        $this->groupManager    = $this->createMock(IGroupManager::class);
        $this->userManager     = $this->createMock(IUserManager::class);

        $this->service = new AdminTemplateService(
            dashboardMapper: $this->dashboardMapper,
            placementMapper: $this->placementMapper,
            settingsService: $this->settingsService,
            groupManager: $this->groupManager,
            userManager: $this->userManager,
        );
    }//end setUp()

    // ---------------------------------------------------------------
    // getGallery — REQ-TMPL-014
    // ---------------------------------------------------------------

    /**
     * REQ-TMPL-014: gallery returns the 8 mandated fields per template.
     *
     * @return void
     */
    public function testGalleryReturnsMandatedFields(): void
    {
        $template = new Dashboard();
        $template->setUuid('uuid-1');
        $template->setName('Marketing dashboard');
        $template->setDescription('Original description');
        $template->setTemplateDescription('Long-form gallery description');
        $template->setTemplateCategory('marketing');
        $template->setTemplatePreviewImage('/apps/mydash/resource/img.png');
        $template->setGridColumns(12);
        $template->setUpdatedAt('2026-05-01 09:00:00');
        // ID is set internally on insert; here we use reflection-free
        // method on the entity from QBMapper rows (cast to 0 for the
        // count-by-id call when no id is set).
        $this->dashboardMapper
            ->expects($this->once())
            ->method('findAllTemplatesForGallery')
            ->with('marketing', 'name')
            ->willReturn([$template]);

        $this->placementMapper
            ->expects($this->once())
            ->method('countByDashboardId')
            ->willReturn(4);

        $result = $this->service->getGallery(
            category: 'marketing',
            sortBy: 'name'
        );

        $this->assertCount(expectedCount: 1, haystack: $result);
        $entry = $result[0];
        $this->assertSame(expected: 'uuid-1', actual: $entry['uuid']);
        $this->assertSame(expected: 'Marketing dashboard', actual: $entry['name']);
        $this->assertSame(
            expected: 'Long-form gallery description',
            actual: $entry['description']
        );
        $this->assertSame(expected: 'marketing', actual: $entry['category']);
        $this->assertSame(
            expected: '/apps/mydash/resource/img.png',
            actual: $entry['previewImage']
        );
        $this->assertSame(expected: 12, actual: $entry['gridColumns']);
        $this->assertSame(expected: 4, actual: $entry['widgetCount']);
        $this->assertSame(
            expected: '2026-05-01 09:00:00',
            actual: $entry['lastUpdatedAt']
        );
    }//end testGalleryReturnsMandatedFields()

    /**
     * REQ-TMPL-014: empty backing list → empty array (HTTP 200, no error).
     *
     * @return void
     */
    public function testGalleryReturnsEmptyListWhenNoTemplates(): void
    {
        $this->dashboardMapper
            ->expects($this->once())
            ->method('findAllTemplatesForGallery')
            ->with(null, 'name')
            ->willReturn([]);

        $this->placementMapper
            ->expects($this->never())
            ->method('countByDashboardId');

        $this->assertSame(
            expected: [],
            actual: $this->service->getGallery()
        );
    }//end testGalleryReturnsEmptyListWhenNoTemplates()

    /**
     * REQ-TMPL-014: gallery falls back to the regular `description`
     * column when `templateDescription` is null.
     *
     * @return void
     */
    public function testGalleryFallsBackToRegularDescription(): void
    {
        $template = new Dashboard();
        $template->setUuid('uuid-2');
        $template->setName('Engineering dashboard');
        $template->setDescription('Regular description');
        // templateDescription left null on purpose.
        $template->setTemplateCategory(null);
        $template->setGridColumns(10);
        $template->setUpdatedAt('2026-04-30 14:30:00');

        $this->dashboardMapper
            ->method('findAllTemplatesForGallery')
            ->willReturn([$template]);
        $this->placementMapper
            ->method('countByDashboardId')
            ->willReturn(0);

        $result = $this->service->getGallery();

        $this->assertSame(
            expected: 'Regular description',
            actual: $result[0]['description']
        );
        $this->assertNull(actual: $result[0]['category']);
    }//end testGalleryFallsBackToRegularDescription()

    // ---------------------------------------------------------------
    // saveAsTemplate — REQ-TMPL-015
    // ---------------------------------------------------------------

    /**
     * REQ-TMPL-015: empty / missing name → InvalidArgumentException.
     *
     * @return void
     */
    public function testSaveAsTemplateRejectsEmptyName(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);

        $this->service->saveAsTemplate(
            userId: 'alice',
            dashboardUuid: 'src-uuid',
            metadata: ['name' => '   ']
        );
    }//end testSaveAsTemplateRejectsEmptyName()

    /**
     * REQ-TMPL-015: non-owner → ForbiddenException + no insert.
     *
     * @return void
     */
    public function testSaveAsTemplateRejectsNonOwner(): void
    {
        $this->dashboardMapper
            ->expects($this->once())
            ->method('findOwnedByUserAndUuid')
            ->with('bob', 'alices-dashboard')
            ->willReturn(null);

        $this->dashboardMapper
            ->expects($this->never())
            ->method('insert');

        $this->expectException(exception: ForbiddenException::class);

        $this->service->saveAsTemplate(
            userId: 'bob',
            dashboardUuid: 'alices-dashboard',
            metadata: ['name' => 'Stolen template']
        );
    }//end testSaveAsTemplateRejectsNonOwner()

    /**
     * REQ-TMPL-015: happy path persists the new template with the
     * mandated invariants and clones placements exactly once.
     *
     * @return void
     */
    public function testSaveAsTemplateHappyPathPersistsAndClones(): void
    {
        $source = new Dashboard();
        $source->setUuid('src-uuid');
        $source->setUserId('alice');
        $source->setType(Dashboard::TYPE_USER);
        $source->setGridColumns(8);
        $source->setIsActive(1);
        // Force the source id so cloneToDashboard receives a known value.
        $source->setId(42);

        $this->dashboardMapper
            ->expects($this->once())
            ->method('findOwnedByUserAndUuid')
            ->with('alice', 'src-uuid')
            ->willReturn($source);

        $this->dashboardMapper
            ->expects($this->once())
            ->method('insert')
            ->willReturnCallback(static function (Dashboard $entity): Dashboard {
                self::assertSame(
                    expected: Dashboard::TYPE_ADMIN_TEMPLATE,
                    actual: $entity->getType()
                );
                self::assertNull(actual: $entity->getUserId());
                self::assertSame(expected: 0, actual: $entity->getIsActive());
                self::assertSame(expected: 0, actual: $entity->getIsDefault());
                self::assertNull(actual: $entity->getBasedOnTemplate());
                self::assertSame(expected: 8, actual: $entity->getGridColumns());
                self::assertSame(
                    expected: 'My Template',
                    actual: $entity->getName()
                );
                self::assertSame(
                    expected: 'product',
                    actual: $entity->getTemplateCategory()
                );
                self::assertSame(
                    expected: 'A new template',
                    actual: $entity->getTemplateDescription()
                );
                self::assertSame(
                    expected: '/apps/mydash/resource/preview.png',
                    actual: $entity->getTemplatePreviewImage()
                );

                // Simulate DB-assigned id on the new row.
                $entity->setId(99);
                return $entity;
            });

        $this->placementMapper
            ->expects($this->once())
            ->method('cloneToDashboard')
            ->with(42, 99)
            ->willReturn(4);

        $result = $this->service->saveAsTemplate(
            userId: 'alice',
            dashboardUuid: 'src-uuid',
            metadata: [
                'name'         => 'My Template',
                'description'  => 'A new template',
                'category'     => 'product',
                'previewImage' => '/apps/mydash/resource/preview.png',
            ]
        );

        $this->assertInstanceOf(
            expected: Dashboard::class,
            actual: $result
        );
        $this->assertSame(
            expected: Dashboard::TYPE_ADMIN_TEMPLATE,
            actual: $result->getType()
        );
        $this->assertSame(expected: 99, actual: $result->getId());
    }//end testSaveAsTemplateHappyPathPersistsAndClones()

    /**
     * REQ-TMPL-015: missing optional fields collapse to null on the new
     * template (empty strings normalise to null, never the literal "").
     *
     * @return void
     */
    public function testSaveAsTemplateNormalisesEmptyOptionalFields(): void
    {
        $source = new Dashboard();
        $source->setUuid('src-uuid');
        $source->setUserId('alice');
        $source->setType(Dashboard::TYPE_USER);
        $source->setGridColumns(12);
        $source->setId(5);

        $this->dashboardMapper
            ->method('findOwnedByUserAndUuid')
            ->willReturn($source);

        $this->dashboardMapper
            ->expects($this->once())
            ->method('insert')
            ->willReturnCallback(static function (Dashboard $entity): Dashboard {
                self::assertNull(actual: $entity->getTemplateCategory());
                self::assertNull(actual: $entity->getTemplateDescription());
                self::assertNull(actual: $entity->getTemplatePreviewImage());
                $entity->setId(6);
                return $entity;
            });

        $this->placementMapper
            ->expects($this->once())
            ->method('cloneToDashboard');

        $this->service->saveAsTemplate(
            userId: 'alice',
            dashboardUuid: 'src-uuid',
            metadata: [
                'name'         => 'Bare template',
                'description'  => '',
                'category'     => '   ',
                'previewImage' => null,
            ]
        );
    }//end testSaveAsTemplateNormalisesEmptyOptionalFields()
}//end class
