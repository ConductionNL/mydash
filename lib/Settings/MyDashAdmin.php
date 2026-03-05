<?php

/**
 * MyDashAdmin
 *
 * Admin settings page for MyDash.
 *
 * @category  Settings
 * @package   OCA\MyDash\Settings
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

namespace OCA\MyDash\Settings;

use OCA\MyDash\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class MyDashAdmin implements ISettings
{
    /**
     * Get the admin settings form.
     *
     * @return TemplateResponse The template response.
     */
    public function getForm(): TemplateResponse
    {
        Util::addScript(
            application: Application::APP_ID,
            file: 'mydash-admin'
        );

        return new TemplateResponse(
            appName: Application::APP_ID,
            templateName: 'settings/admin'
        );
    }//end getForm()

    /**
     * Get the settings section ID.
     *
     * @return string The section ID.
     */
    public function getSection(): string
    {
        return 'mydash';
    }//end getSection()

    /**
     * Get the settings priority.
     *
     * @return int The priority.
     */
    public function getPriority(): int
    {
        return 10;
    }//end getPriority()
}//end class
