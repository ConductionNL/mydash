<?php

/**
 * MenuService Test
 *
 * Covers REQ-MENU-002 — the server-side validator that gates `menu`
 * widget placements on the 3-level nesting cap and the closed enums for
 * `style`, `orientation`, and `activeItemHighlight`.
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
use OCA\MyDash\Service\MenuService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see MenuService}.
 */
class MenuServiceTest extends TestCase
{
    /**
     * Three-level nesting passes silently (top + 2 child levels).
     *
     * @return void
     */
    public function testThreeLevelNestingIsValid(): void
    {
        $svc   = new MenuService();
        $items = [
            [
                'label'    => 'L1',
                'children' => [
                    [
                        'label'    => 'L2',
                        'children' => [
                            ['label' => 'L3'],
                        ],
                    ],
                ],
            ],
        ];

        $svc->validateMenuItems(items: $items);
        $this->assertTrue(true);
    }//end testThreeLevelNestingIsValid()

    /**
     * Four-level nesting rejected with the exact error message.
     *
     * @return void
     */
    public function testFourLevelNestingIsRejected(): void
    {
        $svc   = new MenuService();
        $items = [
            [
                'label'    => 'L1',
                'children' => [
                    [
                        'label'    => 'L2',
                        'children' => [
                            [
                                'label'    => 'L3',
                                'children' => [
                                    ['label' => 'L4'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Menu items can nest at most 3 levels deep');
        $svc->validateMenuItems(items: $items);
    }//end testFourLevelNestingIsRejected()

    /**
     * Empty items array is trivially valid.
     *
     * @return void
     */
    public function testEmptyItemsArrayIsValid(): void
    {
        $svc = new MenuService();
        $svc->validateMenuItems(items: []);
        $this->assertTrue(true);
    }//end testEmptyItemsArrayIsValid()

    /**
     * `style` outside the closed enum rejected.
     *
     * @return void
     */
    public function testInvalidStyleRejected(): void
    {
        $svc = new MenuService();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Menu style must be one of: dropdown, megamenu, tree');
        $svc->validateMenuConfig(content: ['style' => 'carousel']);
    }//end testInvalidStyleRejected()

    /**
     * `orientation` outside the closed enum rejected.
     *
     * @return void
     */
    public function testInvalidOrientationRejected(): void
    {
        $svc = new MenuService();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Menu orientation must be one of: horizontal, vertical');
        $svc->validateMenuConfig(content: ['orientation' => 'diagonal']);
    }//end testInvalidOrientationRejected()

    /**
     * `activeItemHighlight` outside the closed enum rejected.
     *
     * @return void
     */
    public function testInvalidHighlightRejected(): void
    {
        $svc = new MenuService();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Menu activeItemHighlight must be one of: background, underline, left-bar, none'
        );
        $svc->validateMenuConfig(content: ['activeItemHighlight' => 'glow']);
    }//end testInvalidHighlightRejected()

    /**
     * All three enum values valid for the canonical defaults.
     *
     * @return void
     */
    public function testCanonicalDefaultsAreValid(): void
    {
        $svc = new MenuService();
        $svc->validateMenuConfig(
            content: [
                'style'               => 'dropdown',
                'orientation'         => 'horizontal',
                'activeItemHighlight' => 'underline',
            ]
        );
        $this->assertTrue(true);
    }//end testCanonicalDefaultsAreValid()

    /**
     * Non-array item rejected as malformed.
     *
     * @return void
     */
    public function testMalformedItemRejected(): void
    {
        $svc = new MenuService();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Menu item must be an object');
        $svc->validateMenuItems(items: ['not-an-array']);
    }//end testMalformedItemRejected()
}//end class
