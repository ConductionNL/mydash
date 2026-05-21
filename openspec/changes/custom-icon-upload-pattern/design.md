# Design — custom-icon-upload-pattern

## Reuse analysis

| Capability | Reuse from | Why |
|------------|-----------|-----|
| Icon registry | `src/constants/dashboardIcons.js` | Existing module provides the MDI component registry (`Star`, `ViewDashboard`, etc.). This change extends it with a discriminator. |
| File upload | `resource-uploads` capability | The actual file upload endpoint and resource URL format (`/apps/mydash/resource/{id}`) is owned by the parallel `resource-uploads` change. `IconPicker` POST-forwards the file to that endpoint. |
| Shared Vue components | `@conduction/nextcloud-vue` | Use provided components (`NcSelect`, `NcButton`, etc.) — build no custom wrapper layers. |

### What we deliberately do NOT reuse

- **Custom icon storage service** — The icon field is a simple string column; no new service layer is needed. Icon URL → resource lookup is owned by the resource-uploads capability.
- **Separate database column for icon type** — Per ADR-011 (schema standards), avoid type discriminator columns when a runtime discriminator (`isCustomIconUrl`) suffices. Single-column design avoids schema migration.
- **Icon validation on the backend** — Size/MIME validation lives in the `resource-uploads` capability. MyDash treats the returned URL as valid.

## Public API / migration shape

### `src/constants/dashboardIcons.js` (extend)

**New exports:**
- `isCustomIconUrl(name: string|null|undefined): boolean` — pure discriminator returning `true` when `name` is a non-null string AND begins with `/` or `http`
- Updated `getIconComponent(name)` to return `null` for URL inputs (previously fell back to `DEFAULT_ICON`)

```javascript
/**
 * Discriminator: returns true when the icon value is a custom URL (not a registry name).
 * URLs begin with '/' (local path) or 'http' (absolute URL).
 * All other inputs (registry names, null, undefined) return false.
 *
 * @param {string|null|undefined} name
 * @returns {boolean}
 */
export const isCustomIconUrl = (name) => {
  return typeof name === 'string' && (name.startsWith('/') || name.startsWith('http'))
}

/**
 * Updated: returns null for URL inputs, signalling callers to render via <img>.
 * For registry names (including unknown names), returns the component.
 *
 * @param {string|null|undefined} name
 * @returns {Function|object|null}
 */
export const getIconComponent = (name) => {
  if (isCustomIconUrl(name)) {
    return null // Caller must render via <img>
  }
  // Existing logic for registry names
  return ICON_REGISTRY[name] ?? DEFAULT_ICON
}
```

### `src/components/Dashboard/IconRenderer.vue` (new)

Dual-mode renderer component for use across the app. Accepts `name` and renders either an `<img>` or an SVG component.

**Props:**
- `name` (string|null): The icon value (registry name, URL, or null)
- `alt` (string, optional): Alt text for `<img>` fallback. Defaults to a non-empty label.
- `size` (number, default 24): Icon size in pixels

**Template:**
```vue
<template>
  <img
    v-if="isCustomIconUrl(name)"
    :src="name"
    :alt="alt || defaultAlt"
    :width="size"
    :height="size"
    class="icon-renderer__img"
  />
  <component
    v-else
    :is="getIconComponent(name)"
    :size="size"
    class="icon-renderer__component"
  />
</template>
```

**Usage:**
```vue
<!-- Render whatever the icon field contains (built-in name, URL, or null) -->
<IconRenderer
  :name="dashboard.icon"
  :alt="dashboard.label"
  :size="24"
/>
```

### `src/components/Dashboard/IconPicker.vue` (new)

Combined input component presenting both a registry `<select>` and a file upload input, with live preview.

**Props:**
- `modelValue` (string|null): Current icon value (registry name, URL, or null)
- `registryOptions` (Array): List of registry name strings to populate the `<select>`

**Emits:**
- `update:modelValue` with the new value (registry name string or URL string)

**Template sections:**
1. **Registry select** — dropdown of option names
2. **Upload input** — file input `accept="image/*"`
3. **Loading/error states** — spinner during upload, visible error message on failure
4. **Live preview** — 24×24 `IconRenderer` showing the current `modelValue`

**Behavior:**
- Selecting a registry option → emit the option string immediately
- Selecting a file → POST to the `resource-uploads` endpoint, then emit the returned URL (or preserve previous value on error)
- Upload errors surface via a red-text error message; the preview continues to show the previous `modelValue`

**Example usage:**
```vue
<IconPicker
  :model-value="dashboard.icon"
  :registry-options="iconOptions"
  @update:model-value="(val) => dashboard.icon = val"
/>
```

### `lib/Db/Dashboard.php` (docblock update)

Update the `icon` field docblock to document the dual-format convention:

```php
/**
 * Icon identifier or URL.
 *
 * May contain either:
 * - A registry name (string, e.g. "Star", "ViewDashboard") referring to a built-in MDI component
 * - A resource URL (string starting with '/' or 'http', e.g. "/apps/mydash/resource/abc123.png")
 * - NULL for no icon (displays the default icon)
 *
 * Discrimination is purely runtime via `isCustomIconUrl()` in src/constants/dashboardIcons.js.
 * No database schema change required when a value switches between forms.
 *
 * @var string|null
 */
protected ?string $icon = null
```

### `lib/Db/WidgetPlacement.php` (docblock update)

Update the `tileIcon` field docblock with the same convention:

```php
/**
 * Icon identifier or URL for this tile.
 *
 * May contain either:
 * - A registry name (string, e.g. "Star", "Home") referring to a built-in MDI component
 * - A resource URL (string starting with '/' or 'http')
 * - NULL for no icon
 *
 * Discrimination is runtime via `isCustomIconUrl()` in src/constants/dashboardIcons.js.
 *
 * @var string|null
 */
protected ?string $tileIcon = null
```

## Call-site refactoring

### Affected components and the pattern swap:

| Component/File | Current pattern | New pattern |
|---|---|---|
| `DashboardSwitcher.vue` | `v-if="icons.isUrl(dashboard.icon)"` + conditional `<img>` vs `<component>` | Use `<IconRenderer :name="dashboard.icon">` |
| Admin dashboard list page | Inline if/else branching | Use `<IconRenderer>` |
| Link-button widget icon | Manual discriminator in template | Use `<IconRenderer>` |
| Tile editor | Hardcoded icon select + manual upload handling | Use `<IconPicker>` |
| Dashboard create/edit form | Hardcoded icon select | Use `<IconPicker>` |

All call sites must be refactored in Task 7 to eliminate duplicated if/else logic.

## Seed data

No new database entities introduced. The existing `oc_mydash_dashboards` and `oc_mydash_widget_placements` tables are extended via docblock updates only — no schema migration, no seed data injection.

Example state after the change is merged:
```sql
-- Existing dashboards with built-in icons continue unchanged
UPDATE oc_mydash_dashboards SET icon = 'Star' WHERE ...;
UPDATE oc_mydash_dashboards SET icon = 'ViewDashboard' WHERE ...;

-- New dashboards may have custom URLs alongside built-ins
UPDATE oc_mydash_dashboards SET icon = '/apps/mydash/resource/org-logo.png' WHERE ...;

-- Mixed values in one query render cleanly — no migration required
SELECT id, label, icon FROM oc_mydash_dashboards;
-- id | label              | icon
-- 1  | "IT Dashboard"     | "Star"
-- 2  | "Org Dashboard"    | "/apps/mydash/resource/org-logo.png"
-- 3  | "Empty Icon"       | NULL
```

## Migration risk surface

| Risk | Mitigation |
|------|-----------|
| Existing dashboards with built-in icon names render unchanged | The discriminator treats any non-URL string as a registry name. No migration needed. |
| Admin switches a dashboard from custom URL back to built-in | The value is a plain UPDATE — no orphan cleanup code runs. Resource lifecycle (garbage collection of unused uploads) is owned by `resource-uploads`. |
| Upload endpoint returns a malformed response | `IconPicker` wraps the POST in try/catch; on error, the previous `modelValue` is preserved and an error message surfaces. |
| Unknown registry name appears in the `icon` field | `getIconComponent` returns `DEFAULT_ICON` per REQ-ICON-001 (unchanged behavior). |
| `isCustomIconUrl` heuristic conflicts with a future built-in registry entry starting with `/` or `http` | The registry today contains none. Future registry additions MUST be validated against the discriminator. Document this constraint in the registry's inline comments. |

## Design questions and decisions

**Q1: Why not a discriminator column?**  
A: Schema migration cost + query bloat. Every read of the icon field would need to consult a `iconType` discriminator. Single-column design via `isCustomIconUrl()` runtime discriminator avoids this. ADR-011 (schema standards) prefers runtime discrimination when possible.

**Q2: Why not validate MIME/size on the backend?**  
A: The `resource-uploads` capability owns file validation. MyDash's icon field accepts whatever URL is returned — trust the upstream validator.

**Q3: Upload endpoint details?**  
A: Owned by the parallel `resource-uploads` change. This change assumes it exists and returns a URL in the format `/apps/mydash/resource/{id}`.

**Q4: Backwards compatibility for code reading `icon` directly?**  
A: Code that reads `icon` from the database must pipe it through `isCustomIconUrl()` + `getIconComponent()` or use `IconRenderer`. This is enforced by refactoring all call sites in Task 7 and linting in Task 8 (no remaining inline branches).

**Q5: What if someone manually edits the database?**  
A: If a manual UPDATE puts a malformed value (e.g. `"http missing-slash"`), `getIconComponent()` returns `DEFAULT_ICON` and the UI renders the default. No crash, graceful fallback.
