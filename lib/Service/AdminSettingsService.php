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
 */

declare(strict_types=1);

namespace OCA\MyDash\Service;

use InvalidArgumentException;
use OCA\MyDash\Db\AdminSetting;
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Db\Dashboard;
use OCP\AppFramework\Db\DoesNotExistException;

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
            'allowUserDashboards'     => $settings[$userKey] ?? true,
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
     * Get the persisted ordered list of "active" Nextcloud group IDs.
     *
     * Reads the global `group_order` admin setting (REQ-ASET-012). The value
     * is persisted as a JSON-encoded `string[]`. This method is intentionally
     * defensive: a missing row, a `NULL` value, an empty string, corrupt JSON,
     * or any non-array decoded value MUST resolve to `[]` without throwing,
     * so the resolver and admin UI never see a fatal error from a malformed
     * value (REQ-ASET-012 "Corrupt DB JSON falls back to empty array"
     * scenario).
     *
     * Non-string elements that slip through the persisted value (defence in
     * depth — `setGroupOrder` already rejects them) are filtered out.
     *
     * @return array<int, string> The admin-chosen group IDs in priority
     *                            order; `[]` when unset or unparseable.
     */
    public function getGroupOrder(): array
    {
        try {
            $entity = $this->settingMapper->findByKey(
                key: AdminSetting::KEY_GROUP_ORDER
            );
        } catch (DoesNotExistException) {
            return [];
        }

        $raw = $entity->getSettingValue();
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode(
            json: $raw,
            associative: true
        );

        if (is_array($decoded) === false) {
            return [];
        }

        $clean = [];
        foreach ($decoded as $value) {
            if (is_string($value) === true && $value !== '') {
                $clean[] = $value;
            }
        }

        return array_values(array_unique($clean));
    }//end getGroupOrder()

    /**
     * Persist the ordered list of "active" Nextcloud group IDs.
     *
     * Implements REQ-ASET-012 / REQ-ASET-014:
     * - Every element MUST be a non-empty string; otherwise an
     *   `InvalidArgumentException` is thrown and nothing is persisted.
     * - Duplicate IDs are deduplicated, first occurrence wins, order is
     *   preserved.
     * - The result is persisted as a JSON-encoded `string[]` under the
     *   `group_order` key (REPLACE-WHOLESALE — no merge with previous).
     * - Unknown (not currently in Nextcloud) IDs are NOT validated here;
     *   they are tolerated per REQ-ASET-014 "Unknown IDs accepted".
     *
     * @param array<int, mixed> $groupIds The new ordered list of group IDs.
     *
     * @return void
     *
     * @throws InvalidArgumentException When any element is not a non-empty
     *                                  string.
     */
    public function setGroupOrder(array $groupIds): void
    {
        $deduped = [];
        $seen    = [];
        foreach ($groupIds as $id) {
            if (is_string($id) === false || $id === '') {
                throw new InvalidArgumentException(
                    message: 'Every group ID must be a non-empty string.'
                );
            }

            if (isset($seen[$id]) === true) {
                continue;
            }

            $seen[$id] = true;
            $deduped[] = $id;
        }

        $this->settingMapper->setSetting(
            key: AdminSetting::KEY_GROUP_ORDER,
            value: $deduped
        );
    }//end setGroupOrder()
}//end class
