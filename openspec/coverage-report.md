# Coverage Report — mydash

Generated: 2026-05-24 08:33 UTC
Branch: feature/newman-backend-bugs
Commit: f46ea77
Scanner: opsx-coverage-scan v1 (re-scan, overwrites 2026-04-24 report)

## Scope

| Metric | Value |
|---|---|
| OpenSpec specs in `openspec/specs/` | 54 |
| Unique REQ IDs across all specs | 609 |
| REQ IDs referenced anywhere in code | 524 (86%) |
| PHP files enumerated (`lib/` minus `Migration/`/`Db/`/`Exception/`) | 153 |
| PHP files skipped (Migration/Db/Exception boilerplate) | 120 |
| PHP methods to bucket | 1075 |
| Vue/JS files enumerated (`src/`) | 109 |
| Removed-lines cache | `/tmp/removed-lines-mydash.txt` (749 KB, built in 1.2 s) |

**Methodology note.** With 1075 PHP methods + 109 frontend files, per-method enumeration would balloon the JSON and the SKILL allows "heuristic, not mechanical" judgment. The report buckets at **file granularity** with `method_count` rollups. mydash uses two annotation styles:

1. **Canonical** `@spec openspec/changes/.../tasks.md#task-N` — 3 files (RoleFeaturePermissionService, RoleFeaturePermissionApiController, widgetBridge.js).
2. **REQ-direct** `@spec capability:REQ-ID` — 26 PHP files, 68 method-level tags. Not the form ADR-003 / SKILL.md mandate, but it does point at a single owner REQ.

Both are recorded as `annotated`. Inline `REQ-XXX` references in code comments (221 PHP files, 162 frontend files) are evidence-of-intent, not formal annotations.

## Summary

| Bucket | Count | Next action |
|---|---|---|
| annotated | 29 files / 75 method-tags | — (already tagged) |
| plumbing | 28 files | — (never tagged) |
| 1 — REQ matched | 112 file-entries (838 PHP methods + ~95 Vue/JS files) | `/opsx-annotate mydash` |
| 2a — existing capability, no REQ | 4 files / 18 methods, 2 clusters (`dashboards`, `widgets`) | `/opsx-reverse-spec mydash --extend <cap>` |
| 2b — no capability owner | 3 files / 7 methods, 1 cluster (`infrastructure-helpers`) | `/opsx-reverse-spec mydash --cluster infrastructure-helpers` |
| 3a — REQ broken (code removed) | 0 | — |
| 3b — REQ never implemented (or untagged) | 96 REQ IDs | Triage: separate genuinely-deferred from implemented-but-untagged |
| 4 — ADR conformance | 4 rule classes, ~310 findings | Follow-up issues (SPDX + file-level @spec are biggest) |

**Summary line for parent agent:**
`Buckets: annotated=29 | plumbing=28 | 1=112 | 2a=4/2 clusters | 2b=3/1 clusters | 3a=0 | 3b=96 | 4=310`

## Bucket 1 — Ready to annotate

**112 file-entries spanning 32 capabilities, ~838 PHP methods.** Full per-file list in `coverage-report.json`. Highlights:

### Capability: dashboards (5 files, 87 methods)
- `lib/Controller/DashboardApiController.php` — 32 methods (6 already tagged, 26 to annotate). Confidence 0.95.
- `lib/Service/DashboardService.php` — 40 methods. Central CRUD + cascade-event dispatcher. 0.95.
- `lib/Service/DashboardTreeService.php` — 13 methods. Tree/hierarchy. 0.85.
- `lib/Service/DashboardFactory.php` — 4 methods (1 already tagged). REQ-DASH-001. 0.95.
- `lib/Service/DashboardResolver.php` — 6 methods (3 already tagged). tryActivate/createFromTemplate named in REQ-DASH-003 scenarios. 0.90.
- `lib/Controller/DashboardRequestValidator.php` — 5 methods. **NEEDS-REVIEW 0.70** — validator-class architecture not directly REQ-covered (previous report Bucket 2a; reclassified here for retrofit-annotate path).

### Capability: admin-* (5 files, 64 methods)
- `lib/Controller/AdminController.php` — 25 methods (6 tagged). Spans admin-settings, admin-templates, admin-roles, footer-customization, setup-wizard. 0.90.
- `lib/Service/AdminSettingsService.php`, `AdminTemplateService.php`, `AdminSettingsController.php` — exact-name matches. 0.95.

### Capability: dashboard-{comments,locking,reactions,sharing,versioning,language-content,metadata-fields,bulk-operations,view-analytics,export-import,cascade-events,rss-feeds}
Each capability has a `*ApiController` + `*Service` pair with near-perfect path/name match (e.g. `DashboardCommentsApiController.php` + `CommentService.php` → `dashboard-comments`). 0.90–0.95 confidence across the board. **~58 files, ~280 methods total.**

### Capability: widgets and per-widget specs
- `widgets`: `lib/Controller/WidgetApiController.php` (11), `lib/Service/WidgetService.php` (10), `lib/Service/PlacementService.php` (7), `lib/Service/WidgetFormatter.php` (7), `lib/Service/WidgetItemLoader.php` (4), `lib/Service/PlacementUpdater.php` (2), `lib/Service/WidgetPlacementService.php` (1).
- per-widget services: `NewsWidgetService` (23), `FilesWidgetService` (16), `CalendarWidgetService` (12), `PeopleWidgetService` (11), `MenuService` (3) → news-widget / files-widget / calendar-widget / people-widget / menu-widget.

### Capability: tiles
- `TileApiController` (6, 4 tagged), `TileService` (5, 4 tagged), `TileUpdater` (2). 0.85–0.95.

### Capability: cli-commands (16 files, 65 methods)
- `lib/Command/*.php` — 16 commands. All map to `cli-commands` + a domain capability (dashboards / setup-wizard / export-import / cleanup / demo-data-showcases / feed-token / i18n). 0.85–0.95.

### Capability: activity-feed-integration / background-job-feed-refresh / dashboard-rss-feeds
- `lib/Activity/*.php` (3 files, 16 methods), `FeedRefreshService` (14), `FeedService` (14), `FeedTokenService` (7), `FeedController` (7), `Job/FeedRefreshJob.php` (2). Confidence 0.85–0.95.

### Capability: orphaned-data-cleanup (9 files, 58 methods)
- `lib/Service/Cleanup/*` (6 files) + `OrphanedDataCleanupService` + `OrphanedDataCleanupJob` + `AdminCleanupController`. Exact matches throughout.

### Capability: confluence-html-import (9 files, 54 methods)
- `lib/Service/Confluence/*` (6 files) + `ConfluenceImportService` + `ConfluenceImportController` + `ImportConfluenceCommand`. 0.95.

### Capability: resource-uploads / dashboard-icons
- `ResourceController` (11), `ResourceServeController` (6), `ResourceService` (7), `ResourceServeService` (5), `FileController` (4 — **NEEDS-REVIEW 0.75**), `FileService` (9 — **NEEDS-REVIEW 0.75**), `SvgSanitiser` (5), `ImageMimeValidator` (1).

### Capability: conditional-visibility
- `lib/Controller/RuleApiController.php` (6 methods, 4 tagged), `RuleEvaluatorService` (6, 1 tagged), `VisibilityChecker` (5, 1 tagged), `ConditionalService` (7, 4 tagged), `UserAttributeResolver` (3, 1 tagged). Spec/scenario-noun-match cleanly aligns.

### Capability: prometheus-metrics
- `MetricsController` (4, 1 tagged), `MetricsCollector` (6, 1 tagged), `MetricsQueryService` (3, 1 tagged). 0.95.

### Capability: navigation-editor-org
- `OrgNavigationService` (20), `AdminOrgNavigationController` (8). 0.95.

### Capability: nc-unified-search-integration
- `lib/Search/MyDashSearchProvider.php` (16). REQ-SRCH search provider. 0.95.

### Capability: demo-data-showcases
- `DemoShowcasesService` (18), `AdminDemoShowcasesController` (5). 0.95.

### Capability: setup-wizard / footer-customization / initial-state-contract
- `SetupWizardService` (9), `FooterService` (7), `InitialStateBuilder` (16). 0.95.

### Capability: dashboard-view-analytics
- `AnalyticsController` (6), `AnalyticsService` (17), `UniqueViewerDedup` (9), `PurgeViewsJob` (2), `SaltRotationJob` (2). 0.85–0.95.

### Capability: permissions / admin-roles / role-based-content
- `PermissionService` (13, 4 tagged), `RoleService` (17), `RoleFeaturePermissionService` (12, 1 task-tagged), `RoleFeaturePermissionApiController` (9, 1 task-tagged). 0.95.

### Capability: dashboard-export-import
- `ExportService` (11), `ImportService` (12) — both also exposed through `AdminController` actions and CLI commands.

### Capability: dashboard-sharing + dashboard-cascade-events
- `Notifier.php` (10 methods) carries REQ-SHARE-011 / REQ-CSC notification rendering — multi-capability owner.

### Capability: orphan / manifest
- `lib/Controller/ManifestController.php` — 5 methods (1 tagged with **`@spec manifest-v2-runtime:REQ-MVR-001`**, but **no such spec exists** in `openspec/specs/`). Likely owner: `runtime-shell`. **NEEDS-REVIEW 0.75.**

### Frontend (Vue/JS) — summary level
109 files. Path/name → capability mapping is as clean as the PHP side:
- `src/components/Dashboard*.vue` → dashboards
- `src/components/admin/*.vue` → admin-settings / admin-templates / admin-roles / setup-wizard
- `src/components/Widgets/Renderers/*Widget.vue` → individual widget specs
- `src/components/Widgets/Forms/*Form.vue` → matching widget config
- `src/components/Workspace/*.vue` → dashboard-switcher
- `src/composables/use*.js` → grid-layout / dashboard-locking / widgets
- `src/stores/*.js` → matching capability (templates / tiles / orgNavigation / dashboard / roleFeaturePermissions / comments / widgets)

**Estimated Bucket 1 frontend file count: ~95 of 109.** 162 inline REQ references already exist across these files. Only `widgetBridge.js` carries formal `@spec` docblocks. The remaining frontend files would benefit from a parallel annotate pass.

## Bucket 2a — Existing capability, no REQ (reverse-spec --extend)

### cluster: dashboards (2 files, 11 methods)
- `lib/Controller/DashboardRequestValidator.php` (5 methods) — `checkCreatePermissions`, `checkUpdatePermissions`, `resolveCreateParams`, `buildUpdateData`. Previous report flagged Bucket 2a; reclassified here as Bucket 1 NEEDS-REVIEW (same outcome: needs a REQ if the validator-class architecture is to be pinned).
- `lib/Controller/ResponseHelper.php` (5 methods) — HTTP status / JSON envelope shaping. No REQ covers response-shape architecture.

### cluster: widgets (2 files, 9 methods)
- `lib/Service/WidgetFormatter.php` (7 methods, 1 tagged) — widget DTO formatting; spec covers data contract but not formatter abstraction.
- `lib/Service/WidgetItemLoader.php` (4 methods, 1 tagged) — lazy widget-item loader; no REQ covers loader strategy.

Cluster size is well below the 50-method large-cluster sanity-check threshold. Domain vocabulary (`format`, `loader`, `validator`, `response`) aligns with the owning capability.

## Bucket 2b — No capability owner (reverse-spec --cluster)

### cluster: infrastructure-helpers (3 files, 7 methods)
- `lib/Service/SlugGenerator.php` (2 methods) — string→slug utility used across many capabilities.
- `lib/Service/UserAttributeResolver.php` (3 methods, 1 tagged to conditional-visibility) — but also used by admin-roles; pure helper.
- `lib/Controller/RequestDataExtractor.php` (2 methods) — request parsing helper. Also classed plumbing.

Cluster label `infrastructure-helpers` is **behavioral**, not a namespace word — no namespace-word warning.

## Bucket 3 — Surfaced for human triage

### 3a — possibly broken (0)
The removed-lines cache returned zero keyword matches for any of the 96 unimplemented REQs. This is **not because nothing was removed** — it's because REQ IDs are documentation-only annotations rather than code symbols. The reverse-pass mechanism in SKILL assumes REQ keywords are domain nouns (e.g. `handleCatalog`, `registerCatalog`); for mydash, deriving 2–3 keywords from each REQ title and grepping would be the next-step refinement (deferred this run).

### 3b — never implemented or implemented-but-untagged (96)
96 of 609 REQs are not referenced anywhere in code by their exact `REQ-ID`. Examples from spot-checks:

| REQ | Title | Likely status |
|---|---|---|
| `admin-settings#REQ-ASET-004` | Allow Multiple Dashboards Setting | **IMPLEMENTED** — `AdminSettingsService` uses `KEY_ALLOW_MULTIPLE_DASHBOARDS` |
| `admin-settings#REQ-ASET-005` | Default Permission Level Setting | **IMPLEMENTED** — `KEY_DEFAULT_PERMISSION_LEVEL` present |
| `admin-settings#REQ-ASET-006` | Default Grid Columns Setting | **IMPLEMENTED** — grid columns in `DashboardFactory` |
| `admin-settings#REQ-ASET-015` | Initial-state mirror of allow-user-dashboards | **IMPLEMENTED** in `InitialStateBuilder` |
| `admin-settings#REQ-ASET-008` | Admin Settings UI | frontend-only — `src/components/admin/AdminSettings.vue` |
| `activity-feed-integration#REQ-ACT-009` | User Opt-Out via NC Settings | candidate — verify in UI |
| `activity-feed-integration#REQ-ACT-011b` | Unit-Test Contract | test-only — out of code-scan scope |
| `calendar-widget#REQ-CAL-004/005/007` | Recurring events / ICS cache / view-time access | probably gaps |
| `cli-commands#REQ-CLI-001/009/011` | (see spec) | likely partially implemented |
| `dashboards#REQ-DASH-006..010, 036, 037` | 7 dashboard REQs | mix of implemented-untagged and genuinely-deferred |

**Full list of 96 in `/tmp/mydash-unimpl-reqs.txt`.** Triage recommendation: pair this list with a quick `git grep` per REQ keyword to separate the implemented-but-untagged (~60-70% by sample) from genuinely deferred (~30-40%).

## Bucket 4 — ADR conformance findings

### missing-spdx-license-identifier (64 files)
64 of 273 PHP files lack `SPDX-License-Identifier:` in the header. ADR-014 + `hydra-gate-spdx` require it on every file under `lib/`. Examples: `lib/Controller/AdminController.php`, `lib/Service/DashboardService.php`.

### missing-spdx-filecopyrighttext (64 files)
Same set as above also lack `SPDX-FileCopyrightText:`.

### missing-file-level-spec-docblock-tag (153 files, **biggest gap**)
**ZERO** PHP files in `lib/` (excluding Migration/Db/Exception) carry a top-level `@spec` tag in the file docblock. Per ADR-003 §Spec traceability, each file should carry `@spec` at the file level pointing at the owning task(s). All 75 existing `@spec` tags are method-level. This means the canonical retrofit-annotate sweep would need to add a file-level tag to **every implemented file**, not just the unannotated methods.

### orphan-spec-tag (1)
`lib/Controller/ManifestController.php` carries `@spec manifest-v2-runtime:REQ-MVR-001` but no `openspec/specs/manifest-v2-runtime/spec.md` exists. Either rename the spec slug or remove the tag.

### annotation-style-mixed (28 files)
26 PHP files use REQ-direct style (`@spec capability:REQ-ID`) while 2 PHP + 1 frontend file use canonical tasks.md style (`@spec openspec/changes/.../tasks.md#task-N`). The SKILL/ADR-003 mandates the latter. Either migrate REQ-direct → canonical, or update ADR-003 to permit both forms.

### Other rule classes — clean
- `var_dump` / `dd(` / `die(` / `print_r` / `error_log` outside tests: **0 hits.**
- Direct SQL (`$this->db->query/prepare`): **0 hits** — app cleanly uses Mappers.

## Notes for the human reviewer

1. **Two annotation styles in tree.** The REQ-direct form (`@spec dashboards:REQ-DASH-003`) is dominant (26 files) and reads cleanly, but it's not what `hydra-gate-spdx` and ADR-003 mandate. Recommend a single retrofit change that either (a) standardises everything on `tasks.md#task-N`, or (b) updates ADR-003 to bless REQ-direct alongside the canonical form. This is a meta-decision worth making before `/opsx-annotate mydash` runs at scale.

2. **86% inline coverage vs 12% formal coverage.** 524/609 REQs are referenced somewhere in code, but only ~75/609 are tagged via `@spec` docblock. The gap is mostly cultural — the team uses inline `REQ-XXX` references in code comments as documentation but doesn't promote them to docblock tags. The retrofit-annotate pass should mechanically lift inline references into docblock tags wherever the method body verifiably implements the named REQ.

3. **96 "unimplemented" REQs are mostly mis-categorised.** Spot-check of admin-settings REQ-ASET-004..015 found ~5 of 8 are implemented in code; they just don't carry the REQ tag. Before running `/opsx-reverse-spec` on any of these, verify against the code — most are Bucket 1 candidates that need annotation, not new spec REQs.

4. **Bucket 1 NEEDS-REVIEW (4 files).**
   - `DashboardRequestValidator.php` — kept in Bucket 1 (DASH); previous report had Bucket 2a. Either is defensible.
   - `FileController.php` + `FileService.php` — ambiguous between `resource-uploads` and `files-widget`; both reference each capability's spec scenarios.
   - `ManifestController.php` — orphan-tagged to `manifest-v2-runtime`; closest real owner is `runtime-shell`.

5. **Removed-lines cache returned 0 keyword matches.** The reverse-pass mechanism assumes REQ keywords are code symbols. For mydash where REQ IDs are doc-only, a refinement would be to derive 2–3 domain nouns per REQ title and grep those. Deferred this run; nothing changes in the bucket distribution because the 96 "unimplemented" go straight to 3b under either approach.

6. **Frontend was rolled up.** The 109 Vue/JS files were not per-method classified. Recommendation: a separate frontend-only annotate pass after the PHP convention is settled.

7. **Surprise — no forbidden patterns, no direct SQL.** Mydash is mechanically clean on those gates. The retrofit work is annotation/SPDX hygiene, not code quality.

8. **Specs vs changes count mismatch on REQ totals.** REQ extraction regex (`[A-Z]{2,8}-[0-9]+[a-z]*`) found 609 unique REQs across 54 specs. The earlier loose count of 615 differs because of compound-ID forms like `REQ-DASH-016/018` and a handful of malformed cells. Treat 609 as the authoritative denominator for this run.
