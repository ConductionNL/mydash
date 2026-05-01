<?php

/**
 * GridPositionInterface
 *
 * Interface for entities with grid position and size properties.
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
 * Interface for entities with grid position and size properties.
 */
interface GridPositionInterface
{
    /**
     * Get the grid X position.
     *
     * @return int The X position.
     */
    public function getGridX(): int;

    /**
     * Set the grid X position.
     *
     * @param int $gridX The X position.
     *
     * @return void
     */
    public function setGridX(int $gridX): void;

    /**
     * Get the grid Y position.
     *
     * @return int The Y position.
     */
    public function getGridY(): int;

    /**
     * Set the grid Y position.
     *
     * @param int $gridY The Y position.
     *
     * @return void
     */
    public function setGridY(int $gridY): void;

    /**
     * Get the grid width.
     *
     * @return int The width.
     */
    public function getGridWidth(): int;

    /**
     * Set the grid width.
     *
     * @param int $gridWidth The width.
     *
     * @return void
     */
    public function setGridWidth(int $gridWidth): void;

    /**
     * Get the grid height.
     *
     * @return int The height.
     */
    public function getGridHeight(): int;

    /**
     * Set the grid height.
     *
     * @param int $gridHeight The height.
     *
     * @return void
     */
    public function setGridHeight(int $gridHeight): void;
}//end interface
