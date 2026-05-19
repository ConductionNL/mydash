<?php

/**
 * DashboardMapper
 *
 * Database mapper for dashboard entities.
 *
 * @category  Database
 * @package   OCA\MyDash\Db
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\MyDash\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * DashboardMapper
 *
 * Mapper for dashboard entities.
 *
 * @extends QBMapper<Dashboard>
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Personal + group-shared
 *  + tree + publication-state + template-discovery query paths converge here
 *  per the cross-cutting `dashboards` table design.
 */
class DashboardMapper extends QBMapper
{
    /**
     * Constructor
     *
     * @param IDBConnection $db The database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(
            db: $db,
            tableName: 'mydash_dashboards',
            entityClass: Dashboard::class
        );
    }//end __construct()

    /**
     * Find dashboard by ID.
     *
     * @param int $id The dashboard ID.
     *
     * @return Dashboard The found dashboard.
     *
     * @throws DoesNotExistException If not found.
     */
    public function find(int $id): Dashboard
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'id',
                    y: $qb->createNamedParameter(
                        value: $id,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
            );

        return $this->findEntity(query: $qb);
    }//end find()

    /**
     * Find dashboard by UUID.
     *
     * @param string $uuid The dashboard UUID.
     *
     * @return Dashboard The found dashboard.
     *
     * @throws DoesNotExistException If not found.
     */
    public function findByUuid(string $uuid): Dashboard
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'uuid',
                    y: $qb->createNamedParameter(value: $uuid)
                )
            );

        return $this->findEntity(query: $qb);
    }//end findByUuid()

    /**
     * Find all dashboards for a user.
     *
     * @param string $userId The user ID.
     *
     * @return Dashboard[] The list of dashboards.
     */
    public function findByUserId(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'user_id',
                    y: $qb->createNamedParameter(value: $userId)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'type',
                    y: $qb->createNamedParameter(
                        value: Dashboard::TYPE_USER
                    )
                )
            )
            ->orderBy(sort: 'created_at', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findByUserId()

    /**
     * Find active dashboard for a user.
     *
     * @param string $userId The user ID.
     *
     * @return Dashboard The active dashboard.
     *
     * @throws DoesNotExistException If no active dashboard exists.
     */
    public function findActiveByUserId(string $userId): Dashboard
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'user_id',
                    y: $qb->createNamedParameter(value: $userId)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'type',
                    y: $qb->createNamedParameter(
                        value: Dashboard::TYPE_USER
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'is_active',
                    y: $qb->createNamedParameter(
                        value: 1,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
            );

        return $this->findEntity(query: $qb);
    }//end findActiveByUserId()

    /**
     * Find all admin templates.
     *
     * @return Dashboard[] The list of admin templates.
     */
    public function findAdminTemplates(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'type',
                    y: $qb->createNamedParameter(
                        value: Dashboard::TYPE_ADMIN_TEMPLATE
                    )
                )
            )
            ->orderBy(sort: 'name', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findAdminTemplates()

    /**
     * Find default admin template.
     *
     * @return Dashboard The default template.
     *
     * @throws DoesNotExistException If no default template exists.
     */
    public function findDefaultTemplate(): Dashboard
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'type',
                    y: $qb->createNamedParameter(
                        value: Dashboard::TYPE_ADMIN_TEMPLATE
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'is_default',
                    y: $qb->createNamedParameter(
                        value: 1,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
            );

        return $this->findEntity(query: $qb);
    }//end findDefaultTemplate()

    /**
     * Deactivate all dashboards for a user.
     *
     * @param string $userId The user ID.
     *
     * @return void
     */
    public function deactivateAllForUser(string $userId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update(update: $this->getTableName())
            ->set(
                key: 'is_active',
                value: $qb->createNamedParameter(
                    value: 0,
                    type: IQueryBuilder::PARAM_INT
                )
            )
            ->set(
                key: 'updated_at',
                value: $qb->createNamedParameter(
                    value: (new DateTime())->format(format: 'Y-m-d H:i:s')
                )
            )
            ->where(
                $qb->expr()->eq(
                    x: 'user_id',
                    y: $qb->createNamedParameter(value: $userId)
                )
            );

        $qb->executeStatement();
    }//end deactivateAllForUser()

    /**
     * Set a dashboard as active for a user.
     *
     * @param int    $dashboardId The dashboard ID.
     * @param string $userId      The user ID.
     *
     * @return void
     */
    public function setActive(int $dashboardId, string $userId): void
    {
        // First, deactivate all dashboards for the user.
        $this->deactivateAllForUser(userId: $userId);

        // Then activate the specified dashboard.
        $qb = $this->db->getQueryBuilder();
        $qb->update(update: $this->getTableName())
            ->set(
                key: 'is_active',
                value: $qb->createNamedParameter(
                    value: 1,
                    type: IQueryBuilder::PARAM_INT
                )
            )
            ->set(
                key: 'updated_at',
                value: $qb->createNamedParameter(
                    value: (new DateTime())->format(format: 'Y-m-d H:i:s')
                )
            )
            ->where(
                $qb->expr()->eq(
                    x: 'id',
                    y: $qb->createNamedParameter(
                        value: $dashboardId,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'user_id',
                    y: $qb->createNamedParameter(value: $userId)
                )
            );

        $qb->executeStatement();
    }//end setActive()

    /**
     * Find all group-shared dashboards for a single group.
     *
     * Issues `WHERE type = 'group_shared' AND group_id = ?`. Used by the
     * group-scoped CRUD endpoints (REQ-DASH-014). The `default` group is
     * looked up the same way as any other group ID.
     *
     * @param string $groupId The group ID (real Nextcloud group ID, or
     *                        the literal {@see Dashboard::DEFAULT_GROUP_ID}
     *                        sentinel).
     *
     * @return Dashboard[] The group-shared dashboards in that group.
     */
    public function findByGroup(string $groupId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'type',
                    y: $qb->createNamedParameter(
                        value: Dashboard::TYPE_GROUP_SHARED
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'group_id',
                    y: $qb->createNamedParameter(value: $groupId)
                )
            )
            ->orderBy(sort: 'created_at', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findByGroup()

    /**
     * Find all dashboards visible to a user.
     *
     * Issues three indexed queries — personal `user`-type rows owned by
     * the user, `group_shared` rows whose `group_id` is in the user's
     * group memberships, and `group_shared` rows whose `group_id` equals
     * the {@see Dashboard::DEFAULT_GROUP_ID} sentinel — and unions /
     * dedupes the results by UUID. Each result row carries an extra
     * `_source` key (`'user'`, `'group'`, or `'default'`) so the caller
     * can attach the source field to the response. REQ-DASH-013.
     *
     * Priority order on UUID overlap is: user > group > default. This
     * keeps the `source` field deterministic when a user is in the
     * group _and_ also receives the dashboard via the default sentinel
     * (rare but handled).
     *
     * @param string   $userId       The user ID.
     * @param string[] $userGroupIds The user's Nextcloud group IDs.
     *
     * @return array<int, array{dashboard: Dashboard, source: string}>
     *   List of {dashboard, source} pairs, deduplicated by dashboard UUID.
     */
    public function findVisibleToUser(
        string $userId,
        array $userGroupIds
    ): array {
        $personal = $this->findByUserId(userId: $userId);

        $groupRows = [];
        if (empty($userGroupIds) === false) {
            $groupRows = $this->findGroupSharedInGroups(
                groupIds: $userGroupIds
            );
        }

        $defaultRows = $this->findByGroup(
            groupId: Dashboard::DEFAULT_GROUP_ID
        );

        // Dedup by UUID with priority user > group > default.
        $seen   = [];
        $result = [];

        foreach ($personal as $dashboard) {
            $uuid = (string) $dashboard->getUuid();
            if (isset($seen[$uuid]) === true) {
                continue;
            }

            $seen[$uuid] = true;
            $result[]    = [
                'dashboard' => $dashboard,
                'source'    => Dashboard::SOURCE_USER,
            ];
        }

        foreach ($groupRows as $dashboard) {
            $uuid = (string) $dashboard->getUuid();
            if (isset($seen[$uuid]) === true) {
                continue;
            }

            // The default-group sentinel rows are filtered out of the
            // group-bucket so they always appear under SOURCE_DEFAULT.
            if ($dashboard->getGroupId() === Dashboard::DEFAULT_GROUP_ID) {
                continue;
            }

            $seen[$uuid] = true;
            $result[]    = [
                'dashboard' => $dashboard,
                'source'    => Dashboard::SOURCE_GROUP,
            ];
        }

        foreach ($defaultRows as $dashboard) {
            $uuid = (string) $dashboard->getUuid();
            if (isset($seen[$uuid]) === true) {
                continue;
            }

            $seen[$uuid] = true;
            $result[]    = [
                'dashboard' => $dashboard,
                'source'    => Dashboard::SOURCE_DEFAULT,
            ];
        }

        return $result;
    }//end findVisibleToUser()

    /**
     * Find group-shared dashboards whose group_id is in the supplied list.
     *
     * Helper for {@see DashboardMapper::findVisibleToUser()}. Hits the
     * `(type, group_id)` composite index.
     *
     * @param string[] $groupIds The group IDs to match.
     *
     * @return Dashboard[] The matching group-shared dashboards.
     */
    private function findGroupSharedInGroups(array $groupIds): array
    {
        if (empty($groupIds) === true) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'type',
                    y: $qb->createNamedParameter(
                        value: Dashboard::TYPE_GROUP_SHARED
                    )
                )
            )
            ->andWhere(
                $qb->expr()->in(
                    x: 'group_id',
                    y: $qb->createNamedParameter(
                        value: $groupIds,
                        type: IQueryBuilder::PARAM_STR_ARRAY
                    )
                )
            )
            ->orderBy(sort: 'created_at', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findGroupSharedInGroups()

    /**
     * Count group-shared dashboards in a single group.
     *
     * Used by the last-in-group delete guard (REQ-DASH-014).
     *
     * @param string $groupId The group ID.
     *
     * @return int The number of group-shared dashboards in that group.
     */
    public function countByGroup(string $groupId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'type',
                    y: $qb->createNamedParameter(
                        value: Dashboard::TYPE_GROUP_SHARED
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'group_id',
                    y: $qb->createNamedParameter(value: $groupId)
                )
            );

        $cursor = $qb->executeQuery();
        $row    = $cursor->fetch();
        $cursor->closeCursor();

        if ($row === false || isset($row['cnt']) === false) {
            return 0;
        }

        return (int) $row['cnt'];
    }//end countByGroup()

    /**
     * Clear default flag on every group-shared dashboard in a group.
     *
     * Issues `UPDATE oc_mydash_dashboards SET is_default = 0 WHERE
     * type = 'group_shared' AND group_id = ? [AND uuid <> ?]`. Used by
     * {@see \OCA\MyDash\Service\DashboardService::setGroupDefault()} as
     * the first half of the transactional flip. REQ-DASH-015.
     *
     * @param string      $groupId    The group ID (real or
     *                                {@see Dashboard::DEFAULT_GROUP_ID}).
     * @param string|null $exceptUuid Optional uuid to leave untouched
     *                                (avoids a no-op write on the row that
     *                                will immediately be set to 1).
     *
     * @return int The number of rows affected.
     */
    public function clearGroupDefaults(
        string $groupId,
        ?string $exceptUuid=null
    ): int {
        $qb = $this->db->getQueryBuilder();
        $qb->update(update: $this->getTableName())
            ->set(
                key: 'is_default',
                value: $qb->createNamedParameter(
                    value: 0,
                    type: IQueryBuilder::PARAM_INT
                )
            )
            ->set(
                key: 'updated_at',
                value: $qb->createNamedParameter(
                    value: (new DateTime())->format(format: 'Y-m-d H:i:s')
                )
            )
            ->where(
                $qb->expr()->eq(
                    x: 'type',
                    y: $qb->createNamedParameter(
                        value: Dashboard::TYPE_GROUP_SHARED
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'group_id',
                    y: $qb->createNamedParameter(value: $groupId)
                )
            );

        if ($exceptUuid !== null) {
            $qb->andWhere(
                $qb->expr()->neq(
                    x: 'uuid',
                    y: $qb->createNamedParameter(value: $exceptUuid)
                )
            );
        }

        return $qb->executeStatement();
    }//end clearGroupDefaults()

    /**
     * Set the default flag on a single group-shared dashboard.
     *
     * Issues `UPDATE oc_mydash_dashboards SET is_default = 1 WHERE
     * type = 'group_shared' AND group_id = ? AND uuid = ?`. Returns the
     * row-count affected — `0` when the uuid does not belong to the
     * given group (caller treats as 404). REQ-DASH-015.
     *
     * @param string $groupId The group ID from the URL.
     * @param string $uuid    The dashboard UUID from the URL.
     *
     * @return int The number of rows affected (0 or 1).
     */
    public function setGroupDefaultUuid(
        string $groupId,
        string $uuid
    ): int {
        $qb = $this->db->getQueryBuilder();
        $qb->update(update: $this->getTableName())
            ->set(
                key: 'is_default',
                value: $qb->createNamedParameter(
                    value: 1,
                    type: IQueryBuilder::PARAM_INT
                )
            )
            ->set(
                key: 'updated_at',
                value: $qb->createNamedParameter(
                    value: (new DateTime())->format(format: 'Y-m-d H:i:s')
                )
            )
            ->where(
                $qb->expr()->eq(
                    x: 'type',
                    y: $qb->createNamedParameter(
                        value: Dashboard::TYPE_GROUP_SHARED
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'group_id',
                    y: $qb->createNamedParameter(value: $groupId)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'uuid',
                    y: $qb->createNamedParameter(value: $uuid)
                )
            );

        return $qb->executeStatement();
    }//end setGroupDefaultUuid()

    /**
     * Find direct children of a parent dashboard (REQ-DASH-026, REQ-DASH-029).
     *
     * Returns rows ordered by `sort_order` ASC, then `name` ASC for tie
     * breaking. Pass `null` for `$parentUuid` to fetch root dashboards
     * (`parent_uuid IS NULL`). The query is user-agnostic — caller is
     * responsible for filtering visible-to-user results in the service
     * layer.
     *
     * @param string|null $parentUuid The parent UUID (null ⇒ root).
     *
     * @return Dashboard[] The direct children, ordered for tree display.
     */
    public function findByParent(?string $parentUuid): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName());

        if ($parentUuid === null) {
            $qb->where($qb->expr()->isNull(x: 'parent_uuid'));
        } else {
            $qb->where(
                $qb->expr()->eq(
                    x: 'parent_uuid',
                    y: $qb->createNamedParameter(value: $parentUuid)
                )
            );
        }

        $qb->orderBy(sort: 'sort_order', order: 'ASC')
            ->addOrderBy(sort: 'name', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findByParent()

    /**
     * Find a single child by `(parent_uuid, slug)` (REQ-DASH-024,
     * REQ-DASH-027).
     *
     * Used by the path resolver to walk the tree segment by segment.
     * Returns `null` when no row matches — the resolver translates that
     * into a 404 to the caller. Slug comparison is case-insensitive
     * (slugs are stored lowercase by `SlugGenerator::slugify()` but
     * `LOWER(slug)` keeps stale rows uppercased pre-migration matching
     * predictably).
     *
     * @param string|null $parentUuid The parent UUID, or null for root.
     * @param string      $slug       The slug to look up (case folded).
     *
     * @return Dashboard|null The matching child, or null when not found.
     */
    public function findChildBySlug(
        ?string $parentUuid,
        string $slug
    ): ?Dashboard {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName());

        if ($parentUuid === null) {
            $qb->where($qb->expr()->isNull(x: 'parent_uuid'));
        } else {
            $qb->where(
                $qb->expr()->eq(
                    x: 'parent_uuid',
                    y: $qb->createNamedParameter(value: $parentUuid)
                )
            );
        }

        $qb->andWhere(
            $qb->expr()->eq(
                x: $qb->func()->lower('slug'),
                y: $qb->createNamedParameter(value: strtolower($slug))
            )
        )->setMaxResults(maxResults: 1);

        $cursor = $qb->executeQuery();
        $row    = $cursor->fetch();
        $cursor->closeCursor();

        if ($row === false) {
            return null;
        }

        return Dashboard::fromRow($row);
    }//end findChildBySlug()

    /**
     * Count direct children of a parent dashboard.
     *
     * Used by the cascade-delete guard (REQ-DASH-030) to populate the
     * 409 response body without materialising every child entity.
     *
     * @param string $parentUuid The parent UUID.
     *
     * @return int The number of direct children.
     */
    public function countChildrenByParent(string $parentUuid): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'parent_uuid',
                    y: $qb->createNamedParameter(value: $parentUuid)
                )
            );

        $cursor = $qb->executeQuery();
        $row    = $cursor->fetch();
        $cursor->closeCursor();

        if ($row === false || isset($row['cnt']) === false) {
            return 0;
        }

        return (int) $row['cnt'];
    }//end countChildrenByParent()

    /**
     * Walk the ancestor chain upward from a child dashboard (REQ-DASH-025).
     *
     * Returns the ancestor entities in **root-first** order (so the
     * caller can append the child itself to build a breadcrumb list
     * without re-sorting). The walk hard-stops at
     * {@see Dashboard::MAX_DEPTH} hops to defend against pre-existing
     * malformed cycles surviving from before the cycle guard shipped.
     *
     * @param string $uuid The child dashboard UUID.
     *
     * @return Dashboard[] The ancestor entities ordered root → parent
     *                     (the child itself is NOT included).
     */
    public function findAncestors(string $uuid): array
    {
        $ancestors = [];
        $cursor    = $uuid;

        for ($i = 0; $i < Dashboard::MAX_DEPTH; $i++) {
            try {
                $current = $this->findByUuid(uuid: $cursor);
            } catch (DoesNotExistException) {
                break;
            }

            $parent = $current->getParentUuid();
            if ($parent === null || $parent === '') {
                break;
            }

            try {
                $parentEntity = $this->findByUuid(uuid: $parent);
            } catch (DoesNotExistException) {
                break;
            }

            // Prepend so the final list is root-first.
            array_unshift($ancestors, $parentEntity);
            $cursor = $parent;
        }//end for

        return $ancestors;
    }//end findAncestors()

    /**
     * Walk every descendant of an ancestor dashboard.
     *
     * Implements iterative breadth-first traversal capped at
     * {@see Dashboard::MAX_DEPTH} hops below the ancestor. Used by the
     * cascade-delete path (REQ-DASH-030) and by the cycle-detection
     * guard (REQ-DASH-028) when the proposed parent is the dashboard
     * itself or a known descendant.
     *
     * @param string $ancestorUuid The ancestor dashboard UUID.
     *
     * @return Dashboard[] Every descendant dashboard, breadth-first.
     */
    public function findDescendants(string $ancestorUuid): array
    {
        $descendants = [];
        $frontier    = [$ancestorUuid];

        // Cap iterations at MAX_DEPTH as a belt-and-braces guard against
        // pre-existing malformed cycles surviving from before the cycle
        // guard shipped — the for limit is the explicit defence.
        for ($depth = 0; $depth < Dashboard::MAX_DEPTH; $depth++) {
            if (count($frontier) === 0) {
                break;
            }

            $next = [];
            foreach ($frontier as $parentUuid) {
                $children = $this->findByParent(parentUuid: $parentUuid);
                foreach ($children as $child) {
                    $descendants[] = $child;
                    $childUuid     = $child->getUuid();
                    if ($childUuid !== null && $childUuid !== '') {
                        $next[] = $childUuid;
                    }
                }
            }

            $frontier = $next;
        }

        return $descendants;
    }//end findDescendants()

    /**
     * Find every scheduled dashboard whose `publishAt` is past-due.
     *
     * Used by the optional eager-materialisation path in
     * {@see \OCA\MyDash\Service\DashboardService::materialiseScheduledDashboards()}.
     * Lazy materialisation in the visibility filter remains the
     * correctness contract — this mapper exists only for cleaner audit
     * data on the underlying table. REQ-DASH-034.
     *
     * @return Dashboard[] The due-scheduled dashboards.
     */
    public function findDueScheduled(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'publication_status',
                    y: $qb->createNamedParameter(
                        value: Dashboard::STATUS_SCHEDULED
                    )
                )
            )
            ->andWhere(
                $qb->expr()->lte(
                    x: 'publish_at',
                    y: $qb->createNamedParameter(
                        value: (new DateTime())->format(format: 'Y-m-d H:i:s')
                    )
                )
            );

        return $this->findEntities(query: $qb);
    }//end findDueScheduled()

    /**
     * Find all admin templates for the discovery gallery (REQ-TMPL-014).
     *
     * Lists rows with `type = 'admin_template'`, optionally filtered to
     * a single `template_category`. Sort order:
     *   - `sortBy = 'name'` (default): `template_category` ascending with
     *     NULL values last, then `name` ascending — matches the gallery's
     *     "group by category, alphabetical inside each group" UX.
     *   - `sortBy = 'updatedAt'`: `updated_at` descending (most recent
     *     first) for the "Recently updated" tab.
     *
     * No widget placements are fetched — the gallery is a list view, not
     * a render. Callers wanting a count of placements per template should
     * issue a single follow-up query (or use a lightweight COUNT query)
     * rather than N+1 over `findByDashboardId`.
     *
     * @param string|null $category Optional category exact-match filter
     *                              (NULL → no filter).
     * @param string      $sortBy   Sort key: `'name'` (default) or
     *                              `'updatedAt'`.
     *
     * @return Dashboard[] The matching admin templates.
     */
    public function findAllTemplatesForGallery(
        ?string $category=null,
        string $sortBy='name'
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'type',
                    y: $qb->createNamedParameter(
                        value: Dashboard::TYPE_ADMIN_TEMPLATE
                    )
                )
            );

        if ($category !== null && $category !== '') {
            $qb->andWhere(
                $qb->expr()->eq(
                    x: 'template_category',
                    y: $qb->createNamedParameter(value: $category)
                )
            );
        }

        if ($sortBy === 'updatedAt') {
            $qb->orderBy(sort: 'updated_at', order: 'DESC');
        } else {
            // Default: category ASC (NULL last), then name ASC.
            //
            // Nextcloud's `IQueryBuilder::orderBy()` escapes its `sort`
            // argument as a column identifier, so passing a literal
            // CASE WHEN expression there produces invalid SQL on
            // PostgreSQL ("column does not exist"). Wrap the
            // synthetic sort key via `createFunction()` instead — that
            // emits the expression verbatim, preserving NULL-last
            // semantics across pgsql / mysql / sqlite without needing
            // driver-specific NULLS LAST clauses.
            $qb->orderBy(
                sort: $qb->createFunction(
                    'CASE WHEN template_category IS NULL THEN 1 ELSE 0 END'
                ),
                order: 'ASC'
            )
                ->addOrderBy(sort: 'template_category', order: 'ASC')
                ->addOrderBy(sort: 'name', order: 'ASC');
        }//end if

        return $this->findEntities(query: $qb);
    }//end findAllTemplatesForGallery()

    /**
     * Find a personal dashboard by `(userId, uuid)` for the
     * save-as-template ownership check (REQ-TMPL-015).
     *
     * Restricts to `type = 'user'` so admins cannot save group-shared or
     * admin-template rows as templates via this code path. Returns
     * `null` when no row matches — caller treats as forbidden / not
     * found per the REQ-TMPL-015 contract.
     *
     * @param string $userId The owning user ID.
     * @param string $uuid   The dashboard UUID.
     *
     * @return Dashboard|null The owned dashboard, or `null` when no
     *                        match.
     */
    public function findOwnedByUserAndUuid(
        string $userId,
        string $uuid
    ): ?Dashboard {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'user_id',
                    y: $qb->createNamedParameter(value: $userId)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'uuid',
                    y: $qb->createNamedParameter(value: $uuid)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'type',
                    y: $qb->createNamedParameter(
                        value: Dashboard::TYPE_USER
                    )
                )
            )
            ->setMaxResults(maxResults: 1);

        $cursor = $qb->executeQuery();
        $row    = $cursor->fetch();
        $cursor->closeCursor();

        if ($row === false) {
            return null;
        }

        return Dashboard::fromRow($row);
    }//end findOwnedByUserAndUuid()

    /**
     * Clear default flag on all admin templates.
     *
     * @return void
     */
    public function clearDefaultTemplates(): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update(update: $this->getTableName())
            ->set(
                key: 'is_default',
                value: $qb->createNamedParameter(
                    value: 0,
                    type: IQueryBuilder::PARAM_INT
                )
            )
            ->set(
                key: 'updated_at',
                value: $qb->createNamedParameter(
                    value: (new DateTime())->format(format: 'Y-m-d H:i:s')
                )
            )
            ->where(
                $qb->expr()->eq(
                    x: 'type',
                    y: $qb->createNamedParameter(
                        value: Dashboard::TYPE_ADMIN_TEMPLATE
                    )
                )
            );

        $qb->executeStatement();
    }//end clearDefaultTemplates()
}//end class
