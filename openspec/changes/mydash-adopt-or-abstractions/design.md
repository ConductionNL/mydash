# Design — mydash-adopt-or-abstractions

## Reuse analysis

| Capability | Reuse from | Why |
|------------|-----------|-----|
| App-manifest schema | `@conduction/nextcloud-vue` `src/schemas/app-manifest.schema.json` | Single source of truth per `hydra/openspec/changes/adopt-app-manifest/`. MyDash MUST NOT fork or extend the schema in-tree — extensions go upstream. |
| Manifest loader | `useAppManifest()` from nc-vue | Sync bundled load + async backend merge already implemented. Tier 1 only needs the import. |
| Manifest validator | `validateManifest()` from nc-vue | Wired through `npm run check:manifest`; CI already calls `npm run lint`. |
| Tenant context | `useTenantContext()` from nc-vue (pending `multi-tenancy-context` change) | When it ships, MyDash widgets reuse it instead of rolling org-scope logic. |
| OR feature detect | `useAppStatus()` from nc-vue | Wraps `OCS /apps` enabled/installed status. New `useOrFeatureDetect()` composable is a thin façade so widget code reads `or.enabled.value` rather than `useAppStatus('openregister').enabled`. |
| OR data access (runtime only) | OR REST `/index.php/apps/openregister/api/...` and OR GraphQL endpoint | No PHP-side coupling; runtime only. Widgets that need OR data fetch through the existing browser session (CSRF + cookie). |
| Permission model on dashboards | MyDash own `oc_mydash_dashboards.permissions` column | Local concept (view_only / add_only / full) predates OR's per-object RBAC. Keep local; optional runtime delegation. |

### What we deliberately do NOT reuse

- **OR's `RegisterResolverService`** — MyDash has no PHP-side OR
  consumption, so consolidating `getValueString(...register/schema)`
  patterns is N/A. The audit's recommendation #5 lists opencatalogi (5
  controllers) and pipelinq (8 services) — MyDash is not on that list.
- **OR's lifecycle annotations** — MyDash dashboards are not
  schema-driven and do not have a state machine. Lifecycle annotations
  belong in apps with `processing/done/error` style status fields.
- **OR's `Translation` table** — MyDash translations remain in
  Nextcloud's `IL10N` system (`l10n/{nl,en}.js`). MyDash does not
  store translatable user-authored content; widget labels are
  developer-authored.
- **Hydra's per-app `adr-000`** — per ADR-022, apps MUST NOT carry an
  ADR that re-asserts cross-app conventions. MyDash already does not
  have an `adr-000` and this change does NOT introduce one.

## Public API / migration shape

### `src/manifest.json` (new file)

```json
{
  "$schema": "https://unpkg.com/@conduction/nextcloud-vue@latest/dist/schemas/app-manifest.schema.json",
  "version": "0.1.0",
  "dependencies": [],
  "menu": [
    {
      "id": "dashboards",
      "label": "mydash.menu.dashboards",
      "icon": "icon-dashboard",
      "route": "/dashboards",
      "section": "main"
    },
    {
      "id": "admin-templates",
      "label": "mydash.menu.adminTemplates",
      "icon": "icon-template",
      "route": "/admin/templates",
      "section": "settings",
      "permission": "admin"
    },
    {
      "id": "admin-settings",
      "label": "mydash.menu.adminSettings",
      "icon": "icon-settings",
      "route": "/admin/settings",
      "section": "settings",
      "permission": "admin"
    }
  ],
  "pages": [
    {
      "id": "dashboard-detail",
      "route": "/dashboards/:id",
      "type": "dashboard",
      "title": "mydash.pages.dashboard",
      "config": {
        "widgets": "@runtime",
        "layout": "@runtime"
      }
    },
    {
      "id": "admin-templates-index",
      "route": "/admin/templates",
      "type": "index",
      "title": "mydash.pages.adminTemplates",
      "config": {
        "register": null,
        "schema": null,
        "source": "mydash:oc_mydash_admin_settings",
        "columns": ["key", "value", "scope", "updated"]
      }
    },
    {
      "id": "admin-settings",
      "route": "/admin/settings",
      "type": "custom",
      "title": "mydash.pages.adminSettings",
      "component": "AdminSettingsPage"
    }
  ]
}
```

Notes:
- `config.source: "mydash:oc_mydash_admin_settings"` is a MyDash-local
  source URN. The renderer treats it opaquely; only the
  `AdminTemplatesIndexAdapter` MyDash registers via `customComponents`
  understands it.
- `config.{widgets, layout}: "@runtime"` is a sentinel meaning "fetch
  from MyDash backend per-dashboard". The dashboard page-type
  resolver (existing GridStack stack) consumes the dashboard ID from
  the route and ignores the manifest config payload.

### `src/composables/useOrFeatureDetect.js` (new file)

Thin façade over nc-vue's `useAppStatus`. Returns
`{ enabled: ComputedRef<boolean>, version: ComputedRef<string|null>,
error: ComputedRef<Error|null> }`. Used by every OR-backed widget
before any `axios.get('/index.php/apps/openregister/...')` call.

### `src/main.js` (edit, additive)

```js
import bundledManifest from './manifest.json'
import { useAppManifest } from '@conduction/nextcloud-vue/composables'

useAppManifest('mydash', bundledManifest)
```

The vue-router definition stays as-is for Tier 1. Tier 3 (follow-up
change) replaces hand-wired routes with manifest-driven routes.

### Spec rewrites

`openspec/specs/dashboard-sharing/spec.md` — full rewrite. New
requirements:

- **Permission levels** (`view_only` / `add_only` / `full`) live on
  `oc_mydash_dashboards.permissions`.
- **Runtime OR delegation (OPTIONAL)** — when a dashboard's
  `permissions.delegate` field references an OR-backed object, MyDash
  MAY at render time call `OR /api/objects/{id}/can?action={read|write}`
  and AND the result with the local permission. If OR is absent the
  delegation is silently skipped (degrades to local check).
- **MUST NOT** declare an install-time dependency on OR.

`openspec/specs/admin-templates/spec.md` — full rewrite. New
requirements:

- Templates persist in `oc_mydash_admin_settings`.
- **MUST NOT** persist in OR.
- Templates are exported / imported as JSON files (FILENAME_PATTERN
  enforces the safe-name regex from Phase 5.3).

### Migration risk surface

| Risk | Mitigation |
|------|-----------|
| Manifest validation fails CI on first introduction (typos, schema drift) | Tier 1 keeps router hand-wired; failed manifest validation does NOT take the app down. Validator runs at build time. |
| Backend `/api/manifest` endpoint returns 200 but with malformed data | nc-vue loader logs `console.warn` and falls back to bundled. No runtime crash. |
| Operator runs MyDash without OR (intended path) | `useOrFeatureDetect()` returns `enabled.value === false`; widgets render empty state. Smoke tested in Phase 8.2. |
| Operator runs MyDash with OR but tenant context absent | `useTenantContext()` (when shipped) exposes `null`; widgets render OR data without tenant filter (server still enforces per-session). |
| Backwards compat with existing dashboards using GridStack | Untouched. Manifest's `type: "dashboard"` page-type adapter dispatches to the existing GridStack stack. |
| Constants rename in Phase 5.2 breaks BC | Old constants kept as aliases; no consumers external to MyDash. |

## Open design questions

1. **Q1 — Page-type for the dashboard editor.** The Tier 1 manifest
   uses `type: "dashboard"` for the *render* path. The dashboard
   editor (drag-and-drop + widget palette) is a different surface.
   Should it be a separate page (`type: "custom"`,
   `component: "DashboardEditor"`) or an `actionsComponent` slot
   override on the same `dashboard-detail` page? Recommend separate
   page; an editor's URL needs its own deep-link.

2. **Q2 — Backend `/api/manifest` endpoint.** Per
   `hydra/openspec/changes/adopt-app-manifest/`, every app MAY
   implement `GET /index.php/apps/{appId}/api/manifest` to return
   admin-customised overrides (menu order, hidden pages). MyDash has
   no admin-customisable menu today. Does this change scope adding
   the endpoint as a stub returning 404, or skip until there's a
   product reason? Recommend skip; the loader silently falls back on
   404.

3. **Q3 — `useTenantContext()` adoption timing.** The composable
   ships in `nextcloud-vue/openspec/changes/multi-tenancy-context/`
   which is merged in nc-vue PR #113 but not yet released as a
   versioned package consumers can pin. Should this change pin the
   nc-vue version that includes it (potentially a patch version), or
   add the wiring conditionally? Recommend conditional wiring: import
   guarded with `try { ... } catch { /* not yet available */ }` until
   the version is released, then make it unconditional.

4. **Q4 — Permission delegation default.** If an OR-backed object is
   referenced in `permissions.delegate`, should delegation default to
   ON (any OR-aware install gets cross-system perms automatically) or
   OFF (operator opts in)? Recommend OFF — explicit opt-in matches
   "no install-time OR dep" policy and prevents surprise auth
   coupling.

5. **Q5 — `runtime-or-consumption` spec home.** This change creates a
   new local capability under `mydash/openspec/specs/`. Should it
   instead live in `hydra/openspec/specs/` since other apps may
   eventually adopt the same pattern? Recommend local for now;
   promote to hydra if a second app adopts it.

6. **Q6 — `ColumnTypeRegistry` future.** Stream 4 flagged the
   constants as "hardcoded". Is there a future where MyDash column
   types map onto JSON-schema `type` (and merge upward into OR)?
   Recommend NO — column types model rendering choices (`integer` vs
   `boolean` vs `string` UI affordance) and are deliberately not
   data-typed. Add docblock and stop flagging.

7. **Q7 — manifest version cadence.** ADR-024 specifies semver-on-
   content for `manifest.version`. Should MyDash bump on every menu
   change (chatty) or batch monthly? Recommend semver-on-content with
   `version` only bumped when the *shape* of the manifest changes
   (page added/removed, menu reordered). Pure i18n key updates do NOT
   require a bump.
