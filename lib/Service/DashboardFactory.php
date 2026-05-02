<?php

/**
 * DashboardFactory
 *
 * Factory service for creating dashboard entities. Enforces the
 * `(type, groupId)` invariant required by REQ-DASH-011.
 *
 * @category  Service
 * @package   OCA\MyDash\Service
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

namespace OCA\MyDash\Service;

use DateTime;
use InvalidArgumentException;
use OCA\MyDash\Db\Dashboard;

/**
 * Factory service for creating dashboard entities.
 */
class DashboardFactory
{
    /**
     * Create a new dashboard entity.
     *
     * Enforces the REQ-DASH-011 invariant: `type === TYPE_GROUP_SHARED`
     * iff `groupId !== null`. Throws `InvalidArgumentException` on
     * mismatch — no row is persisted in that case (the caller never
     * receives an entity to insert).
     *
     * @param string|null $userId          The user ID — must be non-null for
     *                                     `TYPE_USER`, must be null for
     *                                     `TYPE_GROUP_SHARED` /
     *                                     `TYPE_ADMIN_TEMPLATE`.
     * @param string      $name            The dashboard name.
     * @param string|null $description     The dashboard description.
     * @param string      $type            The dashboard type
     *                                     (default
     *                                     {@see Dashboard::TYPE_USER}).
     * @param string|null $groupId         The group ID — required when
     *                                     `type === TYPE_GROUP_SHARED`,
     *                                     forbidden otherwise.
     * @param int         $gridColumns     The grid column count.
     * @param string      $permissionLevel The permission level (default
     *                                     `Dashboard::PERMISSION_FULL`).
     *
     * @return Dashboard The created dashboard entity (not yet persisted).
     *
     * @throws InvalidArgumentException When the (type, groupId) invariant
     *                                  is violated.
     */
    public function create(
        ?string $userId,
        string $name,
        ?string $description=null,
        string $type=Dashboard::TYPE_USER,
        ?string $groupId=null,
        int $gridColumns=12,
        string $permissionLevel=Dashboard::PERMISSION_FULL
    ): Dashboard {
        $this->assertTypeGroupInvariant(type: $type, groupId: $groupId);

        $now       = (new DateTime())->format(format: 'Y-m-d H:i:s');
        $dashboard = new Dashboard();
        $dashboard->setUuid($this->generateUuid());
        $dashboard->setName($name);
        $dashboard->setDescription($description);
        $dashboard->setType($type);
        $dashboard->setUserId($userId);
        $dashboard->setGroupId($groupId);
        $dashboard->setGridColumns($gridColumns);
        $dashboard->setPermissionLevel($permissionLevel);
        // Group-shared dashboards are not "active" per-user — activation
        // is a personal-scope concept tied to the active-dashboard cookie.
        $isActive = 0;
        if ($type === Dashboard::TYPE_USER) {
            $isActive = 1;
        }

        $dashboard->setIsActive($isActive);
        $dashboard->setCreatedAt($now);
        $dashboard->setUpdatedAt($now);

        return $dashboard;
    }//end create()

    /**
     * Assert the (type, groupId) invariant of REQ-DASH-011.
     *
     * @param string      $type    The dashboard type.
     * @param string|null $groupId The group ID.
     *
     * @return void
     *
     * @throws InvalidArgumentException When the invariant is violated.
     */
    private function assertTypeGroupInvariant(
        string $type,
        ?string $groupId
    ): void {
        if ($type === Dashboard::TYPE_GROUP_SHARED) {
            if ($groupId === null || $groupId === '') {
                throw new InvalidArgumentException(
                    message: 'Dashboard type group_shared requires a non-empty groupId'
                );
            }

            return;
        }

        if ($groupId !== null) {
            throw new InvalidArgumentException(
                message: 'Dashboard type '.$type.' must not have a groupId'
            );
        }
    }//end assertTypeGroupInvariant()

    /**
     * Generate a UUID v4.
     *
     * @return string The generated UUID.
     */
    private function generateUuid(): string
    {
        $data    = random_bytes(length: 16);
        $data[6] = chr(codepoint: ord(character: $data[6]) & 0x0f | 0x40);
        $data[8] = chr(codepoint: ord(character: $data[8]) & 0x3f | 0x80);

        return vsprintf(
            format: '%s%s-%s-%s-%s-%s%s%s',
            values: str_split(string: bin2hex(string: $data), length: 4)
        );
    }//end generateUuid()
}//end class
