<?php

/**
 * DashboardReaction Entity
 *
 * Represents one row in the `oc_mydash_dashboard_reactions` table —
 * a single (dashboardUuid, userId, emoji) tuple recording that a user
 * reacted with an emoji to a dashboard. REQ-RXN-001.
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
 * Dashboard reaction entity.
 *
 * @method string|null getDashboardUuid()
 * @method void setDashboardUuid(?string $dashboardUuid)
 * @method string|null getUserId()
 * @method void setUserId(?string $userId)
 * @method string|null getEmoji()
 * @method void setEmoji(?string $emoji)
 * @method \DateTime|null getReactedAt()
 * @method void setReactedAt(?\DateTime $reactedAt)
 */
class DashboardReaction extends Entity implements JsonSerializable
{

    /**
     * The dashboard UUID this reaction targets.
     *
     * @var string|null
     */
    protected ?string $dashboardUuid = null;

    /**
     * The Nextcloud user ID of the reactor.
     *
     * @var string|null
     */
    protected ?string $userId = null;

    /**
     * The unicode emoji string (e.g. `👍`, `❤️`, `🎉`).
     *
     * @var string|null
     */
    protected ?string $emoji = null;

    /**
     * The instant the reaction was created.
     *
     * @var \DateTime|null
     */
    protected ?DateTime $reactedAt = null;

    /**
     * Constructor — registers column types.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'integer');
        $this->addType(fieldName: 'reactedAt', type: 'datetime');
    }//end __construct()

    /**
     * Format the reaction timestamp the same way other dashboard
     * timestamps are exposed in the JSON envelope. REQ-RXN-001
     * scenario "User adds a reaction to a dashboard they can view".
     *
     * @return string|null `Y-m-d H:i:s` format or null when unset.
     */
    public function getReactedAtFormatted(): ?string
    {
        if ($this->reactedAt === null) {
            return null;
        }

        return $this->reactedAt->format(format: 'Y-m-d H:i:s');
    }//end getReactedAtFormatted()

    /**
     * Serialize to JSON.
     *
     * @return array The serialized reaction.
     */
    public function jsonSerialize(): array
    {
        return [
            'id'            => $this->getId(),
            'dashboardUuid' => $this->dashboardUuid,
            'userId'        => $this->userId,
            'emoji'         => $this->emoji,
            'reactedAt'     => $this->getReactedAtFormatted(),
        ];
    }//end jsonSerialize()
}//end class
