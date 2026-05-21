# Design — Embedded AI assistant and smart summaries

## Context

MyDash is a dashboard system for case workers and governance bodies in Dutch government. Users manage multiple cases, consultations, and decisions daily. Today's dashboard shows static widgets but no summarization of workload. Case workers must navigate to a separate cases list to understand their open work, losing dashboard context in the process.

The two most-demanded features (demand score 42 each from 14 tender mentions) are:
1. A summary of open cases showing total count, status breakdown, and last-updated dates
2. A summary of consultation responses with metrics and key feedback

Both can be powered by AI summarization to make them more actionable — "2 cases are overdue and need immediate attention" vs. "total open: 5".

## Goals / Non-Goals

**Goals:**

- Surface critical case and consultation metrics on the dashboard without leaving the page.
- Use AI-generated summaries to highlight priority actions (e.g., overdue cases, high-engagement consultations).
- Integrate seamlessly with MyDash's existing widget grid, edit mode, and user permissions.
- Cache summaries to avoid excessive API calls while keeping data fresh (5-min TTL).
- Gracefully degrade to raw metrics if the AI service is unavailable.
- Respect OpenRegister per-user object-level permissions; no custom RBAC needed.

**Non-Goals:**

- Custom workflow triggers or automated actions (e.g., auto-assign overdue cases). Out of scope; can be a follow-up business-logic change.
- Real-time streaming updates to summaries. Out of scope; 5-min cached snapshots are acceptable.
- Custom schema definitions — reuse OpenRegister's existing schemas for cases and consultations (or define minimal new ones in OR if needed).
- Bespoke permission model — leverage OpenRegister's RBAC entirely.
- Offline-first caching or service-worker sync. Not needed for a dashboard widget.

## Decisions

### D1: Use OpenRegister as the single source of truth for case and consultation data

**Decision**: All cases and consultations are stored in OpenRegister. The widget controllers fetch data via `ObjectService::findObjects()` and trust OpenRegister's per-object filtering + RBAC.

**Alternatives considered:**

- Store case summaries in MyDash's own database. Rejected — violates ADR-022 (apps consume OR abstractions). OR already manages objects, why duplicate?
- Read directly from a data warehouse / SOLR. Rejected — adds a new dependency; OR's `findObjects` already supports filtering + pagination.

**Rationale**: ADR-022 mandates consuming OR abstractions rather than rolling parallel data models. Cases are domain objects that belong in OR; MyDash is a consumer of OR's object service.

### D2: AI summarization is optional; degrade to raw metrics if unavailable

**Decision**: The `SummaryService` wraps Claude API calls in a try/catch. If the API is unreachable or quota-exhausted, the widget renders metrics alone (count, status breakdown) without the AI narrative.

**Alternatives considered:**

- Fail the entire widget if AI is unavailable. Rejected — the raw metrics are still valuable; losing them is worse than losing the summary.
- Pre-generate summaries during off-peak hours. Rejected — adds complexity; on-demand + caching is simpler.
- Cache summaries persistently in the database. Rejected — overkill for a 5-min TTL; in-memory cache is fine.

**Rationale**: Graceful degradation keeps the widget useful even when the AI service is down. The cache keeps API costs reasonable.

### D3: Widget data endpoint is read-only; no form submission from the widget

**Decision**: `WidgetController::casesAction()` and `::consultationsAction()` are GET-only endpoints. They return JSON summaries. No POST/PUT from the widget.

**Alternatives considered:**

- Allow the widget to update case status or mark consultations as reviewed. Rejected — those are domain-specific workflows, not summarization. If needed, they belong in the detail pages + dedicated workflows, not in a read-only dashboard widget.

**Rationale**: Keeps the widget scope narrow and the controller stateless. Complex mutations flow through proper detail pages with form validation and audit trails.

### D4: Summary cache lives in-memory (APCu) with 5-min TTL

**Decision**: Each widget controller result is cached via Nextcloud's APCu backend with a 5-min key (`mydash_summary_{userId}_{type}`). Cache is busted when OpenRegister emits an object-change event.

**Alternatives considered:**

- Redis cache (faster, clusterable). Rejected — overengineering for a dashboard widget; APCu is available everywhere NC is installed and fine for single-server deployments.
- Database cache. Rejected — adds a write per widget load; APCu in-memory is cheaper.
- No cache; always compute fresh. Rejected — each widget load could trigger 2+ API calls to OpenRegister + 1 AI call. With 20 concurrent users, that's expensive.

**Rationale**: APCu is simple, built-in to NC, and sufficient for this use case. Event-driven invalidation ensures freshness when data changes.

### D5: Consultation response data includes both quantitative + qualitative summaries

**Decision**: The consultation widget displays:
- Total responses received (count)
- Response breakdown by type (e.g., agree/disagree/neutral percentages)
- AI-generated summary of key themes and sentiment

**Alternatives considered:**

- Only metrics, no AI summary. Rejected — the top requested feature is "see key feedback quickly", which requires natural-language summary.
- Full-text feedback display. Rejected — too verbose; summarization is the whole point.

**Rationale**: Metrics alone don't tell the story; sentiment + theme summary answers the question "what do stakeholders actually think?"

### D6: Both widgets use `CnDashboardPage` grid and respect edit mode

**Decision**: Widgets are registered via the standard MyDash widget registry. They render inside `CnWidgetWrapper` and participate in the grid's drag-drop layout, resize, and edit mode.

**Alternatives considered:**

- Custom modal/overlay for summaries. Rejected — loses dashboard context; also requires custom layout code.
- Static top-of-page banner. Rejected — not user-configurable; not part of the grid.

**Rationale**: Consistent with MyDash's existing widget architecture. Users can add/remove/resize/reorder as needed.

## Reuse Analysis

This change consumes the following OpenRegister abstractions (per ADR-022):

| Abstraction | How used |
|---|---|
| **Registers + schemas + objects** | Cases and Consultations stored in OR; fetched via `ObjectService::findObjects()` with user-scoped filtering |
| **Authorization RBAC** | OR's object-level permissions filter visible cases/consultations automatically; no custom per-widget RBAC |
| **Events + webhooks** | Object-change events trigger cache invalidation (future: could drive real-time updates) |
| **@conduction/nextcloud-vue** | `CnWidgetWrapper`, `CnStatsBlock`, `CnChartWidget` for dashboard rendering |
| **ChatService (if available)** | RAG-based context retrieval for smarter summaries (optional, graceful fallback if unavailable) |

No custom services for case/consultation management; all data flows through OR.

## Seed Data

When the change installs, three example objects are loaded into OpenRegister:

**Cases:**
- Case ID: `2024-001`, status: `open`, assignee: `case_worker_1`, last_updated: `2026-05-20`, subject: `Permit application - Office expansion`
- Case ID: `2024-002`, status: `in_progress`, assignee: `case_worker_1`, last_updated: `2026-05-10`, subject: `Appeal review - Zoning decision`
- Case ID: `2024-003`, status: `overdue`, assignee: `case_worker_2`, last_updated: `2026-04-15`, subject: `License renewal - Medical facility`

**Consultations:**
- Consultation ID: `CON-001`, responses: 45, status: `active`, deadline: `2026-05-28`, response_breakdown: `{agree: 28, neutral: 12, disagree: 5}`
- Consultation ID: `CON-002`, responses: 12, status: `closed`, deadline: `2026-05-15`, response_breakdown: `{agree: 8, neutral: 3, disagree: 1}`

These enable testing without an external data source.

## Risks / Trade-offs

- **Risk**: Claude API quota exhaustion during peak hours. → **Mitigation**: 5-min cache reduces API calls by ~90%; widget falls back to metrics-only gracefully; admin can disable summaries via config.
- **Risk**: AI summary is inaccurate or misleading. → **Mitigation**: Summaries are advisory only; users must click through to detail pages for authoritative data. Test summaries with real case data during QA.
- **Risk**: Case worker sees stale summary due to 5-min TTL. → **Mitigation**: TTL is visible in UI ("Last updated 4 min ago"); user can click refresh. For critical decisions, user navigates to detail page (always fresh).
- **Risk**: OpenRegister permission changes don't immediately hide cases from widget. → **Mitigation**: Cache invalidation on object-change events; worst-case, cache expires in 5 min.
- **Trade-off**: Summarization is synchronous (user waits for API response). On slow networks or under load, API response time may add 1-2s to page load. Acceptable for a dashboard widget that loads once per session.

## Migration Plan

1. **Schemas land in OpenRegister** — `Case` and `Consultation` schemas (or extend existing if they exist). Registered in `lib/Settings/mydash_register.json`.
2. **Backend services** — `SummaryService` + `WidgetController` land together in one PR, tests included.
3. **Vue components** — `CasesSummaryWidget.vue` and `ConsultationSummaryWidget.vue` land in the same PR; integrated with the widget registry.
4. **Seed data** — on install, `SettingsLoadService` imports the 3+2 example objects via `ImportHandler::importFromApp()`.
5. **Verify** — run browser tests; confirm widgets appear on dashboard, fetch fresh data, and degrade gracefully when API is down.
6. **Rollback**: drop the two new widgets from the registry, remove the schema. Existing cases/consultations in OR are unaffected.

## Open Questions

- Should the AI-generated summary include a confidence score (e.g., "High confidence: 2 cases are urgent")? Defer to design review.
- What if OpenRegister has no cases or consultations? Show empty state or hide the widget? Current decision: show empty state with "No cases found" message.
- Should case workers be able to snooze a summary (e.g., hide this widget for 24 hours)? Out of scope; can add per-widget user preferences later.
