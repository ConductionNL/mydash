# Setup Wizard

The Setup Wizard is a multi-step first-run configuration flow for freshly installed MyDash instances. It guides administrators through selecting a storage backend, setting group priority order, optionally installing demo data, assigning admin roles, and configuring footer content. The wizard detects first-run state via an admin-setting flag (`mydash.setup_wizard_complete`), supports both interactive (UI) and non-interactive (CLI) flows, and ensures all choices are persisted immediately so progress is not lost. After completion, the wizard is re-runnable, allowing admins to revisit configuration decisions without losing earlier choices.

## Affected code units

- `lib/Controller/AdminController.php` — new endpoints for wizard state + completion
- `lib/Service/SetupWizardService.php` (new) — state tracking, completion flag management
- `src/admin/SetupWizard.vue` (new) — multi-step modal with 7 steps
- `src/admin/AdminSettings.vue` — add banner for fresh installs
- `lib/Command/SetupCommand.php` (new) — CLI `php occ mydash:setup --config=/path/setup.yaml`
- New capability `setup-wizard`

## Why a new capability

The setup wizard orchestrates multiple sibling capabilities (`groupfolder-storage-backend`, `group-priority-order`, `demo-data-showcases`, `admin-roles`, `footer-customization`) and owns two critical responsibilities:

1. **First-run detection** — the `mydash.setup_wizard_complete` flag is the single source of truth for whether the instance needs setup guidance
2. **Step sequencing** — the wizard stitches 7 steps together in a linear flow, each embedding an existing admin component without duplication

This warrants a dedicated capability that owns the orchestration, flag lifecycle, and API surface.

## Approach

- **First-run banner** — admin section checks `mydash.setup_wizard_complete`. If false, displays a banner prompting the admin to run the wizard.
- **Step orchestration** — Step 1 (Welcome) is informational only. Steps 2–6 embed canonical admin UI components from sibling capabilities. Step 7 (Done) shows a summary. Each step has Skip / Back / Next buttons; Step 7 has Finish instead of Next.
- **Immediate persistence** — all choices within each step are persisted immediately to their canonical settings (`mydash.content_storage`, `mydash.group_priority_order`, etc.) so progress survives navigation and page reloads.
- **Re-runnable design** — after completion, the wizard can be re-run, re-walking steps with current values populated. Re-running MUST NOT undo earlier choices; it allows updates.
- **API surface** — `GET /api/admin/setup-wizard/state` returns completion status and per-step progress; `POST /api/admin/setup-wizard/complete` marks the wizard done.
- **CLI support** — `php occ mydash:setup --config=/path/setup.yaml` performs wizard setup non-interactively, ideal for Infrastructure-as-Code.

## Capabilities

**New Capability:**

- `setup-wizard` — first-run detection, step orchestration, state API, CLI command

**Embedded Capabilities:**

- `groupfolder-storage-backend` (Step 2)
- `group-priority-order` (Step 3)
- `demo-data-showcases` (Step 4)
- `admin-roles` (Step 5, optional)
- `footer-customization` (Step 6, optional)

## Notes

- Step 1 is always marked "done" because it has no choices — it is purely explanatory.
- Steps 5 and 6 are skippable and optional; missing sibling capabilities are gracefully skipped (no error).
- Demo installation (Step 4) is persisted via the demo-data-showcases component; re-running the wizard does not re-install already-installed demos.
- Capability dependencies (admin-roles, footer-customization) are checked at step render time. If unavailable, that step is auto-skipped and the wizard jumps to the next step.
