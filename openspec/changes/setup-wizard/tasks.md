# Tasks — setup-wizard

## 1. Admin-setting flag and banner

- [ ] 1.1 Register `mydash.setup_wizard_complete` as a boolean admin-setting key (default `false`) via `IAppConfig`; document the key alongside other `mydash.*` config keys
- [ ] 1.2 Read the flag in `AdminController` (or a new `SetupWizardController`) and expose it on the admin page's initial state so the frontend can decide whether to show the banner
- [ ] 1.3 Implement the "Run setup wizard" banner in `src/views/admin/AdminDashboards.vue`: visible when `setup_wizard_complete = false`, hidden otherwise
- [ ] 1.4 Clicking the banner button MUST open `SetupWizardModal.vue` at Step 1
- [ ] 1.5 After the wizard completes the banner MUST disappear without requiring a full page reload (reactive store update)
- [ ] 1.6 Add `i18n` keys for banner text and button label in both `l10n/en.json` and `l10n/nl.json`

## 2. Multi-step wizard shell (Vue component)

- [ ] 2.1 Create `src/views/admin/SetupWizardModal.vue`: a 7-step modal with a step counter ("N / 7"), Skip / Back / Next button row, and a slot per step
- [ ] 2.2 Implement step navigation state machine: `currentStep` (1–7), transitions forward (Next/Skip) and backward (Back), no free-jump
- [ ] 2.3 Replace "Next" label with "Finish" on Step 7 only; "Finish" triggers `POST /api/admin/setup-wizard/complete`
- [ ] 2.4 Step 1 (Welcome): static explanatory text, no form; Next advances immediately
- [ ] 2.5 Step 7 (Done): static summary text ("Your setup is complete. Click Finish to save changes and dismiss the setup banner."); Finish button calls the complete endpoint and closes modal
- [ ] 2.6 On modal close (X or Finish), emit an event to `AdminDashboards.vue` so it can re-check wizard state and hide the banner
- [ ] 2.7 Add `i18n` keys for all step titles, button labels, and summary text in `l10n/en.json` and `l10n/nl.json`

## 3. Step 2 — Storage backend

- [ ] 3.1 Implement Step 2 UI in the wizard: two radio buttons ("Database (default)" / "GroupFolder (recommended for org use)") with descriptions
- [ ] 3.2 Check via `OCP\App\IAppManager::isInstalled('groupfolders')` in `SetupWizardService::getGroupfolderAvailability()` and expose it in the wizard state; disable the GroupFolder radio when `false`
- [ ] 3.3 Add a tooltip on the disabled GroupFolder radio: "GroupFolder app is not installed. Install 'Nextcloud GroupFolders' to use this option."
- [ ] 3.4 On clicking Next from Step 2 persist the selected value immediately via `POST /api/admin/settings` to write `mydash.content_storage`
- [ ] 3.5 Pre-populate the radio on re-run: read current `mydash.content_storage` from the wizard state endpoint

## 4. Step 3 — Group priority order

- [ ] 4.1 Embed the existing `group-priority-order` admin UI component in Step 3 (import and mount inside wizard modal; do not duplicate it)
- [ ] 4.2 Confirm the component emits or writes directly to `mydash.group_priority_order` on change; if not, add a thin wrapper that calls the admin settings PUT on Next
- [ ] 4.3 Verify Back/Next preserves the order selection (no re-render resets the component state)

## 5. Step 4 — Demo data

- [ ] 5.1 Embed the existing `demo-data-showcases` admin UI component in Step 4 (import and mount inside wizard modal)
- [ ] 5.2 Confirm Next triggers installation of checked demos via the demo-data-showcases service; wizard advances after install completes (or asynchronously; document the choice)
- [ ] 5.3 On re-run: mark already-installed demos as checked and non-reinstallable; verify the component exposes an `installed` state per package

## 6. Step 5 — Admin roles (optional)

- [ ] 6.1 Implement Step 5 UI: dropdown listing all Nextcloud groups; description text explaining "Dashboard Admin" role delegation
- [ ] 6.2 On Next with a group selected: call `RoleService::assignRole(groupId, role='admin', assignedBy=<current-admin>)` (from the `admin-roles` capability)
- [ ] 6.3 If no group selected and admin clicks Next: treat as skip (no role assignment, same as clicking Skip)
- [ ] 6.4 If `admin-roles` capability is not available at runtime: skip Step 5 automatically; adjust step counter display
- [ ] 6.5 Add `i18n` keys for group-selector label and description text

## 7. Step 6 — Footer configuration (optional)

- [ ] 7.1 Implement Step 6 UI: embed the "Structured mode" footer editor from the `footer-customization` capability
- [ ] 7.2 Footer edits MUST persist immediately via the footer-customization service; Next only advances without re-saving
- [ ] 7.3 If `footer-customization` capability is not available at runtime: skip Step 6 automatically; adjust step counter display
- [ ] 7.4 Add `i18n` keys for step description text

## 8. Backend service and API endpoints

- [ ] 8.1 Create `lib/Service/SetupWizardService.php` with methods:
  - `getWizardState(): array` — reads `mydash.setup_wizard_complete` and per-step heuristics; returns `{complete, currentRecommendedStep, stepStatuses}`
  - `markWizardComplete(): array` — idempotently sets `mydash.setup_wizard_complete = true`; returns updated state
  - `getGroupfolderAvailability(): bool` — delegates to `IAppManager::isInstalled('groupfolders')`
- [ ] 8.2 Add `AdminController::getWizardState()` mapped to `GET /api/admin/setup-wizard/state` (NC-admin only)
- [ ] 8.3 Add `AdminController::completeWizard()` mapped to `POST /api/admin/setup-wizard/complete` (NC-admin only, idempotent)
- [ ] 8.4 Register both routes in `appinfo/routes.php` with appropriate admin-only auth constraints
- [ ] 8.5 Add PHPUnit tests for `SetupWizardService::getWizardState()`: fresh instance, partial completion, fully done, and re-run scenarios
- [ ] 8.6 Add PHPUnit tests for `SetupWizardService::markWizardComplete()`: first call sets flag; second call (idempotent) returns same state without error

## 9. CLI command

- [ ] 9.1 Create `lib/Command/SetupCommand.php` implementing `OC\Command\Base` with command name `mydash:setup` and a `--config=` option
- [ ] 9.2 Parse the YAML file (using Symfony Yaml component, already available in NC) and validate the schema: required key `storage_backend`; optional keys `group_priority_order`, `demo_packages`, `admin_role_group`, `footer_config`
- [ ] 9.3 Fail fast on invalid YAML: output "Invalid setup.yaml: missing field '…'" and exit 1; no settings applied
- [ ] 9.4 Execute each step in order, logging progress: "Step N: <name>... done" (verbose by default)
- [ ] 9.5 Handle idempotency: detect already-applied settings and skip without error; log "Step N: <name>... already configured, skipping"
- [ ] 9.6 Register `SetupCommand` in `lib/AppInfo/Application.php` via the `RegisterCommand` event
- [ ] 9.7 Add a functional test (PHPUnit, CLI context) that runs the command with a fixture YAML and verifies the resulting admin settings

## 10. Auto-launch behavior

- [ ] 10.1 In `AdminDashboards.vue` on page mount: if `setup_wizard_complete = false` AND dashboard count === 0 AND no admin settings have been written → automatically open `SetupWizardModal.vue`
- [ ] 10.2 Auto-launch MUST only fire once per page load (no loop); if the admin closes the modal, subsequent loads still show the banner but do not auto-open
- [ ] 10.3 The auto-launch check MUST be client-side only (no backend behavior change required); the banner and state endpoint provide sufficient data
- [ ] 10.4 Add a Playwright test: fresh install → admin opens `/apps/mydash/admin/dashboards` → wizard opens automatically

## 11. Quality gates

- [ ] 11.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered along the way
- [ ] 11.2 ESLint + Stylelint clean on all touched Vue/JS files
- [ ] 11.3 Update generated OpenAPI spec / Postman collection so external API consumers see the two new endpoints
- [ ] 11.4 `i18n` keys for all new strings in both `l10n/en.json` and `l10n/nl.json` per the i18n requirement; verify Dutch translations are accurate
- [ ] 11.5 SPDX headers on every new PHP file (inside the docblock per the SPDX-in-docblock convention) — gate-spdx must pass
- [ ] 11.6 Run all `hydra-gates` locally before opening PR
