# Specifications — Group priority order

## Overview

This change defines the API and behavior for managing an ordered list of Nextcloud groups that serves as the priority/primary-group resolver for multi-group users in MyDash. It includes two new admin endpoints and a drag-and-drop UI component.

---

## Functional Requirements

### REQ-GOP-001: Fetch active and inactive groups

**Requirement:** Admins must be able to fetch the current group order and the full list of known groups.

**API:** `GET /api/admin/groups`

**GIVEN** an authenticated admin user,
**WHEN** they call `GET /api/admin/groups`,
**THEN** the response includes three arrays:
- `active`: groups in the current `group_order`, in priority order
- `inactive`: groups known to Nextcloud but not in `group_order`, plus stale group IDs (deleted groups) still in `group_order`
- `allKnown`: union of active + inactive

**HTTP Status:** 200 (success) | 403 (non-admin)

### REQ-GOP-002: Persist group order

**Requirement:** Admins must be able to set the priority order of groups, replacing the previous setting entirely.

**API:** `POST /api/admin/groups` with body `{ groups: string[] }`

**GIVEN** an authenticated admin user,
**WHEN** they POST a JSON array of group IDs,
**THEN** the list is validated (all strings, no duplicates, unknown IDs tolerated) and persisted to storage.

**GIVEN** a request with invalid JSON (non-string elements, duplicates),
**WHEN** validation fails,
**THEN** the request returns HTTP 400 with a static error message, no stack trace.

**GIVEN** a non-admin user,
**WHEN** they attempt POST to `/api/admin/groups`,
**THEN** the request returns HTTP 403 (`Not authorized`).

**HTTP Status:** 200 (success, returns updated state) | 400 (invalid input) | 403 (non-admin)

### REQ-GOP-003: Stale group handling

**Requirement:** Group IDs that no longer exist in Nextcloud are preserved in the setting and displayed to admins so they can be restored if the group is recreated.

**GIVEN** a group ID in `group_order` that does not exist in Nextcloud,
**WHEN** the admin calls `GET /api/admin/groups`,
**THEN** the stale ID appears in both `active` and `allKnown` (frontend labels it "(deleted)" or "(removed)").

**GIVEN** a stale group ID is still in the persisted `group_order`,
**WHEN** the admin POSTs a new group list that does NOT include the stale ID,
**THEN** it is removed from storage.

### REQ-GOP-004: Default and empty state

**Requirement:** When no group order has been configured, the system defaults to an empty list.

**GIVEN** a fresh MyDash installation with no `group_order` setting,
**WHEN** `AdminSettingsService::getGroupOrder()` is called,
**THEN** it returns an empty array `[]`.

**GIVEN** an empty `group_order`,
**WHEN** `GET /api/admin/groups` is called,
**THEN** `active` is `[]`, `inactive` contains all known Nextcloud groups, `allKnown` equals `inactive`.

### REQ-GOP-005: Auto-save debounce

**Requirement:** The frontend UI auto-saves on every drag with a debounce to avoid excessive API calls.

**GIVEN** an admin drags a group within the active list to reorder priorities,
**WHEN** the drag completes,
**THEN** the UI collects the new order and queues a POST after 300ms.

**GIVEN** multiple drags occur within 300ms,
**WHEN** the debounce timer fires,
**THEN** only ONE POST is made with the final order (no intermediate saves).

### REQ-GOP-006: Downstream consumption

**Requirement:** The `group_order` setting is consumed by downstream features (group-routing, role-based-content) via `AdminSettingsService::getGroupOrder()`.

**GIVEN** downstream code calls `AdminSettingsService::getGroupOrder()`,
**WHEN** JSON is corrupt or missing,
**THEN** the method returns `[]` without throwing, logged as a warning.

**GIVEN** a multi-group user and a non-empty `group_order`,
**WHEN** downstream code determines the user's primary group,
**THEN** it selects the first group in `group_order` that the user belongs to.

### REQ-GOP-007: Admin authorization

**Requirement:** Both endpoints enforce admin-only access at the middleware level.

**GIVEN** a non-admin authenticated user,
**WHEN** they call `GET /api/admin/groups` or `POST /api/admin/groups`,
**THEN** the request returns HTTP 403 (Forbidden) and does not execute the controller body.

**GIVEN** an unauthenticated user,
**WHEN** they call either endpoint,
**THEN** the request returns HTTP 401 (Unauthorized).

### REQ-GOP-008: Input validation

**Requirement:** The `POST` endpoint validates all input before persisting.

**GIVEN** the request body is `{ groups: ["staff", "staff"] }` (duplicate),
**WHEN** validation runs,
**THEN** return HTTP 400 with message `"Invalid group list"` (static, no details).

**GIVEN** the request body is `{ groups: [123, "staff"] }` (non-string element),
**WHEN** validation runs,
**THEN** return HTTP 400 with message `"Invalid group list"`.

**GIVEN** the request body is missing the `groups` key,
**WHEN** validation runs,
**THEN** return HTTP 400 with message `"Invalid group list"`.

---

## Non-Functional Requirements

### REQ-GOP-009: API response consistency

Both endpoints follow ADR-002 (API standards):
- Response body is always JSON with a `message` field on error.
- Status codes are semantically correct (200, 400, 403).
- No stack traces or internal paths in error responses.

### REQ-GOP-010: Logging

Admin actions (fetch, update group order) are logged for audit purposes:
- Log level: INFO for success, WARNING for failures.
- Include: admin user ID (via `$user->getUID()`), action (get/set), and timestamp.

### REQ-GOP-011: Internationalization (i18n)

All user-facing strings (UI labels, error messages) use `t(appName, 'key')` in Vue and `$this->l10n->t('key')` in PHP.
- English keys in `l10n/en.json` (primary language).
- Dutch translations in `l10n/nl.json` (must achieve 100% key parity).
- No hardcoded Dutch or English strings in code.

---

## User Journey

**User:** Communications officer or IT admin

**Journey:**
1. Admin opens MyDash admin settings.
2. Navigates to "Group order" or "Group priority" section.
3. Sees two lists: "Active groups" (in priority order) and "Inactive groups" (available but not active).
4. Drags "board-members" from inactive to active, placing it at the top.
5. Drags "staff" from inactive to active, placing it second.
6. UI auto-saves after each drag.
7. Confirms the order is persisted by refreshing the page.
8. Downstream features (role-based-content, group-routing) now use this priority order to resolve primary groups for multi-group users.

---

## Acceptance Criteria

- [x] Both endpoints return correct responses (200/400/403).
- [x] Admin-only authorization is enforced via `#[AuthorizedAdminSetting]`.
- [x] Input validation rejects duplicates, non-strings, and missing required fields.
- [x] Stale group IDs are preserved and displayed in the UI.
- [x] Auto-save with 300ms debounce works on the frontend.
- [x] JSON storage is read defensively (corrupt JSON returns `[]`).
- [x] All user-facing strings are translated (en + nl).
- [x] Logging includes user ID, action, and timestamp (no PII beyond the required user ID).
