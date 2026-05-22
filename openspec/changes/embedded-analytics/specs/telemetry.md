# Spec — Usage Telemetry (REQ-EMB-008)

## REQ-EMB-008 — Usage telemetry without leaking PII

The system SHALL persist `embed_usage_event` rows for every page-view and interaction, SHALL capture `hostOrigin`, `userAgent` (truncated to family + major version + hashed), and `viewportSize` (bucketed), and SHALL NOT capture full IP addresses, full user-agents, or any identifier of the host-page's logged-in user.

### Scenario 8.1 — Page-view telemetry capture

GIVEN an embed page-view request from `https://www.zeist.nl/woo/` (host origin)
  AND the user's user-agent: `Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36`
  AND the viewport size: 1920×1080 pixels
WHEN the render route processes the request and returns 200
THEN an `embed_usage_event` row SHALL be written with:
  ```json
  {
    "id": "event-uuid",
    "tokenId": "token-id",
    "eventType": "pageView",
    "hostOrigin": "https://www.zeist.nl",
    "userAgent": "Chrome 124",
    "userAgentHash": "sha256(Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36)",
    "viewportSize": "large",
    "responseStatusCode": 200,
    "responseLatencyMs": 87,
    "timestamp": "2026-05-22T14:30:00Z",
    "correlationId": "correlation-uuid"
  }
  ```

### Scenario 8.2 — Viewport size bucketing

GIVEN viewport widths:
  - 0–767px (small)
  - 768–1279px (medium)
  - 1280px+ (large)
WHEN embed requests arrive with various viewport sizes
THEN the event's `viewportSize` field SHALL be bucketed:
  ```json
  [
    {"width": 480, "height": 720, "viewportSize": "small"},
    {"width": 1024, "height": 768, "viewportSize": "medium"},
    {"width": 1920, "height": 1080, "viewportSize": "large"}
  ]
  ```

### Scenario 8.3 — NO IP address capture

GIVEN a request arriving with headers:
  ```
  X-Forwarded-For: 203.0.113.42
  X-Real-IP: 203.0.113.42
  ```
WHEN the embed usage event is written
THEN the event row SHALL NOT include:
  - Client IP address
  - X-Forwarded-For header value
  - Any IP-based geolocation
AND the only "location" data is the `hostOrigin` (domain, not geolocation)

### Scenario 8.4 — User-agent family + major version only

GIVEN user-agents:
  ```
  Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0.6367.60
  Mozilla/5.0 (iPhone; CPU iPhone OS 17_4_1 like Mac OS X) Mobile Safari/604.1
  Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0
  ```
WHEN events are written
THEN the `userAgent` field SHALL be truncated to family + major:
  ```json
  [
    {"userAgent": "Chrome 124"},
    {"userAgent": "Safari 17"},
    {"userAgent": "Firefox 125"}
  ]
  ```
AND the full user-agent string is hashed and stored separately in `userAgentHash` for analytics without fingerprinting

### Scenario 8.5 — NO end-user identifier in events

GIVEN a host page that is embedded in a SaaS portal, where the logged-in user is "customer-123"
  AND the MyDash request is made in the context of that user
WHEN the embed usage event is written
THEN the event row SHALL NOT include:
  - User ID from the host page (even if passed in a header)
  - Email address of the host-page's logged-in user
  - Account name or subscription tier
AND the only identifier is the `tokenId` (which widget/dashboard was embedded, not who viewed it)

### Scenario 8.6 — Host origin stored (not referrer, not full URL)

GIVEN a request from `https://www.zeist.nl/woo/requests-2026-05/?sort=date&limit=50`
WHEN the event is written
THEN the `hostOrigin` field SHALL be:
  ```json
  {"hostOrigin": "https://www.zeist.nl"}
  ```
NOT:
  - The full URL (path+query stripped)
  - The Referer header (may be empty or stripped by proxies)
  - Derived from IP geolocation

### Scenario 8.7 — Interaction events also captured

GIVEN the user interacts with an embedded widget (filter applied, drill-down, export)
WHEN the interaction is processed
THEN an `embed_usage_event` SHALL be written with:
  ```json
  {
    "eventType": "filterApplied",
    "payload": {
      "dimension": "status",
      "value": "openstaande"
    },
    "hostOrigin": "https://www.zeist.nl",
    "userAgent": "Chrome 124",
    "viewportSize": "large",
    "responseStatusCode": 200,
    "timestamp": "2026-05-22T14:30:15Z"
  }
  ```
OR:
  ```json
  {
    "eventType": "export",
    "payload": {
      "format": "csv"
    },
    ...
  }
  ```

### Scenario 8.8 — Usage report in admin UI

GIVEN an admin accessing the usage report for a token
WHEN they view the analytics dashboard
THEN they SHALL see:
  ```
  Pageviews
  ├─ Total (last 7 days): 1,243 views
  ├─ By date:
  │  └─ 2026-05-22: 324 views
  │  └─ 2026-05-21: 289 views
  │  └─ 2026-05-20: 215 views
  ├─ By host origin:
  │  └─ https://www.zeist.nl: 1,100 views
  │  └─ https://intranet.zeist.nl: 143 views
  └─ By viewport size:
     └─ large: 601 views
     └─ medium: 432 views
     └─ small: 210 views
  
  Interactions
  ├─ filterApplied: 87 times
  ├─ drillDown: 45 times
  └─ export: 12 times
  
  Browsers
  ├─ Chrome 124: 623 views
  ├─ Safari 17: 312 views
  └─ Firefox 125: 308 views
  ```

### Scenario 8.9 — No individual-user identifiers in report

GIVEN the admin viewing the usage report
WHEN they inspect the data
THEN they SHALL NOT see:
  - List of end-users who viewed the embed
  - User names or email addresses
  - Timestamps per individual user (only aggregated by day)
  - Correlation of two views to the same person (no fingerprinting via UA hash + viewport)
AND the report answers only: "Did this embed get traffic?" and "From where?"

### Scenario 8.10 — Org-level telemetry kill-switch

GIVEN an admin with org configuration access
WHEN they toggle "Disable embed usage telemetry" in org settings
THEN:
  - No `embed_usage_event` rows are written for any subsequent requests
  - Existing events are NOT deleted (audit trail remains)
  - The toggle is auditable (audit log records who disabled telemetry and when)
  - Re-enabling telemetry immediately resumes event capture

### Scenario 8.11 — Data retention policy

GIVEN the org's data retention policy (e.g., "keep events for 90 days")
WHEN 90 days have passed since an `embed_usage_event` was created
THEN a background job SHALL delete the old event
  AND the event is permanently removed (not soft-deleted; no recovery)
  AND the deletion is logged in the audit trail

### Scenario 8.12 — GDPR/AVG data subject access (not applicable)

GIVEN a data subject (end-user) requesting access to their personal data per GDPR Article 15
WHEN the org's DPO runs the data-subject-access query
THEN the system SHALL respond:
  ```
  No personal data on record for this data subject.
  Reasoning: Embed telemetry captures only host origin and viewport size.
  No user identifiers, emails, IP addresses, or other traceable personal data are stored.
  The only entity involved is the embed token (issued by the org administrator to a third-party host),
  not personal data of an end-user.
  ```
AND the org does NOT need to include embed telemetry in GDPR access response letters
