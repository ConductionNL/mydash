# Spec — Embed Tokens (REQ-EMB-001, REQ-EMB-002)

## REQ-EMB-001 — Token issuance returns JWT exactly once

The system SHALL generate a JWT signed with the active signing key when a new `embed_token` is created, return it once in the create response, persist only the sha256 hash, and reject any subsequent request to re-retrieve the raw token.

### Scenario 1.1 — Create token and receive JWT once

GIVEN an admin user with token-management permission
  AND the system has an active RS256 signing key
WHEN they POST `/api/embed-tokens` with a valid token-create payload:
  ```json
  {
    "name": "Gemeente Zeist WOO",
    "description": "Public WOO requests widget",
    "subject": {"type": "widget", "id": "woo-widget-uuid"},
    "scope": {"mode": "read", "allowedFilters": ["status"], "allowedActions": []},
    "hostOrigins": ["https://intranet.zeist.nl"],
    "rateLimitPolicy": {"requestsPerMinute": 600, "burstSize": 60}
  }
  ```
THEN the response SHALL be 201 with body:
  ```json
  {
    "id": "token-id-uuid",
    "name": "Gemeente Zeist WOO",
    "jwt": "eyJhbGciOiJSUzI1NiIsImtpZCI6InJzYS0xIn0...",
    "warning": "keepThisSafe: This JWT is shown only once. Save it now. If lost, you must re-issue the token.",
    "createdAt": "2026-05-22T14:30:00Z"
  }
  ```
  AND the response body SHALL include the raw JWT exactly once
  AND a new row SHALL be created in `embed_token` register with:
    - `id` matching the response
    - `tokenHash` = sha256("eyJhbGciOiJSUzI1NiIsImtpZCI6InJzYS0xIn0...")
    - ALL other fields matching the request + createdAt

### Scenario 1.2 — Subsequent GET returns metadata but not JWT

GIVEN an existing `embed_token` row with id="token-1" and tokenHash set
WHEN any GET request to `/api/embed-tokens/token-1` is made
THEN the response SHALL be 200 with body:
  ```json
  {
    "id": "token-1",
    "name": "Gemeente Zeist WOO",
    "description": "Public WOO requests widget",
    "subject": {"type": "widget", "id": "woo-widget-uuid"},
    "scope": {"mode": "read", ...},
    "hostOrigins": ["https://intranet.zeist.nl"],
    "createdAt": "2026-05-22T14:30:00Z",
    "createdBy": "admin-user-id",
    "actions": [
      {"id": "re-issue", "label": "Re-issue token", "description": "Revoke this token and generate a new one"}
    ]
  }
  ```
  AND the response SHALL NOT include the `jwt` field
  AND the response SHALL NOT include the `tokenHash` field

### Scenario 1.3 — Token creation rejects malformed hostOrigins

GIVEN an admin user with token-management permission
WHEN they POST `/api/embed-tokens` with `hostOrigins` containing a malformed origin:
  ```json
  {
    "name": "Bad Token",
    "hostOrigins": ["https://www.example.com", "invalid-origin-missing-scheme"],
    ...
  }
  ```
THEN the response SHALL be 400 with body:
  ```json
  {
    "status": "error",
    "error": "invalid_hostOrigins",
    "message": "Invalid origin format at index 1: missing URI scheme. Expected format: https://domain.com",
    "fieldErrors": {
      "hostOrigins[1]": "invalid_hostOrigins"
    }
  }
  ```
  AND the `embed_token` row SHALL NOT be persisted

### Scenario 1.4 — Re-issue token (revoke + create new)

GIVEN an existing `embed_token` row with id="token-1"
WHEN an admin invokes the "re-issue" action via POST `/api/embed-tokens/token-1/re-issue`
THEN a new JWT SHALL be generated (signed with active key)
  AND the old token's `revokedAt` SHALL be set to now
  AND the old token's `revocationReason` SHALL be set to "re-issued"
  AND a new `embed_token` row SHALL be created with:
    - fresh `id` (new UUID)
    - fresh `tokenHash` (sha256 of new JWT)
    - same `subject`, `scope`, `hostOrigins`, `rateLimitPolicy` as the old token
    - new `createdAt` and `createdBy`
  AND the response SHALL include the new JWT (shown once)

---

## REQ-EMB-002 — Public render route validates JWT and enforces scope

The render route SHALL verify the JWT signature against the `kid`-selected `signing_key`, reject expired or revoked tokens, enforce `subject` match, and respond with 403 on any mismatch without leaking the existence of the subject.

### Scenario 2.1 — Valid non-expired token renders widget

GIVEN a valid, non-expired, non-revoked JWT for widget W signed with RS256 key (kid=rsa-1)
  AND the embedded widget renders HTML (no token scope restriction assumed yet — REQ-EMB-004 covers scope enforcement)
WHEN the route is called with `GET /apps/mydash/embed/widget/W?token=<jwt>`
THEN the response SHALL be 200 with HTML body
  AND the response SHALL include header `Content-Security-Policy: frame-ancestors <hostOrigins>`
  AND the response SHALL include header `X-Content-Type-Options: nosniff`
  AND the widget SHALL render with the token's context applied (e.g., filter pinning from `subject.filterContext`)
  AND a `embed_usage_event` row SHALL be written with:
    - `eventType: "pageView"`
    - `responseStatusCode: 200`
    - `responseLatencyMs: <measured>`

### Scenario 2.2 — Subject mismatch returns 403 without leaking existence

GIVEN a valid JWT for widget W
  AND the admin tries to render it against widget V (different UUID)
WHEN the route is called with `GET /apps/mydash/embed/widget/V?token=<jwt>`
THEN the response SHALL be 403 with body:
  ```json
  {
    "error": "scope_mismatch",
    "message": "The provided token does not grant access to this resource"
  }
  ```
  AND the response SHALL NOT indicate whether widget V exists or not
  AND a `embed_usage_event` row SHALL be written with:
    - `eventType: "pageView"`
    - `responseStatusCode: 403`

### Scenario 2.3 — Expired token returns 401 with WWW-Authenticate header

GIVEN a JWT whose `tokenExpiresAt` is in the past (e.g., "2026-01-01T00:00:00Z" and current time is 2026-05-22)
WHEN the route is called with `GET /apps/mydash/embed/widget/W?token=<jwt>`
THEN the response SHALL be 401 with body:
  ```json
  {
    "error": "token_expired",
    "message": "The provided token has expired"
  }
  ```
  AND the response SHALL include header:
    `WWW-Authenticate: Bearer error="invalid_token", error_description="The token has expired"`
  AND a `embed_usage_event` row SHALL be written with `responseStatusCode: 401`

### Scenario 2.4 — Revoked token returns 401 with constant-time comparison

GIVEN a JWT whose `embed_token` row has `revokedAt` set (token was revoked 5 minutes ago)
WHEN the route is called with `GET /apps/mydash/embed/widget/W?token=<jwt>`
THEN the response SHALL be 401 with body:
  ```json
  {
    "error": "token_revoked",
    "message": "The provided token has been revoked"
  }
  ```
  AND the response latency SHALL be constant-time-comparable to a non-revoked rejection (to prevent timing oracle attacks)
  AND a `embed_usage_event` row SHALL be written with `responseStatusCode: 401`

### Scenario 2.5 — Invalid JWT signature returns 401

GIVEN a JWT with an invalid signature (e.g., tampered payload)
WHEN the route is called with `GET /apps/mydash/embed/widget/W?token=<tampered-jwt>`
THEN the response SHALL be 401 with body:
  ```json
  {
    "error": "invalid_token",
    "message": "The provided token is invalid or malformed"
  }
  ```

### Scenario 2.6 — Missing token returns 401

GIVEN a request to the embed render route without a token
WHEN the route is called with `GET /apps/mydash/embed/widget/W` (no ?token= parameter)
THEN the response SHALL be 401 with body:
  ```json
  {
    "error": "missing_token",
    "message": "No token provided. Include ?token=<jwt> or use the Authorization: Bearer <jwt> header"
  }
  ```

### Scenario 2.7 — Bearer header token (SDK form-factor)

GIVEN a valid JWT passed via Authorization header (SDK use case)
WHEN the route is called with `GET /apps/mydash/embed/widget/W` + header `Authorization: Bearer <jwt>`
THEN the response SHALL be 200 with HTML body (same as Scenario 2.1)
  AND the response latency/rate-limit treatment SHALL be identical to query-parameter tokens
  AND the response CSP/cache headers SHALL be identical

### Scenario 2.8 — Query-parameter tokens enforce stricter rate limits

GIVEN a token with `rateLimitPolicy: {requestsPerMinute: 600, burstSize: 60}`
  AND requests arriving via `?token=<jwt>` (query parameter)
  AND requests arriving via `Authorization: Bearer <jwt>` (header)
WHEN both form-factors are used for the same embed in the same minute
THEN the query-parameter form SHALL count against the token's configured rate limit
  AND the Bearer-header form MAY have a separate (stricter) rate limit (e.g., 10× lower) to account for the token leak risk
  AND the admin UI SHOULD surface this distinction in the token-creation flow
