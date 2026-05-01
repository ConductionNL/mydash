# Design — Dashboard RSS Feeds

## Context

This capability provides a per-user RSS/Atom feed of accessible dashboards, gated by a revocable
token. The spec (`dashboard-rss-feeds`) pins one-token-per-user, ACL enforcement at render time,
and dual RSS 2.0 / Atom output. Deleted dashboards disappear naturally via ACL checks at render
time — `dashboard-cascade-events` is not involved.

Source confirms the pattern: `intravox-source/lib/Db/FeedToken.php` and
`intravox-source/lib/Controller/FeedController.php`. `generateToken()` deletes any existing token
before insert — we match this replace-not-accumulate behaviour. This design covers token format,
storage schema, revoke-regenerate flow, format negotiation, and ACL timing.

## Goals / Non-Goals

**Goals:**
- Specify token format and storage schema
- Document revoke-then-regenerate flow (POST always replaces)
- Define feed format selection via Accept header
- Specify ACL enforcement at render time (not token-issue time)
- Document feed item content and ordering

**Non-Goals:**
- Push/webhook delivery (feed is pull-only)
- Per-dashboard granularity tokens (one token covers all user-accessible dashboards)
- Feed caching layer (HTTP cache headers are sufficient)

## Decisions

### D1: Token format
**Decision**: `random_bytes(32)` encoded as URL-safe base64 (no padding) → ~43 chars. Stored as CHAR(43).
**Source evidence**: `intravox-source/lib/Db/FeedToken.php:~40` — `bin2hex(random_bytes(32))` (64-char hex).
We switch to base64url: shorter, same entropy (256-bit), URL-safe without encoding.
**Alternatives considered**:
- UUIDv4 — rejected; only 122 bits entropy
- Hex (source) — 64 chars; functional but unnecessarily long
**Rationale**: base64url is the compact, high-entropy, URL-safe standard choice.

### D2: Storage schema
**Decision**: Table `oc_mydash_feed_tokens(id, user_id, token, created_at)` with `UNIQUE(user_id)`
and `UNIQUE(token)`. `user_id` unique enforces one-token-per-user at DB level.
**Source evidence**: `intravox-source/lib/Db/FeedToken.php:~25-35` — `user_id` unique index
confirmed; the delete-before-insert in `generateToken()` is the application-level guarantee.
**Alternatives considered**:
- Allow multiple tokens per user with revocation list — rejected; spec is explicit on one-token model
**Rationale**: DB-level uniqueness is the safety net. Application-level delete-before-insert
(wrapped in a transaction) is the primary mechanism, matching source behaviour.

### D3: Revoke-then-regenerate flow
**Decision**: `POST /api/me/feed-token` handles both generation and rotation — always deletes
existing token (if any) and inserts a new one in one transaction. Returns HTTP 200
`{"token":"<new>","feedUrl":"<url>"}`. No separate DELETE endpoint.
**Source evidence**: `intravox-source/lib/Controller/FeedController.php:~55` — single action, delete-then-insert.
**Alternatives considered**: POST+DELETE+PUT — rejected; three endpoints for one atomic concept.
**Rationale**: Atomic replace; old token invalid immediately after commit.

### D4: Feed format negotiation
**Decision**: `GET /api/feed/{token}` returns RSS 2.0 by default; `Accept: application/atom+xml`
switches to Atom 1.0. `?format=atom` accepted as fallback for clients that can't set headers.
**Alternatives considered**: JSON Feed — deferred; low demand; addable later without breaking RSS/Atom.
**Rationale**: RSS 2.0 default covers widest reader range; Accept-header negotiation is standard.

### D5: ACL enforcement at feed-render time
**Decision**: `FeedController` resolves user from token, then calls
`DashboardService::getAccessibleDashboards($userId)` — the same method as the regular API.
No ACL snapshot at token-issue time.
**Source evidence**: `intravox-source/lib/Controller/FeedController.php:~80-110` — render-time access checks.
**Alternatives considered**: ACL snapshot at token issue — rejected; stale snapshot exposes revoked shares.
**Rationale**: Real-time ACL; un-shared dashboards vanish from feed on next fetch without token rotation.

### D6: Feed item content and ordering
**Decision**: Each item: `<title>` = dashboard title, `<link>` = public URL, `<description>` =
plain-text summary (stripped), `<pubDate>` = `updated_at`. Ordered by `updated_at` DESC, capped at 50.
**Alternatives considered**:
- No item cap — rejected; thousands of items break most readers and slow generation
- Order by `created_at` — rejected; recent edits are more relevant to subscribers
**Rationale**: 50-item cap matches feed reader conventions; `updated_at` surfaces active dashboards.

## Risks / Trade-offs

- **Token in URL** → appears in server logs and browser history; document as password-equivalent; HTTPS required (standard NC assumption)
- **Feed generation cost** → `getAccessibleDashboards` may be expensive at scale; add `Cache-Control: max-age=300` as first-line throttle

## Open follow-ups

- Add `?limit=` param (default 50, max 200) once usage data is available
- Evaluate per-dashboard `includedInFeed` opt-out flag if privacy concerns arise
- Add Atom `<updated>` feed-level element for conditional GET / `If-Modified-Since` support
