<?php

/**
 * DashboardLock Entity
 *
 * Represents an editing lock on a single dashboard. One row per
 * dashboard at a time (UNIQUE on `dashboard_uuid`). Expiry is computed
 * at query time as `updatedAt + LOCK_TIMEOUT` where LOCK_TIMEOUT is
 * 15 minutes (REQ-LOCK-005). REQ-LOCK-001..008.
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

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Dashboard editing-lock entity.
 *
 * @method string|null getDashboardUuid()
 * @method void setDashboardUuid(?string $dashboardUuid)
 * @method string|null getUserId()
 * @method void setUserId(?string $userId)
 * @method string|null getDisplayName()
 * @method void setDisplayName(?string $displayName)
 * @method string|null getCreatedAt()
 * @method void setCreatedAt(?string $createdAt)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */
class DashboardLock extends Entity implements JsonSerializable
{

    /**
     * Default lock TTL in seconds (15 minutes).
     *
     * @var int
     */
    public const LOCK_TIMEOUT_SECONDS = 900;

    /**
     * The dashboard UUID this lock guards.
     *
     * @var string|null
     */
    protected ?string $dashboardUuid = null;

    /**
     * The Nextcloud user ID of the lock owner.
     *
     * @var string|null
     */
    protected ?string $userId = null;

    /**
     * Cached display name at acquire time (for UI feedback).
     *
     * @var string|null
     */
    protected ?string $displayName = null;

    /**
     * The creation timestamp (set on first acquire, never updated).
     *
     * @var string|null
     */
    protected ?string $createdAt = null;

    /**
     * The last-heartbeat timestamp; bumped on every heartbeat.
     *
     * @var string|null
     */
    protected ?string $updatedAt = null;

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
     * Whether this lock is past its TTL (`updatedAt + 15 min < now`).
     *
     * Returns `true` for an entity with no `updatedAt` (defensive
     * default — a malformed row should not block a fresh acquire).
     *
     * @return bool True when the lock is stale.
     */
    public function isExpired(): bool
    {
        if ($this->updatedAt === null) {
            return true;
        }

        $heartbeat = strtotime(datetime: $this->updatedAt);
        if ($heartbeat === false) {
            return true;
        }

        return ($heartbeat + self::LOCK_TIMEOUT_SECONDS) < time();
    }//end isExpired()

    /**
     * Number of seconds remaining before this lock expires.
     *
     * Returns 0 (never negative) for an already-expired lock.
     *
     * @return int Seconds remaining, clamped to >= 0.
     */
    public function expiresIn(): int
    {
        if ($this->updatedAt === null) {
            return 0;
        }

        $heartbeat = strtotime(datetime: $this->updatedAt);
        if ($heartbeat === false) {
            return 0;
        }

        $remaining = (($heartbeat + self::LOCK_TIMEOUT_SECONDS) - time());

        return max(0, $remaining);
    }//end expiresIn()

    /**
     * Compute the implied expiry timestamp (`updatedAt + 15 min`).
     *
     * Useful for clients that prefer an absolute moment over a duration.
     * Returns `null` for an entity without `updatedAt`.
     *
     * @return string|null ISO-style timestamp (Y-m-d H:i:s) or null.
     */
    public function impliedExpiresAt(): ?string
    {
        if ($this->updatedAt === null) {
            return null;
        }

        $heartbeat = strtotime(datetime: $this->updatedAt);
        if ($heartbeat === false) {
            return null;
        }

        $expiry = ($heartbeat + self::LOCK_TIMEOUT_SECONDS);
        return (new DateTime())->setTimestamp(timestamp: $expiry)
            ->format(format: 'Y-m-d H:i:s');
    }//end impliedExpiresAt()

    /**
     * Serialize to JSON for API responses (REQ-LOCK-004 shape).
     *
     * @return array The serialized lock.
     */
    public function jsonSerialize(): array
    {
        return [
            'id'             => $this->getId(),
            'dashboardUuid'  => $this->dashboardUuid,
            'userId'         => $this->userId,
            'displayName'    => $this->displayName,
            'acquiredAt'     => $this->createdAt,
            'lastHeartbeat'  => $this->updatedAt,
            'expiresAt'      => $this->impliedExpiresAt(),
            'expiresIn'      => $this->expiresIn(),
            'lockTimeoutSec' => self::LOCK_TIMEOUT_SECONDS,
        ];
    }//end jsonSerialize()
}//end class
