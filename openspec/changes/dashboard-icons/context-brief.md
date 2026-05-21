---
capability: dashboard-icons
status: implemented
---

# Dashboard Icons Specification

## Purpose

MyDash dashboards (and the dashboard-list items in the switcher sidebar
and admin UI) display an icon next to their name. This capability owns
the icon vocabulary: a small curated registry of named built-in icons
that live in the frontend bundle, plus the lookup/render functions
consumers use, plus the convention for storing per-dashboard icons in a
single column that may also hold an uploaded resource URL.

The backend stores `dashboards.icon` as an opaque string and never
inspects it — discrimination between registry name and uploaded URL
lives in the frontend.

## Context

The icon system has no backend persistence of its own — it operates on
the existing `oc_mydash_dashboards.icon` column (and any other column
that follows the same convention, e.g. `oc_mydash_widget_placements.tileIcon`).

## Field Convention

The `icon` field convention (REQ-ICON-009) is single-column:

- **NULL** or empty string → render `DEFAULT_ICON`
- A **registered icon name** (e.g. `'ViewDashboard'`, `'Home'`) → look up in `DASHBOARD_ICONS`
- A **URL** (starts with `/` or `http`) → render via `<img>`; the URL is
  produced by the `resource-uploads` capability when an admin uploads a
  custom image through `IconPicker`

Discrimination is purely runtime via `isCustomIconUrl()`. There is no
typed-discriminator object and no schema migration required when a
value flips between built-in and custom forms.

## Frontend Exports

| Export | Type | Purpose |
|---|---|---|
| `DASHBOARD_ICONS` | `Record<string, VueComponent>` | Map from icon name → component import |
| `DEFAULT_ICON` | `string` | The fallback name (currently `'ViewDashboard'`) |
| `getIconComponent(name: string \| null)` | `VueComponent \| null` | Look up; returns `null` for URL inputs (per REQ-ICON-006) and `DEFAULT_ICON` for null/empty/unknown registry names |
| `isCustomIconUrl(name: string \| null)` | `boolean` | True when name starts with `/` or `http` |

Two shared Vue components consume these exports:

- `IconRenderer` — dual-mode `<img>` / `<component :is>` renderer
  (REQ-ICON-007). Consumers MUST use this rather than branching on the
  icon shape themselves.
- `IconPicker` — combined registry-`<select>` + file-upload picker
  (REQ-ICON-008) with a 24×24 live preview through `IconRenderer` and
  previous-value preservation on upload error.

## Requirements

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

### Requirement: URL/name discriminator (REQ-ICON-005)

The system MUST expose a pure function `isCustomIconUrl(name: string|null): boolean` that returns `true` when `name` is a non-null string AND begins with either `'/'` or `'http'`. All other inputs (built-in registry names, null, undefined, empty string) MUST return `false`. The function MUST NOT call any side-effecting code (no fetch, no DOM access, no globals).

#### Scenario: URL inputs return true

- WHEN `isCustomIconUrl('/apps/mydash/resource/abc123.png')` is called
- THEN it MUST return `true`
- AND `isCustomIconUrl('https://example.com/icon.svg')` MUST return `true`
- AND `isCustomIconUrl('http://example.com/icon.png')` MUST return `true`

#### Scenario: Registry names return false

- WHEN `isCustomIconUrl('Star')` is called
- THEN it MUST return `false`
- AND `isCustomIconUrl('ViewDashboard')` MUST return `false`

#### Scenario: Null and empty inputs return false

- WHEN `isCustomIconUrl(null)` is called
- THEN it MUST return `false`
- AND `isCustomIconUrl('')` MUST return `false`
- AND `isCustomIconUrl(undefined)` MUST return `false`

### Requirement: getIconComponent returns null for URLs (REQ-ICON-006)

`getIconComponent(name)` MUST return `null` when `isCustomIconUrl(name)` is true. Callers MUST then render the URL via `<img>` (not via `<component :is>`). This is the contract that lets the picker, switcher, and admin list use a single render component.

#### Scenario: URL input yields a null component

- GIVEN the discriminator considers `/apps/mydash/resource/x.png` a custom URL
- WHEN `getIconComponent('/apps/mydash/resource/x.png')` is called
- THEN it MUST return `null`
- AND callers MUST interpret `null` as "render via `<img>`", NOT as "use the default icon"

#### Scenario: Unknown built-in name still returns the default component

- GIVEN `isCustomIconUrl('NotARegistryEntry')` is `false`
- WHEN `getIconComponent('NotARegistryEntry')` is called
- THEN it MUST return the `DEFAULT_ICON` component (per REQ-ICON-001)
- AND MUST NOT return `null`

### Requirement: IconRenderer dual-mode rendering (REQ-ICON-007)

The shared `IconRenderer` component MUST accept a single `name` prop (string or null) and render either an `<img>` or a `<component :is>` based on `isCustomIconUrl(name)`. Consumers MUST use `IconRenderer` rather than branching on the icon shape themselves.

#### Scenario: Render a built-in icon

- GIVEN `IconRenderer` is rendered with prop `name="Star"`
- THEN the DOM MUST contain a `<svg>` element (the Star MDI component)
- AND MUST NOT contain an `<img>` element

#### Scenario: Render a custom URL icon

- GIVEN `IconRenderer` is rendered with prop `name="/apps/mydash/resource/abc.png"`
- THEN the DOM MUST contain `<img src="/apps/mydash/resource/abc.png">`
- AND MUST NOT contain a `<component>` rendering of an MDI icon

#### Scenario: Render with null name falls back to default

- GIVEN `IconRenderer` is rendered with prop `name={null}`
- THEN it MUST render the `DEFAULT_ICON` component
- AND MUST NOT throw
- AND MUST NOT render an empty placeholder

#### Scenario: Alt text for custom URL icons

- GIVEN `IconRenderer` is rendered with `name="/apps/mydash/resource/abc.png"` and an `alt` prop of `"Marketing"`
- THEN the rendered `<img>` MUST have `alt="Marketing"`
- AND when no `alt` prop is supplied the rendered `<img>` MUST fall back to a non-empty default (e.g. the dashboard or widget name)

### Requirement: IconPicker dual-input UX (REQ-ICON-008)

The `IconPicker` component MUST present BOTH affordances visible simultaneously: a `<select>` of registry option names (per REQ-ICON-003) AND an "Upload icon" `<input type="file" accept="image/*">` button. Selecting either MUST update the same `v-model` value: a registry option assigns the option string, an upload POSTs to the resource-uploads endpoint and assigns the returned URL string. A 24×24 preview thumbnail of the current value MUST be rendered via `IconRenderer`.

#### Scenario: Switching from built-in to custom

- GIVEN `IconPicker` v-model is currently `"Star"`
- WHEN the user uploads a file successfully and the upload returns URL `/apps/mydash/resource/abc.png`
- THEN the v-model value MUST become `/apps/mydash/resource/abc.png`
- AND the preview MUST switch from `<svg>` (Star) to `<img>` (the uploaded image)

#### Scenario: Switching from custom back to built-in

- GIVEN v-model value is `/apps/mydash/resource/abc.png`
- WHEN the user picks `"Home"` from the `<select>`
- THEN the v-model value MUST become `"Home"`
- AND the preview MUST switch from `<img>` to `<svg>` (Home)
- NOTE: switching away does NOT delete the previously uploaded resource (resource lifecycle is owned by `resource-uploads` capability)

#### Scenario: Upload error preserves previous value

- GIVEN `IconPicker` v-model is currently `"Star"`
- WHEN the user attempts an upload that fails (HTTP error or non-image MIME rejected by the upload endpoint)
- THEN the v-model value MUST remain `"Star"`
- AND the picker MUST surface a visible error state to the user
- AND the preview MUST continue to show the Star icon

### Requirement: Field convention is single-column (REQ-ICON-009)

Database columns that store an icon (currently `oc_mydash_dashboards.icon` and `oc_mydash_widget_placements.tileIcon`) MUST hold either a registry name OR a URL string OR NULL — never a typed-discriminator object like `{kind: 'name'|'url', value: ...}`. Discrimination is purely runtime via `isCustomIconUrl`. No schema migration is required when a value flips between built-in and custom forms.

#### Scenario: Mixed values across rows render without migration

- GIVEN three dashboards exist with `icon` values `'Star'`, `'/apps/mydash/resource/x.png'`, and `null`
- WHEN any consumer reads them and passes each `icon` to `IconRenderer`
- THEN no migration or transformation is required
- AND all three dashboards MUST render correctly (svg, img, default svg respectively)

#### Scenario: Switching a dashboard's icon between forms is a plain UPDATE

- GIVEN dashboard `D1` has `icon = 'Star'`
- WHEN the admin uploads a custom icon for `D1` and the picker resolves to URL `/apps/mydash/resource/y.png`
- THEN persisting the change MUST be a single `UPDATE oc_mydash_dashboards SET icon = '/apps/mydash/resource/y.png' WHERE id = ?`
- AND no auxiliary table or column MUST be touched
