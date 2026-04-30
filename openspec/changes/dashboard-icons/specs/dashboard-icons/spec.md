---
capability: dashboard-icons
delta: true
status: draft
---

# Dashboard Icons — Delta from change `dashboard-icons`

## Context

MyDash dashboards (and dashboard list items in the switcher sidebar and admin UI) display an icon next to their name. This capability owns the icon vocabulary: a small curated registry of named built-in icons that live in the frontend bundle, plus the lookup/render functions that consumers use. A separate change (`custom-icon-upload-pattern`) extends this capability so the same `icon` field can also hold an uploaded resource URL.

The icon system has no backend persistence of its own — it operates on the existing `oc_mydash_dashboards.icon` column (and any other column that follows the same convention, e.g. `oc_mydash_widget_placements.tileIcon`).

The `icon` field convention:
- **NULL or empty string** → render `DEFAULT_ICON`
- **A registered icon name** (e.g. `'ViewDashboard'`, `'Home'`) → look up in `DASHBOARD_ICONS`
- **A URL** (starts with `/` or `http`) → see `custom-icon-upload-pattern` (out of scope for this base capability)

Frontend exports:

| Export | Type | Purpose |
|---|---|---|
| `DASHBOARD_ICONS` | `Record<string, VueComponent>` | Map from icon name → component import |
| `DEFAULT_ICON` | `string` | The fallback name (currently `'ViewDashboard'`) |
| `getIconComponent(name: string \| null)` | `VueComponent` | Look up; falls back to `DEFAULT_ICON` for null/empty/unknown |
| `isCustomIconUrl(name: string \| null)` | `boolean` | True when name starts with `/` or `http` (consumed by `custom-icon-upload-pattern`) |

## ADDED Requirements

### Requirement: REQ-ICON-001 Curated registry of built-in icons

The system MUST maintain a curated registry of at least 15 built-in dashboard icons drawn from `vue-material-design-icons`. The registry MUST include at minimum these names: `ViewDashboard`, `Home`, `ChartBar`, `Cog`, `AccountGroup`, `Calendar`, `FileDocument`, `Bell`, `Star`, `Heart`, `BookOpenVariant`, `Lightbulb`, `RocketLaunch`, `Earth`, `Briefcase`. Any consumer that renders an icon by name MUST resolve it through this registry — there MUST NOT be parallel ad-hoc registries elsewhere.

#### Scenario: Resolve a built-in name

- GIVEN the registry contains `'Star'`
- WHEN `getIconComponent('Star')` is called
- THEN it MUST return the `vue-material-design-icons/Star.vue` component reference

#### Scenario: Resolve an unknown name falls back to default

- GIVEN the registry does not contain `'NonExistent'`
- WHEN `getIconComponent('NonExistent')` is called
- THEN it MUST return the component for `DEFAULT_ICON` (currently `ViewDashboard`)

#### Scenario: Default icon is `'ViewDashboard'`

- GIVEN the constants module is loaded
- WHEN `DEFAULT_ICON` is read
- THEN its value MUST equal the string `'ViewDashboard'`
- AND `DASHBOARD_ICONS[DEFAULT_ICON]` MUST be defined

#### Scenario: Registry contains all 15 named icons

- GIVEN the registry module is loaded
- WHEN `Object.keys(DASHBOARD_ICONS)` is inspected
- THEN it MUST contain every name listed in this requirement
- AND its length MUST be at least 15

### Requirement: REQ-ICON-002 Null and empty handling

`getIconComponent` MUST tolerate `null`, `undefined`, and empty string inputs — all of these MUST resolve to the `DEFAULT_ICON` component. The function MUST NOT throw and MUST NOT return `null` for these inputs.

#### Scenario: Null input

- GIVEN any caller invokes the helper with no name available
- WHEN `getIconComponent(null)` is called
- THEN it MUST return the `DEFAULT_ICON` component
- AND it MUST NOT throw

#### Scenario: Undefined input

- GIVEN a dashboard record where `icon` is `undefined`
- WHEN `getIconComponent(undefined)` is called
- THEN it MUST return the `DEFAULT_ICON` component
- AND it MUST NOT throw

#### Scenario: Empty string input

- GIVEN a dashboard record where `icon` is the empty string
- WHEN `getIconComponent('')` is called
- THEN it MUST return the `DEFAULT_ICON` component
- AND it MUST NOT throw

### Requirement: REQ-ICON-003 Single picker source for admin UI

When the admin UI renders an icon picker (e.g. when creating or editing a dashboard), the available options MUST be enumerated from `Object.keys(DASHBOARD_ICONS)` directly. Hardcoding option lists in the picker template is forbidden so the picker stays in lock-step with the registry whenever icons are added or removed.

#### Scenario: Picker reflects registry size

- GIVEN the registry has 15 entries
- WHEN the icon picker `<select>` renders
- THEN it MUST emit exactly 15 `<option>` elements (one per registry key)
- AND each option's `value` MUST match a registry key string

#### Scenario: Picker stays in sync when registry grows

- GIVEN the registry is extended to 17 entries in a future change
- WHEN the icon picker `<select>` renders without code changes
- THEN it MUST emit exactly 17 `<option>` elements

### Requirement: REQ-ICON-004 Tree-shakeable imports

Each icon component import in the registry module MUST be a separate `import` statement so production bundles only include the icons actually referenced by the registry. Bulk wildcard imports (`import * as icons from 'vue-material-design-icons'`) MUST NOT be used in this module.

#### Scenario: Bundle includes only referenced icons

- GIVEN a production webpack build with no other consumers of `vue-material-design-icons`
- WHEN the bundled `js/main.js` is inspected
- THEN it MUST include at most the 15 icons listed in REQ-ICON-001
- AND it MUST NOT include the full library

#### Scenario: No wildcard imports in registry module

- GIVEN the source file `src/constants/dashboardIcons.js`
- WHEN its import statements are inspected
- THEN none MUST use `import *` syntax against `vue-material-design-icons`
- AND each registered icon MUST have its own dedicated `import …Icon from 'vue-material-design-icons/<Name>.vue'` line
