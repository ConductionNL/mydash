# Design — Setup wizard

## Context

The source app ships a CLI-only setup flow: `occ intravox:setup [--language=nl] [--skip-demo] [--force-demo]`. The underlying `SetupService` creates a GroupFolder, configures three Nextcloud groups, sets folder permissions, and optionally seeds demo data. No HTTP endpoint, no Vue component, and no first-run detection flag exist in the source. `SetupService::migrateTemplatesFolders()` handles brownfield installs but is likewise CLI-driven.

The setup wizard defined in this spec is an entirely new MyDash invention. Non-CLI administrators — the majority of self-hosted customers — have no guided path through the suite of configuration decisions that a healthy intranet requires: storage backend, group routing order, demo data, admin role assignment, and footer customization. Today they navigate five separate admin sections in isolation with no indication of what a proper setup sequence looks like.

MyDash adds a 7-step frontend wizard that walks admins through these decisions in one modal flow. Each step embeds the canonical admin UI component from the corresponding sibling capability rather than duplicating it — the wizard is a thin orchestrator, not a parallel settings surface. Choices are persisted immediately to the underlying admin settings on each step commit, so the wizard's own state is derived heuristically from those settings rather than tracked in a separate state machine.

The source CLI pattern is adopted as the model for a parallel `occ mydash:setup` command (defined in the `cli-commands` sibling capability). Both surfaces call the same underlying service methods; the wizard adds UX, not new business logic.

## Goals / Non-Goals

**Goals:**

- Provide a guided first-run experience for admins who do not use OCC.
- Track first-run state with a single boolean flag (`mydash.setup_wizard_complete`) rather than a separate wizard state table.
- Embed existing sibling-capability admin UI components per step — no duplication of settings surfaces.
- Persist each step's choices immediately to the underlying admin settings so navigating away mid-wizard loses no work.
- Expose two admin-only API endpoints (`GET /state`, `POST /complete`) so the frontend can drive the wizard reactively.
- Support re-running the wizard after completion for guided reconfiguration.
- Provide CLI parity via the `cli-commands` sibling spec.

**Non-Goals:**

- Replicating any wizard-step values in a separate wizard state machine — the underlying admin settings are the source of truth; the wizard derives its step statuses heuristically from them.
- Porting or reusing source code from the source CLI command — the wizard is net-new; it models the CLI pattern, not the implementation.
- Undoing previous configuration choices when re-running — re-running re-walks steps showing current state and allows updates, it does not reset.
- Real-time push of wizard completion state to non-admin users.
- Making wizard step ordering configurable per install.

## Decisions

### D1: Net-new frontend wizard — no source counterpart

**Decision**: The 7-step wizard is an entirely new MyDash design. The source app ships CLI-only setup. MyDash adds the frontend flow on top of the same underlying service primitives.

**Source evidence (what the source has)**:
- `intravox-source/lib/Command/SetupCommand.php` — CLI: `intravox:setup` with `--language`, `--skip-demo`, `--force-demo` flags
- `intravox-source/lib/Service/SetupService.php:1-80` — creates GroupFolder, configures groups, seeds demo data
- `intravox-source/lib/AppInfo/Application.php` — no `IInitialState` flag, no wizard route, no onboarding component registered

**Rationale**: The frontend wizard reduces setup friction for admins who don't use OCC. The CLI command remains available and is the appropriate tool for IaC and scripted installs. Both surfaces co-exist; neither replaces the other.

### D2: First-run detection flag — `mydash.setup_wizard_complete` boolean

**Decision**: A single admin setting `mydash.setup_wizard_complete` (boolean, default `false`) tracks completion. When `false`, the MyDash admin section displays a "Run setup wizard" banner. When `true`, the banner is hidden but a "Run setup wizard again" link remains visible in the admin section.

**Rationale**: A single flag keeps the persistence model trivial and survives the wizard being re-run any number of times. The flag signals intent, not content — the actual configuration is in the sibling capabilities' own settings.

### D3: 7-step flow — embed pattern, not duplication

**Decision**: Seven steps as described in the spec. Steps 2–6 each embed the existing admin UI component from the corresponding sibling capability. The wizard owns step sequencing and the completion flag; each sibling capability owns its own settings persistence.

| Step | Sibling capability |
|------|--------------------|
| 2 — Storage backend | `groupfolder-storage-backend` (writes `mydash.content_storage`) |
| 3 — Group order | `group-priority-order` (writes `mydash.group_priority_order`) |
| 4 — Demo data | `demo-data-showcases` (installs selected packages) |
| 5 — Admin roles | `admin-roles` (creates role assignment in `oc_mydash_role_assignments`) |
| 6 — Footer config | `footer-customization` (writes `mydash.footer_config`) |

All five sibling slugs exist in `openspec/changes/` and are confirmed present.

**Rationale**: Embedding keeps the wizard a thin orchestrator. A developer maintaining the group-priority-order admin UI does not need to touch wizard code, and vice versa. There is one canonical UI per setting, reachable both from the wizard and from the standalone admin section.

### D4: Per-step skip / immediate persistence

**Decision**: Each step (except Welcome and Done) has Skip / Back / Next buttons. Clicking Next on a step immediately persists any committed values to the underlying admin settings. Skipping a step does NOT mark the wizard incomplete — only the Done step's "Finish" button writes `mydash.setup_wizard_complete = true`.

**Rationale**: Immediate persistence means navigating away mid-wizard, closing the browser, or clicking Back never causes data loss. The skip/finish distinction avoids a scenario where an admin who skips optional steps 5 and 6 cannot complete the wizard — skipped steps are valid completion states.

### D5: Re-runnable flow — shows current state, does not reset

**Decision**: An admin may click "Run setup wizard again" at any time after completion. Re-running opens the wizard with each step showing current settings values, not a blank form. No existing configuration is reset. The admin may update any step's values; clicking "Finish" again writes `complete = true` (already true — idempotent).

**Rationale**: Guided reconfiguration is a real use case for admins who want to change storage backend or re-assign roles months after initial setup. The wizard provides a more guided experience than navigating individual admin sections.

### D6: Auto-launch heuristic — SHOULD, not MUST

**Decision**: When an admin first opens `/apps/mydash/admin/dashboards`, if `setup_wizard_complete = false` AND no dashboards exist AND no admin settings have been written, the page SHOULD auto-open the wizard. This is a soft UX recommendation, not a mandatory requirement. The banner-link fallback is always available regardless of whether auto-launch fires.

**Rationale**: Auto-launch removes one click for the common fresh-install case. Making it SHOULD rather than MUST avoids breaking edge cases (e.g., headless installs via CLI where an admin views the page mid-setup before completing the OCC command).

### D7: CLI parity via sibling `cli-commands` capability

**Decision**: A `php occ mydash:setup --config=<yaml>` command defined in the `cli-commands` sibling spec executes the equivalent flow non-interactively from a YAML file. The command calls the same service methods as the wizard steps and sets `setup_wizard_complete = true` on success.

**Source evidence**:
- `intravox-source/lib/Command/SetupCommand.php` — CLI pattern to model (flag names, OCC registration, console output conventions)

**Rationale**: CLI parity makes MyDash deployable via Ansible, Docker init scripts, and other IaC tooling without requiring a browser session. The YAML config schema covers all five configurable steps; optional steps may be omitted.

### D8: State endpoint — `GET /api/admin/setup-wizard/state`

**Decision**: Returns `{complete: bool, currentRecommendedStep: 1..7, stepStatuses: {1: 'done'|'skipped'|'pending', ...}}`. The `currentRecommendedStep` heuristic selects the first step whose status is not `'done'`, so re-runs land on the right step without forcing the admin to re-walk completed ones. Step 1 (Welcome) is always `'done'`.

**Rationale**: The state endpoint gives the frontend a single reactive source for rendering the wizard's progress indicator and for determining where to resume on re-open. Step statuses are derived from sibling settings reads, not from a separate wizard state table — consistent with D4's no-separate-state-machine principle.

### D9: Idempotent completion — `POST /api/admin/setup-wizard/complete`

**Decision**: `POST /api/admin/setup-wizard/complete` writes `mydash.setup_wizard_complete = true` and returns the current wizard state (same structure as GET). Calling it when already `true` returns HTTP 200 with current state — no error, no side effects.

**Rationale**: Idempotency allows the Finish button and the CLI command to call the same endpoint without defensive guards. Callers do not need to check current state before posting.

## Spec changes implied

- Add a NOTE to REQ-WIZ-001 confirming this is a net-new MyDash capability with no source counterpart; the source ships CLI-only via `intravox:setup`.
- REQ-WIZ-002 (7 steps): add a NOTE pinning the embed pattern from D3 — wizard embeds canonical sibling admin UI, does not duplicate it.
- REQ-WIZ-010 (CLI): cross-reference the `cli-commands` sibling spec and note that the YAML config schema covers all five configurable steps; optional steps may be omitted.
- REQ-WIZ-008 (auto-launch): the existing scenario already uses "MAY" — add a NOTE clarifying this is intentionally SHOULD/MAY to avoid breaking mid-setup CLI installs.

## Open follow-ups

- Whether the wizard should detect an existing GroupFolder configuration (e.g., from a previous install or an unrelated Nextcloud GroupFolder setup) and skip Step 2 with an informational notice rather than presenting a blank radio choice.
- Whether the `--config` YAML schema for the CLI command should be versioned (e.g., `apiVersion: v1`) to support forward compatibility as new wizard steps are added.
- Whether step ordering should be customisable per install — current recommendation is fixed order for customer-support predictability; revisit if customers request it.
