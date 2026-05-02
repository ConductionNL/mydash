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
     * Default link-button-widget createFile extension allow-list
     * (REQ-LBN-004). Mirrored on
     * {@see \OCA\MyDash\Service\FileService::DEFAULT_ALLOWED_EXTENSIONS}
     * so the admin UI can render the default before
     * {@see FileService::getAllowedExtensions()} is consulted.
     *
     * @var array<int, string>
     */
    public const DEFAULT_LINK_CREATE_FILE_EXTENSIONS = [
        'txt',
        'md',
        'docx',
        'xlsx',
        'csv',
        'odt',
    ];

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
        $extKey   = AdminSetting::KEY_LINK_CREATE_FILE_EXTENSIONS;

        $storedExt = ($settings[$extKey] ?? null);
        if (is_array($storedExt) === false || count($storedExt) === 0) {
            $storedExt = self::DEFAULT_LINK_CREATE_FILE_EXTENSIONS;
        }

        return [
            'defaultPermissionLevel'   => $settings[$permKey] ?? $permDef,
            'allowUserDashboards'      => $settings[$userKey] ?? true,
            'allowMultipleDashboards'  => $settings[$multiKey] ?? true,
            'defaultGridColumns'       => $settings[$gridKey] ?? 12,
            'linkCreateFileExtensions' => $storedExt,
        ];
    }//end getSettings()

    /**
     * Update admin settings.
     *
     * @param string|null $defaultPermLevel   Default permission level.
     * @param bool|null   $allowUserDash      Allow user dashboards.
     * @param bool|null   $allowMultiDash     Allow multiple dashboards.
     * @param int|null    $defaultGridCols    Default grid columns.
     * @param array|null  $linkCreateFileExts link-button-widget
     *                                        createFile
     *                                        extension
     *                                        allow-list
     *                                        (REQ-LBN-004).
     *
     * @return void
     */
    public function updateSettings(
        ?string $defaultPermLevel=null,
        ?bool $allowUserDash=null,
        ?bool $allowMultiDash=null,
        ?int $defaultGridCols=null,
        ?array $linkCreateFileExts=null
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

        if ($linkCreateFileExts !== null) {
            $this->settingMapper->setSetting(
                key: AdminSetting::KEY_LINK_CREATE_FILE_EXTENSIONS,
                value: $this->normaliseExtensions(input: $linkCreateFileExts)
            );
        }
    }//end updateSettings()

    /**
     * Normalise an admin-supplied extension allow-list.
     *
     * Lowercases each entry, strips leading dots, drops anything that
     * is not a bare alphanumeric token, de-duplicates, and falls back
     * to the default allow-list when the input collapses to empty —
     * the admin cannot brick the feature by saving an empty list.
     *
     * @param array $input Raw admin input.
     *
     * @return array<int, string> The normalised allow-list.
     */
    private function normaliseExtensions(array $input): array
    {
        $normalised = [];
        foreach ($input as $value) {
            if (is_string($value) === false) {
                continue;
            }

            $token = strtolower(string: ltrim(string: trim(string: $value), characters: '.'));
            if ($token === '') {
                continue;
            }

            if (preg_match(pattern: '/^[a-z0-9]+$/', subject: $token) !== 1) {
                continue;
            }

            $normalised[$token] = $token;
        }

        if (count($normalised) === 0) {
            return self::DEFAULT_LINK_CREATE_FILE_EXTENSIONS;
        }

        return array_values(array: $normalised);
    }//end normaliseExtensions()
}//end class
