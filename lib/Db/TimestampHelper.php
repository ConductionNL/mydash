<?php

/**
 * TimestampHelper
 *
 * Helper for generating and formatting timestamps.
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

use DateTime;

/**
 * Helper for generating and formatting timestamps.
 */
class TimestampHelper
{
    /**
     * The standard datetime format for database storage.
     *
     * @var string
     */
    public const FORMAT_DB = 'Y-m-d H:i:s';

    /**
     * The ISO 8601 format for API responses.
     *
     * @var string
     */
    public const FORMAT_ISO = 'c';

    /**
     * Get the current timestamp as a formatted string.
     *
     * @param string $format The datetime format.
     *
     * @return string The formatted timestamp.
     */
    public static function now(
        string $format=self::FORMAT_DB
    ): string {
        return (new DateTime())->format(format: $format);
    }//end now()

    /**
     * Format a DateTime object as a string.
     *
     * Returns null if the DateTime is null.
     *
     * @param DateTime|null $dateTime The DateTime to format.
     * @param string        $format   The datetime format.
     *
     * @return string|null The formatted string or null.
     */
    public static function format(
        ?DateTime $dateTime,
        string $format=self::FORMAT_ISO
    ): ?string {
        if ($dateTime === null) {
            return null;
        }

        return $dateTime->format(format: $format);
    }//end format()
}//end class
