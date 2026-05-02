# Initial-state contract

A new `initial-state-contract` capability formalises the precise set of keys that PHP pushes via `IInitialState::provideInitialState` for each Vue mount, and the precise `provide()` calls that each entry point emits to expose them to the rest of the component tree. Without this contract the keys drift silently — frontend reads a key the backend stopped sending, or vice versa, and the breakage only surfaces at runtime.

## Affected code units

- `lib/Controller/WorkspaceController.php` — workspace page initial state
- `lib/Settings/Admin/AdminSettings.php` — admin page initial state
- `src/main.js` — workspace entry, `loadState` calls + `app.provide()` mirror
- `src/admin.js` — admin entry, same
- `lib/Service/InitialStateBuilder.php` (new) — typed builder centralising the keys
- New capability `initial-state-contract`

## Why a new capability

The contract is small but load-bearing. Owning it as its own capability gives:
1. A single source of truth listing every initial-state key
2. A typed PHP builder so the controllers can't omit a key
3. A typed JS reader so the entry points can't consume a key the backend never sent
4. A spec document that reviewers can scan to understand the page-boot data flow

## Approach

- Define a typed PHP builder `InitialStateBuilder` whose constructor accepts the page (workspace | admin) and exposes per-key setter methods. `apply($initialStateService): void` writes everything; throws if a required key was not set.
- Mirror builder on JS with `loadInitialState(page): InitialState` returning a typed object — same key set per page.
- Both controllers (workspace + admin) MUST use the builder; ad-hoc `provideInitialState` calls outside it are forbidden by code review (grep).
- Keys are versioned: bumping the schema requires updating REQ-INIT-002.

## Notes

- Vue `provide`/`inject` is the down-tree distribution mechanism (no Pinia required at the entry-point boundary). Components that need cross-tree reactivity adopt Pinia stores fed from the injected initial state.
- Default values for missing keys live in the JS reader so the frontend never sees `undefined`.
