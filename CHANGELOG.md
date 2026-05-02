# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

### Added

- **Initial-state contract** (REQ-INIT-001..006): `lib/Service/InitialStateBuilder.php`
  centralises the per-page initial-state payload pushed via Nextcloud's
  `IInitialState` service. The matching JS reader at
  `src/utils/loadInitialState.js` returns a typed default-filled object for
  the workspace and admin pages. Both sides stamp / validate a
  `_schemaVersion` constant; deploy skew between PHP and JS surfaces as a
  console warning at runtime.

  Adding, removing, or renaming a key requires four coordinated edits in
  the same commit:
  1. update the spec Data Model in
     `openspec/specs/initial-state-contract/spec.md`,
  2. bump `INITIAL_STATE_SCHEMA_VERSION` in
     `lib/Service/InitialStateBuilder.php` AND
     `src/utils/loadInitialState.js`,
  3. add (or remove) the typed setter in the PHP builder and the matching
     entry in the JS reader's `PAGE_KEYS` table,
  4. update the controller(s) that call the builder.

  CI guards (`composer lint:initial-state`, `npm run lint:initial-state`)
  forbid direct `IInitialState::provideInitialState()` calls outside the
  builder and direct `loadState('mydash', ...)` calls outside the reader.

## 0.1.0 - Initial Release

- Initial app structure
- Basic Nextcloud integration
