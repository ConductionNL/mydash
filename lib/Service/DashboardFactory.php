<?php

/**
 * DashboardFactory
 *
 * Factory service for creating dashboard entities.
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
use OCA\MyDash\Db\Dashboard;

/**
 * Factory service for creating dashboard entities.
 */
class DashboardFactory
{
    /**
     * Create a new dashboard entity.
     *
     * @param string      $userId      The user ID.
     * @param string      $name        The dashboard name.
     * @param string|null $description The dashboard description.
     *
     * @return Dashboard The created dashboard entity (not yet persisted).
     */
    public function create(
        string $userId,
        string $name,
        ?string $description=null
    ): Dashboard {
        $now       = (new DateTime())->format('Y-m-d H:i:s');
        $dashboard = new Dashboard();
        $dashboard->setUuid($this->generateUuid());
        $dashboard->setName($name);
        $dashboard->setDescription($description);
        $dashboard->setType(Dashboard::TYPE_USER);
        $dashboard->setUserId($userId);
        $dashboard->setGridColumns(12);
        $dashboard->setPermissionLevel(Dashboard::PERMISSION_FULL);
        $dashboard->setIsActive(1);
        $dashboard->setCreatedAt($now);
        $dashboard->setUpdatedAt($now);

        return $dashboard;
    }//end create()

    /**
     * Generate a UUID v4.
     *
     * @return string The generated UUID.
     */
    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($data), 4)
        );
    }//end generateUuid()
}//end class
