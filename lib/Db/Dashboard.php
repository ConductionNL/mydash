<?php

/**
 * Dashboard Entity
 *
 * Represents a dashboard entity.
 *
 * @category  Database
 * @package   OCA\MyDash\Db
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12 EUPL-1.2
 * @version   GIT:auto
 * @link      https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\MyDash\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Dashboard entity for storing dashboard configuration.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getIcon()
 * @method void setIcon(?string $icon)
 * @method string|null getType()
 * @method void setType(?string $type)
 * @method string|null getUserId()
 * @method void setUserId(?string $userId)
 * @method string|null getGroupId()
 * @method void setGroupId(?string $groupId)
 * @method int|null getBasedOnTemplate()
 * @method void setBasedOnTemplate(?int $basedOnTemplate)
 * @method int getGridColumns()
 * @method void setGridColumns(int $gridColumns)
 * @method string getPermissionLevel()
 * @method void setPermissionLevel(string $permissionLevel)
 * @method string|null getTargetGroups()
 * @method void setTargetGroups(?string $targetGroups)
 * @method int getIsDefault()
 * @method void setIsDefault(int $isDefault)
 * @method int getIsActive()
 * @method void setIsActive(int $isActive)
 * @method string|null getCreatedAt()
 * @method void setCreatedAt(?string $createdAt)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 * @method string|null getParentUuid()
 * @method void setParentUuid(?string $parentUuid)
 * @method string|null getSlug()
 * @method void setSlug(?string $slug)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method string getPublicationStatus()
 * @method void setPublicationStatus(string $publicationStatus)
 * @method string|null getPublishAt()
 * @method void setPublishAt(?string $publishAt)
 * @method string|null getPublishedAt()
 * @method void setPublishedAt(?string $publishedAt)
 * @method int|null getCommentsEnabled()
 * @method void setCommentsEnabled(?int $commentsEnabled)
 * @method int|null getReactionsEnabled()
 * @method void setReactionsEnabled(?int $reactionsEnabled)
 * @method string getDashboardFooterMode()
 * @method void setDashboardFooterMode(string $dashboardFooterMode)
 * @method string|null getDashboardFooterHtml()
 * @method void setDashboardFooterHtml(?string $dashboardFooterHtml)
 * @method string|null getTemplateCategory()
 * @method void setTemplateCategory(?string $templateCategory)
 * @method string|null getTemplateDescription()
 * @method void setTemplateDescription(?string $templateDescription)
 * @method string|null getTemplatePreviewImage()
 * @method void setTemplatePreviewImage(?string $templatePreviewImage)
 *
 * @SuppressWarnings(PHPMD.TooManyFields) Each field maps to a documented
 *                                        column on `oc_mydash_dashboards`
 *                                        that the frontend or service
 *                                        layer reads directly; splitting
 *                                        would break the entity↔DB row
 *                                        contract. Each new capability
 *                                        adds one column (groupId,
 *                                        isDefault, isActive,
 *                                        commentsEnabled,
 *                                        reactionsEnabled,
 *                                        dashboardFooterMode,
 *                                        dashboardFooterHtml,
 *                                        templateCategory,
 *                                        templateDescription,
 *                                        templatePreviewImage, ...).
 *                                        Aggregates personal,
 *                                        group-shared, hierarchy,
 *                                        publication-state,
 *                                        footer-override and
 *                                        template-discovery columns on a
 *                                        single row; cross-cutting
 *                                        capabilities use the same table
 *                                        per the admin-templates /
 *                                        dashboard-tree /
 *                                        footer-customization designs.
 */
class Dashboard extends Entity implements JsonSerializable
{

    /**
     * Maximum supported dashboard tree depth (root + 4 descendants).
     *
     * Enforced at write time by `DashboardTreeService::validateDepth()`
     * and surfaced via the `validateDepth` guard called from the create
     * and update controllers (REQ-DASH-028).
     *
     * @var integer
     */
    public const MAX_DEPTH = 5;

    /**
     * Dashboard type for admin templates.
     *
     * @var string
     */
    public const TYPE_ADMIN_TEMPLATE = 'admin_template';

    /**
     * Dashboard type for user dashboards.
     *
     * @var string
     */
    public const TYPE_USER = 'user';

    /**
     * Dashboard type for group-shared dashboards.
     *
     * Group-shared dashboards are admin-authored, scoped to a single
     * Nextcloud group via the {@see Dashboard::$groupId} field, and
     * rendered live (not copied) to every member of that group.
     * REQ-DASH-011.
     *
     * @var string
     */
    public const TYPE_GROUP_SHARED = 'group_shared';

    /**
     * Synthetic group sentinel meaning "visible to every user".
     *
     * Reserved literal value for the {@see Dashboard::$groupId} field
     * on group-shared dashboards. REQ-DASH-012.
     *
     * @var string
     */
    public const DEFAULT_GROUP_ID = 'default';

    /**
     * Source tag indicating a personal user-owned dashboard.
     *
     * Used in the `/api/dashboards/visible` payload only — never
     * persisted on the entity. REQ-DASH-013.
     *
     * @var string
     */
    public const SOURCE_USER = 'user';

    /**
     * Source tag indicating a group-matched group-shared dashboard.
     *
     * Used in the `/api/dashboards/visible` payload only — never
     * persisted on the entity. REQ-DASH-013.
     *
     * @var string
     */
    public const SOURCE_GROUP = 'group';

    /**
     * Source tag indicating a default-group group-shared dashboard.
     *
     * Used in the `/api/dashboards/visible` payload only — never
     * persisted on the entity. REQ-DASH-013.
     *
     * @var string
     */
    public const SOURCE_DEFAULT = 'default';

    /**
     * Publication status: dashboard is a draft, visible only to its owner
     * (and Nextcloud admins). REQ-DASH-031..037.
     *
     * @var string
     */
    public const STATUS_DRAFT = 'draft';

    /**
     * Publication status: dashboard is published and follows the normal
     * visibility / share rules. REQ-DASH-031..037.
     *
     * @var string
     */
    public const STATUS_PUBLISHED = 'published';

    /**
     * Publication status: dashboard is scheduled for automatic publication
     * at a future timestamp held in `publishAt`. Behaves as `draft` until
     * `publishAt <= now()`, after which read-time materialisation flips it
     * to `published`. REQ-DASH-034.
     *
     * @var string
     */
    public const STATUS_SCHEDULED = 'scheduled';

    /**
     * Permission level for view only.
     *
     * @var string
     */
    public const PERMISSION_VIEW_ONLY = 'view_only';

    /**
     * Permission level for add only.
     *
     * @var string
     */
    public const PERMISSION_ADD_ONLY = 'add_only';

    /**
     * Permission level for full access.
     *
     * @var string
     */
    public const PERMISSION_FULL = 'full';

    /**
     * Footer mode: dashboard inherits the global instance footer
     * (or none if globally disabled). REQ-FTR-006.
     *
     * @var string
     */
    public const FOOTER_MODE_INHERIT = 'inherit';

    /**
     * Footer mode: dashboard hides the footer regardless of any
     * globally-enabled footer. REQ-FTR-006.
     *
     * @var string
     */
    public const FOOTER_MODE_HIDDEN = 'hidden';

    /**
     * Footer mode: dashboard renders its own
     * {@see Dashboard::$dashboardFooterHtml} (sanitised identically to
     * the global HTML), bypassing the instance footer entirely.
     * REQ-FTR-006.
     *
     * @var string
     */
    public const FOOTER_MODE_CUSTOM = 'custom';

    /**
     * Allow-list of legal {@see Dashboard::$dashboardFooterMode} values.
     * Used by `DashboardService::applyDashboardUpdates()` to validate
     * patches before persisting (REQ-FTR-006).
     *
     * @var array<int, string>
     */
    public const FOOTER_MODES = [
        self::FOOTER_MODE_INHERIT,
        self::FOOTER_MODE_HIDDEN,
        self::FOOTER_MODE_CUSTOM,
    ];

    /**
     * The UUID.
     *
     * @var string|null
     */
    protected ?string $uuid = null;

    /**
     * The name.
     *
     * @var string|null
     */
    protected ?string $name = null;

    /**
     * The description.
     *
     * @var string|null
     */
    protected ?string $description = null;

    /**
     * The dashboard icon.
     *
     * Opaque string owned by the frontend `dashboard-icons` capability.
     * Three legal value classes:
     *   - NULL or empty string — render the frontend `DEFAULT_ICON`
     *   - A registry key (e.g. `'ViewDashboard'`, `'Home'`) — looked up
     *     in `DASHBOARD_ICONS` by the `IconRenderer` component
     *   - A URL (starts with `/` or `http`) — rendered as `<img>` by the
     *     sibling `custom-icon-upload-pattern` capability
     *
     * The backend never inspects this value; it is stored verbatim and
     * the discriminator + lookup live in `src/constants/dashboardIcons.js`.
     *
     * @var string|null
     */
    protected ?string $icon = null;

    /**
     * The dashboard type.
     *
     * @var string|null
     */
    protected ?string $type = self::TYPE_USER;

    /**
     * The user ID.
     *
     * @var string|null
     */
    protected ?string $userId = null;

    /**
     * The group ID for group-shared dashboards.
     *
     * Populated only when {@see Dashboard::$type} equals
     * {@see Dashboard::TYPE_GROUP_SHARED}. The literal value
     * {@see Dashboard::DEFAULT_GROUP_ID} is reserved as a "visible to
     * every user" sentinel. REQ-DASH-011, REQ-DASH-012.
     *
     * @var string|null
     */
    protected ?string $groupId = null;

    /**
     * The template ID this dashboard is based on.
     *
     * @var integer|null
     */
    protected ?int $basedOnTemplate = null;

    /**
     * The number of grid columns.
     *
     * @var integer
     */
    protected int $gridColumns = 12;

    /**
     * The permission level.
     *
     * @var string
     */
    protected string $permissionLevel = self::PERMISSION_FULL;

    /**
     * The target groups JSON.
     *
     * @var string|null
     */
    protected ?string $targetGroups = null;

    /**
     * Whether this is the default (SMALLINT 0/1).
     *
     * @var integer
     */
    protected int $isDefault = 0;

    /**
     * Whether this is active (SMALLINT 0/1).
     *
     * @var integer
     */
    protected int $isActive = 0;

    /**
     * The creation timestamp as string.
     *
     * @var string|null
     */
    protected ?string $createdAt = null;

    /**
     * The update timestamp as string.
     *
     * @var string|null
     */
    protected ?string $updatedAt = null;

    /**
     * The parent dashboard UUID (REQ-DASH-023).
     *
     * NULL for root dashboards. Children reference their parent by
     * UUID (the same value the entity exposes via {@see Dashboard::$uuid}).
     * Cycle prevention and depth enforcement live in
     * `DashboardTreeService` — callers MUST go through the service rather
     * than mutating `parentUuid` directly via the mapper.
     *
     * @var string|null
     */
    protected ?string $parentUuid = null;

    /**
     * The URL-safe slug (REQ-DASH-024).
     *
     * Unique among siblings (per-parent). Auto-generated from the name
     * by `SlugGenerator::slugify()` when not supplied. Slugs combine
     * to form the path (REQ-DASH-025) — `/marketing/campaigns/q1`.
     *
     * @var string|null
     */
    protected ?string $slug = null;

    /**
     * The sibling sort order (REQ-DASH-029).
     *
     * Defaults to 0; ties broken alphabetically by `name`. Lower values
     * appear first in tree responses.
     *
     * @var integer
     */
    protected int $sortOrder = 0;

    /**
     * The publication status (REQ-DASH-031).
     *
     * One of {@see Dashboard::STATUS_DRAFT}, {@see Dashboard::STATUS_PUBLISHED},
     * {@see Dashboard::STATUS_SCHEDULED}. The PHP default mirrors the
     * database column default (`'published'`) so pre-existing rows and
     * raw `new Dashboard()` constructions remain visible until any
     * future migration explicitly flips them. New dashboards created
     * via {@see DashboardFactory::create()} are explicitly overridden
     * to `'draft'` immediately before persistence (REQ-DASH-031, design
     * D2), so the safe default at the application boundary is still
     * "create now, share later".
     *
     * @var string
     */
    protected string $publicationStatus = self::STATUS_PUBLISHED;

    /**
     * The scheduled publish timestamp (REQ-DASH-031, REQ-DASH-033).
     *
     * Required when {@see Dashboard::$publicationStatus} is
     * {@see Dashboard::STATUS_SCHEDULED}; ignored otherwise. ISO-8601
     * timestamp string in the database (DATETIME column, NULL allowed).
     *
     * @var string|null
     */
    protected ?string $publishAt = null;

    /**
     * The first-publication timestamp (REQ-DASH-031, REQ-DASH-032).
     *
     * Set automatically the first time the dashboard transitions to
     * {@see Dashboard::STATUS_PUBLISHED}; preserved when later
     * unpublished so the audit trail survives the round-trip.
     *
     * @var string|null
     */
    protected ?string $publishedAt = null;

    /**
     * Per-dashboard comments toggle (REQ-CMNT-007).
     *
     * Three legal values:
     *   - NULL — inherit the global `mydash.comments_enabled_default`
     *     admin setting.
     *   - 1 (SMALLINT) — force comments on regardless of global toggle.
     *   - 0 (SMALLINT) — force comments off regardless of global toggle.
     *
     * Stored as a nullable SMALLINT to keep the same wire format as
     * `is_default` / `is_active`. Resolution lives in
     * {@see Dashboard::isCommentsEffectivelyEnabled()} so callers do
     * not have to re-implement the precedence rules.
     *
     * @var integer|null
     */
    protected ?int $commentsEnabled = null;

    /**
     * Per-dashboard reactions toggle (REQ-RXN-006).
     *
     * Tri-state SMALLINT NULL column:
     *   - NULL → follow the global `mydash.reactions_enabled_default`
     *     admin setting
     *   - 1    → reactions force-enabled on this dashboard, overriding
     *     the global setting
     *   - 0    → reactions force-disabled on this dashboard, overriding
     *     the global setting
     *
     * Resolution lives in {@see \OCA\MyDash\Service\ReactionService}.
     *
     * @var integer|null
     */
    protected ?int $reactionsEnabled = null;

    /**
     * Per-dashboard footer override mode (REQ-FTR-006).
     *
     * One of {@see Dashboard::FOOTER_MODE_INHERIT},
     * {@see Dashboard::FOOTER_MODE_HIDDEN},
     * {@see Dashboard::FOOTER_MODE_CUSTOM}. Defaults to `inherit` so
     * dashboards created before the migration ran (and any newly
     * persisted entity that does not explicitly set a mode) follow
     * the instance-wide footer setting. Invariant enforced at the
     * service layer: when the mode is `custom`,
     * {@see Dashboard::$dashboardFooterHtml} MUST be a non-empty
     * sanitised HTML string; for both `inherit` and `hidden` it MUST
     * be NULL (the service clears stale HTML on mode flip).
     *
     * @var string
     */
    protected string $dashboardFooterMode = self::FOOTER_MODE_INHERIT;

    /**
     * Per-dashboard footer HTML (REQ-FTR-006).
     *
     * Sanitised server-side via the same allow-list as the global
     * footer (footer-customization design D4). Only consulted when
     * {@see Dashboard::$dashboardFooterMode} is
     * {@see Dashboard::FOOTER_MODE_CUSTOM}; NULL otherwise.
     *
     * @var string|null
     */
    protected ?string $dashboardFooterHtml = null;

    /**
     * The template gallery category (REQ-TMPL-016).
     *
     * Free-form admin-curated label (max 64 chars). NULL means "no
     * category" — surfaced in the gallery alongside categorised templates
     * (sorted last by the default `(category NULLS LAST, name)` ordering).
     * Only meaningful for rows with `type = 'admin_template'`; ignored on
     * `user` and `group_shared` rows.
     *
     * @var string|null
     */
    protected ?string $templateCategory = null;

    /**
     * The template gallery long-form description (REQ-TMPL-016).
     *
     * Stored in a TEXT column so callers can use richer prose than the
     * regular {@see Dashboard::$description} field. Surfaced verbatim
     * in the gallery card. Only meaningful for `admin_template` rows.
     *
     * @var string|null
     */
    protected ?string $templateDescription = null;

    /**
     * The template gallery preview image URL (REQ-TMPL-017).
     *
     * URL produced by the resource-uploads pipeline (typically
     * `/apps/mydash/resource/<filename>`); stored verbatim. NULL means
     * "no preview image" — gallery card renders a placeholder. Only
     * meaningful for `admin_template` rows.
     *
     * @var string|null
     */
    protected ?string $templatePreviewImage = null;

    /**
     * Constructor
     *
     * Registers column types for proper ORM handling.
     * Note: is_default and is_active are SMALLINT in DB, not boolean.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'integer');
        $this->addType(fieldName: 'basedOnTemplate', type: 'integer');
        $this->addType(fieldName: 'gridColumns', type: 'integer');
        $this->addType(fieldName: 'isDefault', type: 'integer');
        // SMALLINT in DB (0/1).
        $this->addType(fieldName: 'isActive', type: 'integer');
        // SMALLINT in DB (0/1).
        $this->addType(fieldName: 'sortOrder', type: 'integer');
        $this->addType(fieldName: 'commentsEnabled', type: 'integer');
        // Nullable SMALLINT (NULL / 0 / 1) — REQ-CMNT-007.
        $this->addType(fieldName: 'reactionsEnabled', type: 'integer');
        // SMALLINT NULL in DB — null means follow global setting (REQ-RXN-006).
    }//end __construct()

    /**
     * Get target groups as array.
     *
     * @return array The decoded target groups.
     */
    public function getTargetGroupsArray(): array
    {
        if (empty($this->targetGroups) === true) {
            return [];
        }

        $decoded = json_decode(json: $this->targetGroups, associative: true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [];
    }//end getTargetGroupsArray()

    /**
     * Set target groups from array.
     *
     * @param array $groups The target groups array.
     *
     * @return void
     */
    public function setTargetGroupsArray(array $groups): void
    {
        // Entity setters resolve via __call which uses $args[0]; named args
        // would break the magic forwarding (see project memory).
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->setTargetGroups(json_encode($groups));
    }//end setTargetGroupsArray()

    /**
     * Serialize to JSON (owner / admin view — full payload).
     *
     * @return array The serialized dashboard.
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                   => $this->getId(),
            'uuid'                 => $this->uuid,
            'name'                 => $this->name,
            'description'          => $this->description,
            'icon'                 => $this->icon,
            'type'                 => $this->type,
            'userId'               => $this->userId,
            'groupId'              => $this->groupId,
            'basedOnTemplate'      => $this->basedOnTemplate,
            'gridColumns'          => $this->gridColumns,
            'permissionLevel'      => $this->permissionLevel,
            'targetGroups'         => $this->getTargetGroupsArray(),
            'isDefault'            => $this->isDefault,
            'isActive'             => $this->isActive,
            'commentsEnabled'      => $this->commentsEnabled,
            // REQ-RXN-006 — null/1/0 tri-state.
            'reactionsEnabled'     => $this->reactionsEnabled,
            'createdAt'            => $this->createdAt,
            'updatedAt'            => $this->updatedAt,
            'parentUuid'           => $this->parentUuid,
            'slug'                 => $this->slug,
            'sortOrder'            => $this->sortOrder,
            'publicationStatus'    => $this->publicationStatus,
            'publishAt'            => $this->publishAt,
            'publishedAt'          => $this->publishedAt,
            // REQ-FTR-006 — surface the per-dashboard footer override
            // fields on every API payload so frontend renderers can
            // decide whether to draw an instance footer, hide it, or
            // render the dashboard-specific HTML.
            'dashboardFooterMode'  => $this->dashboardFooterMode,
            'dashboardFooterHtml'  => $this->dashboardFooterHtml,
            'templateCategory'     => $this->templateCategory,
            'templateDescription'  => $this->templateDescription,
            'templatePreviewImage' => $this->templatePreviewImage,
        ];
    }//end jsonSerialize()

    /**
     * Serialize for a non-owner viewer (M5 — strips internal identity
     * fields from the public envelope).
     *
     * Removes `userId`, `groupId`, `targetGroups`, `templateCategory`,
     * and `templateDescription` which are internal administration fields
     * that non-owner consumers have no legitimate use for.
     *
     * @return array The serialized dashboard without internal fields.
     */
    public function toViewerArray(): array
    {
        $full = $this->jsonSerialize();
        unset(
            $full['userId'],
            $full['groupId'],
            $full['targetGroups'],
            $full['templateCategory'],
            $full['templateDescription']
        );
        return $full;
    }//end toViewerArray()

    /**
     * Whether comments are effectively enabled on this dashboard
     * (REQ-CMNT-007, REQ-CMNT-008).
     *
     * Resolution order:
     *  - When `commentsEnabled` is 1 → true (per-dashboard force-on).
     *  - When `commentsEnabled` is 0 → false (per-dashboard force-off).
     *  - When NULL → fall back to the supplied global default.
     *
     * @param bool $globalDefault The resolved value of the global
     *                            `mydash.comments_enabled_default`
     *                            admin setting.
     *
     * @return bool True when comments are effectively enabled.
     */
    public function isCommentsEffectivelyEnabled(bool $globalDefault): bool
    {
        if ($this->commentsEnabled === 1) {
            return true;
        }

        if ($this->commentsEnabled === 0) {
            return false;
        }

        return $globalDefault;
    }//end isCommentsEffectivelyEnabled()
}//end class
