<?php

/**
 * Tile Entity Test
 *
 * Unit tests for the Tile entity class.
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Db
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Db;

use OCA\MyDash\Db\Tile;
use PHPUnit\Framework\TestCase;

class TileTest extends TestCase
{
    private Tile $tile;

    protected function setUp(): void
    {
        $this->tile = new Tile();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->tile->getFieldTypes();

        $this->assertSame('integer', $fieldTypes['id']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertSame('', $this->tile->getUserId());
        $this->assertSame('', $this->tile->getTitle());
        $this->assertSame('', $this->tile->getIcon());
        $this->assertSame('', $this->tile->getIconType());
        $this->assertSame('', $this->tile->getBackgroundColor());
        $this->assertSame('', $this->tile->getTextColor());
        $this->assertSame('', $this->tile->getLinkType());
        $this->assertSame('', $this->tile->getLinkValue());
        $this->assertNull($this->tile->getCreatedAt());
        $this->assertNull($this->tile->getUpdatedAt());
    }

    public function testSetAndGetUserId(): void
    {
        $this->tile->setUserId('alice');
        $this->assertSame('alice', $this->tile->getUserId());
    }

    public function testSetAndGetTitle(): void
    {
        $this->tile->setTitle('My Files');
        $this->assertSame('My Files', $this->tile->getTitle());
    }

    public function testSetAndGetIcon(): void
    {
        $this->tile->setIcon('icon-folder');
        $this->assertSame('icon-folder', $this->tile->getIcon());
    }

    public function testSetAndGetIconType(): void
    {
        $this->tile->setIconType('class');
        $this->assertSame('class', $this->tile->getIconType());

        $this->tile->setIconType('emoji');
        $this->assertSame('emoji', $this->tile->getIconType());

        $this->tile->setIconType('url');
        $this->assertSame('url', $this->tile->getIconType());

        $this->tile->setIconType('svg');
        $this->assertSame('svg', $this->tile->getIconType());
    }

    public function testSetAndGetBackgroundColor(): void
    {
        $this->tile->setBackgroundColor('#3b82f6');
        $this->assertSame('#3b82f6', $this->tile->getBackgroundColor());
    }

    public function testSetAndGetTextColor(): void
    {
        $this->tile->setTextColor('#ffffff');
        $this->assertSame('#ffffff', $this->tile->getTextColor());
    }

    public function testSetAndGetLinkType(): void
    {
        $this->tile->setLinkType('app');
        $this->assertSame('app', $this->tile->getLinkType());

        $this->tile->setLinkType('url');
        $this->assertSame('url', $this->tile->getLinkType());
    }

    public function testSetAndGetLinkValue(): void
    {
        $this->tile->setLinkValue('/apps/files');
        $this->assertSame('/apps/files', $this->tile->getLinkValue());

        $this->tile->setLinkValue('https://example.com');
        $this->assertSame('https://example.com', $this->tile->getLinkValue());
    }

    public function testSetAndGetTimestamps(): void
    {
        $created = '2024-01-15 10:30:00';
        $updated = '2024-01-16 14:00:00';

        $this->tile->setCreatedAt($created);
        $this->tile->setUpdatedAt($updated);

        $this->assertSame($created, $this->tile->getCreatedAt());
        $this->assertSame($updated, $this->tile->getUpdatedAt());
    }

    public function testJsonSerialize(): void
    {
        $this->tile->setUserId('alice');
        $this->tile->setTitle('My Files');
        $this->tile->setIcon('icon-folder');
        $this->tile->setIconType('class');
        $this->tile->setBackgroundColor('#3b82f6');
        $this->tile->setTextColor('#ffffff');
        $this->tile->setLinkType('app');
        $this->tile->setLinkValue('/apps/files');
        $this->tile->setCreatedAt('2024-01-15 10:00:00');
        $this->tile->setUpdatedAt('2024-01-16 12:00:00');

        $serialized = $this->tile->jsonSerialize();

        $this->assertIsArray($serialized);
        $this->assertSame('alice', $serialized['userId']);
        $this->assertSame('My Files', $serialized['title']);
        $this->assertSame('icon-folder', $serialized['icon']);
        $this->assertSame('class', $serialized['iconType']);
        $this->assertSame('#3b82f6', $serialized['backgroundColor']);
        $this->assertSame('#ffffff', $serialized['textColor']);
        $this->assertSame('app', $serialized['linkType']);
        $this->assertSame('/apps/files', $serialized['linkValue']);
        $this->assertSame('2024-01-15 10:00:00', $serialized['createdAt']);
        $this->assertSame('2024-01-16 12:00:00', $serialized['updatedAt']);
        $this->assertArrayHasKey('id', $serialized);
    }

    public function testJsonSerializeDefaultValues(): void
    {
        $serialized = $this->tile->jsonSerialize();

        $this->assertIsArray($serialized);
        $this->assertSame('', $serialized['userId']);
        $this->assertSame('', $serialized['title']);
        $this->assertSame('', $serialized['icon']);
        $this->assertSame('', $serialized['iconType']);
        $this->assertSame('', $serialized['backgroundColor']);
        $this->assertSame('', $serialized['textColor']);
        $this->assertSame('', $serialized['linkType']);
        $this->assertSame('', $serialized['linkValue']);
        $this->assertNull($serialized['createdAt']);
        $this->assertNull($serialized['updatedAt']);
    }
}
