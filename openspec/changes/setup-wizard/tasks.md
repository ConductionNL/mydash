# Tasks — setup-wizard

## Backend Tasks

- [ ] Task 1: Create `lib/Service/SetupWizardService.php` with methods:
  - `getState(): array` — returns `{complete: bool, currentRecommendedStep: int, stepStatuses: {...}}`
  - `completeWizard(): void` — sets `mydash.setup_wizard_complete = true`
  - `getEnabledSteps(): array` — returns array of step numbers (2–6) that have available capabilities
  - Include inline comments referencing REQ-WIZ-002 and REQ-WIZ-008

- [ ] Task 2: Create `lib/Controller/SetupWizardController.php` with endpoints:
  - `getStateAction()` — `GET /api/admin/setup-wizard/state` returning JSON state; require admin role; return 403 for non-admin (REQ-WIZ-008)
  - `completeAction()` — `POST /api/admin/setup-wizard/complete` setting flag + returning state; idempotent; require admin role (REQ-WIZ-009)
  - Both endpoints MUST check admin permissions before proceeding

- [ ] Task 3: Modify `lib/Controller/AdminController.php` (or create admin settings page if not already present):
  - Add logic to fetch wizard state on page load
  - If `state.complete = false`, render banner with text "Get your intranet started: choose storage, configure groups, install demo data, and set up admin roles." (REQ-WIZ-001)
  - Banner includes button "Run setup wizard" linking to the wizard modal

- [ ] Task 4: Create `lib/Command/SetupCommand.php` implementing `php occ mydash:setup --config=/path/setup.yaml`:
  - Parse YAML file; validate schema (storage_backend required, group_priority_order required; others optional) (REQ-WIZ-010)
  - Call SetupWizardService to apply settings:
    - Step 2: call storage backend service with `storage_backend` value
    - Step 3: call group-priority-order service with `group_priority_order` value
    - Step 4: call demo-data-showcases service to install packages in `demo_packages` list
    - Step 5: call admin-roles service with `admin_role_group` if present
    - Step 6: call footer-customization service with `footer_config` if present
    - Step 7: call SetupWizardService::completeWizard()
  - Output progress: "Step 1: Welcome... done", etc.
  - Exit with 0 on success, non-zero on validation error
  - Idempotent: detect existing installations/assignments and skip them (REQ-WIZ-010)

- [ ] Task 5: Create or extend admin settings initialization:
  - Ensure `mydash.setup_wizard_complete` defaults to `false` on fresh install
  - Create migration if needed to set the flag on existing instances (optional, implementation choice)

## Frontend Tasks

- [ ] Task 6: Create `src/admin/SetupWizard.vue` component:
  - On mount: fetch `GET /api/admin/setup-wizard/state` to determine current state and enabled steps
  - Render 7-step modal with counter (e.g., "2 / 7")
  - Step 1: plain text "Configure your MyDash instance with storage, group ordering, demo data, admin roles, and footer settings." (REQ-WIZ-002)
  - Steps 2–6: embed respective admin components via `<component :is="componentName" />`
    - Step 2 (Storage): `GroupFolderStorageBackend` component
    - Step 3 (Groups): `GroupPriorityOrder` component
    - Step 4 (Demo): `DemoDataShowcases` component
    - Step 5 (Admin roles): `AdminRolesAssignment` component (if available)
    - Step 6 (Footer): `FooterCustomization` component (if available)
  - Step 7: summary text "Your setup is complete. Click Finish to save changes and dismiss the setup banner."
  - Navigation:
    - Steps 1–6: Skip / Back / Next buttons
    - Step 7: Finish button (POST to complete endpoint)
    - Back button disabled on Step 1
    - Skip button hidden on Step 1 + 7
  - On Finish: POST `/api/admin/setup-wizard/complete`; close modal; emit event to reload admin page

- [ ] Task 7: Modify `src/admin/AdminSettings.vue` or main admin page:
  - Fetch wizard state on mount
  - If `complete = false`: render banner above the form with button opening SetupWizard modal (REQ-WIZ-001)
  - If `complete = true`: render "Run setup wizard again" button (optional, for re-runs) (REQ-WIZ-011)
  - Banner styling: prominent, with description and clear call-to-action

- [ ] Task 8: Create wizard data flow and state management:
  - Export `useSetupWizardState()` composable (or Pinia store) that:
    - Holds current step, step statuses, enabled steps
    - Methods: `nextStep()`, `prevStep()`, `skipStep()`, `finishWizard()`
    - On step change: validate step (no skipping disabled steps), update modal state
  - Composable references REQ-WIZ-002 for step sequence

## Integration & Testing Tasks

- [ ] Task 9: Verify embedded component compatibility:
  - Ensure `groupfolder-storage-backend`, `group-priority-order`, `demo-data-showcases` components can be mounted within the modal (not full-page only)
  - If components assume full-page layout, create modal-compatible wrappers
  - Test that each component's form and persistence work unchanged within the modal

- [ ] Task 10: Vitest — backend service tests:
  - SetupWizardService::getState() returns correct step statuses based on admin settings
  - SetupWizardService::completeWizard() sets flag + persists
  - SetupWizardController endpoints require admin role + return 403 for non-admin
  - SetupCommand parses valid YAML and applies settings; rejects invalid YAML with error message

- [ ] Task 11: Vitest — frontend component tests:
  - SetupWizard modal renders correct step count + content per step
  - Navigation buttons work: Next advances, Back returns, Skip jumps to next, Finish posts to API
  - Back disabled on Step 1, Skip hidden on Steps 1 + 7
  - Step values are preserved when navigating Back then Next (data persistence)

- [ ] Task 12: Playwright — end-to-end wizard flow:
  - Fresh instance shows banner on admin page
  - Click "Run setup wizard" opens modal at Step 1
  - Navigate through all 7 steps (using Next, Back, Skip as applicable)
  - On Step 2, select storage and verify it persists to settings
  - On Step 3, reorder groups and verify persistence
  - On Step 4, select demo packages and verify (no actual install required for test; mock if needed)
  - Click Finish on Step 7; verify flag set + modal closes + admin page reloads + banner gone
  - Verify wizard is re-runnable: click "Run setup wizard again"; Step 2 shows previously selected storage

- [ ] Task 13: Playwright — banner + wizard logic:
  - Fresh instance (flag = false) shows banner
  - Completed instance (flag = true) hides banner
  - Banner button opens wizard
  - Wizard completion hides banner on reload
  - Re-run wizard shows current state pre-filled

- [ ] Task 14: CLI smoke test:
  - Create sample `setup.yaml` with valid config
  - Run `php occ mydash:setup --config=setup.yaml`
  - Verify output shows progress ("Step 1: Welcome... done", etc.)
  - Verify admin settings updated correctly
  - Verify flag set to true
  - Run same command again; verify idempotent (no error, no duplicate installations)

## Quality & Documentation Tasks

- [ ] Task 15: Quality checks:
  - ESLint clean on Vue/JS files (SetupWizard.vue, composables)
  - PHPCS/PHPMD/PHPStan/Psalm clean on PHP files (Service, Controller, Command)
  - Run full test suite; no regressions
  - i18n: add user-facing strings to both `nl` and `en` locale files (banner text, button labels, step titles)

- [ ] Task 16: Documentation:
  - Add inline comments to SetupWizardService referencing spec requirements
  - Add controller method PHPDoc with endpoint paths
  - Document YAML config schema in CLI command help text or inline comment
  - Update README (or docs) with first-run setup flow and CLI example

- [ ] Task 17: Deduplication check:
  - Verify SetupWizardService only orchestrates; does NOT duplicate storage/group/demo/admin-roles/footer functionality
  - Verify all setting writes go through canonical services (not direct admin-settings calls)
  - Document findings in this task comment

- [ ] Task 18: Architectural enforcement:
  - Add grep lint (Vitest or CI script) ensuring `provideInitialState` calls for wizard-related keys only come from SetupWizardService or designated places
  - Verify SetupCommand is the only CLI path that sets `mydash.setup_wizard_complete` (no ad-hoc flag writes)

## Verification

`openspec validate` exits clean. Fresh instances display banner; clicking "Run setup wizard" opens modal with all 7 steps functional; completing wizard sets flag + hides banner. CLI command accepts YAML config and applies settings non-interactively. Wizard is re-runnable with current state pre-filled.

## Tests (company-wide ADR-009)

- Vitest: SetupWizardService state logic, SetupCommand YAML parsing, SetupWizard component navigation
- Playwright: end-to-end wizard flow, banner display, persistence, re-run scenario

## Documentation (company-wide ADR-010)

Inline service/command PHPDoc; CLI help text; README example of first-run setup flow.

## i18n (company-wide ADR-007)

User-facing strings: banner text, button labels, step titles, CLI output. Parity in `nl` + `en` locale files.
