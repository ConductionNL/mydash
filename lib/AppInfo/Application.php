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

/**
 * Main application class for MyDash.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) - \OCP\Util::addStyle() is the Nextcloud API for CSS injection
 */
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
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) - required by IBootstrap interface
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
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) - required by IBootstrap interface
     */
    public function boot(IBootContext $context): void
    {
        // App initialization after all apps are registered.
        \OCP\Util::addStyle(application: self::APP_ID, file: 'mydash');
    }//end boot()
}//end class
