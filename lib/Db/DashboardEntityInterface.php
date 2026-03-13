<?php

/**
 * DashboardEntityInterface
 *
 * Interface for MyDash entities that support JSON serialization.
 *
 * @category  Db
 * @package   OCA\MyDash\Db
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

namespace OCA\MyDash\Db;

use JsonSerializable;

/**
 * Interface for MyDash entities that support JSON serialization.
 */
interface DashboardEntityInterface extends JsonSerializable
{
    /**
     * Get the entity ID.
     *
     * @return int|null The entity ID.
     */
    public function getId(): ?int;

    /**
     * Serialize the entity to an array.
     *
     * @return array The serialized entity data.
     */
    public function jsonSerialize(): array;
}//end interface
