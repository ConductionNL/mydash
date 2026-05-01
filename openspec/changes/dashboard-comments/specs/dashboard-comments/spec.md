---
capability: dashboard-comments
delta: true
status: draft
---

# Dashboard Comments — Delta from change `dashboard-comments`

## ADDED Requirements

### Requirement: REQ-CMNT-001 List Comments on Dashboard

Users with view permissions MUST be able to retrieve all comments and replies on a dashboard in a single request, ordered with newest top-level comments first and replies grouped beneath their parent.

#### Scenario: List comments on a dashboard with mixed comments and replies
- GIVEN dashboard UUID "dash-001" has 2 top-level comments and 1 reply under the first comment
- WHEN user "alice" sends `GET /api/dashboards/dash-001/comments`
- THEN the system MUST return HTTP 200 with an array containing comment objects
- AND each comment object MUST include: id, author, message, createdAt, updatedAt, wasEdited, parentId, mentions
- AND top-level comments (parentId = null) MUST be ordered newest-first
- AND the response MUST include `enabled: true` if comments are effectively enabled for this dashboard

#### Scenario: List comments when comments are disabled
- GIVEN dashboard UUID "dash-002" has `commentsEnabled = 0` (forced-off)
- WHEN user "bob" sends `GET /api/dashboards/dash-002/comments`
- THEN the system MUST return HTTP 200 with `{enabled: false, comments: []}`

#### Scenario: List comments without view permission
- GIVEN user "dave" has no permission to view dashboard "dash-005"
- WHEN "dave" sends `GET /api/dashboards/dash-005/comments`
- THEN the system MUST return HTTP 403 Forbidden

### Requirement: REQ-CMNT-002 Create Top-Level Comment

Users with write permissions MUST be able to post new top-level comments on a dashboard.

#### Scenario: Create a new top-level comment
- GIVEN user "alice" has write access to dashboard "dash-001"
- WHEN she sends `POST /api/dashboards/dash-001/comments` with body `{"message": "Need to verify this metric"}`
- THEN the system MUST create a comment via `ICommentsManager::save()` with objectType `'mydash_dashboard'` and objectId = dashboard UUID
- AND return HTTP 201 with the full comment object including generated comment id
- AND the comment MUST appear in subsequent `GET /api/dashboards/dash-001/comments` requests

#### Scenario: Create comment with mentions
- GIVEN user "bob" sends `POST /api/dashboards/dash-001/comments` with body `{"message": "@alice please check this"}`
- THEN the system MUST resolve @alice to user ID via `IUserManager`, send alice a Nextcloud notification, and include `mentions: [{userId: "alice", displayName: "Alice User"}]` in the response

#### Scenario: Create comment when comments disabled
- GIVEN dashboard "dash-002" has `commentsEnabled = 0`
- WHEN user "charlie" sends `POST /api/dashboards/dash-002/comments`
- THEN the system MUST return HTTP 403 Forbidden with error "Comments are disabled on this dashboard"

#### Scenario: Create comment without write permission
- GIVEN user "dave" has view-only access to dashboard "dash-005"
- WHEN "dave" sends `POST /api/dashboards/dash-005/comments`
- THEN the system MUST return HTTP 403 Forbidden

#### Scenario: Create comment with empty message
- GIVEN user "eve" sends `POST /api/dashboards/dash-001/comments` with body `{"message": ""}`
- THEN the system MUST return HTTP 400 Bad Request with validation error

### Requirement: REQ-CMNT-003 One-Level-Deep Nesting Enforcement

Replies may only be attached to top-level comments. Replies to replies MUST be rejected with HTTP 400.

#### Scenario: Create a valid reply to a top-level comment
- GIVEN dashboard "dash-001" has top-level comment with id 100
- WHEN user "frank" sends `POST /api/dashboards/dash-001/comments` with body `{"message": "Agreed", "parentId": 100}`
- THEN the system MUST verify comment 100 is top-level (parentId = 0 in storage) and create a reply
- AND return HTTP 201 with the reply object including `parentId: 100`

#### Scenario: Reject nested reply (reply to a reply)
- GIVEN dashboard "dash-001" has top-level comment id 100 and reply id 101 (parentId = 100)
- WHEN user "grace" sends `POST /api/dashboards/dash-001/comments` with body `{"message": "No way", "parentId": 101}`
- THEN the system MUST verify comment 101 has parentId = 100 (not 0) and return HTTP 400 Bad Request
- AND error message MUST indicate "Comments can only be replied to once"

#### Scenario: Non-existent parent returns 404
- GIVEN user "henry" sends `POST /api/dashboards/dash-001/comments` with body `{"message": "Reply", "parentId": 999}`
- THEN the system MUST return HTTP 404 Not Found with error "Parent comment not found"

### Requirement: REQ-CMNT-004 Edit Comment

Only the comment author or a Nextcloud admin user MUST be able to edit a comment. Non-authors MUST receive HTTP 403. Edited comments MUST be marked with `wasEdited: true`.

#### Scenario: Author edits their own comment
- GIVEN comment id 100 authored by "jack" on dashboard "dash-001"
- WHEN "jack" sends `PUT /api/dashboards/dash-001/comments/100` with body `{"message": "Corrected text"}`
- THEN the system MUST update the comment via `ICommentsManager`, set `wasEdited = true` and `updatedAt` to current timestamp
- AND return HTTP 200 with the updated comment object

#### Scenario: Non-author cannot edit
- GIVEN comment id 100 authored by "kate"
- WHEN "liam" (non-author, non-admin) sends `PUT /api/dashboards/dash-001/comments/100`
- THEN the system MUST return HTTP 403 Forbidden with error "Only the author or an admin may edit this comment"

#### Scenario: Admin can edit any comment
- GIVEN comment id 100 authored by "mike" and "nora" is a Nextcloud admin (verified via `IGroupManager::isAdmin()`)
- WHEN "nora" sends `PUT /api/dashboards/dash-001/comments/100` with new message
- THEN the system MUST allow the edit and return HTTP 200

### Requirement: REQ-CMNT-005 Delete Comment

Only the comment author or a Nextcloud admin user MUST be able to delete a comment. Non-authors MUST receive HTTP 403. Deleting a top-level comment MUST cascade to delete all replies.

#### Scenario: Author deletes their own comment
- GIVEN top-level comment id 100 authored by "quinn" on dashboard "dash-001"
- WHEN "quinn" sends `DELETE /api/dashboards/dash-001/comments/100`
- THEN the system MUST soft-delete via `ICommentsManager::delete()` and return HTTP 204 No Content
- AND the comment MUST no longer appear in subsequent `GET /api/dashboards/dash-001/comments` requests

#### Scenario: Delete top-level comment cascades to replies
- GIVEN top-level comment id 100 with replies id 101 and 102
- WHEN comment 100's author sends `DELETE /api/dashboards/dash-001/comments/100`
- THEN the system MUST delete comment 100 AND its children via separate `ICommentsManager::delete()` calls
- AND all three MUST be removed from subsequent list requests

#### Scenario: Delete a reply only
- GIVEN reply comment id 101 with parentId = 100
- WHEN comment 101's author sends `DELETE /api/dashboards/dash-001/comments/101`
- THEN the system MUST delete only comment 101 (parent and siblings remain)

#### Scenario: Non-author cannot delete
- GIVEN comment id 100 authored by "roger"
- WHEN "susan" (non-author, non-admin) sends `DELETE /api/dashboards/dash-001/comments/100`
- THEN the system MUST return HTTP 403 Forbidden

### Requirement: REQ-CMNT-006 Mention Parsing and Notifications

When a comment message contains `@username` patterns, the system MUST resolve the mention, extract mentions list, and send Nextcloud notifications to mentioned users.

#### Scenario: Single mention in comment message
- GIVEN user "victor" sends `POST /api/dashboards/dash-001/comments` with message "@alice please review"
- THEN the system MUST parse the message for `@username` patterns, resolve "alice" via `IUserManager::get('alice')`
- AND send "alice" a Nextcloud notification with link to the dashboard
- AND return the comment with `mentions: [{userId: "alice", displayName: "Alice User"}]`

#### Scenario: Multiple mentions deduplicated
- GIVEN message "@alice @bob should see this"
- THEN the system MUST extract both usernames, resolve both, send both notifications
- AND return `mentions` array with one entry per unique user

#### Scenario: Non-existent username mention silently skipped
- GIVEN message "@nonexistent_user please help"
- THEN the system MUST attempt resolution via `IUserManager::get()`, which returns null
- AND the mention MUST NOT appear in the `mentions` array
- AND no notification is sent for that mention
- AND the comment is created normally with raw text intact

#### Scenario: Mention in edited comment updates notifications
- GIVEN comment originally with no mentions, edited to add "@xavier this is important"
- THEN the system MUST parse the NEW message for mentions, send notification to xavier
- AND update the `mentions` array in the response

### Requirement: REQ-CMNT-007 Per-Dashboard Comment Toggle

Each dashboard MUST support a nullable `commentsEnabled` field to override the global default setting. When NULL, the dashboard MUST inherit the global `mydash.comments_enabled_default` setting.

#### Scenario: Dashboard inherits global setting when commentsEnabled IS NULL
- GIVEN dashboard "dash-001" has `commentsEnabled = NULL`
- AND global setting `mydash.comments_enabled_default` = true
- WHEN user "yolanda" sends `GET /api/dashboards/dash-001/comments`
- THEN the system MUST return `{enabled: true, comments: [...]}`

#### Scenario: Dashboard forces comments on
- GIVEN dashboard "dash-002" has `commentsEnabled = 1`
- AND global setting `mydash.comments_enabled_default` = false
- WHEN user "zack" sends `GET /api/dashboards/dash-002/comments`
- THEN the system MUST return `{enabled: true, comments: [...]}`

#### Scenario: Dashboard forces comments off
- GIVEN dashboard "dash-003" has `commentsEnabled = 0`
- AND global setting `mydash.comments_enabled_default` = true
- WHEN user "alice" sends `GET /api/dashboards/dash-003/comments`
- THEN the system MUST return `{enabled: false, comments: []}`

#### Scenario: Disabled dashboard rejects POST
- GIVEN dashboard "dash-003" with `commentsEnabled = 0`
- WHEN user "bob" sends `POST /api/dashboards/dash-003/comments`
- THEN the system MUST return HTTP 403 Forbidden

### Requirement: REQ-CMNT-008 Global Comments Setting

Nextcloud admins MUST be able to toggle comment functionality globally via admin setting `mydash.comments_enabled_default`.

#### Scenario: Admin toggles global setting to OFF
- GIVEN admin setting `mydash.comments_enabled_default` = false
- AND dashboard "dash-001" has `commentsEnabled = NULL`
- THEN `GET /api/dashboards/dash-001/comments` MUST return `enabled: false`
- AND `POST /api/dashboards/dash-001/comments` MUST return HTTP 403

#### Scenario: Per-dashboard override persists across global toggle
- GIVEN dashboard "dash-001" has `commentsEnabled = 1` (force-on) and global setting = false
- THEN dashboard "dash-001" MUST remain enabled (per-dashboard setting takes precedence)

#### Scenario: Default value on fresh install
- GIVEN a fresh MyDash installation with no admin config
- THEN `mydash.comments_enabled_default` MUST default to true

### Requirement: REQ-CMNT-009 Permission Integration

Comment readers and writers MUST be subject to the same dashboard permission checks as widget views and edits.

#### Scenario: User with view-only permission can read but not post
- GIVEN user "dave" has `permissionLevel = 'view_only'` on dashboard "dash-005"
- WHEN "dave" sends `GET /api/dashboards/dash-005/comments`
- THEN the system MUST return HTTP 200 with comments list
- WHEN "dave" sends `POST /api/dashboards/dash-005/comments`
- THEN the system MUST return HTTP 403 Forbidden

#### Scenario: User with full permission can read and post
- GIVEN user "frank" has `permissionLevel = 'full'` on dashboard "dash-007"
- WHEN "frank" sends `GET`, `POST`, `PUT`, `DELETE` on that dashboard's comments
- THEN all requests MUST succeed with appropriate HTTP codes (200, 201, 204)

#### Scenario: Anonymous user without view permission gets 403
- GIVEN dashboard "dash-008" is not publicly shared
- WHEN an anonymous user requests `GET /api/dashboards/dash-008/comments`
- THEN the system MUST return HTTP 403 Forbidden
