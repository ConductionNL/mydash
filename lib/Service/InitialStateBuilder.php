<?php

/**
 * InitialStateBuilder
 *
 * Typed builder for the per-page initial-state payload that PHP pushes to the
 * Vue mounts. Every key declared in REQ-INIT-002 of the
 * `initial-state-contract` spec MUST be set through this builder; controllers
 * MUST NOT call IInitialState::provideInitialState directly.
 *
 * Workspace page (#mydash-app) keys:
 *   - widgets              (array)   default []
 *   - layout               (array)   default []
 *   - primaryGroup         (string)  default 'default'
 *   - primaryGroupName     (string)  default ''
 *   - isAdmin              (bool)    default false
 *   - activeDashboardId    (string)  default ''
 *   - dashboardSource      (string)  default 'group'  (user|group|default)
 *   - groupDashboards      (array)   default []
 *   - userDashboards       (array)   default []
 *   - allowUserDashboards  (bool)    default false
 *
 * Admin page (#mydash-admin-settings) keys:
 *   - allGroups            (array)   default []
 *   - configuredGroups     (array)   default []
 *   - widgets              (array)   default []
 *   - allowUserDashboards  (bool)    default false
 *
 * Every payload is also stamped with `_schemaVersion`
 * (see {@see self::INITIAL_STATE_SCHEMA_VERSION}). The matching JS reader
 * lives in `src/utils/loadInitialState.js`.
 *
 * @category  Service
 * @package   OCA\MyDash\Service
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

namespace OCA\MyDash\Service;

use OCA\MyDash\Exception\MissingInitialStateException;
use OCP\AppFramework\Services\IInitialState;

/**
 * Centralised typed builder for MyDash initial-state payloads.
 *
 * See REQ-INIT-001..REQ-INIT-005 in the `initial-state-contract` spec.
 */
class InitialStateBuilder
{
    /**
     * Schema version stamped on every payload under key `_schemaVersion`.
     *
     * Bumping this value is a deliberate spec change per REQ-INIT-002 and
     * MUST be coordinated with the JS reader's compiled-in constant.
     *
     * @var int
     */
    public const INITIAL_STATE_SCHEMA_VERSION = 1;

    /**
     * Reserved key used to stamp the schema version on every payload.
     *
     * @var string
     */
    public const SCHEMA_VERSION_KEY = '_schemaVersion';

    /**
     * Required key sets per page. Keep in sync with REQ-INIT-002 Data Model.
     *
     * @var array<string, array<int, string>>
     */
    private const REQUIRED_KEYS = [
        'workspace' => [
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
        'admin'     => [
            'allGroups',
            'configuredGroups',
            'widgets',
            'allowUserDashboards',
        ],
    ];

    /**
     * Accumulated key/value pairs to push on apply().
     *
     * @var array<string, mixed>
     */
    private array $values = [];

    /**
     * Construct a new InitialStateBuilder.
     *
     * @param IInitialState $initialState The Nextcloud initial-state service.
     * @param Page          $page         The page this builder targets.
     */
    public function __construct(
        private readonly IInitialState $initialState,
        private readonly Page $page,
    ) {
    }//end __construct()

    /**
     * Set the dashboard widgets descriptor list (workspace + admin).
     *
     * @param array $widgets Array of {id,title,iconClass,iconUrl,url}.
     *
     * @return self
     */
    public function setWidgets(array $widgets): self
    {
        $this->values['widgets'] = $widgets;
        return $this;
    }//end setWidgets()

    /**
     * Set the workspace layout grid (workspace).
     *
     * @param array $layout Array of WorkspaceWidget descriptors.
     *
     * @return self
     */
    public function setLayout(array $layout): self
    {
        $this->values['layout'] = $layout;
        return $this;
    }//end setLayout()

    /**
     * Set the primary group identifier (workspace).
     *
     * @param string $primaryGroup The group identifier.
     *
     * @return self
     */
    public function setPrimaryGroup(string $primaryGroup): self
    {
        $this->values['primaryGroup'] = $primaryGroup;
        return $this;
    }//end setPrimaryGroup()

    /**
     * Set the primary group display name (workspace).
     *
     * @param string $primaryGroupName The display name.
     *
     * @return self
     */
    public function setPrimaryGroupName(string $primaryGroupName): self
    {
        $this->values['primaryGroupName'] = $primaryGroupName;
        return $this;
    }//end setPrimaryGroupName()

    /**
     * Set whether the current user is an admin (workspace).
     *
     * @param bool $isAdmin True if admin.
     *
     * @return self
     */
    public function setIsAdmin(bool $isAdmin): self
    {
        $this->values['isAdmin'] = $isAdmin;
        return $this;
    }//end setIsAdmin()

    /**
     * Set the active dashboard identifier (workspace).
     *
     * @param string $activeDashboardId The dashboard id.
     *
     * @return self
     */
    public function setActiveDashboardId(string $activeDashboardId): self
    {
        $this->values['activeDashboardId'] = $activeDashboardId;
        return $this;
    }//end setActiveDashboardId()

    /**
     * Set the source of the active dashboard (workspace).
     *
     * @param string $dashboardSource One of 'user'|'group'|'default'.
     *
     * @return self
     */
    public function setDashboardSource(string $dashboardSource): self
    {
        $this->values['dashboardSource'] = $dashboardSource;
        return $this;
    }//end setDashboardSource()

    /**
     * Set the available group dashboards (workspace).
     *
     * @param array $groupDashboards Array of {id,name,icon,source?}.
     *
     * @return self
     */
    public function setGroupDashboards(array $groupDashboards): self
    {
        $this->values['groupDashboards'] = $groupDashboards;
        return $this;
    }//end setGroupDashboards()

    /**
     * Set the available user dashboards (workspace).
     *
     * @param array $userDashboards Array of {id,name,icon}.
     *
     * @return self
     */
    public function setUserDashboards(array $userDashboards): self
    {
        $this->values['userDashboards'] = $userDashboards;
        return $this;
    }//end setUserDashboards()

    /**
     * Set whether user-owned dashboards are permitted (workspace + admin).
     *
     * @param bool $allowUserDashboards True if allowed.
     *
     * @return self
     */
    public function setAllowUserDashboards(bool $allowUserDashboards): self
    {
        $this->values['allowUserDashboards'] = $allowUserDashboards;
        return $this;
    }//end setAllowUserDashboards()

    /**
     * Set the list of all Nextcloud groups (admin).
     *
     * @param array $allGroups Array of {id,displayName}.
     *
     * @return self
     */
    public function setAllGroups(array $allGroups): self
    {
        $this->values['allGroups'] = $allGroups;
        return $this;
    }//end setAllGroups()

    /**
     * Set the list of MyDash-configured group ids (admin).
     *
     * @param array $configuredGroups Array of group id strings.
     *
     * @return self
     */
    public function setConfiguredGroups(array $configuredGroups): self
    {
        $this->values['configuredGroups'] = $configuredGroups;
        return $this;
    }//end setConfiguredGroups()

    /**
     * Validate the required key set for the current page and push every
     * accumulated value (plus `_schemaVersion`) to the IInitialState service.
     *
     * @return void
     *
     * @throws MissingInitialStateException When a required key was not set.
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
            $this->initialState->provideInitialState(key: $key, data: $value);
        }

        $this->initialState->provideInitialState(
            key: self::SCHEMA_VERSION_KEY,
            data: self::INITIAL_STATE_SCHEMA_VERSION
        );
    }//end apply()
}//end class
