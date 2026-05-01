---
status: draft
---

# Setup Wizard Specification

## Purpose

The Setup Wizard is a multi-step first-run configuration flow for freshly installed MyDash instances. It guides administrators through selecting a storage backend, setting group priority order, installing optional demo data, assigning admin roles, and configuring footer content. The wizard detects first-run state via an admin-setting flag, supports both interactive and non-interactive (CLI) flows, and ensures all choices are persisted immediately so progress is not lost.

## Data Model

The setup wizard does not define a new persistent table; it tracks state via existing admin settings and a single new boolean flag:

- **mydash.setup_wizard_complete**: Boolean (default `false`). Set to `true` when the wizard completes via "Finish" button or CLI command.

Wizard progress (per-step completion status) is derived heuristically from the state of underlying settings:
- Step 1 (Welcome): always "done" (no choice to persist)
- Step 2 (Storage): "done" if `mydash.content_storage` is set; "pending" otherwise
- Step 3 (Group order): "done" if `mydash.group_priority_order` is set; "pending" otherwise
- Step 4 (Demo data): "done" if at least one demo package has been installed; "pending" otherwise
- Step 5 (Admin roles): "skipped" if not visited; "done" if a "Dashboard Admin" role assignment exists
- Step 6 (Footer): "skipped" if not visited; "done" if `mydash.footer_config` is set
- Step 7 (Done): "pending" until wizard completes

## ADDED Requirements

### Requirement: REQ-WIZ-001 Detect First-Run State via Admin Setting Flag

The system MUST detect whether a MyDash instance is being initialized for the first time by consulting the `mydash.setup_wizard_complete` boolean flag. When the flag is `false`, the admin section MUST display a banner prompting the admin to run the wizard.

#### Scenario: Fresh instance shows banner
- GIVEN a freshly installed MyDash with `mydash.setup_wizard_complete = false`
- WHEN an NC admin opens `/apps/mydash/admin/dashboards`
- THEN the page MUST display a prominent banner: "Run setup wizard" with a button linking to the wizard flow
- AND the banner MUST remain visible until the admin clicks "Finish" in the wizard

#### Scenario: Completed instance hides banner
- GIVEN a MyDash instance with `mydash.setup_wizard_complete = true`
- WHEN an NC admin opens `/apps/mydash/admin/dashboards`
- THEN the banner MUST NOT be displayed
- AND the admin section loads normally

#### Scenario: Banner includes descriptive text
- GIVEN a fresh MyDash instance showing the setup banner
- WHEN the admin views the banner
- THEN the banner MUST include text explaining: "Get your intranet started: choose storage, configure groups, install demo data, and set up admin roles."
- NOTE: Banner style and wording are implementation-specific; requirement mandates presence and visibility only

#### Scenario: Banner has explicit "Run setup wizard" call-to-action
- GIVEN the banner is displayed
- WHEN the admin clicks the "Run setup wizard" button
- THEN the wizard modal MUST open and begin at Step 1 (Welcome)

#### Scenario: Admin can dismiss and re-access banner
- GIVEN an admin who dismissed the banner or navigated away
- WHEN the admin returns to `/apps/mydash/admin/dashboards`
- THEN if `mydash.setup_wizard_complete = false`, the banner MUST reappear

### Requirement: REQ-WIZ-002 Multi-Step Wizard Flow with 7 Steps

The wizard MUST guide the admin through a linear, skippable sequence of 7 steps, each with Skip / Back / Next buttons. Only the "Finish" button on Step 7 marks the wizard complete.

#### Scenario: Wizard starts at Step 1
- GIVEN the banner's "Run setup wizard" button is clicked
- WHEN the wizard modal opens
- THEN Step 1 (Welcome / Overview) MUST be displayed
- AND the step counter MUST show "1 / 7"

#### Scenario: Next button advances to next step
- GIVEN the wizard is on Step 1
- WHEN the admin clicks "Next"
- THEN the wizard advances to Step 2 (Storage backend)
- AND the step counter MUST show "2 / 7"
- AND the Back button MUST become active

#### Scenario: Back button returns to previous step
- GIVEN the wizard is on Step 3
- WHEN the admin clicks "Back"
- THEN the wizard returns to Step 2
- AND no data loss occurs (Step 3 values are preserved if re-visited)

#### Scenario: Skip button jumps to next step without commitment
- GIVEN the wizard is on Step 5 (Admin roles, optional)
- WHEN the admin clicks "Skip"
- THEN the wizard advances to Step 6
- AND the wizard completion status for Step 5 is marked "skipped" (not "done")
- NOTE: Skipping a step does not prevent the wizard from eventually marking complete

#### Scenario: Step 1 has no form; only explanatory text
- GIVEN Step 1 (Welcome) is displayed
- WHEN the admin views the step
- THEN the step MUST show text explaining the wizard's purpose: "Configure your MyDash instance with storage, group ordering, demo data, admin roles, and footer settings."
- AND there are no form fields to fill
- AND clicking "Next" advances without persisting any choice

#### Scenario: Step 7 shows summary and Finish button
- GIVEN the wizard reaches Step 7 (Done)
- WHEN the admin views the step
- THEN the step MUST display a summary: "Your setup is complete. Click Finish to save changes and dismiss the setup banner."
- AND a "Finish" button (not "Next") MUST be present
- AND clicking "Finish" executes the completion action

#### Scenario: Finish button completes wizard
- GIVEN the admin is on Step 7 with the "Finish" button visible
- WHEN the admin clicks "Finish"
- THEN the system MUST set `mydash.setup_wizard_complete = true`
- AND the wizard modal MUST close
- AND the admin section MUST reload, banner gone

#### Scenario: Wizard progress persists across Back navigation
- GIVEN the wizard is on Step 3 with group order selected
- WHEN the admin clicks Back to Step 2, modifies storage backend, then clicks Next to Step 3
- THEN the group order selection from Step 3 MUST still be present (no data loss)

### Requirement: REQ-WIZ-003 Step 2 — Storage Backend Choice with GroupFolder Dependency Tooltip

Step 2 MUST present two radio options: "Database (default)" and "GroupFolder (recommended for org use)". The GroupFolder option MUST be disabled with a tooltip if the `groupfolders` Nextcloud app is not installed.

#### Scenario: Storage backend selection shows two options
- GIVEN Step 2 is displayed
- WHEN the admin views the step
- THEN two radio buttons MUST be visible:
  - "Database (default)" — with description "Store dashboard content in MyDash database table"
  - "GroupFolder (recommended for org use)" — with description "Store dashboard content in Nextcloud GroupFolders for collaborative access"
- AND exactly one option MUST be pre-selected (default to "Database")

#### Scenario: GroupFolder option disabled if app not installed
- GIVEN the `groupfolders` Nextcloud app is NOT installed
- WHEN Step 2 is displayed
- THEN the "GroupFolder" radio option MUST be disabled (grayed out)
- AND a tooltip MUST appear on hover: "GroupFolder app is not installed. Install 'Nextcloud GroupFolders' to use this option."
- NOTE: Disabled state prevents selection; tooltip explains why

#### Scenario: GroupFolder option enabled if app installed
- GIVEN the `groupfolders` Nextcloud app IS installed
- WHEN Step 2 is displayed
- THEN the "GroupFolder" radio option MUST be enabled
- AND the option is selectable

#### Scenario: Storage choice persists immediately
- GIVEN Step 2 is displayed with "Database" selected
- WHEN the admin selects "GroupFolder" and clicks Next
- THEN the system MUST immediately write `mydash.content_storage = "groupfolder"` to admin settings
- AND if the admin navigates away (Back, Browser close, etc.), the choice MUST be persisted (not lost)

#### Scenario: Default is Database
- GIVEN a fresh instance where no storage backend has been chosen
- WHEN Step 2 is displayed
- THEN "Database (default)" MUST be pre-selected
- NOTE: This reflects the safe, always-available default

### Requirement: REQ-WIZ-004 Step 3 — Group Priority Order (Embedded Component Reuse)

Step 3 MUST embed the existing `group-priority-order` admin UI component, allowing the admin to configure the priority order of Nextcloud groups for dashboard routing.

#### Scenario: Step 3 embeds existing group-priority-order admin component
- GIVEN Step 3 is displayed
- WHEN the admin views the step
- THEN the existing `group-priority-order` admin UI MUST be visible and functional within the wizard modal
- NOTE: The component is not duplicated; the wizard embeds the canonical admin component

#### Scenario: Group order choice persists immediately
- GIVEN the admin reorders groups in Step 3 (e.g., "engineering" moved above "sales")
- WHEN the admin clicks Next
- THEN the system MUST immediately persist the new order to `mydash.group_priority_order`
- AND navigating away does not lose the change

#### Scenario: Step 3 allows drag-and-drop reordering (existing UI behavior)
- GIVEN the group-priority-order component is displayed
- WHEN the admin drags a group to a new position
- THEN the reorder MUST be reflected in the UI
- NOTE: Exact interaction (drag, arrow buttons, etc.) is determined by the embedded component's design

### Requirement: REQ-WIZ-005 Step 4 — Demo Data Installation (Embedded Component Reuse)

Step 4 MUST embed the existing `demo-data-showcases` admin UI component, allowing the admin to optionally select and install demo packages.

#### Scenario: Step 4 embeds demo-data-showcases admin component
- GIVEN Step 4 is displayed
- WHEN the admin views the step
- THEN the existing `demo-data-showcases` admin UI MUST be visible within the wizard modal
- AND the component displays all available demo packages with checkboxes
- NOTE: The component is the canonical admin UI; wizard embeds it without duplication

#### Scenario: Demo data installation is optional
- GIVEN Step 4 is displayed with zero demo packages selected
- WHEN the admin clicks "Skip" or "Next"
- THEN the wizard MUST advance without requiring any demo to be installed
- NOTE: Step 4 is not skippable via a Skip button; all steps are traversed. However, selecting zero demos and clicking Next is equivalent to skipping.

#### Scenario: Checked demos are installed on Next
- GIVEN the admin selects checkboxes for "Engineering Demo" and "Sales Demo" on Step 4
- WHEN the admin clicks "Next"
- THEN the system MUST trigger the installation of both demo packages
- AND the wizard advances to Step 5 (installation happens asynchronously or blocks until done, implementation choice)
- NOTE: The demo-data-showcases component handles the actual installation logic

#### Scenario: Demo selection is persisted
- GIVEN the admin selected "Engineering Demo" on Step 4 and advanced to Step 5
- WHEN the admin navigates Back to Step 4
- THEN the "Engineering Demo" checkbox MUST remain checked
- AND no re-installation happens (idempotent)

### Requirement: REQ-WIZ-006 Step 5 — Admin Roles (Optional, Depends on admin-roles Capability)

Step 5 MUST allow the admin to optionally assign the "Dashboard Admin" role to one Nextcloud group. This step is skippable via a Skip button.

#### Scenario: Step 5 shows group selector for admin role
- GIVEN the `admin-roles` capability is available
- AND Step 5 is displayed
- WHEN the admin views the step
- THEN a dropdown or multi-select MUST display all Nextcloud groups
- AND a description MUST explain: "Assign 'Dashboard Admin' role to a group to delegate MyDash administration to group members."

#### Scenario: Admin can select one group for Dashboard Admin role
- GIVEN Step 5 is displayed with groups ["engineering", "sales", "marketing"]
- WHEN the admin selects "engineering"
- AND clicks "Next"
- THEN the system MUST call `RoleService::assignRole(groupId="engineering", role="admin", assignedBy=<current-admin>)`
- AND the role assignment MUST be created in `oc_mydash_role_assignments`

#### Scenario: Step 5 is skippable
- GIVEN Step 5 is displayed
- WHEN the admin clicks "Skip"
- THEN no role assignment is made
- AND the wizard advances to Step 6
- AND the completion status for Step 5 MUST be marked "skipped"

#### Scenario: No group selected is equivalent to skip
- GIVEN Step 5 with no group selected
- WHEN the admin clicks "Next"
- THEN no role assignment is made (equivalent to Skip)
- AND the wizard advances to Step 6

#### Scenario: Step 5 unavailable if admin-roles capability missing
- GIVEN the `admin-roles` capability is NOT implemented or not available
- WHEN the wizard would display Step 5
- THEN Step 5 MUST be skipped automatically
- AND the step counter jumps from Step 4 to Step 6
- NOTE: Capability dependency is gracefully handled; missing capability does not break wizard

#### Scenario: Only one group can have admin role per wizard run
- GIVEN Step 5 is displayed
- WHEN the admin selects "engineering" and clicks "Next"
- THEN only the "engineering" group receives the admin role (not multiple groups)
- NOTE: If the admin wishes to assign admin role to multiple groups, they must run the wizard again or use the admin roles management UI directly

### Requirement: REQ-WIZ-007 Step 6 — Footer Configuration (Optional, Depends on footer-customization Capability)

Step 6 MUST allow the admin to optionally open and configure the footer using the "Structured mode" editor. This step is skippable via a Skip button.

#### Scenario: Step 6 shows footer editor
- GIVEN the `footer-customization` capability is available
- AND Step 6 is displayed
- WHEN the admin views the step
- THEN the "Structured mode" footer editor MUST be visible and functional
- AND a description MUST explain: "Customize the footer content and appearance for your intranet."

#### Scenario: Footer edits persist immediately
- GIVEN the admin edits footer content in Step 6
- WHEN the admin clicks "Next"
- THEN the footer configuration MUST be saved to `mydash.footer_config` or equivalent setting
- AND navigating away does not lose the changes

#### Scenario: Step 6 is skippable
- GIVEN Step 6 is displayed
- WHEN the admin clicks "Skip"
- THEN no footer changes are made
- AND the wizard advances to Step 7
- AND the completion status for Step 6 MUST be marked "skipped"

#### Scenario: Step 6 unavailable if footer-customization capability missing
- GIVEN the `footer-customization` capability is NOT implemented or not available
- WHEN the wizard would display Step 6
- THEN Step 6 MUST be skipped automatically
- AND the step counter jumps from Step 5 to Step 7
- NOTE: Capability dependency is gracefully handled; missing capability does not break wizard

### Requirement: REQ-WIZ-008 Get Wizard State via API Endpoint

The system MUST expose a `GET /api/admin/setup-wizard/state` endpoint that returns the wizard's completion status, current recommended step for re-runs, and per-step status.

#### Scenario: Endpoint returns complete state on fresh instance
- GIVEN a fresh MyDash instance with `mydash.setup_wizard_complete = false`
- WHEN an NC admin calls `GET /api/admin/setup-wizard/state`
- THEN the response MUST be HTTP 200 with JSON:
  ```json
  {
    "complete": false,
    "currentRecommendedStep": 1,
    "stepStatuses": {
      "1": "done",
      "2": "pending",
      "3": "pending",
      "4": "pending",
      "5": "pending",
      "6": "pending",
      "7": "pending"
    }
  }
  ```
- NOTE: Step 1 is always "done" (no choice required). Steps 2–7 start "pending".

#### Scenario: Endpoint returns recommended step for re-runs
- GIVEN a MyDash instance with storage backend set but group order not yet set
- WHEN an NC admin calls `GET /api/admin/setup-wizard/state`
- THEN the response MUST include `currentRecommendedStep: 3`
- NOTE: The heuristic picks the first step with status != "done"

#### Scenario: Endpoint returns complete=true when wizard finished
- GIVEN a MyDash instance with `mydash.setup_wizard_complete = true`
- WHEN an NC admin calls `GET /api/admin/setup-wizard/state`
- THEN the response MUST include `complete: true`
- AND `currentRecommendedStep` MAY be 1 (optional) or reflect the last visited step (implementation choice)

#### Scenario: Non-admin requests receive 403
- GIVEN a non-admin user
- WHEN they call `GET /api/admin/setup-wizard/state`
- THEN the system MUST return HTTP 403 (Forbidden)

#### Scenario: Endpoint includes skipped step status
- GIVEN the wizard has been run with Step 5 skipped
- WHEN an NC admin calls `GET /api/admin/setup-wizard/state`
- THEN the response MUST include `"5": "skipped"` in stepStatuses
- NOTE: Skipped status indicates the step was traversed but no action taken; does not block wizard completion

### Requirement: REQ-WIZ-009 Mark Wizard Complete via API Endpoint

The system MUST expose a `POST /api/admin/setup-wizard/complete` endpoint that idempotently sets `mydash.setup_wizard_complete = true` and returns the current wizard state.

#### Scenario: Endpoint sets complete flag
- GIVEN a MyDash instance with `mydash.setup_wizard_complete = false`
- WHEN an NC admin calls `POST /api/admin/setup-wizard/complete`
- THEN the system MUST set `mydash.setup_wizard_complete = true`
- AND the response MUST be HTTP 200 with the current wizard state (same structure as GET endpoint)

#### Scenario: Endpoint is idempotent
- GIVEN a MyDash instance with `mydash.setup_wizard_complete = true`
- WHEN an NC admin calls `POST /api/admin/setup-wizard/complete` again
- THEN the system MUST return HTTP 200
- AND `complete` in the response MUST be true
- AND no error is raised

#### Scenario: Non-admin requests receive 403
- GIVEN a non-admin user
- WHEN they call `POST /api/admin/setup-wizard/complete`
- THEN the system MUST return HTTP 403 (Forbidden)
- AND the flag MUST NOT change

#### Scenario: Completion returns full state
- GIVEN the request succeeds
- WHEN the response is examined
- THEN it MUST contain the full wizard state (complete, currentRecommendedStep, stepStatuses)
- NOTE: Return format is identical to GET endpoint for consistency

### Requirement: REQ-WIZ-010 CLI Command for Non-Interactive Setup (IaC-Friendly)

The system MUST expose a CLI command `php occ mydash:setup` that accepts a `--config=/path/setup.yaml` argument to perform wizard setup non-interactively, ideal for Infrastructure-as-Code deployments.

#### Scenario: CLI command accepts YAML config file
- GIVEN a YAML file `/tmp/setup.yaml` with content:
  ```yaml
  storage_backend: "groupfolder"
  group_priority_order: ["engineering", "sales"]
  demo_packages: ["engineering-demo", "sales-demo"]
  admin_role_group: "engineering"
  footer_config:
    layout: "structured"
    items: [...]
  ```
- WHEN an admin runs `php occ mydash:setup --config=/tmp/setup.yaml`
- THEN the system MUST:
  - Set `mydash.content_storage = "groupfolder"` (Step 2)
  - Set `mydash.group_priority_order = ["engineering", "sales"]` (Step 3)
  - Install "engineering-demo" and "sales-demo" (Step 4)
  - Assign "Dashboard Admin" role to "engineering" (Step 5)
  - Set `mydash.footer_config` with provided config (Step 6)
  - Set `mydash.setup_wizard_complete = true` (Step 7)

#### Scenario: CLI command validates YAML schema
- GIVEN a malformed YAML file (e.g., missing required fields)
- WHEN `php occ mydash:setup --config=/tmp/setup.yaml` is run
- THEN the system MUST output an error: "Invalid setup.yaml: missing field 'storage_backend'"
- AND the command MUST exit with non-zero status
- AND no settings are applied

#### Scenario: CLI command is verbose by default
- GIVEN the command runs successfully
- WHEN the output is examined
- THEN it MUST show progress: "Step 1: Welcome... done", "Step 2: Storage backend... done", etc.
- AND final message: "Setup wizard completed successfully."

#### Scenario: CLI command skips optional steps if not in config
- GIVEN a YAML file with only `storage_backend` and `group_priority_order`
- WHEN the command runs
- THEN Steps 5 and 6 MUST be skipped (no error)
- AND Steps 2 and 3 MUST execute with provided values
- AND the wizard completes

#### Scenario: CLI command is idempotent
- GIVEN a YAML config that was applied once
- WHEN the same command runs again with the same config
- THEN the system MUST return success (HTTP 200 equivalent)
- AND no duplicate role assignments or demo installations occur
- NOTE: The system MUST detect existing assignments/installations and skip them

### Requirement: REQ-WIZ-011 Wizard is Re-Runnable

The wizard MUST be re-runnable even after `mydash.setup_wizard_complete = true`. Re-running MUST NOT undo earlier choices; it MUST re-walk the steps showing current state and allowing updates.

#### Scenario: Admin can run wizard again after completion
- GIVEN a MyDash instance with `mydash.setup_wizard_complete = true`
- WHEN an NC admin navigates to `/apps/mydash/admin` and clicks "Run setup wizard again" (or uses a Re-run button in a separate admin UI section)
- THEN the wizard modal MUST open
- AND all step values MUST reflect current settings (storage backend, group order, etc.)
- AND the admin can make changes

#### Scenario: Re-running from Step 2 updates storage backend
- GIVEN the instance has `mydash.content_storage = "database"`
- AND the admin re-runs the wizard and navigates to Step 2
- WHEN the admin selects "GroupFolder" and clicks Next
- THEN the system MUST update `mydash.content_storage = "groupfolder"`
- AND the previous choice "database" is overwritten (not appended or merged)

#### Scenario: Re-running does not re-install demo packages
- GIVEN the instance has already installed "engineering-demo"
- AND the admin re-runs the wizard to Step 4
- WHEN the admin unchecks "engineering-demo" and clicks Next
- THEN the system MUST NOT re-install the demo (it's already there)
- NOTE: Demo installation is one-time; re-running the wizard does not trigger redundant installations. If the admin wishes to un-install, they must use a separate admin UI for demo management.

#### Scenario: Re-running preserves skipped steps
- GIVEN the wizard was run previously with Step 5 skipped
- AND the admin re-runs the wizard
- WHEN Step 5 is displayed
- THEN the step MUST show "Skip" button as before
- AND if the admin clicks Skip again, the behavior is consistent with the first run

#### Scenario: Step 1 is always done on re-run
- GIVEN a re-run of the wizard
- WHEN Step 1 is displayed
- THEN no new action is required; clicking Next immediately advances

#### Scenario: API endpoint reports re-runnable state
- GIVEN the instance has `mydash.setup_wizard_complete = true`
- WHEN an NC admin calls `GET /api/admin/setup-wizard/state`
- THEN the response MUST indicate `complete: true`
- AND the admin UI MUST show a "Run setup wizard again" button (not a "Run setup wizard" banner)
- NOTE: The UI signals that re-running is available and safe
