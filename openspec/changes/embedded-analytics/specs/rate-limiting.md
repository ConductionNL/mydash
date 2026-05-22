# Spec — Rate Limiting and Request Budgeting (REQ-EMB-007)

## REQ-EMB-007 — Rate limiting and request budgeting per token

The system SHALL apply the token's `rateLimitPolicy` to all requests, return 429 with a `Retry-After` header when the bucket is exhausted, and write a `embed_usage_event` of type `pageView` even on rate-limited responses for budgeting analysis.

### Scenario 7.1 — Token-bucket rate limiting with burst

GIVEN a token with `rateLimitPolicy={requestsPerMinute: 60, burstSize: 10}`
  AND the current time is T=0
WHEN 70 requests arrive between T=0 and T=10 seconds (10 seconds into the first minute)
THEN:
  - Requests 1-10 SHALL be processed (up to burst size)
  - Requests 11-70 SHALL receive 429 responses with `Retry-After: 50` (seconds until next bucket refill)
  AND each 429 response SHALL include an `embed_usage_event` with `responseStatusCode: 429`
  AND after 60 seconds (one full minute), the bucket is refilled and new requests can be processed

### Scenario 7.2 — Org-default rate limit for tokens without policy

GIVEN a token with `rateLimitPolicy: null` (no custom policy specified)
WHEN requests arrive
THEN the org-level default policy SHALL apply:
  ```json
  {
    "requestsPerMinute": 600,
    "burstSize": 60
  }
  ```
  AND the token operates under these defaults (high-throughput default for typical embeds)

### Scenario 7.3 — 429 response format

GIVEN a request that exceeds the rate limit
WHEN the render route processes it
THEN the response SHALL be 429 with:
  ```
  HTTP/1.1 429 Too Many Requests
  Content-Type: application/json
  Retry-After: 45
  
  {
    "error": "rate_limit_exceeded",
    "message": "Rate limit exceeded. The token allows 60 requests per minute. Try again in 45 seconds.",
    "retryAfter": 45,
    "resetAt": "2026-05-22T14:31:00Z"
  }
  ```

### Scenario 7.4 — Rate-limited request logged in usage events

GIVEN a request that hits the rate limit
WHEN the 429 response is returned
THEN an `embed_usage_event` row SHALL be written with:
  ```json
  {
    "id": "event-uuid",
    "tokenId": "token-id",
    "eventType": "pageView",
    "hostOrigin": "https://www.zeist.nl",
    "userAgent": "Chrome 124 / sha256(...)",
    "viewportSize": "medium",
    "timestamp": "2026-05-22T14:30:05Z",
    "responseStatusCode": 429,
    "responseLatencyMs": 12,
    "correlationId": "correlation-uuid"
  }
  ```
  AND the admin can query: "This token hit rate limits 45 times on 2026-05-22 between 14:00 and 15:00"

### Scenario 7.5 — Bucket refill and sliding window

GIVEN a token with `requestsPerMinute: 60, burstSize: 10` (1 req/sec, burst 10)
  AND 8 requests have been consumed at T=0
WHEN new requests arrive at T=30 seconds (midway through the first minute)
THEN:
  - 30 seconds of refill grants 30 requests (60 rpm ÷ 60 sec × 30 sec)
  - Bucket now has: 10 (burst) + 30 (refilled) - 8 (used) = 32 capacity
  - New request consumes 1, bucket now 31
  AND this is a token-bucket algorithm, not a fixed sliding window (continuous refill, not window resets)

### Scenario 7.6 — Query-parameter vs Bearer-header rate limit distinction

GIVEN a token with `rateLimitPolicy: {requestsPerMinute: 600, burstSize: 60}`
WHEN requests arrive via `?token=<jwt>` (query parameter)
THEN the query-parameter form MAY enforce a stricter limit:
  - Override to 10× lower: 60 rpm, burst 6 (token leak risk warrants stricter throttling)
  AND requests via `Authorization: Bearer <jwt>` (header) use the configured limit (600 rpm, burst 60)
  AND the admin UI displays this distinction when creating a token:
    ```
    Query-parameter form: up to 60 requests/minute (stricter for security)
    Bearer header form: up to 600 requests/minute (as configured)
    ```

### Scenario 7.7 — Rate limit policy updateable after token creation

GIVEN an existing token with `rateLimitPolicy: {requestsPerMinute: 60, burstSize: 10}`
WHEN an admin invokes `PUT /api/embed-tokens/token-id` with a new policy:
  ```json
  {
    "rateLimitPolicy": {
      "requestsPerMinute": 120,
      "burstSize": 15
    }
  }
  ```
THEN the API SHALL respond 200 and update the token
  AND the new rate limit applies to subsequent requests immediately
  AND in-flight requests (between update and next request) use the old policy (best-effort; eventual consistency is acceptable)

### Scenario 7.8 — Rate limit exhaustion analysis

GIVEN the admin accessing the usage report for a token
WHEN they view the analytics dashboard
THEN they SHALL see:
  ```
  Rate Limit Breaches
  ├─ 2026-05-22: 145 requests rejected (60 expected)
  │  Time: 14:00–15:00 (busiest hour)
  │  Breach threshold: 600 rpm → hit 700 rpm
  ├─ 2026-05-21: 23 requests rejected
  └─ 2026-05-20: 0 requests rejected
  
  Recommendation: Consider raising requestsPerMinute to 1200
  ```
  AND a button "Adjust rate limit" opens the token edit dialog

### Scenario 7.9 — Alert configuration (optional v1+)

GIVEN an admin who wants to monitor token health
WHEN they configure alerts on a token (v1.1 feature, out of scope here but schema-ready):
  ```json
  {
    "alertPolicy": {
      "thresholdPercentage": 80,
      "notifyEmail": "ops@gemeente.nl"
    }
  }
  ```
THEN when the token reaches 80% of its rate limit in a given minute, an email alert is sent
  (This is a note for future expansion; not implemented in v1, but the schema supports it)

### Scenario 7.10 — Rate limit headers in all responses

GIVEN any response from the embed render route
WHEN the response is generated
THEN the response SHALL include rate-limit headers (RFC 6585 style):
  ```
  RateLimit-Limit: 60
  RateLimit-Remaining: 45
  RateLimit-Reset: 2026-05-22T14:31:00Z
  ```
  AND the host page / SDK can read these headers to show a rate-limit warning to the user
