<?php

/**
 * DashboardCommentsApiController
 *
 * Controller for the dashboard-comments capability (REQ-CMNT-001..009).
 * Owns the four endpoints under `/api/dashboards/{uuid}/comments`:
 *   - GET    list comments + replies
 *   - POST   create top-level or reply comment
 *   - PUT    edit own / admin-edit any comment
 *   - DELETE soft-delete with cascade for top-level
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
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Exception\CommentForbiddenException;
use OCA\MyDash\Exception\CommentNotFoundException;
use OCA\MyDash\Exception\InvalidCommentException;
use OCA\MyDash\Service\CommentService;
use OCA\MyDash\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for `/api/dashboards/{uuid}/comments` (REQ-CMNT-001..009).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The comments controller
 *                                                  legitimately spans the
 *                                                  comment service,
 *                                                  permission service,
 *                                                  dashboard mapper and
 *                                                  three exception types
 *                                                  — splitting would
 *                                                  fragment a four-route
 *                                                  surface.
 */
class DashboardCommentsApiController extends Controller
{
    /**
     * Constructor
     *
     * @param IRequest          $request           The HTTP request.
     * @param CommentService    $commentService    Comment business logic.
     * @param PermissionService $permissionService Dashboard permission resolver.
     * @param DashboardMapper   $dashboardMapper   Used to resolve UUIDs to ids.
     * @param string|null       $userId            The acting user (null when
     *                                             unauthenticated).
     */
    public function __construct(
        IRequest $request,
        private readonly CommentService $commentService,
        private readonly PermissionService $permissionService,
        private readonly DashboardMapper $dashboardMapper,
        private readonly ?string $userId,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * List comments on a dashboard (REQ-CMNT-001).
     *
     * Returns `{enabled: bool, comments: array}`. When comments are
     * effectively disabled the list is always empty.
     *
     * @param string $uuid The dashboard UUID.
     *
     * @return JSONResponse The list envelope or an error.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboard-comments/spec.md */
    public function index(string $uuid): JSONResponse
    {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $dashboard = $this->dashboardMapper->findByUuid(uuid: $uuid);
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: ['error' => 'Dashboard not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        if ($this->permissionService->canViewDashboard(
            userId: $this->userId,
            dashboardId: (int) $dashboard->getId()
        ) === false
        ) {
            return ResponseHelper::forbidden();
        }

        if ($this->commentService->isEnabledFor(dashboard: $dashboard) === false) {
            return ResponseHelper::success(
                data: [
                    'enabled'  => false,
                    'comments' => [],
                ]
            );
        }

        $comments = $this->commentService->getCommentsForDashboard(
            dashboardUuid: $uuid
        );

        return ResponseHelper::success(
            data: [
                'enabled'  => true,
                'comments' => $comments,
            ]
        );
    }//end index()

    /**
     * Create a top-level or reply comment (REQ-CMNT-002, REQ-CMNT-003).
     *
     * Body shape: `{message: string, parentId?: int}`.
     *
     * @param string      $uuid     The dashboard UUID.
     * @param string|null $message  The comment text.
     * @param mixed       $parentId Optional parent comment id; coerced to int.
     *
     * @return JSONResponse The new comment envelope or an error.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboard-comments/spec.md */
    public function create(
        string $uuid,
        ?string $message=null,
        $parentId=null
    ): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $dashboard = $this->dashboardMapper->findByUuid(uuid: $uuid);
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: ['error' => 'Dashboard not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        if ($this->commentService->isEnabledFor(dashboard: $dashboard) === false) {
            return new JSONResponse(
                data: [
                    'error'     => 'Comments are disabled on this dashboard',
                    'errorCode' => 'comment_disabled',
                ],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        // REQ-CMNT-009: writers must have edit-grade access. Viewers may
        // read but never post. We reuse `canEditDashboard` so the role
        // capability (Viewer-blocked / Admin-allowed) layers on top of
        // the permissions capability without duplication.
        if ($this->permissionService->canEditDashboard(
            userId: $this->userId,
            dashboardId: (int) $dashboard->getId()
        ) === false
        ) {
            return ResponseHelper::forbidden();
        }

        $resolvedParent = $this->coerceParentId(value: $parentId);

        try {
            $comment = $this->commentService->createComment(
                dashboardUuid: $uuid,
                userId: (string) $this->userId,
                message: (string) $message,
                parentId: $resolvedParent
            );
        } catch (InvalidCommentException $e) {
            return $this->renderError(exception: $e);
        } catch (CommentNotFoundException $e) {
            return $this->renderError(exception: $e);
        }

        return ResponseHelper::success(
            data: $this->commentService->serialiseComment(comment: $comment),
            statusCode: Http::STATUS_CREATED
        );
    }//end create()

    /**
     * Update the message of an existing comment (REQ-CMNT-004).
     *
     * @param string      $uuid    The dashboard UUID (kept for routing).
     * @param int         $id      The comment ID.
     * @param string|null $message The new message text.
     *
     * @return JSONResponse The updated comment envelope or an error.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboard-comments/spec.md */
    public function update(
        string $uuid,
        int $id,
        ?string $message=null
    ): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $dashboard = $this->dashboardMapper->findByUuid(uuid: $uuid);
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: ['error' => 'Dashboard not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        if ($this->commentService->isEnabledFor(dashboard: $dashboard) === false) {
            return new JSONResponse(
                data: [
                    'error'     => 'Comments are disabled on this dashboard',
                    'errorCode' => 'comment_disabled',
                ],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        if ($this->permissionService->canViewDashboard(
            userId: $this->userId,
            dashboardId: (int) $dashboard->getId()
        ) === false
        ) {
            return ResponseHelper::forbidden();
        }

        try {
            $comment = $this->commentService->updateComment(
                commentId: $id,
                newMessage: (string) $message,
                currentUserId: (string) $this->userId
            );
        } catch (InvalidCommentException $e) {
            return $this->renderError(exception: $e);
        } catch (CommentForbiddenException $e) {
            return $this->renderError(exception: $e);
        } catch (CommentNotFoundException $e) {
            return $this->renderError(exception: $e);
        }

        return ResponseHelper::success(
            data: $this->commentService->serialiseComment(comment: $comment)
        );
    }//end update()

    /**
     * Delete (soft) a comment (REQ-CMNT-005).
     *
     * @param string $uuid The dashboard UUID (kept for routing).
     * @param int    $id   The comment ID.
     *
     * @return JSONResponse Empty success or an error envelope.
     */
    #[NoAdminRequired]
    /** @spec openspec/specs/dashboard-comments/spec.md */
    public function destroy(string $uuid, int $id): JSONResponse
    {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $dashboard = $this->dashboardMapper->findByUuid(uuid: $uuid);
        } catch (DoesNotExistException) {
            return new JSONResponse(
                data: ['error' => 'Dashboard not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        if ($this->permissionService->canViewDashboard(
            userId: $this->userId,
            dashboardId: (int) $dashboard->getId()
        ) === false
        ) {
            return ResponseHelper::forbidden();
        }

        try {
            $this->commentService->deleteComment(
                commentId: $id,
                currentUserId: (string) $this->userId
            );
        } catch (CommentForbiddenException $e) {
            return $this->renderError(exception: $e);
        } catch (CommentNotFoundException $e) {
            return $this->renderError(exception: $e);
        }

        return new JSONResponse(
            data: [],
            statusCode: Http::STATUS_NO_CONTENT
        );
    }//end destroy()

    /**
     * Coerce a wire-format parentId to an int|null.
     *
     * Accepts numeric strings (`"42"`), ints (`42`), and the absence
     * sentinels (`null`, empty string, `0`). Anything else falls back
     * to `null` so the comment is created as a new top-level entry —
     * the comment service still rejects unresolved parents.
     *
     * @param mixed $value Raw value from the request body.
     *
     * @return int|null Integer parent id, or null for a top-level comment.
     */
    private function coerceParentId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        if (is_int($value) === true) {
            return $value;
        }

        if (is_string($value) === true && ctype_digit(text: $value) === true) {
            return (int) $value;
        }

        return null;
    }//end coerceParentId()

    /**
     * Render a comment exception as a JSON error envelope.
     *
     * @param InvalidCommentException|CommentForbiddenException|CommentNotFoundException $exception The exception.
     *
     * @return JSONResponse The error envelope.
     */
    private function renderError(
        InvalidCommentException|CommentForbiddenException|CommentNotFoundException $exception
    ): JSONResponse {
        return new JSONResponse(
            data: [
                'error'     => $exception->getDisplayMessage(),
                'errorCode' => $exception->getErrorCode(),
            ],
            statusCode: $exception->getHttpStatus()
        );
    }//end renderError()
}//end class
