<?php

/**
 * DashboardTreeService
 *
 * Builds and validates the dashboard hierarchy added by REQ-DASH-023..030.
 * Owns cycle detection, depth enforcement, slug uniqueness, path
 * resolution, breadcrumb computation, and the cascade-delete walker.
 *
 * Per the design doc, the service uses adjacency-list traversal — at the
 * pinned depth cap of 5 (root + 4 descendants) a cycle/depth check is at
 * most 4 ancestor reads and a path resolve at most 5 child reads.
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
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

/**
 * Service that owns the dashboard tree contract.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Single source of truth
 *  for cycle/depth/slug/cascade — splitting risks divergent rules between
 *  the create and update paths.
 */
class DashboardTreeService
{
    /**
     * Error message returned when a parent UUID does not resolve.
     *
     * @var string
     */
    public const ERR_PARENT_NOT_FOUND = 'Parent dashboard not found';

    /**
     * Error message returned when a parent assignment would create a
     * cycle (REQ-DASH-028).
     *
     * @var string
     */
    public const ERR_CYCLE_DETECTED = 'Setting this parent would create a cycle';

    /**
     * Error message returned when an assignment would exceed the depth
     * cap of {@see Dashboard::MAX_DEPTH} (REQ-DASH-028).
     *
     * @var string
     */
    public const ERR_MAX_DEPTH = 'Cannot exceed maximum tree depth of 5 levels';

    /**
     * Error message returned when a slug collides with an existing
     * sibling (REQ-DASH-024).
     *
     * @var string
     */
    public const ERR_SLUG_TAKEN = 'Slug must be unique among siblings';

    /**
     * Error message returned when a delete would cascade into children
     * but the caller did not supply `?cascade=true` (REQ-DASH-030).
     *
     * @var string
     */
    public const ERR_HAS_CHILDREN = 'Dashboard has children. Use ?cascade=true to delete the subtree.';

    /**
     * Constructor.
     *
     * @param DashboardMapper       $dashboardMapper The dashboard mapper.
     * @param WidgetPlacementMapper $placementMapper The placement mapper
     *                                               (used by the cascade
     *                                               walker — REQ-DASH-005
     *                                               cascade is reused).
     * @param IDBConnection         $db              DB connection used by
     *                                               the cascade-delete
     *                                               transaction
     *                                               (REQ-DASH-030).
     */
    public function __construct(
        private readonly DashboardMapper $dashboardMapper,
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly IDBConnection $db,
    ) {
    }//end __construct()

    /**
     * Validate that a proposed parent assignment is acceptable.
     *
     * Combines cycle prevention (REQ-DASH-028 — `$newParentUuid` MUST NOT
     * be the dashboard itself or any of its descendants) and depth
     * enforcement (the resulting depth MUST NOT exceed
     * {@see Dashboard::MAX_DEPTH}). Pass `null` for `$movingUuid` when
     * creating a new dashboard (no descendants exist yet).
     *
     * @param string|null $movingUuid    The dashboard being re-parented,
     *                                   or NULL on create.
     * @param string|null $newParentUuid The proposed parent UUID, or NULL
     *                                   for a root-level placement.
     *
     * @return void
     *
     * @throws InvalidArgumentException When the parent does not exist,
     *                                  the assignment would create a
     *                                  cycle, or the resulting depth
     *                                  exceeds the cap.
     */
    /** @spec openspec/specs/dashboard-switcher/spec.md */
    public function validateParent(
        ?string $movingUuid,
        ?string $newParentUuid
    ): void {
        if ($newParentUuid === null || $newParentUuid === '') {
            // Root-level placement is always legal.
            return;
        }

        if ($movingUuid !== null && $movingUuid === $newParentUuid) {
            // Self-parent is the trivial cycle case.
            throw new InvalidArgumentException(
                message: self::ERR_CYCLE_DETECTED
            );
        }

        try {
            $parent = $this->dashboardMapper->findByUuid(
                uuid: $newParentUuid
            );
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(
                message: self::ERR_PARENT_NOT_FOUND
            );
        }

        $this->assertNotDescendant(
            movingUuid: $movingUuid,
            parent: $parent
        );

        $this->assertDepthWithinCap(
            movingUuid: $movingUuid,
            parent: $parent
        );
    }//end validateParent()

    /**
     * Validate that a slug is unique within its parent scope.
     *
     * REQ-DASH-024 — siblings sharing the same `parent_uuid` MUST have
     * distinct slugs. The check is a single mapper query — the unique
     * index on `(parent_uuid, slug)` is the cross-driver fallback.
     *
     * @param string|null $parentUuid  The parent UUID (NULL for root).
     * @param string      $slug        The proposed slug.
     * @param string|null $excludeUuid UUID to exclude from the check
     *                                 (used on update to allow the row
     *                                 to keep its current slug).
     *
     * @return void
     *
     * @throws InvalidArgumentException When the slug is already in use.
     */
    /** @spec openspec/specs/dashboard-switcher/spec.md */
    public function validateSlugUnique(
        ?string $parentUuid,
        string $slug,
        ?string $excludeUuid=null
    ): void {
        $existing = $this->dashboardMapper->findChildBySlug(
            parentUuid: $parentUuid,
            slug: $slug
        );

        if ($existing === null) {
            return;
        }

        if ($excludeUuid !== null && $existing->getUuid() === $excludeUuid) {
            return;
        }

        throw new InvalidArgumentException(
            message: self::ERR_SLUG_TAKEN
        );
    }//end validateSlugUnique()

    /**
     * Compute the slash-joined slug path from root to the given UUID
     * (REQ-DASH-025).
     *
     * Returns `/marketing/campaigns/q1` for a 3-deep dashboard. Empty
     * segments (rows with NULL slug) are skipped — the caller can detect
     * unaddressable rows by comparing depth in {@see self::computeBreadcrumbs()}
     * against the path segments.
     *
     * @param string $uuid The dashboard UUID.
     *
     * @return string The leading-slash path.
     */
    /** @spec openspec/specs/dashboard-switcher/spec.md */
    public function computePath(string $uuid): string
    {
        $breadcrumbs = $this->computeBreadcrumbs(uuid: $uuid);

        $segments = [];
        foreach ($breadcrumbs as $crumb) {
            $slug = ($crumb['slug'] ?? null);
            if (is_string($slug) === true && $slug !== '') {
                $segments[] = $slug;
            }
        }

        if ($segments === []) {
            return '';
        }

        return '/'.implode('/', $segments);
    }//end computePath()

    /**
     * Compute the breadcrumbs for a dashboard (REQ-DASH-025).
     *
     * Returns an ordered list root → leaf where each entry is
     * `{uuid, name, slug}`. The leaf is the dashboard itself; an empty
     * array means the UUID does not resolve.
     *
     * @param string $uuid The dashboard UUID.
     *
     * @return array<int, array{uuid: ?string, name: ?string, slug: ?string}>
     */
    /** @spec openspec/specs/dashboard-switcher/spec.md */
    public function computeBreadcrumbs(string $uuid): array
    {
        try {
            $leaf = $this->dashboardMapper->findByUuid(uuid: $uuid);
        } catch (DoesNotExistException) {
            return [];
        }

        $crumbs = [];
        foreach ($this->dashboardMapper->findAncestors(uuid: $uuid) as $ancestor) {
            $crumbs[] = [
                'uuid' => $ancestor->getUuid(),
                'name' => $ancestor->getName(),
                'slug' => $ancestor->getSlug(),
            ];
        }

        $crumbs[] = [
            'uuid' => $leaf->getUuid(),
            'name' => $leaf->getName(),
            'slug' => $leaf->getSlug(),
        ];

        return $crumbs;
    }//end computeBreadcrumbs()

    /**
     * Resolve a slug-chain path to a dashboard (REQ-DASH-027).
     *
     * Walks the segments left-to-right starting at root. Trailing slash
     * is ignored; comparison is case-insensitive (the mapper folds the
     * query side, the caller folds the slug side). Returns `null` when
     * any segment fails to resolve.
     *
     * @param string $path The slash-joined slug chain (with or without
     *                     leading/trailing slash).
     *
     * @return Dashboard|null The dashboard at the path, or NULL on miss.
     */
    /** @spec openspec/specs/dashboard-switcher/spec.md */
    public function resolvePath(string $path): ?Dashboard
    {
        $segments = $this->splitPath(path: $path);
        if ($segments === []) {
            return null;
        }

        $cursor     = null;
        $cursorUuid = null;
        foreach ($segments as $segment) {
            $cursor = $this->dashboardMapper->findChildBySlug(
                parentUuid: $cursorUuid,
                slug: $segment
            );

            if ($cursor === null) {
                return null;
            }

            $cursorUuid = $cursor->getUuid();
        }

        return $cursor;
    }//end resolvePath()

    /**
     * Build a nested tree from the given root parent UUID.
     *
     * Used by the `/api/dashboards/tree` endpoint (REQ-DASH-026). Each
     * node is `{uuid, name, slug, sortOrder, children}`. Children are
     * sorted by the mapper (`sort_order` ASC, `name` ASC) so the tree
     * matches REQ-DASH-029 ordering.
     *
     * Pass `null` for `$parentUuid` to build the full visible-roots tree.
     *
     * @param string|null $parentUuid The parent UUID (NULL ⇒ roots).
     * @param int         $depth      Internal recursion depth — capped at
     *                                {@see Dashboard::MAX_DEPTH} as a
     *                                belt-and-braces guard against
     *                                pre-existing malformed cycles.
     *
     * @return array<int, array{uuid: ?string, name: ?string, slug: ?string, sortOrder: int, children: array}>
     */
    /** @spec openspec/specs/dashboard-switcher/spec.md */
    public function buildTree(
        ?string $parentUuid,
        int $depth=0
    ): array {
        if ($depth >= Dashboard::MAX_DEPTH) {
            return [];
        }

        $nodes    = [];
        $children = $this->dashboardMapper->findByParent(
            parentUuid: $parentUuid
        );

        foreach ($children as $child) {
            $childUuid = $child->getUuid();
            $childTree = [];
            if ($childUuid !== null && $childUuid !== '') {
                $childTree = $this->buildTree(
                    parentUuid: $childUuid,
                    depth: ($depth + 1)
                );
            }

            $nodes[] = [
                'uuid'      => $childUuid,
                'name'      => $child->getName(),
                'slug'      => $child->getSlug(),
                'sortOrder' => (int) $child->getSortOrder(),
                'children'  => $childTree,
            ];
        }

        return $nodes;
    }//end buildTree()

    /**
     * Build the full tree of root-level dashboards.
     *
     * @return array<int, array{uuid: ?string, name: ?string, slug: ?string, sortOrder: int, children: array}>
     *
     * @deprecated Use getFilteredTree() for user-facing endpoints to avoid
     *             cross-user enumeration (C1 fix, REQ-PERM-001). Kept for
     *             admin-only internal usage.
     */
    public function getFullTree(): array
    {
        return $this->buildTree(parentUuid: null);
    }//end getFullTree()

    /**
     * Build a nested tree limited to the caller's visible dashboards.
     *
     * C1 fix (REQ-DASH-026 + REQ-PERM-001): every node is included ONLY
     * when its UUID appears in `$visibleUuids`. Nodes the caller cannot
     * see are omitted; their children are also omitted (a hidden parent
     * means the full sub-tree is inaccessible).
     *
     * @param array<string, bool> $visibleUuids Map of UUID ⇒ true for dashboards
     *                                          the caller may see (from
     *                                          DashboardService::getVisibleToUser).
     *
     * @return array<int, array{uuid: ?string, name: ?string, slug: ?string, sortOrder: int, children: array}>
     */
    /** @spec openspec/specs/dashboards/spec.md */
    public function getFilteredTree(array $visibleUuids): array
    {
        return $this->buildFilteredTree(
            parentUuid: null,
            visibleUuids: $visibleUuids
        );
    }//end getFilteredTree()

    /**
     * Internal recursive helper for getFilteredTree().
     *
     * @param string|null         $parentUuid   The parent UUID (null for roots).
     * @param array<string, bool> $visibleUuids Allowed UUID map.
     * @param int                 $depth        Recursion depth guard.
     *
     * @return array<int, array{uuid: ?string, name: ?string, slug: ?string, sortOrder: int, children: array}>
     */
    private function buildFilteredTree(
        ?string $parentUuid,
        array $visibleUuids,
        int $depth=0
    ): array {
        if ($depth >= Dashboard::MAX_DEPTH) {
            return [];
        }

        $nodes    = [];
        $children = $this->dashboardMapper->findByParent(
            parentUuid: $parentUuid
        );

        foreach ($children as $child) {
            $childUuid = $child->getUuid();

            // Skip nodes the caller cannot see.
            if ($childUuid === null
                || $childUuid === ''
                || isset($visibleUuids[$childUuid]) === false
            ) {
                continue;
            }

            $childTree = $this->buildFilteredTree(
                parentUuid: $childUuid,
                visibleUuids: $visibleUuids,
                depth: ($depth + 1)
            );

            $nodes[] = [
                'uuid'      => $childUuid,
                'name'      => $child->getName(),
                'slug'      => $child->getSlug(),
                'sortOrder' => (int) $child->getSortOrder(),
                'children'  => $childTree,
            ];
        }

        return $nodes;
    }//end buildFilteredTree()

    /**
     * Cascade-delete a dashboard subtree (REQ-DASH-030).
     *
     * Deletes the dashboard plus every descendant (breadth-first). All
     * widget placements on every removed dashboard are cascaded via
     * {@see WidgetPlacementMapper::deleteByDashboardId()} per REQ-DASH-005.
     * Wrapped in a single transaction so a mid-walk failure leaves no
     * orphan placements.
     *
     * @param Dashboard $dashboard The root of the subtree to delete.
     *
     * @return int The total number of dashboards removed (root + descendants).
     */
    /** @spec openspec/specs/dashboard-switcher/spec.md */
    public function deleteSubtree(Dashboard $dashboard): int
    {
        $rootUuid = (string) $dashboard->getUuid();
        $deleted  = 0;

        $this->db->beginTransaction();
        try {
            $descendants = [];
            if ($rootUuid !== '') {
                $descendants = $this->dashboardMapper->findDescendants(
                    ancestorUuid: $rootUuid
                );
            }

            // Delete leaves first (descendants reverse order) so that the
            // adjacency-list invariants stay consistent during the walk.
            foreach (array_reverse($descendants) as $child) {
                $childId = $child->getId();
                if ($childId !== null) {
                    $this->placementMapper->deleteByDashboardId(
                        dashboardId: $childId
                    );
                }

                $this->dashboardMapper->delete(entity: $child);
                $deleted++;
            }

            $rootId = $dashboard->getId();
            if ($rootId !== null) {
                $this->placementMapper->deleteByDashboardId(
                    dashboardId: $rootId
                );
            }

            $this->dashboardMapper->delete(entity: $dashboard);
            $deleted++;

            $this->db->commit();
        } catch (\Throwable $t) {
            $this->db->rollBack();
            throw $t;
        }//end try

        return $deleted;
    }//end deleteSubtree()

    /**
     * Reject a parent that is a descendant of the moving dashboard
     * (REQ-DASH-028).
     *
     * Walks the proposed parent's ancestor chain — if `$movingUuid`
     * appears, the assignment would create a cycle. Also rejects the
     * pathological case where the proposed parent is itself a known
     * descendant of the moving dashboard.
     *
     * @param string|null $movingUuid The dashboard being re-parented.
     * @param Dashboard   $parent     The proposed parent entity.
     *
     * @return void
     *
     * @throws InvalidArgumentException When the parent is a descendant.
     */
    private function assertNotDescendant(
        ?string $movingUuid,
        Dashboard $parent
    ): void {
        if ($movingUuid === null || $movingUuid === '') {
            return;
        }

        $parentUuid = $parent->getUuid();
        if ($parentUuid === null || $parentUuid === '') {
            return;
        }

        // Walk parent's ancestor chain — the moving uuid MUST NOT appear.
        $ancestors = $this->dashboardMapper->findAncestors(
            uuid: $parentUuid
        );

        foreach ($ancestors as $ancestor) {
            if ($ancestor->getUuid() === $movingUuid) {
                throw new InvalidArgumentException(
                    message: self::ERR_CYCLE_DETECTED
                );
            }
        }

        // Also walk the moving dashboard's descendants — if the proposed
        // parent appears there, the assignment is a cycle on the other
        // side of the chain.
        $descendants = $this->dashboardMapper->findDescendants(
            ancestorUuid: $movingUuid
        );

        foreach ($descendants as $descendant) {
            if ($descendant->getUuid() === $parentUuid) {
                throw new InvalidArgumentException(
                    message: self::ERR_CYCLE_DETECTED
                );
            }
        }
    }//end assertNotDescendant()

    /**
     * Reject a parent assignment that would push the resulting tree
     * past {@see Dashboard::MAX_DEPTH} levels (REQ-DASH-028).
     *
     * The depth-after-attach is `parentDepth + 1 + movingSubtreeDepth`.
     * `parentDepth` counts ancestors above the proposed parent;
     * `movingSubtreeDepth` counts the deepest descendant under the
     * dashboard being moved (0 when no descendants exist or no UUID
     * supplied).
     *
     * @param string|null $movingUuid The dashboard being re-parented.
     * @param Dashboard   $parent     The proposed parent entity.
     *
     * @return void
     *
     * @throws InvalidArgumentException When the cap would be exceeded.
     */
    private function assertDepthWithinCap(
        ?string $movingUuid,
        Dashboard $parent
    ): void {
        $parentUuid = $parent->getUuid();
        if ($parentUuid === null || $parentUuid === '') {
            return;
        }

        $parentDepth = (count(
                $this->dashboardMapper->findAncestors(
            uuid: $parentUuid
        )
                ) + 1);

        $movingSubtreeDepth = 0;
        if ($movingUuid !== null && $movingUuid !== '') {
            $movingSubtreeDepth = $this->measureSubtreeDepth(
                rootUuid: $movingUuid
            );
        }

        // Layout once the move lands:
        // parentDepth (ancestors of parent + parent itself)
        // + 1 (the moving dashboard slotted as child)
        // + movingSubtreeDepth (deepest descendant below the moving row).
        $finalDepth = ($parentDepth + 1 + $movingSubtreeDepth);

        if ($finalDepth > Dashboard::MAX_DEPTH) {
            throw new InvalidArgumentException(
                message: self::ERR_MAX_DEPTH
            );
        }
    }//end assertDepthWithinCap()

    /**
     * Measure the depth of the subtree rooted at `$rootUuid`.
     *
     * Returns 0 when the root has no children, 1 when it has children
     * but no grandchildren, etc.
     *
     * @param string $rootUuid The subtree root UUID.
     *
     * @return int The maximum descendant depth (0 when no descendants).
     */
    private function measureSubtreeDepth(string $rootUuid): int
    {
        $maxDepth = 0;
        $frontier = [['uuid' => $rootUuid, 'depth' => 0]];

        while (count($frontier) > 0) {
            $next = [];
            foreach ($frontier as $entry) {
                $children = $this->dashboardMapper->findByParent(
                    parentUuid: $entry['uuid']
                );

                foreach ($children as $child) {
                    $childUuid = $child->getUuid();
                    if ($childUuid === null || $childUuid === '') {
                        continue;
                    }

                    $childDepth = ($entry['depth'] + 1);
                    if ($childDepth > $maxDepth) {
                        $maxDepth = $childDepth;
                    }

                    if ($childDepth < Dashboard::MAX_DEPTH) {
                        $next[] = [
                            'uuid'  => $childUuid,
                            'depth' => $childDepth,
                        ];
                    }
                }
            }//end foreach

            $frontier = $next;
        }//end while

        return $maxDepth;
    }//end measureSubtreeDepth()

    /**
     * Split a slug-chain path into its segments.
     *
     * Strips the leading and trailing slashes, lowercases each segment,
     * and discards empty fragments (so `//foo//bar//` becomes
     * `['foo', 'bar']`).
     *
     * @param string $path The slash-joined slug chain.
     *
     * @return array<int, string> The segments (possibly empty).
     */
    private function splitPath(string $path): array
    {
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return [];
        }

        $segments = [];
        foreach (explode('/', $trimmed) as $part) {
            $part = strtolower(trim($part));
            if ($part !== '') {
                $segments[] = $part;
            }
        }

        return $segments;
    }//end splitPath()
}//end class
