# Tasks — dashboard-comments

## 1. Schema migration

- [ ] 1.1 Create `lib/Migration/VersionXXXXDate2026...AddCommentsEnabledColumn.php` adding `commentsEnabled SMALLINT(0/1) NULL` to `oc_mydash_dashboards`
- [ ] 1.2 Confirm migration is reversible (drop column in `postSchemaChange` rollback path)
- [ ] 1.3 Run migration locally against sqlite, mysql, and postgres; verify schema applied cleanly each time

## 2. Domain model

- [ ] 2.1 Add `commentsEnabled` field to `Dashboard` entity with getter/setter (Entity `__call` pattern — no named args)
- [ ] 2.2 Update `Dashboard::jsonSerialize()` to include `commentsEnabled` (nullable in output)
- [ ] 2.3 Add Dashboard helper method `isCommentsEffectivelyEnabled(IAppConfig $appConfig): bool` that checks `commentsEnabled`, falls back to global setting

## 3. Service layer — Core comment logic

- [ ] 3.1 Create `lib/Service/CommentService.php` with dependency injection: `ICommentsManager`, `IUserManager`, `INotificationManager`, `IGroupManager`, `IAppConfig`
- [ ] 3.2 Implement `CommentService::getCommentsForDashboard(string $dashboardUuid): array` — calls `ICommentsManager::getForObject('mydash_dashboard', $dashboardUuid)` and returns ordered comment tree with top-level comments newest-first, replies grouped by parentId
- [ ] 3.3 Implement `CommentService::createComment(string $dashboardUuid, string $userId, string $message, ?int $parentId = null): IComment` — creates comment via `ICommentsManager::save()`, enforces nesting limit (rejects if parentId points to a reply), triggers mention parsing
- [ ] 3.4 Implement `CommentService::updateComment(int $commentId, string $newMessage, string $currentUserId): IComment` — verify author-or-admin, update message, set updatedAt timestamp, trigger mention re-parsing
- [ ] 3.5 Implement `CommentService::deleteComment(int $commentId, string $currentUserId): void` — verify author-or-admin, soft-delete via `ICommentsManager::delete()`, cascade-delete replies if top-level
- [ ] 3.6 Implement `CommentService::parseAndResolveMentions(string $message, string $dashboardUuid, string $authorUserId): array` returning `[{userId, displayName}, ...]` and send Nextcloud notifications to each

## 4. Mention parsing implementation

- [ ] 4.1 Extract `@username` patterns via regex `/@([a-zA-Z0-9_.-]+)/` (case-insensitive, case-normalized to lowercase for lookup)
- [ ] 4.2 Call `IUserManager::get($username)` for each unique match (deduplicate)
- [ ] 4.3 For each resolved user, create Nextcloud notification via `INotificationManager::createNotification()` with:
  - Event: 'mentioned_in_comment' (custom event app must register in `appinfo/info.xml`)
  - Subject i18n key: `cmnt_mentioned_in_comment`
  - Link to dashboard (route to `/index.php/apps/mydash/...#dashboard/{uuid}`)
  - Include author display name and dashboard name in notification
- [ ] 4.4 Return structured mention list; silently skip unresolved mentions (no error)
- [ ] 4.5 Add i18n entries in `l10n/en.json` and `l10n/nl.json` for notification subject and body

## 5. Controller + routes

- [ ] 5.1 Add `DashboardController::getComments(string $uuid)` mapped to `GET /api/dashboards/{uuid}/comments` (logged-in or public share, `#[NoAdminRequired]`)
- [ ] 5.2 Add `DashboardController::createComment(string $uuid)` mapped to `POST /api/dashboards/{uuid}/comments` (logged-in or public share, `#[NoAdminRequired]`)
- [ ] 5.3 Add `DashboardController::updateComment(string $uuid, int $id)` mapped to `PUT /api/dashboards/{uuid}/comments/{id}` (logged-in, `#[NoAdminRequired]`, author-or-admin check in body)
- [ ] 5.4 Add `DashboardController::deleteComment(string $uuid, int $id)` mapped to `DELETE /api/dashboards/{uuid}/comments/{id}` (logged-in, `#[NoAdminRequired]`, author-or-admin check in body)
- [ ] 5.5 In each controller method:
  - Load dashboard by uuid and verify comment-read/write permission via `PermissionService::getEffectivePermissionLevel()`
  - Check `Dashboard::isCommentsEffectivelyEnabled($appConfig)` and return 403 if disabled (except GET returns `{enabled: false, comments: []}`)
  - Handle public shares: extract userId from `IShareManager` share token (if present) and delegate permission check to share's permission level
- [ ] 5.6 Register all four routes in `appinfo/routes.php` with proper regex requirements (uuid = UUID v4 pattern, id = integer)
- [ ] 5.7 Return comment list as `{enabled: true, comments: [{id, author, message, createdAt, updatedAt, wasEdited, parentId, mentions}]}` in get responses
- [ ] 5.8 Validate request bodies: message non-empty, parentId must be integer if provided

## 6. Global setting integration

- [ ] 6.1 Register `mydash.comments_enabled_default` boolean admin setting in `appinfo/info.xml` (default: true)
- [ ] 6.2 Add admin UI setting in Settings page (deferred to follow-up if needed; this change wires backend only)
- [ ] 6.3 Provide `CommentService::isCommentsEnabledGlobally(): bool` method that reads the setting

## 7. Frontend store updates

- [ ] 7.1 Extend `src/stores/dashboards.js` to track `commentsEnabled` per dashboard (from API responses)
- [ ] 7.2 Add `getDashboard(uuid)` getter that includes `commentsEnabled` field
- [ ] 7.3 Ensure existing store mutations propagate `commentsEnabled` when dashboards are fetched or updated

## 8. Frontend UI component (new)

- [ ] 8.1 Create `src/components/DashboardComments.vue` Vue component for reading and posting comments
- [ ] 8.2 Component props: `dashboardUuid`, `currentPermissionLevel`, `commentsEnabled`
- [ ] 8.3 Display:
  - "Comments disabled" message if `commentsEnabled = false`
  - Comment list grouped with replies under parents (nested visual hierarchy or indentation)
  - Per-comment author name, timestamp, message (with parsed mention highlight/links)
  - Edit/Delete buttons visible only to author/admin (conditional v-if)
- [ ] 8.4 Post new comment:
  - Text input field with @mention autocomplete (lookup users via API or local user list)
  - "Reply" button on each comment to set parentId (inline reply mode)
  - Submit button creates comment via POST /api/dashboards/{uuid}/comments
- [ ] 8.5 Edit comment:
  - Inline edit form on edit button click
  - PUT /api/dashboards/{uuid}/comments/{id} on save
  - Display "Edited" label and timestamp after save
- [ ] 8.6 Delete comment:
  - Confirm dialog before DELETE
  - Remove comment from list on 204 response
  - Cascade-delete visual indication (if top-level, note that replies will also be deleted)
- [ ] 8.7 i18n keys for all UI labels, buttons, error messages in `l10n/en.json` and `l10n/nl.json`
- [ ] 8.8 WCAG AA compliance: keyboard navigation, screen reader labels, focus management

## 9. PHPUnit tests

- [ ] 9.1 `CommentServiceTest::testGetCommentsForDashboard` — fetch comments, verify tree structure, top-level order
- [ ] 9.2 `CommentServiceTest::testCreateComment` — basic create, replies, parentId validation
- [ ] 9.3 `CommentServiceTest::testNestingLimitEnforcement` — reject reply-to-reply with HTTP 400
- [ ] 9.4 `CommentServiceTest::testUpdateComment` — author can edit, non-author cannot, admin can, wasEdited flag set
- [ ] 9.5 `CommentServiceTest::testDeleteComment` — author can delete, cascade deletes replies, non-author cannot
- [ ] 9.6 `CommentServiceTest::testParseAndResolveMentions` — extract usernames, resolve to user IDs, deduplicate, skip nonexistent, send notifications
- [ ] 9.7 `CommentServiceTest::testMentionCaseInsensitivity` — @Alice and @alice resolve to same user
- [ ] 9.8 `CommentControllerTest::testGetCommentsRequiresViewPermission` — HTTP 403 for view_only... (wait, view_only users can read comments; only write is restricted)
- [ ] 9.9 `CommentControllerTest::testPostCommentRequiresWritePermission` — HTTP 403 for view_only users posting
- [ ] 9.10 `CommentControllerTest::testDisabledCommentsReturn403OnPost` — commentsEnabled = 0 rejects POST with 403
- [ ] 9.11 `CommentControllerTest::testDisabledCommentsReturnEmptyOnGet` — commentsEnabled = 0 returns {enabled: false, comments: []}
- [ ] 9.12 `CommentControllerTest::testIsCommentsEffectivelyEnabled` — NULL inherits global, 1 forces on, 0 forces off
- [ ] 9.13 `DashboardMapperTest` regression — all existing dashboard tests pass (commentsEnabled field doesn't break existing flow)

## 10. End-to-end Playwright tests

- [ ] 10.1 User posts a top-level comment and sees it immediately in the list (newest-first)
- [ ] 10.2 User replies to a comment and the reply appears grouped beneath the parent
- [ ] 10.3 User mentions @someone and that user receives a Nextcloud notification with a link to the dashboard
- [ ] 10.4 User edits their comment; "Edited" label appears; timestamp updates
- [ ] 10.5 Admin user edits another user's comment (non-author edit)
- [ ] 10.6 User deletes their comment; it disappears from list; replies (if top-level) also vanish
- [ ] 10.7 Attempt to reply to a reply returns HTTP 400 (nesting limit)
- [ ] 10.8 Disabled dashboard shows "Comments disabled" message; POST returns 403
- [ ] 10.9 View-only user can read comments but "Post" button is disabled (or returns 403 on attempt)
- [ ] 10.10 Admin toggles per-dashboard `commentsEnabled` setting and comments enable/disable accordingly

## 11. Quality gates

- [ ] 11.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered along the way
- [ ] 11.2 ESLint + Stylelint clean on all Vue/JS files
- [ ] 11.3 SPDX headers on every new PHP file (inside the docblock per the SPDX-in-docblock convention)
- [ ] 11.4 i18n keys defined in `l10n/en.json` and `l10n/nl.json` for:
  - Notification subject: `cmnt_mentioned_in_comment`
  - UI labels: `cmnt_comments`, `cmnt_post_comment`, `cmnt_edit`, `cmnt_delete`, `cmnt_reply`, `cmnt_edited`, `cmnt_disabled_message`
  - Error messages: `cmnt_empty_message`, `cmnt_nested_reply_error`, `cmnt_permission_denied`, `cmnt_disabled_error`
- [ ] 11.5 Register custom notification event in `appinfo/info.xml`: activity/notificationTypes section
- [ ] 11.6 Update generated OpenAPI spec so API consumers see the four new endpoints
- [ ] 11.7 Run all 10 `hydra-gates` locally before opening PR
