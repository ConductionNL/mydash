---
status: implemented
---

# Admin Templates Specification

## Purpose

Admin templates allow Nextcloud administrators to create pre-configured dashboards that are automatically distributed to users based on group membership. When a user opens MyDash for the first time (or when a new template targets their group), the system creates a personal copy of the matching template. This copy is an independent dashboard that the user can modify within the limits of the inherited permission level. Templates enable organizations to provide standardized dashboard layouts with compulsory widgets while still allowing user customization where appropriate.

## Data Model

Admin templates are stored as dashboards in `oc_mydash_dashboards` with `type: "admin_template"`. Additional template-specific fields:
- **targetGroups**: JSON string of Nextcloud group IDs (e.g., `["marketing", "all-staff"]`), accessed via `getTargetGroupsArray()`/`setTargetGroupsArray()`
- **isDefault**: SMALLINT (0/1) flag -- if 1 (true), this template is distributed to all users regardless of group membership
- **permissionLevel**: One of `view_only`, `add_only`, `full` -- inherited by user copies
- **userId**: Set to null for admin templates (they are not owned by a specific user)
- **basedOnTemplate**: Not used for templates themselves; used on user copies to reference the template ID

Templates own their widget placements (in `oc_mydash_widget_placements`) which serve as the blueprint for user copies. The template's placements include `isCompulsory` flags that are copied to user dashboards. When a user copy is created, `TemplateService::createDashboardFromTemplate()` clones all placements from the template to the new user dashboard.

## Requirements


@e2e exclude all scenarios test REST CRUD for admin template dashboards — template distribution and UI admin forms are not yet implemented in this version

### Requirement: Create Admin Template (REQ-TMPL-001)

Nextcloud administrators MUST be able to create dashboard templates for distribution to users.

#### Scenario: Create a template targeting specific groups
- GIVEN a Nextcloud admin user
- WHEN they send POST /api/admin/templates with body:
  ```json
  {
    "name": "Marketing Dashboard",
    "description": "Standard dashboard for the marketing team",
    "targetGroups": ["marketing", "communications"],
    "isDefault": false,
    "permissionLevel": "add_only"
  }
  ```
- THEN the system MUST create a dashboard with `type: "admin_template"`
- AND `userId` MUST be set to null (admin templates are not owned by a specific user)
- AND `gridColumns` MUST default to 12
- AND the response MUST return HTTP 201 with the full template object

#### Scenario: Create a default template for all users
- GIVEN a Nextcloud admin user
- WHEN they send POST /api/admin/templates with body:
  ```json
  {
    "name": "Company Dashboard",
    "isDefault": true,
    "permissionLevel": "view_only",
    "targetGroups": []
  }
  ```
- THEN the system MUST create a template with `isDefault: 1`
- AND any previously default template MUST have its `isDefault` set to 0 via `clearDefaultTemplates()`
- AND this template MUST be distributed to all users regardless of group membership

#### Scenario: Non-admin user cannot create templates
- GIVEN a regular (non-admin) Nextcloud user "alice"
- WHEN she sends POST /api/admin/templates
- THEN the system MUST return HTTP 403
- AND the template MUST NOT be created

#### Scenario: Create template with invalid permission level
- GIVEN a Nextcloud admin user
- WHEN they send POST /api/admin/templates with `permissionLevel: "super_admin"`
- THEN the system MUST return HTTP 400 with a validation error
- AND only `view_only`, `add_only`, and `full` MUST be accepted
- NOTE: Permission level validation is NOT currently implemented -- any string is accepted

#### Scenario: Create template with UUID generation
- GIVEN a Nextcloud admin user
- WHEN they create a new template
- THEN the system MUST assign a UUID v4 via `Ramsey\Uuid\Uuid::uuid4()` (unlike user dashboards which use a custom UUID generator in `DashboardFactory`)
- AND the UUID MUST be unique across all dashboards

### Requirement: List Admin Templates (REQ-TMPL-002)

Administrators MUST be able to view all existing templates with their configuration.

#### Scenario: List all templates
- GIVEN 3 admin templates exist: "Marketing Dashboard", "Company Dashboard" (default), "Engineering Dashboard"
- WHEN the admin sends GET /api/admin/templates
- THEN the system MUST return HTTP 200 with an array of all 3 templates
- AND each template MUST include: id, uuid, name, description, targetGroups, isDefault, permissionLevel, gridColumns, type, basedOnTemplate, isActive, createdAt, updatedAt

#### Scenario: Non-admin cannot list templates
- GIVEN a regular user "alice"
- WHEN she sends GET /api/admin/templates
- THEN the system MUST return HTTP 403

#### Scenario: Template list includes widget placement count
- GIVEN the "Marketing Dashboard" template has 6 widget placements
- WHEN the admin sends GET /api/admin/templates
- THEN the template object SHOULD include a widget_count field showing 6
- AND this helps admins understand the template's complexity at a glance
- NOTE: Widget count is NOT currently included in the list response

#### Scenario: Empty template list
- GIVEN no admin templates have been created
- WHEN the admin sends GET /api/admin/templates
- THEN the system MUST return HTTP 200 with an empty array

#### Scenario: Templates filtered from user dashboard list
- GIVEN 3 admin templates and 2 user dashboards exist
- WHEN user "alice" sends GET /api/dashboards
- THEN the response MUST contain only her user dashboards
- AND admin templates MUST NOT appear in the user's dashboard list

### Requirement: Update Admin Template (REQ-TMPL-003)

Administrators MUST be able to modify template configuration including name, description, target groups, permission level, and grid columns.

#### Scenario: Update template target groups
- GIVEN template id 1 targets groups ["marketing"]
- WHEN the admin sends PUT /api/admin/templates/1 with body `{"targetGroups": ["marketing", "sales"]}`
- THEN the system MUST update the targetGroups
- AND newly targeted users (in "sales") MUST receive the template on their next dashboard load
- AND existing user copies for "marketing" users MUST NOT be affected

#### Scenario: Update template permission level
- GIVEN template id 1 has `permissionLevel: "add_only"`
- WHEN the admin sends PUT /api/admin/templates/1 with body `{"permissionLevel": "full"}`
- THEN the template's permissionLevel MUST be updated to "full"
- AND existing user copies MUST inherit the new permission level at runtime because `PermissionService::getEffectivePermissionLevel()` dynamically resolves from the source template via `basedOnTemplate`
- NOTE: This means permission level changes DO propagate to existing copies. The resolution chain is: template's level -> dashboard's own level -> admin default.

#### Scenario: Update template widget layout
- GIVEN template id 1 has 4 widget placements
- WHEN the admin adds a new widget to the template and repositions existing ones
- THEN the template's placements MUST be updated
- AND existing user copies MUST NOT be affected (placement copies are independent after creation)

#### Scenario: Mark template as default
- GIVEN template id 1 is not the default and template id 2 is the default
- WHEN the admin sends PUT /api/admin/templates/1 with body `{"isDefault": true}`
- THEN template 1 MUST become the default
- AND template 2 MUST have `isDefault` set to 0 (false) -- enforced by `clearDefaultTemplates()` called before setting the new default

#### Scenario: Non-admin cannot update templates
- GIVEN template id 1 exists
- WHEN regular user "alice" sends PUT /api/admin/templates/1
- THEN the system MUST return HTTP 403

### Requirement: Delete Admin Template (REQ-TMPL-004)

Administrators MUST be able to delete templates, with proper cleanup of associated widget placements.

#### Scenario: Delete a template with no user copies
- GIVEN template id 1 has no user copies
- WHEN the admin sends DELETE /api/admin/templates/1
- THEN the system MUST delete the template
- AND all template widget placements MUST be cascade-deleted via `placementMapper->deleteByDashboardId()`
- AND the response MUST return HTTP 200

#### Scenario: Delete a template with existing user copies
- GIVEN template id 1 has been copied to 15 users
- WHEN the admin sends DELETE /api/admin/templates/1
- THEN the system MUST delete the template
- AND existing user copies MUST NOT be affected (they are independent dashboards)
- AND user copies with `basedOnTemplate: 1` will fall back to their own `permissionLevel` or admin default since the template no longer exists (caught by `DoesNotExistException` in `getEffectivePermissionLevel()`)

#### Scenario: Non-admin cannot delete templates
- GIVEN template id 1 exists
- WHEN regular user "alice" sends DELETE /api/admin/templates/1
- THEN the system MUST return HTTP 403

#### Scenario: Delete non-template dashboard via template endpoint
- GIVEN dashboard id 5 is a user dashboard (type: "user"), not an admin template
- WHEN the admin sends DELETE /api/admin/templates/5
- THEN the system MUST throw an exception indicating "Not an admin template"
- AND the dashboard MUST NOT be deleted

#### Scenario: Delete the default template
- GIVEN template id 1 is the default template (`isDefault: true`)
- WHEN the admin deletes template id 1
- THEN the system MUST delete the template
- AND no template MUST be the default afterward (this is allowed)
- AND new users without a group-targeted template will get no template on first access

### Requirement: Template Distribution on First Access (REQ-TMPL-005)

When a user accesses MyDash for the first time, the system MUST create personal copies of matching templates via the `DashboardResolver` chain.

#### Scenario: First-time user receives default template
- GIVEN a default template "Company Dashboard" exists with `isDefault: true` and 5 widget placements (3 compulsory)
- AND user "alice" has never opened MyDash
- WHEN alice navigates to MyDash (triggers GET /api/dashboard)
- THEN the system MUST create a personal dashboard for alice as a copy of the template
- AND the copy MUST have `type: "user"` and `userId: "alice"`
- AND the copy MUST inherit the template's permissionLevel
- AND the copy MUST include all 5 widget placements with their positions, sizes, and isCompulsory flags
- AND `basedOnTemplate` on the copy MUST reference the template's ID
- AND the copy MUST be set as alice's active dashboard

#### Scenario: First-time user receives group-targeted template
- GIVEN template "Marketing Dashboard" targets groups ["marketing"]
- AND user "bob" is a member of the "marketing" group
- AND bob has never opened MyDash
- WHEN bob navigates to MyDash
- THEN the system MUST create a personal copy of "Marketing Dashboard" for bob
- NOTE: `TemplateService::getApplicableTemplate()` returns only ONE template (the first matching group-targeted template takes priority over the default). Multiple template distribution is NOT implemented.

#### Scenario: First-time user not in any target group
- GIVEN template "Marketing Dashboard" targets groups ["marketing"]
- AND no default template exists
- AND user "carol" is only in the "engineering" group
- WHEN carol navigates to MyDash
- THEN the system MUST NOT create any dashboard for carol from the marketing template
- AND if `allowUserDashboards` is true, the system MUST create a default "My Dashboard" with recommendations and activity widgets

#### Scenario: Template already distributed to user
- GIVEN user "alice" already has a personal copy of template "Company Dashboard"
- WHEN alice navigates to MyDash again
- THEN the system MUST NOT create a duplicate copy
- AND `DashboardResolver::tryGetActiveDashboard()` MUST find her existing dashboard first

#### Scenario: Multiple templates match the user
- GIVEN templates "Company Dashboard" (default) and "Marketing Dashboard" (targets marketing group)
- AND user "alice" is in the "marketing" group
- WHEN alice navigates to MyDash for the first time
- THEN alice MUST receive a copy of "Marketing Dashboard" (group-targeted template takes priority over default)
- NOTE: Only ONE template per first-access. Group-targeted templates are evaluated first; the default template is the fallback.

### Requirement: Template Copy Independence (REQ-TMPL-006)

User copies of templates MUST be fully independent from the source template after creation, with the exception of permission level resolution.

#### Scenario: User modifies their template copy
- GIVEN user "alice" has a copy of "Marketing Dashboard" with `permissionLevel: "add_only"`
- WHEN she adds a new widget to her copy
- THEN the template MUST NOT be modified
- AND other users' copies MUST NOT be affected

#### Scenario: Admin updates template after distribution
- GIVEN template "Marketing Dashboard" has been copied to 10 users
- WHEN the admin adds a new widget to the template
- THEN existing user copies MUST NOT receive the new widget
- AND only new copies created after the change MUST include the new widget

#### Scenario: Admin deletes template after distribution
- GIVEN template "Marketing Dashboard" has been copied to user "alice"
- WHEN the admin deletes the template
- THEN alice's copy MUST continue to function normally
- AND alice's dashboard MUST retain all placements
- AND permission resolution MUST fall back to the dashboard's own `permissionLevel` (template lookup caught by `DoesNotExistException`)

### Requirement: Template Widget Management (REQ-TMPL-007)

Administrators MUST be able to manage widget placements on templates using the same API as regular dashboards.

#### Scenario: Add widget to template
- GIVEN template id 1 exists
- WHEN the admin sends POST /api/dashboard/1/widgets with widget data including `isCompulsory: 1`
- THEN the widget placement MUST be created on the template
- AND `isCompulsory` MUST be set to 1

#### Scenario: Remove widget from template
- GIVEN template id 1 has widget placement id 20
- WHEN the admin sends DELETE /api/widgets/20
- THEN the placement MUST be removed from the template
- AND existing user copies MUST NOT be affected

#### Scenario: Configure template grid layout
- GIVEN template id 1 exists
- WHEN the admin arranges widgets on the template via the grid
- THEN the positions MUST be saved as the template's widget placements
- AND new user copies MUST receive these exact positions

#### Scenario: Template placements include tile data
- GIVEN the admin adds a tile placement to template id 1 with inline tile data (tileTitle, tileIcon, etc.)
- WHEN the template is distributed to users
- THEN the tile placement MUST be cloned with all inline tile data via `clonePlacement()`
- AND the user copy MUST render the tile identically to the template

### Requirement: Only One Default Template (REQ-TMPL-008)

The system MUST enforce that at most one template is marked as the default at any time.

#### Scenario: Set a template as default when no default exists
- GIVEN no template has `isDefault: true`
- WHEN the admin creates or updates a template with `isDefault: true`
- THEN that template MUST become the default
- AND no other templates MUST be affected

#### Scenario: Set a template as default when another is already default
- GIVEN template "Company Dashboard" has `isDefault: true`
- WHEN the admin sets template "New Dashboard" as the default
- THEN "New Dashboard" MUST become the default
- AND "Company Dashboard" MUST have `isDefault` set to 0 (false)
- AND `clearDefaultTemplates()` MUST be called before setting the new default

#### Scenario: Remove default status from the only default template
- GIVEN template "Company Dashboard" has `isDefault: true`
- WHEN the admin sends PUT /api/admin/templates/1 with body `{"isDefault": false}`
- THEN the template MUST have `isDefault` set to 0 (false)
- AND no template MUST be the default (this is allowed)

### Requirement: Get Template with Placements (REQ-TMPL-009)

Administrators MUST be able to retrieve a specific template along with all its widget placements for editing.

#### Scenario: Get template with its placements
- GIVEN template id 1 has 6 widget placements
- WHEN the admin sends GET /api/admin/templates/1
- THEN the system MUST return the template object and an array of its 6 placements
- AND the response MUST include both the template entity and its placements as separate keys

#### Scenario: Get non-template dashboard via template endpoint
- GIVEN dashboard id 5 is a user dashboard (type: "user")
- WHEN the admin sends GET /api/admin/templates/5
- THEN the system MUST throw an exception indicating "Not an admin template"

#### Scenario: Get template with no placements
- GIVEN template id 2 exists but has no widget placements
- WHEN the admin sends GET /api/admin/templates/2
- THEN the system MUST return the template object with an empty placements array

### Requirement: Template Group Resolution (REQ-TMPL-010)

Template distribution MUST use Nextcloud's `IGroupManager` API to resolve user group memberships accurately.

#### Scenario: User added to a target group after template creation
- GIVEN template "Marketing Dashboard" targets groups ["marketing"]
- AND user "alice" was not in the "marketing" group when the template was created
- AND alice is later added to the "marketing" group
- WHEN alice opens MyDash for the first time
- THEN the system MUST distribute the "Marketing Dashboard" template to alice
- AND group membership MUST be checked at access time, not at template creation time

#### Scenario: User removed from a target group after receiving template
- GIVEN user "alice" received a copy of "Marketing Dashboard" while in the "marketing" group
- AND alice is later removed from the "marketing" group
- WHEN alice continues to use MyDash
- THEN alice's copy MUST continue to function normally
- AND the copy MUST NOT be deleted or revoked

#### Scenario: Template targets non-existent group
- GIVEN template "Test Dashboard" targets groups ["nonexistent-group"]
- WHEN any user opens MyDash
- THEN the template MUST NOT match any user (no user is in a non-existent group)
- AND the system MUST NOT throw errors during group resolution

### Requirement: Template Administration UI (REQ-TMPL-011)

The admin settings page MUST provide a UI for managing templates.

#### Scenario: Template list in admin settings
- GIVEN the admin opens the MyDash admin settings page
- THEN a template management section MUST be displayed
- AND all existing templates MUST be listed with their name, target groups, and default status

#### Scenario: Create template via admin UI
- GIVEN the admin clicks "Create Template" in the admin settings
- THEN a modal dialog MUST appear with fields for name, description, target groups, permission level, and default status
- AND the admin MUST be able to save the new template

#### Scenario: Group selection in template editor
- GIVEN the admin opens the template editor
- THEN a group selector MUST allow selecting from available Nextcloud groups
- NOTE: The current implementation uses `NcSelectTags` but `availableGroups` is hardcoded to an empty array. Groups are NOT fetched from the server.

### Requirement: Primary-group resolution for workspace routing (REQ-TMPL-012)

The system MUST expose a pure function `resolvePrimaryGroup(string $userId): string` that returns the Nextcloud group ID whose `group_shared` dashboards the user should see, OR the literal string `'default'` when no match is found. The algorithm MUST be:

1. Read the admin-configured ordered list of group IDs from `admin_settings.group_order` (JSON `string[]`, default `[]`).
2. Read the user's Nextcloud group memberships via `IGroupManager::getUserGroupIds($userId)`.
3. Walk `group_order` left-to-right and return the first group ID that also appears in the user's memberships.
4. If no match, return the literal string `'default'`.

The function MUST be deterministic and idempotent (no writes).

#### Scenario: First match wins by admin-configured priority

- GIVEN admin has set `group_order = ["engineering", "all-staff"]`
- AND user "alice" belongs to groups: `["all-staff", "engineering", "marketing"]`
- WHEN `resolvePrimaryGroup("alice")` is called
- THEN it MUST return `"engineering"` (because engineering appears first in group_order, even though all-staff is alphabetically earlier in alice's groups)

#### Scenario: User in no active group falls through to default sentinel

- GIVEN admin has set `group_order = ["engineering", "executives"]`
- AND user "carol" belongs only to groups: `["support"]`
- WHEN `resolvePrimaryGroup("carol")` is called
- THEN it MUST return `"default"`

#### Scenario: Empty group_order always returns default

- GIVEN admin has not configured any active groups (`group_order = []`)
- WHEN `resolvePrimaryGroup` is called for any user
- THEN it MUST return `"default"` regardless of the user's actual group memberships

#### Scenario: Configured group that the user is NOT in is skipped

- GIVEN `group_order = ["executives", "engineering"]`
- AND user "bob" belongs to: `["engineering", "support"]`
- WHEN `resolvePrimaryGroup("bob")` is called
- THEN it MUST skip "executives" and return `"engineering"`

#### Scenario: Configured group that no longer exists in Nextcloud is harmless

- GIVEN `group_order = ["deleted-group", "engineering"]`
- AND the Nextcloud group "deleted-group" has been removed
- AND user "alice" belongs to: `["engineering"]`
- WHEN `resolvePrimaryGroup("alice")` is called
- THEN it MUST return `"engineering"`
- AND MUST NOT raise an error
- NOTE: Cleanup of stale group IDs in `group_order` is the admin UI's responsibility; the resolver MUST be tolerant.

### Requirement: Resolver is the single routing authority (REQ-TMPL-013)

All workspace-rendering and dashboard-resolution code paths (REQ-DASH-013, REQ-DASH-018) MUST consult `resolvePrimaryGroup` for the user's primary group. There MUST NOT be parallel implementations of this lookup.

#### Scenario: Single source of truth

- GIVEN any future capability needs the user's primary workspace group
- WHEN it computes a group ID
- THEN it MUST go through `AdminTemplateService::resolvePrimaryGroup` (or its declared service interface)
- AND duplicating the algorithm inline is forbidden by code review

### Requirement: Template Gallery Endpoint (REQ-TMPL-014)

The system MUST expose a read-only gallery endpoint that lists all `admin_template` dashboards with metadata suitable for discovery and instantiation.

> NOTE (D2 — Index): The `WHERE type='admin_template' AND templateCategory=?` filter path MUST be backed by a composite index on `(type, templateCategory)`. The migration adding the three new metadata columns MUST also add this composite index to keep the optional category-filter query indexed at scale. The base `WHERE type='admin_template'` path already benefits from the existing index on `type`.

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

### Requirement: Save-as-template Action (REQ-TMPL-015)

Any dashboard owner MUST be able to convert their current dashboard into a reusable admin template, creating a snapshot with a fresh UUID and a deep-copied widget tree.

> NOTE (D3 — Deep-copy semantics): `save-as-template` creates a **deep copy**, not a link or reference. All widget placements are duplicated into a new row. The source dashboard is not modified. The new template row MUST have `basedOnTemplate = null` — templates do not chain and do not inherit lineage from the source dashboard. Edits to the source after save-as-template MUST NOT propagate to the template; this is consistent with the independence guarantee already provided by REQ-TMPL-006 for user copies.

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

### Requirement: Template Metadata Fields (REQ-TMPL-016)

Admin templates MUST support three new metadata fields for categorization and discovery: `templateCategory` (VARCHAR 64, nullable), `templateDescription` (TEXT, nullable), and `templatePreviewImage` (TEXT, nullable). The fields are stored as nullable columns on `oc_mydash_dashboards` and only meaningful for rows with `type = 'admin_template'`.

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

### Requirement: Preview Image Upload Endpoint (REQ-TMPL-017)

Administrators MUST be able to upload a preview image for a template, persisted using the existing resource-uploads ("custom-icon-upload") pipeline. The endpoint accepts a base64 data URL body so it shares MIME validation, SVG sanitisation, and the 5 MB size cap with `POST /api/resources`.

> NOTE (D4 — resource-uploads pipeline reuse): The `POST /api/admin/templates/{uuid}/preview-image` endpoint MUST delegate persistence to {@see ResourceService::upload()}. The body shape is `{base64: 'data:image/<type>;base64,<bytes>'}`. Allowed image types are PNG, JPG, GIF, WebP, SVG (sanitised). The persisted public URL is written to `templatePreviewImage` and returned as `{previewImage: '<url>'}`. Do not introduce a parallel image-persistence mechanism.

#### Scenario: Upload preview image for a template

- GIVEN an admin user and an admin template with UUID "abc123"
- WHEN the admin sends `POST /api/admin/templates/abc123/preview-image` with a JSON body containing `{base64: 'data:image/png;base64,<bytes>'}`
- THEN the system MUST save the image via the resource-uploads pipeline
- AND the image MUST be persisted under `<appdata>/resources/` with a high-entropy filename
- AND the template's `templatePreviewImage` field MUST be updated with the URL
- AND the response MUST return HTTP 200 with `{"status": "success", "previewImage": "/apps/mydash/resource/<filename>"}`

#### Scenario: Non-admin cannot upload preview image

- GIVEN a regular user "alice"
- WHEN she sends `POST /api/admin/templates/abc123/preview-image` with a base64 image
- THEN the system MUST return HTTP 403
- AND the template's `templatePreviewImage` MUST NOT be modified

#### Scenario: Upload replaces previous preview image

- GIVEN a template with `templatePreviewImage: '/apps/mydash/resource/old.png'`
- WHEN an admin uploads a new image via `POST /api/admin/templates/{uuid}/preview-image`
- THEN the system MUST overwrite the column with the new URL
- AND `templatePreviewImage` MUST point to the new image
- AND the old image file MAY be cleaned up (implementation-dependent)

#### Scenario: Invalid file format is rejected

- GIVEN an admin sends `POST /api/admin/templates/abc123/preview-image` with a `data:application/pdf;...` body
- THEN the system MUST return HTTP 400 with an `invalid_image` error code
- AND only image formats (PNG, JPG, GIF, WebP, SVG) MUST be accepted

#### Scenario: Preview image URL in gallery response

- GIVEN a template with a recently uploaded preview image
- WHEN a user calls `GET /api/templates/gallery`
- THEN the template object MUST include the `previewImage` URL
- AND the image MUST be immediately accessible (no delay)

> NOTE (D1 — Storage divergence): MyDash stores templates as `type='admin_template'` rows in `oc_mydash_dashboards`. This is a deliberate and permanent divergence from the reference implementation's `/{lang}/_templates/` filesystem-folder convention. Reasons: (1) the existing REQ-TMPL-001..011 capability is already shipped — switching storage models would be a breaking change; (2) `WHERE type='admin_template'` is a single indexed query; the filesystem approach requires a full page-tree walk with path-segment string-matching; (3) DB enum cleanly separates kind from location; (4) MyDash supports DB-backed dashboards that have no GroupFolder and therefore no `_templates/` folder — a cross-backend representation requires the DB type column; (5) ACL equivalence is already provided by the `dashboard-sharing` capability. Do not attempt to converge on the filesystem-folder approach.

## Non-Functional Requirements

- **Performance**: Template distribution (copying placements) MUST complete within 2 seconds per user, even for templates with 20+ widget placements. The first-access check MUST add no more than 200ms to the initial dashboard load. `GET /api/templates/gallery` MUST return within 500ms even with 100+ templates; the gallery list SHOULD NOT fetch widget placements (use `WidgetPlacementMapper::countByDashboardId()` for the count, not `findByDashboardId()`).
- **Data integrity**: Template copies MUST be atomic -- if any placement fails to copy, the entire copy operation MUST be rolled back. The single-default invariant MUST be enforced at the database/service level. Save-as-template (REQ-TMPL-015) deep-copy MUST also be atomic — if placement copy fails, the entire operation MUST be rolled back.
- **Scalability**: Template distribution MUST work efficiently for organizations with 1000+ users. The system SHOULD NOT eagerly copy templates to all users; copies MUST be created on-demand at first access.
- **Security**: Only Nextcloud admin users MUST be able to create, update, or delete templates. Only dashboard owners can call `POST /api/dashboards/{uuid}/save-as-template`. Only admins can call `POST /api/admin/templates/{uuid}/preview-image`. Group membership checks MUST use Nextcloud's `IGroupManager` API.
- **Storage**: Preview images MUST be stored using the existing resource-uploads pipeline (same MIME validation, SVG sanitisation, 5 MB cap as `POST /api/resources`).
- **Localization**: Admin template management UI labels and error messages MUST support English and Dutch.

### Current Implementation Status

**Fully implemented:**
- REQ-TMPL-001 (Create Admin Template): `AdminTemplateService::createTemplate()` in `lib/Service/AdminTemplateService.php` creates dashboards with `type: "admin_template"`, `userId: null`, default `gridColumns: 12`. Default clearing via `clearDefaultTemplates()` is implemented.
- REQ-TMPL-002 (List Admin Templates): `AdminTemplateService::listTemplates()` calls `DashboardMapper::findAdminTemplates()`.
- REQ-TMPL-003 (Update Admin Template): `AdminTemplateService::updateTemplate()` with `applyTemplateUpdates()` handles name, description, targetGroups, permissionLevel, isDefault, gridColumns.
- REQ-TMPL-004 (Delete Admin Template): `AdminTemplateService::deleteTemplate()` deletes placements first via `placementMapper->deleteByDashboardId()`, then deletes the template.
- REQ-TMPL-005 (Template Distribution): `TemplateService::getApplicableTemplate()` checks group membership via `IGroupManager::getUserGroupIds()`. `createDashboardFromTemplate()` copies all placements including `isCompulsory` flags.
- REQ-TMPL-006 (Template Copy Independence): Copies are independent -- `buildDashboardFromTemplate()` creates a new Dashboard entity, `copyTemplatePlacements()` creates new WidgetPlacement entities.
- REQ-TMPL-007 (Template Widget Management): Templates share the same widget placement API as regular dashboards.
- REQ-TMPL-008 (Only One Default): `clearDefaultTemplates()` on DashboardMapper ensures single default.
- REQ-TMPL-009 (Get Template with Placements): `AdminTemplateService::getTemplateWithPlacements()` returns template + placements.
- REQ-TMPL-014 (Gallery endpoint): `AdminTemplateService::getGallery()` + `TemplateController::gallery()` expose `GET /api/templates/gallery`. Backed by `DashboardMapper::findAllTemplatesForGallery()` and the composite `(type, template_category)` index added in `Version001012Date20260503000000`.
- REQ-TMPL-015 (Save-as-template): `AdminTemplateService::saveAsTemplate()` + `TemplateController::saveAsTemplate()` expose `POST /api/dashboards/{uuid}/save-as-template`. Owner-only, transactional, uses `WidgetPlacementMapper::cloneToDashboard()` for the deep copy.
- REQ-TMPL-016 (Metadata fields): `templateCategory`, `templateDescription`, `templatePreviewImage` columns added to `oc_mydash_dashboards`; serialised via `Dashboard::jsonSerialize()`.
- REQ-TMPL-017 (Preview image upload): `AdminTemplateService::uploadPreviewImage()` + `AdminController::uploadTemplatePreviewImage()` expose `POST /api/admin/templates/{uuid}/preview-image`. Reuses `ResourceService::upload()` for storage.

**Not yet implemented:**
- REQ-TMPL-001 validation: No server-side validation for `permissionLevel` values.
- REQ-TMPL-002 widget_count: Template list response does NOT include a widget placement count.
- REQ-TMPL-005 multi-template distribution: Only ONE template is distributed per first-access.
- REQ-TMPL-011 group fetching: `AdminSettings.vue` group selector has `availableGroups` hardcoded to empty array.

### Standards & References
- Nextcloud Group API: `OCP\IGroupManager::getUserGroupIds()`
- Nextcloud User API: `OCP\IUserManager::get()`
- WCAG 2.1 AA for the admin template management UI (modal dialogs, form fields)
- WAI-ARIA: Modal dialog accessibility via `NcModal` component
