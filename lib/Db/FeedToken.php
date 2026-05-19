<?php

/**
 * FeedToken Entity
 *
 * Represents a per-user RSS / Atom feed-token entity — a single row in
 * the `oc_mydash_feed_tokens` table binding a Nextcloud user to one
 * cryptographically-random opaque token. Backs REQ-FEED-001..009 (token
 * issue, regenerate, soft-revoke, public feed render).
 *
 * @category  Database
 * @package   OCA\MyDash\Db
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12 EUPL-1.2
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Feed-token entity (REQ-FEED-001..009).
 *
 * @method string|null getUserId()
 * @method void setUserId(?string $userId)
 * @method string|null getToken()
 * @method void setToken(?string $token)
 * @method string|null getCreatedAt()
 * @method void setCreatedAt(?string $createdAt)
 * @method string|null getLastUsedAt()
 * @method void setLastUsedAt(?string $lastUsedAt)
 * @method string|null getRevokedAt()
 * @method void setRevokedAt(?string $revokedAt)
 */
class FeedToken extends Entity implements JsonSerializable
{

    /**
     * The owning Nextcloud user ID.
     *
     * @var string|null
     */
    protected ?string $userId = null;

    /**
     * The cryptographically-random base64url-encoded opaque token.
     *
     * @var string|null
     */
    protected ?string $token = null;

    /**
     * The token-issue timestamp.
     *
     * @var string|null
     */
    protected ?string $createdAt = null;

    /**
     * The last-used timestamp; touched on every successful public feed
     * fetch (REQ-FEED-004 scenario "Fetch feed with valid token").
     *
     * @var string|null
     */
    protected ?string $lastUsedAt = null;

    /**
     * The soft-revoke timestamp; non-null marks the token as revoked
     * (REQ-FEED-002 / REQ-FEED-003). Public feed lookup MUST treat
     * revoked tokens as "not found" — no leak of revocation status.
     *
     * @var string|null
     */
    protected ?string $revokedAt = null;

    /**
     * Constructor — registers column types.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'integer');
    }//end __construct()

    /**
     * Whether this token has been soft-revoked.
     *
     * @return bool True when `revokedAt` is non-null.
     */
    public function isRevoked(): bool
    {
        return ($this->revokedAt !== null);
    }//end isRevoked()

    /**
     * Whether this token is currently valid (not revoked).
     *
     * @return bool True when `revokedAt` is null.
     */
    public function isValid(): bool
    {
        return ($this->revokedAt === null);
    }//end isValid()

    /**
     * Serialize to JSON.
     *
     * The `token` field is intentionally serialised; it is the value
     * returned to the owning user from the management endpoints. Public
     * feed-render code paths MUST NOT serialise the entity — they only
     * use it to resolve the `userId`.
     *
     * @return array The serialized feed-token.
     */
    public function jsonSerialize(): array
    {
        return [
            'id'         => $this->getId(),
            'userId'     => $this->userId,
            'token'      => $this->token,
            'createdAt'  => $this->createdAt,
            'lastUsedAt' => $this->lastUsedAt,
            'revokedAt'  => $this->revokedAt,
        ];
    }//end jsonSerialize()
}//end class
