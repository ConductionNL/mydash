---
status: draft
---

# Dashboard RSS Feeds Specification

## Purpose

Expose a user's accessible dashboards as an RSS 2.0 / Atom feed accessible without Nextcloud browser authentication via a per-user secret token. Each user may opt-in by requesting their feed token; the feed is filtered by the token-owner's dashboard ACLs (permissions) so private content remains private. The feed enables integration with RSS readers, monitoring tools, and third-party systems without requiring full Nextcloud login.

## Data Model

Feed tokens are stored in a new `oc_mydash_feed_tokens` table with the following fields:
- **id**: Auto-increment integer primary key
- **userId**: Nextcloud user ID (VARCHAR(64)), for whom this token was issued
- **token**: URL-safe cryptographically random token string (VARCHAR(64))
- **createdAt**: Timestamp (DATETIME) when the token was first generated
- **lastUsedAt**: Nullable timestamp (DATETIME NULL) of the last successful feed request
- **revokedAt**: Nullable timestamp (DATETIME NULL) indicating soft revocation

Constraints:
- UNIQUE index on `(userId)` — only one token per user at any time (rotation via revoke + regenerate)
- Token format: 32 random bytes encoded as base64-url string, yielding approximately 43 characters (not enumerable, not time-based, cryptographically strong)

## ADDED Requirements

### Requirement: REQ-FEED-001 Token Issue

Users MUST be able to request and receive their personal feed token on first call, creating it atomically if not yet issued.

#### Scenario: Request token for first time
- GIVEN a logged-in Nextcloud user "alice" who has never requested a feed token
- WHEN she sends `GET /api/feed/token`
- THEN the system MUST:
  - Generate a 32-byte random token and base64-url-encode it
  - Insert a record in `oc_mydash_feed_tokens` with `userId='alice'`, the generated token, `createdAt` set to now, `lastUsedAt=NULL`, `revokedAt=NULL`
  - Return HTTP 200 with JSON body `{"token": "...", "url": "https://example.com/feed/<token>.xml"}`
- AND the `url` MUST be an absolute URL (e.g., `https://myinstance.example/index.php/apps/mydash/api/feed/<token>.xml`)

#### Scenario: Request token when one already exists
- GIVEN user "alice" has previously issued a token with value "abc...xyz"
- WHEN she sends `GET /api/feed/token`
- THEN the system MUST return HTTP 200 with the existing token (no duplicate created)
- AND the response MUST include `{"token": "abc...xyz", "url": "...abc...xyz.xml"}`

#### Scenario: Unauthenticated request is rejected
- GIVEN an unauthenticated client (no Nextcloud session)
- WHEN it sends `GET /api/feed/token`
- THEN the system MUST return HTTP 401 Unauthorized

### Requirement: REQ-FEED-002 Token Regenerate

Users MUST be able to revoke and reissue a new token in a single atomic operation, invalidating the old token.

#### Scenario: Regenerate an existing token
- GIVEN user "bob" has an existing token "old_token_xyz"
- WHEN he sends `POST /api/feed/token/regenerate`
- THEN the system MUST:
  - Set `revokedAt` on the old token record to now
  - Generate a new 32-byte random token and base64-url-encode it
  - Insert or update to create a fresh record with `userId='bob'`, the new token, `createdAt` set to now, `lastUsedAt=NULL`, `revokedAt=NULL`
  - Return HTTP 200 with `{"token": "new_token_...", "url": "...new_token_....xml"}`
- AND the old token MUST no longer resolve any feeds (returns 404)

#### Scenario: Regenerate when no token exists
- GIVEN user "charlie" has never requested a token
- WHEN he sends `POST /api/feed/token/regenerate`
- THEN the system MUST generate and return a new token (same as first-time issuance)
- AND HTTP 200 with the token and URL

#### Scenario: Unauthenticated regenerate is rejected
- GIVEN an unauthenticated client
- WHEN it sends `POST /api/feed/token/regenerate`
- THEN the system MUST return HTTP 401

### Requirement: REQ-FEED-003 Token Soft-Revoke

Users MUST be able to soft-revoke (disable) their token without deleting the database record, enabling re-enablement or re-issuance later.

#### Scenario: Soft-revoke an active token
- GIVEN user "diana" has an active token
- WHEN she sends `DELETE /api/feed/token`
- THEN the system MUST:
  - Set `revokedAt` on her token record to now
  - Return HTTP 204 No Content
- AND the token MUST no longer resolve any feeds (returns 404 on public `/feed/<token>.xml` request)

#### Scenario: Revoke when no token exists
- GIVEN user "eve" has never requested a token
- WHEN she sends `DELETE /api/feed/token`
- THEN the system MUST return HTTP 204 (idempotent, treat as success even if no token was active)

#### Scenario: Re-issue after revocation
- GIVEN user "frank" has previously revoked his token via `DELETE /api/feed/token`
- WHEN he sends `GET /api/feed/token`
- THEN the system MUST issue a NEW token (the revoked record remains, a new one is inserted)
- AND the old revoked token MUST NOT be re-activated

#### Scenario: Unauthenticated revoke is rejected
- GIVEN an unauthenticated client
- WHEN it sends `DELETE /api/feed/token`
- THEN the system MUST return HTTP 401

### Requirement: REQ-FEED-004 Public Feed Rendering

Public clients MUST be able to request and render a feed without Nextcloud session auth by providing a valid token, receiving a standards-compliant RSS or Atom feed.

#### Scenario: Fetch feed with valid token
- GIVEN a public client (no Nextcloud auth) and a valid token "secure_token_xyz" belonging to user "grace"
- WHEN it sends `GET /feed/secure_token_xyz.xml`
- THEN the system MUST:
  - Resolve the user "grace" from the token record
  - Return HTTP 200 with Content-Type `application/rss+xml` or `application/atom+xml`
  - Return a valid RSS 2.0 or Atom feed with one `<item>` (RSS) or `<entry>` (Atom) per dashboard grace can access
  - Set `lastUsedAt` on the token record to now
- AND the response MUST include a `<title>` tag (e.g., "Grace's MyDash Dashboards") and `<link>` to the MyDash home

#### Scenario: Fetch feed with invalid token
- GIVEN a public client and a token that does not exist in the database
- WHEN it sends `GET /feed/invalid_token.xml`
- THEN the system MUST return HTTP 404 (do NOT leak whether the token ever existed)
- AND `lastUsedAt` MUST NOT be updated

#### Scenario: Fetch feed with revoked token
- GIVEN a public client and a token that was previously revoked (has `revokedAt` set)
- WHEN it sends `GET /feed/revoked_token.xml`
- THEN the system MUST return HTTP 404 (treat revoked same as non-existent, do not leak revocation status)

#### Scenario: Fetch feed with revoked-then-reissued user
- GIVEN user "henry" revokes his token and later re-issues (generating a new one), and a client tries the old revoked token
- WHEN it sends `GET /feed/old_revoked_token.xml`
- THEN the system MUST return HTTP 404

### Requirement: REQ-FEED-005 Feed Item Content

Each dashboard the token-owner can access MUST appear as a separate `<item>` (RSS) or `<entry>` (Atom) in the feed, carrying essential metadata and respecting the user's ACLs.

#### Scenario: Feed with multiple accessible dashboards
- GIVEN user "iris" has three dashboards: "Work" (updated 2026-04-20), "Personal" (updated 2026-04-15), "Archived" (updated 2026-03-01), all accessible to iris per the permissions capability
- WHEN a public client requests `GET /feed/iris_token.xml`
- THEN the response MUST include three items in reverse-chronological order (most recent first):
  1. "Work" (pubDate 2026-04-20T..., title "Work")
  2. "Personal" (pubDate 2026-04-15T...)
  3. "Archived" (pubDate 2026-03-01T...)
- AND each item MUST carry:
  - `<title>`: dashboard name (e.g., "Work")
  - `<link>`: absolute deep-link to the dashboard in Nextcloud (e.g., `https://example.com/index.php/apps/mydash#/dashboard/uuid`)
  - `<description>`: dashboard description (escaped for XML), or empty string if null
  - `<pubDate>`: dashboard's `updatedAt` timestamp in RFC 2822 format (e.g., "Wed, 20 Apr 2026 14:30:00 +0000")
  - `<guid isPermaLink="false">`: dashboard UUID (ensures feed readers detect updates correctly)
  - `<author>`: display name of the dashboard owner (e.g., "Iris Smith")

#### Scenario: Dashboard with null description
- GIVEN dashboard "NoDesc" with `description=NULL`
- WHEN it appears in a feed
- THEN the `<description>` tag MUST be present but empty, or the tag MUST be omitted entirely
- AND the item MUST remain valid RSS

#### Scenario: Dashboard description with special XML characters
- GIVEN dashboard "Special" with description "Profits & losses <Q2>" (contains `&`, `<`, `>`)
- WHEN it appears in the feed
- THEN all special characters MUST be XML-escaped in the `<description>` tag (e.g., `&amp;`, `&lt;`, `&gt;`)

### Requirement: REQ-FEED-006 ACL Filtering

Feeds MUST respect the token-owner's dashboard access permissions (permissions capability) such that private dashboards visible only to the token-owner do not leak their content to unauthorized observers.

#### Scenario: Token-owner sees only accessible dashboards
- GIVEN user "jack" has two dashboards: "Public" (visible to all, via permissions), "Private" (only visible to jack)
- WHEN a public client requests jack's feed token
- THEN the response MUST include only "Public"
- AND "Private" MUST NOT appear in the feed

#### Scenario: Shared dashboard via permissions
- GIVEN dashboard "Shared" is configured to be visible to user "kate" (via permissions capability), and "kate"'s token is used
- WHEN a public client requests /feed/kate_token.xml
- THEN "Shared" MUST be included in the feed

#### Scenario: Filtering is based on token-owner's identity, not requester IP
- GIVEN user "leo" has token "leo_secret" and dashboard "Leo's Private" visible only to leo
- WHEN ANY public client (any IP, any location) requests /feed/leo_secret.xml
- THEN the feed MUST contain only dashboards accessible to leo (regardless of who or where the requester is)
- AND "Leo's Private" MUST appear even if accessed from a different country

### Requirement: REQ-FEED-007 Feed Item Count Cap

Feeds MUST include at most N dashboards per feed (admin-configurable), ordered by most recent first, to prevent unbounded response sizes.

#### Scenario: Feed respects default cap of 50 items
- GIVEN user "maya" has 75 accessible dashboards, ordered by descending `updatedAt`
- WHEN a public client requests /feed/maya_token.xml
- THEN the response MUST include exactly 50 items (the newest 50 by `updatedAt`)
- AND the oldest 25 dashboards MUST NOT appear

#### Scenario: Administrator configures feed cap
- GIVEN the `mydash.feed_item_cap` config is set to 10 (default: 50)
- WHEN user "noah" requests /feed/noah_token.xml with 15 accessible dashboards
- THEN the response MUST include exactly 10 items
- AND the config value MUST be read from `OCP\IConfig::getAppValue('mydash', 'mydash.feed_item_cap', '50')`

#### Scenario: Feed with fewer than cap dashboards
- GIVEN user "olivia" has 8 accessible dashboards and the cap is 50
- WHEN a public client requests /feed/olivia_token.xml
- THEN the response MUST include all 8 items (no padding or errors)

### Requirement: REQ-FEED-008 Per-User Opt-In

Feeds MUST be off by default; users MUST explicitly opt-in by calling `GET /api/feed/token` to enable feed generation.

#### Scenario: No feed token pre-created for any user
- GIVEN a fresh MyDash installation with no users having requested feed tokens
- WHEN an admin or system process tries to request /feed/any_token.xml
- THEN the system MUST return HTTP 404 for any token

#### Scenario: User explicitly requests token to enable feed
- GIVEN user "paul" has never requested a feed token
- WHEN paul sends `GET /api/feed/token`
- THEN:
  - A token is generated and stored
  - The endpoint returns the token
  - Only NOW can public clients access /feed/paul_token.xml (before, /feed/paul_token.xml would return 404 because no token record existed)

#### Scenario: Revocation disables feed without deleting user
- GIVEN user "quinn" has an active token and a feed accessible at /feed/quinn_token.xml
- WHEN quinn sends `DELETE /api/feed/token`
- THEN:
  - /feed/quinn_token.xml returns HTTP 404
  - quinn's user account remains intact
  - If quinn later calls `GET /api/feed/token` again, a NEW token is issued (the old one is not reactivated)

### Requirement: REQ-FEED-009 Token Format and Randomness

Token format MUST be cryptographically random, URL-safe, and non-enumerable to prevent brute-force attacks.

#### Scenario: Token is cryptographically random
- GIVEN a MyDash instance with access to `random_bytes()` (PHP 7+)
- WHEN user "rose" requests a token via `GET /api/feed/token`
- THEN the generated token MUST be produced by `bin2hex(random_bytes(32))` or equivalent base64-url encoding
- AND each token MUST be unique across all users (enforced by database UNIQUE constraint on (userId))

#### Scenario: Token is URL-safe
- GIVEN a generated token
- WHEN it is used in a URL like /feed/{token}.xml
- THEN it MUST NOT require URL-encoding (no `+`, `/`, `=` or other URL-unsafe characters after base64-url encoding)
- NOTE: bin2hex() output is inherently URL-safe; base64-url encoding must use `-` and `_` instead of `+` and `/`

#### Scenario: Token is not enumerable
- GIVEN two consecutive token generations (e.g., from two different users)
- WHEN their tokens are observed
- THEN there MUST be no discernible pattern, incremental sequence, or time-based correlation
- AND an attacker MUST NOT be able to guess a valid token even if they observe one or more valid tokens

