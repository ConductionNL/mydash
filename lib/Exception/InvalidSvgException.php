<?php

/**
 * InvalidSvgException
 *
 * Raised when an uploaded SVG cannot be parsed by libxml or is fully
 * stripped to an empty document by `SvgSanitiser::sanitize()`. Maps to
 * HTTP 400 + stable error code `invalid_svg`. Spec: REQ-RES-009.
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
 * Uploaded SVG bytes failed to parse or sanitised to an empty document.
 */
class InvalidSvgException extends ResourceException
{

    /**
     * Stable error code.
     *
     * @var string
     */
    protected string $errorCode = 'invalid_svg';

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
        string $message='SVG could not be parsed or contained no allowed content'
    ) {
        parent::__construct(message: $message);
    }//end __construct()
}//end class
