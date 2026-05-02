<?php

/**
 * AdminSettingsService
 *
 * Service for managing admin settings.
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

use InvalidArgumentException;
use OCA\MyDash\Db\AdminSetting;
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Db\Dashboard;

/**
 * Service for managing admin settings.
 */
class AdminSettingsService
{
    /**
     * Constructor
     *
     * @param AdminSettingMapper $settingMapper The admin setting mapper.
     */
    public function __construct(
        private readonly AdminSettingMapper $settingMapper,
    ) {
    }//end __construct()

    /**
     * Get all admin settings with defaults.
     *
     * @return array The settings array.
     */
    public function getSettings(): array
    {
        $settings = $this->settingMapper->getAllAsArray();

        $permKey  = AdminSetting::KEY_DEFAULT_PERMISSION_LEVEL;
        $permDef  = Dashboard::PERMISSION_ADD_ONLY;
        $userKey  = AdminSetting::KEY_ALLOW_USER_DASHBOARDS;
        $multiKey = AdminSetting::KEY_ALLOW_MULTIPLE_DASHBOARDS;
        $gridKey  = AdminSetting::KEY_DEFAULT_GRID_COLUMNS;

        return [
            'defaultPermissionLevel'  => $settings[$permKey] ?? $permDef,
            // REQ-ASET-003 (extended): default `false` — admins MUST opt in
            // to personal dashboard creation.
            'allowUserDashboards'     => $settings[$userKey] ?? false,
            'allowMultipleDashboards' => $settings[$multiKey] ?? true,
            'defaultGridColumns'      => $settings[$gridKey] ?? 12,
        ];
    }//end getSettings()

    /**
     * Update admin settings.
     *
     * @param string|null $defaultPermLevel Default permission level.
     * @param bool|null   $allowUserDash    Allow user dashboards.
     * @param bool|null   $allowMultiDash   Allow multiple dashboards.
     * @param int|null    $defaultGridCols  Default grid columns.
     *
     * @return void
     */
    public function updateSettings(
        ?string $defaultPermLevel=null,
        ?bool $allowUserDash=null,
        ?bool $allowMultiDash=null,
        ?int $defaultGridCols=null
    ): void {
        if ($defaultPermLevel !== null) {
            $this->settingMapper->setSetting(
                key: AdminSetting::KEY_DEFAULT_PERMISSION_LEVEL,
                value: $defaultPermLevel
            );
        }

        if ($allowUserDash !== null) {
            $this->settingMapper->setSetting(
                key: AdminSetting::KEY_ALLOW_USER_DASHBOARDS,
                value: $allowUserDash
            );
        }

        if ($allowMultiDash !== null) {
            $this->settingMapper->setSetting(
                key: AdminSetting::KEY_ALLOW_MULTIPLE_DASHBOARDS,
                value: $allowMultiDash
            );
        }

        if ($defaultGridCols !== null) {
            $this->settingMapper->setSetting(
                key: AdminSetting::KEY_DEFAULT_GRID_COLUMNS,
                value: $defaultGridCols
            );
        }
    }//end updateSettings()

    /**
     * Get the persisted admin-chosen group priority order (REQ-ASET-012).
     *
     * Defensive read: returns `[]` when the row is missing, when the value
     * is not a JSON-encoded list of strings, or when JSON decoding fails.
     * MUST never throw — corrupt data in the database MUST resolve to the
     * factory default so admin UI and downstream resolvers stay alive.
     *
     * @return string[] The ordered list of group IDs, or `[]` when missing
     *                  or corrupt.
     */
    public function getGroupOrder(): array
    {
        $raw = $this->settingMapper->getValue(
            key: AdminSetting::KEY_GROUP_ORDER,
            default: null
        );

        if (is_array($raw) === false) {
            // Corrupt or missing — fall back to empty list (REQ-ASET-012).
            return [];
        }

        $result = [];
        foreach ($raw as $entry) {
            // Drop any element that isn't a non-empty string. The persist
            // path validates this, but a hand-edited DB row could still
            // contain garbage.
            if (is_string($entry) === true && $entry !== '') {
                $result[] = $entry;
            }
        }

        return $result;
    }//end getGroupOrder()

    /**
     * Persist the admin-chosen group priority order (REQ-ASET-012,
     * REQ-ASET-014).
     *
     * Replaces the persisted value wholesale — no merge semantics. Every
     * element MUST be a non-empty string; throws
     * {@see \InvalidArgumentException} otherwise so the controller can
     * surface HTTP 400. Duplicates are deduplicated (first occurrence
     * wins, preserving order). Unknown (not-currently-in-Nextcloud) IDs
     * are tolerated by design (REQ-ASET-014) — the runtime resolver in
     * `group-routing` drops them.
     *
     * @param string[] $groupIds The ordered group IDs.
     *
     * @return void
     *
     * @throws InvalidArgumentException When any element is not a
     *                                  non-empty string.
     */
    public function setGroupOrder(array $groupIds): void
    {
        $deduplicated = [];
        foreach ($groupIds as $entry) {
            if (is_string($entry) === false || $entry === '') {
                throw new InvalidArgumentException(
                    message: 'group_order entries must be non-empty strings'
                );
            }

            if (in_array(needle: $entry, haystack: $deduplicated, strict: true) === true) {
                continue;
            }

            $deduplicated[] = $entry;
        }

        $this->settingMapper->setSetting(
            key: AdminSetting::KEY_GROUP_ORDER,
            value: $deduplicated
        );
    }//end setGroupOrder()
}//end class
