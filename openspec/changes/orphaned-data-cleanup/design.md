# Design — Orphaned Data Cleanup

## Context

MyDash installations accumulate orphaned data as dashboards are deleted, users are removed, features are disabled, and operations fail mid-transaction. Examples:

- Locks older than 15 minutes (normal timeout window)
- Share tokens referencing deleted dashboards (dangling foreign key)
- Widget placements orphaned when a dashboard is deleted
- Role assignments for users that no longer exist
- Metadata-value rows whose field definitions were deleted (optional feature)
- Feed tokens for users that no longer exist (optional feature)
- Translations for dashboards that were deleted (optional feature)

Administrators currently have no way to detect and safely remove this debris without running raw SQL. This change introduces a comprehensive, auditable, extensible cleanup system.

## Goals / Non-Goals

**Goals:**
- Expose scan capability (non-destructive, lists all orphans by category)
- Expose purge capability (destructive, with dry-run safety, confirmation, and per-category filtering)
- Support automatic daily purge of Tier-A categories (zero-risk items)
- Log all real purges as activity events for audit trails
- Cache scan results (5-minute TTL) to reduce database load
- Implement via a registry pattern — adding a new category requires only one new class
- Support optional features gracefully (categories report `isAvailable() === false` on missing tables)
- Tie-in to existing permission model (admin-only for API/CLI)

**Non-Goals:**
- Do NOT support undo/rollback of purges — admins MUST use dry-run first
- Do NOT rewrite existing data models — work with current schema
- Do NOT change the distribution or template instantiation logic
- Do NOT introduce new concepts like "purge presets" or "manual approval workflows"
- Do NOT change the background job frequency (24 hours is the standard Nextcloud cycle)

## Decisions

### D1: Registry pattern via constructor injection

**Decision:** A single `CategoryRegistryService` collects all cleanup categories via Symfony constructor dependency injection. Each category implements `CleanupCategoryInterface`. The orchestrator (`OrphanedDataCleanupService`) asks the registry for available categories and invokes them in order.

**Alternatives considered:**

- **Hardcoded category list in orchestrator**: Rejected — violates open/closed principle. Adding a new feature with a new orphan type would require editing the orchestrator, creating merge-conflict risk and coupling cleanup logic to each feature.
- **Service locator / string-based lookup**: Rejected — no type safety, error-prone category name strings.
- **Event-based subscription (PSR-14)**: Rejected — overkill for a bounded domain with a predictable list of categories.

**Rationale:** Constructor injection is idiomatic Nextcloud/Symfony. The registry is the single place where all categories are enumerated; each category is a standalone class with no knowledge of the others. This is the definition of extensibility.

### D2: Three-tier safety classification

**Decision:** Categories are grouped into three tiers:

1. **Tier-A (Auto-safe)**: expired_locks, expired_share_tokens — no user-visible impact; safe to auto-purge daily without review
2. **Tier-B (Manual-safe)**: orphaned_widget_placements, orphaned_conditional_rules, orphaned_widget_assets, orphaned_metadata_values, orphaned_feed_tokens, dangling_dashboard_translations — safe to purge but require manual review first (dry-run, data inspection)
3. **Tier-C (Inspect-first)**: orphaned_role_assignments — role-based permissions at stake; MUST be reviewed before any purge

Each category exposes its tier via `getSafeToPurgeAutomatically(): bool`. The daily background job defaults to Tier-A; admins can override via config. The CLI/API support all three tiers.

**Rationale:** Default-safe reduces risk; manual review gates higher-risk operations. The config override allows advanced deployments to enable more aggressive auto-purge after validation.

### D3: Dry-run wrapped in transaction rollback

**Decision:** `--dry-run` executes all deletion queries inside a transaction that is rolled back before commit. The response shows "DRY-RUN: Would purge N items..." with identical counts to a real purge, leaving all data untouched.

**Alternatives considered:**

- **Dry-run as a separate code path (no DB access)**: Rejected — does not catch logic bugs or permission denials that would occur in a real purge.
- **Copy-on-write snapshots**: Rejected — overkill, non-portable across DBMS.

**Rationale:** Transaction rollback is portable, simple, and guarantees exact fidelity — the counts returned are the same as a real purge would achieve. Admins can trust dry-run output to predict real behavior.

### D4: Single activity event per purge operation

**Decision:** A purge (CLI, API, or background job) that deletes N > 0 items emits exactly one Nextcloud Activity event with structured metadata: `totalRows`, `byCategory` (object mapping category name to count), `durationMs`, `source` (one of 'cli', 'api', 'job'). Dry-run purges emit NO activity event.

**Alternatives considered:**

- **One event per category**: Rejected — too noisy; admin logs get flooded with 9 lines per nightly job.
- **No event for background job, event only for CLI/API**: Rejected — inconsistent; admins cannot audit the full cleanup history.
- **Event emitted per row deleted**: Rejected — would create thousands of events for a large purge.

**Rationale:** One event per operation is the Nextcloud standard for batch operations. The structured metadata (byCategory breakdown) preserves audit detail without log noise.

### D5: Distributed cache with 300-second TTL

**Decision:** Full scan results (no category filter) are cached in `ICacheFactory::createDistributed('mydash.cleanup.scan')` for 300 seconds. Partial scans (with non-empty category name filters) bypass the cache entirely. Cache is invalidated on any successful (non-dry-run) purge.

**Alternatives considered:**

- **No caching**: Rejected — scan across 9 tables on a large install can take 1-2 seconds. Repeated quick checks (e.g., admin dashboard reloading every 30s) create unnecessary load.
- **Longer TTL (1 hour)**: Rejected — stale counts confuse admins; 5-minute window balances freshness and load reduction.
- **Cache partial scans**: Rejected — partial-scan cache keys grow unbounded as admins filter by different categories; distributed cache is not the right tool for that complexity.

**Rationale:** Distributed cache handles multi-server deployments. 300s is a reasonable balance. Invalidation on real purge means admins see the impact immediately after cleanup.

### D6: Optional category availability checking

**Decision:** Categories for optional features (dashboard-metadata-fields, dashboard-rss-feeds, admin-roles, dashboard-language-content) report `isAvailable() === false` when the feature is not installed or its schema tables are missing. The orchestrator skips unavailable categories silently. Scan output lists skipped categories under a comment.

**Alternatives considered:**

- **Throw exception if a category's table is missing**: Rejected — installation in progress (feature disabled mid-update) should not hard-fail the cleanup system.
- **Scan only "always available" categories by default**: Rejected — if a feature IS installed, its orphans MUST be scanned; inconsistency undermines trustworthiness.

**Rationale:** Graceful skipping makes cleanup resilient to partially-installed features. The scan output transparency tells admins what was checked and what was skipped.

### D7: Per-category filtering with empty array = all categories

**Decision:** The purge API accepts `{"categories": [...]}`. An empty array `[]` is interpreted as "all registered categories". Unknown category names yield HTTP 400 with a list of valid categories.

**Alternatives considered:**

- **Empty array = no-op**: Rejected — less useful; forces clients to fetch the category list separately.
- **Omitted categories field = all, empty array = none**: Rejected — ambiguous; clients must guess the intent.

**Rationale:** Empty array = all is explicit and predictable. Unknown categories fail fast with guidance.

### D8: Caching + dry-run interaction

**Decision:** Dry-run purges do NOT invalidate cache (because they delete nothing). Real purges (dryRun=false) invalidate cache immediately. This allows admins to do dry-run → cache hit on next scan → real purge → fresh scan, without performance surprises.

**Rationale:** Cache coherency is guaranteed because the only source of truth is the real purges. Dry-run is a read-like operation; it should not change cache state.

## Seed data (design examples)

Example database state for testing:

**oc_mydash_dashboards** (3 user dashboards, 2 templates):
- id=1, uuid='dash-user-alice', type='user', userId='alice', name='Work', gridColumns=4, ...
- id=2, uuid='dash-user-bob', type='user', userId='bob', name='Home', gridColumns=3, ...
- id=3, uuid='dash-user-carol', type='user', userId='carol', name='Deleted-ref', gridColumns=4, basedOnTemplate=999, ...
- id=4, uuid='tmpl-engineering', type='admin_template', userId=null, name='Engineering Startup', ...
- id=5, uuid='tmpl-marketing', type='admin_template', userId=null, name='Marketing Blitz', ...

**oc_mydash_dashboard_locks** (2 current, 1 expired):
- id=1, dashboardId=1, userId='charlie', updatedAt='2026-05-21 10:00:00' (5 min old, active)
- id=2, dashboardId=2, userId='dave', updatedAt='2026-05-21 09:00:00' (75 min old, EXPIRED)
- id=3, dashboardId=2, userId='eve', updatedAt='2026-05-20 10:00:00' (1 day old, EXPIRED)

**oc_mydash_dashboard_shares** (1 valid, 2 orphaned):
- id=1, dashboardId=1, token='share-valid-1', ... (dashboard 1 exists)
- id=2, dashboardId=999, token='share-orphan-1', ... (dashboard 999 does not exist)
- id=3, dashboardId=888, token='share-orphan-2', ... (dashboard 888 does not exist)

**oc_mydash_widget_placements** (3 valid, 1 orphaned):
- id=1, dashboardId=1, widgetId='cal', position=0, ...
- id=2, dashboardId=1, widgetId='weather', position=1, ...
- id=3, dashboardId=2, widgetId='notes', position=0, ...
- id=4, dashboardId=777, widgetId='rss', position=0, ... (dashboardId=777 does not exist)

**oc_mydash_conditional_rules** (1 valid, 1 orphaned):
- id=1, widgetPlacementId=1, condition='...', ...
- id=2, widgetPlacementId=999, condition='...', ... (placementId=999 does not exist)

**oc_users** (2 users):
- uid='alice', displayName='Alice', ...
- uid='bob', displayName='Bob', ...

**oc_mydash_role_assignments** (2 valid, 1 orphaned):
- id=1, roleId='editor', userId='alice', grantedAt='...', ...
- id=2, roleId='viewer', userId='carol', grantedAt='...', ... (carol does not exist in oc_users)

Expected scan result:
```
Category                               Count
expired_locks                              2
expired_share_tokens                       2
orphaned_widget_placements                 1
orphaned_conditional_rules                 1
orphaned_role_assignments                  1
[others at 0]
────────────────────────────────────────
TOTAL                                      7
```

## Open follow-ups

- Selective rollback per category on partial failure (if one category errors, continue others or fail all?)
- Scheduled job time customization (24h is default; can admins change it?)
- Bulk restore from backups (cleanup has no undo; admins must restore from DB backup if a real purge was wrong)
- Dashboard-level audit log viewer (show "cleanup removed 5 orphans on 2026-05-21" to admins in UI)
