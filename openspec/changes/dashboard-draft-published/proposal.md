# Dashboard publication workflow

## Why

Today MyDash dashboards are either in an unpublished (draft, invisible) or published (shared) state. There is no notion of scheduling a dashboard for future publication, or of preserving audit history when a dashboard transitions from draft to published. This change introduces a publication-state workflow (`draft` / `published` / `scheduled`) so users can:

- Create dashboards and keep them private until ready (draft state)
- Publish dashboards to their intended audience (published state)
- Schedule dashboards for automatic publication at a future date/time (scheduled state)
- Unpublish dashboards back to draft if needed

The change preserves backward compatibility: existing dashboards are backfilled to `published` state on migration to maintain current visibility behaviour.

## What Changes

- Add `publicationStatus ENUM('draft','published','scheduled') NOT NULL DEFAULT 'draft'` column on `oc_mydash_dashboards`.
- Add `publishAt TIMESTAMP NULL` — required when `publicationStatus = 'scheduled'`; ignored otherwise.
- Add `publishedAt TIMESTAMP NULL` — set automatically when transitioning to `published`; preserved when unpublishing.
- A dashboard in `draft` state is visible ONLY to its owner and to Nextcloud admins, never in `GET /api/dashboards/visible` for other viewers.
- A dashboard in `published` state is visible to its normal audience (per existing dashboard visibility rules and shares).
- A dashboard in `scheduled` state behaves as `draft` until `publishAt <= now()`, then is treated as `published`. The transition is detectable on every read (lazy materialisation).
- Expose `POST /api/dashboards/{uuid}/publish` — set status to `published`, set `publishedAt = now()`. Owner-or-admin only.
- Expose `POST /api/dashboards/{uuid}/unpublish` — set status to `draft`, preserve `publishedAt` historical. Owner-or-admin only.
- Expose `POST /api/dashboards/{uuid}/schedule` with body `{publishAt: ISO-8601}` — set status to `scheduled`, validate `publishAt > now()`, return 400 if past.
- Status transitions are audit-logged via Nextcloud activity: `dashboard_published`, `dashboard_unpublished`, `dashboard_scheduled`.
- New dashboards created via `POST /api/dashboard` MUST default to `publicationStatus = 'draft'` to preserve a "create now, share later" workflow.
- Background job (optional, sibling `background-job-feed-refresh` is unrelated): periodically (every 5 minutes) eagerly flip past-due `scheduled` rows to `published` for cleaner audit logs.

## Capabilities

### New Capabilities

(none — the feature folds into the existing `dashboards` capability as a delta)

### Modified Capabilities

- `dashboards`: adds REQ-DASH-019 (schema: publication-state columns), REQ-DASH-020 (draft visibility rules), REQ-DASH-021 (publish action), REQ-DASH-022 (unpublish action), REQ-DASH-023 (schedule action), REQ-DASH-024 (lazy resolution of scheduled-as-published), REQ-DASH-025 (migration backfill to published). Existing REQ-DASH-001..018 are untouched.

## Impact

**Affected code:**

- `lib/Db/Dashboard.php` — extend entity with `publicationStatus`, `publishAt`, `publishedAt` fields and getters/setters
- `lib/Db/DashboardMapper.php` — add `findVisibleToUser()` method that filters by publication state and applies lazy materialisation for scheduled dashboards; update `findByUserId()` result set processing to handle scheduled-as-published logic
- `lib/Service/DashboardService.php` — add `publish()`, `unpublish()`, `schedule()` methods; update `createDashboard()` to default new dashboards to `publicationStatus = 'draft'`
- `lib/Service/ActivityService.php` or similar — log activity for `dashboard_published`, `dashboard_unpublished`, `dashboard_scheduled`
- `lib/Controller/DashboardController.php` — three new POST endpoints: `/api/dashboards/{uuid}/publish`, `/api/dashboards/{uuid}/unpublish`, `/api/dashboards/{uuid}/schedule`
- `appinfo/routes.php` — register the three new routes
- `lib/Migration/VersionXXXXDate2026...AddPublicationState.php` — schema migration adding three columns + index on `(userId, publicationStatus)` for fast visible-dashboard queries; backfill existing rows to `publicationStatus = 'published'`
- `src/stores/dashboards.js` — track `publicationStatus`, `publishAt`, `publishedAt` in store; update `/api/dashboards/visible` filtering logic on client side to respect publication state
- `src/views/DashboardDetail.vue` — UI for publish/unpublish/schedule actions (deferred to a follow-up `dashboard-publication-ui` change; this change only ships the backend + store wiring)

**Affected APIs:**

- 3 new routes (no existing routes changed)
- Existing `GET /api/dashboards` and `GET /api/dashboards/visible` filtering must now respect publication state

**Dependencies:**

- `OCP\Activity\IManager` — for audit logging (already used elsewhere in MyDash)
- No new composer or npm dependencies

**Migration:**

- Non-breaking: the migration adds three nullable/defaulted columns. All existing dashboard rows are backfilled to `publicationStatus = 'published'` so visibility behaviour is preserved immediately after migration.
- No data loss.
