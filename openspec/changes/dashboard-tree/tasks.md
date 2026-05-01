# Tasks — dashboard-tree

## 1. Schema migration

- [ ] 1.1 Create `lib/Migration/VersionXXXXDate2026...AddHierarchyColumns.php` adding `parentUuid VARCHAR(36) NULL`, `slug VARCHAR(128) NULL`, `sortOrder INT DEFAULT 0` to `oc_mydash_dashboards`
- [ ] 1.2 Add composite unique index `idx_mydash_dash_parent_slug` on `(parentUuid, slug)` — siblings must have unique slugs per parent
- [ ] 1.3 Add index `idx_mydash_dash_parent` on `(parentUuid)` for fast child lookups
- [ ] 1.4 Add index `idx_mydash_dash_sort` on `(parentUuid, sortOrder)` for ordered sibling retrieval
- [ ] 1.5 Confirm migration is reversible (drop columns + indexes in `postSchemaChange` rollback path)
- [ ] 1.6 Run migration locally against sqlite, mysql, and postgres; verify schema applied cleanly each time

## 2. Domain model

- [ ] 2.1 Add `Dashboard::MAX_DEPTH = 5` constant (root + 4 descendants)
- [ ] 2.2 Add `parentUuid`, `slug`, `sortOrder` fields to `Dashboard` entity with getters/setters (Entity `__call` pattern — no named args)
- [ ] 2.3 Add computed getter `getDashboardPath(): string` returning slash-joined slug chain from root to this dashboard (e.g., `/marketing/campaigns/q1`)
- [ ] 2.4 Add computed getter `getBreadcrumbs(): array` returning ordered list of `{uuid, name, slug}` from root to this dashboard (requires mapper queries up the tree)
- [ ] 2.5 Update `Dashboard::jsonSerialize()` to include `parentUuid`, `slug`, `sortOrder` (all nullable/integer as-is; `path` and `breadcrumbs` are computed on-demand, not serialized by default)

## 3. Mapper layer

- [ ] 3.1 Add `DashboardMapper::findByParent(?string $parentUuid): array` — `WHERE parentUuid = ? ORDER BY sortOrder, name` (pass null for root-only dashboards)
- [ ] 3.2 Add `DashboardMapper::findByPath(string $path): ?Dashboard` — split path by `/`, walk down the tree via successive parent lookups
- [ ] 3.3 Add `DashboardMapper::findDescendants(string $ancestorUuid): array` — recursive traversal (or iterative with stack) to fetch all descendants of a given dashboard
- [ ] 3.4 Add `DashboardMapper::findAncestors(string $uuid): array` — reverse traversal to fetch all parents up to root
- [ ] 3.5 Add `DashboardMapper::countChildrenByParent(string $parentUuid): int` — used in cascade delete guard
- [ ] 3.6 Add fixture-based PHPUnit test covering: tree with 5 levels (max depth), path resolution, cycle detection, dedup edge cases

## 4. Service layer — validation

- [ ] 4.1 Add `TreeService::validateNoCycle(string $uuid, ?string $newParentUuid): void` — checks if setting parent to `newParentUuid` would create a loop (i.e., `newParentUuid` is a descendant of `uuid`); throws `\InvalidArgumentException` if true
- [ ] 4.2 Add `TreeService::validateDepth(?string $parentUuid): void` — counts ancestors of `parentUuid` and throws `\InvalidArgumentException` if adding a child would exceed MAX_DEPTH
- [ ] 4.3 Update `DashboardFactory::create()` to auto-generate `slug` from `name` if not supplied; slug must be alphanumeric + dash/underscore, lowercased, max 128 chars
- [ ] 4.4 Add validation in `DashboardFactory::create()` to reject `parentUuid` if it points to a non-existent dashboard; throw `\InvalidArgumentException`
- [ ] 4.5 When `parentUuid` is updated via PUT, call `validateNoCycle()` and `validateDepth()` guards before persisting

## 5. Service layer — tree and path

- [ ] 5.1 Add `TreeService::buildTree(?string $parentUuid): array` — recursively fetch and nest children, returning `{uuid, name, slug, children: [...]}` structure
- [ ] 5.2 Add `TreeService::getFullTree(): array` — build complete tree from all root dashboards (WHERE parentUuid IS NULL)
- [ ] 5.3 Add `TreeService::resolvePath(string $path): ?Dashboard` — delegates to `DashboardMapper::findByPath()`
- [ ] 5.4 Add `TreeService::computePath(string $uuid): string` — fetch ancestors and join slugs, e.g., `/marketing/campaigns/q1`
- [ ] 5.5 Add `TreeService::computeBreadcrumbs(string $uuid): array` — fetch ancestors and return `{uuid, name, slug}` list from root to this dashboard
- [ ] 5.6 Ensure all path operations are user-scoped (only dashboards owned/visible by the current user are included)

## 6. Service layer — cascade delete

- [ ] 6.1 Update `DashboardService::deleteDashboard()` to check if dashboard has children
- [ ] 6.2 If children exist and `?cascade=true` query param is NOT present, return HTTP 409 with `{error: "Dashboard has N children", childCount: N}`
- [ ] 6.3 If `?cascade=true` is present, recursively delete all descendants (placements cascade-deleted per existing REQ-DASH-005)
- [ ] 6.4 Add `TreeService::deleteSubtree(string $uuid, bool $cascade): void` — recursive deletion logic
- [ ] 6.5 Add PHPUnit test: delete dashboard with 3 descendants, no cascade → HTTP 409; delete with cascade=true → all 4 deleted

## 7. Controller + routes

- [ ] 7.1 Add `DashboardController::tree()` mapped to `GET /api/dashboards/tree` (logged-in user, `#[NoAdminRequired]`) — returns nested tree of all visible dashboards
- [ ] 7.2 Add `DashboardController::byPath(string $path)` mapped to `GET /api/dashboards/by-path/{*path}` (logged-in user, `#[NoAdminRequired]`) — resolves path to dashboard, returns dashboard object with computed breadcrumbs
- [ ] 7.3 Register both routes in `appinfo/routes.php` with proper requirements (`path` regex allows `/` separators)
- [ ] 7.4 Both endpoints return 404 if path/tree is empty for the user (no visible dashboards)

## 8. Frontend store

- [ ] 8.1 Extend `src/stores/dashboards.js` with `dashboardTree` getter returning nested tree structure from `/api/dashboards/tree`
- [ ] 8.2 Add `breadcrumbs` getter returning computed breadcrumbs for the active dashboard from `/api/dashboard` response (if endpoint includes them; otherwise fetch on demand)
- [ ] 8.3 Add `dashboardByPath(path)` getter that resolves via `/api/dashboards/by-path/{path}` or caches the result
- [ ] 8.4 Defer advanced tree UI (collapsible folders, drag-to-reorder) to follow-up change — this change only ships the backend + store scaffolding

## 9. Slug auto-generation

- [ ] 9.1 Create helper `SlugGenerator::slugify(string $name): string` — lowercases, replaces spaces with `-`, removes non-alphanumeric (except `-/_`), max 128 chars
- [ ] 9.2 In `DashboardFactory::create()`, if `slug` is not supplied, call `SlugGenerator::slugify($name)` to auto-generate
- [ ] 9.3 On `PUT /api/dashboard/{id}`, if `slug` is supplied, accept it; if omitted, preserve existing slug (do not re-slug on name change)
- [ ] 9.4 Add `DashboardService::validateSlugUnique(?string $parentUuid, string $slug, ?string $excludeUuid): void` — ensures no sibling has the same slug; pass `$excludeUuid` when updating to allow keeping current slug

## 10. PHPUnit tests

- [ ] 10.1 `TreeServiceTest::buildTree` — 5-level tree, verify nesting and sort order
- [ ] 10.2 `TreeServiceTest::resolvePath` — resolve `/marketing/campaigns/q1`, return correct dashboard
- [ ] 10.3 `TreeServiceTest::computePath` — compute path from a deep descendant, verify ancestors resolved
- [ ] 10.4 `TreeServiceTest::validateNoCycle` — attempt to set a descendant as parent, verify exception
- [ ] 10.5 `TreeServiceTest::validateDepth` — attempt to add child at max depth, verify exception
- [ ] 10.6 `DashboardControllerTest::testTreeEndpoint` — `/api/dashboards/tree` returns nested structure with 3+ dashboards
- [ ] 10.7 `DashboardControllerTest::testByPathEndpoint` — `/api/dashboards/by-path/marketing/campaigns/q1` returns correct dashboard, includes breadcrumbs
- [ ] 10.8 `DashboardControllerTest::testDeleteCascadeGuard` — delete with children, no cascade param → HTTP 409; cascade=true → deleted
- [ ] 10.9 `DashboardMapperTest::findByParent` — root dashboards (parentUuid = NULL), child dashboards, sort order
- [ ] 10.10 Test all 10 existing REQ-DASH-001..010 requirements still pass (regression — hierarchy must not break flat dashboard operations)

## 11. End-to-end Playwright tests

- [ ] 11.1 User creates dashboard "Marketing", then creates child "Q1 Campaigns" with parent set to "Marketing" UUID
- [ ] 11.2 GET `/api/dashboards/by-path/marketing/q1-campaigns` returns the child dashboard with correct breadcrumbs
- [ ] 11.3 GET `/api/dashboards/tree` returns nested structure with "Marketing" as parent of "Q1 Campaigns"
- [ ] 11.4 User attempts to set a descendant as parent, receives HTTP 400
- [ ] 11.5 User attempts to delete "Marketing" (which has 2 children) without cascade, receives HTTP 409; with cascade=true, all 3 deleted
- [ ] 11.6 Path computation correctly reflects re-parenting (change parent, verify path updates on next page load)

## 12. Quality gates

- [ ] 12.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered along the way
- [ ] 12.2 ESLint + Stylelint clean on all touched Vue/JS files
- [ ] 12.3 Update generated OpenAPI spec / Postman collection so external API consumers see the new endpoints
- [ ] 12.4 `i18n` keys for all new error messages (`Dashboard has children`, `Cycle detected`, `Maximum depth exceeded`, `Slug must be unique`) in both `nl` and `en` per the i18n requirement
- [ ] 12.5 SPDX headers on every new PHP file (inside the docblock per the SPDX-in-docblock convention) — gate-spdx must pass
- [ ] 12.6 Run all 10 `hydra-gates` locally before opening PR
