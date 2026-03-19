<?php

/**
 * AdminController
 *
 * Controller for admin dashboard template management.
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
use OCA\MyDash\Service\AdminTemplateService;
use OCA\MyDash\Service\AdminSettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for admin dashboard template management.
 *
 * @SuppressWarnings(PHPMD.StaticAccess)        - ResponseHelper uses static methods by design
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) - boolean params used for admin template flags
 */
class AdminController extends Controller
{
    /**
     * Constructor
     *
     * @param IRequest             $request         The request.
     * @param AdminTemplateService $templateService The admin template service.
     * @param AdminSettingsService $settingsService The admin settings service.
     */
    public function __construct(
        IRequest $request,
        private readonly AdminTemplateService $templateService,
        private readonly AdminSettingsService $settingsService,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * List all admin dashboard templates.
     *
     * @return JSONResponse The list of templates.
     */
    public function listTemplates(): JSONResponse
    {
        $templates = $this->templateService->listTemplates();

        return ResponseHelper::success(
            data: ResponseHelper::serializeList(entities: $templates)
        );
    }//end listTemplates()

    /**
     * Get a specific admin template.
     *
     * @param int $id The template ID.
     *
     * @return JSONResponse The template data.
     */
    public function getTemplate(int $id): JSONResponse
    {
        try {
            $result     = $this->templateService->getTemplateWithPlacements(
                id: $id
            );
            $placements = ResponseHelper::serializeList(
                entities: $result['placements']
            );

            return ResponseHelper::success(
                data: [
                    'template'   => $result['template']->jsonSerialize(),
                    'placements' => $placements,
                ]
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(
                exception: $e,
                statusCode: Http::STATUS_NOT_FOUND
            );
        }//end try
    }//end getTemplate()

    /**
     * Create a new admin template.
     *
     * @param string      $name            The template name.
     * @param string|null $description     The description.
     * @param array|null  $targetGroups    The target groups.
     * @param string      $permissionLevel The permission level.
     * @param bool        $isDefault       Whether default.
     *
     * @return JSONResponse The created template.
     */
    public function createTemplate(
        string $name,
        ?string $description=null,
        ?array $targetGroups=null,
        string $permissionLevel=Dashboard::PERMISSION_ADD_ONLY,
        bool $isDefault=false
    ): JSONResponse {
        try {
            $template = $this->templateService->createTemplate(
                name: $name,
                description: $description,
                targetGroups: $targetGroups,
                permissionLevel: $permissionLevel,
                isDefault: $isDefault
            );

            return ResponseHelper::success(
                data: $template->jsonSerialize(),
                statusCode: Http::STATUS_CREATED
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end createTemplate()

    /**
     * Update an admin template.
     *
     * @param int         $id              The template ID.
     * @param string|null $name            The name.
     * @param string|null $description     The description.
     * @param array|null  $targetGroups    The target groups.
     * @param string|null $permissionLevel The permission level.
     * @param bool|null   $isDefault       Whether default.
     * @param int|null    $gridColumns     The grid columns.
     *
     * @return JSONResponse The updated template.
     */
    public function updateTemplate(
        int $id,
        ?string $name=null,
        ?string $description=null,
        ?array $targetGroups=null,
        ?string $permissionLevel=null,
        ?bool $isDefault=null,
        ?int $gridColumns=null
    ): JSONResponse {
        try {
            $data = $this->buildUpdateData(
                name: $name,
                description: $description,
                targetGroups: $targetGroups,
                permissionLevel: $permissionLevel,
                isDefault: $isDefault,
                gridColumns: $gridColumns
            );

            $template = $this->templateService->updateTemplate(
                id: $id,
                data: $data
            );

            return ResponseHelper::success(
                data: $template->jsonSerialize()
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end updateTemplate()

    /**
     * Delete an admin template.
     *
     * @param int $id The template ID.
     *
     * @return JSONResponse The deletion confirmation.
     */
    public function deleteTemplate(int $id): JSONResponse
    {
        try {
            $this->templateService->deleteTemplate(id: $id);

            return ResponseHelper::success(data: ['status' => 'ok']);
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end deleteTemplate()

    /**
     * Get admin settings.
     *
     * @return JSONResponse The admin settings.
     */
    public function getSettings(): JSONResponse
    {
        return ResponseHelper::success(
            data: $this->settingsService->getSettings()
        );
    }//end getSettings()

    /**
     * Update admin settings.
     *
     * @param string|null $defaultPermLevel Default permission level.
     * @param bool|null   $allowUserDash    Allow user dashboards.
     * @param bool|null   $allowMultiDash   Allow multiple dashboards.
     * @param int|null    $defaultGridCols  Default grid columns.
     *
     * @return JSONResponse The update confirmation.
     */
    public function updateSettings(
        ?string $defaultPermLevel=null,
        ?bool $allowUserDash=null,
        ?bool $allowMultiDash=null,
        ?int $defaultGridCols=null
    ): JSONResponse {
        try {
            $this->settingsService->updateSettings(
                defaultPermLevel: $defaultPermLevel,
                allowUserDash: $allowUserDash,
                allowMultiDash: $allowMultiDash,
                defaultGridCols: $defaultGridCols
            );

            return ResponseHelper::success(data: ['status' => 'ok']);
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end updateSettings()

    /**
     * Build the update data array from nullable parameters.
     *
     * @param string|null $name            The name.
     * @param string|null $description     The description.
     * @param array|null  $targetGroups    The target groups.
     * @param string|null $permissionLevel The permission level.
     * @param bool|null   $isDefault       Whether default.
     * @param int|null    $gridColumns     The grid columns.
     *
     * @return array The non-null update data.
     */
    private function buildUpdateData(
        ?string $name,
        ?string $description,
        ?array $targetGroups,
        ?string $permissionLevel,
        ?bool $isDefault,
        ?int $gridColumns
    ): array {
        $fields = [
            'name'            => $name,
            'description'     => $description,
            'targetGroups'    => $targetGroups,
            'permissionLevel' => $permissionLevel,
            'isDefault'       => $isDefault,
            'gridColumns'     => $gridColumns,
        ];

        return array_filter(
            array: $fields,
            callback: function ($value) {
                return $value !== null;
            }
        );
    }//end buildUpdateData()
}//end class
