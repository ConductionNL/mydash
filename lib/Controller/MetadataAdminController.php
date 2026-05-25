<?php

/**
 * MetadataAdminController
 *
 * Admin-only HTTP entry points for the dashboard-metadata-fields
 * registry (REQ-MDFL-001..003). All endpoints gate on
 * `IGroupManager::isAdmin` at runtime and return HTTP 403 otherwise
 * (the `#[NoAdminRequired]` attribute is omitted so the framework
 * already requires authentication; the runtime check adds the admin
 * gate on top — same pattern as `AdminSettingsController`).
 *
 * @category  Controller
 * @package   OCA\MyDash\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Controller;

use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Exception\InvalidMetadataFieldException;
use OCA\MyDash\Exception\MetadataFieldHasValuesException;
use OCA\MyDash\Service\MetadataService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Admin field-definition CRUD controller.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Constructor wires
 *  the standard Nextcloud admin trio (group manager + session +
 *  request) plus the capability service.
 */
class MetadataAdminController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request         The HTTP request.
     * @param MetadataService $metadataService The metadata service facade.
     * @param IGroupManager   $groupManager    Group manager (admin gate).
     * @param IUserSession    $userSession     Active session accessor.
     */
    public function __construct(
        IRequest $request,
        private readonly MetadataService $metadataService,
        private readonly IGroupManager $groupManager,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * `GET /api/admin/metadata-fields` — list all field definitions
     * (REQ-MDFL-001).
     *
     * @return JSONResponse The fields array + count, or 403.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboard-metadata-fields/spec.md */
    public function listFields(): JSONResponse
    {
        $forbidden = $this->assertAdmin();
        if ($forbidden !== null) {
            return $forbidden;
        }

        $fields = $this->metadataService->listFields();

        return ResponseHelper::success(
            data: [
                'fields' => ResponseHelper::serializeList(entities: $fields),
                'count'  => count($fields),
            ]
        );
    }//end listFields()

    /**
     * `POST /api/admin/metadata-fields` — create a new field definition
     * (REQ-MDFL-001).
     *
     * @param string                  $key       The slug.
     * @param string                  $label     The display label.
     * @param string                  $type      The field type.
     * @param array<int, string>|null $options   Option set (select types).
     * @param int                     $required  0 / 1.
     * @param int                     $sortOrder UI sort order.
     *
     * @return JSONResponse 201 + field, 400 on validation failure,
     *                      403 for non-admins.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboard-metadata-fields/spec.md */
    public function createField(
        string $key='',
        string $label='',
        string $type='',
        ?array $options=null,
        int $required=0,
        int $sortOrder=0
    ): JSONResponse {
        $forbidden = $this->assertAdmin();
        if ($forbidden !== null) {
            return $forbidden;
        }

        try {
            $field = $this->metadataService->createFieldDefinition(
                key: $key,
                label: $label,
                type: $type,
                options: $options,
                required: $required,
                sortOrder: $sortOrder
            );
        } catch (InvalidMetadataFieldException $exception) {
            return self::badRequest(message: $exception->getMessage());
        }

        return new JSONResponse(
            data: $field->jsonSerialize(),
            statusCode: Http::STATUS_CREATED
        );
    }//end createField()

    /**
     * `GET /api/admin/metadata-fields/{id}` — fetch a single field
     * definition (REQ-MDFL-001).
     *
     * @param int $id The field id.
     *
     * @return JSONResponse 200 + field, 404 when missing, 403 for non-admins.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboard-metadata-fields/spec.md */
    public function getField(int $id): JSONResponse
    {
        $forbidden = $this->assertAdmin();
        if ($forbidden !== null) {
            return $forbidden;
        }

        try {
            $field = $this->metadataService->getField(id: $id);
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: ['error' => 'Field not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        return ResponseHelper::success(data: $field->jsonSerialize());
    }//end getField()

    /**
     * `PUT /api/admin/metadata-fields/{id}` — update label / sortOrder
     * / required / options. Forbids `key` rename (REQ-MDFL-002).
     *
     * @param int                     $id        The field id.
     * @param string|null             $label     The new label.
     * @param int|null                $sortOrder The new sort order.
     * @param int|null                $required  The new required flag.
     * @param array<int, string>|null $options   The new option set.
     * @param string|null             $key       Forbidden — triggers 400.
     *
     * @return JSONResponse 200 + field, 400 on validation failure,
     *                      404 when missing, 403 for non-admins.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboard-metadata-fields/spec.md */
    public function updateField(
        int $id,
        ?string $label=null,
        ?int $sortOrder=null,
        ?int $required=null,
        ?array $options=null,
        ?string $key=null
    ): JSONResponse {
        $forbidden = $this->assertAdmin();
        if ($forbidden !== null) {
            return $forbidden;
        }

        $patch = [];
        if ($key !== null) {
            $patch['key'] = $key;
        }

        if ($label !== null) {
            $patch['label'] = $label;
        }

        if ($sortOrder !== null) {
            $patch['sortOrder'] = $sortOrder;
        }

        if ($required !== null) {
            $patch['required'] = $required;
        }

        // Distinguish "not supplied" from "null to clear". The router
        // delivers `null` when the body omits the key; treat any
        // explicit array (including empty) as "set options" so admins
        // can clear an option set on non-select types.
        if ($options !== null) {
            $patch['options'] = $options;
        }

        try {
            $field = $this->metadataService->updateFieldDefinition(
                id: $id,
                patch: $patch
            );
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: ['error' => 'Field not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (InvalidMetadataFieldException $exception) {
            return self::badRequest(message: $exception->getMessage());
        }

        return ResponseHelper::success(data: $field->jsonSerialize());
    }//end updateField()

    /**
     * `DELETE /api/admin/metadata-fields/{id}?cascade=true` —
     * REQ-MDFL-003.
     *
     * @param int  $id      The field id.
     * @param bool $cascade Whether to cascade-delete dependent values.
     *
     * @return JSONResponse 200 on success, 409 when soft-deletion blocked,
     *                      404 when missing, 403 for non-admins.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboard-metadata-fields/spec.md */
    public function deleteField(int $id, bool $cascade=false): JSONResponse
    {
        $forbidden = $this->assertAdmin();
        if ($forbidden !== null) {
            return $forbidden;
        }

        try {
            $this->metadataService->deleteFieldDefinition(
                id: $id,
                cascade: $cascade
            );
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: ['error' => 'Field not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (MetadataFieldHasValuesException $exception) {
            return new JSONResponse(
                data: [
                    'error'      => MetadataFieldHasValuesException::ERROR_CODE,
                    'message'    => $exception->getMessage(),
                    'valueCount' => $exception->getValueCount(),
                ],
                statusCode: Http::STATUS_CONFLICT
            );
        }

        return ResponseHelper::success(data: ['status' => 'ok']);
    }//end deleteField()

    /**
     * Build a 400-with-error envelope.
     *
     * @param string $message The validation message.
     *
     * @return JSONResponse The 400 response.
     */
    private static function badRequest(string $message): JSONResponse
    {
        return new JSONResponse(
            data: [
                'error'   => InvalidMetadataFieldException::ERROR_CODE,
                'message' => $message,
            ],
            statusCode: Http::STATUS_BAD_REQUEST
        );
    }//end badRequest()

    /**
     * Reject non-admin callers.
     *
     * @return JSONResponse|null `null` when the caller is an admin, or
     *                           a 403 response that the calling action
     *                           should return verbatim.
     */
    private function assertAdmin(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return ResponseHelper::forbidden();
        }

        if ($this->groupManager->isAdmin(userId: $user->getUID()) === false) {
            return ResponseHelper::forbidden();
        }

        return null;
    }//end assertAdmin()
}//end class
