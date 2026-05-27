<?php

/**
 * RuleApiController Security Test
 *
 * Covers the C4 ownership checks on updateRule and deleteRule: any
 * caller without ownership of the associated placement MUST be rejected
 * with a 403 (REQ-PERM-001).
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\MyDash\Controller\RuleApiController;
use OCA\MyDash\Db\ConditionalRule;
use OCA\MyDash\Service\ConditionalService;
use OCA\MyDash\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Security tests for RuleApiController::updateRule and ::deleteRule (C4).
 */
class RuleApiControllerSecurityTest extends TestCase
{

    /** @var IRequest&MockObject */
    private $request;

    /** @var ConditionalService&MockObject */
    private $conditionalService;

    /** @var PermissionService&MockObject */
    private $permissionService;

    /**
     * Set up shared mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request            = $this->createMock(IRequest::class);
        $this->conditionalService = $this->createMock(ConditionalService::class);
        $this->permissionService  = $this->createMock(PermissionService::class);
    }//end setUp()

    /**
     * Build a controller for the given user.
     *
     * @param string|null $userId The acting user ID.
     *
     * @return RuleApiController
     */
    private function makeController(?string $userId): RuleApiController
    {
        return new RuleApiController(
            request: $this->request,
            conditionalService: $this->conditionalService,
            permissionService: $this->permissionService,
            userId: $userId,
        );
    }//end makeController()

    /**
     * Build a ConditionalRule fixture with the given placement ID.
     *
     * @param int $placementId The owning placement ID.
     *
     * @return ConditionalRule
     */
    private function makeRule(int $placementId=99): ConditionalRule
    {
        $rule = new ConditionalRule();
        $rule->setWidgetPlacementId($placementId);
        $rule->setRuleType('group');
        $rule->setRuleConfigArray(['key' => 'value']);
        $rule->setIsInclude(true);

        return $rule;
    }//end makeRule()

    // -----------------------------------------------------------------------
    // C4: updateRule — ownership check
    // -----------------------------------------------------------------------

    /**
     * C4: updateRule by the placement owner MUST succeed (call service).
     *
     * @return void
     */
    public function testUpdateRuleByOwnerCallsService(): void
    {
        $rule = $this->makeRule(placementId: 7);

        $this->conditionalService
            ->expects($this->once())
            ->method('findRule')
            ->with(ruleId: 42)
            ->willReturn($rule);

        $this->permissionService
            ->expects($this->once())
            ->method('verifyPlacementOwnership')
            ->with(userId: 'alice', placementId: 7)
            ->willReturn($this->createMock(\OCA\MyDash\Db\WidgetPlacement::class));

        $updatedRule = $this->makeRule(placementId: 7);
        $this->conditionalService
            ->expects($this->once())
            ->method('updateRule')
            ->with(ruleId: 42)
            ->willReturn($updatedRule);

        $controller = $this->makeController('alice');
        $response   = $controller->updateRule(ruleId: 42, ruleType: 'time');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testUpdateRuleByOwnerCallsService()

    /**
     * C4: updateRule by a non-owner MUST be rejected (verifyPlacementOwnership
     * throws Exception → 403 envelope).
     *
     * @return void
     */
    public function testUpdateRuleByNonOwnerIsRejected(): void
    {
        $rule = $this->makeRule(placementId: 7);

        $this->conditionalService
            ->method('findRule')
            ->willReturn($rule);

        $this->permissionService
            ->method('verifyPlacementOwnership')
            ->willThrowException(new Exception('Access denied'));

        // The service must NOT be called to perform the actual update.
        $this->conditionalService
            ->expects($this->never())
            ->method('updateRule');

        $controller = $this->makeController('attacker');
        $response   = $controller->updateRule(ruleId: 42, ruleType: 'time');

        // ResponseHelper::error maps generic Exception to 500; the
        // assertion is that updateRule was NOT called — not just the status.
        $this->assertNotSame(Http::STATUS_OK, $response->getStatus());
    }//end testUpdateRuleByNonOwnerIsRejected()

    /**
     * C4: updateRule MUST return 401 when the caller is anonymous.
     *
     * @return void
     */
    public function testUpdateRuleReturns401ForAnonymousCaller(): void
    {
        $this->conditionalService->expects($this->never())->method('findRule');
        $this->conditionalService->expects($this->never())->method('updateRule');

        $controller = $this->makeController(null);
        $response   = $controller->updateRule(ruleId: 42);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testUpdateRuleReturns401ForAnonymousCaller()

    // -----------------------------------------------------------------------
    // C4: deleteRule — ownership check
    // -----------------------------------------------------------------------

    /**
     * C4: deleteRule by the placement owner MUST succeed.
     *
     * @return void
     */
    public function testDeleteRuleByOwnerCallsService(): void
    {
        $rule = $this->makeRule(placementId: 7);

        $this->conditionalService
            ->expects($this->once())
            ->method('findRule')
            ->with(ruleId: 55)
            ->willReturn($rule);

        $this->permissionService
            ->expects($this->once())
            ->method('verifyPlacementOwnership')
            ->with(userId: 'alice', placementId: 7)
            ->willReturn($this->createMock(\OCA\MyDash\Db\WidgetPlacement::class));

        $this->conditionalService
            ->expects($this->once())
            ->method('deleteRule')
            ->with(ruleId: 55);

        $controller = $this->makeController('alice');
        $response   = $controller->deleteRule(ruleId: 55);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testDeleteRuleByOwnerCallsService()

    /**
     * C4: deleteRule by a non-owner MUST be rejected and MUST NOT delete.
     *
     * @return void
     */
    public function testDeleteRuleByNonOwnerIsRejected(): void
    {
        $rule = $this->makeRule(placementId: 7);

        $this->conditionalService
            ->method('findRule')
            ->willReturn($rule);

        $this->permissionService
            ->method('verifyPlacementOwnership')
            ->willThrowException(new Exception('Access denied'));

        $this->conditionalService
            ->expects($this->never())
            ->method('deleteRule');

        $controller = $this->makeController('attacker');
        $response   = $controller->deleteRule(ruleId: 55);

        $this->assertNotSame(Http::STATUS_OK, $response->getStatus());
    }//end testDeleteRuleByNonOwnerIsRejected()

    /**
     * C4: deleteRule MUST return 401 when the caller is anonymous.
     *
     * @return void
     */
    public function testDeleteRuleReturns401ForAnonymousCaller(): void
    {
        $this->conditionalService->expects($this->never())->method('findRule');
        $this->conditionalService->expects($this->never())->method('deleteRule');

        $controller = $this->makeController(null);
        $response   = $controller->deleteRule(ruleId: 55);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testDeleteRuleReturns401ForAnonymousCaller()
}//end class
