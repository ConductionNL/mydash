# Design — Setup Wizard

## Context

MyDash instances are freshly deployed with defaults applied to all admin settings (`mydash.content_storage = 'database'`, `mydash.group_priority_order` not set, etc.). Without a guided first-run experience, admins must navigate to various admin sub-pages to configure the instance, and configuration steps are disconnected — there is no clear sequence and no indication that the instance is incomplete. The Setup Wizard consolidates these decisions into a single, linear, first-run flow that:

1. Guides admins through required and optional configuration steps
2. Detects completion via a flag and displays a banner on fresh installs
3. Persists choices to the canonical settings immediately so progress survives reload
4. Supports both interactive (web UI) and non-interactive (CLI) setup for IaC deployments
5. Is re-runnable so admins can revisit choices later

## Goals / Non-Goals

**Goals:**

- Provide a clear, linear first-run sequence that eliminates confusion about what needs configuring.
- Always ensure choices are persisted immediately (no "save all at the end" with risk of data loss on browser close).
- Support non-interactive (CLI) setup for infrastructure-as-code environments.
- Detect when setup is complete and hide the banner, signaling to the admin that the instance is ready.
- Allow the wizard to be re-run, so admins can revisit decisions without breaking earlier choices.
- Gracefully handle missing optional capabilities (admin-roles, footer-customization) by auto-skipping those steps.

**Non-Goals:**

- Custom validation per step — validation is owned by the embedded component.
- Multi-user coordination — simultaneous wizard runs by different admins are assumed rare and acceptable (last-write-wins).
- Undo/rollback within a single wizard run — admin must use individual admin UIs if they need to revert.
- Wizard branching — the sequence is linear; conditional skips are only for missing capabilities.

## Architecture

### Data model

- **Flag:** `mydash.setup_wizard_complete` (boolean, default `false`). Set to `true` when wizard completes via Finish button or CLI command.
- **Step status:** Derived heuristically from underlying settings (no dedicated table):
  - Step 1: always "done" (no choice)
  - Step 2: "done" if `mydash.content_storage` is set; "pending" otherwise
  - Step 3: "done" if `mydash.group_priority_order` is set; "pending" otherwise
  - Step 4: "done" if at least one demo is installed; "pending" otherwise
  - Step 5: "skipped" if not visited; "done" if a "Dashboard Admin" role assignment exists
  - Step 6: "skipped" if not visited; "done" if `mydash.footer_config` is set
  - Step 7: "pending" until wizard completes

### UI flow

```
Admin opens /apps/mydash/admin/dashboards

IF mydash.setup_wizard_complete = false:
  Display banner: "Get your intranet started — Run setup wizard"
  
  When admin clicks banner button:
    Open SetupWizard modal
    Start at Step 1 (Welcome / Overview)
    
    For each of Steps 1–7:
      IF Step has capability dependency AND dependency unavailable:
        Auto-skip to next step
      ELSE:
        Display step with form (or info text for Step 1 + 7)
        Embed existing admin component if applicable
        
    Each step allows: Skip / Back / Next
    Step 7: Finish button instead of Next
    
    On Finish:
      Set mydash.setup_wizard_complete = true
      Close modal
      Reload admin section (banner now hidden)
ELSE:
  Display normal admin page (no banner)
  IF available: Show "Run setup wizard again" button for re-runs
```

### Backend

```
SetupWizardService
├─ getState(): {complete, currentRecommendedStep, stepStatuses}
├─ completeWizard(): void
├─ getEnabledSteps(): {2, 3, 4, ...} (excludes steps with unavailable capabilities)
└─ applyCliConfig(config: array): void (non-interactive path)

AdminController
├─ GET /api/admin/setup-wizard/state → {complete, ...}
└─ POST /api/admin/setup-wizard/complete → {complete, ...}

SetupCommand (CLI)
├─ php occ mydash:setup --config=/path/setup.yaml
├─ Parses YAML; validates schema
├─ Calls SetupWizardService::applyCliConfig()
└─ Sets mydash.setup_wizard_complete = true
```

### Frontend

```
src/admin/SetupWizard.vue
├─ Fetches /api/admin/setup-wizard/state on mount
├─ Renders step counter (e.g., "2 / 7")
├─ For each step:
│  ├─ Step 1: plain text (no form)
│  ├─ Steps 2–6: embed existing admin component via <component :is="componentName" />
│  └─ Step 7: summary text + Finish button
├─ Navigation: Skip / Back / Next (Finish on step 7)
├─ On Next/Finish: persist values via embedded components' own APIs
└─ On Finish: POST /api/admin/setup-wizard/complete, then close + reload

src/admin/AdminSettings.vue (modifications)
├─ Before: render normal admin form
├─ After: 
│  ├─ Fetch /api/admin/setup-wizard/state
│  ├─ IF complete = false: render banner above form
│  └─ Banner text + link to open SetupWizard modal
```

## Decisions

### D1: Flag-based first-run detection, not heuristic

**Decision:** Use a dedicated `mydash.setup_wizard_complete` boolean flag to track completion, not heuristics on the underlying settings.

**Alternatives considered:**

- Infer "setup complete" from "all settings are non-default" (e.g., if storage is set AND group order is set, assume complete). Rejected because an admin might deliberately set only storage and leave group order at default for a valid use case.
- Check if wizard was ever accessed (store a timestamp). Rejected because the flag is simpler and is the real intent.

**Rationale:** An explicit flag makes the intent unmistakable. Admin settings can be configured piecemeal outside the wizard; the flag cleanly separates "wizard run / setup complete" from the individual setting state. CLI provisioning may also set the flag independently.

### D2: Immediate per-step persistence, not batch-save-at-end

**Decision:** Each step persists its own choices to canonical settings immediately (via the embedded component's own API). The Finish button does not re-persist; it only marks the wizard complete.

**Alternatives considered:**

- Collect all step choices in memory and save them all in a batch on Finish. Rejected because it loses data on browser close mid-wizard, and makes the wizard's undo story unclear.
- Save-as-you-go in a temporary wizard-state table, then merge to canonical settings on Finish. Rejected as unnecessary complexity.

**Rationale:** MyDash's embedded admin components already own their persistence. Leaning on that avoids duplication, simplifies the wizard's responsibility, and ensures no data loss if the admin closes the browser mid-wizard.

### D3: Linear step sequence with capability-based auto-skip

**Decision:** Steps are traversed in order (1–7). If a step's dependency (e.g., admin-roles, footer-customization) is unavailable, that step is auto-skipped with no warning; the wizard jumps to the next enabled step.

**Alternatives considered:**

- Skip with a visual indicator ("skipped because admin-roles not available"). Rejected as noisy for fresh installs where admins are not aware of sibling capabilities.
- Require the dependency and error if missing. Rejected because it blocks wizard completion for a valid optional step; IaC deployments that don't need those features should not be blocked.
- Present a checkbox "Enable admin-roles?" to let the admin decide. Rejected because the admin doesn't know if the capability is implemented, and a checkbox implies a feature-flag (wrong mental model).

**Rationale:** Clean auto-skip is the least intrusive. If the capability exists later, re-running the wizard surfaces its step.

### D4: Re-runnable without undo

**Decision:** The wizard can be re-run after completion. Re-running re-walks all steps, pre-populating current values, and allows updates. Changes overwrite previous choices; the wizard has no "undo" mechanism.

**Alternatives considered:**

- Lock the wizard after completion (one-run-only). Rejected because admins need to reconfigure after instance launch (e.g., swap storage backend, change group priority).
- Provide an undo/rollback for the entire wizard run. Rejected as too complex for a setup tool; individual admin UIs handle undos for their domains.

**Rationale:** Administrators are expected to revisit setup choices as the organization evolves. Re-running with current values pre-filled is the natural mental model ("show me what's configured, let me change it").

### D5: CLI config schema is non-strict on optional steps

**Decision:** The CLI YAML config schema is non-strict: required fields (storage_backend, group_priority_order) MUST be present, but optional fields (admin_role_group, footer_config) MAY be omitted. Omitting an optional field is equivalent to skipping that step in the wizard.

**Alternatives considered:**

- Strict schema: all fields required or error. Rejected because IaC configs for minimal setups (e.g., just storage + groups) should not need placeholder values for optional steps.
- Permissive: accept extra fields. Kept — allows future extensibility without breaking existing configs.

**Rationale:** Minimalist IaC configs should not require boilerplate for optional steps. The wizard already handles skips gracefully.

## Component reuse

The wizard embeds existing admin components for Steps 2–6:

- **Step 2 (Storage):** `groupfolder-storage-backend` admin UI (radio button pair: Database | GroupFolder)
- **Step 3 (Groups):** `group-priority-order` admin UI (drag-and-drop reordering)
- **Step 4 (Demo data):** `demo-data-showcases` admin UI (checkbox list of demo packages)
- **Step 5 (Admin roles):** `admin-roles` admin UI (group selector for role assignment, if available)
- **Step 6 (Footer):** `footer-customization` admin UI (structured editor, if available)

Each embedded component owns its own validation and persistence. The wizard is a thin orchestrator.

## Seed data

The setup wizard does not define persistent data entities (no OpenRegister schemas, no database tables besides the flag). The `mydash.setup_wizard_complete` flag is application config, not domain data. No seed data is required.

## Deduplication check

The setup wizard references the following existing services and does NOT duplicate them:

- `groupfolder-storage-backend` — storage backend selector
- `group-priority-order` — group reordering
- `demo-data-showcases` — demo installation
- `admin-roles` — role assignment (optional)
- `footer-customization` — footer editor (optional)

The wizard adds only the orchestration layer (step sequencing, first-run banner, completion flag).
