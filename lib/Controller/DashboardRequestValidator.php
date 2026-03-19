<?php

/**
 * DashboardRequestValidator
 *
 * Validates and resolves dashboard API request parameters and permissions.
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

use OCA\MyDash\Service\DashboardService;
use OCA\MyDash\Service\PermissionService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;

/**
 * Validates dashboard request parameters and permissions.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) - ResponseHelper uses static methods by design
 */
class DashboardRequestValidator
{
    /**
     * Constructor
     *
     * @param DashboardService  $dashboardService  The dashboard service.
     * @param PermissionService $permissionService The permission service.
     * @param IL10N             $l10n              The localization service.
     */
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly PermissionService $permissionService,
        private readonly IL10N $l10n,
    ) {
    }//end __construct()

    /**
     * Check update permissions based on whether placements are included.
     *
     * REQ-PERM-007: Metadata-only updates (name, description) are allowed
     * for all permission levels. Widget/tile/layout changes require
     * add_only or full permission.
     *
     * @param string     $userId      The user ID.
     * @param int        $dashboardId The dashboard ID.
     * @param array|null $placements  The placements (null = metadata-only).
     *
     * @return JSONResponse|null Error response or null if allowed.
     */
    public function checkUpdatePermissions(string $userId, int $dashboardId, ?array $placements): ?JSONResponse
    {
        $allowed = $this->permissionService->canEditDashboard(
            userId: $userId,
            dashboardId: $dashboardId
        );
        if ($placements === null) {
            $allowed = $this->permissionService->canEditDashboardMetadata(
                userId: $userId,
                dashboardId: $dashboardId
            );
        }

        if ($allowed === false) {
            return ResponseHelper::forbidden();
        }

        return null;
    }//end checkUpdatePermissions()

    /**
     * Resolve create parameters from JSON body or individual params.
     *
     * @param mixed       $name        The name parameter.
     * @param string|null $description The description parameter.
     *
     * @return array The resolved name and description.
     */
    public function resolveCreateParams(
        $name,
        ?string $description
    ): array {
        if (is_array($name) === true) {
            return [
                'name'        => $name['name'] ?? 'My Dashboard',
                'description' => $name['description'] ?? null,
            ];
        }

        return [
            'name'        => $name ?? $this->l10n->t('My Dashboard'),
            'description' => $description,
        ];
    }//end resolveCreateParams()

    /**
     * Check creation permissions and return error if denied.
     *
     * @param string $userId The user ID.
     *
     * @return JSONResponse|null Error response or null if allowed.
     */
    public function checkCreatePermissions(string $userId): ?JSONResponse
    {
        if ($this->permissionService->canCreateDashboard(
            userId: $userId
        ) === false
        ) {
            return ResponseHelper::forbidden(
                message: $this->l10n->t('Dashboard creation not allowed')
            );
        }

        $existing = $this->dashboardService->getUserDashboards(
            userId: $userId
        );
        if (empty($existing) === false
            && $this->permissionService->canHaveMultipleDashboards(
                userId: $userId
            ) === false
        ) {
            return ResponseHelper::forbidden(
                message: $this->l10n->t('Multiple dashboards not allowed')
            );
        }

        return null;
    }//end checkCreatePermissions()

    /**
     * Build update data from nullable parameters.
     *
     * @param string|null $name        The name.
     * @param string|null $description The description.
     * @param array|null  $placements  The placements.
     *
     * @return array The non-null update data.
     */
    public function buildUpdateData(
        ?string $name,
        ?string $description,
        ?array $placements
    ): array {
        $fields = [
            'name'        => $name,
            'description' => $description,
            'placements'  => $placements,
        ];

        return array_filter(
            array: $fields,
            callback: function ($value) {
                return $value !== null;
            }
        );
    }//end buildUpdateData()
}//end class
