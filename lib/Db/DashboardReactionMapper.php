<?php

/**
 * DashboardReactionMapper
 *
 * Database mapper for DashboardReaction entities. Covers the
 * `oc_mydash_dashboard_reactions` table — emoji reactions on
 * dashboards. REQ-RXN-001..009.
 *
 * @category  Database
 * @package   OCA\MyDash\Db
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

namespace OCA\MyDash\Db;

use DateTime;
use Exception;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for DashboardReaction entities.
 *
 * @extends QBMapper<DashboardReaction>
 */
class DashboardReactionMapper extends QBMapper
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
            tableName: 'mydash_dashboard_reactions',
            entityClass: DashboardReaction::class
        );
    }//end __construct()

    /**
     * Find all reactions for a dashboard, newest first.
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return DashboardReaction[] The reactions.
     */
    public function findByDashboard(string $dashboardUuid): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_uuid',
                    y: $qb->createNamedParameter(value: $dashboardUuid)
                )
            )
            ->orderBy(sort: 'reacted_at', order: 'DESC');

        return $this->findEntities(query: $qb);
    }//end findByDashboard()

    /**
     * Find reactions for a single emoji on a dashboard, oldest first
     * (chronological, matching REQ-RXN-004 scenario "User retrieves
     * reactors for a specific emoji").
     *
     * @param string   $dashboardUuid The dashboard UUID.
     * @param string   $emoji         The emoji.
     * @param int|null $limit         Optional row cap (used for the 100-item
     *                                pagination cap).
     * @param int|null $offset        Optional offset (cursor pagination).
     *
     * @return DashboardReaction[] The reactions.
     */
    public function findByEmoji(
        string $dashboardUuid,
        string $emoji,
        ?int $limit=null,
        ?int $offset=null
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_uuid',
                    y: $qb->createNamedParameter(value: $dashboardUuid)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'emoji',
                    y: $qb->createNamedParameter(value: $emoji)
                )
            )
            ->orderBy(sort: 'reacted_at', order: 'ASC')
            ->addOrderBy(sort: 'id', order: 'ASC');

        if ($limit !== null) {
            $qb->setMaxResults(maxResults: $limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult(firstResult: $offset);
        }

        return $this->findEntities(query: $qb);
    }//end findByEmoji()

    /**
     * Find every reaction the calling user has placed on a dashboard.
     *
     * @param string $userId        The user ID.
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return DashboardReaction[] The reactions.
     */
    public function findByUser(string $userId, string $dashboardUuid): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_uuid',
                    y: $qb->createNamedParameter(value: $dashboardUuid)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'user_id',
                    y: $qb->createNamedParameter(value: $userId)
                )
            );

        return $this->findEntities(query: $qb);
    }//end findByUser()

    /**
     * Aggregate reaction counts per emoji for a single dashboard.
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return array<string, int> Map of `emoji => count`.
     */
    public function countByEmoji(string $dashboardUuid): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: 'emoji')
            ->selectAlias(
                select: $qb->func()->count('*'),
                alias: 'cnt'
            )
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_uuid',
                    y: $qb->createNamedParameter(value: $dashboardUuid)
                )
            )
            ->groupBy(groupBys: 'emoji');

        $result = $qb->executeQuery();
        $counts = [];
        while (($row = $result->fetch()) !== false) {
            $counts[(string) $row['emoji']] = (int) $row['cnt'];
        }

        $result->closeCursor();

        return $counts;
    }//end countByEmoji()

    /**
     * Insert a reaction row. Throws on duplicate (caller is expected to
     * either swallow the duplicate exception for idempotent semantics
     * or surface it as a 4xx error).
     *
     * @param string $dashboardUuid The dashboard UUID.
     * @param string $userId        The user ID.
     * @param string $emoji         The emoji.
     *
     * @return DashboardReaction The inserted reaction.
     *
     * @throws DbException When the unique constraint is violated.
     */
    public function addReaction(
        string $dashboardUuid,
        string $userId,
        string $emoji
    ): DashboardReaction {
        $entity = new DashboardReaction();
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        // Entity setters resolve via __call which forwards $args[0]; named
        // parameters MUST NOT be used here.
        $entity->setDashboardUuid($dashboardUuid);
        $entity->setUserId($userId);
        $entity->setEmoji($emoji);
        $entity->setReactedAt(new DateTime());
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        return $this->insert(entity: $entity);
    }//end addReaction()

    /**
     * Delete a single reaction matching `(dashboardUuid, userId, emoji)`.
     *
     * Idempotent — when nothing matches, returns false rather than
     * raising an exception.
     *
     * @param string $dashboardUuid The dashboard UUID.
     * @param string $userId        The user ID.
     * @param string $emoji         The emoji.
     *
     * @return bool True when a row was deleted, false when none matched.
     */
    public function removeReaction(
        string $dashboardUuid,
        string $userId,
        string $emoji
    ): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(delete: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_uuid',
                    y: $qb->createNamedParameter(value: $dashboardUuid)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'user_id',
                    y: $qb->createNamedParameter(value: $userId)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'emoji',
                    y: $qb->createNamedParameter(value: $emoji)
                )
            );

        return $qb->executeStatement() > 0;
    }//end removeReaction()

    /**
     * Cascade-delete every reaction tied to a dashboard. Used by the
     * `ReactionsListener` (REQ-CSC-003) and the `DashboardService`
     * cascade path.
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return int The number of rows deleted.
     */
    public function deleteByDashboardUuid(string $dashboardUuid): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(delete: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_uuid',
                    y: $qb->createNamedParameter(value: $dashboardUuid)
                )
            );

        return $qb->executeStatement();
    }//end deleteByDashboardUuid()

    /**
     * Count the rows for a dashboard that match a single emoji.
     *
     * Cheap helper used by the pagination response so the
     * controller can decide whether to surface a `nextCursor` link.
     *
     * @param string $dashboardUuid The dashboard UUID.
     * @param string $emoji         The emoji.
     *
     * @return int The total number of reactions for that emoji.
     */
    public function countReactorsByEmoji(
        string $dashboardUuid,
        string $emoji
    ): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: $qb->func()->count('*', 'cnt'))
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_uuid',
                    y: $qb->createNamedParameter(value: $dashboardUuid)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'emoji',
                    y: $qb->createNamedParameter(value: $emoji)
                )
            );

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if (is_array($row) === false) {
            return 0;
        }

        return (int) ($row['cnt'] ?? 0);
    }//end countReactorsByEmoji()
}//end class
