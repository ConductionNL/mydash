# Dashboard RSS Feeds

## Why

MyDash users increasingly need to share dashboard updates with external systems (monitoring tools, RSS readers, email digests, n8n workflows) without granting full Nextcloud access. Today, this requires either embedding dashboards in iframes (fragile, requires browser login) or manually exporting data. A per-user RSS feed token enables seamless integration with standard feed consumers while respecting each user's existing dashboard ACLs (permissions) — no new authorization model needed.

## What Changes

- Add `oc_mydash_feed_tokens` table with `userId`, `token`, `createdAt`, `lastUsedAt`, `revokedAt` fields and UNIQUE constraint on `(userId)` for one-token-per-user rotation.
- Add `GET /api/feed/token` (authed) — issue or return existing user token, providing both the token string and an absolute feed URL.
- Add `POST /api/feed/token/regenerate` (authed) — atomically revoke the current token and issue a new one.
- Add `DELETE /api/feed/token` (authed) — soft-revoke the current token; subsequent `GET` will re-create if desired.
- Add `GET /feed/{token}.xml` (public, no Nextcloud auth) — resolve token to user, filter dashboards by token-user's ACLs, return RSS 2.0 or Atom feed; update `lastUsedAt`; return 404 for invalid or revoked tokens.
- Token format: 32 random bytes, base64-url encoded, non-enumerable, cryptographically strong.
- Opt-in model: feeds are off by default; users must call `GET /api/feed/token` to enable.
- Feed items: one per accessible dashboard, reverse-chronological by `updatedAt`, capped at 50 (admin-tunable via `mydash.feed_item_cap`). Each item carries dashboard name, deep-link, description (escaped), pubDate, UUID guid, and owner display name.
- Filtering: feeds respect the token-owner's permissions (from the existing permissions capability); private dashboards visible only to the token-owner do not leak to public feed consumers.

## Capabilities

### New Capabilities

- `dashboard-rss-feeds`: provides REQ-FEED-001 through REQ-FEED-009 (token issue, regenerate, revoke, public feed rendering, item content, ACL filtering, item cap, opt-in, token format).

### Modified Capabilities

- None. The `dashboards` capability is unchanged; the new feed is an alternative *output* of the same dashboard data, not a change to dashboard structure or CRUD.

## Impact

**Affected code:**

- `lib/Db/FeedToken.php` — new entity for token records
- `lib/Db/FeedTokenMapper.php` — new mapper for CRUD operations on feed tokens
- `lib/Service/FeedTokenService.php` — token generation, revocation, and resolution logic
- `lib/Service/FeedService.php` — render RSS/Atom feed from dashboards, apply ACL filter, enforce caps
- `lib/Controller/FeedController.php` — endpoint implementations (GET /api/feed/token, POST /api/feed/token/regenerate, DELETE /api/feed/token, GET /feed/{token}.xml)
- `appinfo/routes.php` — register four new routes (two authed, two public)
- `lib/Migration/VersionXXXXDate2026...php` — schema migration adding `oc_mydash_feed_tokens` table
- `config/sensitive-parameters.php` — mark `token` column as sensitive in logs (if such file exists in the app)

**Affected APIs:**

- 4 new routes (no existing routes modified)
- Existing `GET /api/dashboards` and related CRUD routes unchanged
- New public route `/feed/{token}.xml` requires no Nextcloud auth

**Dependencies:**

- `OCP\IConfig` — for reading `mydash.feed_item_cap` config
- `OCP\IUserManager` — for resolving display names from user IDs
- No new composer or npm dependencies; use PHP's built-in `random_bytes()` and base64 encoding

**Migration:**

- Creates a new table; zero impact on existing data.
- All users start with no token (opt-in); feeds remain private until a token is explicitly requested.

**Security:**

- Tokens are cryptographically random and URL-safe, resistant to brute-force enumeration.
- Invalid or revoked tokens return HTTP 404 (not 403) to avoid leaking token existence.
- Feed content is filtered by the token-owner's ACLs; no new authorization bypass is created.
- Tokens should be treated as secrets and not logged in plaintext (mark sensitive in logging config).

