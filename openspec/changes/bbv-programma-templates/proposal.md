# BBV Programma Templates

## Why

Every gemeente in Nederland is wettelijk verplicht een programmabegroting op te stellen die de gemeentelijke taken indeelt in programma's (sociaal domein, ruimte, bestuur, openbare orde, ...), met per programma één of meer doelen en per doel meetbare indicatoren. Vandaag bouwt elke gemeente die structuur opnieuw op in Excel of lokaal BI-tool, afwijkend van buurgementen, waardoor benchmarking lastig is en provinciale toezichthouders aanleving lastig kunnen vergelijken.

De BBV-taxonomie is echter sterk gestandaardiseerd: het Ministerie van BZK publiceert verplichte beleidsindicatoren, IV3-rubrieken (Informatie voor Derden) zijn vastgesteld, en meeste gemeenten hanteren vergelijkbare programma-indeling op hoofdlijnen. Deze spec introduceert een eerste-klasse Programma / Doel / Indicator / Maatregel datamodel in OpenRegister, seed-templates voor gangbare programma-indelingen (G4, M50, kleine gemeenten, provincies, waterschappen), één-klik installatie zodat een gemeente een template kiest en alle programma's, doelen en standaard-indicatoren in één keer aanmaakt, en koppelingen naar financeq (begrotings- en realisatie-cijfers per programma) en planix (planning van maatregelen onder een doel).

## What Changes

- New OpenRegister schemas: `Programma`, `Doel`, `Indicator`, `IndicatorMeting`, `Maatregel`, `IV3Mapping`, `ProgrammaTemplate`, `TemplateProgramma`, `TemplateDoel`, `TemplateIndicator`.
- New backend services: `ProgrammaService` (CRUD + template installatie), `TemplateService` (template upgrade logic), `IV3ExportService` (export voor provinciale toezichthouder).
- New frontend views: programma-tree (visualisatie van Programma → Doel → Indicator → Maatregel hiërarchie), template-picker (één-klik installatie), template-upgrade-wizard.
- Integration: openconnector (sync IndicatorMetingen van waarstaatjegemeente / CBS), financeq (begroting ↔ realisatie per programma), planix (Maatregel → project link).
- Seed data: templates voor G4, M50, kleine gemeenten, provincies, waterschappen (centraal beheerd in openregister).

## Capabilities

### New Capabilities

- `programma-tree` — Programma / Doel / Indicator / Maatregel CRUD en hiërarchie-beheer.
- `programma-templates` — One-click template installatie + versie-upgrade.
- `iv3-mapping-export` — IV3-export voor provinciale toezichthouders.
- `indicator-syncing` — Geautomatiseerde sync van IndicatorMetingen van externe bronnen via openconnector.
- `maatregel-planning` — Maatregel ↔ planix project linking.
- `programma-begroting` — Begroting ↔ realisatie synchronisatie met financeq.

### Modified Capabilities

(none — all entirely new)

## Impact

**Affected integrations:**

- `openregister` — opslag van Programma, Doel, Indicator, Maatregel, IV3Mapping, templates, seed data.
- `financeq` — query van begroting / realisatie per IV3-taakveld; verdeling over programma's via IV3Mapping.
- `planix` — project creatie + status synchronisatie gelinkt aan Maatregel.
- `openconnector` — sync van IndicatorMetingen van waarstaatjegemeente.nl, CBS API, eigen meetbronnen.

**Dependencies:**

- OpenRegister (already required by mydash)
- IGroupManager, IUserManager (from Nextcloud core)
- No new composer/npm dependencies

**Migration:**

- Zero-impact: seed templates are loaded via OpenRegister's ImportHandler pipeline on first install or via repair step. Existing tenants without templates see an empty programma-tree until they choose a template in the UI.

## Standards

- **BBV** — Besluit Begroting en Verantwoording provincies en gemeenten.
- **IV3** — Informatie voor Derden taakveldenlijst (BZK).
- **Verplichte beleidsindicatoren** — Jaarlijks bijgewerkt door BZK.
- **Waarstaatjegemeente.nl** — data-bron-specificatie.
- **CBS Open Data API** — indicator-meting bronnen.

## Non-Goals

- Real-time collaboration on programma edits (out of scope; single-user editing assumed).
- Mobile app support (webapp-only; can defer mobile to later change).
- Workflow approvals for programma publication (status lifecycle exists, but approval workflow deferred to future change).
- Multi-tenant template versioning (seeds are global; per-tenant template forks deferred).

## Risks

- **Risk:** Template installatie is niet atomair en mislukt half-weg. → **Mitigation:** Transactie wrapper om template cloning; rollback on any error.
- **Risk:** IV3-mapping is incorrect en export gaat fout naar provinciale toezichthouder. → **Mitigation:** Valideerstap in export dat som per programma 100% bedraagt.
- **Risk:** External data sources (waarstaatjegemeente, CBS) zijn offline en sync mislukt. → **Mitigation:** Graceful degradation; sync retries later; eigenaar van doel krijgt notification.

## Deliverables

- OpenRegister schemas (JSON, in mydash `_register.json`)
- Backend services (PHP in `lib/Service/`)
- Frontend views (Vue in `src/views/`)
- API endpoints (PHP in `lib/Controller/`)
- Integration code (openconnector hooks, financeq API calls, planix linking)
- Seed templates (JSON objects loaded on install)
- Unit + integration tests
- Changelog + admin docs (ADR-010)
