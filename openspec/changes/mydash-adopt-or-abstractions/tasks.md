# Tasks — mydash-adopt-or-abstractions

> Spec-only change. No PR / merge / archive tasks here — those belong
> to Hydra coordination per `feedback_opsx-no-process-tasks.md`.

## Phase 1 — Manifest pilot (Tier 1)

- [ ] 1.1 Add `src/manifest.json` describing current MyDash UI:
  - top-level Dashboards menu (i18n key `mydash.menu.dashboards`)
  - per-dashboard pages (`type: "dashboard"`, route
    `/dashboards/:id`, `config.{widgets, layout}` populated from the
    existing GridStack model)
  - admin templates index (`type: "index"`, route
    `/admin/templates`)
  - admin settings page (`type: "custom"`, route `/admin/settings`,
    `component: "AdminSettingsPage"`)
- [ ] 1.2 Set `$schema` to the published nc-vue
  app-manifest schema URL.
- [ ] 1.3 Set `dependencies: []` (explicit empty — verify in code review
  that no follow-up commit silently adds `"openregister"`).
- [ ] 1.4 Add `version: "0.1.0"` and bump on each manifest content
  change.
- [ ] 1.5 Wire `useAppManifest('mydash', bundled)` in `src/main.js`
  alongside the existing router setup. Tier 1 — manifest loaded but
  vue-router still hand-wired.
- [ ] 1.6 Add `npm run check:manifest` script to `package.json` calling
  the validator from `@conduction/nextcloud-vue`.
- [ ] 1.7 Wire `npm run check:manifest` into the existing CI lint job.

## Phase 2 — Runtime OR consumption pattern

- [ ] 2.1 Add `src/composables/useOrFeatureDetect.js` that wraps
  `useAppStatus('openregister')` from nc-vue and exposes
  `{ enabled, version, error }`.
- [ ] 2.2 Document in `docs/widgets/or-data.md` the canonical pattern
  for an OR-backed widget: feature-detect → conditional render →
  graceful empty state when OR is absent.
- [ ] 2.3 Add a runtime-OR-consumption section to the new
  `runtime-or-consumption` spec capability (see Phase 4).
- [ ] 2.4 Audit existing widgets for ad-hoc OR calls; list each one
  with file:line. Result lives in tasks.md as a checklist for
  follow-up migration changes (no code edits in this change).

## Phase 3 — Spec rewrites (NEEDS-REWRITE cohort)

- [ ] 3.1 Rewrite `openspec/specs/dashboard-sharing/spec.md`:
  - keep MyDash permission levels (view_only / add_only / full) as
    native concepts on `oc_mydash_dashboards.permissions`
  - add a "Runtime OR delegation (optional)" section describing how
    a dashboard MAY delegate its row-level permission check to OR's
    per-object RBAC at render time when OR is enabled
  - explicit "MUST NOT add a hard OR dependency" requirement
- [ ] 3.2 Rewrite `openspec/specs/admin-templates/spec.md`:
  - declare admin templates persist in `oc_mydash_admin_settings`
    (local table)
  - explicit rationale: MyDash must work standalone
  - explicit "MUST NOT store templates in OR" requirement
- [ ] 3.3 Cross-link both rewritten specs from the new
  `runtime-or-consumption` capability.

## Phase 4 — New `runtime-or-consumption` spec capability

- [ ] 4.1 Create `openspec/specs/runtime-or-consumption/spec.md` with:
  - `Requirement: MyDash MUST NOT declare an install-time dependency
    on openregister or openconnector`
  - `Requirement: MyDash widgets that surface OR data MUST
    feature-detect OR at runtime`
  - `Requirement: MyDash widgets MUST pass ?_lang= when fetching
    translatable OR data`
  - `Requirement: MyDash widgets MUST consume useTenantContext() from
    nc-vue when surfacing tenant-scoped OR data`
  - `Requirement: MyDash widgets MUST render a documented empty
    state when OR is absent or returns 5xx`
- [ ] 4.2 Each requirement gets at least one
  GIVEN / WHEN / THEN scenario.

## Phase 5 — Local hygiene cleanups (stream 4)

- [ ] 5.1 `lib/Db/ColumnTypeRegistry.php:31-45` — add a docblock
  explaining why MyDash column types are intentionally separate
  from JSON-schema `type`. Constants stay local. No code edit
  required beyond the docblock.
- [ ] 5.2 `lib/Db/AdminSetting.php:42-85` — collect the eight `KEY_*`
  constants under a single `AdminSettingKey` const-list (or PHP 8.1
  `enum`) with doc comments. Keep BC by aliasing the old constants.
- [ ] 5.3 `lib/Service/FileService.php:62` — extract
  `FILENAME_PATTERN` to a named class constant; add a unit test that
  asserts:
  - allowed: `dashboard-export.json`, `template_v2.json`,
    `template-with-dashes.json`
  - rejected: `../etc/passwd`, `name.with.two.dots.json`, paths
    containing slashes, paths with leading dots
- [ ] 5.4 Run `composer check:strict` and fix any pre-existing
  PHPCS/PHPMD/Psalm/PHPStan warnings touched by the above edits
  (per project policy in CLAUDE.md).

## Phase 6 — Manifest Tier 3 graduation (follow-up tracking)

- [ ] 6.1 Track in this tasks.md (no code in this change) the
  prerequisites for Tier 3:
  - dashboard `type: "dashboard"` page-type contract stable in nc-vue
  - GridStack adapter component shipped in nc-vue or local
  - admin pages converted from `type: "custom"` to declarative
    config where possible
- [ ] 6.2 Open a follow-up opsx change `mydash-manifest-tier-3`
  once Phase 6 prerequisites are met. (Tracking only — do not
  create the change in this proposal.)

## Phase 7 — Documentation

- [ ] 7.1 Update `docs/architecture.md` (or create) to describe:
  - manifest as the single source of truth for routes / menu
  - runtime-only OR consumption policy
  - permission model on `oc_mydash_dashboards`
- [ ] 7.2 Add `docs/widgets/or-data.md` (referenced from Phase 2.2).
- [ ] 7.3 Cross-link the new docs from the app's README.

## Phase 8 — Verification

- [ ] 8.1 Run `npm run check:manifest` locally — must pass.
- [ ] 8.2 Verify in a clean Nextcloud install (no OR, no OC) that
  MyDash boots, renders empty dashboards, and shows graceful empty
  states for any OR-backed widget the operator manually adds.
- [ ] 8.3 Verify in a Nextcloud install with OR enabled that
  OR-backed widgets surface data through the runtime API contract.
- [ ] 8.4 Confirm `composer check:strict` and `npm run lint` pass.
- [ ] 8.5 Confirm unit tests for the FILENAME_PATTERN regex pass.
