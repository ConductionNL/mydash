<?php

/**
 * InitialStateBuilder
 *
 * Centralises the per-page initial-state contract for MyDash. This is the
 * only class allowed to call {@see \OCP\AppFramework\Services\IInitialState::provideInitialState()};
 * a CI grep lint enforces that against `lib/Controller/` and `lib/Settings/`.
 *
 * Construct one builder per render with the destination page, call the
 * typed setters, then `apply()`. `apply()` validates that every required
 * key for the chosen page has been set, throwing
 * {@see \OCA\MyDash\Exception\MissingInitialStateException} when any are
 * missing — the page never renders with a partial payload.
 *
 * The contract version is stamped onto every payload under the key
 * `_schemaVersion`. Bumping {@see self::INITIAL_STATE_SCHEMA_VERSION}
 * MUST accompany any change to the per-page key sets, and MUST be paired
 * with the matching constant in `src/utils/loadInitialState.js`. See
 * REQ-INIT-002 for the rules.
 *
 * Workspace page (`#mydash-app`) keys:
 *  - widgets             — array of dashboard widget descriptors
 *  - layout              — array of WidgetPlacement rows for the active dashboard
 *  - primaryGroup        — group id of the user's resolved primary group
 *  - primaryGroupName    — human-readable display name for the primary group
 *  - isAdmin             — boolean, true when the user is in the admin group
 *  - activeDashboardId   — id of the currently active dashboard for the user
 *  - dashboardSource     — 'user' | 'group' | 'default' (drives canEdit)
 *  - groupDashboards     — list of group-scope dashboards visible to the user
 *  - userDashboards      — list of user-scope (personal) dashboards
 *  - allowUserDashboards — admin flag toggling the personal-dashboards UI
 *
 * Admin page (`#mydash-admin-settings`) keys:
 *  - allGroups           — every Nextcloud group, for the ordering UI
 *  - configuredGroups    — group ids the admin has explicitly ordered
 *  - widgets             — every available dashboard widget descriptor
 *  - allowUserDashboards — current value of the personal-dashboards admin flag
 *
 * Both payloads always carry `_schemaVersion`.
 *
 * @category  Service
 * @package   OCA\MyDash\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * @link https://conduction.nl/openspec/initial-state-contract REQ-INIT-002
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Service;

use OCA\MyDash\Exception\MissingInitialStateException;
use OCA\MyDash\Service\InitialState\Page;
use OCP\AppFramework\Services\IInitialState;

/**
 * Typed builder for the per-page initial-state payload (REQ-INIT-001, REQ-INIT-002).
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Twelve typed setters mirror the contract.
 * @SuppressWarnings(PHPMD.TooManyMethods)       Same.
 */
class InitialStateBuilder
{
    /**
     * Schema version stamped onto every payload under `_schemaVersion`.
     *
     * Bump in the same commit that changes any per-page key set. The JS
     * reader in `src/utils/loadInitialState.js` MUST keep its constant in
     * lockstep — version drift surfaces as a console warning at runtime
     * (REQ-INIT-002).
     *
     * @var integer
     */
    public const INITIAL_STATE_SCHEMA_VERSION = 1;

    /**
     * Reserved payload key carrying the schema version.
     *
     * @var string
     */
    public const KEY_SCHEMA_VERSION = '_schemaVersion';

    /**
     * Required key set per page. Keys MUST exactly match the spec's Data
     * Model — adding or removing a key is a spec change (REQ-INIT-002).
     *
     * @var array<string, list<string>>
     */
    private const REQUIRED_KEYS = [
        Page::WORKSPACE->value => [
            'widgets',
            'layout',
            'primaryGroup',
            'primaryGroupName',
            'isAdmin',
            'activeDashboardId',
            'dashboardSource',
            'groupDashboards',
            'userDashboards',
            'allowUserDashboards',
        ],
        Page::ADMIN->value     => [
            'allGroups',
            'configuredGroups',
            'widgets',
            'allowUserDashboards',
        ],
    ];

    /**
     * Buffered key/value pairs awaiting apply().
     *
     * @var array<string, mixed>
     */
    private array $values = [];

    /**
     * Constructor.
     *
     * @param IInitialState $initialState The Nextcloud initial-state service.
     * @param Page          $page         Destination page.
     */
    public function __construct(
        private readonly IInitialState $initialState,
        private readonly Page $page,
    ) {
    }//end __construct()

    /**
     * Set the dashboard widgets list (workspace + admin).
     *
     * @param array $widgets Widget descriptors `[{id, title, iconClass, iconUrl, url}, ...]`.
     *
     * @return self Fluent.
     */
    public function setWidgets(array $widgets): self
    {
        $this->values['widgets'] = $widgets;
        return $this;
    }//end setWidgets()

    /**
     * Set the active-dashboard layout (workspace).
     *
     * @param array $layout WidgetPlacement rows for the active dashboard.
     *
     * @return self Fluent.
     */
    public function setLayout(array $layout): self
    {
        $this->values['layout'] = $layout;
        return $this;
    }//end setLayout()

    /**
     * Set the primary group id (workspace).
     *
     * @param string $primaryGroup Resolved primary group id (e.g. 'default').
     *
     * @return self Fluent.
     */
    public function setPrimaryGroup(string $primaryGroup): self
    {
        $this->values['primaryGroup'] = $primaryGroup;
        return $this;
    }//end setPrimaryGroup()

    /**
     * Set the primary group display name (workspace).
     *
     * @param string $primaryGroupName Human-readable primary group name.
     *
     * @return self Fluent.
     */
    public function setPrimaryGroupName(string $primaryGroupName): self
    {
        $this->values['primaryGroupName'] = $primaryGroupName;
        return $this;
    }//end setPrimaryGroupName()

    /**
     * Set the is-admin flag (workspace).
     *
     * @param bool $isAdmin True when the user belongs to the admin group.
     *
     * @return self Fluent.
     */
    public function setIsAdmin(bool $isAdmin): self
    {
        $this->values['isAdmin'] = $isAdmin;
        return $this;
    }//end setIsAdmin()

    /**
     * Set the active dashboard id (workspace).
     *
     * @param string $activeDashboardId Id of the currently active dashboard.
     *
     * @return self Fluent.
     */
    public function setActiveDashboardId(string $activeDashboardId): self
    {
        $this->values['activeDashboardId'] = $activeDashboardId;
        return $this;
    }//end setActiveDashboardId()

    /**
     * Set the dashboard source (workspace).
     *
     * Valid values: 'user' | 'group' | 'default'. Drives canEdit on the
     * runtime shell (REQ-RTS-006).
     *
     * @param string $dashboardSource One of 'user', 'group', 'default'.
     *
     * @return self Fluent.
     */
    public function setDashboardSource(string $dashboardSource): self
    {
        $this->values['dashboardSource'] = $dashboardSource;
        return $this;
    }//end setDashboardSource()

    /**
     * Set the visible group dashboards (workspace).
     *
     * @param array $groupDashboards List of group-scope dashboards visible to the user.
     *
     * @return self Fluent.
     */
    public function setGroupDashboards(array $groupDashboards): self
    {
        $this->values['groupDashboards'] = $groupDashboards;
        return $this;
    }//end setGroupDashboards()

    /**
     * Set the user (personal) dashboards (workspace).
     *
     * @param array $userDashboards List of personal dashboards owned by the user.
     *
     * @return self Fluent.
     */
    public function setUserDashboards(array $userDashboards): self
    {
        $this->values['userDashboards'] = $userDashboards;
        return $this;
    }//end setUserDashboards()

    /**
     * Set the allow-user-dashboards flag (workspace + admin).
     *
     * @param bool $allowUserDashboards Current value of the admin flag.
     *
     * @return self Fluent.
     */
    public function setAllowUserDashboards(bool $allowUserDashboards): self
    {
        $this->values['allowUserDashboards'] = $allowUserDashboards;
        return $this;
    }//end setAllowUserDashboards()

    /**
     * Set every Nextcloud group (admin).
     *
     * @param array $allGroups List of `{id, displayName}` pairs.
     *
     * @return self Fluent.
     */
    public function setAllGroups(array $allGroups): self
    {
        $this->values['allGroups'] = $allGroups;
        return $this;
    }//end setAllGroups()

    /**
     * Set the configured (ordered) group ids (admin).
     *
     * @param array $configuredGroups Ordered list of group ids.
     *
     * @return self Fluent.
     */
    public function setConfiguredGroups(array $configuredGroups): self
    {
        $this->values['configuredGroups'] = $configuredGroups;
        return $this;
    }//end setConfiguredGroups()

    /**
     * Validate required keys then push every buffered pair plus the
     * schema version to {@see IInitialState::provideInitialState()}.
     *
     * @return void
     *
     * @throws MissingInitialStateException When any required key for the
     *                                      page was not set.
     */
    public function apply(): void
    {
        $required = self::REQUIRED_KEYS[$this->page->value];

        foreach ($required as $key) {
            if (array_key_exists($key, $this->values) === false) {
                throw new MissingInitialStateException(
                    page: $this->page->value,
                    key: $key
                );
            }
        }

        foreach ($this->values as $key => $value) {
            $this->initialState->provideInitialState($key, $value);
        }

        $this->initialState->provideInitialState(
            self::KEY_SCHEMA_VERSION,
            self::INITIAL_STATE_SCHEMA_VERSION
        );
    }//end apply()
}//end class
