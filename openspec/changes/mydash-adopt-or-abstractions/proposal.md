# MyDash — adopt OR abstractions (manifest, runtime-only OR consumption)

## Why

The 2026-05-03 OR-abstraction audit (`.claude/audit-2026-05-03/`) flagged
adoption gaps across all eight Specter-pipeline apps. For MyDash the gaps
fall into three categories:

1. **Manifest adoption** — MyDash currently has no architectural
   `src/manifest.json` and wires its router by hand. Per the
   fleet-wide `adopt-app-manifest` change in `hydra/openspec/changes/`
   and the migration order in **ADR-024**, MyDash is the recommended
   *pilot* — smallest surface, dashboard-dominant, low risk.
2. **Spec staleness** — `dashboard-sharing/spec.md` and
   `admin-templates/spec.md` are flagged NEEDS-REWRITE by stream 2 of
   the audit. The rewrites must clarify which storage layer (OR
   per-object RBAC vs MyDash's own `oc_mydash_*` tables) owns each
   capability, *without* introducing a hard dependency on OpenRegister
   in the install graph.
3. **Local hygiene** — stream 4 found a small cluster of hardcoded
   constants (`ColumnTypeRegistry.php:31-45`, `AdminSetting.php:42-85`,
   `FileService.php:62`). None of these are OR-related; they are
   in-repo cleanup that this change folds in for completeness.

### Special policy: no install-time OR dependency

MyDash is a BI / dashboarding tool. Per
`feedback_mydash-no-or-dependency.md`, MyDash MUST NOT declare a hard
install-time dependency on `openregister` or `openconnector`. The
manifest's `dependencies: []` array MUST stay empty for the OR family.
Any OR data MyDash displays is consumed exclusively over the **runtime
GraphQL / REST API** with graceful degradation when OR is absent.

This makes MyDash structurally different from the other adoption
changes (larpingapp, softwarecatalog, decidesk) which all declare
`"dependencies": ["openregister"]` in their manifest.

## What Changes

### Manifest adoption (Tier 1 → Tier 3)

- Add `src/manifest.json` describing the current MyDash UI: top-level
  Dashboards menu, per-dashboard pages of `type: "dashboard"`, admin
  templates index of `type: "index"`, settings page of `type: "custom"`.
- Set `$schema` to the published `@conduction/nextcloud-vue`
  app-manifest schema URL.
- Set `dependencies: []` (explicit empty — no install-time OR/OC
  dependency).
- Wire `useAppManifest('mydash', bundled)` in `src/main.js`. Start at
  Tier 1 (manifest loaded but not yet rendering routes); plan Tier 3
  (`CnAppNav` + `CnPageRenderer` driving the shell) for a follow-up.
- Add `npm run check:manifest` to `package.json` so CI fails on schema
  errors.

### Runtime-only OR consumption

- Document the runtime consumption pattern: dashboards that surface OR
  data MUST do so via authenticated calls to
  `/index.php/apps/openregister/api/...` (REST) or the OR GraphQL
  endpoint, gated on a runtime feature-detect (`useAppStatus('openregister')`
  from `@conduction/nextcloud-vue`).
- Adopt `useTenantContext()` from nc-vue once the
  `nextcloud-vue/openspec/changes/multi-tenancy-context/` change ships
  in v0.x — but only on widgets that actually surface OR objects, and
  only when OR is enabled at runtime.
- Adopt the OR `?_lang=` / `X-Translation-Target-Language` API
  conventions from
  `openregister/openspec/changes/i18n-api-language-negotiation/` for
  any widget that fetches translatable OR objects.

### Spec rewrites (NEEDS-REWRITE cohort)

- Rewrite `openspec/specs/dashboard-sharing/spec.md` to describe MyDash
  permission levels (`view_only` / `add_only` / `full`) as native
  MyDash concepts (`oc_mydash_dashboards.permissions`), with an
  optional **runtime** delegation to OR per-object RBAC when OR is
  enabled. No backend-PHP dependency on OR.
- Rewrite `openspec/specs/admin-templates/spec.md` to declare that
  admin templates are stored in MyDash's own `oc_mydash_admin_settings`
  table (NOT in OR) and document the rationale: MyDash must work
  standalone without OR installed.

### Local hygiene (stream 4 cleanups)

- `ColumnTypeRegistry.php:31-45` — keep `TYPE_INTEGER`/`TYPE_BOOLEAN`/
  `TYPE_STRING` constants local. They legitimately model MyDash column
  types, which are independent of JSON-schema `type`. Document the
  rationale inline so future audits stop flagging it.
- `AdminSetting.php:42-85` — extract the eight `KEY_*` admin-config
  constants to a single typed enum or const-list and document each.
- `FileService.php:62` — extract `FILENAME_PATTERN` regex to a named
  class constant with a unit test that pins the accepted/rejected
  filename set.

## Problem

Every other Specter-pipeline app can simply declare
`"dependencies": ["openregister"]` and rely on OR being present at
boot. MyDash cannot. As a dashboarding tool it must:

- Boot and render usable dashboards when OR is absent (e.g. a
  Nextcloud install with only built-in widgets and Nextcloud Talk).
- Feature-detect OR at runtime and unlock OR-backed widgets only when
  available.
- Avoid stamping OR identifiers (register IDs, schema IDs) in MyDash's
  own database — MyDash dashboards that reference OR data store
  references *symbolically* (e.g. `{"source": "openregister:register/schema"}`)
  and resolve at render time.

The current adoption gap means MyDash dashboards that surface OR data
do so through ad-hoc widget-specific code paths with no unified
runtime contract. The manifest gives us the contract; the runtime-only
policy keeps MyDash deployable in OR-less Nextcloud installs.

## Proposed Solution

A single `mydash-adopt-or-abstractions` change that:

1. Lands the manifest pilot at Tier 1, with a follow-up tracked in
   tasks.md to graduate to Tier 3 once the dashboards page-type
   contract stabilises in nc-vue.
2. Documents (in a new shared `runtime-or-consumption` spec capability)
   the pattern for *runtime-only* OR consumption that MyDash uses, so
   future widgets follow it consistently.
3. Rewrites the two NEEDS-REWRITE specs to make the
   "no-install-OR-dep" policy explicit.
4. Folds in the three small hygiene cleanups so they don't accumulate.

No code outside MyDash changes. No PRs are opened by this change — it
is spec-only and goes through the standard opsx flow
(`/opsx-plan-to-issues` → `/opsx-apply` → `/opsx-verify`).

## Out of Scope

- Building OR-backed dashboard widgets in this change. Widget work
  lands in follow-up changes that consume the runtime-OR pattern this
  change documents.
- Migrating MyDash's own data into OR. MyDash owns `oc_mydash_*`
  tables; that ownership stays.
- Tier 4 manifest adoption (full `CnAppRoot` shell). Tier 1 first;
  Tier 3 in follow-up; Tier 4 only if/when MyDash's bespoke admin
  pages can be modelled as `type: "custom"` cleanly.
- Any change to the GraphQL or REST endpoints OR exposes — those
  contracts are owned by OR.

## See also

- `openregister/openspec/changes/register-resolver-service/` — OR's
  consolidated `getValueString(...register/schema...)` resolver. MyDash
  does not consume it directly (no PHP-side OR dep) but downstream
  apps that proxy MyDash-managed configs MAY.
- `openregister/openspec/changes/pluggable-integration-registry/` —
  ADR-019 registry. MyDash widgets that pull from third-party sources
  (e.g. JIRA, n8n) MAY register as integration providers in a future
  change.
- `openregister/openspec/changes/i18n-source-of-truth/` (ADR-025) —
  schema-level `sourceLanguage` + per-row source tracking. MyDash
  reads this metadata when rendering OR-translatable fields in
  dashboard widgets.
- `openregister/openspec/changes/i18n-api-language-negotiation/`
  (ADR-025) — `?_lang=` query param + `X-Translation-Target-Language`
  header. MyDash widgets that read OR data SHOULD pass `?_lang=` so
  multi-language dashboards render consistently.
- `nextcloud-vue/openspec/changes/multi-tenancy-context/` —
  `useTenantContext()` composable. MyDash widgets that surface
  tenant-scoped OR data adopt this once it ships.
- `hydra/openspec/changes/adopt-app-manifest/` — fleet-wide manifest
  convention (ADR-024). MyDash is the recommended pilot.
- ADR-022 — Apps consume OR abstractions (no duplication).
- ADR-024 — App manifest fleet-wide adoption.
- ADR-025 — i18n source-of-truth + API language negotiation.
- `feedback_mydash-no-or-dependency.md` — the policy this change
  encodes.
- `.claude/audit-2026-05-03/` — source audit (research files
  R2/R4/R5/R6 referenced throughout).
