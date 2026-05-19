# Design — Dashboard Reactions

## Context

This capability adds emoji reactions to dashboards. The spec (`dashboard-reactions`) pins the
`oc_mydash_dashboard_reactions` table, per-dashboard + global toggles, admin allow-list, idempotent
POST, and hide-on-disable behaviour. Deliberately NOT built on `ICommentsManager` — diverging from
`dashboard-comments`. The source app has no equivalent; the closest NC precedent is Deck's card
reactions, which also uses a dedicated table. This design covers storage schema, allow-list
mechanics, toggle semantics, and aggregation query shape.

## Goals / Non-Goals

**Goals:**
- Justify own table over ICommentsManager
- Document composite unique constraint and idempotent-POST semantics
- Specify allow-list storage and default value
- Define hide-on-disable behaviour (rows preserved, API returns empty)
- Specify aggregation query shape for dashboard detail response

**Non-Goals:**
- Reactions on comments (separate concern if ever needed)
- Real-time push on new reactions (NC notification/activity framework is out of scope for MVP)
- Custom emoji upload (allow-list is admin-controlled text list only)

## Decisions

### D1: Own table over ICommentsManager
**Decision**: Store reactions in `oc_mydash_dashboard_reactions(id, dashboard_uuid, user_id, emoji,
created_at)` with a composite unique index on `(dashboard_uuid, user_id, emoji)`.
**Alternatives considered**:
- NC `ICommentsManager` — rejected; designed for text comments, not emoji counts; makes
  "show dashboards alice reacted to" a full comment-table scan; audit semantics differ
- NC `oc_reactions` table (used by Talk) — rejected; not a public OCP API, subject to change
**Rationale**: Own table gives clean query patterns for user-centric queries (`WHERE user_id = ?`)
and dashboard-centric aggregation (`GROUP BY emoji`). Schema is minimal and stable.

### D2: Idempotent POST semantics
**Decision**: `POST /dashboards/{id}/reactions` with an already-reacted emoji returns HTTP 200.
`INSERT IGNORE` (or equivalent); always returns current counts.
**Alternatives considered**:
- HTTP 409 — rejected; forces pre-check round-trip
- HTTP 201 first / 200 repeat — rejected; complicates optimistic UI
**Rationale**: Idempotent toggle UX; client POSTs freely and trusts returned counts.

### D3: Allow-list storage
**Decision**: Admin setting `mydash.reactions_allowed_emojis` (NC `IAppConfig`) stores a JSON
array. Default value: `["👍","❤️","🎉","😂","🤔","😢"]`.
**Alternatives considered**:
- Hardcoded enum in code — rejected; admin customisation is a spec requirement
- Per-dashboard allow-list — rejected; admin wants global governance; per-dashboard adds
  complexity without a clear use case
**Rationale**: `IAppConfig` is the canonical NC mechanism for admin settings. JSON array in a
text config key is simple, readable, and patchable via `occ config:app:set`.

### D4: Allow-list enforcement point
**Decision**: Enforced at write time in `ReactionService::addReaction()`. Emoji not in the allow-list
returns HTTP 422 `emoji_not_allowed`.
**Alternatives considered**:
- Enforce at read time (filter stored reactions) — rejected; allows garbage data into the DB
**Rationale**: Write-time enforcement keeps the table clean. If admin narrows the allow-list later,
existing rows with now-disallowed emojis are preserved but hidden at read time (see D5).

### D5: Hide-on-disable semantics
**Decision**: When globally or per-dashboard disabled, `GET` returns `{"reactions": {}}` and `POST`
returns HTTP 403. Existing rows are NOT deleted; re-enabling restores everything.
**Alternatives considered**: Delete rows on disable — rejected; irreversible, violates least surprise.
**Rationale**: Disable is a governance control, not a purge.

### D6: Aggregation query shape
**Decision**: Dashboard detail endpoint includes `reactions` as a pre-aggregated map:
`{"👍": {"count": 5, "userReacted": true}}`. Computed via single `SELECT emoji, COUNT(*) as count,
MAX(user_id = ?) as user_reacted FROM oc_mydash_dashboard_reactions WHERE dashboard_uuid = ?
GROUP BY emoji`.
**Alternatives considered**:
- Separate `GET /reactions` endpoint — available for detail use, but summary map baked into
  dashboard detail avoids extra round-trips in the common case
**Rationale**: One query returns both aggregate and per-user state. The `:currentUserId` param is
injected by `ReactionService` from the NC session.

## Risks / Trade-offs

- **Emoji encoding edge cases** → Multi-codepoint emoji (skin tones, ZWJ sequences) stored as UTF-8 strings; MySQL `utf8mb4` required — verify collation on `emoji` column
- **Allow-list narrowing** → Rows with now-disallowed emojis are hidden but not purged; surface count of hidden rows in admin panel

## Open follow-ups

- Add admin endpoint `GET /api/admin/reactions/orphaned` to show rows with emojis no longer in allow-list
- Evaluate NC Activity integration: log `reacted_to_dashboard` event on first reaction per user per dashboard
- Consider rate-limiting reaction POSTs (e.g. 60/min per user) to prevent abuse
