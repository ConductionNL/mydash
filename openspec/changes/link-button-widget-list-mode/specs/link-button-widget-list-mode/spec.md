---
status: draft
---

# Link-Button Widget List Mode

## ADDED Requirements

### Requirement: REQ-LBLM-001 Display mode configuration

The link-button widget MUST support a `displayMode ENUM('button','list')` field on its widget-config record. The field MUST default to `'button'` to preserve backward compatibility with existing single-button placements. When `displayMode = 'button'`, the widget renders a single button using only the first entry from the `links` array (or legacy single-link fields). When `displayMode = 'list'`, the widget renders a full vertical or horizontal list of multiple links per the list rendering requirements.

#### Scenario: Display mode field exists with button default
- GIVEN a new link-button-widget placement is created without specifying `displayMode`
- WHEN the placement is retrieved via API
- THEN the placement MUST have `displayMode = 'button'`

#### Scenario: Existing placements remain valid without displayMode
- GIVEN a placement created before list-mode support exists (no `displayMode` field in data)
- WHEN the placement is retrieved via API
- THEN the system MUST treat it as `displayMode = 'button'` implicitly
- AND the widget MUST render correctly using legacy single-link fields

#### Scenario: Display mode can be set to list
- GIVEN a placement update includes `displayMode = 'list'`
- WHEN the update is saved
- THEN the placement MUST have `displayMode = 'list'`

### Requirement: REQ-LBLM-002 Links array schema

The widget placement MUST support a `links JSON` field typed as an array of link objects. Each link object MUST contain:

```json
{
  "label": "string (required)",
  "url": "string (required)",
  "icon": "string (optional, name or URL)",
  "actionType": "enum: 'url' | 'action_id' | 'createFile' (required)",
  "value": "string (optional, populated only for createFile)"
}
```

When `displayMode = 'button'`, only the first entry in the `links` array is used, preserving existing single-button behaviour. When `displayMode = 'list'`, all entries in the array are rendered. The `links` field MAY be empty or null for `displayMode = 'button'` placements (legacy single-link fields take precedence); it MUST be a non-empty array for `displayMode = 'list'`.

#### Scenario: Links array stored on placement
- GIVEN a placement with `displayMode = 'list'` and `links = [{label: 'Docs', url: '...', actionType: 'url', ...}, ...]`
- WHEN the placement is retrieved
- THEN the full `links` array MUST be present in the response

#### Scenario: Single-button mode ignores links array
- GIVEN a placement with `displayMode = 'button'`, a populated `links` array, AND legacy single-link fields (`url`, `icon`)
- WHEN the widget renders
- THEN the legacy fields MUST be used
- AND the `links` array MUST NOT be rendered

#### Scenario: Links array can be empty for button mode
- GIVEN a placement with `displayMode = 'button'` and `links = []`
- WHEN the widget renders
- THEN the system MUST fall back to legacy single-link fields
- AND no error MUST occur

### Requirement: REQ-LBLM-003 Action type reuse for list items

Each link entry in the `links` array MUST follow the same three action-type specification as the existing single-button widget (from REQ-LBN-001):

1. `'url'` — External link: `window.open(url, '_blank', 'noopener,noreferrer')`
2. `'action_id'` — Internal action: resolved against the internal action registry and invoked if registered
3. `'createFile'` — File creation: opens a filename-prompt modal (per REQ-LBN-003) and creates a new file via `POST /api/files/create`

For `'createFile'` actions, the `value` field MUST contain the file extension (e.g., `'docx'`, `'txt'`). Per-link click handlers MUST respect the dashboard edit-mode suppression (no actions fire when `canEdit === true` and `isAdmin === true`).

#### Scenario: List item with external link
- GIVEN a list item with `actionType: 'url'` and `url: 'https://example.com'`
- WHEN the user clicks the list item (not in edit mode)
- THEN the system MUST open the URL in a new tab

#### Scenario: List item with internal action
- GIVEN a list item with `actionType: 'action_id'` and `url: 'open-files'` (a registered internal action)
- WHEN the user clicks the list item
- THEN the system MUST invoke the registered function for `'open-files'`

#### Scenario: List item with createFile action
- GIVEN a list item with `actionType: 'createFile'`, `label: 'New Report'`, and `value: 'docx'`
- WHEN the user clicks the list item
- THEN the system MUST open the createFile modal (per REQ-LBN-003)
- AND on success, create a file with `.docx` extension

#### Scenario: List item click suppressed in edit mode
- GIVEN a widget in a dashboard with `canEdit === true` and `isAdmin === true`
- AND the widget is rendering in list mode
- WHEN the user clicks any list item
- THEN no action MUST fire

### Requirement: REQ-LBLM-004 Icon resolution per list item

Each link entry's `icon` field MUST follow the same dual-mode convention as REQ-LBN-002:

- A URL (starts with `/` or `http`) MUST render as `<img>` 
- A bare name MUST render via the shared `IconRenderer` (MDI component)
- An empty or null value MUST render no icon

Icon size MUST be consistent across all list items (24 px square for list mode; 48 px for compact/normal/spacious variants may adjust padding but not icon size). The icon MUST appear inline (left of the label in vertical mode, above in horizontal mode per list orientation).

#### Scenario: Custom icon URL in list item
- GIVEN a list item with `icon: '/apps/mydash/icons/report.png'` and `label: 'Q4 Report'`
- WHEN the widget renders in list mode
- THEN the item MUST show the custom image followed by the label text

#### Scenario: Icon name in list item
- GIVEN a list item with `icon: 'folder'` (an MDI icon name) and `label: 'Browse files'`
- WHEN the widget renders
- THEN the item MUST display the MDI folder icon followed by the label

#### Scenario: No icon in list item
- GIVEN a list item with `icon: ''` and `label: 'Click here'`
- WHEN the widget renders
- THEN no icon MUST appear
- AND only the label MUST be visible

### Requirement: REQ-LBLM-005 List orientation and spacing

The widget MUST support `listOrientation ENUM('vertical','horizontal')` (default: `'vertical'`) and `listItemGap ENUM('compact','normal','spacious')` (default: `'normal'`) configuration fields on the placement.

Vertical mode MUST render the list as `<ul role="list">` with each item as `<li>`, stacked vertically using flexbox. Horizontal mode MUST render as `<div role="list">` with each item as `<div role="listitem">`, laid out inline as horizontal pills with flex wrapping.

The `listItemGap` values control inter-item spacing:
- `'compact'` — 0.5 rem gap
- `'normal'` — 1 rem gap
- `'spacious'` — 1.5 rem gap

#### Scenario: Vertical list orientation
- GIVEN a widget with `displayMode: 'list'`, `listOrientation: 'vertical'`, and 3 links
- WHEN the widget renders
- THEN the items MUST be stacked vertically
- AND the HTML MUST use `<ul role="list">` as the container

#### Scenario: Horizontal list orientation
- GIVEN a widget with `displayMode: 'list'`, `listOrientation: 'horizontal'`, and 3 links
- WHEN the widget renders
- THEN the items MUST be laid out inline (horizontally)
- AND the HTML MUST use `<div role="list">` as the container
- AND the container MUST have flex wrapping enabled

#### Scenario: List item spacing
- GIVEN a widget with `listItemGap: 'spacious'`
- WHEN the widget renders
- THEN the gap between items MUST be 1.5 rem (CSS `gap: 1.5rem`)

#### Scenario: Orientation and gap default to vertical and normal
- GIVEN a placement with `displayMode: 'list'` but no `listOrientation` or `listItemGap` fields
- WHEN the placement is retrieved
- THEN `listOrientation` MUST default to `'vertical'`
- AND `listItemGap` MUST default to `'normal'`

### Requirement: REQ-LBLM-006 Edit form integration

The edit form for a link-button-widget placement MUST gain:

1. A "Display mode" toggle/select switching between `'button'` and `'list'`
2. A list editor UI (only visible when `displayMode = 'list'`) with:
   - A drag-to-reorder handle for each link
   - An "Add link" button to append a new entry
   - An "Edit" button per link opening a modal with the existing single-link form (label, url, actionType, icon, backgroundColor, textColor)
   - A "Remove" button per link to delete that entry

The single-link form MUST be reused unchanged inside the list editor modal for consistency. When editing in button mode, only the first link's fields are exposed; the full links array remains hidden to the user.

#### Scenario: Display mode toggle in edit form
- GIVEN the edit form for a link-button-widget placement
- WHEN the user toggles display mode from `'button'` to `'list'`
- THEN the form MUST show the list editor section
- AND the legacy single-link fields MUST be hidden or disabled

#### Scenario: Add link button appends to array
- GIVEN the list editor with 2 existing links
- WHEN the user clicks "Add link"
- THEN a new empty entry MUST be appended to the `links` array
- AND a form modal MUST open for the user to fill in the new link's details

#### Scenario: Edit link reuses single-link form
- GIVEN the list editor with 3 links
- WHEN the user clicks "Edit" on the second link
- THEN the existing single-link form modal MUST open pre-populated with that link's values

#### Scenario: Remove link deletes from array
- GIVEN the list editor with 3 links
- WHEN the user clicks "Remove" on the first link
- THEN the first link MUST be deleted from the `links` array
- AND the remaining 2 links MUST shift down in order

#### Scenario: Drag to reorder list items
- GIVEN the list editor with 3 links A, B, C
- WHEN the user drags B above A
- THEN the `links` array order MUST become [B, A, C]

### Requirement: REQ-LBLM-007 Validation and constraints

The system MUST enforce the following validation rules:

- When `displayMode = 'list'`, the `links` field MUST be a non-empty array; if the user tries to save without any links, the form MUST show an error `'At least one link is required for list mode'`.
- Each link in the `links` array MUST have non-empty `label` and `url` fields; if either is empty, the form MUST prevent saving.
- When `displayMode = 'button'`, the `links` field MAY be empty or contain up to one entry; if `links` is empty, the legacy single-link fields (URL, icon) MUST be used.
- The invariant `displayMode = 'button' XOR links is non-empty array` MUST NOT be enforced by the backend — the frontend form ensures this before submission.

#### Scenario: List mode requires non-empty links array
- GIVEN a placement with `displayMode: 'list'` and `links: []`
- WHEN the user attempts to save
- THEN the form MUST display an error message
- AND the save MUST be prevented

#### Scenario: Each link requires label and url
- GIVEN a list editor with a link missing the `label` field
- WHEN the user attempts to save
- THEN the form MUST show an error
- AND highlight the offending link

#### Scenario: Button mode with empty links falls back to legacy fields
- GIVEN a placement with `displayMode: 'button'`, `links: []`, and `url: 'https://example.com'`, `icon: 'external'`
- WHEN the widget renders
- THEN the button MUST open the URL from the legacy field
- AND no error MUST occur

### Requirement: REQ-LBLM-008 Rendering semantics

The list-mode renderer MUST:

1. Wrap the list in `<ul role="list">` (vertical) or `<div role="list">` (horizontal)
2. Render each link as a `<li>` (vertical) or `<div role="listitem">` (horizontal)
3. Inside each item, use the same button styling primitives as the single-button mode (background color, text color, hover lift effect, 2 px up translation)
4. Preserve accessibility: each item MUST have a semantic label derived from its `label` field
5. Inline styles MUST use CSS variables and inline `style` attributes; no hardcoded colours in the HTML

When icon + label are present, the layout MUST be: icon (left, inline) followed by label text (no wrapping). Icon size MUST be 24 px in list mode. Label text MUST be left-aligned.

#### Scenario: Vertical list renders as ul with li
- GIVEN a widget with `displayMode: 'list'`, `listOrientation: 'vertical'`, and 2 links
- WHEN the widget renders
- THEN the HTML structure MUST be:
  ```html
  <ul role="list">
    <li><!-- item 1 --></li>
    <li><!-- item 2 --></li>
  </ul>
  ```

#### Scenario: Horizontal list renders as div with flex
- GIVEN a widget with `displayMode: 'list'`, `listOrientation: 'horizontal'`
- WHEN the widget renders
- THEN the HTML MUST be:
  ```html
  <div role="list" style="display: flex; flex-wrap: wrap; gap: ...">
    <div role="listitem"><!-- item 1 --></div>
    <div role="listitem"><!-- item 2 --></div>
  </div>
  ```

#### Scenario: List item uses button styling
- GIVEN a list item with `backgroundColor: '#0066cc'` and `textColor: '#ffffff'`
- WHEN the item renders
- THEN the CSS MUST apply `background-color: #0066cc` and `color: #ffffff` via inline styles

#### Scenario: Hover effect applies to list items
- GIVEN a list item is hovered
- WHEN the user hovers the pointer over it
- THEN the item MUST translate up by 2 px
- AND a soft drop shadow MUST be applied (matching single-button hover)

### Requirement: REQ-LBLM-009 Backward compatibility migration

Existing widget placements created before list-mode support MUST remain valid and render correctly without data migration. Placements lacking a `displayMode` field MUST be treated as `displayMode = 'button'` implicitly. Placements lacking a `links` field MUST use the legacy single-link fields (`url`, `icon`) for rendering.

No schema migration is required — the system MUST support both the old (single-link fields) and new (links array + displayMode) representations side-by-side. The frontend form MUST detect which representation a placement uses and offer the appropriate edit interface: single-link form for `displayMode = 'button'` with no `links` array, list editor for `displayMode = 'list'`.

#### Scenario: Pre-list-mode placement renders with legacy fields
- GIVEN a placement created before list-mode support, with fields: `{url: 'https://example.com', icon: 'external', label: 'Go'}`
- AND no `displayMode` or `links` fields
- WHEN the widget renders
- THEN the button MUST show using the legacy fields
- AND the widget MUST function identically to before

#### Scenario: Edit form detects legacy format
- GIVEN a placement in legacy format (no `displayMode` or `links`)
- WHEN the edit form opens
- THEN the system MUST present the single-link form
- AND NOT the list editor

#### Scenario: Upgrade from button to list preserves existing link
- GIVEN a placement in legacy format with `url: 'https://example.com'`
- WHEN the user toggles `displayMode` to `'list'` in the edit form
- THEN the system MUST create a `links` array with the first entry populated from the legacy fields
- AND the placement MUST save successfully

#### Scenario: No schema change needed
- GIVEN the app schema at version N (before list-mode support)
- WHEN the app is updated to version N+1 (with list-mode support)
- THEN no Nextcloud migration step MUST run
- AND existing placements MUST continue to work without re-saving
