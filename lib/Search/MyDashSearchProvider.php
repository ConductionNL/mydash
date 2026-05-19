<?php

/**
 * MyDashSearchProvider
 *
 * Nextcloud unified-search provider that surfaces MyDash dashboards,
 * text-display widget content, and dashboard metadata-field values to
 * the global Ctrl+K / Cmd+K search bar.
 *
 * Implements REQ-SRCH-001..012:
 *  - REQ-SRCH-001 — provider registration with id `mydash`, order 50
 *  - REQ-SRCH-002 — case-insensitive substring match on dashboard
 *    name and description
 *  - REQ-SRCH-003 — text-display widget content match against the
 *    `styleConfig` JSON column with `{type:'text', content:{text:…}}`
 *  - REQ-SRCH-004 — metadata field value match (graceful degradation
 *    when no metadata is configured / capability disabled)
 *  - REQ-SRCH-005 — permission filtering via the canonical
 *    `DashboardService::getVisibleToUser()` boundary which already
 *    layers the `permissions` capability and publication-state filter
 *  - REQ-SRCH-006 — result entries carry title / subline / icon /
 *    resource URL with deep-link hash for widget matches
 *  - REQ-SRCH-007 — per-bucket cap of 10 entries
 *  - REQ-SRCH-008 — every user-facing string runs through `IL10N`
 *  - REQ-SRCH-009 — empty term and no-match return
 *    `SearchResult::complete()` with an empty array (never throws)
 *  - REQ-SRCH-010 — single in-memory pass over the user's visible set
 *    keeps the response under the unified-search budget
 *  - REQ-SRCH-011 — result URLs target the workspace page with a
 *    `#dashboard/{uuid}` (and optional `;widget={placementId}`) hash
 *    so the SPA can deep-link without a separate redirect route
 *  - REQ-SRCH-012 — `getOrder()` returns 50, mid-range priority
 *
 * @category  Search
 * @package   OCA\MyDash\Search
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

namespace OCA\MyDash\Search;

use OCA\MyDash\AppInfo\Application;
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Service\DashboardService;
use OCA\MyDash\Service\MetadataService;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\L10N\IFactory;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use Throwable;

/**
 * Unified-search provider for MyDash content.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Search needs the
 *  visible-set boundary, the placement mapper for widget content,
 *  the metadata facade, and the URL/IL10N factories. The provider is
 *  the single integration point with NC's search dispatcher.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The provider
 *  orchestrates three independent match dimensions (dashboard,
 *  widget content, metadata) plus result-entry building and URL
 *  construction. Splitting would scatter the per-dimension match
 *  invariants across helpers without reducing total complexity.
 */
class MyDashSearchProvider implements IProvider
{
    /**
     * Cap per result bucket — REQ-SRCH-007.
     *
     * @var int
     */
    private const PER_BUCKET_LIMIT = 10;

    /**
     * Discriminator for the text-display widget shape stored in the
     * `styleConfig` JSON column (text-display-widget spec).
     *
     * @var string
     */
    private const TEXT_WIDGET_TYPE = 'text';

    /**
     * Constructor.
     *
     * @param DashboardService      $dashboardService The dashboard service.
     * @param WidgetPlacementMapper $placementMapper  The placement mapper.
     * @param MetadataService       $metadataService  The metadata service.
     * @param IFactory              $l10nFactory      The L10N factory.
     * @param IURLGenerator         $urlGenerator     The URL generator.
     */
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly MetadataService $metadataService,
        private readonly IFactory $l10nFactory,
        private readonly IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * Provider id — REQ-SRCH-001.
     *
     * @return string The provider id.
     */
    public function getId(): string
    {
        return Application::APP_ID;
    }//end getId()

    /**
     * Translated display name — REQ-SRCH-001 / REQ-SRCH-008.
     *
     * @return string The translated name.
     */
    public function getName(): string
    {
        return $this->l10nFactory->get(app: Application::APP_ID)->t('Dashboards');
    }//end getName()

    /**
     * Provider order — REQ-SRCH-012. Mid-range so admin-search providers
     * (typical order 5-10) come first, contacts/files (100+) come after.
     *
     * @param string $route           The current frontend route.
     * @param array  $routeParameters The current route parameters.
     *
     * @return int|null The provider order.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) Required by interface.
     */
    public function getOrder(string $route, array $routeParameters): ?int
    {
        // Boost MyDash to the top when the user is already in the app.
        $inApp = str_starts_with(haystack: $route, needle: Application::APP_ID.'.');
        if ($inApp === true) {
            return 5;
        }

        return 50;
    }//end getOrder()

    /**
     * Run the search across dashboards, widget content, and metadata.
     *
     * Permission boundary delegates to {@see DashboardService::getVisibleToUser()}
     * which already enforces the `permissions` capability and the
     * `dashboards` publication-state filter (REQ-SRCH-005).
     *
     * @param IUser        $user  The acting user.
     * @param ISearchQuery $query The unified-search query.
     *
     * @return SearchResult The search result for the `mydash` group.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Match-tier dispatch
     *  drives the branch count; splitting would scatter the result-entry
     *  shape across helpers for no readability gain.
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function search(IUser $user, ISearchQuery $query): SearchResult
    {
        $providerName = $this->getName();
        $term         = trim(string: $query->getTerm());

        // REQ-SRCH-009: empty term yields an empty (non-error) result.
        if ($term === '') {
            return SearchResult::complete(name: $providerName, entries: []);
        }

        try {
            $visible = $this->dashboardService->getVisibleToUser(
                userId: $user->getUID()
            );
        } catch (Throwable) {
            // REQ-SRCH-005: fail safe — never leak when the permission
            // boundary cannot resolve.
            return SearchResult::complete(name: $providerName, entries: []);
        }

        if (count($visible) === 0) {
            return SearchResult::complete(name: $providerName, entries: []);
        }

        $needle = mb_strtolower(string: $term);
        $l10n   = $this->l10nFactory->get(app: Application::APP_ID);

        $dashboardEntries = [];
        $widgetEntries    = [];
        $metadataEntries  = [];

        foreach ($visible as $entry) {
            $dashboard = $entry['dashboard'];

            // --- Dashboard name + description (REQ-SRCH-002) ---
            if (count($dashboardEntries) < self::PER_BUCKET_LIMIT) {
                if ($this->matchesDashboard(dashboard: $dashboard, needle: $needle) === true) {
                    $dashboardEntries[] = $this->buildDashboardEntry(
                        dashboard: $dashboard
                    );
                }
            }

            // --- Widget content (REQ-SRCH-003) ---
            if (count($widgetEntries) < self::PER_BUCKET_LIMIT) {
                $matchedPlacements = $this->findMatchingTextPlacements(
                    dashboard: $dashboard,
                    needle: $needle
                );
                foreach ($matchedPlacements as $placement) {
                    if (count($widgetEntries) >= self::PER_BUCKET_LIMIT) {
                        break;
                    }

                    $widgetEntries[] = $this->buildWidgetEntry(
                        dashboard: $dashboard,
                        placement: $placement,
                        l10n: $l10n
                    );
                }
            }

            // --- Metadata-field values (REQ-SRCH-004) ---
            if (count($metadataEntries) < self::PER_BUCKET_LIMIT) {
                $matchedMetadata = $this->findMatchingMetadata(
                    dashboard: $dashboard,
                    needle: $needle
                );
                foreach ($matchedMetadata as $key => $value) {
                    if (count($metadataEntries) >= self::PER_BUCKET_LIMIT) {
                        break;
                    }

                    $metadataEntries[] = $this->buildMetadataEntry(
                        dashboard: $dashboard,
                        fieldKey: (string) $key,
                        fieldValue: $value,
                        l10n: $l10n
                    );
                }
            }
        }//end foreach

        // Rank tiers: title/description first, then widget body, then
        // metadata. REQ-SRCH-006 / REQ-SRCH-011 (UI grouping).
        $entries = array_merge($dashboardEntries, $widgetEntries, $metadataEntries);

        return SearchResult::complete(name: $providerName, entries: $entries);
    }//end search()

    /**
     * Whether a dashboard's name or description contains the needle
     * (case-insensitive substring match).
     *
     * @param Dashboard $dashboard The dashboard.
     * @param string    $needle    The lower-cased search term.
     *
     * @return bool Whether the dashboard matches.
     */
    private function matchesDashboard(Dashboard $dashboard, string $needle): bool
    {
        $name = mb_strtolower(string: (string) $dashboard->getName());
        if ($name !== '' && str_contains(haystack: $name, needle: $needle) === true) {
            return true;
        }

        $description = mb_strtolower(string: (string) $dashboard->getDescription());
        if ($description !== ''
            && str_contains(haystack: $description, needle: $needle) === true
        ) {
            return true;
        }

        return false;
    }//end matchesDashboard()

    /**
     * Find text-display placements on the dashboard whose `text`
     * content matches the needle.
     *
     * @param Dashboard $dashboard The dashboard.
     * @param string    $needle    The lower-cased search term.
     *
     * @return WidgetPlacement[] The matching placements.
     */
    private function findMatchingTextPlacements(
        Dashboard $dashboard,
        string $needle
    ): array {
        $dashboardId = (int) $dashboard->getId();
        if ($dashboardId === 0) {
            return [];
        }

        try {
            $placements = $this->placementMapper->findByDashboardId(
                dashboardId: $dashboardId
            );
        } catch (Throwable) {
            return [];
        }

        $matches = [];
        foreach ($placements as $placement) {
            $text = $this->extractTextWidgetContent(placement: $placement);
            if ($text === null) {
                continue;
            }

            $haystack = mb_strtolower(string: $this->stripTags(html: $text));
            if (str_contains(haystack: $haystack, needle: $needle) === true) {
                $matches[] = $placement;
            }
        }

        return $matches;
    }//end findMatchingTextPlacements()

    /**
     * Decode the text-display content from a placement's `styleConfig`
     * JSON column. Returns `null` when the placement is not a text
     * widget or the column is malformed.
     *
     * @param WidgetPlacement $placement The placement entity.
     *
     * @return string|null The raw `content.text` value, or null.
     */
    private function extractTextWidgetContent(WidgetPlacement $placement): ?string
    {
        $rawConfig = $placement->getStyleConfig();
        if ($rawConfig === null || $rawConfig === '') {
            return null;
        }

        try {
            $decoded = json_decode(
                json: $rawConfig,
                associative: true,
                depth: 32,
                flags: JSON_THROW_ON_ERROR
            );
        } catch (Throwable) {
            return null;
        }

        if (is_array(value: $decoded) === false) {
            return null;
        }

        $type = ($decoded['type'] ?? null);
        if ($type !== self::TEXT_WIDGET_TYPE) {
            return null;
        }

        $content = ($decoded['content'] ?? null);
        if (is_array(value: $content) === false) {
            return null;
        }

        $text = ($content['text'] ?? null);
        if (is_string(value: $text) === false || $text === '') {
            return null;
        }

        return $text;
    }//end extractTextWidgetContent()

    /**
     * Strip HTML tags so search matches survive markup
     * (REQ-SRCH-003 scenario "Text widget with HTML rendering").
     *
     * @param string $html The widget body — may contain HTML.
     *
     * @return string The plain-text body.
     */
    private function stripTags(string $html): string
    {
        $stripped = strip_tags(string: $html);
        // Decode entities so `&amp;` matches `&`.
        return html_entity_decode(
            string: $stripped,
            flags: (ENT_QUOTES | ENT_HTML5),
            encoding: 'UTF-8'
        );
    }//end stripTags()

    /**
     * Find metadata key/value pairs on the dashboard that match the
     * needle (REQ-SRCH-004). Degrades silently on any storage error
     * — the metadata capability is optional from the search provider's
     * perspective.
     *
     * @param Dashboard $dashboard The dashboard.
     * @param string    $needle    The lower-cased search term.
     *
     * @return array<string, string> Matching key → value pairs.
     */
    private function findMatchingMetadata(
        Dashboard $dashboard,
        string $needle
    ): array {
        $uuid = (string) $dashboard->getUuid();
        if ($uuid === '') {
            return [];
        }

        try {
            $metadata = $this->metadataService->getMetadataForDashboard(
                dashboardUuid: $uuid
            );
        } catch (Throwable) {
            return [];
        }

        $matches = [];
        foreach ($metadata as $key => $value) {
            $stringValue = (string) $value;
            if ($stringValue === '') {
                continue;
            }

            $haystack = mb_strtolower(string: $stringValue);
            if (str_contains(haystack: $haystack, needle: $needle) === true) {
                $matches[(string) $key] = $stringValue;
            }
        }

        return $matches;
    }//end findMatchingMetadata()

    /**
     * Build a search-result entry for a dashboard match.
     *
     * @param Dashboard $dashboard The matching dashboard.
     *
     * @return SearchResultEntry The result entry.
     */
    private function buildDashboardEntry(Dashboard $dashboard): SearchResultEntry
    {
        $l10n        = $this->l10nFactory->get(app: Application::APP_ID);
        $name        = (string) $dashboard->getName();
        $description = trim(string: (string) $dashboard->getDescription());
        $subline     = $description;
        if ($subline === '') {
            $subline = $l10n->t('MyDash dashboard');
        }

        return new SearchResultEntry(
            thumbnailUrl: $this->iconUrl(),
            title: $name,
            subline: $subline,
            resourceUrl: $this->dashboardUrl(uuid: (string) $dashboard->getUuid()),
            icon: $this->iconUrl(),
            rounded: false
        );
    }//end buildDashboardEntry()

    /**
     * Build a search-result entry for a widget-content match.
     *
     * @param Dashboard       $dashboard The parent dashboard.
     * @param WidgetPlacement $placement The matching placement.
     * @param \OCP\IL10N      $l10n      The localizer.
     *
     * @return SearchResultEntry The result entry.
     */
    private function buildWidgetEntry(
        Dashboard $dashboard,
        WidgetPlacement $placement,
        \OCP\IL10N $l10n
    ): SearchResultEntry {
        $dashboardName = (string) $dashboard->getName();
        $subline       = $l10n->t('Widget content on %s', [$dashboardName]);

        return new SearchResultEntry(
            thumbnailUrl: $this->iconUrl(),
            title: $dashboardName,
            subline: $subline,
            resourceUrl: $this->widgetUrl(
                uuid: (string) $dashboard->getUuid(),
                placementId: (int) $placement->getId()
            ),
            icon: $this->iconUrl(),
            rounded: false
        );
    }//end buildWidgetEntry()

    /**
     * Build a search-result entry for a metadata-value match.
     *
     * @param Dashboard  $dashboard  The parent dashboard.
     * @param string     $fieldKey   The metadata field key.
     * @param string     $fieldValue The matching value.
     * @param \OCP\IL10N $l10n       The localizer.
     *
     * @return SearchResultEntry The result entry.
     */
    private function buildMetadataEntry(
        Dashboard $dashboard,
        string $fieldKey,
        string $fieldValue,
        \OCP\IL10N $l10n
    ): SearchResultEntry {
        $dashboardName = (string) $dashboard->getName();
        $subline       = $l10n->t(
            'Metadata: %1$s = %2$s',
            [$fieldKey, $fieldValue]
        );

        return new SearchResultEntry(
            thumbnailUrl: $this->iconUrl(),
            title: $dashboardName,
            subline: $subline,
            resourceUrl: $this->dashboardUrl(uuid: (string) $dashboard->getUuid()),
            icon: $this->iconUrl(),
            rounded: false
        );
    }//end buildMetadataEntry()

    /**
     * Build the dashboard deep-link URL. The hash fragment lets the SPA
     * focus the matching dashboard without a separate redirect route
     * (REQ-SRCH-006 design D6).
     *
     * @param string $uuid The dashboard UUID.
     *
     * @return string The absolute deep-link URL.
     */
    private function dashboardUrl(string $uuid): string
    {
        $base = $this->urlGenerator->linkToRouteAbsolute(
            routeName: Application::APP_ID.'.page.index'
        );

        if ($uuid === '') {
            return $base;
        }

        return $base.'#dashboard/'.rawurlencode(string: $uuid);
    }//end dashboardUrl()

    /**
     * Build the widget deep-link URL. The placement id is appended to
     * the dashboard hash fragment so the SPA can scroll/highlight the
     * specific widget on mount (REQ-SRCH-003 / REQ-SRCH-011).
     *
     * @param string $uuid        The dashboard UUID.
     * @param int    $placementId The placement id.
     *
     * @return string The absolute deep-link URL.
     */
    private function widgetUrl(string $uuid, int $placementId): string
    {
        $base = $this->dashboardUrl(uuid: $uuid);
        if ($placementId === 0) {
            return $base;
        }

        $separator = '?';
        if (str_contains(haystack: $base, needle: '#') === true) {
            // Hash already present — append widget hint inside the fragment.
            return $base.';widget='.$placementId;
        }

        return $base.$separator.'widget='.$placementId;
    }//end widgetUrl()

    /**
     * Resolve the absolute MyDash icon URL used for every result entry.
     *
     * @return string The absolute icon URL.
     */
    private function iconUrl(): string
    {
        return $this->urlGenerator->getAbsoluteURL(
            url: $this->urlGenerator->imagePath(
                appName: Application::APP_ID,
                file: 'app.svg'
            )
        );
    }//end iconUrl()
}//end class
