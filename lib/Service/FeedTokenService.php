<?php

/**
 * FeedTokenService
 *
 * Token-management service for the dashboard RSS / Atom feed capability
 * (REQ-FEED-001..009). Handles token generation, lookup, idempotent
 * soft-revocation, and the atomic "revoke + reissue" rotation flow.
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

use DateTime;
use OCA\MyDash\Db\FeedToken;
use OCA\MyDash\Db\FeedTokenMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Service for issuing, rotating, and resolving per-user feed tokens.
 *
 * Token format (REQ-FEED-009): 32 cryptographically-random bytes from
 * `random_bytes()` encoded as URL-safe base64 (no `+`, `/`, `=`) yielding
 * a 43-character opaque string — 256 bits of entropy, non-enumerable,
 * non-time-correlated.
 */
class FeedTokenService
{
    /**
     * Token entropy in bytes (REQ-FEED-009 — 256 bits).
     *
     * @var integer
     */
    public const TOKEN_BYTES = 32;

    /**
     * Constructor.
     *
     * @param FeedTokenMapper $mapper The token-row mapper.
     * @param IDBConnection   $db     The DB connection (for the
     *                                regenerate transaction).
     * @param LoggerInterface $logger Logger for diagnostic events.
     */
    public function __construct(
        private readonly FeedTokenMapper $mapper,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Return the user's active token, creating one on first call
     * (REQ-FEED-001).
     *
     * @param string $userId The owning user ID.
     *
     * @return FeedToken The active feed token.
     */
    /** @spec openspec/specs/dashboard-rss-feeds/spec.md */
    public function getOrCreateToken(string $userId): FeedToken
    {
        $existing = $this->mapper->findByUserId(userId: $userId);
        if ($existing !== null) {
            return $existing;
        }

        // The findByUserId filter is `revoked_at IS NULL`; the user
        // could still have prior soft-revoked rows that the unique
        // constraint (`mydash_feed_tok_user_uq`) would block on the
        // next insert. Drop them before creating a fresh active token.
        $this->mapper->deleteAllForUser(userId: $userId);
        return $this->createToken(userId: $userId);
    }//end getOrCreateToken()

    /**
     * Atomically revoke the user's existing token (if any) and issue a
     * fresh one. Wraps the two-step in a transaction so a partial
     * failure cannot leave the user with two active rows that would
     * violate `UNIQUE(user_id)` on the next request (REQ-FEED-002).
     *
     * @param string $userId The owning user ID.
     *
     * @return FeedToken The newly-issued feed token.
     */
    /** @spec openspec/specs/dashboard-rss-feeds/spec.md */
    public function regenerateToken(string $userId): FeedToken
    {
        $this->db->beginTransaction();
        try {
            // Hard-delete every existing row for this user (active +
            // any previously soft-revoked) so the next insert doesn't
            // collide with the `mydash_feed_tok_user_uq` constraint.
            // Feed tokens are user secrets — losing revoke history is
            // acceptable; running into a 500 on regenerate is not.
            $this->mapper->deleteAllForUser(userId: $userId);
            $fresh = $this->createToken(userId: $userId);
            $this->db->commit();
            return $fresh;
        } catch (Throwable $exception) {
            $this->db->rollBack();
            $this->logger->error(
                message: 'Failed to regenerate feed token',
                context: [
                    'app'       => 'mydash',
                    'userId'    => $userId,
                    'exception' => $exception->getMessage(),
                ]
            );
            throw $exception;
        }//end try
    }//end regenerateToken()

    /**
     * Soft-revoke the user's currently-active token, if one exists.
     * Idempotent — succeeds (no-op) when the user has never opted in
     * (REQ-FEED-003 scenario "Revoke when no token exists").
     *
     * @param string $userId The owning user ID.
     *
     * @return void
     */
    /** @spec openspec/specs/dashboard-rss-feeds/spec.md */
    public function revokeToken(string $userId): void
    {
        $existing = $this->mapper->findByUserId(userId: $userId);
        if ($existing === null) {
            return;
        }

        $this->mapper->softRevoke(token: $existing);
    }//end revokeToken()

    /**
     * Resolve an opaque token to its owning row, touching `lastUsedAt`
     * on the way out. Returns `null` for unknown OR revoked tokens —
     * the caller MUST surface a single HTTP 404 either way so the
     * revocation status does not leak (REQ-FEED-004).
     *
     * @param string $token The opaque token from the URL path.
     *
     * @return FeedToken|null The active token row or null on miss.
     */
    /** @spec openspec/specs/dashboard-rss-feeds/spec.md */
    public function resolveToken(string $token): ?FeedToken
    {
        if ($token === '') {
            return null;
        }

        try {
            $row = $this->mapper->findByToken(token: $token);
        } catch (DoesNotExistException) {
            return null;
        }

        // Defence in depth — the mapper already filters out revoked
        // rows but the entity check here closes the race window
        // between fetch and the controller logging the hit.
        if ($row->isRevoked() === true) {
            return null;
        }

        $this->mapper->updateLastUsed(token: $row);
        return $row;
    }//end resolveToken()

    /**
     * Generate a fresh random token and persist a new row for the
     * given user. Internal — callers MUST go through
     * {@see self::getOrCreateToken()} or {@see self::regenerateToken()}
     * so the `UNIQUE(user_id)` invariant is upheld.
     *
     * @param string $userId The owning user ID.
     *
     * @return FeedToken The freshly-persisted feed token.
     */
    private function createToken(string $userId): FeedToken
    {
        $token = self::generateTokenString();
        $now   = (new DateTime())->format(format: 'Y-m-d H:i:s');

        $entity = new FeedToken();
        $entity->setUserId($userId);
        $entity->setToken($token);
        $entity->setCreatedAt($now);

        return $this->mapper->insert(entity: $entity);
    }//end createToken()

    /**
     * Produce one URL-safe base64-encoded token string.
     *
     * Strips the trailing `=` padding and rewrites `+` → `-` and
     * `/` → `_` so the result is safe to drop verbatim into a path
     * segment (REQ-FEED-009 scenario "Token is URL-safe").
     *
     * @return string The 43-character opaque token.
     */
    /** @spec openspec/specs/dashboard-rss-feeds/spec.md */
    public static function generateTokenString(): string
    {
        $raw     = random_bytes(length: self::TOKEN_BYTES);
        $encoded = rtrim(string: base64_encode(string: $raw), characters: '=');
        return strtr(string: $encoded, from: '+/', to: '-_');
    }//end generateTokenString()
}//end class
