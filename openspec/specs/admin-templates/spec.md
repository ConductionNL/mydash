---
status: reviewed
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

### REQ-TMPL-001: Create Admin Template

Nextcloud administrators MUST be able to create dashboard templates for distribution.

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
- AND any previously default template MUST have its `isDefault` set to 0
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

### REQ-TMPL-002: List Admin Templates

Administrators MUST be able to view all existing templates.

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

### REQ-TMPL-003: Update Admin Template

Administrators MUST be able to modify template configuration and content.

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
- AND existing user copies MUST NOT have their permission level changed retroactively
- NOTE: However, `PermissionService::getEffectivePermissionLevel()` resolves permission from the source template at runtime via `basedOnTemplate`. So if a template's permissionLevel changes, existing user copies will inherit the NEW permission level because the resolution is dynamic.
- AND only new copies created after this change MUST inherit "full"

#### Scenario: Update template widget layout
- GIVEN template id 1 has 4 widget placements
- WHEN the admin adds a new widget to the template and repositions existing ones
- THEN the template's placements MUST be updated
- AND existing user copies MUST NOT be affected (they are independent after creation)

#### Scenario: Mark template as default
- GIVEN template id 1 is not the default and template id 2 is the default
- WHEN the admin sends PUT /api/admin/templates/1 with body `{"isDefault": true}`
- THEN template 1 MUST become the default
- AND template 2 MUST have `isDefault` set to 0 (false) -- enforced by `clearDefaultTemplates()` called before setting the new default

#### Scenario: Non-admin cannot update templates
- GIVEN template id 1 exists
- WHEN regular user "alice" sends PUT /api/admin/templates/1
- THEN the system MUST return HTTP 403

### REQ-TMPL-004: Delete Admin Template

Administrators MUST be able to delete templates.

#### Scenario: Delete a template with no user copies
- GIVEN template id 1 has no user copies
- WHEN the admin sends DELETE /api/admin/templates/1
- THEN the system MUST delete the template
- AND all template widget placements MUST be cascade-deleted
- AND the response MUST return HTTP 200

#### Scenario: Delete a template with existing user copies
- GIVEN template id 1 has been copied to 15 users
- WHEN the admin sends DELETE /api/admin/templates/1
- THEN the system MUST delete the template
- AND existing user copies MUST NOT be affected (they are independent dashboards)
- AND no new copies of this template MUST be created going forward

#### Scenario: Non-admin cannot delete templates
- GIVEN template id 1 exists
- WHEN regular user "alice" sends DELETE /api/admin/templates/1
- THEN the system MUST return HTTP 403

### REQ-TMPL-005: Template Distribution on First Access

When a user accesses MyDash for the first time, the system MUST create personal copies of matching templates.

#### Scenario: First-time user receives default template
- GIVEN a default template "Company Dashboard" exists with `isDefault: true` and 5 widget placements (3 compulsory)
- AND user "alice" has never opened MyDash
- WHEN alice navigates to MyDash (triggers GET /api/dashboard or GET /api/dashboards)
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
- NOTE: The current `TemplateService::getApplicableTemplate()` returns only ONE template (the first matching group-targeted template takes priority over the default). Multiple template distribution is NOT currently implemented -- users receive at most one template copy on first access.

#### Scenario: First-time user not in any target group
- GIVEN template "Marketing Dashboard" targets groups ["marketing"]
- AND no default template exists
- AND user "carol" is only in the "engineering" group
- WHEN carol navigates to MyDash
- THEN the system MUST NOT create any dashboard for carol from the marketing template
- AND carol MUST see an empty dashboard list

#### Scenario: Template already distributed to user
- GIVEN user "alice" already has a personal copy of template "Company Dashboard"
- WHEN alice navigates to MyDash again
- THEN the system MUST NOT create a duplicate copy
- AND the system MUST detect that alice already has a copy of this template

#### Scenario: Multiple templates match the user
- GIVEN templates "Company Dashboard" (default) and "Marketing Dashboard" (targets marketing group)
- AND user "alice" is in the "marketing" group
- WHEN alice navigates to MyDash for the first time
- THEN alice MUST receive a copy of "Marketing Dashboard" (group-targeted template takes priority over default)
- NOTE: The current implementation only distributes ONE template per first-access. Group-targeted templates are evaluated first; the default template is the fallback. Multi-template distribution is not yet implemented.

### REQ-TMPL-006: Template Copy Independence

User copies of templates MUST be fully independent from the source template after creation.

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
- AND alice's dashboard MUST retain its permissionLevel and all placements

### REQ-TMPL-007: Template Widget Management

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

### REQ-TMPL-008: Only One Default Template

The system MUST enforce that at most one template is marked as the default.

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

#### Scenario: Remove default status from the only default template
- GIVEN template "Company Dashboard" has `isDefault: true`
- WHEN the admin sends PUT /api/admin/templates/1 with body `{"is_default": false}`
- THEN the template MUST have `isDefault` set to 0 (false)
- AND no template MUST be the default (this is allowed)

## Non-Functional Requirements

- **Performance**: Template distribution (copying placements) MUST complete within 2 seconds per user, even for templates with 20+ widget placements. The first-access check MUST add no more than 200ms to the initial dashboard load.
- **Data integrity**: Template copies MUST be atomic -- if any placement fails to copy, the entire copy operation MUST be rolled back. The single-default invariant MUST be enforced at the database/service level.
- **Scalability**: Template distribution MUST work efficiently for organizations with 1000+ users. The system SHOULD NOT eagerly copy templates to all users; copies MUST be created on-demand at first access.
- **Security**: Only Nextcloud admin users MUST be able to create, update, or delete templates. Group membership checks MUST use Nextcloud's `IGroupManager` API.
- **Localization**: Admin template management UI labels and error messages MUST support English and Dutch.

### Current Implementation Status

**Fully implemented:**
- REQ-TMPL-001 (Create Admin Template): `AdminTemplateService::createTemplate()` in `lib/Service/AdminTemplateService.php` creates dashboards with `type: "admin_template"`, `userId: null`, default `gridColumns: 12`. `AdminController::createTemplate()` in `lib/Controller/AdminController.php` exposes the endpoint. Default clearing via `clearDefaultTemplates()` is implemented.
- REQ-TMPL-002 (List Admin Templates): `AdminTemplateService::listTemplates()` calls `DashboardMapper::findAdminTemplates()`. Returns all templates serialized. Admin-only enforcement via AdminController (no `#[NoAdminRequired]`).
- REQ-TMPL-003 (Update Admin Template): `AdminTemplateService::updateTemplate()` with `applyTemplateUpdates()` handles name, description, targetGroups, permissionLevel, isDefault, gridColumns. Default-clearing logic is correctly applied when `isDefault: true`.
- REQ-TMPL-004 (Delete Admin Template): `AdminTemplateService::deleteTemplate()` deletes placements first via `placementMapper->deleteByDashboardId()`, then deletes the template. User copies are unaffected.
- REQ-TMPL-005 (Template Distribution): `TemplateService::getApplicableTemplate()` in `lib/Service/TemplateService.php` checks group membership via `IGroupManager::getUserGroupIds()`. Group-targeted templates are checked first, default template is fallback. `createDashboardFromTemplate()` copies all placements including `isCompulsory` flags. Called via `DashboardResolver::handleTemplateResult()` in `lib/Service/DashboardResolver.php`.
- REQ-TMPL-006 (Template Copy Independence): Copies are independent -- `buildDashboardFromTemplate()` creates a new Dashboard entity, `copyTemplatePlacements()` creates new WidgetPlacement entities. `basedOnTemplate` is set for permission resolution only.
- REQ-TMPL-007 (Template Widget Management): Templates share the same widget placement API as regular dashboards (same `oc_mydash_widget_placements` table).
- REQ-TMPL-008 (Only One Default): `clearDefaultTemplates()` on DashboardMapper ensures single default.

**Not yet implemented:**
- REQ-TMPL-001 validation: No server-side validation for `permissionLevel` values (any string accepted). Spec says MUST return 400 for invalid values.
- REQ-TMPL-002 widget_count: Template list response does NOT include a widget placement count per template. Spec says SHOULD include this.
- REQ-TMPL-005 multi-template distribution: Only ONE template is distributed per first-access. The `getApplicableTemplate()` method returns the first match, not all matches.
- REQ-TMPL-005 duplicate detection: No check for whether a user already has a copy of a given template. If a user already has a copy and accesses MyDash, `DashboardResolver::tryGetActiveDashboard()` will find their existing dashboard first, preventing duplication -- but this is implicit, not explicit.
- Template management UI: `AdminSettings.vue` in `src/components/admin/AdminSettings.vue` provides template CRUD via a modal dialog. Group selection uses `NcSelectTags` but `availableGroups` is hardcoded to empty array (groups are NOT fetched from the server).

**Partial implementations:**
- REQ-TMPL-003 permission level retroactivity: The spec NOTE correctly documents that `PermissionService::getEffectivePermissionLevel()` dynamically resolves from the template, meaning changes DO propagate to existing copies at runtime. This contradicts the main requirement text. The spec already documents this conflict.

### Standards & References
- Nextcloud Group API: `OCP\IGroupManager::getUserGroupIds()`
- Nextcloud User API: `OCP\IUserManager::get()`
- WCAG 2.1 AA for the admin template management UI (modal dialogs, form fields)
- WAI-ARIA: Modal dialog accessibility via `NcModal` component

### Specificity Assessment
- The spec is detailed and implementable. Template distribution logic, copy semantics, and default enforcement are well-specified.
- **Missing:** No specification for fetching available Nextcloud groups in the admin UI (the `availableGroups` array is empty).
- **Missing:** No API endpoint to get a single template's widget placements for the admin editor grid (GET /api/admin/templates/{id} exists but the spec doesn't describe the admin template editor grid UI).
- **Ambiguous:** REQ-TMPL-003 says existing copies MUST NOT have permission changed retroactively, but the NOTE acknowledges the runtime resolution does exactly that. The spec should resolve this contradiction.
- **Open question:** Should template distribution be re-triggered when targetGroups change? Currently, new group members get the template on next first-access, but there's no re-check for existing users who are already in a target group.
