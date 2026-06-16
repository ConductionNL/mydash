# Design — dashboard-sharing-followups

## Context

The baseline `dashboard-sharing` capability is shipped: per-user/per-group share rows, three permission levels (`view_only`, `add_only`, `full`), owner-only management. This change layers operational concerns on top: discovery (notifications), efficient management (bulk replace), and lifecycle (cascade on user delete with retention). The biggest design question is the cascade — owner deletion is destructive by default, and the user requirement is that **a dashboard with at least one admin-level recipient must survive the owner being deleted**. Everything else is mostly mechanical.

## Decision: Pull notifications via Nextcloud's `INotifier`, not push or polling

Nextcloud already ships an `INotifier` ecosystem with three rendering surfaces (bell dropdown, activity stream, daily email digest) and an `INotificationManager` for publishing. By implementing `INotifier::prepare()` for two subjects (`dashboard_shared`, `dashboard_ownership_transferred`) we get all three surfaces for free, plus translation, plus deep-linking, plus deduplication of unread state.

**Alternatives considered:**

- **WebSocket / SSE push** — Nextcloud doesn't ship a usable bidirectional channel for app code. Requires Talk's HPB or a separate broker. Rejected: too much infrastructure for a low-frequency event.
- **Polling endpoint + frontend badge** — would require client-side state and a new endpoint. The bell already polls and renders unread counts; we'd be reinventing it.
- **Email-only via `IMailer`** — would skip the in-app surface entirely. Bad UX for active users.

**Trade-off:** the bell renders on the next poll cycle (default 30s), so notifications are not real-time. Acceptable for share events.

## Decision: Notify on add and on level upgrade only — never on remove or downgrade

Sharing tends to be additive ("Alice shared X with you"). Revocations and demotions are quiet operations: the recipient simply loses access. Notifying on revoke creates two failure modes:

- spam (e.g. a sharer iterating the share list multiple times to fix a typo)
- an awkward "Alice removed your access to X" message that adds no actionable information — the dashboard simply disappears from their list

Demotions (full → add_only) follow the same logic: the recipient still has access; the change is silent.

A *level upgrade* (view_only → full) is treated as a re-share for notification purposes, since it can functionally enable new behaviour (add widgets, edit layout) for the recipient.

## Decision: Group shares fan out at publish time, not at subscribe time

Two options for group share notifications:

- **(a) Fan-out at publish**: when the share is created, resolve the group's member list once and emit one notification per member.
- **(b) Subscribe at read**: keep one notification row, and at read time check group membership.

We pick (a) because Nextcloud's `INotificationManager` is publish-only; there's no "match all members of group X" subscription primitive. (a) also produces cleaner per-user state (read/unread tracked per recipient out of the box).

The trade-off is that a member who joins the group **after** the share is created will not get the notification. They will still see the dashboard in their list because group resolution at read time *is* live for the visibility query — only the notification is missed. This is acceptable; the alternative has worse failure modes.

## Decision: Bulk replace is `PUT`, not `POST` or `PATCH`

`PUT /api/dashboard/{id}/shares` with `{shares: [...]}` payload makes the intent unambiguous: "this is the entire desired share list". Any share not in the payload disappears.

- **Why not `POST`**: POST is used today for "add one share". Reusing the verb with a different semantic would break clients.
- **Why not `PATCH`**: PATCH implies partial mutation, which would require a vocabulary like `{add: [...], remove: [...]}`. Possible, but the simple "replace" semantic matches how the UI naturally behaves (user edits the list, hits save).

The trade-off is that it requires the client to send the full list — but the list is small (typically <20 shares per dashboard) so the bytes cost is trivial.

## Decision: Admin pool excludes `add_only` recipients

The retention algorithm only considers `permission_level = 'full'` shares as candidates for ownership transfer. `add_only` recipients can edit content but the user requirement explicitly says "admin (users can edit and delete the dashboard)" — that maps to `full` only.

If a dashboard has only `view_only` and `add_only` shares and the owner is deleted, **the dashboard is deleted**. This may be surprising; we surface it via the `mydash_dashboards_orphaned_at_owner_deletion_total` Prometheus counter so admins can detect cases where they want to bump shares to `full` proactively.

We considered a "preserve if at least one human can still see it" rule (i.e. retention threshold = `view_only`). Rejected: it would silently leave dashboards owned by `null`/system, which adds a third ownership state we don't want to model.

## Decision: New-owner selection is deterministic, not interactive

When the owner is deleted, the listener picks a new owner without prompting. The rule:

1. Among `user`-type `full` shares, smallest `created_at` wins.
2. If none, expand the alphabetically-first `group`-type `full` share, pick the alphabetically-first remaining member.
3. If still none (concurrency edge case), fall through to delete.

**Why deterministic:** the deletion event is synchronous — we cannot wait for human input. We could enqueue a "needs new owner" job and email all admins, but that introduces ambiguous state (a dashboard with `user_id = NULL` for an unbounded period). The simple rule is predictable and replayable.

**Why created_at ASC:** approximates "earliest-trusted recipient", a heuristic for "person Alice deliberately picked first when she set up the dashboard". Alphabetic is the tiebreaker for groups because there's no per-group ordering.

The new owner gets a `dashboard_ownership_transferred` notification ("X is now yours — ownership transferred after the previous owner was removed") so they're not surprised by the dashboard appearing as theirs.

## Decision: Synchronous cascade in the event listener

`UserDeletedEvent` is fired synchronously inside the user-removal flow. We do all work — share cleanup, dashboard retention/deletion, ownership transfer, notification publishing — synchronously inside the listener, in DB transactions per dashboard.

**Alternative considered:** publish a `mydash.user_deleted` queue message and process asynchronously. Rejected: introduces a queue dependency, complicates testing, and creates a window where dashboards owned by a deleted user are still visible/usable. Simpler to do it inline and pay the deletion-time latency cost.

**Risk:** a deletion of a heavily-sharing user (e.g. an admin who owned 100 dashboards) could take seconds. Mitigation: the listener is bounded by the number of owned dashboards, which in practice is small (median <10). If it becomes a problem we can move to a queued model later — the listener's current contract is "complete or fail loudly", not "complete fast".

## Decision: No automatic re-share when a recipient user is recreated

If user `bob` is deleted and later recreated with the same uid, **shares are not restored**. The old share rows were deleted permanently in step A of the listener.

This is the right call because (a) a recreated uid is not necessarily the same human, (b) the original sharer should re-grant intentionally, and (c) backup/restore is the proper recovery channel for accidental deletions.

## Decision: Group deletion is out of scope

`OCP\Group\Events\GroupDeletedEvent` exists, and we could mirror the user-deletion logic for groups. We are not doing it in this change because:

- Group deletion is rare and usually administrative
- A deleted group leaves shares with an unreachable `share_with` — they're inert (no recipients), not actively harmful
- The optional Migration 001006 (orphan cleanup) covers this case if an admin opts in

A future change can add a `GroupDeletedListener` if usage shows it's a real problem.

## Decision: No bulk frontend UI for revoke-all-for-recipient

The `DELETE /api/sharees/{shareType}/{shareWith}` endpoint is added but not surfaced in the UI in this change. Rationale: the operation has no clear UI affordance ("here's a recipient — what dashboards do they see from me?" requires building an inverse view that doesn't exist). Exposing it via API only lets admin tooling and `occ` scripts use it without committing to a UI shape we haven't designed.

## Edge cases

### Owner deletes themselves while still owning shared dashboards

`occ user:delete <self>` is allowed for the system administrator. Shares are evaluated against the deleted user's *current* group membership at the moment of deletion. If the deleted user shared with themselves indirectly (shouldn't be possible — `addShare` rejects self-shares), the listener handles it as if no admin were available.

### A user has both a direct share AND is in a shared group on the same dashboard

The retention algorithm considers them once: direct user shares are evaluated first. If they're also in a `full`-level group share, they only end up in the candidate pool once.

### Concurrent owner deletion + new share

Two concurrent transactions:

1. T1: admin deletes user `alice` (owner of dashboard 5)
2. T2: alice's frontend issues `POST /api/dashboard/5/shares` (added bob as `full`)

If T2 commits before T1's listener reads the share table, the listener sees bob and transfers ownership to him. If T2 commits after, T1 deletes the dashboard and bob's share row is cascaded away. Either outcome is consistent — there's no torn state.

### A `full`-level group share whose group has zero current members

The pool resolves to empty. The dashboard is deleted. This is correct behaviour: an empty group means no human is privileged on this dashboard.

### Notification spam mitigation

A user adding 50 recipients via the bulk endpoint produces 50 notifications, one per recipient. Each recipient gets one notification (fan-out is per-target, not per-share-event). Recipients can mute the `mydash` notifier in their personal settings via the existing Nextcloud notification preferences UI — no special handling needed.
