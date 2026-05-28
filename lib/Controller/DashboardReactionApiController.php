<?php

/**
 * DashboardReactionApiController
 *
 * Controller for dashboard emoji reaction endpoints — REQ-RXN-001..009.
 * Owns the four routes:
 *   - GET    /api/dashboards/{uuid}/reactions
 *   - POST   /api/dashboards/{uuid}/reactions          (idempotent)
 *   - DELETE /api/dashboards/{uuid}/reactions/{emoji}  (idempotent)
 *   - GET    /api/dashboards/{uuid}/reactions/{emoji}/users
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

use InvalidArgumentException;
use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Service\PermissionDeniedException;
use OCA\MyDash\Service\ReactionService;
use OCA\MyDash\Service\ReactionsDisabledException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dashboard reaction endpoints.
 *
 * Permission gating is runtime (calls into PermissionService through
 * ReactionService) so every method carries `#[NoAdminRequired]` —
 * non-admins can react if and only if they can VIEW the dashboard.
 */
class DashboardReactionApiController extends Controller
{
    /**
     * Constructor
     *
     * @param IRequest        $request         The request.
     * @param ReactionService $reactionService The reaction service.
     * @param LoggerInterface $logger          PSR logger.
     * @param string|null     $userId          The acting user ID.
     */
    public function __construct(
        IRequest $request,
        private readonly ReactionService $reactionService,
        private readonly LoggerInterface $logger,
        private readonly ?string $userId,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * GET /api/dashboards/{uuid}/reactions — return the
     * `{counts, mine, enabled}` summary. REQ-RXN-003.
     *
     * @param string $uuid The dashboard UUID.
     *
     * @return JSONResponse The summary.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboard-reactions/spec.md */
    public function getReactions(string $uuid): JSONResponse
    {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $summary = $this->reactionService->getReactionsSummary(
                dashboardUuid: $uuid,
                userId: $this->userId
            );
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: ['error' => 'Dashboard not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (PermissionDeniedException $e) {
            return ResponseHelper::forbidden(message: $e->getMessage());
        } catch (Throwable $e) {
            $this->logger->error(
                message: 'getReactions failed: '.$e->getMessage(),
                context: ['exception' => $e]
            );
            return new JSONResponse(
                data: ['error' => 'Operation failed'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try

        return ResponseHelper::success(data: $summary);
    }//end getReactions()

    /**
     * POST /api/dashboards/{uuid}/reactions — add the calling user's
     * reaction. Idempotent (REQ-RXN-001 scenario "User re-posts the
     * same emoji").
     *
     * @param string $uuid  The dashboard UUID.
     * @param string $emoji The emoji to add (request body field).
     *
     * @return JSONResponse The updated summary.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboard-reactions/spec.md */
    public function addReaction(string $uuid, string $emoji=''): JSONResponse
    {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $summary = $this->reactionService->addReaction(
                dashboardUuid: $uuid,
                userId: $this->userId,
                emoji: $emoji
            );
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: ['error' => 'Dashboard not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (PermissionDeniedException $e) {
            return ResponseHelper::forbidden(message: $e->getMessage());
        } catch (ReactionsDisabledException $e) {
            return ResponseHelper::forbidden(message: $e->getMessage());
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        } catch (Throwable $e) {
            $this->logger->error(
                message: 'addReaction failed: '.$e->getMessage(),
                context: ['exception' => $e]
            );
            return new JSONResponse(
                data: ['error' => 'Operation failed'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try

        return ResponseHelper::success(data: $summary);
    }//end addReaction()

    /**
     * DELETE /api/dashboards/{uuid}/reactions/{emoji} — remove the
     * calling user's reaction. Idempotent (REQ-RXN-002).
     *
     * @param string $uuid  The dashboard UUID.
     * @param string $emoji The emoji to remove.
     *
     * @return JSONResponse Empty 204 response.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboard-reactions/spec.md */
    public function removeReaction(string $uuid, string $emoji): JSONResponse
    {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->reactionService->removeReaction(
                dashboardUuid: $uuid,
                userId: $this->userId,
                emoji: $emoji
            );
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: ['error' => 'Dashboard not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (PermissionDeniedException $e) {
            return ResponseHelper::forbidden(message: $e->getMessage());
        } catch (Throwable $e) {
            $this->logger->error(
                message: 'removeReaction failed: '.$e->getMessage(),
                context: ['exception' => $e]
            );
            return new JSONResponse(
                data: ['error' => 'Operation failed'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try

        // 204 No Content — JSONResponse with empty body and explicit
        // status (the framework still emits headers/body shape, but
        // the contract is "204 always" per REQ-RXN-002).
        return new JSONResponse(
            data: [],
            statusCode: Http::STATUS_NO_CONTENT
        );
    }//end removeReaction()

    /**
     * GET /api/dashboards/{uuid}/reactions/{emoji}/users — return the
     * paginated list of reactors. REQ-RXN-004.
     *
     * @param string      $uuid   The dashboard UUID.
     * @param string      $emoji  The emoji.
     * @param string|null $cursor Optional opaque cursor (offset).
     *
     * @return JSONResponse The reactors page.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboard-reactions/spec.md */
    public function getReactorsByEmoji(
        string $uuid,
        string $emoji,
        ?string $cursor=null
    ): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $page = $this->reactionService->getReactorsByEmoji(
                dashboardUuid: $uuid,
                emoji: $emoji,
                userId: $this->userId,
                cursor: $cursor
            );
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: ['error' => 'Dashboard not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (PermissionDeniedException $e) {
            return ResponseHelper::forbidden(message: $e->getMessage());
        } catch (Throwable $e) {
            $this->logger->error(
                message: 'getReactorsByEmoji failed: '.$e->getMessage(),
                context: ['exception' => $e]
            );
            return new JSONResponse(
                data: ['error' => 'Operation failed'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try

        return ResponseHelper::success(data: $page);
    }//end getReactorsByEmoji()
}//end class
