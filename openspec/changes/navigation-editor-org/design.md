# Design — Organization-wide Navigation Editor

## Context

The `navigation-editor-org` spec introduces a centralized, admin-curated organization-wide navigation tree stored as per-language JSON files within MyDash's `IAppData` folder. The tree supports recursive node nesting (max 3 levels), group-based visibility filtering via Nextcloud's native group membership, and global position configuration (left, right, top, hidden).

The design documents key architectural decisions, including service-layer organization, storage strategy, group filtering mechanics, and the seed-data approach for the admin editor's initial state.

## Goals / Non-Goals

**Goals:**
- Specify the per-language JSON file storage location and structure
- Define the `OrgNavigationService` as the single source of truth for tree CRUD, validation, and filtering
- Nail down the group-visibility filtering semantics (null = all, array = restricted)
- Confirm the position-setting storage and default value
- Document seed-data examples (3-5 realistic org-nav trees per language for admin testing)

**Non-Goals:**
- Designing the full Pinia store (details in specs and tasks)
- Specifying the admin editor UI form layout (deferred to design review)
- Implementing webhook hooks on tree changes (future capability)
- Defining versioning or audit history of tree changes (audit trail is ADR-001 scope)

## Decisions

### D1: Per-language tree storage in `IAppData`

**Decision**: Store org-navigation trees as separate JSON files per language under `IAppData('mydash')/org-navigation/{lang}.json`.

**Rationale**:
- `IAppData` is the standard Nextcloud location for app-specific persistent data.
- Per-language separation allows admins to maintain trees in multiple languages independently without file conflicts.
- JSON is human-readable for debugging and supports arbitrary nesting.
- Wholesale file replacement (vs per-node CRUD endpoints) simplifies validation and prevents partially-applied updates.

**File paths**:
- Dutch tree: `IAppData('mydash')/org-navigation/nl.json`
- English tree: `IAppData('mydash')/org-navigation/en.json`
- Max file size: 5 MB (enforced on read and write to prevent resource exhaustion)

**Example (nl.json)**:
```json
[
  {
    "id": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
    "label": "Bedrijfsresources",
    "icon": null,
    "url": null,
    "openInNewTab": false,
    "groupVisibility": null,
    "children": [
      {
        "id": "yyyyyyyy-yyyy-yyyy-yyyy-yyyyyyyyyyyy",
        "label": "Medewerkerhandboek",
        "icon": "file-document",
        "url": "https://intranet.example.org/handbook",
        "openInNewTab": true,
        "groupVisibility": null,
        "children": []
      }
    ]
  }
]
```

### D2: Node ID generation and validation

**Decision**: Each node MUST have a valid UUID (v1..v5) as its `id` field. IDs are generated client-side (via `crypto.randomUUID()` or similar) when creating nodes and validated server-side on save.

**Rationale**:
- UUIDs are collision-free across distributed editors.
- No server-side ID allocation logic needed; simpler API contract.
- Enables future audit trail tracking by node ID.

**Validation rule**:
- `OrgNavigationService::validateTree()` rejects any node with an invalid or missing `id`.
- Detects duplicate IDs at any depth and returns HTTP 400 with error message `'Duplicate node id'`.

### D3: Group-visibility filtering — explicit cascade

**Decision**: A node is visible to a user if and only if:
1. `groupVisibility` is `null` (visible to all), OR
2. The user belongs to at least one group listed in `groupVisibility`

If a parent node is hidden, all its children are also hidden (cascading hide).

**Rationale**:
- Simple, deterministic rule: no group precedence or "any group wins" logic.
- Cascading hide prevents orphaned child nodes in the rendered panel.
- Null-means-public convention is familiar from unix file permissions.

**Implementation**:
- `OrgNavigationService::filterTreeByUserGroups($tree, $userId)` walks the tree recursively.
- For each node, checks `$groupManager->getUserGroupIds($userId)` and evaluates visibility.
- Rebuilds tree with only visible nodes; removes nodes with no visible children.

### D4: Position setting storage and defaults

**Decision**: Store the position setting (`'left'`, `'right'`, `'top'`, `'hidden'`) as a scalar string in the `mydash_admin_settings` key-value table with key `'org_navigation_position'`. Default value: `'hidden'` (the org nav rail is opt-in).

**Rationale**:
- `mydash_admin_settings` is the standard Nextcloud `IAppConfig` location for admin-only scalars.
- Position is a global setting, not per-user, so not stored in the tree JSON.
- Default `'hidden'` prevents visual clutter on first install; admins explicitly enable the feature.
- The four values map directly to CSS layout properties: `left` (sidebar left), `right` (sidebar right), `top` (top bar), `hidden` (no render).

**Endpoints**:
- `GET /api/admin/org-navigation/position` returns `{ "position": "hidden" }` (or the current value)
- `PUT /api/admin/org-navigation/position` accepts `{ "position": "left"|"right"|"top"|"hidden" }` and stores it

### D5: Service-account context for group-folder reads (optional)

**Decision**: This capability does not create file-access endpoints; org-nav URLs are external links or internal MyDash URLs. No service-account read path is required for this spec. (See sibling spec `groupfolder-storage-backend` for details on service-account reads when dashboard content lives in GroupFolders.)

**Rationale**:
- Org-nav nodes are metadata (links and labels), not files.
- Any file-serving capability (e.g., dashboard images) is orthogonal.

## Seed Data

The following seed objects represent realistic org-navigation trees in Dutch and English for admin testing and feature demo purposes.

### Dutch (nl.json) — Example 1: Verkooporganisatie

```json
[
  {
    "id": "a1b2c3d4-e5f6-4a5b-8c7d-e8f9a0b1c2d3",
    "label": "Verkoopsources",
    "icon": "briefcase",
    "url": null,
    "openInNewTab": false,
    "groupVisibility": ["sales", "admin"],
    "children": [
      {
        "id": "b2c3d4e5-f6a7-4b6c-8d8e-f9a0b1c2d3e4",
        "label": "CRM Dashboard",
        "icon": "chart-line",
        "url": "/apps/mydash/dashboards/crm-sales",
        "openInNewTab": false,
        "groupVisibility": null,
        "children": []
      },
      {
        "id": "c3d4e5f6-a7b8-4c7d-8e9f-a0b1c2d3e4f5",
        "label": "Quota Tracker",
        "icon": "target",
        "url": "https://sales-tracker.example.nl/quotas",
        "openInNewTab": true,
        "groupVisibility": null,
        "children": []
      }
    ]
  },
  {
    "id": "d4e5f6a7-b8c9-4d8e-8f0a-b1c2d3e4f5a6",
    "label": "Bedrijfsbeleid",
    "icon": "file-document",
    "url": null,
    "openInNewTab": false,
    "groupVisibility": null,
    "children": [
      {
        "id": "e5f6a7b8-c9da-4e9f-8012-c2d3e4f5a6b7",
        "label": "Medewerkerhandboek",
        "icon": "file-pdf",
        "url": "/apps/mydash/documents/handbook-nl.pdf",
        "openInNewTab": true,
        "groupVisibility": null,
        "children": []
      },
      {
        "id": "f6a7b8c9-daeb-4fa0-8213-d3e4f5a6b7c8",
        "label": "Gedragscode",
        "icon": "shield",
        "url": "/apps/mydash/documents/coc-nl.pdf",
        "openInNewTab": true,
        "groupVisibility": null,
        "children": []
      }
    ]
  }
]
```

### English (en.json) — Example 1: Sales Organization

```json
[
  {
    "id": "a1b2c3d4-e5f6-4a5b-8c7d-e8f9a0b1c2d3",
    "label": "Sales Resources",
    "icon": "briefcase",
    "url": null,
    "openInNewTab": false,
    "groupVisibility": ["sales", "admin"],
    "children": [
      {
        "id": "b2c3d4e5-f6a7-4b6c-8d8e-f9a0b1c2d3e4",
        "label": "CRM Dashboard",
        "icon": "chart-line",
        "url": "/apps/mydash/dashboards/crm-sales",
        "openInNewTab": false,
        "groupVisibility": null,
        "children": []
      },
      {
        "id": "c3d4e5f6-a7b8-4c7d-8e9f-a0b1c2d3e4f5",
        "label": "Quota Tracker",
        "icon": "target",
        "url": "https://sales-tracker.example.com/quotas",
        "openInNewTab": true,
        "groupVisibility": null,
        "children": []
      }
    ]
  },
  {
    "id": "d4e5f6a7-b8c9-4d8e-8f0a-b1c2d3e4f5a6",
    "label": "Company Policy",
    "icon": "file-document",
    "url": null,
    "openInNewTab": false,
    "groupVisibility": null,
    "children": [
      {
        "id": "e5f6a7b8-c9da-4e9f-8012-c2d3e4f5a6b7",
        "label": "Employee Handbook",
        "icon": "file-pdf",
        "url": "/apps/mydash/documents/handbook-en.pdf",
        "openInNewTab": true,
        "groupVisibility": null,
        "children": []
      },
      {
        "id": "f6a7b8c9-daeb-4fa0-8213-d3e4f5a6b7c8",
        "label": "Code of Conduct",
        "icon": "shield",
        "url": "/apps/mydash/documents/coc-en.pdf",
        "openInNewTab": true,
        "groupVisibility": null,
        "children": []
      }
    ]
  }
]
```

## Reuse Analysis

This capability leverages the following existing services and patterns:

- **`IAppData` (Nextcloud API)** — file storage
- **`IGroupManager` (Nextcloud API)** — group membership resolution
- **Pinia store pattern** — frontend state management (already used in `dashboard-switcher`, `admin-templates`)
- **Vue 2.7 SFC** — component architecture (consistent with existing widgets)
- **Nextcloud i18n** — translation loading and runtime `t()` function

No overlap with `dashboard-switcher-sidebar` (personal dashboards) or `admin-templates` (full dashboard seeding). The org-nav is a separate metadata surface.

## Notes

- The position setting is global; per-user position preferences are out of scope.
- Tree validation is centralized in `OrgNavigationService::validateTree()` to ensure consistency between admin editor and API layer.
- Empty trees render nothing, preventing visual clutter.
- Mobile responsiveness uses CSS `@media (max-width: 799px)` only; no JS resize listeners required.
- Icon resolution follows the `link-button-widget` pattern: URL → `<img>`, bare name → inline label, null → no icon.
