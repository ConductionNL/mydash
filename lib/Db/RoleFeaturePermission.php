<?php

/**
 * RoleFeaturePermission Entity
 *
 * Represents a role → widget permission mapping for MyDash. Persists which
 * Nextcloud group is allowed (or denied) which dashboard widgets, plus
 * optional priority weights used by layout seeding.
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
 * Role-feature-permission entity (REQ-RFP-001..010).
 *
 * `allowedWidgets` and `deniedWidgets` are persisted as JSON-encoded text
 * columns; `priorityWeights` is a JSON-encoded `{widgetId: int}` map.
 * Decoded values are exposed via the `*Decoded` accessors so callers do
 * not have to json_decode at every call site.
 *
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string getGroupId()
 * @method void setGroupId(string $groupId)
 * @method string|null getAllowedWidgets()
 * @method void setAllowedWidgets(?string $allowedWidgets)
 * @method string|null getDeniedWidgets()
 * @method void setDeniedWidgets(?string $deniedWidgets)
 * @method string|null getPriorityWeights()
 * @method void setPriorityWeights(?string $priorityWeights)
 * @method string|null getCreatedAt()
 * @method void setCreatedAt(?string $createdAt)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */
class RoleFeaturePermission extends Entity implements JsonSerializable
{
    /**
     * Sentinel group id used as a catch-all fallback when a user's
     * `group_order` priority does not yield a match (REQ-RFP-009).
     *
     * @var string
     */
    public const GROUP_DEFAULT = 'default';

    /**
     * Human-readable name (e.g. "Medewerker widget-rechten").
     *
     * @var string
     */
    protected string $name = '';

    /**
     * Optional purpose / notes.
     *
     * @var string|null
     */
    protected ?string $description = null;

    /**
     * Nextcloud group ID this permission applies to.
     *
     * @var string
     */
    protected string $groupId = '';

    /**
     * JSON-encoded list of widget IDs the group may add. Empty list = no
     * widgets allowed; null after construction = unset.
     *
     * @var string|null
     */
    protected ?string $allowedWidgets = null;

    /**
     * JSON-encoded list of widget IDs explicitly denied. Wins over
     * `allowedWidgets` when merging multiple groups (deny-wins, REQ-RFP-005).
     *
     * @var string|null
     */
    protected ?string $deniedWidgets = null;

    /**
     * JSON-encoded map of `{widgetId: int}` priority weights. Higher value =
     * earlier in seeded layout / dashboard ordering.
     *
     * @var string|null
     */
    protected ?string $priorityWeights = null;

    /**
     * ISO-8601 creation timestamp.
     *
     * @var string|null
     */
    protected ?string $createdAt = null;

    /**
     * ISO-8601 last-modification timestamp.
     *
     * @var string|null
     */
    protected ?string $updatedAt = null;

    /**
     * Constructor — registers ORM column types.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'integer');
    }//end __construct()

    /**
     * Get `allowedWidgets` decoded into a string array.
     *
     * @return array The list of allowed widget IDs (empty array if unset).
     */
    public function getAllowedWidgetsDecoded(): array
    {
        if ($this->allowedWidgets === null || $this->allowedWidgets === '') {
            return [];
        }

        $decoded = json_decode(json: $this->allowedWidgets, associative: true);
        return is_array($decoded) === true ? array_values(array: $decoded) : [];
    }//end getAllowedWidgetsDecoded()

    /**
     * Get `deniedWidgets` decoded into a string array.
     *
     * @return array The list of denied widget IDs (empty array if unset).
     */
    public function getDeniedWidgetsDecoded(): array
    {
        if ($this->deniedWidgets === null || $this->deniedWidgets === '') {
            return [];
        }

        $decoded = json_decode(json: $this->deniedWidgets, associative: true);
        return is_array($decoded) === true ? array_values(array: $decoded) : [];
    }//end getDeniedWidgetsDecoded()

    /**
     * Get `priorityWeights` decoded into a `{widgetId: int}` map.
     *
     * @return array The decoded priority map (empty array if unset).
     */
    public function getPriorityWeightsDecoded(): array
    {
        if ($this->priorityWeights === null || $this->priorityWeights === '') {
            return [];
        }

        $decoded = json_decode(json: $this->priorityWeights, associative: true);
        return is_array($decoded) === true ? $decoded : [];
    }//end getPriorityWeightsDecoded()

    /**
     * Serialize to a JSON-friendly array.
     *
     * @return array The serialized representation.
     */
    public function jsonSerialize(): array
    {
        return [
            'id'               => $this->getId(),
            'name'             => $this->name,
            'description'      => $this->description,
            'groupId'          => $this->groupId,
            'allowedWidgets'   => $this->getAllowedWidgetsDecoded(),
            'deniedWidgets'    => $this->getDeniedWidgetsDecoded(),
            'priorityWeights'  => (object) $this->getPriorityWeightsDecoded(),
            'createdAt'        => $this->createdAt,
            'updatedAt'        => $this->updatedAt,
        ];
    }//end jsonSerialize()
}//end class
