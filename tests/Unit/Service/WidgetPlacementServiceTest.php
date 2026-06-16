<?php

/**
 * WidgetPlacementService Test
 *
 * Covers REQ-CONT-006 (maximum container nesting depth = 3): the
 * recursive depth checker MUST accept payloads of depth 0..3 and reject
 * depth-4 payloads with a `container_depth_exceeded` exception. The
 * exception message is the canonical error code the controller wraps
 * into the `{status: 'error', error: 'container_depth_exceeded',
 * maxDepth: 3}` HTTP 400 envelope.
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Service;

use InvalidArgumentException;
use OCA\MyDash\Service\WidgetPlacementService;
use PHPUnit\Framework\TestCase;

class WidgetPlacementServiceTest extends TestCase
{
    /**
     * Build a container `content` blob with `$depth` nested containers.
     *
     * Depth 0 = a label leaf (no `placements[]`).
     * Depth 1 = container { placements: [label] }.
     * Depth 2 = container { placements: [container { placements: [label] }] }.
     *
     * @param int $depth How deep to nest containers.
     *
     * @return array
     */
    private function buildNested(int $depth): array
    {
        if ($depth <= 0) {
            return ['text' => 'leaf'];
        }

        return [
            'placements' => [
                [
                    'type'    => 'container',
                    'content' => $this->buildNested(($depth - 1)),
                ],
            ],
        ];
    }

    public function testValidateContainerDepthAcceptsLeaf(): void
    {
        $service = new WidgetPlacementService();
        $service->validateContainerDepth(content: ['text' => 'plain label, no placements']);
        $this->assertTrue(true, 'leaf content blob must not raise');
    }

    public function testValidateContainerDepthAcceptsDepthOne(): void
    {
        $service = new WidgetPlacementService();
        $service->validateContainerDepth(content: $this->buildNested(depth: 1));
        $this->assertTrue(true, 'depth 1 (one container with non-container child) must pass');
    }

    public function testValidateContainerDepthAcceptsDepthTwo(): void
    {
        $service = new WidgetPlacementService();
        $service->validateContainerDepth(content: $this->buildNested(depth: 2));
        $this->assertTrue(true, 'depth 2 must pass (under cap)');
    }

    public function testValidateContainerDepthAcceptsDepthThree(): void
    {
        $service = new WidgetPlacementService();
        $service->validateContainerDepth(content: $this->buildNested(depth: 3));
        $this->assertTrue(true, 'depth 3 must pass (== MAX_CONTAINER_DEPTH)');
    }

    public function testValidateContainerDepthRejectsDepthFour(): void
    {
        $service = new WidgetPlacementService();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('container_depth_exceeded');
        $service->validateContainerDepth(content: $this->buildNested(depth: 4));
    }

    public function testValidateContainerDepthIgnoresNonContainerChildren(): void
    {
        // A 4-deep tree where the deepest level is a label (no
        // placements) should still pass — only nested CONTAINERS push
        // the depth counter, not arbitrary children.
        $service = new WidgetPlacementService();
        $depth3 = [
            'placements' => [
                [
                    'type'    => 'container',
                    'content' => [
                        'placements' => [
                            [
                                'type'    => 'container',
                                'content' => [
                                    'placements' => [
                                        [
                                            'type'    => 'label',
                                            'content' => ['text' => 'OK at depth 3'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $service->validateContainerDepth(content: $depth3);
        $this->assertTrue(true);
    }

    public function testMaxContainerDepthConstantIsThree(): void
    {
        $this->assertSame(
            expected: 3,
            actual: WidgetPlacementService::MAX_CONTAINER_DEPTH,
            message: 'REQ-CONT-006: maxDepth envelope value MUST be 3'
        );
    }

    public function testValidateContainerDepthHandlesEmptyPlacements(): void
    {
        // A container with an empty placements[] is depth 0 effectively
        // and should pass without raising.
        $service = new WidgetPlacementService();
        $service->validateContainerDepth(content: ['placements' => []]);
        $this->assertTrue(true);
    }
}
