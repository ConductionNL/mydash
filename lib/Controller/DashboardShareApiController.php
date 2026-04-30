<?php

/**
 * DashboardShareApiController
 *
 * Controller for dashboard sharing endpoints. Covers per-row add/remove
 * (REQ-SHARE-001..007), bulk replace (REQ-SHARE-009), and revoke-all-for-
 * recipient (REQ-SHARE-010).
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

use Exception;
use InvalidArgumentException;
use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Service\DashboardShareService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Controller for dashboard sharing API endpoints.
 *
 * All endpoints require a logged-in user (#[NoAdminRequired]). Owner checks
 * are delegated to DashboardShareService.
 */
class DashboardShareApiController extends Controller
{
    /**
     * Constructor
     *
     * @param IRequest              $request      The request.
     * @param DashboardShareService $shareService The share service.
     * @param string|null           $userId       The calling user ID.
     */
    public function __construct(
        IRequest $request,
        private readonly DashboardShareService $shareService,
        private readonly ?string $userId,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * List all shares for a dashboard.
     *
     * @param int $id The dashboard ID.
     *
     * @return DataResponse The list of shares.
     */
    #[NoAdminRequired]
    public function index(int $id): DataResponse
    {
        if ($this->userId === null) {
            return new DataResponse(
                data: ['error' => 'Not logged in'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        try {
            $shares     = $this->shareService->listShares(
                dashboardId: $id,
                userId: $this->userId
            );
            $serialized = array_map(
                callback: static fn($s) => $s->jsonSerialize(),
                array: $shares
            );
            return new DataResponse(data: $serialized);
        } catch (DoesNotExistException) {
            return new DataResponse(
                data: ['error' => 'Dashboard not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (Exception $e) {
            return new DataResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }//end try
    }//end index()

    /**
     * Add or upsert a single share. REQ-SHARE-001.
     *
     * @param int         $id              The dashboard ID.
     * @param string|null $shareType       The share type.
     * @param string|null $shareWith       The recipient.
     * @param string|null $permissionLevel The permission level.
     *
     * @return DataResponse The created/updated share.
     */
    #[NoAdminRequired]
    public function create(
        int $id,
        ?string $shareType=null,
        ?string $shareWith=null,
        ?string $permissionLevel=null
    ): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(
                data: ['error' => 'Not logged in'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        try {
            $share = $this->shareService->addShare(
                dashboardId: $id,
                shareType: (string) $shareType,
                shareWith: (string) $shareWith,
                permissionLevel: (string) $permissionLevel,
                callerId: $this->userId
            );
            return new DataResponse(
                data: $share->jsonSerialize(),
                statusCode: Http::STATUS_CREATED
            );
        } catch (InvalidArgumentException $e) {
            return new DataResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        } catch (DoesNotExistException) {
            return new DataResponse(
                data: ['error' => 'Dashboard not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (Exception $e) {
            return new DataResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }//end try
    }//end create()

    /**
     * Remove a share by ID. REQ-SHARE-001.
     *
     * @param int $shareId The share ID.
     *
     * @return DataResponse Empty 204 on success.
     */
    #[NoAdminRequired]
    public function destroy(int $shareId): DataResponse
    {
        if ($this->userId === null) {
            return new DataResponse(
                data: ['error' => 'Not logged in'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        try {
            $this->shareService->removeShare(
                shareId: $shareId,
                callerId: $this->userId
            );
            return new DataResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
        } catch (DoesNotExistException) {
            return new DataResponse(
                data: ['error' => 'Share not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (Exception $e) {
            return new DataResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }//end try
    }//end destroy()

    /**
     * Atomically replace all shares for a dashboard. REQ-SHARE-009.
     *
     * @param int        $id     The dashboard ID.
     * @param array|null $shares The new share list.
     *
     * @return DataResponse The new full share list.
     */
    #[NoAdminRequired]
    public function replace(int $id, ?array $shares=null): DataResponse
    {
        if ($this->userId === null) {
            return new DataResponse(
                data: ['error' => 'Not logged in'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        if ($shares === null) {
            $shares = [];
        }

        try {
            $newShares  = $this->shareService->replaceShares(
                dashboardId: $id,
                shares: $shares,
                userId: $this->userId
            );
            $serialized = array_map(
                callback: static fn($s) => $s->jsonSerialize(),
                array: $newShares
            );
            return new DataResponse(data: $serialized);
        } catch (InvalidArgumentException $e) {
            return new DataResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        } catch (DoesNotExistException) {
            return new DataResponse(
                data: ['error' => 'Dashboard not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (Exception $e) {
            return new DataResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }//end try
    }//end replace()

    /**
     * Revoke all shares the caller has granted to a specific recipient.
     * REQ-SHARE-010.
     *
     * @param string $shareType The share type.
     * @param string $shareWith The recipient user/group ID.
     *
     * @return DataResponse The count of deleted rows.
     */
    #[NoAdminRequired]
    public function revokeForRecipient(
        string $shareType,
        string $shareWith
    ): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(
                data: ['error' => 'Not logged in'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        try {
            $count = $this->shareService->revokeAllForRecipient(
                shareType: $shareType,
                shareWith: $shareWith,
                callerId: $this->userId
            );
            return new DataResponse(data: ['deleted' => $count]);
        } catch (InvalidArgumentException $e) {
            return new DataResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }
    }//end revokeForRecipient()
}//end class
