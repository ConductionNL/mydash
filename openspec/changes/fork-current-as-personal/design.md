# Design — Fork Current Layout to Personal Dashboard

## Context

MyDash allows admins to create group-shared dashboards (visible to a specific group of users) and system-default dashboards (visible to all users). When multiple users view the same dashboard, any customizations they make (widget add/remove/move/resize/config changes) are currently reflected in a single shared state — all users see each other's edits in real-time. This creates two problems:

1. **Unpredictable UX**: When user A drags a widget while user B is viewing, widget B's layout changes without their action. They don't know if they or a colleague caused the change.
2. **No personal preference persistence**: A user who customizes the layout for their workflow saves it back to the shared state, but the next day when their colleague logs in and customizes differently, the layout changes globally.

Today, admins can create multiple dashboards, but there is no one-click mechanism for a user to fork a visible dashboard (one they have read access to) into their own personal copy that they alone own and can customize.

The existing `multi-scope-dashboards` change introduced dashboard types (user/group/default). This change adds the fork capability: a user sees a shared dashboard, clicks "fork," and instantly gets a personal copy with the same widget layout that they can modify independently.

## Goals / Non-Goals

**Goals:**

- Allow authenticated users to fork any dashboard they can read (user/group/default) into a personal copy they alone own.
- Make the fork operation deterministic and atomic — either the entire fork succeeds or fails, with no partial state left behind.
- Preserve widget placement state (position, size, styling, tile metadata) so the fork is a true visual and functional clone.
- Enforce admin control via a feature gate (`allow_user_dashboards` setting) so deployments can disable personal dashboards entirely if needed.
- Make the fork operation one-click: user initiates it, system handles UUID generation, default naming, and state transitions.

**Non-Goals:**

- Automatic per-user layout isolation on first login (that is a separate feature). This change is about explicit user-initiated fork action.
- Merge/sync capability (forked copy is independent; edits to original do not cascade to fork).
- Revision/rollback history of a fork (history is per-dashboard via audit trails; a fork is a point-in-time snapshot).
- Permission inheritance (fork defaults to personal only; user must explicitly share/group-assign the fork separately).
- Resource duplication (uploaded images, widget content files are shared by reference; see REQ-DASH-022).

## Decisions

### D1: Fork is explicit user action, not automatic background task

**Decision**: Fork is triggered by a user clicking a "fork" action in the UI, not automatically on first login or first edit.

**Alternatives considered:**

- Automatic fork on first edit of a shared dashboard (invisible to user). Rejected — the user might not want a fork; they might just be viewing. Explicit is more discoverable and less surprising.
- Automatic fork on first login if user hasn't logged in before. Rejected — adds server-side complexity (track login state per user per app) and users who never customize a shared layout would accumulate unused forked copies.
- Lazy fork on first GET if no personal version exists. Rejected — equivalent to explicit but hidden from user and auditing; explicit action is clearer.

**Rationale**: Explicit action gives users control and admins a clear audit trail. One-click via a UI button is discoverable and non-disruptive.

### D2: Fork source is ANY readable dashboard, not just group-shared

**Decision**: A user can fork any dashboard they have read access to (user/group/default type), including their own personal dashboards (creating an independent duplicate).

**Alternatives considered:**

- Only group-shared dashboards can be forked. Rejected — a user might want to backup/duplicate their own dashboard; artificial restriction adds no value.
- Only the current active dashboard can be forked. Rejected — user might want to fork a shared dashboard they don't have active, then switch to it.
- Explicit whitelist of dashboard UUIDs that can be forked. Rejected — administrative overhead; read-access is the right gate.

**Rationale**: Users own their read-access model (RBAC gates who can see what). The fork endpoint trusts that gate: if you can read it, you can fork it. This is consistent with "export" and other read-based operations.

### D3: Fork uses visibility resolver (REQ-DASH-013) to gate source access

**Decision**: Before forking, the service calls the shared `REQ-DASH-013` visibility resolver to check that the calling user has read access to the source dashboard. If not, return 404 (don't leak existence).

**Alternatives considered:**

- Simple `userId == ownerId OR admin` check. Rejected — doesn't account for group-shared dashboards where visibility depends on group membership.
- Run a full permission query in-line. Rejected — REQ-DASH-013 already solved this; reuse it.

**Rationale**: REQ-DASH-013 is the canonical visibility contract for all read operations. Reusing it means one place to audit, one place to fix if visibility rules change.

### D4: Default name is translated string with source name

**Decision**: When user omits a `name` field in the fork request, use `t('My copy of {name}', ['name' => $source->getName()])` — a translated template that makes provenance obvious.

**Alternatives considered:**

- Silent auto-name like "Dashboard_copy_1". Rejected — opaque, hard to tell which source was forked.
- Force user to provide name (no default). Rejected — adds friction; one-click needs a sensible default.
- Use source name as-is. Rejected — no signal that it's a personal copy, confusing when user has a fork and the original both visible.

**Rationale**: "My copy of Marketing Overview" clearly signals the fork relationship and appears in personal namespace. Translatable via `IL10N::t()` for non-English deployments.

### D5: Fork is fully transactional; all-or-nothing semantics

**Decision**: The fork operation (insert dashboard row, bulk-insert cloned placements, update active-dashboard state) MUST execute within a single `IDBConnection::beginTransaction()` block. Any error rolls back the entire operation.

**Alternatives considered:**

- Best-effort: insert dashboard, skip failed placements, proceed anyway. Rejected — would leave the fork in a partial state (missing widgets), breaking the visual clone contract.
- Separate placements transaction. Rejected — if placements fail but dashboard row was committed, admin has to clean up orphaned rows.

**Rationale**: Atomic transaction guarantees that users either get a complete fork or no fork at all. On failure, the system is in a clean state, no orphaned data, no manual intervention needed.

### D6: Forking does NOT deactivate only the source; it deactivates ALL other personal dashboards

**Decision**: When a fork is created, it becomes the user's active dashboard. This means deactivating ANY previously-active dashboard owned by that user, not just the source.

**Alternatives considered:**

- Deactivate only the source. Rejected — if user had Dashboard A active and tries to fork Dashboard B, should the fork become active (UX expectation) but A stays active? That's inconsistent state.
- Never change active state; user switches manually. Rejected — fork is a user action, becoming active is the point.
- Keep the source active, put fork second-in-line. Rejected — user expects to see their fresh fork, not the source.

**Rationale**: "I just forked Dashboard B" → "I am now viewing my fork of Dashboard B" is the user expectation. This requires deactivating whatever was previously active.

### D7: Fork bulk-inserts cloned placements via `INSERT…SELECT`

**Decision**: Instead of fetching source placements in PHP and inserting one-by-one, use a single bulk `INSERT INTO dashboard_placements (cols...) SELECT cols... FROM dashboard_placements WHERE dashboard_id = $sourceId` query with new IDs auto-generated.

**Alternatives considered:**

- Loop-and-insert in PHP (simpler code, easier to debug). Rejected — N placements = N round-trips to DB; bulk INSERT…SELECT is 1 round-trip.
- Prepare a batch insert statement. Rejected — equivalent overhead to INSERT…SELECT but more code.

**Rationale**: Bulk insert is O(1) DB calls vs O(n), faster on widgets-heavy dashboards, and keeps the transaction duration short.

## Reuse Analysis

The fork operation reuses these existing OpenRegister abstractions and services:

- **REQ-DASH-013 visibility resolver** — to gate source access (read-permission gate)
- **Dashboard model + mapper** — existing `Dashboard` entity and `DashboardMapper` for CRUD
- **WidgetPlacementMapper** — existing mapper for placement rows; fork adds `cloneToDashboard()` helper
- **IDBConnection transaction** — standard Nextcloud DB transaction API for atomicity
- **IL10N** — standard Nextcloud i18n for translated default name
- **IAppConfig** — existing settings service for the `allow_user_dashboards` admin gate

No new services or abstractions are built. The fork is a transactional composition of existing pieces.

## Deduplication Check

Searched `openspec/` and `lib/Service/` for overlapping fork/clone/duplicate capabilities:

- `widget-collision-placement` (grid layout auto-position) — unrelated, different operation
- `multi-scope-dashboards` (dashboard types + visibility RBAC) — used by fork but not duplicated
- `allow-personal-dashboards-flag` (admin setting) — prerequisite feature, fork consumes it
- No existing fork/clone implementation found; this is the first fork mechanism for dashboards

**Verdict**: No duplication. Fork builds on the foundation of multi-scope-dashboards and allow-personal-dashboards-flag.

## Seed Data

When MyDash is first installed, `lib/Settings/mydash_register.json` includes a "system default" dashboard template with a sensible widget layout (e.g., key metrics + recent activity). Users fork this template to create personal dashboards that suit their workflow.

**System default layout** (seeded on install):
- Dashboard UUID: `system-default` (or similar stable slug)
- Type: `default`
- Owner: `null` (admin-owned)
- Widgets: 4 sample placements (KPI card, activity list, upcoming events, notes)

**Personal copy** (created on fork):
- Dashboard UUID: newly generated UUID
- Type: `user`
- Owner: user's UID
- Widgets: byte-for-byte clone of source (same gridX/Y/W/H, styleConfig, tileTitle, tileIcon, etc.)

Example seed data is documented in the "Seed Data" section of `design.md` if this change introduces OpenRegister schemas. Since fork uses existing Dashboard entity, no schema change; seed data is illustrative only.

## Migration Plan

1. **Mapper helper lands first** — add `WidgetPlacementMapper::cloneToDashboard()` helper and unit tests in one commit.
2. **Service method follows** — add `DashboardService::forkAsPersonal()` with transaction wrapping and integration tests.
3. **Controller endpoint** — add `POST /api/dashboards/{uuid}/fork` route and expose via OpenAPI.
4. **Frontend wiring** — add "+ Fork Dashboard" button to sidebar and integrate with store.
5. **Quality gates** — run `composer check:strict` and Playwright tests; document in API spec.

**Rollback**: Pure CRUD operation, no schema migration. Reverting the PR leaves any previously-forked dashboards in place (they become orphaned but readable via their UUID if accessed directly). Cleanup is optional (admins can delete unused forks manually or run a cron job).

## Open Questions

None at this time. The design is aligned with existing architecture (REQ-DASH-013 visibility, IDBConnection transactions, IL10N for i18n, existing mapper pattern).
