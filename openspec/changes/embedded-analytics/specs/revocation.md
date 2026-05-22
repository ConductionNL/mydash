# Spec — Token Revocation (REQ-EMB-009)

## REQ-EMB-009 — Token revocation takes effect within 60 seconds

The system SHALL revoke a token by setting `revokedAt`, propagate the revocation to all render-route workers within 60 seconds via a short-TTL cache, and reject subsequent render requests with 401.

### Scenario 9.1 — Revocation via admin UI

GIVEN an admin viewing an embed token's detail page
WHEN they click the "Revoke" button
THEN the admin UI SHALL display a confirmation dialog:
  ```
  Revoke token "Gemeente Zeist WOO"?
  
  [Dropdown] Revocation reason:
  ├─ Compromised
  ├─ No longer needed
  ├─ Host website decommissioned
  └─ Other...
  
  [Text field] Additional notes (optional):
  "Gemeente decided to move WOO tracking in-house"
  
  [Cancel] [Confirm Revoke]
  ```
WHEN they select a reason and confirm
THEN the UI SHALL POST `/api/embed-tokens/token-id/revoke` with:
  ```json
  {
    "reason": "no_longer_needed",
    "notes": "Gemeente decided to move WOO tracking in-house"
  }
  ```

### Scenario 9.2 — Revocation persisted in token record

GIVEN the revoke request
WHEN the API processes it
THEN the `embed_token` row SHALL be updated with:
  ```json
  {
    "id": "token-id",
    "name": "Gemeente Zeist WOO",
    ...
    "revokedAt": "2026-05-22T14:35:00Z",
    "revocationReason": "no_longer_needed",
    "revocationNotes": "Gemeente decided to move WOO tracking in-house",
    "revokedBy": "admin-user-id"
  }
  ```
  AND the response SHALL be 200 with the updated token metadata
  AND a notification SHALL be sent to the token's `createdBy` user:
    ```
    Token "Gemeente Zeist WOO" has been revoked by admin-user.
    Reason: no_longer_needed
    Notes: Gemeente decided to move WOO tracking in-house
    Revocation timestamp: 2026-05-22T14:35:00Z
    ```

### Scenario 9.3 — Render-route workers reject revoked token within 60 seconds

GIVEN a revoked token (revokedAt set 5 seconds ago)
  AND multiple render-route worker processes running
WHEN any request arrives with the revoked token
THEN within 60 seconds (the cache propagation window), ALL workers SHALL:
  1. Check their revocation cache
  2. If the token is in the cache as revoked, respond 401
  3. If the token is NOT in the cache yet (slow propagation), query the database:
     ```sql
     SELECT revokedAt FROM embed_token WHERE id = ? LIMIT 1;
     ```
  4. If `revokedAt IS NOT NULL`, respond 401 and update the local cache
  5. Cache the revocation status for 60 seconds (TTL)
AND the response SHALL be 401 with:
  ```json
  {
    "error": "token_revoked",
    "message": "The provided token has been revoked"
  }
  ```

### Scenario 9.4 — Usage event written even for revoked token

GIVEN a revoked token used after revocation
WHEN the render route returns 401
THEN an `embed_usage_event` SHALL be written with:
  ```json
  {
    "eventType": "pageView",
    "responseStatusCode": 401,
    "responseLatencyMs": 5,
    "timestamp": "2026-05-22T14:36:30Z"
  }
  ```
AND the admin can query the usage report:
  "Token 'Gemeente Zeist WOO' was used 23 times AFTER revocation (2026-05-22 14:35–14:45)"
AND this helps forensic review of potential security incidents

### Scenario 9.5 — Cache propagation guarantees

GIVEN a render-route worker A and worker B
WHEN a token is revoked at T=0
AND worker A immediately refreshes its cache from the database
AND worker B is busy and does not refresh for 50 seconds
THEN:
  - Worker A rejects the token starting at T=1 second
  - Worker B continues accepting the token until T=50 seconds
  - At T=60 seconds, worker B's cache expires and it must query the database
  - Worker B then rejects the token starting at T=61 seconds
AND the 60-second window bounds the maximum delay across all workers

### Scenario 9.6 — Purge cache action for critical incidents

GIVEN a critical incident (token compromised, attacker active)
WHEN an admin clicks "Purge cache" on the revoked token's detail page
THEN the API SHALL:
  1. Immediately invalidate all render-route worker caches for this token
  2. Send a broadcast message to all workers (Redis, AMQP, or similar):
     ```json
     {
       "type": "token_cache_purge",
       "tokenId": "token-id",
       "timestamp": "2026-05-22T14:35:30Z"
     }
     ```
  3. All workers receive the message and drop the token from their cache
  4. The next request with the revoked token queries the database and is rejected immediately
AND the response shows:
  ```
  Cache purged. All render workers will immediately reject this token.
  Purge initiated at 2026-05-22T14:35:30Z.
  ```

### Scenario 9.7 — Re-issue (new token, revoke old)

GIVEN an admin who has lost a token or wants to refresh it
WHEN they invoke the "Re-issue" action on the token
THEN the system SHALL:
  1. Generate a new JWT
  2. Create a new `embed_token` row with the same subject, scope, hostOrigins, etc.
  3. Revoke the old token (set revokedAt and revocationReason: "re-issued")
  4. Return the new JWT to the admin (shown once)
AND the old token is rejected starting immediately (cache expires within 60s, database rejects immediately)
AND subsequent integration uses the new token

### Scenario 9.8 — Revocation audit trail

GIVEN the audit log for a token
WHEN an admin reviews the token's history
THEN they SHALL see:
  ```
  Audit Trail for Token "Gemeente Zeist WOO"
  ├─ 2026-05-22T14:35:00Z — REVOKED by admin@gemeente.nl
  │  Reason: no_longer_needed
  │  Notes: Gemeente decided to move WOO tracking in-house
  ├─ 2026-05-20T10:00:00Z — ACCESSED 342 times
  │  Host origins: https://intranet.zeist.nl (340), https://www.zeist.nl (2)
  ├─ 2026-05-15T09:30:00Z — CREATED by admin@gemeente.nl
  │  Subject: widget / woo-widget
  │  Scope: read, filters=[status, periode]
  └─ (no further events)
  ```

### Scenario 9.9 — Token state visibility

GIVEN a list of all embed tokens in the admin UI
WHEN the admin views the token table
THEN each row SHALL display:
  - Token name
  - Status: [ACTIVE | REVOKED | EXPIRING SOON]
  - Subject: (widget/dashboard name)
  - Created: 2026-05-15
  - Last used: 2026-05-22 14:30
  - Revoked: (empty if active, or "2026-05-22 14:35" if revoked)
  - Actions: [View] [Edit] [Re-issue] [Revoke] (or [Un-revoke] if revoked — soft-revoke only)

### Scenario 9.10 — Soft revocation (can be undone)

GIVEN a revoked token
WHEN an admin clicks "Undo revocation" (or "Un-revoke")
THEN the system SHALL:
  1. Clear the `revokedAt` and `revocationReason` fields
  2. Set `unrevokedAt` and `unrevokedBy` for audit
  3. Respond 200 with the token's updated state
  4. Send a notification to the token's creator
AND the token is immediately re-activated (cache expires, database accepts)
AND the full history (revocation + un-revocation) is auditable

### Scenario 9.11 — Bulk revocation (future feature, out of scope)

GIVEN multiple tokens that are no longer needed
WHEN an admin selects them and invokes "Revoke selected" (future v1.1 feature)
THEN all tokens are revoked in a single operation
  (This is a note for future; not implemented in v1, but the schema supports bulk operations)

### Scenario 9.12 — Revocation email notification

GIVEN a token that was revoked
WHEN the revocation is confirmed
THEN an email SHALL be sent to the token's creator:
  ```
  Subject: Token Revoked: Gemeente Zeist WOO
  
  Your embed token "Gemeente Zeist WOO" has been revoked.
  
  Revoked by: admin@gemeente.nl
  Reason: no_longer_needed
  Notes: Gemeente decided to move WOO tracking in-house
  Revocation time: 2026-05-22 14:35 UTC
  
  If this was unexpected, contact the administrator.
  ```
