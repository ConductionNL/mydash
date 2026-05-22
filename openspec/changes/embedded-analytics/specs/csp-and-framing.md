# Spec — CSP and Framing Control (REQ-EMB-003)

## REQ-EMB-003 — CSP frame-ancestors enforced from token hostOrigins

The render route SHALL emit `Content-Security-Policy: frame-ancestors <hostOrigins>` derived from the token's `hostOrigins`, and the embed SHALL refuse to render when the iframe's actual top-level origin is not in `hostOrigins`. This is enforced both at the CSP level (modern browsers) and via Fetch Metadata headers (defence in depth).

### Scenario 3.1 — Valid origin renders with CSP frame-ancestors header

GIVEN a token with `hostOrigins=["https://www.zeist.nl"]`
  AND the token is for widget W
WHEN the embed is loaded inside an iframe on `https://www.zeist.nl/woo/`
THEN the response to `GET /apps/mydash/embed/widget/W?token=<jwt>` SHALL be 200 with HTML body
  AND the response SHALL include header:
    `Content-Security-Policy: frame-ancestors https://www.zeist.nl`
  AND the browser SHALL render the iframe normally (CSP allows framing from https://www.zeist.nl)

### Scenario 3.2 — Invalid origin blocked by CSP + server-side Sec-Fetch-Site check

GIVEN the same token with `hostOrigins=["https://www.zeist.nl"]`
WHEN the iframe is loaded from `https://evil.example.com/phishing-page`
  AND the iframe's top-level origin is `https://evil.example.com`
THEN the browser SHALL refuse to display the iframe due to CSP frame-ancestors violation
  AND the server-side render route SHALL check the `Sec-Fetch-Site` header:
    - If `Sec-Fetch-Site: cross-site` and top-origin from Referer ≠ allowed hostOrigins
    - Then respond 403 with body `{error: "origin_mismatch", message: "This iframe cannot be embedded on this origin"}`
  AND the browser still refuses to render (CSP violation takes precedence)
  AND a `embed_usage_event` SHALL be written with `responseStatusCode: 403`

### Scenario 3.3 — Multiple allowed origins

GIVEN a token with `hostOrigins=["https://intranet.zeist.nl", "https://www.zeist.nl"]`
WHEN the embed is loaded from either origin
THEN the response SHALL include header:
  `Content-Security-Policy: frame-ancestors https://intranet.zeist.nl https://www.zeist.nl`
  AND the browser SHALL allow framing from either origin

### Scenario 3.4 — Wildcard hostOrigins rejected at token creation

GIVEN an admin user
WHEN they attempt to POST `/api/embed-tokens` with `hostOrigins=["*"]`
THEN the response SHALL be 400 with body:
  ```json
  {
    "status": "error",
    "error": "invalid_hostOrigins",
    "message": "Wildcard origin '*' is not permitted. Specify explicit origins, e.g., [\"https://www.example.com\"]",
    "fieldErrors": {
      "hostOrigins": "wildcard_not_permitted"
    }
  }
  ```
  AND the token SHALL NOT be created

### Scenario 3.5 — X-Frame-Options fallback for legacy browsers

GIVEN any render route response
WHEN the response is generated
THEN the response SHALL ALSO include header:
  `X-Frame-Options: SAMEORIGIN`
  (As a fallback for browsers that do not support CSP Level 3; no protective claim is made about X-Frame-Options sufficiency on modern browsers per W3C spec)

### Scenario 3.6 — Referer header check (defence in depth)

GIVEN a request with `Authorization: Bearer <jwt>` (Bearer header, not query param)
  AND the token's `hostOrigins=["https://intranet.example.com"]`
WHEN the render route processes the request
  AND the `Referer` header is `https://evil.example.com/page`
THEN the route SHALL respond 403 with body `{error: "origin_mismatch"}`
  AND a `embed_usage_event` SHALL be written with `responseStatusCode: 403`

### Scenario 3.7 — No Referer header (privacy-preserving browser configuration)

GIVEN a request with no `Referer` header
  AND the `Sec-Fetch-Site` header indicates `same-site`
THEN the route SHALL allow the request (assume same-origin; Referer is not available in privacy-preserving configurations)
  AND a `embed_usage_event` SHALL be written with `responseStatusCode: 200`

### Scenario 3.8 — Admin UI displays CSP header preview

GIVEN the admin is creating or editing an embed token
WHEN they edit the `hostOrigins` field
THEN the admin UI SHALL display a live preview of the resulting CSP header:
  ```
  Content-Security-Policy: frame-ancestors https://intranet.zeist.nl
  ```
  AND a "Copy CSP header" button SHALL let them paste this into their host website's audit documentation
