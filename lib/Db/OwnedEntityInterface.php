<?php

/**
 * OwnedEntityInterface
 *
 * Interface for entities that belong to a specific user.
 *
 * @category  Db
 * @package   OCA\MyDash\Db
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\MyDash\Db;

/**
 * Interface for entities that belong to a specific user.
 */
interface OwnedEntityInterface
{
    /**
     * Get the user ID that owns this entity.
     *
     * @return string|null The user ID.
     */
    public function getUserId(): ?string;

    /**
     * Set the user ID that owns this entity.
     *
     * @param string|null $userId The user ID.
     *
     * @return void
     */
    public function setUserId(?string $userId): void;
}//end interface
