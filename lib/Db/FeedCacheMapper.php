<?php

/**
 * FeedCacheMapper
 *
 * Database mapper for FeedCache entities (REQ-FRJ-001..012). Covers the
 * `oc_mydash_feed_cache` table — one row per distinct external feed URL.
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

use DateTimeInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for FeedCache entities.
 *
 * @extends QBMapper<FeedCache>
 */
class FeedCacheMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db The database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(
            db: $db,
            tableName: 'mydash_feed_cache',
            entityClass: FeedCache::class
        );
    }//end __construct()

    /**
     * Find a cache row by its `feed_url`.
     *
     * @param string $feedUrl The feed URL.
     *
     * @return FeedCache The cache row.
     *
     * @throws DoesNotExistException When no row exists for this URL.
     */
    public function findByUrl(string $feedUrl): FeedCache
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'feed_url',
                    y: $qb->createNamedParameter(value: $feedUrl)
                )
            );

        return $this->findEntity(query: $qb);
    }//end findByUrl()

    /**
     * Insert-if-missing for a feed URL — returns the existing row when
     * one is on file (REQ-FRJ-003 "New feed URL upserted before first
     * fetch"). The returned row has all metadata fields null on first
     * insert; the caller sets timestamps / headers / items.
     *
     * @param string $feedUrl The feed URL.
     *
     * @return FeedCache The existing or freshly-inserted row.
     */
    public function upsertUrl(string $feedUrl): FeedCache
    {
        try {
            return $this->findByUrl(feedUrl: $feedUrl);
        } catch (DoesNotExistException) {
            $row = new FeedCache();
            $row->setFeedUrl($feedUrl);
            return $this->insert(entity: $row);
        }
    }//end upsertUrl()

    /**
     * Enumerate all cached feeds (REQ-FRJ-008 batch processing reads
     * the alphabetically-sorted set returned here).
     *
     * @return FeedCache[] All cached feeds, sorted ascending by URL.
     */
    public function findAll(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->orderBy(sort: 'feed_url', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findAll()

    /**
     * Find rows whose `last_fetched_at` is older than `$cutoff` — the
     * orphan-cleanup hand-off surface required by REQ-FRJ-009.
     *
     * @param DateTimeInterface $cutoff The cutoff timestamp (typically
     *                                  `now() - 30 days`).
     *
     * @return FeedCache[] The orphan candidates.
     */
    public function findOrphanedBefore(DateTimeInterface $cutoff): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->lt(
                    x: 'last_fetched_at',
                    y: $qb->createNamedParameter(
                        value: $cutoff->format(format: 'Y-m-d H:i:s')
                    )
                )
            );

        return $this->findEntities(query: $qb);
    }//end findOrphanedBefore()
}//end class
