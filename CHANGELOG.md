# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

### Added

- **Initial-state contract** (REQ-INIT-001..REQ-INIT-005). Workspace and admin
  pages now route every initial-state key through the typed PHP service
  `OCA\MyDash\Service\InitialStateBuilder` (with a `Page` enum and required-key
  enforcement via `MissingInitialStateException`). The mirrored typed JS reader
  `src/utils/loadInitialState.js` returns a default-filled object and warns when
  `INITIAL_STATE_SCHEMA_VERSION` (currently `1`) drifts between server and
  client. To add a key: update the spec Data Model (REQ-INIT-002), bump
  `INITIAL_STATE_SCHEMA_VERSION` in both PHP and JS, and add the setter +
  reader entry in the same commit. Direct calls to
  `IInitialState::provideInitialState` from controllers / settings, and direct
  `loadState('mydash', ...)` calls from `src/`, are forbidden by lint.

## 0.1.0 - Initial Release

- Initial app structure
- Basic Nextcloud integration
