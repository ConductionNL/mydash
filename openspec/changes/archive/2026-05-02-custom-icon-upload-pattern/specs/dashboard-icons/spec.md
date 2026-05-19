---
capability: dashboard-icons
delta: true
status: draft
---

# Dashboard Icons — Delta from change `custom-icon-upload-pattern`

## ADDED Requirements

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
