<?php

/**
 * CorruptImageException
 *
 * Raised when `getimagesizefromstring` returns false for a raster
 * payload, indicating the bytes are not a valid image. Maps to
 * HTTP 400 + error code `corrupt_image`.
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
 * Image bytes could not be decoded by the image library.
 */
class CorruptImageException extends ResourceException
{

    /**
     * Stable error code.
     *
     * @var string
     */
    protected string $errorCode = 'corrupt_image';

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
        string $message='Image content appears to be corrupt'
    ) {
        parent::__construct(message: $message);
    }//end __construct()
}//end class
