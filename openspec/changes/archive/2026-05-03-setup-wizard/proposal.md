# Setup Wizard

## Why

A freshly installed MyDash instance requires several configuration decisions to establish a healthy intranet: storage backend selection (Database vs. GroupFolder), default group priority order, demo data installation, admin role assignments, and footer configuration. Today, administrators must navigate multiple admin sections in isolation, without guidance on what a proper setup entails. A multi-step wizard walks admins through these choices, explains the implications, and persists decisions immediately, reducing setup friction and ensuring instances are properly configured from day one.

## What Changes

- Add an admin-setting flag `mydash.setup_wizard_complete` (boolean, default `false`) to track first-run completion. When `false`, the MyDash admin section displays a "Run setup wizard" banner.
- Implement a multi-step Vue 3 modal/screen wizard in the MyDash admin section:
  - **Step 1 — Welcome**: Overview of what the wizard configures.
  - **Step 2 — Storage backend**: Radio choice of "Database (default)" or "GroupFolder (recommended for org use)". GroupFolder option disabled with tooltip if the `groupfolders` NC app is not installed. Writes `mydash.content_storage` admin setting.
  - **Step 3 — Group order**: Pick priority order of NC groups for dashboard routing. Reuses existing `group-priority-order` admin UI as an embedded component.
  - **Step 4 — Demo data**: Optional checkboxes per showcase. Reuses existing `demo-data-showcases` admin UI as an embedded component.
  - **Step 5 — Admin roles**: Optional — assign "Dashboard Admin" role to one NC group (depends on `admin-roles` capability). Skippable.
  - **Step 6 — Footer config**: Optional — open "Structured mode" footer editor (depends on `footer-customization`). Skippable.
  - **Step 7 — Done**: Summary screen. "Finish" button writes `mydash.setup_wizard_complete = true` and dismisses banner.
- Each step has Skip / Back / Next buttons. Skipping does not mark wizard incomplete; only "Finish" marks complete.
- Wizard is re-runnable: admins can click "Run setup wizard again" even after completion. Re-running does not undo earlier choices; it re-walks the steps showing current state.
- Each step's completion (committed values) is persisted immediately, so navigating away mid-wizard does not lose work.
- Expose `GET /api/admin/setup-wizard/state` → `{complete: bool, currentRecommendedStep: 1..7, stepStatuses: {1: 'done'|'skipped'|'pending', ...}}`. The `currentRecommendedStep` heuristically picks "first non-done step" for re-runs.
- Expose `POST /api/admin/setup-wizard/complete` → marks `setup_wizard_complete = true`. NC-admin only. Idempotent: writing when already true returns 200.
- CLI command `php occ mydash:setup --config=/path/setup.yaml` performs wizard non-interactively, reading a YAML config file describing every step's choices (IaC-friendly).
- Optional auto-launch: when an admin first opens `/apps/mydash/admin/dashboards`, if `setup_wizard_complete` is false AND no dashboards exist AND no admin settings have been written, the page MAY auto-open the wizard (recommended UX but not required).

## Capabilities

### New Capabilities

- `setup-wizard`: First-run admin wizard with state tracking, step progression, immediate persistence, re-runnable flow, API endpoints, and CLI command.

### Modified Capabilities

- `groupfolder-storage-backend` (ref: `openspec/changes/groupfolder-storage-backend/specs/groupfolder-storage-backend/spec.md`): Step 2 writes the `mydash.content_storage` setting that this capability defines. No schema changes; wizard is a consumer.
- `group-priority-order` (ref: `openspec/changes/group-priority-order/specs/admin-settings/spec.md`): Step 3 embeds this existing admin UI. No changes to the capability itself.
- `demo-data-showcases` (ref: `openspec/changes/demo-data-showcases/specs/demo-data-showcases/spec.md`): Step 4 embeds this existing admin UI. No changes to the capability itself.
- `admin-roles` (ref: `openspec/changes/admin-roles/specs/admin-roles/spec.md`): Step 5 allows assigning "Dashboard Admin" role to a group. No changes; wizard is a convenience consumer.
- `footer-customization` (ref: `openspec/changes/footer-customization/specs/footer-customization/spec.md`): Step 6 opens the footer editor. No changes; wizard is a convenience consumer.

## Impact

**Affected code:**

- `lib/Service/SetupWizardService.php` — new service with `getWizardState()`, `markWizardComplete()`, `validateStep()`, `persistStepChoice()` methods.
- `lib/Controller/AdminController.php` (extend) — two new endpoints: `GET /api/admin/setup-wizard/state`, `POST /api/admin/setup-wizard/complete`.
- `lib/Command/SetupCommand.php` — new CLI command `php occ mydash:setup --config=/path/setup.yaml`.
- `appinfo/routes.php` (extend) — register the two new routes.
- `src/views/admin/SetupWizardModal.vue` — new Vue 3 component with 7-step flow, Skip/Back/Next buttons, progress tracking.
- `src/views/admin/AdminDashboards.vue` (extend) — add "Run setup wizard" banner when `setup_wizard_complete` is false.
- `l10n/en.json`, `l10n/nl.json` — translations for wizard labels, steps, and button text.

**Affected APIs:**

- 2 new routes: `GET /api/admin/setup-wizard/state`, `POST /api/admin/setup-wizard/complete`
- 1 new CLI command: `mydash:setup`

**Dependencies:**

- `admin-roles` capability (Step 5 depends on it; optional step)
- `footer-customization` capability (Step 6 depends on it; optional step)
- `groupfolder-storage-backend` capability (Step 2 writes setting defined by it)
- `group-priority-order` capability (Step 3 embeds its admin UI)
- `demo-data-showcases` capability (Step 4 embeds its admin UI)
- `OCP\IAppConfig` — for reading/writing admin settings
- `OCP\IUserManager`, `OCP\IGroupManager` — for validating group choices in Step 5
- No new composer or npm dependencies beyond existing MyDash stack

**Migration:**

- Zero-impact: no schema changes, only new admin-setting flag
- New instances start with `setup_wizard_complete = false`; admins see banner on first login
- Existing instances (already have settings) can opt-in to running wizard; completion flag defaults to false (wizard remains available until explicitly completed)

## Front-end Concern (Not in Scope)

The admin UI MUST provide an accessible, mobile-friendly modal with clear explanations for each step, disabled-state tooltips for unavailable options (e.g., GroupFolder option when app not installed), and a visual progress indicator. Steps 3–6 embed existing admin UI components and MUST remain functional within the wizard context.
