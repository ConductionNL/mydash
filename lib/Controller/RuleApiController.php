<?php

/**
 * RuleApiController
 *
 * Controller for conditional rule API endpoints.
 *
 * @category  Controller
 * @package   OCA\MyDash\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\MyDash\Controller;

use InvalidArgumentException;
use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Service\ActionAuthService;
use OCA\MyDash\Service\ConditionalService;
use OCA\MyDash\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for conditional rule API endpoints.
 */
class RuleApiController extends Controller
{
    /**
     * Constructor
     *
     * @param IRequest           $request            The request.
     * @param ConditionalService $conditionalService The conditional service.
     * @param PermissionService  $permissionService  The permission service.
     * @param ActionAuthService  $actionAuth         ADR-023 action authorization.
     * @param IUserSession       $userSession        User session (IUser resolution).
     * @param string|null        $userId             The user ID.
     */
    public function __construct(
        IRequest $request,
        private readonly ConditionalService $conditionalService,
        private readonly PermissionService $permissionService,
        private readonly ActionAuthService $actionAuth,
        private readonly IUserSession $userSession,
        private readonly ?string $userId,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );
    }//end __construct()

    /**
     * Get conditional rules for a widget placement.
     *
     * @param int $placementId The placement ID.
     *
     * @return JSONResponse The conditional rules.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-mydash/tasks.md#task-9
     */
    #[NoAdminRequired]
    public function getRules(int $placementId): JSONResponse
    {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->actionAuth->requireAction($user, 'rule.get-rules');
        } catch (OCSForbiddenException) {
            return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        try {
            $this->permissionService->verifyPlacementOwnership(
                userId: $this->userId,
                placementId: $placementId
            );
            $rules     = $this->conditionalService->getRules(
                placementId: $placementId
            );
            $isVisible = $this->conditionalService->checkRulesForPlacement(
                placementId: $placementId,
                userId: $this->userId
            );

            return ResponseHelper::success(
                data: [
                    'rules'     => ResponseHelper::serializeList(entities: $rules),
                    'isVisible' => $isVisible,
                ]
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end getRules()

    /**
     * Add a conditional rule to a widget placement.
     *
     * @param int         $placementId The placement ID.
     * @param string|null $ruleType    The rule type.
     * @param array|null  $ruleConfig  The rule configuration.
     * @param bool        $isInclude   Whether this is an include rule.
     *
     * @return JSONResponse The created rule.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-mydash/tasks.md#task-8
     */
    #[NoAdminRequired]
    public function addRule(
        int $placementId,
        ?string $ruleType=null,
        ?array $ruleConfig=null,
        bool $isInclude=true
    ): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->actionAuth->requireAction($user, 'rule.add-rule');
        } catch (OCSForbiddenException) {
            return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        // Validate body shape explicitly so missing fields return a clean
        // 400 instead of a TypeError 500 from the dispatcher. Mirrors the
        // hardening on WidgetApiController::addWidget.
        if ($ruleType === null || $ruleType === '') {
            return ResponseHelper::error(
                exception: new InvalidArgumentException(
                    'Missing required field: ruleType'
                ),
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        if ($ruleConfig === null) {
            return ResponseHelper::error(
                exception: new InvalidArgumentException(
                    'Missing required field: ruleConfig'
                ),
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $this->permissionService->verifyPlacementOwnership(
                userId: $this->userId,
                placementId: $placementId
            );
            $rule = $this->conditionalService->addRule(
                placementId: $placementId,
                ruleType: $ruleType,
                ruleConfig: $ruleConfig,
                isInclude: $isInclude
            );

            return ResponseHelper::success(
                data: $rule->jsonSerialize(),
                statusCode: Http::STATUS_CREATED
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end addRule()

    /**
     * Update a conditional rule.
     *
     * C4 fix (REQ-PERM-001): the rule's owning placement is resolved and
     * `verifyPlacementOwnership` is called before any mutation, mirroring
     * the guard already present on `addRule`. Without this check any
     * authenticated user could overwrite rules on other users' placements
     * by iterating rule IDs.
     *
     * @param int         $ruleId     The rule ID.
     * @param string|null $ruleType   The rule type.
     * @param array|null  $ruleConfig The rule configuration.
     * @param bool|null   $isInclude  Whether this is an include rule.
     *
     * @return JSONResponse The updated rule.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-mydash/tasks.md#task-10
     */
    #[NoAdminRequired]
    public function updateRule(
        int $ruleId,
        ?string $ruleType=null,
        ?array $ruleConfig=null,
        ?bool $isInclude=null
    ): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->actionAuth->requireAction($user, 'rule.update-rule');
        } catch (OCSForbiddenException) {
            return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        try {
            // C4 fix: load the rule first to get its placement, then verify
            // the caller owns that placement before applying any update.
            $rule = $this->conditionalService->findRule(ruleId: $ruleId);
            $this->permissionService->verifyPlacementOwnership(
                userId: $this->userId,
                placementId: $rule->getWidgetPlacementId()
            );

            $data = $this->buildRuleUpdateData(
                ruleType: $ruleType,
                ruleConfig: $ruleConfig,
                isInclude: $isInclude
            );

            $rule = $this->conditionalService->updateRule(
                ruleId: $ruleId,
                data: $data
            );

            return ResponseHelper::success(
                data: $rule->jsonSerialize()
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }//end try
    }//end updateRule()

    /**
     * Delete a conditional rule.
     *
     * C4 fix (REQ-PERM-001): the rule's owning placement is resolved and
     * `verifyPlacementOwnership` is called before the deletion, mirroring
     * the guard already present on `addRule`. Without this check any
     * authenticated user could permanently delete conditional display
     * logic on other users' widget placements by iterating rule IDs.
     *
     * @param int $ruleId The rule ID.
     *
     * @return JSONResponse The deletion confirmation.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-mydash/tasks.md#task-11
     */
    #[NoAdminRequired]
    public function deleteRule(int $ruleId): JSONResponse
    {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->actionAuth->requireAction($user, 'rule.delete-rule');
        } catch (OCSForbiddenException) {
            return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        try {
            // C4 fix: load the rule first to get its placement, then verify
            // the caller owns that placement before deleting.
            $rule = $this->conditionalService->findRule(ruleId: $ruleId);
            $this->permissionService->verifyPlacementOwnership(
                userId: $this->userId,
                placementId: $rule->getWidgetPlacementId()
            );

            $this->conditionalService->deleteRule(ruleId: $ruleId);

            return ResponseHelper::success(data: ['status' => 'ok']);
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }
    }//end deleteRule()

    /**
     * Build rule update data from nullable parameters.
     *
     * @param string|null $ruleType   The rule type.
     * @param array|null  $ruleConfig The rule configuration.
     * @param bool|null   $isInclude  Whether include rule.
     *
     * @return array The non-null update data.
     */
    private function buildRuleUpdateData(
        ?string $ruleType,
        ?array $ruleConfig,
        ?bool $isInclude
    ): array {
        $data = [];
        if ($ruleType !== null) {
            $data['ruleType'] = $ruleType;
        }

        if ($ruleConfig !== null) {
            $data['ruleConfig'] = $ruleConfig;
        }

        if ($isInclude !== null) {
            $data['isInclude'] = $isInclude;
        }

        return $data;
    }//end buildRuleUpdateData()
}//end class
