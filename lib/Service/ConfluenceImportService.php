<?php

/**
 * ConfluenceImportService
 *
 * Top-level orchestrator for the `confluence-html-import` capability
 * (REQ-CFLI-001..012). Reads a Confluence HTML export ZIP via
 * {@see ArchiveParser}, sanitises page bodies, rewrites internal links,
 * and creates one MyDash dashboard per page (with a single full-width
 * text-display widget) preserving the source page hierarchy via the
 * existing `dashboard-tree` capability. Creation runs per-page in its
 * own transaction so a single bad page never aborts the whole batch
 * (REQ-CFLI-009).
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
use OCA\MyDash\Db\Dashboard;
use OCA\MyDash\Db\DashboardMapper;
use OCA\MyDash\Db\WidgetPlacement;
use OCA\MyDash\Db\WidgetPlacementMapper;
use OCA\MyDash\Service\Confluence\ArchiveParser;
use OCA\MyDash\Service\Confluence\HtmlSanitizer;
use OCA\MyDash\Service\Confluence\LinkRewriter;
use OCA\MyDash\Service\Confluence\MacroRenderer;
use OCA\MyDash\Service\Confluence\ParsedArchive;
use OCA\MyDash\Service\Confluence\ParsedPage;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Orchestrates Confluence HTML export → MyDash dashboard imports.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *      Top-level orchestrator must collaborate with parser, sanitiser,
 *      macro renderer, link rewriter, factory, and two mappers.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class ConfluenceImportService
{

    /**
     * Default text-display widget identifier (matches the built-in
     * registration in `WidgetService`).
     *
     * @var string
     */
    private const TEXT_WIDGET_ID = 'text';

    /**
     * Default grid width for the imported text widget.
     *
     * @var integer
     */
    private const WIDGET_GRID_WIDTH = 12;

    /**
     * Default grid height for the imported text widget.
     *
     * @var integer
     */
    private const WIDGET_GRID_HEIGHT = 12;

    /**
     * Constructor.
     *
     * @param ArchiveParser         $parser           Archive reader.
     * @param HtmlSanitizer         $sanitizer        HTML allow-list filter.
     * @param MacroRenderer         $macroRenderer    Macro → HTML renderer.
     * @param LinkRewriter          $linkRewriter     Internal-link rewriter.
     * @param DashboardFactory      $dashboardFactory Dashboard factory.
     * @param DashboardTreeService  $treeService      Tree validation / lookups.
     * @param DashboardMapper       $dashboardMapper  Dashboard mapper.
     * @param WidgetPlacementMapper $placementMapper  Widget mapper.
     * @param IDBConnection         $db               DB connection.
     * @param LoggerInterface       $logger           PSR-3 logger.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     *      Top-level orchestrator must wire every collaborator.
     */
    public function __construct(
        private readonly ArchiveParser $parser,
        private readonly HtmlSanitizer $sanitizer,
        private readonly MacroRenderer $macroRenderer,
        private readonly LinkRewriter $linkRewriter,
        private readonly DashboardFactory $dashboardFactory,
        private readonly DashboardTreeService $treeService,
        private readonly DashboardMapper $dashboardMapper,
        private readonly WidgetPlacementMapper $placementMapper,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Inspect a Confluence archive without committing anything.
     *
     * REQ-CFLI-007: returns the page count, attachment count, parsing
     * warnings, and the asset-folder timestamp the importer would use
     * if invoked for real.
     *
     * @param string $zipPath Path to the uploaded ZIP.
     *
     * @return array{
     *     pageCount:int,
     *     attachmentCount:int,
     *     estimatedDashboards:int,
     *     warnings:array<int,string>,
     *     assetFolder:string
     * }
     */
    public function dryRun(string $zipPath): array
    {
        $archive   = $this->parser->parse(zipPath: $zipPath);
        $timestamp = $this->buildTimestamp();

        return [
            'pageCount'           => $archive->pageCount(),
            'attachmentCount'     => $archive->attachmentCount(),
            'estimatedDashboards' => $archive->pageCount(),
            'warnings'            => $archive->warnings,
            'assetFolder'         => 'MyDash/Imports/'.$timestamp,
        ];
    }//end dryRun()

    /**
     * Import a Confluence HTML export into MyDash dashboards.
     *
     * @param string      $zipPath       Path to the uploaded ZIP.
     * @param string      $currentUserId Importing user UID.
     * @param string|null $parentUuid    Optional parent dashboard UUID
     *                                   (REQ-CFLI-011) — root pages are
     *                                   slotted under this dashboard.
     *
     * @return array{
     *     createdDashboardCount:int,
     *     skippedPageCount:int,
     *     errors:array<int, array{pageId:string, reason:string}>,
     *     warnings:array<int,string>,
     *     assetFolder:string
     * }
     */
    public function import(
        string $zipPath,
        string $currentUserId,
        ?string $parentUuid=null
    ): array {
        $archive   = $this->parser->parse(zipPath: $zipPath);
        $timestamp = $this->buildTimestamp();

        if ($parentUuid !== null && $parentUuid !== '') {
            // 404 surfaces to caller as a warning — pages still import as roots.
            try {
                $this->dashboardMapper->findByUuid(uuid: $parentUuid);
            } catch (DoesNotExistException) {
                $this->logger->warning(
                    message: 'Confluence import: parent dashboard not found, falling back to roots',
                    context: ['parentUuid' => $parentUuid]
                );
                $parentUuid = null;
            }
        }

        $ordered         = $this->orderParentsBeforeChildren(pages: $archive->pages);
        $pageIdToUuid    = [];
        $pageIdToOrder   = [];
        $createdCount    = 0;
        $skippedCount    = 0;
        $errors          = [];
        $rewriteWarnings = $archive->warnings;

        foreach ($ordered as $page) {
            $resolvedParent = $this->resolveParentUuid(
                page: $page,
                pageIdToUuid: $pageIdToUuid,
                rootParentUuid: $parentUuid
            );

            try {
                $dashboardUuid = $this->createDashboardForPage(
                    page: $page,
                    parentUuid: $resolvedParent,
                    currentUserId: $currentUserId
                );

                $pageIdToUuid[strtolower(string: $page->pageId)] = $dashboardUuid;
                $pageIdToOrder[$page->pageId] = $page;
                $createdCount++;
            } catch (Throwable $e) {
                $skippedCount++;
                $errors[] = [
                    'pageId' => $page->pageId,
                    'reason' => $e->getMessage(),
                ];
                $this->logger->warning(
                    message: 'Confluence page skipped during import',
                    context: ['pageId' => $page->pageId, 'exception' => $e]
                );
            }//end try
        }//end foreach

        $rewriteWarnings = array_merge(
            $rewriteWarnings,
            $this->backfillWidgetContent(
                pages: $pageIdToOrder,
                pageIdToUuid: $pageIdToUuid
            )
        );

        return [
            'createdDashboardCount' => $createdCount,
            'skippedPageCount'      => $skippedCount,
            'errors'                => $errors,
            'warnings'              => $rewriteWarnings,
            'assetFolder'           => 'MyDash/Imports/'.$timestamp,
        ];
    }//end import()

    /**
     * Topologically order pages so every parent is created before its
     * children. Pages whose declared parent never resolves are emitted
     * at the end so they end up as orphans (root-level imports).
     *
     * @param ParsedPage[] $pages The pages to sort.
     *
     * @return ParsedPage[] Ordered list (parents first).
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *      Two-pass topological order with a sibling sort and parent
     *      cycle detection — splitting into helpers would require
     *      passing the per-pass closure state across method boundaries.
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function orderParentsBeforeChildren(array $pages): array
    {
        $byPageId = [];
        foreach ($pages as $page) {
            $byPageId[strtolower(string: $page->pageId)] = $page;
        }

        $resolved = [];
        $emitted  = [];

        $emit = static function (ParsedPage $page) use (&$emitted, &$resolved): void {
            $key = strtolower(string: $page->pageId);
            if (isset($emitted[$key]) === true) {
                return;
            }

            $emitted[$key] = true;
            $resolved[]    = $page;
        };

        $visit = function (ParsedPage $page) use (&$visit, &$emitted, &$byPageId, $emit): void {
            $key = strtolower(string: $page->pageId);
            if (isset($emitted[$key]) === true) {
                return;
            }

            $parentKey = null;
            if ($page->parentPageId !== null) {
                $parentKey = strtolower(string: $page->parentPageId);
            }

            if ($parentKey !== null
                && $parentKey !== $key
                && isset($byPageId[$parentKey]) === true
                && isset($emitted[$parentKey]) === false
            ) {
                $visit($byPageId[$parentKey]);
            }

            $emit($page);
        };

        foreach ($pages as $page) {
            $visit($page);
        }

        // Stable secondary sort within siblings by parent + sortOrder.
        usort(
            array: $resolved,
            callback: static function (ParsedPage $a, ParsedPage $b): int {
                return ($a->sortOrder <=> $b->sortOrder);
            }
        );

        // Re-apply parent-before-children after the sort.
        $emitted    = [];
        $finalOrder = [];
        $reEmit     = static function (ParsedPage $page) use (&$emitted, &$finalOrder): void {
            $key = strtolower(string: $page->pageId);
            if (isset($emitted[$key]) === true) {
                return;
            }

            $emitted[$key] = true;
            $finalOrder[]  = $page;
        };

        $reVisit = function (ParsedPage $page) use (&$reVisit, &$emitted, &$byPageId, $reEmit): void {
            $key = strtolower(string: $page->pageId);
            if (isset($emitted[$key]) === true) {
                return;
            }

            $parentKey = null;
            if ($page->parentPageId !== null) {
                $parentKey = strtolower(string: $page->parentPageId);
            }

            if ($parentKey !== null
                && $parentKey !== $key
                && isset($byPageId[$parentKey]) === true
                && isset($emitted[$parentKey]) === false
            ) {
                $reVisit($byPageId[$parentKey]);
            }

            $reEmit($page);
        };

        foreach ($resolved as $page) {
            $reVisit($page);
        }

        return $finalOrder;
    }//end orderParentsBeforeChildren()

    /**
     * Resolve the parent dashboard UUID for a given page.
     *
     * @param ParsedPage            $page           The page being created.
     * @param array<string, string> $pageIdToUuid   Map of created pages.
     * @param string|null           $rootParentUuid Optional CLI-supplied root.
     *
     * @return string|null The dashboard UUID to use as `parentUuid`.
     */
    private function resolveParentUuid(
        ParsedPage $page,
        array $pageIdToUuid,
        ?string $rootParentUuid
    ): ?string {
        if ($page->parentPageId === null) {
            return $rootParentUuid;
        }

        $key = strtolower(string: $page->parentPageId);
        if (isset($pageIdToUuid[$key]) === true) {
            return $pageIdToUuid[$key];
        }

        // Parent did not import — fall back to the optional CLI root.
        return $rootParentUuid;
    }//end resolveParentUuid()

    /**
     * Create a dashboard + text widget for a single Confluence page.
     *
     * Wrapped in its own transaction so a malformed widget never leaves
     * the DB with a half-imported dashboard.
     *
     * @param ParsedPage  $page          The parsed page.
     * @param string|null $parentUuid    Resolved parent dashboard UUID.
     * @param string      $currentUserId Importing user UID.
     *
     * @return string The new dashboard UUID.
     */
    private function createDashboardForPage(
        ParsedPage $page,
        ?string $parentUuid,
        string $currentUserId
    ): string {
        $this->db->beginTransaction();
        try {
            // Validate parent + depth. NULL parents always pass.
            $this->treeService->validateParent(
                movingUuid: null,
                newParentUuid: $parentUuid
            );

            $dashboard = $this->dashboardFactory->create(
                userId: $currentUserId,
                name: $page->title,
                description: null,
                type: Dashboard::TYPE_USER,
                groupId: null,
                gridColumns: 12,
                permissionLevel: Dashboard::PERMISSION_FULL,
                parentUuid: $parentUuid,
                slug: null,
                sortOrder: $page->sortOrder
            );

            // Suffix slug with pageId on collision so re-import always
            // succeeds (REQ-CFLI-010 — no dedup, new dashboards each run).
            $dashboard = $this->ensureUniqueSlug(
                dashboard: $dashboard,
                parentUuid: $parentUuid,
                pageId: $page->pageId
            );

            $persisted = $this->dashboardMapper->insert(entity: $dashboard);

            $placement = $this->buildPlaceholderWidget(
                dashboardId: (int) $persisted->getId(),
                page: $page
            );

            $this->placementMapper->insert(entity: $placement);

            $this->db->commit();

            return (string) $persisted->getUuid();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }//end try
    }//end createDashboardForPage()

    /**
     * Build the placeholder text widget for a freshly-inserted dashboard.
     *
     * Body content is rewritten in {@see backfillWidgetContent()} after
     * every page UUID is known so internal `<a href="page-X.html">`
     * links can be re-pointed at the new dashboards.
     *
     * @param int        $dashboardId The new dashboard ID.
     * @param ParsedPage $page        The source page (only used for context).
     *
     * @return WidgetPlacement A new placement entity (unsaved).
     */
    private function buildPlaceholderWidget(int $dashboardId, ParsedPage $page): WidgetPlacement
    {
        unset($page);
        $placement = new WidgetPlacement();
        $placement->setDashboardId($dashboardId);
        $placement->setWidgetId(self::TEXT_WIDGET_ID);
        $placement->setGridX(0);
        $placement->setGridY(0);
        $placement->setGridWidth(self::WIDGET_GRID_WIDTH);
        $placement->setGridHeight(self::WIDGET_GRID_HEIGHT);
        $placement->setIsVisible(1);
        $placement->setShowTitle(1);
        $placement->setSortOrder(0);

        $placement->setStyleConfigArray(
            config: [
                'text'            => '',
                'fontSize'        => '14px',
                'color'           => 'var(--color-main-text)',
                'backgroundColor' => 'transparent',
                'textAlign'       => 'left',
            ]
        );

        return $placement;
    }//end buildPlaceholderWidget()

    /**
     * Backfill widget content with sanitised + rewritten HTML.
     *
     * Done as a second pass so internal Confluence links can be
     * resolved against the freshly-created dashboard UUIDs.
     *
     * @param array<string, ParsedPage> $pages        pageId → ParsedPage.
     * @param array<string, string>     $pageIdToUuid pageId (lower) → uuid.
     *
     * @return array<int, string> Warnings collected during link rewriting.
     */
    private function backfillWidgetContent(array $pages, array $pageIdToUuid): array
    {
        $warnings = [];

        foreach ($pages as $page) {
            try {
                $dashboard = $this->dashboardMapper->findByUuid(
                    uuid: $pageIdToUuid[strtolower(string: $page->pageId)]
                );
            } catch (DoesNotExistException) {
                continue;
            }

            $placements = $this->placementMapper->findByDashboardId(
                dashboardId: (int) $dashboard->getId()
            );

            if ($placements === []) {
                continue;
            }

            $expanded   = $this->macroRenderer->render(html: $page->body);
            $rewriteRes = $this->linkRewriter->rewrite(
                html: $expanded,
                pageIdToUuid: $pageIdToUuid
            );
            $sanitised  = $this->sanitizer->sanitize(html: $rewriteRes['html']);

            $placement      = $placements[0];
            $config         = $placement->getStyleConfigArray();
            $config['text'] = $sanitised;
            $placement->setStyleConfigArray(config: $config);
            $placement->setUpdatedAt((new DateTime())->format(format: 'Y-m-d H:i:s'));

            $this->placementMapper->update(entity: $placement);

            $warnings = array_merge($warnings, $rewriteRes['warnings']);
        }//end foreach

        return $warnings;
    }//end backfillWidgetContent()

    /**
     * Append `-<pageId>` to the slug when a sibling already owns it.
     *
     * Each Confluence import always creates new dashboards (REQ-CFLI-010
     * — no dedup), so on the second run the slug derived from the page
     * title would collide. Suffixing with the original pageId keeps the
     * collision check happy without forcing the admin to clean up the
     * first run.
     *
     * @param Dashboard   $dashboard  The dashboard with caller-derived slug.
     * @param string|null $parentUuid The resolved parent UUID.
     * @param string      $pageId     The Confluence page identifier.
     *
     * @return Dashboard The (possibly mutated) dashboard.
     */
    private function ensureUniqueSlug(
        Dashboard $dashboard,
        ?string $parentUuid,
        string $pageId
    ): Dashboard {
        $slug = (string) $dashboard->getSlug();
        if ($slug === '') {
            return $dashboard;
        }

        try {
            $this->treeService->validateSlugUnique(
                parentUuid: $parentUuid,
                slug: $slug
            );
            return $dashboard;
        } catch (\InvalidArgumentException) {
            $suffix = SlugGenerator::slugify(name: $pageId);
            if ($suffix === '') {
                $suffix = (string) random_int(min: 1000, max: 9999);
            }

            $candidate = ($slug.'-'.$suffix);
            // Final fallback: append timestamp segment (always unique).
            try {
                $this->treeService->validateSlugUnique(
                    parentUuid: $parentUuid,
                    slug: $candidate
                );
            } catch (\InvalidArgumentException) {
                $candidate = ($candidate.'-'.(string) time());
            }

            $dashboard->setSlug($candidate);
            return $dashboard;
        }//end try
    }//end ensureUniqueSlug()

    /**
     * Build the timestamp segment for the asset folder.
     *
     * @return string The ISO 8601 segment (e.g. `2026-05-02T10-30-00`).
     */
    private function buildTimestamp(): string
    {
        return (new DateTime())->format(format: 'Y-m-d\TH-i-s');
    }//end buildTimestamp()
}//end class
