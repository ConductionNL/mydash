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
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Controller;

use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Service\ConditionalService;
use OCA\MyDash\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Controller for conditional rule API endpoints.
 *
 * @SuppressWarnings(PHPMD.StaticAccess)        - ResponseHelper uses static methods by design
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) - boolean params used for rule include/exclude flags
 */
class RuleApiController extends Controller
{
    /**
     * Constructor
     *
     * @param IRequest           $request            The request.
     * @param ConditionalService $conditionalService The conditional service.
     * @param PermissionService  $permissionService  The permission service.
     * @param IL10N              $l10n               The localization service.
     * @param string|null        $userId             The user ID.
     */
    public function __construct(
        IRequest $request,
        private readonly ConditionalService $conditionalService,
        private readonly PermissionService $permissionService,
        private readonly IL10N $l10n,
        private readonly ?string $userId,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request
        );

        ResponseHelper::setL10N($this->l10n);
    }//end __construct()

    /**
     * Get conditional rules for a widget placement.
     *
     * @param int $placementId The placement ID.
     *
     * @return JSONResponse The conditional rules.
     */
    #[NoAdminRequired]
    public function getRules(int $placementId): JSONResponse
    {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        try {
            $this->permissionService->verifyPlacementOwnership(
                userId: $this->userId,
                placementId: $placementId
            );
            $rules = $this->conditionalService->getRules(
                placementId: $placementId
            );

            return ResponseHelper::success(
                data: ResponseHelper::serializeList(entities: $rules)
            );
        } catch (\Exception $e) {
            return ResponseHelper::error(exception: $e);
        }
    }//end getRules()

    /**
     * Add a conditional rule to a widget placement.
     *
     * @param int    $placementId The placement ID.
     * @param string $ruleType    The rule type.
     * @param array  $ruleConfig  The rule configuration.
     * @param bool   $isInclude   Whether this is an include rule.
     *
     * @return JSONResponse The created rule.
     */
    #[NoAdminRequired]
    public function addRule(
        int $placementId,
        string $ruleType,
        array $ruleConfig,
        bool $isInclude=true
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
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
     * @param int         $ruleId     The rule ID.
     * @param string|null $ruleType   The rule type.
     * @param array|null  $ruleConfig The rule configuration.
     * @param bool|null   $isInclude  Whether this is an include rule.
     *
     * @return JSONResponse The updated rule.
     */
    #[NoAdminRequired]
    public function updateRule(
        int $ruleId,
        ?string $ruleType=null,
        ?array $ruleConfig=null,
        ?bool $isInclude=null
    ): JSONResponse {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        try {
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
     * @param int $ruleId The rule ID.
     *
     * @return JSONResponse The deletion confirmation.
     */
    #[NoAdminRequired]
    public function deleteRule(int $ruleId): JSONResponse
    {
        if ($this->userId === null) {
            return ResponseHelper::unauthorized();
        }

        try {
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
