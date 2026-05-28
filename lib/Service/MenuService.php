<?php

/**
 * MenuService
 *
 * Server-side validator for the `menu` widget content blob. Enforces the
 * 3-level nesting cap and the closed enums for `style`, `orientation`, and
 * `activeItemHighlight` documented in REQ-MENU-001 / REQ-MENU-002.
 *
 * @category  Service
 * @package   OCA\MyDash\Service
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

namespace OCA\MyDash\Service;

use InvalidArgumentException;

/**
 * Validates `menu` widget content before placement save.
 *
 * REQ-MENU-002 mandates a hard cap of 3 levels of nesting (top-level + 2
 * child levels) and closed-enum validation of `style`, `orientation`, and
 * `activeItemHighlight`. The matching renderer never recurses past 3 levels,
 * so violating placements MUST be rejected at save time rather than letting
 * unrenderable rows persist.
 */
class MenuService
{
    /**
     * Maximum item nesting depth permitted (top + 2 child levels).
     *
     * @var integer
     */
    public const MAX_DEPTH = 3;

    /**
     * Allowed values for the `style` field.
     *
     * @var string[]
     */
    public const ALLOWED_STYLES = ['dropdown', 'megamenu', 'tree'];

    /**
     * Allowed values for the `orientation` field.
     *
     * @var string[]
     */
    public const ALLOWED_ORIENTATIONS = ['horizontal', 'vertical'];

    /**
     * Allowed values for the `activeItemHighlight` field.
     *
     * @var string[]
     */
    public const ALLOWED_HIGHLIGHTS = ['background', 'underline', 'left-bar', 'none'];

    /**
     * Validate the `items` array recursively.
     *
     * REQ-MENU-002: rejects any tree where an item's `children` list pushes
     * the depth beyond 3 levels. The error message is fixed so the API
     * response (HTTP 400) and form-side error remain in lock-step.
     *
     * @param array $items Top-level menu items.
     *
     * @return void
     * @throws InvalidArgumentException When depth exceeds 3 or an item is malformed.
     */
    /** @spec openspec/specs/menu-widget/spec.md */
    public function validateMenuItems(array $items): void
    {
        $this->validateLevel(items: $items, depth: 1);
    }//end validateMenuItems()

    /**
     * Validate the closed-enum fields on the menu content blob.
     *
     * Each call short-circuits on the first illegal value so the caller
     * sees the most specific error possible. Defaults supplied by the
     * renderer are accepted unconditionally — only explicit overrides are
     * checked.
     *
     * @param array $content Full widget content (`style`, `orientation`,
     *                       `activeItemHighlight`, ...).
     *
     * @return void
     * @throws InvalidArgumentException When a field value is outside its allowed set.
     */
    /** @spec openspec/specs/menu-widget/spec.md */
    public function validateMenuConfig(array $content): void
    {
        if (isset($content['style']) === true
            && in_array(needle: $content['style'], haystack: self::ALLOWED_STYLES, strict: true) === false
        ) {
            throw new InvalidArgumentException(
                message: 'Menu style must be one of: dropdown, megamenu, tree'
            );
        }

        if (isset($content['orientation']) === true
            && in_array(
                needle: $content['orientation'],
                haystack: self::ALLOWED_ORIENTATIONS,
                strict: true
            ) === false
        ) {
            throw new InvalidArgumentException(
                message: 'Menu orientation must be one of: horizontal, vertical'
            );
        }

        if (isset($content['activeItemHighlight']) === true
            && in_array(
                needle: $content['activeItemHighlight'],
                haystack: self::ALLOWED_HIGHLIGHTS,
                strict: true
            ) === false
        ) {
            throw new InvalidArgumentException(
                message: 'Menu activeItemHighlight must be one of: background, underline, left-bar, none'
            );
        }
    }//end validateMenuConfig()

    /**
     * Validate one level of the items tree.
     *
     * @param array   $items Items at the current depth.
     * @param integer $depth Current depth (1-indexed).
     *
     * @return void
     * @throws InvalidArgumentException When the depth cap is exceeded.
     */
    private function validateLevel(array $items, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException(
                message: 'Menu items can nest at most 3 levels deep'
            );
        }

        foreach ($items as $item) {
            if (is_array($item) === false) {
                throw new InvalidArgumentException(
                    message: 'Menu item must be an object'
                );
            }

            if (isset($item['children']) === true && is_array($item['children']) === true
                && count($item['children']) > 0
            ) {
                $this->validateLevel(items: $item['children'], depth: ($depth + 1));
            }
        }
    }//end validateLevel()
}//end class
