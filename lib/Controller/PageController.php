<?php

/**
 * PageController
 *
 * Controller for rendering the main MyDash workspace page. The workspace
 * initial-state payload is constructed via
 * {@see \OCA\MyDash\Service\InitialStateBuilder} per REQ-INIT-001 — direct
 * calls to IInitialState::provideInitialState are forbidden here (and any
 * other controller) and enforced by a grep lint test.
 *
 * @category  Controller
 * @package   OCA\MyDash\Controller
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

namespace OCA\MyDash\Controller;

use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Service\AdminSettingsService;
use OCA\MyDash\Service\AdminTemplateService;
use OCA\MyDash\Service\DashboardService;
use OCA\MyDash\Service\InitialStateBuilder;
use OCA\MyDash\Service\Page;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Dashboard\IManager as IDashboardManager;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

class PageController extends Controller
{
    /**
     * Constructor
     *
     * @param IRequest             $request             The request.
     * @param IInitialState        $initialState        Nextcloud initial-state service.
     * @param IDashboardManager    $dashboardManager    Dashboard widget manager.
     * @param IUserSession         $userSession         Current user session.
     * @param IGroupManager        $groupManager        Group membership lookup.
     * @param AdminSettingsService $adminSettings       MyDash admin settings.
     * @param DashboardService     $dashboardService    Dashboard service (active
     *                                                  resolver — REQ-DASH-018).
     * @param AdminTemplateService $templateService     Template service (primary
     *                                                  group resolver —
     *                                                  REQ-TMPL-012).
     */
    public function __construct(
        IRequest $request,
        private readonly IInitialState $initialState,
        private readonly IDashboardManager $dashboardManager,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly AdminSettingsService $adminSettings,
        private readonly DashboardService $dashboardService,
        private readonly AdminTemplateService $templateService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Render the main workspace index page.
     *
     * Builds the workspace initial-state payload via InitialStateBuilder
     * (REQ-INIT-001) and applies it before the template is rendered.
     *
     * @return TemplateResponse The template response.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        Util::addScript(application: Application::APP_ID, file: 'mydash-main');
        Util::addStyle(application: Application::APP_ID, file: 'mydash');

        // Load all widget scripts so legacy widgets can register their callbacks.
        $widgets = $this->loadWidgetScripts();

        $user     = $this->userSession->getUser();
        $isAdmin  = false;
        $userId   = null;
        if ($user !== null) {
            $userId  = $user->getUID();
            $isAdmin = $this->groupManager->isAdmin(userId: $userId);
        }

        // Resolve the primary group via the canonical REQ-TMPL-012 authority.
        $primaryGroup = ($userId !== null)
            ? $this->templateService->resolvePrimaryGroup(userId: $userId)
            : Dashboard::DEFAULT_GROUP_ID;

        $primaryGroupName = $primaryGroup;
        if ($primaryGroup !== 'default'
            && $this->groupManager->groupExists(gid: $primaryGroup) === true
        ) {
            $group = $this->groupManager->get(gid: $primaryGroup);
            if ($group !== null) {
                $primaryGroupName = $group->getDisplayName();
            }
        }

        // Resolve the active dashboard via the REQ-DASH-018 precedence chain.
        $activeDashboardId = '';
        $dashboardSource   = 'group';
        if ($userId !== null) {
            $resolved = $this->dashboardService->resolveActiveDashboard(
                userId: $userId,
                primaryGroupId: $primaryGroup
            );
            if ($resolved !== null) {
                $activeDashboardId = (string) $resolved['dashboard']->getUuid();
                $dashboardSource   = $resolved['source'];
            }
        }

        $settings = $this->adminSettings->getSettings();

        $builder = new InitialStateBuilder(
            initialState: $this->initialState,
            page: Page::WORKSPACE
        );
        $builder
            ->setWidgets(widgets: $this->describeWidgets(widgets: $widgets))
            ->setLayout(layout: [])
            ->setPrimaryGroup(primaryGroup: $primaryGroup)
            ->setPrimaryGroupName(primaryGroupName: $primaryGroupName)
            ->setIsAdmin(isAdmin: $isAdmin)
            ->setActiveDashboardId(activeDashboardId: $activeDashboardId)
            ->setDashboardSource(dashboardSource: $dashboardSource)
            ->setGroupDashboards(groupDashboards: [])
            ->setUserDashboards(userDashboards: [])
            ->setAllowUserDashboards(
                allowUserDashboards: (bool) ($settings['allowUserDashboards'] ?? false)
            )
            ->apply();

        return new TemplateResponse(
            appName: Application::APP_ID,
            templateName: 'index'
        );
    }//end index()

    /**
     * Load scripts for all available dashboard widgets.
     *
     * This ensures legacy widgets can register their callbacks via
     * OCA.Dashboard.register.
     *
     * @return array<string, \OCP\Dashboard\IWidget> Map of widget id to widget.
     */
    private function loadWidgetScripts(): array
    {
        $widgets = $this->dashboardManager->getWidgets();

        foreach ($widgets as $widget) {
            // Call the widget's load() method to inject its scripts.
            $widget->load();
        }

        return $widgets;
    }//end loadWidgetScripts()

    /**
     * Build serialisable widget descriptors for the initial-state payload.
     *
     * @param array<string, \OCP\Dashboard\IWidget> $widgets Widget map.
     *
     * @return array<int, array{id:string,title:string,iconClass:string,iconUrl:string,url:?string}>
     */
    private function describeWidgets(array $widgets): array
    {
        $descriptors = [];
        foreach ($widgets as $id => $widget) {
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
