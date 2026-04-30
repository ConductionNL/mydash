<?php

/**
 * Dashboard Entity Test
 *
 * Unit tests for the Dashboard entity class.
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

use OCA\MyDash\Db\Dashboard;
use PHPUnit\Framework\TestCase;

class DashboardTest extends TestCase
{
    private Dashboard $dashboard;

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->dashboard->getFieldTypes();

        $this->assertSame('integer', $fieldTypes['id']);
        $this->assertSame('integer', $fieldTypes['basedOnTemplate']);
        $this->assertSame('integer', $fieldTypes['gridColumns']);
        $this->assertSame('integer', $fieldTypes['isDefault']);
        $this->assertSame('integer', $fieldTypes['isActive']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertNull($this->dashboard->getUuid());
        $this->assertNull($this->dashboard->getName());
        $this->assertNull($this->dashboard->getDescription());
        $this->assertSame(Dashboard::TYPE_USER, $this->dashboard->getType());
        $this->assertNull($this->dashboard->getUserId());
        $this->assertNull($this->dashboard->getBasedOnTemplate());
        $this->assertSame(12, $this->dashboard->getGridColumns());
        $this->assertSame(Dashboard::PERMISSION_FULL, $this->dashboard->getPermissionLevel());
        $this->assertNull($this->dashboard->getTargetGroups());
        $this->assertSame(0, $this->dashboard->getIsDefault());
        $this->assertSame(0, $this->dashboard->getIsActive());
        $this->assertNull($this->dashboard->getCreatedAt());
        $this->assertNull($this->dashboard->getUpdatedAt());
    }

    public function testSetAndGetUuid(): void
    {
        $uuid = 'test-uuid-1234';
        $this->dashboard->setUuid($uuid);
        $this->assertSame($uuid, $this->dashboard->getUuid());
    }

    public function testSetAndGetName(): void
    {
        $name = 'My Test Dashboard';
        $this->dashboard->setName($name);
        $this->assertSame($name, $this->dashboard->getName());
    }

    public function testSetAndGetDescription(): void
    {
        $description = 'A test dashboard description';
        $this->dashboard->setDescription($description);
        $this->assertSame($description, $this->dashboard->getDescription());
    }

    public function testSetAndGetType(): void
    {
        $this->dashboard->setType(Dashboard::TYPE_ADMIN_TEMPLATE);
        $this->assertSame(Dashboard::TYPE_ADMIN_TEMPLATE, $this->dashboard->getType());
    }

    public function testSetAndGetUserId(): void
    {
        $userId = 'admin';
        $this->dashboard->setUserId($userId);
        $this->assertSame($userId, $this->dashboard->getUserId());
    }

    public function testSetAndGetBasedOnTemplate(): void
    {
        $templateId = 5;
        $this->dashboard->setBasedOnTemplate($templateId);
        $this->assertSame($templateId, $this->dashboard->getBasedOnTemplate());
    }

    public function testSetAndGetGridColumns(): void
    {
        $this->dashboard->setGridColumns(6);
        $this->assertSame(6, $this->dashboard->getGridColumns());
    }

    public function testSetAndGetPermissionLevel(): void
    {
        $this->dashboard->setPermissionLevel(Dashboard::PERMISSION_VIEW_ONLY);
        $this->assertSame(Dashboard::PERMISSION_VIEW_ONLY, $this->dashboard->getPermissionLevel());

        $this->dashboard->setPermissionLevel(Dashboard::PERMISSION_ADD_ONLY);
        $this->assertSame(Dashboard::PERMISSION_ADD_ONLY, $this->dashboard->getPermissionLevel());
    }

    public function testSetAndGetIsDefault(): void
    {
        $this->dashboard->setIsDefault(1);
        $this->assertSame(1, $this->dashboard->getIsDefault());
    }

    public function testSetAndGetIsActive(): void
    {
        $this->dashboard->setIsActive(1);
        $this->assertSame(1, $this->dashboard->getIsActive());
    }

    public function testSetAndGetTimestamps(): void
    {
        $created = '2024-01-15 10:30:00';
        $updated = '2024-01-16 14:00:00';

        $this->dashboard->setCreatedAt($created);
        $this->dashboard->setUpdatedAt($updated);

        $this->assertSame($created, $this->dashboard->getCreatedAt());
        $this->assertSame($updated, $this->dashboard->getUpdatedAt());
    }

    public function testGetTargetGroupsArrayEmpty(): void
    {
        $this->assertSame([], $this->dashboard->getTargetGroupsArray());
    }

    public function testGetTargetGroupsArrayWithValidJson(): void
    {
        $groups = ['admins', 'editors', 'viewers'];
        $this->dashboard->setTargetGroups(json_encode($groups));
        $this->assertSame($groups, $this->dashboard->getTargetGroupsArray());
    }

    public function testGetTargetGroupsArrayWithInvalidJson(): void
    {
        $this->dashboard->setTargetGroups('not-valid-json');
        $this->assertSame([], $this->dashboard->getTargetGroupsArray());
    }

    public function testSetTargetGroupsArray(): void
    {
        $groups = ['group1', 'group2'];
        $this->dashboard->setTargetGroupsArray($groups);
        $this->assertSame($groups, $this->dashboard->getTargetGroupsArray());
    }

    public function testSetNullValues(): void
    {
        $this->dashboard->setUuid('test');
        $this->dashboard->setUuid(null);
        $this->assertNull($this->dashboard->getUuid());

        $this->dashboard->setName('test');
        $this->dashboard->setName(null);
        $this->assertNull($this->dashboard->getName());

        $this->dashboard->setBasedOnTemplate(5);
        $this->dashboard->setBasedOnTemplate(null);
        $this->assertNull($this->dashboard->getBasedOnTemplate());
    }

    public function testJsonSerialize(): void
    {
        $this->dashboard->setUuid('uuid-123');
        $this->dashboard->setName('Test Dashboard');
        $this->dashboard->setDescription('Test Description');
        $this->dashboard->setType(Dashboard::TYPE_ADMIN_TEMPLATE);
        $this->dashboard->setUserId('testuser');
        $this->dashboard->setBasedOnTemplate(3);
        $this->dashboard->setGridColumns(8);
        $this->dashboard->setPermissionLevel(Dashboard::PERMISSION_VIEW_ONLY);
        $this->dashboard->setTargetGroupsArray(['admins']);
        $this->dashboard->setIsDefault(1);
        $this->dashboard->setIsActive(1);
        $this->dashboard->setCreatedAt('2024-01-15 10:00:00');
        $this->dashboard->setUpdatedAt('2024-01-16 12:00:00');

        $serialized = $this->dashboard->jsonSerialize();

        $this->assertIsArray($serialized);
        $this->assertSame('uuid-123', $serialized['uuid']);
        $this->assertSame('Test Dashboard', $serialized['name']);
        $this->assertSame('Test Description', $serialized['description']);
        $this->assertSame(Dashboard::TYPE_ADMIN_TEMPLATE, $serialized['type']);
        $this->assertSame('testuser', $serialized['userId']);
        $this->assertSame(3, $serialized['basedOnTemplate']);
        $this->assertSame(8, $serialized['gridColumns']);
        $this->assertSame(Dashboard::PERMISSION_VIEW_ONLY, $serialized['permissionLevel']);
        $this->assertSame(['admins'], $serialized['targetGroups']);
        $this->assertSame(1, $serialized['isDefault']);
        $this->assertSame(1, $serialized['isActive']);
        $this->assertSame('2024-01-15 10:00:00', $serialized['createdAt']);
        $this->assertSame('2024-01-16 12:00:00', $serialized['updatedAt']);
        $this->assertArrayHasKey('id', $serialized);
    }

    public function testJsonSerializeDefaultValues(): void
    {
        $serialized = $this->dashboard->jsonSerialize();

        $this->assertIsArray($serialized);
        $this->assertNull($serialized['uuid']);
        $this->assertNull($serialized['name']);
        $this->assertNull($serialized['description']);
        $this->assertSame(Dashboard::TYPE_USER, $serialized['type']);
        $this->assertNull($serialized['userId']);
        $this->assertNull($serialized['basedOnTemplate']);
        $this->assertSame(12, $serialized['gridColumns']);
        $this->assertSame(Dashboard::PERMISSION_FULL, $serialized['permissionLevel']);
        $this->assertSame([], $serialized['targetGroups']);
        $this->assertSame(0, $serialized['isDefault']);
        $this->assertSame(0, $serialized['isActive']);
        $this->assertNull($serialized['createdAt']);
        $this->assertNull($serialized['updatedAt']);
    }

    public function testConstants(): void
    {
        $this->assertSame('admin_template', Dashboard::TYPE_ADMIN_TEMPLATE);
        $this->assertSame('user', Dashboard::TYPE_USER);
        $this->assertSame('group_shared', Dashboard::TYPE_GROUP_SHARED);
        $this->assertSame('default', Dashboard::DEFAULT_GROUP_ID);
        $this->assertSame('user', Dashboard::SOURCE_USER);
        $this->assertSame('group', Dashboard::SOURCE_GROUP);
        $this->assertSame('default', Dashboard::SOURCE_DEFAULT);
        $this->assertSame('view_only', Dashboard::PERMISSION_VIEW_ONLY);
        $this->assertSame('add_only', Dashboard::PERMISSION_ADD_ONLY);
        $this->assertSame('full', Dashboard::PERMISSION_FULL);
    }

    public function testSetAndGetGroupId(): void
    {
        $this->dashboard->setGroupId('marketing');
        $this->assertSame('marketing', $this->dashboard->getGroupId());

        $this->dashboard->setGroupId(null);
        $this->assertNull($this->dashboard->getGroupId());
    }

    public function testJsonSerializeIncludesGroupId(): void
    {
        $serialized = $this->dashboard->jsonSerialize();
        $this->assertArrayHasKey('groupId', $serialized);
        $this->assertNull($serialized['groupId']);

        $this->dashboard->setGroupId('engineering');
        $serialized = $this->dashboard->jsonSerialize();
        $this->assertSame('engineering', $serialized['groupId']);
    }
}
