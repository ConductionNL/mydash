# Group routing — pick primary group for a user

## Why

When a user opens the workspace page, MyDash MUST decide which Nextcloud group's dashboards they belong to. Many users belong to multiple groups; today there is no deterministic priority and individual call sites would each invent their own ordering, leading to drift between the workspace renderer, the dashboard resolver, and any future caller. This change locks the algorithm into a single pure resolver inside `admin-templates` (which already owns the group-targeting concept via REQ-TMPL-010) and forbids parallel implementations.

The existing REQ-TMPL-010 ("Template Group Resolution") already covers picking the right template for a user via group matching, but only for the templating model. This change extends the algorithm to govern routing into `group_shared` dashboards (from `multi-scope-dashboards`) using the same `group_order` admin setting — one source of truth for both pathways.

## What Changes

- Add a pure function `AdminTemplateService::resolvePrimaryGroup(string $userId): string` that walks the admin-configured `group_order` setting and returns the first matching group ID OR the literal sentinel `'default'` (the same sentinel introduced by `multi-scope-dashboards` REQ-DASH-012).
- Read `group_order` (JSON list of group IDs, default `[]`) from `oc_mydash_admin_settings` via `AdminSettingsService::getGroupOrder(): array`.
- Resolve the user's actual group memberships via `IGroupManager::getUserGroupIds`.
- Tolerate stale entries in `group_order` (deleted groups) without throwing — cleanup is the admin UI's responsibility.
- Wire the resolver into `WorkspaceController::index` so the workspace renderer asks one place "what's this user's primary group?".
- Refactor REQ-DASH-013 + REQ-DASH-018 implementations to consume this resolver instead of inlining the lookup; enforce the single-source-of-truth rule via a grep-based PHPUnit guard.
- Surface the resolved primary group plus its display name to the frontend as `primaryGroup` initial state (display name comes from `IGroupManager::get($id)?->getDisplayName()`, or the literal `'Default'` for the sentinel).

This change is strictly the read-side of the admin's `group_order` setting. The write-side (admin UI for ordering groups) ships in the parallel `group-priority-order` change.

## Capabilities

### New Capabilities

(none — this change folds into the existing `admin-templates` capability)

### Modified Capabilities

- `admin-templates`: adds REQ-TMPL-012 (primary-group resolution algorithm) and REQ-TMPL-013 (resolver is the single routing authority). Existing REQ-TMPL-001..011 are untouched.

The `dashboards` capability is intentionally not modified — REQ-DASH-013 and REQ-DASH-018 already own the visible-to-user resolution; this change only wires their implementations to consume the new resolver instead of duplicating the algorithm.

## Impact

**Affected code:**

- `lib/Service/AdminTemplateService.php` — new `resolvePrimaryGroup(string $userId): string` method (read-only, pure)
- `lib/Service/AdminTemplateService.php` — new internal helper `pickFirstMatch(array $orderedGroups, array $userGroups): ?string` for testability
- `lib/Service/AdminSettingsService.php` — new `getGroupOrder(): array` accessor
- `lib/Controller/WorkspaceController.php` — calls the resolver in `index()` and passes the result into dashboard resolution
- `lib/Db/Dashboard.php` — add `Dashboard::DEFAULT_GROUP_ID = 'default'` constant so the sentinel is named in one place
- `src/views/Workspace.vue` (or equivalent shell) — receives `primaryGroup` and `primaryGroupDisplayName` in initial state from `runtime-shell`

**Affected APIs:**

- No new HTTP endpoints. Pure backend resolver consumed by existing controllers; the result is exposed through the existing initial-state payload that `runtime-shell` already ships.

**Dependencies:**

- `OCP\IGroupManager` — already injected elsewhere, used for `getUserGroupIds` and (frontend-side) display-name lookup
- No new composer or npm dependencies

**Migration:**

- Zero schema impact: the resolver only reads existing `oc_mydash_admin_settings` rows. If `group_order` is missing or empty, the resolver returns `'default'` and the existing behaviour is preserved.
- No data backfill required.
