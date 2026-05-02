<?php

/**
 * PageController
 *
 * Controller for rendering the main MyDash workspace page (REQ-INIT-001,
 * REQ-INIT-002). The page-render path constructs an
 * {@see \OCA\MyDash\Service\InitialStateBuilder} for
 * {@see \OCA\MyDash\Service\InitialState\Page::WORKSPACE}, populates every
 * key declared in the spec's Data Model, and applies — direct calls to
 * {@see \OCP\AppFramework\Services\IInitialState::provideInitialState()}
 * are forbidden here by the `lint:initial-state` CI guard.
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
use OCA\MyDash\Service\DashboardService;
use OCA\MyDash\Service\InitialState\Page;
use OCA\MyDash\Service\InitialStateBuilder;
use OCA\MyDash\Service\WidgetService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Dashboard\IManager;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

/**
 * Workspace page controller — wires the typed initial-state contract.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Boot path needs widget,
 *                                                  dashboard, settings and
 *                                                  group services to fill
 *                                                  the contract.
 */
class PageController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest         $request          The request.
     * @param IManager         $dashboardManager Nextcloud dashboard widget manager.
     * @param IInitialState    $initialState     The Nextcloud initial-state service.
     * @param IUserSession     $userSession      Active user session.
     * @param IGroupManager    $groupManager     Group manager (admin + primary).
     * @param WidgetService    $widgetService    Available-widgets descriptor formatter.
     * @param DashboardService $dashboardService Dashboard listing + resolver
     *                                           (also exposes the
     *                                           `allow_user_dashboards` flag
     *                                           — REQ-ASET-003).
     */
    public function __construct(
        IRequest $request,
        private readonly IManager $dashboardManager,
        private readonly IInitialState $initialState,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly WidgetService $widgetService,
        private readonly DashboardService $dashboardService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Render the workspace page.
     *
     * Wires the full workspace initial-state contract into the template via
     * {@see InitialStateBuilder}. Every key declared in REQ-INIT-002 is set
     * before `apply()` runs; missing keys raise
     * {@see \OCA\MyDash\Exception\MissingInitialStateException} so the page
     * never renders with a partial payload.
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
        $this->loadWidgetScripts();

        $user   = $this->userSession->getUser();
        $userId = '';
        if ($user !== null) {
            $userId = $user->getUID();
        }

        $primaryGroupId   = Dashboard::DEFAULT_GROUP_ID;
        $primaryGroupName = '';
        if ($user !== null) {
            $userGroups = $this->groupManager->getUserGroups(user: $user);
            if ($userGroups !== []) {
                $firstGroup       = reset($userGroups);
                $primaryGroupId   = $firstGroup->getGID();
                $primaryGroupName = $firstGroup->getDisplayName();
            }
        }

        $isAdmin = false;
        if ($userId !== '') {
            $isAdmin = $this->dashboardService->isAdmin(userId: $userId);
        }

        $visible = [];
        if ($userId !== '') {
            $visible = $this->dashboardService->getVisibleToUser(userId: $userId);
        }

        $groupDashboards = [];
        $userDashboards  = [];
        foreach ($visible as $entry) {
            $dashboard = $entry['dashboard'];
            // Dashboard entity has no icon column today — surface an empty
            // string so the frontend descriptor shape matches REQ-INIT-002.
            $descriptor = [
                'id'     => (string) $dashboard->getUuid(),
                'name'   => (string) $dashboard->getName(),
                'icon'   => '',
                'source' => $entry['source'],
            ];

            if ($entry['source'] === Dashboard::SOURCE_USER) {
                unset($descriptor['source']);
                $userDashboards[] = $descriptor;
                continue;
            }

            $groupDashboards[] = $descriptor;
        }

        $active = null;
        if ($userId !== '') {
            $active = $this->dashboardService->resolveActiveDashboard(
                userId: $userId,
                primaryGroupId: $primaryGroupId
            );
        }

        $activeDashboardId = '';
        $dashboardSource   = Dashboard::SOURCE_GROUP;
        $layout            = [];
        if ($active !== null) {
            $activeDashboard   = $active['dashboard'];
            $activeDashboardId = (string) $activeDashboard->getUuid();
            $dashboardSource   = (string) $active['source'];
            $placements        = $this->widgetService->getDashboardPlacements(
                dashboardId: $activeDashboard->getId()
            );
            $layout            = array_map(
                callback: function ($placement) {
                    return $placement->jsonSerialize();
                },
                array: $placements
            );
        }

        $allowUserDashboards = $this->dashboardService->getAllowUserDashboards();

        $builder = new InitialStateBuilder(
            initialState: $this->initialState,
            page: Page::WORKSPACE
        );

        $builder
            ->setWidgets($this->widgetService->getAvailableWidgets())
            ->setLayout($layout)
            ->setPrimaryGroup($primaryGroupId)
            ->setPrimaryGroupName($primaryGroupName)
            ->setIsAdmin($isAdmin)
            ->setActiveDashboardId($activeDashboardId)
            ->setDashboardSource($dashboardSource)
            ->setGroupDashboards($groupDashboards)
            ->setUserDashboards($userDashboards)
            ->setAllowUserDashboards($allowUserDashboards)
            ->apply();

        // REQ-SHELL-001: pass the chrome slot ids so Nextcloud treats
        // `#app-workspace` as the main content slot and allocates no left
        // navigation panel (the runtime shell renders its own slide-in
        // sidebar via `dashboard-switcher-sidebar`). Renderer parameter
        // names match the Nextcloud chrome conventions.
        $response = new TemplateResponse(
            appName: Application::APP_ID,
            templateName: 'index',
            params: [
                'id-app-content'    => '#app-workspace',
                'id-app-navigation' => null,
            ]
        );

        return $response;
    }//end index()

    /**
     * Load scripts for all available dashboard widgets.
     * This ensures legacy widgets can register their callbacks via OCA.Dashboard.register.
     *
     * @return void
     */
    private function loadWidgetScripts(): void
    {
        $widgets = $this->dashboardManager->getWidgets();

        foreach ($widgets as $widget) {
            // Call the widget's load() method to inject its scripts.
            $widget->load();
        }
    }//end loadWidgetScripts()
}//end class
