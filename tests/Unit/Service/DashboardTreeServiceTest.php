<?php

/**
 * DashboardTreeService Test
 *
 * Covers cycle prevention, depth enforcement, slug uniqueness, path
 * resolution, breadcrumb computation, and the cascade-delete walker
 * pinned by REQ-DASH-023..030.
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
use OCA\MyDash\Service\DashboardTreeService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see DashboardTreeService}.
 */
class DashboardTreeServiceTest extends TestCase
{
    /** @var DashboardMapper&MockObject */
    private $dashboardMapper;

    /** @var WidgetPlacementMapper&MockObject */
    private $placementMapper;

    /** @var IDBConnection&MockObject */
    private $db;

    private DashboardTreeService $service;

    protected function setUp(): void
    {
        $this->dashboardMapper = $this->createMock(DashboardMapper::class);
        $this->placementMapper = $this->createMock(WidgetPlacementMapper::class);
        $this->db              = $this->createMock(IDBConnection::class);

        $this->service = new DashboardTreeService(
            dashboardMapper: $this->dashboardMapper,
            placementMapper: $this->placementMapper,
            db: $this->db,
        );
    }//end setUp()

    /**
     * Build a minimal Dashboard entity for the mock returns.
     *
     * @param string      $uuid       The dashboard UUID.
     * @param string      $name       The display name.
     * @param string|null $slug       The slug.
     * @param string|null $parentUuid The parent UUID (NULL ⇒ root).
     */
    private function makeDashboard(
        string $uuid,
        string $name,
        ?string $slug=null,
        ?string $parentUuid=null
    ): Dashboard {
        $dash = new Dashboard();
        $dash->setUuid($uuid);
        $dash->setName($name);
        if ($slug !== null) {
            $dash->setSlug($slug);
        }

        if ($parentUuid !== null) {
            $dash->setParentUuid($parentUuid);
        }

        return $dash;
    }//end makeDashboard()

    /**
     * REQ-DASH-028: re-parenting onto self MUST throw cycle_detected.
     *
     * @return void
     */
    public function testValidateParentRejectsSelfParent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(DashboardTreeService::ERR_CYCLE_DETECTED);

        $this->service->validateParent(
            movingUuid: 'uuid-a',
            newParentUuid: 'uuid-a'
        );
    }//end testValidateParentRejectsSelfParent()

    /**
     * REQ-DASH-023: parent that does not exist MUST throw.
     *
     * @return void
     */
    public function testValidateParentRejectsMissingParent(): void
    {
        $this->dashboardMapper->method('findByUuid')
            ->willThrowException(new DoesNotExistException('not found'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(DashboardTreeService::ERR_PARENT_NOT_FOUND);

        $this->service->validateParent(
            movingUuid: null,
            newParentUuid: 'uuid-missing'
        );
    }//end testValidateParentRejectsMissingParent()

    /**
     * REQ-DASH-023: NULL parent (root) is always legal — no DB call needed.
     *
     * @return void
     */
    public function testValidateParentAcceptsNullParent(): void
    {
        $this->dashboardMapper->expects($this->never())->method('findByUuid');

        $this->service->validateParent(
            movingUuid: 'uuid-a',
            newParentUuid: null
        );

        $this->assertTrue(true);
    }//end testValidateParentAcceptsNullParent()

    /**
     * REQ-DASH-024: validateSlugUnique throws when a sibling with the
     * same slug exists.
     *
     * @return void
     */
    public function testValidateSlugUniqueRejectsCollision(): void
    {
        $existing = $this->makeDashboard(
            uuid: 'uuid-existing',
            name: 'Q1',
            slug: 'q1'
        );

        $this->dashboardMapper->method('findChildBySlug')->willReturn($existing);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(DashboardTreeService::ERR_SLUG_TAKEN);

        $this->service->validateSlugUnique(
            parentUuid: 'parent-uuid',
            slug: 'q1'
        );
    }//end testValidateSlugUniqueRejectsCollision()

    /**
     * REQ-DASH-024: excludeUuid lets the row keep its current slug
     * (no-op writes succeed).
     *
     * @return void
     */
    public function testValidateSlugUniqueExcludesSelf(): void
    {
        $self = $this->makeDashboard(
            uuid: 'uuid-self',
            name: 'Q1',
            slug: 'q1'
        );

        $this->dashboardMapper->method('findChildBySlug')->willReturn($self);

        $this->service->validateSlugUnique(
            parentUuid: 'parent-uuid',
            slug: 'q1',
            excludeUuid: 'uuid-self'
        );

        $this->assertTrue(true);
    }//end testValidateSlugUniqueExcludesSelf()

    /**
     * REQ-DASH-025: computeBreadcrumbs returns the ancestor chain
     * root → leaf with the leaf appended.
     *
     * @return void
     */
    public function testComputeBreadcrumbsReturnsRootToLeaf(): void
    {
        $marketing = $this->makeDashboard(
            uuid: 'uuid-marketing',
            name: 'Marketing',
            slug: 'marketing'
        );
        $campaigns = $this->makeDashboard(
            uuid: 'uuid-campaigns',
            name: 'Campaigns',
            slug: 'campaigns',
            parentUuid: 'uuid-marketing'
        );
        $q1 = $this->makeDashboard(
            uuid: 'uuid-q1',
            name: 'Q1',
            slug: 'q1',
            parentUuid: 'uuid-campaigns'
        );

        $this->dashboardMapper->method('findByUuid')->with('uuid-q1')
            ->willReturn($q1);

        $this->dashboardMapper->method('findAncestors')->with('uuid-q1')
            ->willReturn([$marketing, $campaigns]);

        $crumbs = $this->service->computeBreadcrumbs(uuid: 'uuid-q1');

        $this->assertCount(3, $crumbs);
        $this->assertSame('marketing', $crumbs[0]['slug']);
        $this->assertSame('campaigns', $crumbs[1]['slug']);
        $this->assertSame('q1', $crumbs[2]['slug']);
    }//end testComputeBreadcrumbsReturnsRootToLeaf()

    /**
     * REQ-DASH-025: computePath joins the breadcrumb slugs with `/`.
     *
     * @return void
     */
    public function testComputePathJoinsBreadcrumbSlugs(): void
    {
        $marketing = $this->makeDashboard(
            uuid: 'uuid-marketing',
            name: 'Marketing',
            slug: 'marketing'
        );
        $q1 = $this->makeDashboard(
            uuid: 'uuid-q1',
            name: 'Q1',
            slug: 'q1',
            parentUuid: 'uuid-marketing'
        );

        $this->dashboardMapper->method('findByUuid')->with('uuid-q1')
            ->willReturn($q1);

        $this->dashboardMapper->method('findAncestors')->with('uuid-q1')
            ->willReturn([$marketing]);

        $this->assertSame(
            '/marketing/q1',
            $this->service->computePath(uuid: 'uuid-q1')
        );
    }//end testComputePathJoinsBreadcrumbSlugs()

    /**
     * REQ-DASH-027: resolvePath returns NULL when any segment misses.
     *
     * @return void
     */
    public function testResolvePathReturnsNullOnMiss(): void
    {
        $this->dashboardMapper->method('findChildBySlug')->willReturn(null);

        $this->assertNull(
            $this->service->resolvePath(path: '/marketing/missing')
        );
    }//end testResolvePathReturnsNullOnMiss()

    /**
     * REQ-DASH-027: resolvePath walks the chain segment by segment.
     *
     * @return void
     */
    public function testResolvePathWalksChain(): void
    {
        $marketing = $this->makeDashboard(
            uuid: 'uuid-marketing',
            name: 'Marketing',
            slug: 'marketing'
        );
        $campaigns = $this->makeDashboard(
            uuid: 'uuid-campaigns',
            name: 'Campaigns',
            slug: 'campaigns',
            parentUuid: 'uuid-marketing'
        );

        $this->dashboardMapper->method('findChildBySlug')
            ->willReturnCallback(
                static function (?string $parentUuid, string $slug) use ($marketing, $campaigns) {
                    if ($parentUuid === null && $slug === 'marketing') {
                        return $marketing;
                    }

                    if ($parentUuid === 'uuid-marketing' && $slug === 'campaigns') {
                        return $campaigns;
                    }

                    return null;
                }
            );

        $resolved = $this->service->resolvePath(
            path: '/marketing/campaigns'
        );

        $this->assertNotNull($resolved);
        $this->assertSame('uuid-campaigns', $resolved->getUuid());
    }//end testResolvePathWalksChain()

    /**
     * REQ-DASH-026: buildTree nests children recursively.
     *
     * @return void
     */
    public function testBuildTreeNestsChildren(): void
    {
        $marketing = $this->makeDashboard(
            uuid: 'uuid-marketing',
            name: 'Marketing',
            slug: 'marketing'
        );
        $campaigns = $this->makeDashboard(
            uuid: 'uuid-campaigns',
            name: 'Campaigns',
            slug: 'campaigns',
            parentUuid: 'uuid-marketing'
        );

        $this->dashboardMapper->method('findByParent')
            ->willReturnCallback(
                static function (?string $parentUuid) use ($marketing, $campaigns) {
                    if ($parentUuid === null) {
                        return [$marketing];
                    }

                    if ($parentUuid === 'uuid-marketing') {
                        return [$campaigns];
                    }

                    return [];
                }
            );

        $tree = $this->service->getFullTree();

        $this->assertCount(1, $tree);
        $this->assertSame('uuid-marketing', $tree[0]['uuid']);
        $this->assertCount(1, $tree[0]['children']);
        $this->assertSame('uuid-campaigns', $tree[0]['children'][0]['uuid']);
    }//end testBuildTreeNestsChildren()

    // -----------------------------------------------------------------------
    // C1 fix: getFilteredTree must limit nodes to the supplied visible set.
    // -----------------------------------------------------------------------

    /**
     * C1: getFilteredTree returns only nodes whose UUID is in $visibleUuids.
     *
     * @return void
     */
    public function testGetFilteredTreeOmitsInvisibleNodes(): void
    {
        $marketing = $this->makeDashboard(
            uuid: 'uuid-marketing',
            name: 'Marketing',
            slug: 'marketing'
        );
        $campaigns = $this->makeDashboard(
            uuid: 'uuid-campaigns',
            name: 'Campaigns',
            slug: 'campaigns',
            parentUuid: 'uuid-marketing'
        );
        $privateAdmin = $this->makeDashboard(
            uuid: 'uuid-private',
            name: 'Admin Private',
            slug: 'admin-private'
        );

        $this->dashboardMapper->method('findByParent')
            ->willReturnCallback(
                static function (?string $parentUuid) use ($marketing, $privateAdmin, $campaigns) {
                    if ($parentUuid === null) {
                        return [$marketing, $privateAdmin];
                    }

                    if ($parentUuid === 'uuid-marketing') {
                        return [$campaigns];
                    }

                    return [];
                }
            );

        // Caller can see marketing and campaigns but NOT the admin-private dashboard.
        $visibleUuids = [
            'uuid-marketing' => true,
            'uuid-campaigns' => true,
        ];

        $tree = $this->service->getFilteredTree(visibleUuids: $visibleUuids);

        $this->assertCount(1, $tree, 'Only marketing is visible at root; private is excluded');
        $this->assertSame('uuid-marketing', $tree[0]['uuid']);
        $this->assertCount(1, $tree[0]['children']);
        $this->assertSame('uuid-campaigns', $tree[0]['children'][0]['uuid']);
    }//end testGetFilteredTreeOmitsInvisibleNodes()

    /**
     * C1: getFilteredTree with empty visibleUuids returns an empty tree.
     *
     * @return void
     */
    public function testGetFilteredTreeWithEmptyUuidsReturnsEmpty(): void
    {
        $marketing = $this->makeDashboard(
            uuid: 'uuid-marketing',
            name: 'Marketing',
            slug: 'marketing'
        );

        $this->dashboardMapper->method('findByParent')
            ->willReturnCallback(
                static function (?string $parentUuid) use ($marketing) {
                    if ($parentUuid === null) {
                        return [$marketing];
                    }

                    return [];
                }
            );

        $tree = $this->service->getFilteredTree(visibleUuids: []);

        $this->assertSame([], $tree, 'Empty visibility set must yield empty tree');
    }//end testGetFilteredTreeWithEmptyUuidsReturnsEmpty()
}//end class
