---
capability: initial-state-contract
delta: true
status: draft
---

# Initial State Contract — Delta from change `initial-state-contract`

## ADDED Requirements

### Requirement: Centralised PHP builder for initial state (REQ-INIT-001)

The system MUST expose `lib/Service/InitialStateBuilder.php` with a constructor accepting an `IInitialState` and a `Page` enum (`Page::WORKSPACE` or `Page::ADMIN`). The builder MUST expose typed setter methods (e.g. `setWidgets(array $widgets): self`, `setLayout(array $layout): self`, `setIsAdmin(bool $isAdmin): self`) and a final `apply(): void` that writes every key to the initial-state service. `apply()` MUST throw `MissingInitialStateException` if any required key was not set for the chosen page. Controllers (`WorkspaceController`, `AdminSettings`) MUST use the builder; direct calls to `IInitialState::provideInitialState` from controllers are forbidden by code review (a grep lint MUST find such calls only inside `InitialStateBuilder`).

#### Scenario: Builder writes all keys

- **WHEN** a workspace controller calls every required setter and then `apply()` and the page renders
- **THEN** the system MUST forward each value to `IInitialState::provideInitialState` so that `loadState('mydash', <key>)` on the JS side returns the exact values set
- **AND** the JS bundle MUST receive all 10 workspace keys declared in the Data Model

#### Scenario: Missing required key raises before render

- **WHEN** a controller constructs the builder for `Page::WORKSPACE`, omits the required `setLayout()` call, and then invokes `apply()`
- **THEN** the system MUST throw `MissingInitialStateException` with a message naming the missing key `layout`
- **AND** the page MUST NOT render (HTTP 500 surfaces during dev; CI test catches this earlier)

#### Scenario: Direct provideInitialState call rejected

- **WHEN** a developer adds `$initialState->provideInitialState('foo', 'bar')` directly inside `lib/Controller/WorkspaceController.php`
- **THEN** the lint test (grep against `lib/Controller/`) MUST fail with a message pointing to `InitialStateBuilder`
- **AND** the change MUST NOT be merged

### Requirement: Versioned key set per page (REQ-INIT-002)

The exact set of initial-state keys per page is part of the contract and MUST match the Data Model documented in this spec. Adding, removing, or renaming a key is a deliberate spec change and MUST be accompanied by (1) an update to this spec's Data Model, (2) a bump of the `INITIAL_STATE_SCHEMA_VERSION` constant (currently `1`), and (3) coordinated updates to both the PHP builder and the JS reader in the same commit. The schema version MUST itself be pushed as initial state under the key `_schemaVersion`. The JS reader MUST log a console warning when the received version differs from the compiled-in version (catches caching and deploy-skew bugs).

The Workspace page (mounted into `#workspace-vue`) MUST expose exactly these keys:

| Key | Type | Default (if missing) |
|---|---|---|
| `widgets` | `Array<{id, title, iconClass, iconUrl, url}>` | `[]` |
| `layout` | `Array<WorkspaceWidget>` | `[]` |
| `primaryGroup` | `string` | `'default'` |
| `primaryGroupName` | `string` | `''` |
| `isAdmin` | `boolean` | `false` |
| `activeDashboardId` | `string` | `''` |
| `dashboardSource` | `'user'\|'group'\|'default'` | `'group'` |
| `groupDashboards` | `Array<{id, name, icon, source?}>` | `[]` |
| `userDashboards` | `Array<{id, name, icon}>` | `[]` |
| `allowUserDashboards` | `boolean` | `false` |

The Admin page (mounted into `#workspace-admin-vue`) MUST expose exactly these keys:

| Key | Type | Default |
|---|---|---|
| `allGroups` | `Array<{id, displayName}>` | `[]` |
| `configuredGroups` | `Array<string>` | `[]` |
| `widgets` | `Array<{id, title, iconClass, iconUrl, url}>` | `[]` |
| `allowUserDashboards` | `boolean` | `false` |

#### Scenario: Adding a key bumps version

- **WHEN** a developer adds a new workspace initial-state key `theme` and submits the change for review
- **THEN** the spec Data Model table MUST list `theme` with type and default
- **AND** the `INITIAL_STATE_SCHEMA_VERSION` constant MUST be bumped to `2`
- **AND** the JS reader MUST consume `theme`

#### Scenario: Schema version mismatch warning

- **WHEN** PHP pushes `_schemaVersion: 2` but the loaded JS bundle was compiled against version `1` and the JS reader runs
- **THEN** the reader MUST log a console warning of the form `MyDash initial-state schema mismatch: server v2 vs client v1 — refresh recommended`
- **AND** the reader MUST still attempt to load known keys (graceful degradation)

### Requirement: Centralised JS reader for initial state (REQ-INIT-003)

The system MUST expose `src/utils/loadInitialState.js` exporting `loadInitialState(page: 'workspace' | 'admin'): InitialState`. The reader MUST call `loadState('mydash', key, default)` for every key declared for `page` in the Data Model, MUST return a typed object with default-filled fields (no `undefined` values), and MUST validate the received `_schemaVersion` against the compiled-in `INITIAL_STATE_SCHEMA_VERSION` constant per REQ-INIT-002. Entry points (`src/main.js`, `src/admin.js`) MUST use the reader; direct `loadState('mydash', ...)` calls outside the reader are forbidden by a JS-side grep lint.

#### Scenario: Reader fills defaults

- **WHEN** PHP pushes only `widgets` (other keys missing for any reason) and `loadInitialState('workspace')` runs
- **THEN** every key declared for the workspace page MUST have a defined value taken from the Data Model defaults
- **AND** no field on the returned object MUST be `undefined`

#### Scenario: Direct loadState rejected

- **WHEN** a Vue component under `src/` calls `loadState('mydash', 'something')` directly
- **THEN** the lint test (grep against `src/`) MUST fail
- **AND** the failure message MUST direct the developer to use `loadInitialState`

### Requirement: Provide-down-tree convention (REQ-INIT-004)

After the entry point loads the initial state, it MUST expose every key via `app.provide(key, value)` so descendant components can `inject(key, default)` without re-reading from `loadState`. The provide call MUST use the same key string as the initial-state key — no renaming at the boundary (renaming is itself a spec change and bumps the schema version).

#### Scenario: Provide names match initial-state keys

- **WHEN** the workspace entry loads 10 initial-state keys via the reader and calls `app.provide`
- **THEN** the entry MUST emit exactly 10 `provide` calls, one per key, with identical key strings
- **AND** no key renaming MUST occur at this boundary

#### Scenario: Components inject by key name

- **WHEN** a deep child component calls `inject('widgets', [])`
- **THEN** the component MUST receive the value the entry point provided
- **AND** the wiring MUST work without additional plumbing

### Requirement: Reactivity boundary (REQ-INIT-005)

`provide`/`inject` distributes static snapshots — values are NOT reactive across writes. Components that need to mutate shared state (for example `layout` after editing) MUST clone the injected value into a local `ref` (or hand off to a Pinia store fed from the injected value). The entry-point provides MUST NEVER be wrapped in a `ref` to "make them reactive" — that would let component code accidentally mutate the boot snapshot and break re-mount semantics.

#### Scenario: Mutation does not leak through inject

- **WHEN** component `A` injects `layout`, clones into `localLayout = ref([...injectedLayout])`, and mutates `localLayout`
- **THEN** any sibling component that injects `layout` MUST still see the original (unmutated) value
- **AND** no console error MUST fire about reactive mutation

#### Scenario: Entry point does not wrap provide in ref

- **WHEN** a code reviewer inspects `src/main.js` or `src/admin.js`
- **THEN** every `app.provide(key, value)` call MUST pass a plain (non-reactive) value
- **AND** no `app.provide(key, ref(value))` or `app.provide(key, reactive(value))` MUST appear at the entry-point boundary
