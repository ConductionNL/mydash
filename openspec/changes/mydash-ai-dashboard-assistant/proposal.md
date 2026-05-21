# Embedded AI assistant and smart summaries

As a case worker or dashboard user, I want to view intelligent summaries of my workload on the dashboard—cases and consultation responses—so that I can quickly assess my current status without navigating away. This change adds two widgets powered by AI-driven summarization to help users make faster decisions on their open cases and gather feedback insights.

## Affected code units

- `src/components/CasesSummaryWidget.vue` — displays open case count, status distribution, and recent cases
- `src/components/ConsultationSummaryWidget.vue` — displays consultation response metrics and key feedback
- `src/store/modules/casesStore.js` — Pinia store for case data (CRUD via OpenRegister)
- `src/store/modules/consultationStore.js` — Pinia store for consultation data (CRUD via OpenRegister)
- `lib/Service/SummaryService.php` — AI-powered summarization for both case and consultation data
- `lib/Controller/WidgetController.php` — API endpoints for widget data (`GET /api/widgets/cases-summary`, `GET /api/widgets/consultation-summary`)
- `lib/Settings/mydash_register.json` — OpenRegister schema definitions for Case and Consultation
- Modifies dashboard `capabilities` to register the two new widgets

## Why a delta

MyDash currently has no dashboard widgets for case summaries. Case workers must navigate to a separate cases list to see their open work, losing context from the dashboard. This change surfaces key case and consultation metrics inline on the dashboard, reducing context switching and improving situational awareness. The summarization is powered by Claude via OpenAI-compatible API for intelligent, actionable insights.

## Approach

- **Data source**: OpenRegister stores all cases and consultations. Widget controllers fetch and aggregate this data via OpenRegister's `ObjectService`.
- **Summarization**: A `SummaryService` enriches aggregated data with AI-generated narrative summaries (e.g., "You have 5 open cases, 2 are overdue. Key actions: X, Y, Z").
- **Frontend**: Two Vue components (`CasesSummaryWidget`, `ConsultationSummaryWidget`) render dashboard cards with metrics and summaries, styled with NL Design System tokens.
- **RBAC**: OpenRegister's object-level permissions filter visible cases/consultations per user; the widget respects these filters automatically.
- **Caching**: Summary API responses cached for 5 minutes to avoid repeated AI calls for the same data snapshot.

## Capabilities

**New Capabilities:**

- `mydash-cases-summary` — display open case count, status breakdown, and AI summary
- `mydash-consultation-summary` — display consultation response metrics and key feedback summary

## Notes

- Summaries are generated on-demand (not pre-computed) and cached briefly to balance freshness vs. API cost.
- Both widgets integrate with MyDash's existing dashboard edit mode and GridStack layout engine.
- Case and Consultation schemas are defined in OpenRegister with full CRUD operations available via the existing app scaffolding.
- Widget visibility respects per-user OpenRegister permissions — no additional RBAC layer needed.
- The AI service is optional; if the Claude API is unavailable or quota exhausted, widgets fall back to displaying raw metrics without summaries.
