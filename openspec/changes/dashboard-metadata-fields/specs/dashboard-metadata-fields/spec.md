---
status: draft
---

# Dashboard Metadata Fields Specification

## Purpose

Dashboard Metadata Fields allow administrators to define custom, queryable attributes that can be attached to every dashboard in a MyDash instance. Once an administrator defines a global registry of field definitions (e.g., "department", "project stage", "audience"), end users populate values for those fields on their dashboards. The field values are then queryable for filtering dashboards in search, widget configuration, and API calls. This capability standardizes what would otherwise be ad-hoc naming conventions and enables the discovery and organization of dashboards at scale.

## Data Model

### Metadata Field Definition (oc_mydash_metadata_fields)

Stores the global registry of field definitions:

- **id**: Auto-increment integer primary key
- **key**: Unique VARCHAR(64) machine name (lowercase, alphanumeric + underscore only, URL-safe)
- **label**: VARCHAR(255) human-readable field name (shown in UI)
- **type**: ENUM('text', 'number', 'date', 'select', 'multi-select', 'boolean')
  - `text`: arbitrary string (no length constraint in type definition; validation at write time if needed)
  - `number`: decimal number (stored as string, validated as numeric at write)
  - `date`: ISO-8601 date string (YYYY-MM-DD)
  - `select`: single-choice from a defined set of options
  - `multi-select`: multiple choices from a defined set of options (stored as JSON array)
  - `boolean`: true/false (stored as "0" or "1" string)
- **options**: JSON NULL-or-array field. Only populated for `select` and `multi-select` types. For other types, MUST be NULL. Format: `["option1", "option2", ...]` (array of strings)
- **required**: SMALLINT(0 or 1) — whether the field MUST have a value on every dashboard (0 = optional, 1 = required)
- **sortOrder**: INT — used to order fields in admin UI and API list responses
- **createdAt**: DATETIME
- **updatedAt**: DATETIME

### Metadata Value (oc_mydash_metadata_values)

Stores field values per dashboard. Each row is a (dashboardUuid, fieldId) pair with a type-encoded value:

- **id**: Auto-increment integer primary key
- **dashboardUuid**: VARCHAR(36) foreign key to `oc_mydash_dashboards.uuid`
- **fieldId**: INT foreign key to `oc_mydash_metadata_fields.id` (if field is deleted, the value row becomes orphaned)
- **value**: TEXT (type-encoded):
  - text: plain string
  - number: decimal string (e.g., "42.5")
  - date: ISO-8601 string (e.g., "2026-05-01")
  - select: single option string (e.g., "marketing")
  - multi-select: JSON array of strings (e.g., `["feature1", "feature2"]`)
  - boolean: "0" or "1" string
- **Composite unique constraint**: (dashboardUuid, fieldId) — only one value per field per dashboard

### Orphaned Values

If a field definition is deleted without cascade, the corresponding value rows remain in the database with a stale `fieldId` that no longer has a matching field definition. The system MUST:
- Never crash when reading orphaned values
- Hide orphaned values from `GET /api/dashboards/{uuid}/metadata` responses
- Offer admin tooling to detect and clean up orphans
- Optionally support cascading delete to avoid orphans entirely

## ADDED Requirements

### Requirement: REQ-MDFL-001 Field Definition CRUD

Administrators MUST be able to create, read, update, and delete field definitions via a dedicated admin API endpoint. Field definitions form the global schema against which all dashboard values are validated.

#### Scenario: Create a text field definition

- GIVEN a logged-in administrator
- WHEN they send `POST /api/admin/metadata-fields` with body `{"key": "department", "label": "Department", "type": "text", "required": 1}`
- THEN the system MUST create a MetadataField record with:
  - `key` set to "department" (lowercase, unique)
  - `label` set to "Department"
  - `type` set to "text"
  - `required` set to 1
  - `options` set to NULL (text type does not use options)
  - `sortOrder` defaulting to 0
  - timestamps set to current time
- AND return HTTP 201 with the created field object

#### Scenario: Validate key format

- GIVEN an administrator
- WHEN they send `POST /api/admin/metadata-fields` with body `{"key": "Invalid Key", "label": "L", "type": "text"}`
- THEN the system MUST return HTTP 400 with error `"Field key must be lowercase alphanumeric with underscores only"`
- AND no record MUST be created

#### Scenario: Create a select field with options

- GIVEN an administrator
- WHEN they send `POST /api/admin/metadata-fields` with body `{"key": "status", "label": "Status", "type": "select", "options": ["open", "closed", "pending"]}`
- THEN the system MUST create a field with:
  - `type` set to "select"
  - `options` set to `["open", "closed", "pending"]`
- AND return HTTP 201

#### Scenario: Select field without options is rejected

- GIVEN an administrator
- WHEN they send `POST /api/admin/metadata-fields` with body `{"key": "status", "label": "Status", "type": "select"}`
- THEN the system MUST return HTTP 400 with error `"Select type requires non-empty options array"`
- AND no record MUST be created

#### Scenario: Non-admin cannot create field definitions

- GIVEN a logged-in non-admin user "alice"
- WHEN she sends `POST /api/admin/metadata-fields` with any body
- THEN the system MUST return HTTP 403

#### Scenario: List all field definitions

- GIVEN 3 field definitions exist with sortOrder values 10, 0, 5
- WHEN an admin sends `GET /api/admin/metadata-fields`
- THEN the system MUST return HTTP 200 with an array of all 3 fields
- AND fields MUST be sorted by `sortOrder` ascending (0, 5, 10)

### Requirement: REQ-MDFL-002 Field Definition Updates

Administrators MUST be able to update a field definition's metadata (label, sort order, required flag, options), but renaming the field's `key` MUST be forbidden — the key is the stable identifier and renaming it breaks existing values.

#### Scenario: Update field label and sortOrder

- GIVEN a field with id=5, key="department", label="Dept", sortOrder=0
- WHEN an admin sends `PUT /api/admin/metadata-fields/5` with body `{"label": "Department (Required)", "sortOrder": 100}`
- THEN the system MUST update the label and sortOrder
- AND set `updatedAt` to current time
- AND return HTTP 200 with the updated field

#### Scenario: Forbid key rename

- GIVEN a field with id=5, key="department"
- WHEN an admin sends `PUT /api/admin/metadata-fields/5` with body `{"key": "division"}`
- THEN the system MUST return HTTP 400 with error `"Field key cannot be renamed"`
- AND the key MUST remain "department"

### Requirement: REQ-MDFL-003 Field Definition Deletion

Administrators MUST be able to delete field definitions. Deletion has two modes: soft (reject if orphans would be created) or cascade (delete all values).

#### Scenario: Delete field with no values

- GIVEN a field id=5 with no corresponding value rows
- WHEN an admin sends `DELETE /api/admin/metadata-fields/5`
- THEN the system MUST delete the field
- AND return HTTP 200

#### Scenario: Delete field with cascade

- GIVEN a field id=5 with 3 corresponding value rows
- WHEN an admin sends `DELETE /api/admin/metadata-fields/5?cascade=true`
- THEN the system MUST delete the field definition
- AND cascade-delete all 3 value rows
- AND return HTTP 200

### Requirement: REQ-MDFL-004 Dashboard Metadata Read

Users MUST be able to read all metadata values for a given dashboard as a flat key-value object.

#### Scenario: Get metadata for a dashboard

- GIVEN user "alice" has a dashboard with uuid="abc-123" and three populated field values: department="marketing", priority=8, status="approved"
- WHEN she sends `GET /api/dashboards/abc-123/metadata`
- THEN the system MUST return HTTP 200 with body:
  ```json
  {
    "department": "marketing",
    "priority": "8",
    "status": "approved"
  }
  ```

#### Scenario: Empty metadata for a new dashboard

- GIVEN a dashboard with uuid="xyz-789" has no metadata values set
- WHEN a user sends `GET /api/dashboards/xyz-789/metadata`
- THEN the system MUST return HTTP 200 with an empty object `{}`

#### Scenario: Orphaned field values are hidden

- GIVEN a dashboard with a value row where fieldId=999 (field no longer exists)
- WHEN a user sends `GET /api/dashboards/xyz-789/metadata`
- THEN the system MUST NOT include that orphaned value in the response
- AND MUST NOT crash

### Requirement: REQ-MDFL-005 Dashboard Metadata Write

Users MUST be able to set or update metadata values for a dashboard via a flat key-value object. Omitted keys are NOT deleted; only keys in the request body are upserted.

#### Scenario: Set metadata on a dashboard

- GIVEN a dashboard with uuid="abc-123" and no prior metadata
- WHEN user sends `PUT /api/dashboards/abc-123/metadata` with body `{"department": "marketing", "priority": "5"}`
- THEN the system MUST create value rows for both fields
- AND return HTTP 200 with the updated metadata object

#### Scenario: Partial update (upsert specific keys)

- GIVEN a dashboard already has metadata: `{"department": "marketing", "priority": "5", "status": "open"}`
- WHEN user sends `PUT /api/dashboards/abc-123/metadata` with body `{"department": "sales", "status": "approved"}`
- THEN the system MUST update "department" and "status"
- AND leave "priority" unchanged at "5"
- AND return the complete updated metadata object

#### Scenario: Setting a required field to null

- GIVEN the "department" field is marked required
- WHEN user sends `PUT /api/dashboards/abc-123/metadata` with body `{"department": null}`
- THEN the system MUST return HTTP 400 with error `"Field 'Department' is required"`

### Requirement: REQ-MDFL-006 Type Validation at Write

The system MUST validate each value against its field's type definition before persisting. Invalid values are rejected with a 400 error.

#### Scenario: Number field validation

- GIVEN a number field "priority"
- WHEN user sends `PUT /api/dashboards/{uuid}/metadata` with body `{"priority": "not-a-number"}`
- THEN the system MUST return HTTP 400 with error `"Field 'Priority' must be a valid number"`

#### Scenario: Number field accepts decimal

- GIVEN a number field "score"
- WHEN user sends `PUT /api/dashboards/{uuid}/metadata` with body `{"score": "42.75"}`
- THEN the system MUST accept and store it

#### Scenario: Date field validation

- GIVEN a date field "go-live"
- WHEN user sends `PUT /api/dashboards/{uuid}/metadata` with body `{"go-live": "invalid-date"}`
- THEN the system MUST return HTTP 400 with error `"Field 'Go Live' must be a valid date (YYYY-MM-DD)"`

#### Scenario: Select field validation

- GIVEN a select field "status" with options `["open", "closed", "pending"]`
- WHEN user sends `PUT /api/dashboards/{uuid}/metadata` with body `{"status": "rejected"}`
- THEN the system MUST return HTTP 400 with error `"Field 'Status' value 'rejected' not in allowed options"`

### Requirement: REQ-MDFL-007 Dashboard Filtering by Metadata

The system MUST expose a query filter mechanism on the dashboard list endpoint to filter dashboards by metadata values using `?metadata.<key>=<value>` syntax.

#### Scenario: Filter dashboards by text field (exact match)

- GIVEN three dashboards with department values "marketing", "sales", "engineering"
- WHEN a user sends `GET /api/dashboards?metadata.department=marketing`
- THEN the system MUST return only the dashboard with department="marketing"

#### Scenario: Filter dashboards by numeric range

- GIVEN five dashboards with priority values 1, 3, 5, 7, 9
- WHEN a user sends `GET /api/dashboards?metadata.priority.min=5&metadata.priority.max=7`
- THEN the system MUST return dashboards with priority 5 and 7 (inclusive range)

#### Scenario: Multiple metadata filters (AND logic)

- GIVEN dashboards with varying department and priority values
- WHEN a user sends `GET /api/dashboards?metadata.department=marketing&metadata.priority.min=5`
- THEN the system MUST return only dashboards matching BOTH conditions

#### Scenario: Filter by select field

- GIVEN dashboards with status values "open", "closed", "pending"
- WHEN a user sends `GET /api/dashboards?metadata.status=open`
- THEN the system MUST return only dashboards with status="open"

### Requirement: REQ-MDFL-008 Permission and Ownership

Reading and writing metadata MUST be scoped to the dashboard's owner (for personal dashboards) or to users with access to the dashboard.

#### Scenario: User can read their own dashboard's metadata

- GIVEN user "alice" owns dashboard "abc-123"
- WHEN she sends `GET /api/dashboards/abc-123/metadata`
- THEN the system MUST return HTTP 200 with the metadata

#### Scenario: User cannot read another user's dashboard metadata

- GIVEN user "bob" owns dashboard "xyz-789", and user "alice" does not have access
- WHEN alice sends `GET /api/dashboards/xyz-789/metadata`
- THEN the system MUST return HTTP 403 or 404

#### Scenario: User can write metadata for their own dashboard

- GIVEN user "alice" owns dashboard "abc-123"
- WHEN she sends `PUT /api/dashboards/abc-123/metadata` with body `{"department": "marketing"}`
- THEN the system MUST update the metadata and return HTTP 200

## Non-Functional Requirements

- **Performance**: `GET /api/admin/metadata-fields` MUST return within 200ms for up to 1000 fields. `GET /api/dashboards?metadata.*=*` MUST return within 1000ms for 10,000 dashboards with filtering applied (indexed queries).
- **Data integrity**: Field key uniqueness MUST be enforced at the database layer via unique constraint.
- **Localization**: All error messages, field labels, and option values MUST support English and Dutch via the i18n system.
- **Graceful degradation**: If metadata reading fails for orphaned values, the system MUST log and continue (never crash the dashboard load).
- **Backward compatibility**: Dashboards without any metadata MUST continue to work (empty metadata object), and the metadata endpoints are opt-in (clients not using them are unaffected).

## Implementation Status

**Not yet implemented** — this is a new capability being introduced with this change.
