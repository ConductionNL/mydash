# Design — initial-state-contract

## Overview

The `initial-state-contract` capability formalises the exact set of key/value pairs PHP pushes via Nextcloud's `IInitialState::provideInitialState` for each Vue mount in MyDash (workspace + admin pages). Today these calls are scattered across `WorkspaceController` and `AdminSettings`, and the matching `loadState('mydash', ...)` reads are scattered across entry-point and component files. Drift between PHP and JS is silent — a key the backend stops sending becomes `undefined` on the frontend, a key the frontend stops reading lingers as dead PHP code, and onboarding a new page is guesswork.

This change introduces a typed PHP `InitialStateBuilder` service, a typed JS `loadInitialState(page)` reader, a versioned `_schemaVersion` key, and a CI lint pair that prevents controllers and components from bypassing the central code paths.

## Goals

- One source of truth for the per-page initial-state key set (the spec's Data Model)
- Compile-time-ish guarantees on the PHP side (typed setters + required-key check in `apply()`)
- Default-filled, never-`undefined` reads on the JS side
- A schema version that travels with the payload so deploy skew (PHP and JS bundle out of sync) shows up as a console warning instead of a mysterious bug
- Lint enforcement so bypasses cannot land

## Non-goals

- Not a Pinia replacement — `provide`/`inject` only distributes the boot snapshot; reactive shared state remains the responsibility of dedicated stores
- Not a typed-codegen pipeline — the contract is documented + grep-enforced, not generated; lower ceremony fits MyDash's size
- No runtime per-key schema validation (e.g. JSON Schema) — type discipline lives in the PHP setters and JS table; runtime validation is overkill for an internal boot payload

## Architecture

### PHP side

```
WorkspaceController::index ─┐
                            ├──> InitialStateBuilder(IInitialState, Page::WORKSPACE)
AdminSettings::getForm    ─┘    ├─ setWidgets(...)
                                ├─ setLayout(...)
                                ├─ ...
                                └─ apply()  ─→ IInitialState::provideInitialState(key, value) × N
                                              + provideInitialState('_schemaVersion', 1)
```

- `InitialStateBuilder` is the only class allowed to call `IInitialState::provideInitialState`. A grep-based CI lint enforces this against `lib/Controller/` and any other future call sites.
- `Page` is a PHP enum with two cases (`WORKSPACE`, `ADMIN`). Per-page required-key sets live in a `private const REQUIRED_KEYS = [...]` map keyed by enum case.
- `apply()` walks the required set; missing keys throw `MissingInitialStateException` (a new exception class under `lib/Exception/`) with a message naming the page and the missing key.
- `INITIAL_STATE_SCHEMA_VERSION` is a `public const int` on the builder; `apply()` always pushes it under `_schemaVersion` regardless of page.

### JS side

```
src/main.js ──┐
              ├──> loadInitialState('workspace' | 'admin')
src/admin.js ─┘    ├─ for each declared key: loadState('mydash', key, default)
                   ├─ if (received._schemaVersion !== INITIAL_STATE_SCHEMA_VERSION) console.warn(...)
                   └─ return typed object
                   
                          │
                          ▼
                   for (const [k, v] of Object.entries(state)) app.provide(k, v)
                          │
                          ▼
                   any descendant: const widgets = inject('widgets', [])
```

- `src/utils/loadInitialState.js` declares per-page key/default tables that mirror the spec's Data Model exactly.
- Entry points loop over the returned object and emit `app.provide(k, v)` for each key — the key string is preserved to keep the boundary obvious to readers.
- A grep-based CI lint forbids any `loadState\(['"]mydash['"]` call outside the reader.

## Decisions

### Why a builder instead of a configuration array?

A typed builder catches missing-key bugs at the call site rather than at render time, and the setter signatures double as living documentation of each key's shape. An array-of-arrays approach gives no IDE help and turns every typo into a runtime `undefined`.

### Why grep-lint instead of a deeper static analyser?

The bypass surface is small (two specific function calls) and a 2-line shell grep in CI catches every case. A custom Psalm plugin or ESLint rule would cost more than it saves at MyDash's scale.

### Why provide/inject and not Pinia for the boot payload?

Boot data is read-only by definition — once the page renders, the snapshot does not change. `provide`/`inject` matches that lifetime, avoids a Pinia round-trip, and makes the read sites explicit. Pinia stores still own anything that changes after boot (REQ-INIT-005).

### Why a single integer schema version instead of per-key versions?

Per-key versioning sounds nice but is heavy: every key would need a version literal, and the warning logic would need to compare a map. A single integer treats the contract as one unit (which it is — the PHP and JS sides ship together), keeps the warning trivial, and forces deliberate consideration when the table changes.

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| Developer adds a key in PHP but forgets the JS reader | CI lint pair fails; the reader's missing-key default would mask the bug at runtime, but the version bump (REQ-INIT-002) is a per-key code-review checklist item |
| Schema version not bumped on a key change | Code-review checklist item; the spec's Data Model is the single source of truth and reviewers compare against it |
| Future page with different keys needs different required set | Add a new `Page` enum case; required-key map is keyed by case so extension is local |
| Component tries to mutate an injected value | REQ-INIT-005 plus an ESLint hint in the entry-point file; runtime detection is not added (cost > benefit) |

## Seed Data

This change introduces no new OpenRegister schemas — `InitialStateBuilder` is a PHP service that reads from existing Nextcloud APIs (`IManager::getWidgets()`, `IGroupManager`, MyDash dashboard mappers) and pushes their values into Nextcloud's `IInitialState` service. There is no register, no schema, and no persisted object created by this capability.

The downstream consumers of the initial-state payload (Workspace dashboards, Widgets, Group settings, Admin Settings) are seeded by their own respective changes and capabilities; this contract simply transports their already-seeded data to the Vue mount.

No `_registers.json` entry is required for this change.

## Test strategy

- **PHPUnit (`tests/unit/Service/InitialStateBuilderTest.php`)**: per-page required-key validation, value pass-through, `_schemaVersion` always present
- **Vitest (`src/utils/__tests__/loadInitialState.spec.js`)**: default-fill behaviour, version-mismatch warning, key set per page matches the table
- **Vitest (`src/__tests__/provide-inject.spec.js`)**: mount the workspace entry against a stubbed `loadState`, assert each declared key is `inject`-able by a child component, assert mutating a cloned `ref` does not leak
- **CI lint pair** (shell scripts in `.github/workflows/`): PHP-side grep for `provideInitialState` outside the builder; JS-side grep for `loadState\(['"]mydash['"]` outside the reader
