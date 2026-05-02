<?php

/**
 * MyDashAdmin
 *
 * Admin settings page for MyDash. Wires the typed admin initial-state
 * contract via {@see \OCA\MyDash\Service\InitialStateBuilder} (REQ-INIT-001,
 * REQ-INIT-002). Direct calls to
 * {@see \OCP\AppFramework\Services\IInitialState::provideInitialState()}
 * are forbidden here by the `lint:initial-state` CI guard.
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
use OCA\MyDash\Db\AdminSetting;
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Service\InitialState\Page;
use OCA\MyDash\Service\InitialStateBuilder;
use OCA\MyDash\Service\WidgetService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\Settings\ISettings;
use OCP\Util;

class MyDashAdmin implements ISettings
{
    /**
     * Constructor.
     *
     * @param IInitialState      $initialState  The Nextcloud initial-state service.
     * @param IGroupManager      $groupManager  Group manager (full group list).
     * @param WidgetService      $widgetService Available-widgets descriptor formatter.
     * @param AdminSettingMapper $settingMapper Admin settings store.
     */
    public function __construct(
        private readonly IInitialState $initialState,
        private readonly IGroupManager $groupManager,
        private readonly WidgetService $widgetService,
        private readonly AdminSettingMapper $settingMapper,
    ) {
    }//end __construct()

    /**
     * Get the admin settings form.
     *
     * Wires the full admin initial-state contract (REQ-INIT-002) before
     * rendering the template — every required key is set on the builder
     * so the page never renders with a partial payload.
     *
     * @return TemplateResponse The template response.
     */
    public function getForm(): TemplateResponse
    {
        Util::addScript(
            application: Application::APP_ID,
            file: 'mydash-admin'
        );

        $allGroups = [];
        foreach ($this->groupManager->search(search: '') as $group) {
            $allGroups[] = [
                'id'          => $group->getGID(),
                'displayName' => $group->getDisplayName(),
            ];
        }

        $configuredGroups = $this->settingMapper->getValue(
            key: 'configured_groups',
            default: []
        );
        if (is_array($configuredGroups) === false) {
            $configuredGroups = [];
        }

        $allowUserDashboards = (bool) $this->settingMapper->getValue(
            key: AdminSetting::KEY_ALLOW_USER_DASHBOARDS,
            default: false
        );

        (new InitialStateBuilder(
            initialState: $this->initialState,
            page: Page::ADMIN
        ))
            ->setAllGroups($allGroups)
            ->setConfiguredGroups($configuredGroups)
            ->setWidgets($this->widgetService->getAvailableWidgets())
            ->setAllowUserDashboards($allowUserDashboards)
            ->apply();

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
