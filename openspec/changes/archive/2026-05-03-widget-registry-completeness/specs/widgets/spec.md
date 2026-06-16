# Spec delta — widgets

## ADDED Requirements

### Requirement: REQ-WDG-023 Widget registry completeness verifiable in CI

The widget registry (`src/constants/widgetRegistry.js`) MUST be covered by an explicit completeness test (`src/constants/__tests__/widgetRegistry.completeness.spec.js`) that asserts a canonical EXPECTED_TYPES set:

- `calendar`, `container`, `divider`, `files`, `header`, `image`, `label`, `link`, `links`, `menu`, `nc-widget`, `news`, `people`, `quicklinks`, `text`, `tile`, `video` (current set; updated when widget capabilities are added or removed)

The test MUST fail if:

- A registered type is missing from EXPECTED_TYPES (registry has more types than expected -> drift undocumented)
- An EXPECTED_TYPES entry is missing from the registry (regression -- type silently disappeared from the picker)
- Any registered entry lacks `renderer`, `form`, `displayName`, `defaultContent`, or `icon` fields (incomplete entry -> would be filtered out by REQ-WDG-014's `listWidgetTypes`, hiding it from users)

When a new widget capability lands, the EXPECTED_TYPES constant MUST be updated in the same commit. When a widget capability is deprecated, EXPECTED_TYPES MUST be updated in the same commit as its registry removal.

#### Scenario: Test fails when a widget type is silently dropped

- **GIVEN** the registry currently contains every member of EXPECTED_TYPES (including `tile`)
- **AND** EXPECTED_TYPES is the same set
- **WHEN** a refactor accidentally removes the `tile` entry from `widgetRegistry.js` without updating EXPECTED_TYPES
- **THEN** `npm test` MUST fail with a clear diff message naming `tile` as the missing type

#### Scenario: Test fails when registry adds a type without updating EXPECTED_TYPES

- **GIVEN** EXPECTED_TYPES lists the canonical set
- **WHEN** a new widget capability adds `chart` to `widgetRegistry.js` without updating EXPECTED_TYPES
- **THEN** `npm test` MUST fail with a diff message naming `chart` as the unexpected addition
- **AND** the failure message MUST instruct: "Update EXPECTED_TYPES in widgetRegistry.completeness.spec.js"

#### Scenario: Test fails when an entry is incomplete

- **GIVEN** a new widget type added to the registry without a `form` field (set to `null`)
- **WHEN** `npm test` runs
- **THEN** the test MUST fail with a clear message naming the type and the missing field
