<?php

/**
 * TimestampedEntityInterface
 *
 * Interface for entities with created and updated timestamps.
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
 * Interface for entities with created and updated timestamps.
 */
interface TimestampedEntityInterface
{
    /**
     * Get the creation timestamp.
     *
     * @return string|null The creation timestamp.
     */
    public function getCreatedAt(): ?string;

    /**
     * Set the creation timestamp.
     *
     * @param string $createdAt The creation timestamp.
     *
     * @return void
     */
    public function setCreatedAt(string $createdAt): void;

    /**
     * Get the update timestamp.
     *
     * @return string|null The update timestamp.
     */
    public function getUpdatedAt(): ?string;

    /**
     * Set the update timestamp.
     *
     * @param string $updatedAt The update timestamp.
     *
     * @return void
     */
    public function setUpdatedAt(string $updatedAt): void;
}//end interface
