<?php

/**
 * FileTypeNotAllowedException
 *
 * Raised when the supplied filename's extension is not in the
 * admin-configured allow-list (default: `txt, md, docx, xlsx, csv, odt`).
 * Maps to HTTP 400 + error code `file_type_not_allowed`.
 *
 * @category  Exception
 * @package   OCA\MyDash\Exception
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Exception;

/**
 * Extension is outside the admin-configured allow-list.
 */
class FileTypeNotAllowedException extends ResourceException
{

    /**
     * Stable error code.
     *
     * @var string
     */
    protected string $errorCode = 'file_type_not_allowed';

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
        string $message='File type not allowed'
    ) {
        parent::__construct(message: $message);
    }//end __construct()
}//end class
