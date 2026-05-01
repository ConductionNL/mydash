# Design — Nextcloud Activity Feed Integration

## Context

The source application implements `OCP\Activity\IProvider` and emits exactly **6** event types through its `parse()` switch: `page_viewed`, `page_created`, `page_updated`, `page_deleted`, `comment_added`, and `reaction_added`. Any other subject throws `\InvalidArgumentException('Unknown subject: ...')`, confirming that set is exhaustive. MyDash ports 5 of these (renamed to the `dashboard_*` domain prefix) and deliberately excludes `page_viewed`.

MyDash ships 8 additional event types that have no source counterpart. These arise naturally from sibling capabilities — dashboard publishing, sharing, public links, versioning, locking, and role management — none of which exist in the source application. The full catalogue of 13 types is therefore a deliberate product decision, not a gap or an error in the spec.

The most significant source deviation is view tracking. The source application emits `page_viewed` to the Activity stream from `AnalyticsService::trackPageView()`, recording a row for every page load. MyDash already owns a dedicated `dashboard-view-analytics` capability for view counting; re-publishing to the Activity stream would generate a row per dashboard load across every audience member, flooding notification inboxes on busy default-group dashboards with no actionable information.

Audience fan-out is the other area requiring deliberate design. A default-group dashboard shared with every authenticated user on a 1 000-user instance generates up to 1 000 `oc_activity` rows per event. Without debouncing, a sequence of rapid edits or a storm of reactions can produce tens of thousands of rows in minutes. Two debounce rules address this at the source.

## Goals / Non-Goals

**Goals:**
- Surface all meaningful MyDash actions in the standard NC Activity feed without custom UI.
- Define a canonical 13-type event catalogue, split clearly into source-derived and MyDash-native groups.
- Specify audience-targeting rules for all four dashboard scopes (personal, user-shared, group-shared, default-group).
- Prevent Activity-stream spam via a 15-minute debounce on high-fan-out events.
- Allow users to opt out of individual event types via standard NC Activity notification preferences.
- Keep emission call-sites inside the sibling capability that owns each action.

**Non-Goals:**
- Do NOT publish view-tracking events (`dashboard_viewed` / `page_viewed`) to the Activity stream. View counts are analytics data, not actionable notifications.
- Do NOT provide a custom MyDash notification preferences page; NC's built-in per-type settings UI is sufficient.
- Do NOT expose a new HTTP endpoint for querying the activity stream; NC's stock Activity REST API surfaces MyDash events automatically once the provider is registered.
- Do NOT backfill historical events that occurred before this capability is installed.

## Decisions

### D1: Event catalogue — 5 source-derived + 8 MyDash additions (13 total)

**Decision**: MyDash emits exactly 13 event-type constants. 5 are direct ports of source event types, renamed to the `dashboard_*` prefix. The remaining 8 emerge from sibling capabilities that have no source-app counterpart. `page_viewed` is excluded (see D2).

**Source-derived (5)**:
- `dashboard_created` (source: `page_created`)
- `dashboard_updated` (source: `page_updated`)
- `dashboard_deleted` (source: `page_deleted`)
- `dashboard_commented` (source: `comment_added`)
- `dashboard_reacted` (source: `reaction_added`)

**MyDash additions (8 — emerge from sibling capabilities)**:
- `dashboard_published`, `dashboard_unpublished`, `dashboard_scheduled` — emitted by `dashboard-draft-published`
- `dashboard_shared` — emitted by `dashboard-sharing`
- `dashboard_public_share_created` — emitted by `dashboard-public-share`
- `dashboard_restored` — emitted by `dashboard-versioning`
- `dashboard_lock_overridden` — emitted by `dashboard-locking`
- `dashboard_role_changed` — emitted by `admin-roles`

**Source evidence**:
- `intravox-source/lib/Activity/Provider.php:43-81` — `parse()` switch handles exactly the 5 source types listed above plus `page_viewed`; any other subject throws `\InvalidArgumentException`.

**Rationale**: The 13-type catalogue is a complete, non-redundant set. Each MyDash addition maps 1-to-1 to a sibling capability that already owns the domain action; adding the emission call-site there keeps responsibility co-located with the action.

---

### D2: `page_viewed` — analytics-only, NOT published to the Activity stream

**Decision**: View tracking is owned exclusively by `dashboard-view-analytics`. `ActivityPublisher` MUST NOT emit a `dashboard_viewed` event. The source app's choice to emit `page_viewed` to Activity is not replicated.

**Alternatives considered**:
- **Match source (emit `dashboard_viewed` to Activity stream)**: rejected. Every dashboard load triggers an event; on a default-group dashboard with 1 000 users that becomes 1 000 rows per page load. Users would receive view notifications for their own dashboards on every login. The `dashboard-view-analytics` capability already captures counts in a purpose-built structure with aggregation; a parallel Activity row adds no user-visible value.

**Source evidence**:
- `intravox-source/lib/Service/AnalyticsService.php::trackPageView()` — emits to Activity AND increments analytics counters; MyDash separates these concerns into distinct capabilities.

**Rationale**: The Activity feed is designed for actions that other users need to react to. A view is a passive event with no required follow-up. Keeping view data in the analytics capability preserves stream signal quality and prevents inbox fatigue, particularly for widely-shared dashboards.

---

### D3: Audience-targeting rules per dashboard scope

**Decision**: The recipient set for any event is determined by the dashboard's scope at emit-time:

| Scope | Recipients |
|---|---|
| `user` (personal) | Owner only |
| User-to-user share | Owner + every named share recipient |
| `group_shared` | All current members of the target group (via `IGroupManager`) |
| Default-group (`groupId = 'default'`) | All authenticated NC users (via `IUserManager::callForAllUsers`) |

The actor always receives their own row (first-person subject template). Third-party recipients receive the third-person variant. The actor MUST NOT receive a duplicate row when they are already a group member.

**Rationale**: Scope determines who has a legitimate interest in knowing about the event. Resolving group membership at emit-time (not at read-time) matches NC's own pattern and keeps `oc_activity` queries simple. Personal-dashboard events are never fan-out events; the guard on `dashboard->getType()` prevents any `IGroupManager` call for them.

---

### D4: Debouncing — 15-minute window for reactions and default-group fan-out

**Decision**: Two event classes are debounced using APCu keys with a 900-second (15-minute) TTL:

1. **`dashboard_reacted`**: debounced per `(actorUserId, dashboardUuid)`. At most one reaction activity row per actor per dashboard per 15-minute window. This prevents emoji-storm patterns where one user rapidly adds/changes reactions.

2. **Default-group fan-out events** (`groupId = 'default'`): for high-frequency types (`dashboard_updated`, `dashboard_commented`), the entire fan-out is debounced per `(dashboardUuid, eventType)`. When the window is active, `IUserManager::callForAllUsers` is NOT called and zero rows are written. Suppressed fan-outs MUST emit a DEBUG-level log entry; no WARNING or ERROR (suppression is expected behaviour).

**Rationale**: A well-meaning admin who edits a default-group dashboard 50 times in an hour would otherwise send 50 000 activity rows across a 1 000-user org. The 15-minute window collapses bursts into one notification cycle, which matches how users actually check their activity feeds. The reaction debounce preserves the informational value (someone reacted) while eliminating noise from iterative emoji selection.

---

### D5: Implementation pattern — single registration class, distributed emission

**Decision**: ONE class (`lib/Activity/Extension.php`) registers all 13 event types with NC's `IProvider` interface, provides subject/message templates, icons, and the `ALL_EVENTS` constant array. Actual `IManager::publishActivity()` call-sites live INSIDE each sibling capability's service (e.g. `dashboard_published` is fired by `DashboardPublicationService::publish()` in the `dashboard-draft-published` implementation).

A thin `lib/Activity/ActivityPublisher.php` wrapper handles audience resolution, debounce checks, and error isolation so sibling capabilities never depend on `IManager` directly.

**Rationale**: Centralising type registration prevents duplication and provides a single source of truth for the catalogue (auditable by reading one file). Distributing emission keeps responsibility co-located with the owning action: the publication service knows when it has persisted a state change; the activity extension does not.

---

### D6: Naming — `dashboard_*` prefix throughout

**Decision**: All 13 MyDash event types use the `dashboard_` prefix. No `page_*` type names appear in MyDash code or translation keys.

**Rationale**: MyDash's domain entity is a dashboard, not a page. Adopting the source naming would create confusion about which system emitted an event and would expose the source application's vocabulary to MyDash users.

---

### D7: Opt-out via standard NC Activity preferences

**Decision**: Each of the 13 event types is registered with NC's per-type notification preference system. Users can independently disable in-app and email notifications per type via NC Settings → Activity. No custom MyDash preferences page is provided. Default: in-app ON, email OFF for all types.

**Rationale**: NC's activity preference system is already trusted by users and familiar from Files, Talk, and other apps. Replicating it for MyDash would fragment the settings experience and add maintenance burden with no UX benefit.

## Spec changes implied

- **REQ-ACT-002 (event catalogue)**: The 13 listed types in `spec.md` are correct — `dashboard_created`, `dashboard_updated`, `dashboard_deleted`, `dashboard_published`, `dashboard_unpublished`, `dashboard_scheduled`, `dashboard_shared`, `dashboard_public_share_created`, `dashboard_commented`, `dashboard_reacted`, `dashboard_restored`, `dashboard_lock_overridden`, `dashboard_role_changed`. Confirm no `dashboard_viewed` constant appears anywhere. Add a NOTE that the 5 source-derived types are ports and the 8 additions are MyDash-native.
- **REQ-ACT-003 (audience targeting)**: Pin the four-scope table from D3 as normative. Clarify that group membership is resolved at emit-time, not read-time.
- **REQ-ACT-007 / REQ-ACT-008 (debouncing)**: Pin the 15-minute (900 s) APCu TTL as the canonical window value. Spec currently implies it but does not state the number explicitly in the requirement body — make it normative there, not just in scenarios.
- **Add NOTE**: Source-derived types and MyDash additions coexist by design. The spec is not incomplete because it defines more types than the source; the additions are intentional extensions for capabilities the source does not have.

## Open follow-ups

- Whether to expose a `GET /api/me/activity` endpoint proxying NC's Activity stream filtered to MyDash event types, vs directing users to NC's stock activity page.
- Whether `dashboard_role_changed` should emit one row to the affected user AND one row to the admin who made the change, or only to the affected user (current spec implies the latter).
- Whether `Extension` should auto-discover sibling-capability emitters via DI tags (open/closed principle) vs static registration in the class docblock (simpler, auditable).
- Confirm whether `dashboard_public_share_created` should fan out to the dashboard owner when the admin who creates the link IS the owner (to avoid a self-notification that carries no information).
