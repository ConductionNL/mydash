<?php

/**
 * FeedTokenMapper
 *
 * Database mapper for FeedToken entities (REQ-FEED-001..009). Covers the
 * `oc_mydash_feed_tokens` table.
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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for FeedToken entities.
 *
 * @extends QBMapper<FeedToken>
 */
class FeedTokenMapper extends QBMapper
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
            tableName: 'mydash_feed_tokens',
            entityClass: FeedToken::class
        );
    }//end __construct()

    /**
     * Find an active (non-revoked) token by its opaque value.
     *
     * Used by the public `/feed/{token}.xml` endpoint. Revoked tokens
     * MUST be reported as `DoesNotExistException` so the controller can
     * map both "no record" and "revoked" to a single HTTP 404
     * (REQ-FEED-004 — no revocation-status leak).
     *
     * @param string $token The opaque token value.
     *
     * @return FeedToken The active token.
     *
     * @throws DoesNotExistException When the token is unknown or revoked.
     */
    public function findByToken(string $token): FeedToken
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'token',
                    y: $qb->createNamedParameter(value: $token)
                )
            )
            ->andWhere($qb->expr()->isNull(x: 'revoked_at'));

        return $this->findEntity(query: $qb);
    }//end findByToken()

    /**
     * Find the active (non-revoked) token row for a given user.
     *
     * Returns `null` (no exception) when the user has not yet opted in
     * (REQ-FEED-008) or has only revoked records on file. Used by both
     * the GET /api/feed/token "issue or return existing" path and the
     * regenerate path (which must explicitly revoke the row first).
     *
     * @param string $userId The owning user ID.
     *
     * @return FeedToken|null The active token, or null when none exists.
     */
    public function findByUserId(string $userId): ?FeedToken
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
            ->andWhere($qb->expr()->isNull(x: 'revoked_at'));

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }//end findByUserId()

    /**
     * Update the `last_used_at` timestamp on a successful public feed
     * fetch (REQ-FEED-004). Persists immediately.
     *
     * @param FeedToken $token The token to touch.
     *
     * @return void
     */
    public function updateLastUsed(FeedToken $token): void
    {
        $now = (new DateTime())->format(format: 'Y-m-d H:i:s');
        $token->setLastUsedAt($now);
        $this->update(entity: $token);
    }//end updateLastUsed()

    /**
     * Soft-revoke a token by stamping `revoked_at`. Idempotent — calling
     * twice is a no-op past the first stamp. (REQ-FEED-003.)
     *
     * @param FeedToken $token The token to revoke.
     *
     * @return void
     */
    public function softRevoke(FeedToken $token): void
    {
        if ($token->isRevoked() === true) {
            return;
        }

        $now = (new DateTime())->format(format: 'Y-m-d H:i:s');
        $token->setRevokedAt($now);
        $this->update(entity: $token);
    }//end softRevoke()

    /**
     * Hard-delete every feed-token row for `$userId`, regardless of
     * revoked state. Used by `regenerateToken` because the table's
     * `mydash_feed_tok_user_uq` unique constraint sits on `user_id`
     * alone — the soft-revoke pattern (one active + N revoked rows
     * per user) trips it on the next insert. Feed tokens are
     * regenerable user secrets so we drop revoked history rather
     * than maintain a parallel audit table.
     *
     * @param string $userId The owning user ID.
     *
     * @return void
     */
    public function deleteAllForUser(string $userId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(delete: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'user_id',
                    y: $qb->createNamedParameter(value: $userId)
                )
            );
        $qb->executeStatement();
    }//end deleteAllForUser()
}//end class
