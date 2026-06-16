<?php

/**
 * FolderNotFoundException
 *
 * Thrown by {@see \OCA\MyDash\Service\FilesWidgetService} when the
 * placement-configured folder no longer exists, has been moved out of
 * reach, or the user's sub-path navigation lands on a missing node
 * (REQ-FLS-009).
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
 * Files-widget folder-resolution failure.
 */
class FolderNotFoundException extends ResourceException
{

    /**
     * Stable error code surfaced to the API consumer.
     *
     * @var string
     */
    protected string $errorCode = 'folder_not_found';

    /**
     * HTTP status code used by the controller envelope.
     *
     * @var integer
     */
    protected int $httpStatus = 404;

    /**
     * Constructor.
     *
     * @param string $message Optional override message.
     */
    public function __construct(string $message='The configured folder no longer exists')
    {
        parent::__construct(message: $message);
    }//end __construct()
}//end class
