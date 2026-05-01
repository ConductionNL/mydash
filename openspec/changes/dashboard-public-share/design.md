# Design — Public-share render

## Context

The `dashboard-public-share` spec introduces anonymous read-only rendering of dashboards via URL-safe tokens with optional password protection, expiry, and view tracking. No Nextcloud account is required; a share token in the URL is sufficient to render the dashboard.

Before writing the spec, we analysed the corresponding feature in a commercially available reference application to understand battle-tested throttle patterns. That analysis surfaced a concrete behavioural discrepancy: the spec assumed **per-share-per-IP** throttling (i.e., failed unlock attempts are tracked per `(token, IP)` pair), whereas the reference implementation tracks failed attempts **per-IP globally across all share actions**, using a single fixed action name with no token component.

The discrepancy matters because the two models have different properties under attack. IP-global throttling stops broad scanning (one attacker, many targets) earlier, but also risks blocking a whole office or school behind a shared NAT if any one person hits the limit. Per-share throttling is more granular — the limit for share A doesn't bleed into share B — but is easier to bypass by creating many shares and spreading attempts across them.

This design document records the decisions made, their rationale, and the resulting changes required in the spec.

## Goals / Non-Goals

**Goals:**
- Decide the throttle scope (IP-global vs per-share-per-IP) and document it with rationale.
- Nail down the two distinct action buckets and their concrete limits.
- Fix the HTTP response code for throttle trips (spec said 503; NC convention says 429).
- Specify the service-account read path for GroupFolder-backed dashboard content.
- Confirm the view-count debounce key and mechanism.

**Non-Goals:**
- Designing the share-creation rate limit (admin-only endpoint; separate concern).
- Defining the full data model or migration — those live in `proposal.md`.
- Specifying the UI for share management — deferred to a follow-up.

## Decisions

### D1: Throttle scope — IP-global vs per-share-per-IP

**Decision**: IP-global across all share actions, split into two action buckets:
- `mydash_share_access` — page-render failures, limit 60 req/60 s per IP
- `mydash_share_password` — wrong-password submissions, limit 10 req/60 s per IP

Both buckets apply regardless of which share token is targeted. This matches the reference implementation.

**Alternatives considered:**
- **Per-share-per-IP** (the spec's original assumption): the throttle key would include the share token, e.g., `mydash_share_password:{token}`. Rejected for v1 because it requires a custom throttle key instead of NC's stock `IThrottler` action names, adds code complexity, and the "many-shares bypass" attack it prevents is already substantially mitigated by the share-creation endpoint being owner-or-admin only (an anonymous attacker cannot create new shares).

**Rationale**: NC's `IThrottler` + `BruteForceProtection` are designed around fixed action strings and give administrators a well-understood knob. IP-global throttling reliably stops broad scanning attacks — an attacker trying many share tokens from the same IP hits the ceiling quickly. The NAT collision trade-off is real but acceptable: the threshold (60 general / 10 password per minute) is high enough that legitimate users behind shared NAT are unlikely to collide, and the attack surface for a targeted single-share brute force is limited by the BCrypt cost of password verification. Keeping v1 simple and auditable outweighs the marginal gain of per-share scoping.

**Source evidence**:
- `intravox-source/lib/Controller/PageController.php:107, 197, 271` — `#[BruteForceProtection(action: 'intravox_share_access')]` and `#[BruteForceProtection(action: 'intravox_share_password')]` — single fixed action names with no token interpolation
- `intravox-source/lib/Controller/PageController.php:106, 196, 270` — `#[AnonRateThrottle(limit: 60, period: 60)]` (general access) and `#[AnonRateThrottle(limit: 10, period: 60)]` (password) — also IP-global
- `intravox-source/lib/Controller/PageController.php:318-323` — `registerBruteForceAttempt()` registers the IP without any per-share scoping

### D2: Throttle action names — `mydash_*` prefix

**Decision**: Use `mydash_share_access` and `mydash_share_password` (not the source app's names).

**Rationale**: Nextcloud's `IThrottler` stores attempt counters keyed by action string. Using an app-prefixed name prevents counter collisions if another app's throttle action happened to share the same string, and makes the counter's origin unambiguous in the NC admin throttle log.

### D3: Brute-force lockout response code

**Decision**: When the throttle trips, return HTTP 429 (Too Many Requests) with NC's standard `IThrottler` JSON body. Not HTTP 503.

**Rationale**: HTTP 429 is the correct semantic for rate limiting (RFC 6585). NC's own `ThrottlingMiddleware` returns 429; using 503 would be inconsistent with NC conventions and would mislead monitoring systems that treat 5xx as server errors. The spec's existing scenario (REQ-PSHR-009) currently states 503 and must be corrected.

### D4: Service-account read for GroupFolder-backed shares (REQ-PSHR-010)

**Decision**: A successful public-share render of a dashboard whose content lives in the GroupFolder backend MUST NOT use the requesting client's session for file ACL evaluation. The controller MUST obtain the GroupFolder content via a system/service-account context so that the GroupFolder ACL bypass mechanism grants access through the share token, not through a session that does not exist for anonymous users.

**Rationale**: Anonymous users have no Nextcloud session. Passing a null or non-existent user context to GroupFolder's ACL evaluator causes the read to fail silently or return empty widget data. A service-account read path is the only correct mechanism; it is already established in the sibling `groupfolder-storage-backend` spec.

**Source evidence**: The reference implementation's page controller resolves GroupFolder resources through the share record itself rather than through `getUserFolder()` on the current session, keeping file access decoupled from the viewer's identity.

### D5: Per-IP debouncing of view-count increments (REQ-PSHR-007)

**Decision**: View-count is incremented at most once per `(token, IP)` pair per 60-second window. Implemented via NC `ICache` with TTL 60 s. Key pattern: `mydash_vc_{token}_{ip}`. Matches the spec's existing assumption — no change required to the requirement, only the implementation detail is pinned here.

**Rationale**: Database-level debounce (checking `lastViewedAt`) is not thread-safe under concurrent requests; cache-level TTL keys give atomic set-if-absent semantics without a transaction.

## Spec changes implied

- **REQ-PSHR-009 (throttle scope)**: rewrite the "Throttle is per-IP per-share" scenario to specify IP-global semantics; remove the `SHOULD be tracked per-share (implementation may vary)` hedge.
- **REQ-PSHR-009 (action names)**: change any source-app action name references to `mydash_share_access` and `mydash_share_password`; add the concrete limits (60/60 s and 10/60 s).
- **REQ-PSHR-009 (response code)**: replace HTTP 503 with HTTP 429 in the throttle-trip scenario.
- **REQ-PSHR-010 (service-account read)**: add a NOTE specifying that the service-account context must be obtained before any GroupFolder file-system call, and that the anonymous viewer's session MUST NOT be passed to the ACL evaluator.

## Open follow-ups

- Whether to add a separate share-creation rate limit (owner-only endpoint, less critical but cheap to add at the controller layer).
- Whether `?password=<...>` query-param submission (vs `X-Share-Password` header) should be explicitly supported or removed — browser history leaks the password in the query-param form; the current spec permits both but does not flag the security trade-off.
