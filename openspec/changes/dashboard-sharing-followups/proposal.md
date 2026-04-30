# Dashboard sharing follow-ups: notifications, bulk management, cascade

## Why

The baseline `dashboard-sharing` capability (REQ-SHARE-001..007) ships per-user/per-group dashboard sharing with view, add, and full access levels. In real use, three operational gaps stand out:

1. **No discovery signal.** A recipient only learns they have access to a new shared dashboard if they happen to reopen the dashboard menu and notice the new entry. There is no inbox, no badge, no email — for high-value collaboration scenarios (e.g. an admin shares a compliance dashboard with the legal team) the sharing event must produce a visible signal so the recipient acts on it.
2. **Share management is one-row-at-a-time.** Adding ten users to a dashboard means ten POST round-trips with no atomicity guarantee, and there is no API surface for "replace all shares" or "remove a recipient from every dashboard". This is a problem both for power users (a department lead onboarding a class of users) and for cleanup scenarios.
3. **User deletion leaves dangling state and can destroy collaboration unintentionally.** When a Nextcloud user is removed (`occ user:delete`, federated-user revocation, retention policy), today their owned dashboards are deleted; any shares they granted become orphaned share rows; any shares granted **to** them remain in the table forever. Worse, deleting an owner can wipe out a dashboard that the rest of the organisation still actively uses, because the share rows never promoted any recipient to ownership.

This change adds a Nextcloud-native pull notification on share creation, a bulk share-management API, and a deletion cascade that **preserves dashboards still owned by an admin-level recipient** by transferring ownership rather than deleting.

## What Changes

### Pull notifications when shared
- On every successful `addShare` (insert OR update where `permission_level` increased), publish a Nextcloud notification via `OCP\Notification\IManager` to the recipient(s):
  - `app: 'mydash'`, `subject: 'dashboard_shared'`, `objectType: 'dashboard'`, `objectId: <dashboardId>`
  - For group shares, fan out one notification per current group member (resolved at publish time via `IGroupManager`).
  - The notification payload carries `{sharerUserId, dashboardName, permissionLevel}` so the notifier can render "Alice shared **Marketing Overview** with you (full access)" with a deep link to `/apps/mydash/?dashboard={uuid}`.
- Implement `INotifier` so the notification renders in the bell, the activity stream, and the email digest using existing Nextcloud channels — no new transport.
- Provide a single "Dismiss" action that marks the notification read; no in-notification accept/reject (Pattern A: shared dashboards appear automatically in the recipient's list).
- When a share is **removed** OR **downgraded**, no notification is published (avoid notification spam for routine permission changes).

### Bulk share management
- Add `PUT /api/dashboard/{id}/shares` accepting `{shares: Share[]}` — replaces the entire share list for the dashboard atomically. Existing shares not in the payload are deleted; new entries are inserted; matching entries are upserted. One DB transaction, one notification batch (one notification per newly-added or upgraded recipient, not for unchanged or removed ones).
- Add `DELETE /api/sharees/{shareType}/{shareWith}` — owner-restricted to the *caller's* dashboards: removes every share where this caller is the owner AND the share targets the named recipient. Used to "stop sharing anything with Bob".
- Frontend `DashboardConfigModal` gains "Save shares" / "Cancel" buttons that buffer changes locally and submit a single `PUT` on save, instead of the current per-row immediate write.

### Cascade on user deletion (with admin-retention guard)
- Listen to Nextcloud's `OCP\User\Events\UserDeletedEvent`. When user `X` is deleted:
  1. **Remove all shares granted *to* X** (`share_type = 'user' AND share_with = X`). Group shares are unaffected — group membership is managed by Nextcloud's user removal hook elsewhere.
  2. **For every dashboard owned by X**, evaluate the *admin pool*:
     - The admin pool of dashboard `D` consists of every share row on `D` whose `permission_level = 'full'`, expanded as: each `user`-type share contributes one user; each `group`-type share contributes the current member list (resolved via `IGroupManager`) at deletion time.
     - **If the admin pool is non-empty**, the dashboard MUST be retained: ownership is transferred to one admin from the pool (selection rule: prefer explicit `user`-type shares, ordered by `created_at ASC`; fall back to the alphabetically-first member of the alphabetically-first group-share). The matching share row for the new owner is deleted (they own it now). All other shares are retained as-is. The new owner receives a `dashboard_ownership_transferred` notification.
     - **If the admin pool is empty**, the dashboard and all its placements/shares are deleted (existing behaviour).
- This logic runs synchronously in the event listener inside a single DB transaction.
- Group deletion is **not** handled by this change (no `GroupDeletedEvent` listener) — group shares pointing at a deleted group simply become unreachable shares; a follow-up periodic cleanup job is out of scope here.

## Capabilities

### New Capabilities

(none — all changes fold into the existing `dashboard-sharing` capability)

### Modified Capabilities

- `dashboard-sharing`: adds REQ-SHARE-008 (notify on share), REQ-SHARE-009 (bulk replace), REQ-SHARE-010 (revoke-all-for-recipient), REQ-SHARE-011 (notification rendering), REQ-SHARE-012 (cascade on user delete with admin retention), REQ-SHARE-013 (ownership-transfer notification). Existing REQ-SHARE-001..007 are untouched.

## Impact

**Affected code:**

- `lib/Notification/Notifier.php` — new `INotifier` implementation parsing `mydash`/`dashboard_shared` and `mydash`/`dashboard_ownership_transferred` subjects; deep-link target via `IURLGenerator`
- `lib/AppInfo/Application.php` — register `Notifier` via `IRegistrationContext::registerNotifierService()`; register `UserDeletedListener` for `UserDeletedEvent`
- `lib/Listener/UserDeletedListener.php` — new event listener implementing the retention algorithm above; depends on `DashboardMapper`, `DashboardShareMapper`, `WidgetPlacementMapper`, `IGroupManager`, `IManager` (for ownership-transfer notifications)
- `lib/Service/DashboardShareService.php` — split internal `addShare` into `_persist + _notify` so the bulk path can do one transaction + one notification batch; add `replaceShares(int $dashboardId, array $shares, string $userId): array`, `revokeAllForRecipient(string $shareType, string $shareWith, string $callerId): int`, `transferOwnership(int $dashboardId, string $newUserId): void`
- `lib/Controller/DashboardShareApiController.php` — add `replace(int $id, array $shares)` and `revokeForRecipient(string $shareType, string $shareWith)` actions
- `appinfo/routes.php` — register `PUT /api/dashboard/{id}/shares` and `DELETE /api/sharees/{shareType}/{shareWith}`
- `src/services/api.js` — add `replaceShares(id, shares)` and `revokeAllForRecipient(shareType, shareWith)`
- `src/components/DashboardConfigModal.vue` — buffer share edits in local state until save; "Save" calls `replaceShares` once

**Affected APIs:**

- 2 new routes (PUT bulk replace, DELETE revoke-all)
- 0 existing routes change semantics; the per-row `POST /api/dashboard/{id}/shares` and `DELETE /api/dashboard/share/{shareId}` continue to exist and continue to publish notifications, so legacy clients keep working

**Dependencies:**

- `OCP\Notification\IManager` and `INotifier` — already available in Nextcloud core, no new composer dep
- `OCP\User\Events\UserDeletedEvent` — already available
- No new npm dependencies

**Migration:**

- No schema changes. The existing `oc_mydash_dashboard_shares` table is sufficient.
- One-time data hygiene: a one-shot repair step (`Migration/Version001006Date20260430130000.php`) MAY scan for shares whose `share_with` userId no longer exists in `oc_users` and remove them. This is OPTIONAL and gated behind an admin opt-in to avoid surprise data deletion on environments with federated/external users.
