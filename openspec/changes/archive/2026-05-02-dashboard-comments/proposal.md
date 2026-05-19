# Dashboard Comments

## Why

Dashboards are shared workspaces within teams and organizations. Currently MyDash offers no way for users to discuss or annotate a dashboard — there is no thread for "why is this widget red?" or "we should change this metric next sprint". This change introduces threaded, one-level-deep comments on dashboards, leveraging Nextcloud's native `ICommentsManager` infrastructure (already used for file comments, talk integration, etc.) so that Nextcloud administrators get unified comment storage, notifications, and audit trails across the platform. Comments are persisted via Nextcloud's existing comment storage layer with no custom database table required.

## What Changes

- Use `ICommentsManager` with object type `mydash_dashboard` and object id = dashboard UUID to store all comments.
- Add a nullable `commentsEnabled SMALLINT(0/1) NULL` column on `oc_mydash_dashboards` to support per-dashboard comment toggle (NULL = follow global setting, 1 = force-on, 0 = force-off).
- Add global admin setting `mydash.comments_enabled_default` (defaults to true) that applies to all dashboards where `commentsEnabled IS NULL`.
- Expose `GET /api/dashboards/{uuid}/comments` returning ordered comment list with nested replies grouped beneath top-level comments.
- Expose `POST /api/dashboards/{uuid}/comments` accepting `{message, parentId?}` to create top-level or reply comments.
- Enforce one-level-deep nesting: a top-level comment may have replies, but replies cannot themselves have replies (HTTP 400 on violation).
- Expose `PUT /api/dashboards/{uuid}/comments/{id}` accepting `{message}` to edit comments (author or admin only, marks edited comments with `wasEdited: true`).
- Expose `DELETE /api/dashboards/{uuid}/comments/{id}` for soft-delete (author or admin only; deleting a top-level comment cascades to its replies).
- Parse `@username` mentions in messages, resolve to user IDs via `IUserManager`, and send Nextcloud notifications to each mentioned user; include mentions list in response.
- When comments are effectively disabled for a dashboard, `GET` returns `{enabled: false, comments: []}` and `POST` returns HTTP 403.
- Comment readers/writers must pass existing dashboard permission checks (delegate to `PermissionService`). Public-share viewers cannot post unless the share grants `permission = comment` (document but defer v2 implementation).

## Capabilities

### New Capabilities

- `dashboard-comments`: introduces REQ-CMNT-001 through REQ-CMNT-008 covering list, create, nesting limit, edit, delete, mentions, per-dashboard toggle, global toggle, and permission integration.

### Modified Capabilities

- `dashboards`: add `commentsEnabled` field to Dashboard entity (nullable SMALLINT in output).

## Impact

**Affected code:**

- `lib/Db/Dashboard.php` — add nullable `commentsEnabled` field with getter/setter
- `lib/Service/CommentService.php` (new) — CRUD and business logic for `ICommentsManager` wrapping, mention parsing, notification dispatch
- `lib/Controller/DashboardController.php` — four new endpoints (list, create, edit, delete comments) plus permission checks
- `appinfo/routes.php` — register four new routes under `/api/dashboards/{uuid}/comments`
- `lib/Migration/VersionXXXXDate2026...AddCommentsEnabledColumn.php` — add `commentsEnabled` column to `oc_mydash_dashboards`
- `src/stores/dashboards.js` — track `commentsEnabled` per dashboard in store
- `src/components/DashboardComments.vue` (new) — UI component for reading and posting comments

**Affected APIs:**

- 4 new routes: `GET|POST /api/dashboards/{uuid}/comments`, `PUT|DELETE /api/dashboards/{uuid}/comments/{id}`
- No existing routes changed

**Dependencies:**

- `OCP\Comments\ICommentsManager` — core Nextcloud API, no new composer dependency (already in framework)
- `OCP\INotificationManager` — for mention notifications (core, no new dependency)
- `OCP\IUserManager` — for mention resolution (core, no new dependency)
- No new npm dependencies

**Migration:**

- Zero-impact: the migration only adds a nullable column. Existing dashboards get `commentsEnabled = NULL` and inherit the global setting.
- No data backfill required.
