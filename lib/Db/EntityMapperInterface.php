<?php

/**
 * EntityMapperInterface
 *
 * Interface for MyDash entity mappers with common operations.
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

use OCP\AppFramework\Db\Entity;

/**
 * Interface for MyDash entity mappers with common operations.
 */
interface EntityMapperInterface
{
    /**
     * Find an entity by its ID.
     *
     * @param int $id The entity ID.
     *
     * @return Entity The found entity.
     */
    public function find(int $id): Entity;
}//end interface
