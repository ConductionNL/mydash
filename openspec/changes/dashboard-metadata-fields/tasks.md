# Tasks — dashboard-metadata-fields

## 1. Schema migration

- [ ] 1.1 Create `lib/Migration/VersionXXXXDate2026...AddMetadataFieldsTable.php` creating `oc_mydash_metadata_fields` table with columns: `id INT PRIMARY KEY`, `key VARCHAR(64) UNIQUE NOT NULL`, `label VARCHAR(255) NOT NULL`, `type ENUM('text','number','date','select','multi-select','boolean') NOT NULL`, `options JSON NULL`, `required SMALLINT(0/1) DEFAULT 0`, `sortOrder INT DEFAULT 0`, `createdAt DATETIME`, `updatedAt DATETIME`
- [ ] 1.2 Same migration creates `oc_mydash_metadata_values` table with columns: `id INT PRIMARY KEY`, `dashboardUuid VARCHAR(36) NOT NULL`, `fieldId INT NOT NULL`, `value TEXT NOT NULL`, composite unique constraint on `(dashboardUuid, fieldId)`, foreign key `fieldId` → `oc_mydash_metadata_fields(id)`
- [ ] 1.3 Add composite indexes: `idx_metadata_fields_key` on `key`, `idx_metadata_values_dashboard` on `dashboardUuid`, `idx_metadata_values_field` on `fieldId`
- [ ] 1.4 Confirm migration is reversible; verify schema applied cleanly on sqlite, mysql, and postgres locally

## 2. Domain model — Field definitions

- [ ] 2.1 Create `lib/Db/MetadataField.php` entity with getters/setters for: id, key, label, type, options (JSON-decoded to array on read), required, sortOrder, createdAt, updatedAt
- [ ] 2.2 Add constants for field types: `const TYPE_TEXT = 'text'`, `TYPE_NUMBER = 'number'`, `TYPE_DATE = 'date'`, `TYPE_SELECT = 'select'`, `TYPE_MULTI_SELECT = 'multi-select'`, `TYPE_BOOLEAN = 'boolean'`
- [ ] 2.3 Add `jsonSerialize()` method to return all fields with options JSON-encoded if present
- [ ] 2.4 Add validation helper method `isSelectType(): bool` (returns true if type is `select` or `multi-select`)

## 3. Domain model — Field values

- [ ] 3.1 Create `lib/Db/MetadataValue.php` entity with getters/setters for: id, dashboardUuid, fieldId, value
- [ ] 3.2 Add `jsonSerialize()` method returning all fields

## 4. Mapper layer — Fields

- [ ] 4.1 Create `lib/Db/MetadataFieldMapper.php` with methods: `create(MetadataField): int`, `findAll(): array`, `find(int $id): ?MetadataField`, `findByKey(string $key): ?MetadataField`, `update(MetadataField): bool`, `delete(int $id): bool`
- [ ] 4.2 Enforce `key` uniqueness: `findByKey()` checks for existing key before insert/update; throw `DuplicateKeyException` on collision
- [ ] 4.3 Add `deleteWithCascade(int $id): bool` that deletes the field definition AND all corresponding value rows in `oc_mydash_metadata_values`
- [ ] 4.4 Add fixture-based test: create field, read it back, update label + sortOrder, verify key cannot be changed, delete with cascade confirms all values gone

## 5. Mapper layer — Values

- [ ] 5.1 Create `lib/Db/MetadataValueMapper.php` with methods: `create(MetadataValue): int`, `findByDashboard(string $dashboardUuid): array`, `findByDashboardAndField(string $dashboardUuid, int $fieldId): ?MetadataValue`, `upsert(MetadataValue): int` (insert if new, update if exists), `deleteByDashboard(string $dashboardUuid): int`, `deleteByField(int $fieldId): int`
- [ ] 5.2 Add test fixture: create dashboard with 3 field values, upsert a value (verify update path), delete by dashboard (verify cascade), verify orphan-safe read

## 6. Service layer — Metadata service

- [ ] 6.1 Create `lib/Service/MetadataService.php` with dependency injection: `MetadataFieldMapper`, `MetadataValueMapper`, `IGroupManager`
- [ ] 6.2 Implement admin-only guard `ensureAdminAccess(string $userId)` that throws `\OCP\AppFramework\OCS\OCSForbiddenException` if not admin
- [ ] 6.3 Implement `createFieldDefinition(string $key, string $label, string $type, ?array $options, bool $required, ?int $sortOrder): MetadataField` with validation:
  - `key` must be lowercase, max 64 chars, alphanumeric + underscore only (slugified via a helper)
  - `label` must be 1-255 chars
  - `type` must be one of the enum values
  - if type is `select` or `multi-select`, `options` must be a non-empty array of strings; if not select type, options MUST be null
  - throw 400 errors (via `BadRequestException`) for validation failures
- [ ] 6.4 Implement `updateFieldDefinition(int $id, array $patch): MetadataField` that allows updating `label`, `sortOrder`, `required`, and `options`, but FORBIDS changing `key` (throw 400 if attempted)
- [ ] 6.5 Implement `deleteFieldDefinition(int $id, bool $cascade = false): bool` that:
  - if `cascade` is false and orphan values exist, throw 409 Conflict with hint to use `?cascade=true`
  - if `cascade` is true or no orphans, delete field and cascade-delete all values
- [ ] 6.6 Implement `getMetadataForDashboard(string $dashboardUuid): array` that:
  - issues 1 JOIN query: `SELECT v.value, f.key FROM oc_mydash_metadata_values v JOIN oc_mydash_metadata_fields f ON v.fieldId = f.id WHERE v.dashboardUuid = ?`
  - returns flat key-value object `{key: value, ...}`
  - gracefully handles stale fieldId references (orphans): skip those rows and log a warning
  - never crash on orphaned values
- [ ] 6.7 Implement `setMetadataForDashboard(string $dashboardUuid, array $keyValues): void` that:
  - for each key in the input, resolve fieldId via mapper
  - throw 400 if key does not exist
  - validate value against field type and constraints
  - upsert each value row
  - throw 400 on validation failure (e.g., "field 'department' is required", "field 'status' value 'unknown' not in options")
- [ ] 6.8 Implement `filterDashboards(array $dashboards, ?array $metadataFilters): array` that:
  - accepts filters in shape: `{"metadata.department": "marketing", "metadata.priority": {"min": 5, "max": 10}, "metadata.date": {"after": "2026-01-01"}}`
  - for each filter, load field definition to determine type
  - evaluate every dashboard against the filter set (all must match for dashboard to be included)
  - text/select/boolean: exact match
  - number/date: range query if `min`/`max` or `after`/`before` keys present
  - return filtered array

## 7. Validation service helper

- [ ] 7.1 Create `lib/Service/MetadataValidationService.php` with method `validateValue(string $value, MetadataField $field): void` that:
  - for text: no validation beyond length (can be empty if not required)
  - for number: ensure value is numeric (decimal allowed), throw 400 if not
  - for date: ensure value is ISO-8601 string (YYYY-MM-DD), throw 400 if not
  - for select: value must be in `field->options`, throw 400 if not
  - for multi-select: value must be JSON array of strings from `field->options`, throw 400 if any element not in options
  - for boolean: value must be exactly "0" or "1" string, throw 400 otherwise
  - if field is required and value is null/empty string/empty array, throw 400
- [ ] 7.2 Add test: validate number field rejects "abc", accepts "42.5", rejects empty if required

## 8. Controller — Admin field endpoints

- [ ] 8.1 Create `lib/Controller/MetadataAdminController.php` with `#[NoAdminRequired]` + runtime admin check in every method
- [ ] 8.2 Implement `listFields()` mapped to `GET /api/admin/metadata-fields` — returns `["fields": [Field[], "count": int]` with fields sorted by sortOrder
- [ ] 8.3 Implement `createField(string $key, string $label, string $type, ?array $options, int $required = 0, int $sortOrder = 0)` mapped to `POST /api/admin/metadata-fields` — returns 201 + Field
- [ ] 8.4 Implement `getField(int $id)` mapped to `GET /api/admin/metadata-fields/{id}` — returns Field or 404
- [ ] 8.5 Implement `updateField(int $id, ?string $label, ?int $sortOrder, ?int $required, ?array $options)` mapped to `PUT /api/admin/metadata-fields/{id}` — returns 200 + updated Field, or 400 if key change attempted
- [ ] 8.6 Implement `deleteField(int $id, bool $cascade = false)` mapped to `DELETE /api/admin/metadata-fields/{id}?cascade=true|false` — returns 200 on success, 409 if orphans and no cascade, 403 if not admin

## 9. Controller — Dashboard metadata endpoints

- [ ] 9.1 Modify `lib/Controller/DashboardController.php` to add `getMetadata(string $uuid)` mapped to `GET /api/dashboards/{uuid}/metadata` (logged-in user, read-only) — returns flat key-value object
- [ ] 9.2 Add `setMetadata(string $uuid)` mapped to `PUT /api/dashboards/{uuid}/metadata` (logged-in user) — accepts flat key-value object, upserts all keys, returns 200 + updated metadata
- [ ] 9.3 Modify `listDashboards()` and `getDashboard(uuid)` methods to accept optional query filter `?metadata.<key>=<value>` and pass to `MetadataService::filterDashboards()`
- [ ] 9.4 Add test: list dashboards filter by `?metadata.department=marketing`, verify only matching dashboards returned

## 10. Routes registration

- [ ] 10.1 Register 6 routes in `appinfo/routes.php`:
  - `GET /api/admin/metadata-fields` → MetadataAdminController::listFields
  - `POST /api/admin/metadata-fields` → MetadataAdminController::createField
  - `GET /api/admin/metadata-fields/{id}` → MetadataAdminController::getField
  - `PUT /api/admin/metadata-fields/{id}` → MetadataAdminController::updateField
  - `DELETE /api/admin/metadata-fields/{id}` → MetadataAdminController::deleteField
  - `GET /api/dashboards/{uuid}/metadata` → DashboardController::getMetadata
  - `PUT /api/dashboards/{uuid}/metadata` → DashboardController::setMetadata
- [ ] 10.2 Confirm all routes follow Nextcloud routing conventions and auth guards

## 11. Frontend store

- [ ] 11.1 Extend `src/stores/dashboards.js` to add getter `metadataFields` that returns all field definitions (loaded from admin endpoint)
- [ ] 11.2 Add `metadataByDashboard` computed property that returns `{dashboardUuid: {key: value, ...}, ...}`
- [ ] 11.3 Add action `fetchMetadataFields()` that calls `GET /api/admin/metadata-fields` and caches result
- [ ] 11.4 Add action `updateDashboardMetadata(dashboardUuid, keyValues)` that calls `PUT /api/dashboards/{uuid}/metadata` and updates store

## 12. PHPUnit tests

- [ ] 12.1 `MetadataFieldMapperTest` — create/read/update/delete cycle, key uniqueness, cascade delete
- [ ] 12.2 `MetadataValueMapperTest` — upsert cycle, find by dashboard, orphan rows safe
- [ ] 12.3 `MetadataServiceTest::createFieldDefinition` — valid types, invalid key/label/options, select type requires options
- [ ] 12.4 `MetadataServiceTest::updateFieldDefinition` — allow label/sortOrder/required/options, forbid key change
- [ ] 12.5 `MetadataServiceTest::validateValue` — number/date/select/multi-select/boolean validation, required fields
- [ ] 12.6 `MetadataServiceTest::setMetadataForDashboard` — rejects non-existent field key, validates types, upserts values
- [ ] 12.7 `MetadataServiceTest::getMetadataForDashboard` — returns key-value object, handles orphan stale fieldIds gracefully
- [ ] 12.8 `MetadataServiceTest::filterDashboards` — text exact match, number range, date range, multi-filter AND logic
- [ ] 12.9 `MetadataAdminControllerTest` — 403 for non-admin on all endpoints, CRUD success cases
- [ ] 12.10 `DashboardControllerTest` — get/put metadata, filter by metadata on list endpoint

## 13. End-to-end Playwright tests

- [ ] 13.1 Admin creates text field "department" (required), numeric field "priority" (1-10), select field "status" (options: open, in-review, approved)
- [ ] 13.2 User populates dashboard with all three field values
- [ ] 13.3 Admin updates "status" options (removes "in-review"); verify user's dashboard still shows old value (graceful stale-field tolerance)
- [ ] 13.4 Filter dashboards by `?metadata.department=marketing` via API; verify correct subset returned
- [ ] 13.5 User updates dashboard metadata via PUT; verify new values persisted and visible on next GET

## 14. Quality gates

- [ ] 14.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered
- [ ] 14.2 ESLint + Stylelint clean on all touched Vue/JS files
- [ ] 14.3 Update generated OpenAPI spec / Postman collection to document all new endpoints and field definition schema
- [ ] 14.4 `i18n` keys for all validation error messages (`Field '<key>' is required`, `Value '<value>' not in options for field '<label>'`, etc.) in both `nl` and `en`
- [ ] 14.5 SPDX headers on every new PHP file (inside docblock per convention) — gate-spdx must pass
- [ ] 14.6 Run all 10 `hydra-gates` locally before opening PR
