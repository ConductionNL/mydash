# Gallery and categorization for template discovery

## Why

Today, MyDash admins can create templates and distribute them to groups, but users have no way to **discover and instantiate** templates themselves. Templates exist as administrative snapshots visible only via the first-access distribution chain. Organizations need a self-service gallery where any dashboard owner can browse available templates, understand their purpose (with preview images and descriptions), filter by category, and create their own copy via "save as template" — converting a custom dashboard back into a reusable blueprint. This unblocks workflow-led adoption where teams build their own gold-standard layouts and share them within peer groups.

## What Changes

- Extend `oc_mydash_dashboards` rows of type `admin_template` with three new columns: `templateCategory` (VARCHAR 64, nullable), `templatePreviewImage` (TEXT, nullable), and `templateDescription` (TEXT, nullable, longer than the existing `description` field).
- Expose `GET /api/templates/gallery` returning all `admin_template` dashboards with metadata (`uuid`, `name`, `description`, `category`, `previewImage`, `gridColumns`, `widgetCount`, `lastUpdatedAt`) — no widget tree content (list view only). Supports `?category=<cat>` filtering and `?sort=updatedAt` for recency.
- Expose `POST /api/dashboards/{uuid}/save-as-template` — owner-only endpoint creating a new `admin_template` with a fresh UUID, deep-copied widget tree, and user-supplied `{name, description, category, previewImage}` metadata.
- Expose `POST /api/admin/templates/{uuid}/preview-image` (multipart) for admins to upload preview images, persisted via the existing dashboard-icons custom-icon-upload pattern (read prior art in `openspec/changes/custom-icon-upload-pattern/`).
- Existing template-from-template instantiation flow (REQ-TMPL-005) remains unchanged — this adds gallery + categorization UI on top.

## Capabilities

### Modified Capabilities

- `admin-templates`: adds REQ-TMPL-012 (gallery endpoint), REQ-TMPL-013 (save-as-template), REQ-TMPL-014 (template metadata), REQ-TMPL-015 (preview-image upload). Existing REQ-TMPL-001..011 untouched.

## Impact

**Affected code:**

- `lib/Db/Dashboard.php` — add three new nullable fields with getters/setters
- `lib/Db/DashboardMapper.php` — add `findAllTemplatesForGallery()` with optional category filter, sorting by category/name or updatedAt
- `lib/Service/AdminTemplateService.php` — add gallery listing + filtering logic
- `lib/Controller/TemplateController.php` (new) — expose `gallery()`, `saveAsTemplate()` endpoints
- `lib/Controller/AdminController.php` — add `uploadPreviewImage()` endpoint
- `appinfo/routes.php` — register three new routes
- `lib/Migration/VersionXXXXDate2026...php` — schema migration adding three nullable columns
- `src/stores/templates.js` — track gallery state + preview images
- `src/views/TemplateGallery.vue` (new) — gallery UI with category filter, cards with preview images

**Affected APIs:**

- 3 new routes (no existing routes changed, gallery is read-only)
- All endpoints support existing template distribution — no breaking changes

**Dependencies:**

- Custom-icon-upload pattern from `openspec/changes/custom-icon-upload-pattern/` (reused for preview-image storage)
- No new composer or npm dependencies

**Migration:**

- Zero-impact: three new nullable columns. Existing rows get `NULL` defaults. Existing REQ-TMPL-001..011 endpoints continue unchanged.
- No data backfill required.
