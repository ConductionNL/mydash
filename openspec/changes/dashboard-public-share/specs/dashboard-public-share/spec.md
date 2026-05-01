---
capability: dashboard-public-share
delta: true
status: draft
---

# Dashboard Public Share — New Capability Specification

## ADDED Requirements

### Requirement: REQ-PSHR-001 Create Public Share

Dashboard owners (or admins) MUST be able to create a public share with an optional password and expiry date.

#### Scenario: Create a public share without password
- GIVEN a logged-in Nextcloud user "alice" who owns dashboard "Sales Pipeline" (uuid `550e8400-e29b-41d4-a716-446655440001`)
- WHEN she sends `POST /api/dashboards/550e8400-e29b-41d4-a716-446655440001/public-share` with body `{}`
- THEN the system MUST create a new public share record with a generated 64-byte URL-safe random `token`, `passwordHash` set to NULL, `expiresAt` set to NULL, and `createdBy` set to "alice"
- AND return HTTP 201 with response body `{token, url, passwordRequired: false, expiresAt: null}`

#### Scenario: Create a public share with password
- GIVEN user "alice" owns dashboard uuid `550e8400-e29b-41d4-a716-446655440001`
- WHEN she sends `POST /api/dashboards/550e8400-e29b-41d4-a716-446655440001/public-share` with body `{"password": "SecurePass123!"}`
- THEN the system MUST hash the password via BCrypt (cost factor ≥ 10) and store the hash (NOT plaintext) in `passwordHash`
- AND return `passwordRequired: true` in the response

#### Scenario: Create a public share with expiry
- GIVEN user "alice" owns the dashboard
- WHEN she sends `POST /api/dashboards/550e8400-e29b-41d4-a716-446655440001/public-share` with body `{"expiresAt": "2026-12-31T23:59:59Z"}`
- THEN the system MUST parse the ISO 8601 timestamp and store it in the `expiresAt` column
- AND return the expiry in the response

#### Scenario: Create multiple shares for one dashboard
- GIVEN user "alice" owns a dashboard and has already created one public share for it
- WHEN she creates a second share with different password/expiry settings
- THEN both shares MUST be active simultaneously with independent tokens and passwords
- AND each share MUST NOT affect the other

#### Scenario: Non-owner cannot create shares
- GIVEN user "bob" does NOT own dashboard uuid `550e8400-e29b-41d4-a716-446655440001`
- WHEN he sends `POST /api/dashboards/550e8400-e29b-41d4-a716-446655440001/public-share`
- THEN the system MUST return HTTP 403
- AND no share MUST be created

### Requirement: REQ-PSHR-002 List Active Shares

Dashboard owners MUST be able to list all active (non-revoked) public shares for their dashboard.

#### Scenario: List shares for a dashboard with multiple active shares
- GIVEN user "alice" owns a dashboard with 3 active shares and 1 revoked share
- WHEN she sends `GET /api/dashboards/550e8400-e29b-41d4-a716-446655440001/public-shares`
- THEN the system MUST return HTTP 200 with an array of 3 shares (revoked excluded)
- AND each share object MUST include: `id`, `token` (fully visible, not redacted), `url`, `passwordRequired`, `expiresAt`, `viewCount`, `lastViewedAt`

#### Scenario: List shares for a dashboard with no shares
- GIVEN user "alice" owns a dashboard with zero shares
- WHEN she sends `GET /api/dashboards/550e8400-e29b-41d4-a716-446655440001/public-shares`
- THEN the system MUST return HTTP 200 with an empty array `[]`

#### Scenario: Non-owner cannot list shares
- GIVEN user "bob" does NOT own the dashboard
- WHEN he sends `GET /api/dashboards/550e8400-e29b-41d4-a716-446655440001/public-shares`
- THEN the system MUST return HTTP 403

#### Scenario: Revoked shares are not included in list
- GIVEN user "alice" has 5 shares, 2 of which have `revokedAt IS NOT NULL`
- WHEN she fetches the list
- THEN exactly 3 shares MUST be returned

### Requirement: REQ-PSHR-003 Revoke Public Share

Dashboard owners MUST be able to soft-revoke a share via setting `revokedAt` without hard-deleting the row.

#### Scenario: Soft-revoke a public share
- GIVEN user "alice" owns a dashboard and share id=7 is active
- WHEN she sends `DELETE /api/dashboards/550e8400-e29b-41d4-a716-446655440001/public-shares/7`
- THEN the system MUST set `revokedAt` to the current timestamp (soft-delete)
- AND NOT hard-delete the row from the database
- AND return HTTP 204 (No Content)

#### Scenario: Revoked share is no longer valid
- GIVEN share id=7 has been revoked (`revokedAt IS NOT NULL`)
- WHEN an anonymous user tries to access `GET /s/{token-for-share-7}`
- THEN the system MUST return HTTP 404 (not distinguishing whether token never existed or was revoked)

#### Scenario: Non-owner cannot revoke shares
- GIVEN user "bob" does NOT own the dashboard
- WHEN he sends `DELETE /api/dashboards/550e8400-e29b-41d4-a716-446655440001/public-shares/7`
- THEN the system MUST return HTTP 403

#### Scenario: Revoking already-revoked share is idempotent
- GIVEN share id=7 already has `revokedAt IS NOT NULL`
- WHEN alice sends DELETE for the same share again
- THEN the system MUST return HTTP 204 (no error)

### Requirement: REQ-PSHR-004 Public Render Dashboard (Anonymous)

Anonymous users MUST be able to render a dashboard via a public share token, subject to password protection and expiry checks.

#### Scenario: Public render via valid token without password
- GIVEN a public share with `token = "vK9mP2qL7xR4nJ5tU8wS3cF6gH1jZ0bY"` (no password, not revoked, not expired)
- WHEN an unauthenticated user sends `GET /s/vK9mP2qL7xR4nJ5tU8wS3cF6gH1jZ0bY`
- THEN the system MUST validate the token, confirm `revokedAt IS NULL` and expiry not elapsed, load the dashboard and placements
- AND return HTTP 200 with the dashboard data (read-only JSON, no edit endpoints exposed)

#### Scenario: Invalid token returns 404
- GIVEN a request with `token = "invalid-token-does-not-exist"`
- WHEN `GET /s/invalid-token-does-not-exist` is sent
- THEN the system MUST return HTTP 404

#### Scenario: Revoked token returns 404
- GIVEN a share with a valid token but `revokedAt IS NOT NULL`
- WHEN the token is used to render
- THEN the system MUST return HTTP 404 (avoid leaking whether the token ever existed)

#### Scenario: Expired token returns 404
- GIVEN a share with `expiresAt = "2026-04-30T23:59:59Z"` and the current time is 2026-05-01T00:00:00Z
- WHEN the token is used to render
- THEN the system MUST return HTTP 404

#### Scenario: Public render must not reuse user session for GroupFolder content
- GIVEN dashboard content lives in the GroupFolder backend (sibling spec: `groupfolder-storage-backend`)
- WHEN a public-share token renders the dashboard
- THEN the system MUST use a service-account path (NOT the current Nextcloud user's permissions)
- SO that the GroupFolder ACL bypass mechanism allows the render as intended

### Requirement: REQ-PSHR-005 Password Gate (Unlock)

Password-protected shares MUST require a POST unlock before rendering via the public share UI.

#### Scenario: Password-protected share returns 401 without unlock
- GIVEN a public share with `passwordHash IS NOT NULL` and `token = "abc123"`
- WHEN an anonymous user sends `GET /s/abc123` without unlock
- THEN the system MUST return HTTP 401 with body `{passwordRequired: true}`
- AND the dashboard data MUST NOT be included

#### Scenario: Unlock with correct password
- GIVEN a share with BCrypt password hash for password "SecurePass123!"
- WHEN an anonymous user sends `POST /s/abc123/unlock` with body `{"password": "SecurePass123!"}`
- THEN the system MUST verify the plaintext password against the stored hash via `password_verify()`
- AND return HTTP 200 with body `{access: true}`

#### Scenario: Unlock with incorrect password
- GIVEN the same share and password
- WHEN the user sends `POST /s/abc123/unlock` with body `{"password": "WrongPassword"}`
- THEN the system MUST return HTTP 401 with body `{access: false}`
- AND NOT block subsequent attempts (throttle is separate)

#### Scenario: Unlock is throttled to prevent brute-force
- GIVEN a public share with a password
- WHEN an attacker sends 11 failed unlock attempts from IP `203.0.113.100` within 60 minutes
- THEN the system MUST invoke Nextcloud's `IThrottler` service
- AND return HTTP 503 (Service Unavailable) on the 11th attempt with `Retry-After` header

#### Scenario: Query param password alternative to header
- GIVEN a password-protected share
- WHEN an anonymous user sends `GET /s/abc123?password=SecurePass123!` OR includes header `X-Share-Password: SecurePass123!`
- THEN the system MUST accept either method
- AND return HTTP 200 with the rendered dashboard (server-side verification)

### Requirement: REQ-PSHR-006 Read-Only Enforcement

Any mutation endpoint accessed with a public-share token (not a logged-in Nextcloud user session) MUST return HTTP 403.

#### Scenario: Cannot create widget on public share
- GIVEN an anonymous user has rendered dashboard via public share
- WHEN they attempt `POST /api/dashboards/{uuid}/placements`
- THEN the system MUST detect the public-share bearer and return HTTP 403 with message "Cannot modify dashboard via public share"

#### Scenario: Cannot edit dashboard via public share
- GIVEN a public share allows viewing
- WHEN an anonymous user attempts `PUT /api/dashboard/{uuid}` to rename
- THEN the system MUST return HTTP 403

#### Scenario: Cannot delete dashboard via public share
- GIVEN a public share allows viewing
- WHEN an anonymous user attempts `DELETE /api/dashboard/{uuid}`
- THEN the system MUST return HTTP 403

#### Scenario: Logged-in user on public share renders read-only
- GIVEN an authenticated Nextcloud user accesses the public share link
- THEN the system MUST render it as read-only (same as anonymous)
- AND the user's normal dashboard edit permissions MUST NOT override the public-share read-only mode

### Requirement: REQ-PSHR-007 View Count Debouncing

View counts MUST be incremented at most once per minute per (token, client IP) pair to prevent refresh-spam inflation.

#### Scenario: Repeated renders from same IP in one minute
- GIVEN a public share with `viewCount = 10`
- WHEN the same IP renders the dashboard at t=0s, t=15s, t=45s (all within 60 seconds)
- THEN `viewCount` MUST be incremented to 11 (once only)
- AND `lastViewedAt` MUST be set to the latest render time (t=45s)

#### Scenario: Renders from different IPs are counted separately
- GIVEN the same share
- WHEN IP `203.0.113.42` renders at t=0s and IP `203.0.113.43` renders at t=10s
- THEN `viewCount` MUST be incremented once per IP (each IP counts independently)

#### Scenario: Renders beyond the 60-second window reset the debounce
- GIVEN the same share
- WHEN IP `203.0.113.42` renders at t=0s and again at t=65s (beyond the 60-second window)
- THEN `viewCount` MUST be incremented twice (one per window)

### Requirement: REQ-PSHR-008 Expiry and Revocation 404 Response

Shares with `expiresAt < now()` OR `revokedAt IS NOT NULL` MUST return HTTP 404 and avoid leaking whether the token ever existed.

#### Scenario: Expired share returns 404
- GIVEN a share with `expiresAt = "2026-04-30T23:59:59Z"`
- AND the current time is 2026-05-01T00:00:00Z
- WHEN an anonymous user attempts `GET /s/{token}`
- THEN the system MUST return HTTP 404

#### Scenario: Share expiring in the future is still valid
- GIVEN a share with `expiresAt = "2026-05-02T12:00:00Z"`
- AND the current time is 2026-05-01T14:00:00Z
- WHEN the token is used to render
- THEN the system MUST return HTTP 200

#### Scenario: Share with null expiresAt never expires
- GIVEN a share with `expiresAt IS NULL`
- WHEN the token is rendered at any future time
- THEN the system MUST NOT apply expiry logic
- AND the share MUST remain valid until revoked

### Requirement: REQ-PSHR-009 Brute-Force Protection

Failed unlock attempts MUST be throttled via Nextcloud's `IThrottler` to prevent password guessing.

#### Scenario: 10 failed unlocks per hour allowed
- GIVEN a public share with a password
- WHEN an attacker sends unlock requests with wrong passwords from IP `203.0.113.50`
- THEN the first 10 attempts MUST return HTTP 401
- AND the 11th attempt within the 60-minute window MUST return HTTP 503

#### Scenario: Throttle is per-IP per-share
- GIVEN two different public shares
- WHEN IP `203.0.113.50` sends 10 failed unlocks against share A and 10 failed unlocks against share B
- THEN the throttle counter SHOULD be tracked per-share (implementation may vary)

#### Scenario: Throttle resets after time window
- GIVEN IP `203.0.113.50` exhausted 10 failed attempts
- AND 60+ minutes pass with no new attempts
- WHEN they send another unlock attempt
- THEN the system MUST accept it (not return 503)

### Requirement: REQ-PSHR-010 Service-Account File Read for GroupFolder Content

Public shares referencing dashboards with GroupFolder-backed content MUST use a service-account read path, not the viewer's session.

#### Scenario: Public share rendering GroupFolder content uses service account
- GIVEN a dashboard containing a widget that references a GroupFolder resource
- WHEN an anonymous user renders the dashboard via a public share token
- THEN the system MUST use a service-account file-read context (NOT the anonymous user's non-existent session)
- AND the GroupFolder backend's ACL bypass mechanism MUST allow the render

#### Scenario: Widget data accessible via service account
- GIVEN a GroupFolder resource is readable only by members of group "Engineering"
- AND an anonymous public-share user is not a member of "Engineering"
- WHEN the service-account render path is used
- THEN the service account MUST have appropriate read permissions (granted by GroupFolder admin configuration)
- AND the widget data MUST be included in the render

---

## Summary of `dashboard-public-share` Capability

This new capability introduces anonymous read-only sharing of dashboards via URL-safe tokens with optional password protection, expiry, and view tracking. The 10 requirements cover creation, listing, revocation, anonymous rendering, password gating, read-only enforcement, view-count debouncing, expiry/revocation 404 responses, brute-force protection, and GroupFolder service-account integration.

**Spec version**: draft (2026-05-01)
