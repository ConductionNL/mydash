<?php

/**
 * DashboardVersion Entity
 *
 * Represents a single dashboard version snapshot in
 * `oc_mydash_dashboard_versions`. Database-backend versioning rows are
 * managed by the `dashboard-versioning` capability (REQ-VERS-001..009).
 * Snapshots are the full JSON serialisation of a dashboard's content
 * (widget placements, metadata) at a specific point in time.
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
 * Dashboard version snapshot entity (REQ-VERS-001..009).
 *
 * @method string|null getDashboardUuid()
 * @method void setDashboardUuid(?string $dashboardUuid)
 * @method int|null getVersionNumber()
 * @method void setVersionNumber(?int $versionNumber)
 * @method string|null getSnapshotJson()
 * @method void setSnapshotJson(?string $snapshotJson)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method string|null getCreatedAt()
 * @method void setCreatedAt(?string $createdAt)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 */
class DashboardVersion extends Entity implements JsonSerializable
{

    /**
     * The dashboard UUID this snapshot belongs to.
     *
     * @var string|null
     */
    protected ?string $dashboardUuid = null;

    /**
     * Per-dashboard monotonic version number. Starts at 1 and never
     * resets — pruning the oldest rows does NOT renumber the survivors
     * (REQ-VERS-006 scenario "pruning does not affect versionNumber
     * sequence").
     *
     * @var integer|null
     */
    protected ?int $versionNumber = null;

    /**
     * The full JSON snapshot body — widget placements, dashboard
     * metadata, anything required to restore the dashboard byte-for-byte
     * (REQ-VERS-001 scenario "snapshot captures full state"). Stored
     * as a TEXT-class column (MEDIUMTEXT on MySQL, TEXT on Postgres /
     * SQLite) so up to ~16 MB of JSON is supported.
     *
     * @var string|null
     */
    protected ?string $snapshotJson = null;

    /**
     * The Nextcloud user ID that triggered the snapshot creation. May be
     * the original author of the PUT (automatic snapshot) or an explicit
     * caller (POST /versions / POST /restore).
     *
     * @var string|null
     */
    protected ?string $createdBy = null;

    /**
     * The snapshot creation timestamp (Y-m-d H:i:s).
     *
     * @var string|null
     */
    protected ?string $createdAt = null;

    /**
     * Optional note / label supplied with explicit snapshots
     * (REQ-VERS-002). NULL for automatic snapshots and explicit
     * snapshots whose payload omitted or empty-stringed the field.
     *
     * @var string|null
     */
    protected ?string $note = null;

    /**
     * Constructor — registers integer column types for proper ORM hydration.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'integer');
        $this->addType(fieldName: 'versionNumber', type: 'integer');
    }//end __construct()

    /**
     * Serialize to JSON for API responses.
     *
     * The full snapshot body is intentionally NOT included in list
     * responses (REQ-VERS-003) — callers MUST hit
     * `GET /api/dashboards/{uuid}/versions/{versionNumber}` to fetch the
     * snapshot. The list serialisation includes a `sizeBytes` hint so
     * clients can render the row weight without downloading the body.
     *
     * @return array The serialized version metadata.
     */
    public function jsonSerialize(): array
    {
        $size = 0;
        if ($this->snapshotJson !== null) {
            $size = strlen($this->snapshotJson);
        }

        return [
            'id'            => $this->getId(),
            'dashboardUuid' => $this->dashboardUuid,
            'versionNumber' => $this->versionNumber,
            'createdBy'     => $this->createdBy,
            'createdAt'     => $this->createdAt,
            'note'          => $this->note,
            'sizeBytes'     => $size,
        ];
    }//end jsonSerialize()
}//end class
