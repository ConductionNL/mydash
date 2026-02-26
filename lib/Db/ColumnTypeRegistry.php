<?php

/**
 * ColumnTypeRegistry
 *
 * Registry for database column type definitions.
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

/**
 * Registry for database column type definitions.
 */
class ColumnTypeRegistry
{
    /**
     * Column type for integer fields.
     *
     * @var string
     */
    public const TYPE_INTEGER = 'integer';

    /**
     * Column type for boolean fields.
     *
     * @var string
     */
    public const TYPE_BOOLEAN = 'boolean';

    /**
     * Column type for string fields.
     *
     * @var string
     */
    public const TYPE_STRING = 'string';

    /**
     * Get the column types for the dashboard entity.
     *
     * @return array The column name to type mapping.
     */
    public static function getDashboardTypes(): array
    {
        return [
            'id'              => self::TYPE_INTEGER,
            'basedOnTemplate' => self::TYPE_INTEGER,
            'gridColumns'     => self::TYPE_INTEGER,
            'isDefault'       => self::TYPE_INTEGER,
            'isActive'        => self::TYPE_INTEGER,
        ];
    }//end getDashboardTypes()

    /**
     * Get the column types for the widget placement entity.
     *
     * @return array The column name to type mapping.
     */
    public static function getWidgetPlacementTypes(): array
    {
        return [
            'id'           => self::TYPE_INTEGER,
            'dashboardId'  => self::TYPE_INTEGER,
            'gridX'        => self::TYPE_INTEGER,
            'gridY'        => self::TYPE_INTEGER,
            'gridWidth'    => self::TYPE_INTEGER,
            'gridHeight'   => self::TYPE_INTEGER,
            'isCompulsory' => self::TYPE_INTEGER,
            'isVisible'    => self::TYPE_INTEGER,
            'showTitle'    => self::TYPE_INTEGER,
            'sortOrder'    => self::TYPE_INTEGER,
        ];
    }//end getWidgetPlacementTypes()

    /**
     * Get the column types for the conditional rule entity.
     *
     * @return array The column name to type mapping.
     */
    public static function getConditionalRuleTypes(): array
    {
        return [
            'id'                => self::TYPE_INTEGER,
            'widgetPlacementId' => self::TYPE_INTEGER,
            'isInclude'         => self::TYPE_BOOLEAN,
        ];
    }//end getConditionalRuleTypes()

    /**
     * Get the column types for the admin setting entity.
     *
     * @return array The column name to type mapping.
     */
    public static function getAdminSettingTypes(): array
    {
        return [
            'id' => self::TYPE_INTEGER,
        ];
    }//end getAdminSettingTypes()

    /**
     * Get the column types for the tile entity.
     *
     * @return array The column name to type mapping.
     */
    public static function getTileTypes(): array
    {
        return [
            'id' => self::TYPE_INTEGER,
        ];
    }//end getTileTypes()
}//end class
