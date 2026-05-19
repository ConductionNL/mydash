# Tasks — dashboard-templates

## 1. Schema migration

- [ ] 1.1 Create `lib/Migration/VersionXXXXDate2026...AddTemplateMetadataColumns.php` adding three columns to `oc_mydash_dashboards`:
  - `templateCategory VARCHAR(64) NULL`
  - `templateDescription TEXT NULL`
  - `templatePreviewImage TEXT NULL`
- [ ] 1.2 Migration adds index `idx_mydash_template_category` on `(templateCategory)` for fast category filtering
- [ ] 1.3 Confirm migration is reversible (drop columns + index in `postSchemaChange` rollback path)
- [ ] 1.4 Run migration locally against sqlite, mysql, and postgres; verify schema applied cleanly each time

## 2. Domain model

- [ ] 2.1 Add `templateCategory`, `templateDescription`, `templatePreviewImage` fields to `Dashboard` entity with getters/setters (Entity `__call` pattern — no named args)
- [ ] 2.2 Update `Dashboard::jsonSerialize()` to include all three new fields (nullable in output)
- [ ] 2.3 Add `Dashboard::getWidgetCount(): int` method that returns count of associated placements (used in gallery serialisation)

## 3. Mapper layer

- [ ] 3.1 Add `DashboardMapper::findAllTemplatesForGallery(?string $category = null, ?string $sortBy = 'name'): array`
  - Filters to `type = 'admin_template'`
  - If `$category` is provided, filters to `templateCategory = $category`
  - If `$sortBy = 'name'` (default), sorts by `templateCategory, name`
  - If `$sortBy = 'updatedAt'`, sorts by `updatedAt DESC` (recency)
  - Returns full Dashboard entities (widget placements fetched separately if needed for count)
- [ ] 3.2 Add `DashboardMapper::findByOwnerUuid(string $userId, string $uuid): ?Dashboard` — used by save-as-template ownership check
- [ ] 3.3 Add fixture-based PHPUnit test covering: gallery with mixed categories, filter by category, sort by recency, empty category result

## 4. Service layer

- [ ] 4.1 In `AdminTemplateService` add `getGallery(?string $category = null, ?string $sort = 'name'): array` that:
  - Calls `DashboardMapper::findAllTemplatesForGallery()`
  - Serializes each template with gallery metadata (uuid, name, description, category, previewImage, gridColumns, widgetCount, lastUpdatedAt)
  - Returns raw array (controller handles HTTP response wrapping)
- [ ] 4.2 Add `DashboardService::saveAsTemplate(string $userId, string $dashboardUuid, array $metadata): Dashboard` that:
  - Ownership check: dashboard must belong to `$userId` and have `type = 'user'`
  - Create new Dashboard with `type = 'admin_template'`, fresh UUID, `userId = null`, inherited `gridColumns`
  - Set `templateCategory`, `templateDescription`, `templatePreviewImage` from `$metadata`
  - Deep-copy all placements from source via `WidgetPlacementMapper::copyPlacements(sourceId, newId)`
  - Throw `\OCA\MyDash\Exception\PermissionException` on ownership mismatch
  - Return the new template
- [ ] 4.3 Add `AdminTemplateService::uploadPreviewImage(string $templateUuid, \Psr\Http\Message\UploadedFileInterface $file): string` that:
  - Ownership check: admin-only via `IGroupManager::isAdmin()`
  - Validate file MIME type (PNG, JPG, GIF, WebP, SVG only)
  - Store via custom-icon-upload pattern (reuse from `openspec/changes/custom-icon-upload-pattern/`)
  - Update template's `templatePreviewImage` to the new URL
  - Return the new URL
  - Throw `\OCA\MyDash\Exception\InvalidImageException` on invalid format or upload failure

## 5. Controller + routes

- [ ] 5.1 Create `lib/Controller/TemplateController.php` (new) extending `OCP\AppFramework\Controller`
- [ ] 5.2 Add `TemplateController::gallery()` method:
  - Route: `GET /api/templates/gallery`
  - Attribute: `#[NoAdminRequired]` (logged-in user only)
  - Query params: `category` (optional), `sort` (optional, default 'name')
  - Call `AdminTemplateService::getGallery($category, $sort)`
  - Return HTTP 200 with array of gallery objects
- [ ] 5.3 Add `TemplateController::saveAsTemplate()` method:
  - Route: `POST /api/dashboards/{uuid}/save-as-template`
  - Attribute: `#[NoAdminRequired]` (logged-in user only)
  - Extract user ID from `$this->userId`
  - Request body: `{name, description, category, previewImage}` all optional except name
  - Call `DashboardService::saveAsTemplate($userId, $uuid, $metadata)`
  - Catch `PermissionException` → HTTP 403
  - Return HTTP 201 with the new template object
- [ ] 5.4 Add `AdminController::uploadPreviewImage()` method (add to existing `AdminController`):
  - Route: `POST /api/admin/templates/{uuid}/preview-image`
  - Attribute: `#[NoAdminRequired]` with in-body admin check
  - Multipart body: `file` (required)
  - Call `AdminTemplateService::uploadPreviewImage($uuid, $_FILES['file'])`
  - Catch `InvalidImageException` → HTTP 400 with error message
  - Return HTTP 200 with `{"previewImage": "..."}`
- [ ] 5.5 Register three routes in `appinfo/routes.php`:
  - `GET /api/templates/gallery`
  - `POST /api/dashboards/{uuid}/save-as-template`
  - `POST /api/admin/templates/{uuid}/preview-image`

## 6. Widget placement copying

- [ ] 6.1 Add `WidgetPlacementMapper::copyPlacements(int $sourceId, int $targetId): void` that:
  - Query all placements for `sourceId`
  - For each placement, create a new row for `targetId` with identical data (except id is auto-incremented)
  - Do NOT copy `isCompulsory` flag (it's only meaningful for template→user distribution)
- [ ] 6.2 Test via PHPUnit fixture: copy 4 placements, verify all appear on target, verify edit independence

## 7. Frontend store

- [ ] 7.1 Extend or create `src/stores/templates.js` with state for gallery:
  - `galleryTemplates`: array of gallery objects with `{uuid, name, description, category, previewImage, gridColumns, widgetCount, lastUpdatedAt}`
  - `selectedCategory`: currently active filter (optional, for UI state)
  - `sortBy`: current sort order ('name' or 'updatedAt')
- [ ] 7.2 Add actions:
  - `fetchGallery(category?, sortBy?)` — calls `GET /api/templates/gallery` with query params
  - `saveAsTemplate(dashboardUuid, metadata)` — calls `POST /api/dashboards/{uuid}/save-as-template`
  - `uploadPreviewImage(templateUuid, file)` — calls `POST /api/admin/templates/{uuid}/preview-image`
- [ ] 7.3 Update existing store methods that modify templates to invalidate gallery cache

## 8. Frontend UI

- [ ] 8.1 Create `src/views/TemplateGallery.vue` (new) displaying:
  - List of gallery cards with preview image, title, description, category badge, grid-column count, last-updated date
  - Category filter dropdown populated from unique categories in galleryTemplates
  - Sort toggle (name / recency)
  - "Save as template" action (route to save-as-template dialog after user selects source dashboard)
- [ ] 8.2 Create `src/components/TemplateCard.vue` (new) for individual gallery card rendering with:
  - Preview image (placeholder if null)
  - Title, description, category badge
  - "Use this template" button (instantiate via existing REQ-TMPL-005 flow)
  - (Admin only) "Edit metadata" / "Upload preview" actions
- [ ] 8.3 Create `src/components/SaveAsTemplateModal.vue` (new) for user to provide:
  - Template name (required)
  - Template description (optional, longer text)
  - Category dropdown (optional, admin-curated list or freeform input)
  - Preview image uploader (optional, file drag-drop)
  - Confirm button calls store action
- [ ] 8.4 Update `src/views/DashboardList.vue` to add "Save as template" action in dashboard context menu (calls SaveAsTemplateModal)
- [ ] 8.5 Wire gallery view into main navigation (defer specific UI location to design phase)

## 9. PHPUnit tests

- [ ] 9.1 `DashboardMapperTest::findAllTemplatesForGallery` — basic lookup, category filter, sort by name vs updatedAt, empty result
- [ ] 9.2 `DashboardMapperTest::findByOwnerUuid` — owned vs non-owned dashboard lookup
- [ ] 9.3 `AdminTemplateServiceTest::gallery` — serialisation of gallery objects, widget count included
- [ ] 9.4 `DashboardServiceTest::saveAsTemplate` — ownership check, fresh UUID, placement copy, metadata assignment, non-owner rejection (HTTP 403)
- [ ] 9.5 `AdminTemplateServiceTest::uploadPreviewImage` — admin-only enforcement, MIME type validation, URL update, invalid format rejection
- [ ] 9.6 `WidgetPlacementMapperTest::copyPlacements` — verify all fields copied, new IDs assigned, no side effects on source
- [ ] 9.7 Test save-as-template with source dashboard having `isActive: 1` — verify resulting template has `isActive: 0`

## 10. End-to-end Playwright tests

- [ ] 10.1 User calls `GET /api/templates/gallery` and retrieves list with category and preview image metadata
- [ ] 10.2 User filters gallery by category via `?category=marketing` and sees only matching templates
- [ ] 10.3 User saves their personal dashboard as a template via `POST /api/dashboards/{uuid}/save-as-template` with metadata, receives HTTP 201 with new template UUID
- [ ] 10.4 Saved template appears in gallery response with correct metadata
- [ ] 10.5 Admin uploads preview image via `POST /api/admin/templates/{uuid}/preview-image`, template's `previewImage` field is updated
- [ ] 10.6 Non-owner attempting save-as-template on another user's dashboard receives HTTP 403
- [ ] 10.7 Non-admin attempting preview image upload receives HTTP 403

## 11. Quality gates

- [ ] 11.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered along the way
- [ ] 11.2 ESLint + Stylelint clean on all touched Vue/JS files
- [ ] 11.3 Update generated OpenAPI spec / Postman collection with three new endpoints
- [ ] 11.4 `i18n` keys for all new strings in both `nl` and `en` per i18n requirement:
  - "Save as template"
  - "Template gallery"
  - "Preview image"
  - "Invalid image format"
  - "Cannot delete the only dashboard" (if applicable)
- [ ] 11.5 SPDX headers on every new PHP file (inside docblock per SPDX-in-docblock convention) — gate-spdx must pass
- [ ] 11.6 Run all 10 `hydra-gates` locally before opening PR
