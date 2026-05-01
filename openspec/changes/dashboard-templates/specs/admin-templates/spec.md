---
capability: admin-templates
delta: true
status: draft
---

# Admin Templates — Delta from change `dashboard-templates`

> NOTE (D1 — Storage divergence): MyDash stores templates as `type='admin_template'` rows in
> `oc_mydash_dashboards`. This is a **deliberate and permanent divergence** from the reference
> implementation's `/{lang}/_templates/` filesystem-folder convention. Reasons: (1) the existing
> REQ-TMPL-001..011 capability is already shipped — switching storage models is a breaking change;
> (2) `WHERE type='admin_template'` is a single indexed query; the filesystem approach requires a
> full page-tree walk with path-segment string-matching; (3) DB enum cleanly separates kind from
> location; (4) MyDash supports DB-backed dashboards that have no GroupFolder and therefore no
> `_templates/` folder — a cross-backend representation requires the DB type column; (5) ACL
> equivalence is already provided by the `dashboard-sharing` capability. Do not attempt to converge
> on the filesystem-folder approach.

## ADDED Requirements

### Requirement: REQ-TMPL-012 Template Gallery Endpoint

The system MUST expose a read-only gallery endpoint that lists all `admin_template` dashboards with metadata suitable for discovery and instantiation.

> NOTE (D2 — Index): The `WHERE type='admin_template' AND templateCategory=?` filter path MUST be
> backed by a composite index on `(type, templateCategory)`. The migration adding the three new
> metadata columns MUST also add this composite index to keep the optional category-filter query
> indexed at scale. The base `WHERE type='admin_template'` path already benefits from the existing
> index on `type`.

#### Scenario: List all templates in gallery

- GIVEN 3 admin templates exist with `templateCategory: 'marketing'`, `'engineering'`, and `null`
- WHEN a logged-in user sends `GET /api/templates/gallery`
- THEN the system MUST return HTTP 200 with an array of 3 template objects
- AND each object MUST include: `uuid`, `name`, `description`, `category` (nullable string), `previewImage` (nullable URL), `gridColumns`, `widgetCount` (count of widget placements), `lastUpdatedAt`
- AND the response MUST NOT include the widget tree or `isCompulsory` flag details (gallery is a list view, not a render)

#### Scenario: Filter gallery by category

- GIVEN 5 templates exist: 2 with `templateCategory: 'marketing'`, 2 with `templateCategory: 'engineering'`, 1 with `templateCategory: null`
- WHEN a logged-in user sends `GET /api/templates/gallery?category=marketing`
- THEN the system MUST return HTTP 200 with an array of 2 templates
- AND the response MUST contain only templates where `templateCategory = 'marketing'`

#### Scenario: Default sort order

- GIVEN multiple templates exist with various categories and names
- WHEN a user sends `GET /api/templates/gallery` (no sort parameter)
- THEN results MUST be sorted first by `templateCategory` (null last), then by `name` alphabetically
- AND this enables consistent ordering for pagination

#### Scenario: Sort by recency

- GIVEN 3 templates with `lastUpdatedAt` values: "2026-05-01 10:00:00", "2026-04-30 14:30:00", "2026-05-01 09:15:00"
- WHEN a user sends `GET /api/templates/gallery?sort=updatedAt`
- THEN the system MUST return results sorted by `lastUpdatedAt` descending (most recent first)
- AND HTTP 200 MUST be returned

#### Scenario: Gallery includes category null templates

- GIVEN a template has `templateCategory: null`
- WHEN a user calls `GET /api/templates/gallery` without category filter
- THEN the template MUST be included
- AND when calling `GET /api/templates/gallery?category=marketing`, the template with `null` category MUST NOT be included

### Requirement: REQ-TMPL-013 Save-as-template Action

Any dashboard owner MUST be able to convert their current dashboard into a reusable admin template, creating a snapshot with a fresh UUID and a deep-copied widget tree.

> NOTE (D3 — Deep-copy semantics): `save-as-template` creates a **deep copy**, not a link or
> reference. All widget placements are duplicated into a new row. The source dashboard is not
> modified. The new template row MUST have `basedOnTemplate = null` — templates do not chain and
> do not inherit lineage from the source dashboard. Edits to the source after save-as-template MUST
> NOT propagate to the template; this is consistent with the independence guarantee already provided
> by REQ-TMPL-006 for user copies.

#### Scenario: Save a personal dashboard as a template

- GIVEN user "alice" owns a personal dashboard with 4 widget placements
- WHEN she sends `POST /api/dashboards/{uuid}/save-as-template` with body:
  ```json
  {
    "name": "Product Roadmap Template",
    "description": "Standard layout for product planning dashboards",
    "category": "product",
    "previewImage": "https://example.com/roadmap-preview.png"
  }
  ```
- THEN the system MUST create a new dashboard with `type: 'admin_template'` and a fresh UUID
- AND the new template MUST have all 4 widget placements deep-copied from the source
- AND each copied placement MUST be independent (editing the source dashboard MUST NOT affect the template)
- AND `userId` on the template MUST be null (templates are admin-collective, not user-owned)
- AND the response MUST return HTTP 201 with the newly created template object

#### Scenario: Save-as-template resets isActive flag

- GIVEN a personal dashboard with `isActive: 1` (currently selected by the user)
- WHEN the dashboard is saved as a template
- THEN the resulting template MUST have `isActive: 0` (templates are not user dashboards; no dashboard is active for them)

#### Scenario: Non-owner cannot save another's dashboard as template

- GIVEN user "alice" owns dashboard "Work"
- WHEN user "bob" sends `POST /api/dashboards/{uuid}/save-as-template` (alice's dashboard UUID)
- THEN the system MUST return HTTP 403
- AND the template MUST NOT be created

#### Scenario: Save-as-template with admin_template source

- GIVEN a user "alice" has a personal copy of an admin template (with `basedOnTemplate: 3`)
- WHEN she sends `POST /api/dashboards/{uuid}/save-as-template` with her copy's UUID
- THEN the system MUST create a new admin template
- AND the new template's `basedOnTemplate` MUST be null (templates do not chain; they are independent)
- AND the copy's source lineage is NOT preserved

#### Scenario: Save-as-template with missing optional fields

- GIVEN a user sends `POST /api/dashboards/{uuid}/save-as-template` with body `{"name": "My Template"}` (omitting description, category, previewImage)
- THEN the system MUST create the template with:
  - `templateDescription: null`
  - `templateCategory: null`
  - `templatePreviewImage: null`
- AND HTTP 201 MUST be returned with the new template

### Requirement: REQ-TMPL-014 Template Metadata Fields

Admin templates MUST support three new metadata fields for categorization and discovery.

#### Scenario: Template metadata in gallery response

- GIVEN a template with `templateCategory: 'marketing'`, `templateDescription: 'Use for campaign planning'`, `templatePreviewImage: 'https://example.com/img.png'`
- WHEN a user retrieves the template via `GET /api/templates/gallery`
- THEN the response MUST include all three metadata fields exactly as stored

#### Scenario: Metadata persists across updates

- GIVEN a template with `templateCategory: 'engineering'`
- WHEN an admin sends `PUT /api/admin/templates/{id}` to update the template name (via existing REQ-TMPL-003 endpoint)
- THEN `templateCategory` MUST remain `'engineering'` (unchanged)

#### Scenario: Update template metadata via save-as-template

- GIVEN user "alice" saves her dashboard as a template with `category: 'product'`
- THEN the new template's `templateCategory` MUST be set to `'product'`

#### Scenario: Template description field length

- GIVEN a user provides a `description` string of 500 characters when calling save-as-template
- THEN the system MUST accept and store the full 500 characters (unlike the regular `description` field, which may be shorter)
- NOTE: The `templateDescription` column stores longer text; validation MUST NOT truncate

#### Scenario: Metadata fields are nullable

- GIVEN a template with all metadata fields set to null
- WHEN the template is returned via any API endpoint
- THEN the response MUST include the fields with null values
- AND no error MUST be thrown

### Requirement: REQ-TMPL-015 Preview Image Upload Endpoint

Administrators MUST be able to upload a preview image for a template via multipart form, persisted using the existing dashboard-icons upload pattern.

> NOTE (D4 — custom-icon-upload-pattern reuse): The `POST /api/admin/templates/{uuid}/preview-image`
> endpoint MUST follow the **exact same endpoint shape** as defined in
> `openspec/changes/custom-icon-upload-pattern/` — same multipart field name (`file`), same
> file-type validation (PNG, JPG, GIF, WebP, SVG), same Nextcloud Files storage path convention,
> and same public-share URL return contract (`{"previewImage": "<url>"}`). Do not introduce a
> parallel image-persistence mechanism; the custom-icon-upload pattern already covers file-type
> validation, Nextcloud Files storage, and public-share URL generation.

#### Scenario: Upload preview image for a template

- GIVEN an admin user and an admin template with UUID "abc123"
- WHEN the admin sends `POST /api/admin/templates/abc123/preview-image` with a multipart body containing `file=<binary PNG>` and optional `filename='roadmap-preview.png'`
- THEN the system MUST save the image via the custom-icon-upload pattern (reusing the mechanism from `openspec/changes/custom-icon-upload-pattern/`)
- AND the image MUST be persisted as a publicly accessible URL or a Nextcloud share
- AND the template's `templatePreviewImage` field MUST be updated with the URL
- AND the response MUST return HTTP 200 with `{"previewImage": "https://example.com/...png"}`

#### Scenario: Non-admin cannot upload preview image

- GIVEN a regular user "alice"
- WHEN she sends `POST /api/admin/templates/abc123/preview-image` with a file
- THEN the system MUST return HTTP 403
- AND the template's `templatePreviewImage` MUST NOT be modified

#### Scenario: Upload replaces previous preview image

- GIVEN a template with `templatePreviewImage: 'https://example.com/old.png'`
- WHEN an admin uploads a new image via `POST /api/admin/templates/{uuid}/preview-image`
- THEN the system MUST overwrite the old URL
- AND `templatePreviewImage` MUST point to the new image
- AND the old image file MAY be cleaned up (implementation-dependent)

#### Scenario: Invalid file format is rejected

- GIVEN an admin sends `POST /api/admin/templates/abc123/preview-image` with a `.txt` file
- THEN the system MUST return HTTP 400 with an error message
- AND only image formats (PNG, JPG, GIF, WebP, SVG) MUST be accepted

#### Scenario: Preview image URL in gallery response

- GIVEN a template with a recently uploaded preview image
- WHEN a user calls `GET /api/templates/gallery`
- THEN the template object MUST include the `previewImage` URL
- AND the image MUST be immediately accessible (no delay)

## Non-Functional Requirements

- **Performance**: `GET /api/templates/gallery` MUST return within 500ms even with 100+ templates. Gallery list SHOULD NOT fetch widget placements (no `findPlacements()` call per template).
- **Data integrity**: Save-as-template deep-copy MUST be atomic — if placement copy fails, the entire operation MUST be rolled back.
- **Security**: Only dashboard owners can call `POST /api/dashboards/{uuid}/save-as-template`. Only admins can call `POST /api/admin/templates/{uuid}/preview-image`.
- **Storage**: Preview images MUST be stored using the existing custom-icon-upload pattern (read `openspec/changes/custom-icon-upload-pattern/spec.md` for specifics).
- **Localization**: Error messages MUST support English and Dutch.

### Current Implementation Status

**Not yet implemented:**

- REQ-TMPL-012 (Gallery endpoint): No `/api/templates/gallery` endpoint exists.
- REQ-TMPL-013 (Save-as-template): No `/api/dashboards/{uuid}/save-as-template` endpoint exists.
- REQ-TMPL-014 (Metadata fields): `templateCategory`, `templateDescription`, `templatePreviewImage` columns do not exist on `oc_mydash_dashboards`.
- REQ-TMPL-015 (Preview image upload): No `/api/admin/templates/{uuid}/preview-image` endpoint exists.

### Standards & References

- Custom-icon-upload pattern: `openspec/changes/custom-icon-upload-pattern/` — reuse for image persistence
- Nextcloud file upload: `OCP\Files\IRootFolder`, public-share URL generation
- WCAG 2.1 AA: Gallery UI images should have alt text, category filters should be keyboard-operable
