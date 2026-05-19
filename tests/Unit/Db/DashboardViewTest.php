<?php

/**
 * DashboardView Entity Test
 *
 * Unit tests for the DashboardView aggregate-row entity
 * (REQ-ANLT-001).
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Db
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Db;

use OCA\MyDash\Db\DashboardView;
use PHPUnit\Framework\TestCase;

class DashboardViewTest extends TestCase
{
    private DashboardView $view;

    protected function setUp(): void
    {
        $this->view = new DashboardView();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->view->getFieldTypes();

        $this->assertSame('integer', $fieldTypes['id']);
        $this->assertSame('integer', $fieldTypes['viewCount']);
        $this->assertSame('integer', $fieldTypes['uniqueViewerCount']);
    }

    public function testDefaultsAreZero(): void
    {
        $this->assertNull($this->view->getDashboardUuid());
        $this->assertNull($this->view->getViewBucket());
        $this->assertSame(0, $this->view->getViewCount());
        $this->assertSame(0, $this->view->getUniqueViewerCount());
    }

    public function testSettersRoundTrip(): void
    {
        $this->view->setDashboardUuid('uuid-101');
        $this->view->setViewBucket('2026-05-01');
        $this->view->setViewCount(42);
        $this->view->setUniqueViewerCount(13);

        $this->assertSame('uuid-101', $this->view->getDashboardUuid());
        $this->assertSame('2026-05-01', $this->view->getViewBucket());
        $this->assertSame(42, $this->view->getViewCount());
        $this->assertSame(13, $this->view->getUniqueViewerCount());
    }

    public function testJsonSerialize(): void
    {
        $this->view->setDashboardUuid('uuid-abc');
        $this->view->setViewBucket('2026-05-02');
        $this->view->setViewCount(7);
        $this->view->setUniqueViewerCount(3);

        $serialized = $this->view->jsonSerialize();

        $this->assertSame(
            [
                'id'                => null,
                'dashboardUuid'     => 'uuid-abc',
                'viewBucket'        => '2026-05-02',
                'viewCount'         => 7,
                'uniqueViewerCount' => 3,
            ],
            $serialized
        );
    }
}
