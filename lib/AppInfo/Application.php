<?php

/**
 * Application
 *
 * Main application bootstrap class for MyDash.
 *
 * @category  AppInfo
 * @package   OCA\MyDash\AppInfo
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

namespace OCA\MyDash\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'mydash';

    /**
     * Constructor
     *
     * @param array $urlParams The URL parameters.
     */
    public function __construct(array $urlParams=[])
    {
        parent::__construct(appName: self::APP_ID, urlParams: $urlParams);
    }//end __construct()

    /**
     * Register services, event listeners, etc.
     *
     * @param IRegistrationContext $context The registration context.
     *
     * @return void
     */
    public function register(IRegistrationContext $context): void
    {
        // Register services, event listeners, etc.
    }//end register()

    /**
     * App initialization after all apps are registered.
     *
     * @param IBootContext $context The boot context.
     *
     * @return void
     */
    public function boot(IBootContext $context): void
    {
        // App initialization after all apps are registered.
        // Load custom header styling to override nldesign theme.
        // This must be loaded here (not in PageController) to override theme CSS.
        \OCP\Util::addStyle(application: self::APP_ID, file: 'mydash');
        \OCP\Util::addStyle(application: self::APP_ID, file: 'header-override');
    }//end boot()
}//end class
