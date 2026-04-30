<?php

/**
 * MyDashAdmin
 *
 * Admin settings page for MyDash. The admin initial-state payload is built
 * via {@see \OCA\MyDash\Service\InitialStateBuilder} per REQ-INIT-001 of the
 * `initial-state-contract` spec — direct calls to
 * IInitialState::provideInitialState are forbidden and enforced by a grep
 * lint test.
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
use OCA\MyDash\Service\AdminSettingsService;
use OCA\MyDash\Service\InitialStateBuilder;
use OCA\MyDash\Service\Page;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Dashboard\IManager as IDashboardManager;
use OCP\IGroupManager;
use OCP\Settings\ISettings;
use OCP\Util;

class MyDashAdmin implements ISettings
{
    /**
     * Constructor
     *
     * @param IInitialState        $initialState     Nextcloud initial-state service.
     * @param IDashboardManager    $dashboardManager Dashboard widget manager.
     * @param IGroupManager        $groupManager     Group lookup.
     * @param AdminSettingsService $adminSettings    MyDash admin settings.
     */
    public function __construct(
        private readonly IInitialState $initialState,
        private readonly IDashboardManager $dashboardManager,
        private readonly IGroupManager $groupManager,
        private readonly AdminSettingsService $adminSettings,
    ) {
    }//end __construct()

    /**
     * Get the admin settings form.
     *
     * Builds the admin initial-state payload via InitialStateBuilder
     * (REQ-INIT-001) before rendering the template.
     *
     * @return TemplateResponse The template response.
     */
    public function getForm(): TemplateResponse
    {
        Util::addScript(
            application: Application::APP_ID,
            file: 'mydash-admin'
        );

        $settings = $this->adminSettings->getSettings();

        $builder = new InitialStateBuilder(
            initialState: $this->initialState,
            page: Page::ADMIN
        );
        $builder
            ->setAllGroups(allGroups: $this->describeGroups())
            ->setConfiguredGroups(configuredGroups: [])
            ->setWidgets(widgets: $this->describeWidgets())
            ->setAllowUserDashboards(
                allowUserDashboards: (bool) ($settings['allowUserDashboards'] ?? false)
            )
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

    /**
     * Build serialisable group descriptors for the admin initial-state.
     *
     * @return array<int, array{id:string,displayName:string}>
     */
    private function describeGroups(): array
    {
        $descriptors = [];
        foreach ($this->groupManager->search(search: '') as $group) {
            $descriptors[] = [
                'id'          => $group->getGID(),
                'displayName' => $group->getDisplayName(),
            ];
        }

        return $descriptors;
    }//end describeGroups()

    /**
     * Build serialisable widget descriptors for the admin initial-state.
     *
     * @return array<int, array{id:string,title:string,iconClass:string,iconUrl:string,url:?string}>
     */
    private function describeWidgets(): array
    {
        $descriptors = [];
        foreach ($this->dashboardManager->getWidgets() as $id => $widget) {
            $descriptors[] = [
                'id'        => $id,
                'title'     => $widget->getTitle(),
                'iconClass' => $widget->getIconClass(),
                'iconUrl'   => '',
                'url'       => $widget->getUrl(),
            ];
        }

        return $descriptors;
    }//end describeWidgets()
}//end class
