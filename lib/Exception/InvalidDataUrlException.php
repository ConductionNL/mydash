<?php

/**
 * InvalidDataUrlException
 *
 * Raised when the supplied base64 string does not parse as a
 * `data:image/<type>;base64,...` data URL. Maps to HTTP 400 +
 * error code `invalid_data_url`.
 *
 * @category  Exception
 * @package   OCA\MyDash\Exception
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

namespace OCA\MyDash\Exception;

/**
 * Supplied base64 string is not a valid data URL.
 */
class InvalidDataUrlException extends ResourceException
{

    /**
     * Stable error code.
     *
     * @var string
     */
    protected string $errorCode = 'invalid_data_url';

    /**
     * HTTP status.
     *
     * @var integer
     */
    protected int $httpStatus = 400;

    /**
     * Constructor.
     *
     * @param string $message Display message.
     */
    public function __construct(
        string $message='Body must contain a base64 data URL'
    ) {
        parent::__construct(message: $message);
    }//end __construct()
}//end class
