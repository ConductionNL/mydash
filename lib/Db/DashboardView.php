<?php

/**
 * DashboardView Entity
 *
 * Represents one daily aggregate row in `mydash_dashboard_views` —
 * the persistent half of the dashboard view-analytics capability
 * (REQ-ANLT-001..003). One entity per `(dashboardUuid, viewBucket)`
 * pair.
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

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Dashboard view-analytics aggregate entity (REQ-ANLT-001).
 *
 * @method string|null getDashboardUuid()
 * @method void setDashboardUuid(?string $dashboardUuid)
 * @method string|null getViewBucket()
 * @method void setViewBucket(?string $viewBucket)
 * @method int getViewCount()
 * @method void setViewCount(int $viewCount)
 * @method int getUniqueViewerCount()
 * @method void setUniqueViewerCount(int $uniqueViewerCount)
 */
class DashboardView extends Entity implements JsonSerializable
{

    /**
     * Dashboard UUID this aggregate row belongs to.
     *
     * @var string|null
     */
    protected ?string $dashboardUuid = null;

    /**
     * Calendar date in UTC (YYYY-MM-DD).
     *
     * @var string|null
     */
    protected ?string $viewBucket = null;

    /**
     * Total view-event count for the bucket.
     *
     * @var integer
     */
    protected int $viewCount = 0;

    /**
     * Distinct viewer count for the bucket (cache-deduped).
     *
     * @var integer
     */
    protected int $uniqueViewerCount = 0;

    /**
     * Constructor — register column types.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'integer');
        $this->addType(fieldName: 'viewCount', type: 'integer');
        $this->addType(fieldName: 'uniqueViewerCount', type: 'integer');
    }//end __construct()

    /**
     * Serialize to JSON.
     *
     * Stable, frontend-facing key names. The `viewBucket` key is the
     * UTC date string `YYYY-MM-DD` — matches the spec scenarios in
     * REQ-ANLT-007.
     *
     * @return array The serialized aggregate row.
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                => $this->getId(),
            'dashboardUuid'     => $this->dashboardUuid,
            'viewBucket'        => $this->viewBucket,
            'viewCount'         => $this->viewCount,
            'uniqueViewerCount' => $this->uniqueViewerCount,
        ];
    }//end jsonSerialize()
}//end class
