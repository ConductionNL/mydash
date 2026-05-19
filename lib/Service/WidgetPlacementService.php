<?php

/**
 * WidgetPlacementService
 *
 * Server-side validation for widget placement payloads. Currently hosts
 * the REQ-CONT-006 maximum-nesting-depth invariant for the `container`
 * widget type; future placement-level validators (depth limits, schema
 * sanity, etc.) should live here so the dispatch is single-responsibility.
 *
 * @category  Service
 * @package   OCA\MyDash\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Service;

use InvalidArgumentException;

/**
 * Server-side validators for widget placement payloads.
 */
class WidgetPlacementService
{
    /**
     * Maximum container nesting depth (REQ-CONT-006).
     *
     * Depth counts the number of nested CONTAINER levels — not the total
     * number of children. A container holding a container holding a
     * container holding a label has depth 3 (allowed). Adding a fourth
     * nested container makes it depth 4 (rejected).
     *
     * @var int
     */
    public const MAX_CONTAINER_DEPTH = 3;

    /**
     * Recursively walk a widget placement's `content` blob and reject
     * payloads whose nested-container depth exceeds
     * {@see self::MAX_CONTAINER_DEPTH} (REQ-CONT-006).
     *
     * Tolerant of non-container payloads — when `$content` has no
     * `placements[]` array (i.e. the widget is not a container) the
     * method returns immediately without raising. This keeps the
     * controller wiring trivial: every save can call
     * `validateContainerDepth` regardless of widget type.
     *
     * @param array $content The widget placement's `content` blob.
     * @param int   $depth   Current nesting depth (0 at the top level).
     *
     * @return void
     *
     * @throws InvalidArgumentException When the depth limit is exceeded.
     *
     * @spec container-widget:REQ-CONT-006
     */
    public function validateContainerDepth(array $content, int $depth=0): void
    {
        if (isset($content['placements']) === false
            || is_array($content['placements']) === false
        ) {
            return;
        }

        // The `placements[]` key marks this content as a container —
        // the current node IS one container level. If we're already at
        // (or beyond) the cap, having any placements at all violates
        // the invariant: a container at depth MAX cannot contain a
        // container child (which would push the tree to MAX + 1).
        // We only reject when one of those children is itself another
        // container, matching the proposal wording: "depth > 3 AND any
        // placements[] child is also a container".
        $children = $content['placements'];
        foreach ($children as $child) {
            if (is_array($child) === false) {
                continue;
            }

            $childContent = $child['content'] ?? null;
            if (is_array($childContent) === false) {
                continue;
            }

            $isChildContainer = (
                ($child['type'] ?? null) === 'container'
                || (isset($childContent['placements']) === true
                    && is_array($childContent['placements']) === true)
            );

            if ($isChildContainer === true) {
                $childDepth = ($depth + 1);
                if ($childDepth > self::MAX_CONTAINER_DEPTH) {
                    throw new InvalidArgumentException(
                        message: 'container_depth_exceeded'
                    );
                }

                $this->validateContainerDepth(
                    content: $childContent,
                    depth: $childDepth
                );
            }
        }//end foreach
    }//end validateContainerDepth()
}//end class
