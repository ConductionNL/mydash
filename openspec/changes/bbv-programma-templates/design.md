# Design — BBV Programma Templates

## Context

Gemeenten in Nederland moeten een programmabegroting volgens BBV (Besluit Begroting en Verantwoording) opstellen. Deze hierarchie (Programma → Doel → Indicator → Maatregel) is wettelijk verplicht en moet aansluiten op de IV3-taakveldenlijst van BZK voor provinciale toezicht. Vandaag bouwen gemeenten dit in spreadsheets of externe BI-tools, resulterend in:

1. **Geen gestandaardiseerde indeling** — benchmark tussen gemeenten is lastig, provinciale toezichthouders kunnen aanlevering moeilijk vergelijken.
2. **Lange setup-tijd** — implementatie van een baseline duurt weken.
3. **Verplichte beleidsindicatoren raken uit sync** — gemeenten updaten niet mee als BZK nieuwe verplichte indicatoren publiceert.
4. **Data-silos** — programmastructuur is los van begroting (financeq) en planuitvoering (planix).

Deze change introduceert een gestandaardiseerde Programma/Doel/Indicator/Maatregel datamodel in OpenRegister, seed-templates voor de meest gangbare gemeentetypen (G4, M50, klein, provincie, waterschap), één-klik installatie, en koppelingen naar financeq en planix zodat begroting en planning automatisch integreren met de programmastructuur.

## Goals / Non-Goals

**Goals:**

- Provide a standardised BBV-compliant data model for Programma → Doel → Indicator → Maatregel hierarchy in OpenRegister.
- One-click template installation so a gemeente stands up a baseline in <1 minute.
- Automatic sync of required indicators from BZK, with user control over updates.
- Transparent IV3-mapping so gemeenten can export to provincial supervisors without manual transformation.
- Budget ↔ realisatie flow from financeq so programma managers see spending per programma without manual reconciliation.
- Measure-based planning: Maatregel planning integrated with planix so actions and budgets align.

**Non-Goals:**

- Workflow approvals or formal publication controls (status enum exists; governance workflows deferred).
- Multi-governance models (assumes 1 gemeente = 1 programmastructuur; shared multi-org templates deferred).
- Mobile-optimized UX (webapp-only; mobile support is a future change).
- Real-time collaboration (single-user assumption; realtime edits deferred).
- Custom indicator definitions beyond BBV standard (we seed templates; custom extensions via relation system if needed).

## Decisions

### D1: Model programma-structure as separate OpenRegister schemas, not a single tree document

**Decision**: Create individual schemas for Programma, Doel, Indicator, Maatregel, and link via register+schema+objectId relations (per ADR-001).

**Alternatives considered:**

- Store the entire tree as a single JSON document in OpenRegister. Rejected because (a) updates to one indicator would require re-serializing the entire parent programma tree, (b) access control at the Doel level becomes impossible, (c) reporting/search across all goals regardless of parent programma is cumbersome.

**Rationale**: Normalized schemas enable per-entity access control (een Doel-manager beheert alleen hun doel), enable search and filtering (find all indicators with naam matching X), and keep the schema evolution simple (adding a Doel field doesn't touch Programma records).

### D2: Store TemplateProgramma/TemplateDoel/TemplateIndicator as separate skeleton-record schemas, cloned on install

**Decision**: Define three parallel template-entity schemas that are cloned into production records during template installation, with a `_templateId` back-reference for versioning.

**Alternatives considered:**

- Use a single generic Template schema with metadata describing whether it's a programma/doel/indicator. Rejected because per-entity type-checking and field validation becomes complex in a single schema.
- Store templates as JSON blobs in ProgrammaTemplate, not as individual records. Rejected because (a) cloning would require custom logic (we'd re-deserialize, re-serialize), (b) searching "which templates include indicator X" becomes impossible, (c) version diffing (REQ-BBV-004) requires structured field-level comparison.

**Rationale**: Skeleton records in OpenRegister enable the clone operation to use standard `ObjectService.saveObject()`, enable search and filtering of template content, and enable atomic versioned diffing (every template field is a separate property, not a JSON string).

### D3: `'default'` synthetic group for IV3-mapping fallback

**Decision**: Reserve groupId = `'default'` in IV3Mapping to mean "if no specific IV3 match found, use this mapping as the fallback".

**Alternatives considered:**

- No fallback; every programma must have an exact IV3-taakveld match. Rejected because some gemeenten have organization-specific programma divisions that don't align neatly with the IV3 list.
- Use a nullable `isDefault` boolean column. Rejected because it overcomplicates the query (WHERE isDefault=true OR (groupId = ? AND isDefault=false)).

**Rationale**: A single string sentinel keeps the schema simple, is indexable, and documents itself in the database.

### D4: Template version upgrade is opt-in-per-change, not bulk-apply-all

**Decision**: `TemplateUpgradeService::suggestDifferences()` returns a per-field change list for admin review; admin checks each change and accepts/rejects individually. No auto-apply-all.

**Alternatives considered:**

- Auto-apply all non-conflicting changes silently. Rejected because a gemeentenumfunctie might have hand-edited indicator definitions post-install; silent overwrites lose work.
- Show the diff but require one-click accept-all-or-nothing. Rejected because an admin might want to accept BZK's new beleidsindicator but reject a renamed doelstelling (their local change is more recent).

**Rationale**: Fine-grained control respects local customizations and reduces the risk of silent data loss. The UI experience (checkboxes per change) is more complex but aligns with the commons value "no surprises in government software".

### D5: IV3-export validation requires sum-to-100% check

**Decision**: When exporting to IV3 format, the system validates that IV3Mapping percentages for each programma sum to exactly 100 (with ±1% tolerance for rounding).

**Alternatives considered:**

- Allow unmapped programma's (skip them in export). Rejected because provinciale toezichthouders require accounting for 100% of gemeente spending; missing programma's is a critical error.
- Allow partial mapping (82% allocated, 18% left out). Rejected because it hides allocation errors and makes provincial oversight impossible.

**Rationale**: 100% validation forces gemeenten to explicitly account for all spending before exporting, preventing silent data loss in provincial reporting.

### D6: IndicatorMeting sync from waarstaatjegemeente/CBS is push-based, not pull-based

**Decision**: openconnector schedules periodic sync jobs that push new metingen into mydash via webhook. On error, the system notifies the doel-eigenaar but does NOT prevent page load.

**Alternatives considered:**

- Sync on-demand when a user opens the Indicator detail page. Rejected because (a) it adds latency to page load, (b) if the external source is slow/offline, the user is blocked, (c) it couples UI responsiveness to external SLA.
- Store a cache timestamp and invalidate after N hours. Rejected because it's complex to manage and exposes stale data until the cache expires.

**Rationale**: Background scheduled jobs keep the UI fast and decouple external data availability from user experience. Webhook-based push is simpler than polling and OpenRegister already has webhook support.

### D7: Maatregel ↔ planix linking creates a planix project, not a task

**Decision**: When a gebruiker clicks "Plan this Maatregel", the system creates a planix `Project` (full project, not a single task) with the Maatregel's name, description, and dates as fields.

**Alternatives considered:**

- Create a planix Task instead. Rejected because a Maatregel is typically multi-month or multi-year; a single task would be too granular.
- Create a linked record in planix without creating the project automatically. Rejected because it adds manual work; one-click should do the whole thing.

**Rationale**: A project-level scope matches the Maatregel's typical lifecycle (multiple quarters, many subtasks, budget tracking) and aligns planix data structure with the domein knowledge.

## Data Model

All schemas conform to schema.org vocabulary and ADR-001 data-layer rules. All are stored in OpenRegister.

### Programma

```json
{
  "@self": {
    "register": "bbv_programma",
    "schema": "Programma",
    "slug": "sociaal-domein-2025"
  },
  "code": "01",
  "naam": "Sociaal Domein",
  "omschrijving": "Alle gemeentelijke taken rond welzijn, kinderopvang, ouderopleiding, maatschappelijke ondersteuning.",
  "portefeuillehouderUserId": "user-uuid",
  "themaCluster": "sociaal",
  "volgorde": 1,
  "jaar": 2025,
  "status": "vastgesteld"
}
```

**Fields:**
- `code` (string, required) — Korte unieke identifier (e.g., "01", "02"). Samen met `jaar` vormt dit een natuurlijke key.
- `naam` (string, required) — Naam van het programma.
- `omschrijving` (string, optional) — Vrije tekst, 500 char max.
- `portefeuillehouderUserId` (relation: user, optional) — Portefeuillehouder (eigenaar). Link naar Nextcloud user.
- `themaCluster` (enum: sociaal | ruimte | bestuur | veiligheid | economie | financien | milieu, required) — Thema.
- `volgorde` (integer, optional) — Display order in UI.
- `jaar` (integer, required) — Begroting year (e.g., 2025).
- `status` (enum: concept | vastgesteld | in_uitvoering | verantwoord, required) — Lifecycle status.

### Doel

```json
{
  "@self": {
    "register": "bbv_doel",
    "schema": "Doel",
    "slug": "sociaal-domein-jeugdzorg-2025"
  },
  "programmaId": "programma-uuid",
  "code": "01.01",
  "naam": "Toegankelijke jeugdzorg",
  "omschrijving": "Alle kinderen met hulpbehoeften krijgen passende ondersteuning.",
  "beoogdMaatschappelijkEffect": "Minder jongeren met onbehandelde psychische problemen",
  "eigenaarUserId": "user-uuid",
  "startjaar": 2025,
  "eindjaar": 2030,
  "status": "vastgesteld"
}
```

**Fields:**
- `programmaId` (relation: Programma, required)
- `code` (string, required) — E.g., "01.01".
- `naam` (string, required)
- `omschrijving` (string, optional)
- `beoogdMaatschappelijkEffect` (string, optional) — Societal outcome sought.
- `eigenaarUserId` (relation: user, optional) — Doel owner.
- `startjaar` (integer, required)
- `eindjaar` (integer, required)
- `status` (enum: concept | vastgesteld | in_uitvoering | verantwoord, required)

### Indicator

```json
{
  "@self": {
    "register": "bbv_indicator",
    "schema": "Indicator",
    "slug": "indicator-psychische-gezondheid-jeugd-2025"
  },
  "doelId": "doel-uuid",
  "code": "BZK-JZ-001",
  "naam": "Jongeren met behandelde psychische problemen",
  "eenheid": "aantal",
  "bron": "waarstaatjegemeente",
  "berekeningswijze": "Aantal jongeren 12-25j in gemeentelijke dataset geregistreerd als 'in behandeling' / totaal 12-25j × 100",
  "isVerplichteBeleidsindicator": true,
  "nulmeting": 1250,
  "nulmetingJaar": 2024,
  "streefwaarde": 1500,
  "streefjaar": 2030
}
```

**Fields:**
- `doelId` (relation: Doel, required)
- `code` (string, required) — E.g., "BZK-JZ-001" for BZK-standard indicators.
- `naam` (string, required)
- `eenheid` (string, required) — "aantal", "percentage", "index", etc.
- `bron` (enum: waarstaatjegemeente | CBS | eigenMeting | overig, required)
- `berekeningswijze` (string, optional) — Free-form definition of how the indicator is calculated.
- `isVerplichteBeleidsindicator` (boolean, required) — Flagged by BZK.
- `nulmeting` (number, optional) — Baseline value (start of period).
- `nulmetingJaar` (integer, optional)
- `streefwaarde` (number, optional) — Target value.
- `streefjaar` (integer, optional)

### IndicatorMeting

```json
{
  "@self": {
    "register": "bbv_indicator_meting",
    "schema": "IndicatorMeting",
    "slug": "psychische-gezondheid-jeugd-2024"
  },
  "indicatorId": "indicator-uuid",
  "periode": "2024-Q4",
  "waarde": 1380,
  "geverifieerdDoor": "system-openconnector",
  "geverifieerdOp": "2025-01-15T08:00:00Z",
  "opmerking": "Data retrieved from waarstaatjegemeente.nl on Jan 15, 2025"
}
```

**Fields:**
- `indicatorId` (relation: Indicator, required)
- `periode` (string, required) — E.g., "2024-Q4", "2024", "2024-01" (ISO 8601 prefixes).
- `waarde` (number, required)
- `geverifieerdDoor` (string, required) — "system-openconnector", "system-cbs", "gebruiker-email", etc.
- `geverifieerdOp` (datetime, required)
- `opmerking` (string, optional)

### Maatregel

```json
{
  "@self": {
    "register": "bbv_maatregel",
    "schema": "Maatregel",
    "slug": "maatregel-jeugdzorg-opschaling-2025"
  },
  "doelId": "doel-uuid",
  "naam": "Opschaling capaciteit jeugdzorg",
  "omschrijving": "Huur extra kantoor, inhuren 3 therapeuten, upgrade IT-systemen",
  "trekkerUserId": "user-uuid",
  "startdatum": "2025-03-01",
  "einddatum": "2025-12-31",
  "status": "gepland",
  "geraamdBudget": 250000,
  "gerealiseerdBudget": 120000,
  "planixProjectId": "project-uuid"
}
```

**Fields:**
- `doelId` (relation: Doel, required)
- `naam` (string, required)
- `omschrijving` (string, optional)
- `trekkerUserId` (relation: user, optional) — Action owner.
- `startdatum` (date, required)
- `einddatum` (date, required)
- `status` (enum: gepland | in_uitvoering | afgerond | uitgesteld, required)
- `geraamdBudget` (number, optional) — Budget in EUR.
- `gerealiseerdBudget` (number, optional) — Realised spend in EUR.
- `planixProjectId` (relation: planix project, optional) — Reference to planix for planning + budget tracking.

### IV3Mapping

```json
{
  "@self": {
    "register": "bbv_iv3_mapping",
    "schema": "IV3Mapping",
    "slug": "sociaal-domein-iv3-2025"
  },
  "programmaId": "programma-uuid",
  "iv3Taakveld": "15.01.01",
  "iv3Categorie": "Sociaal Domein",
  "percentage": 100
}
```

**Fields:**
- `programmaId` (relation: Programma, required)
- `iv3Taakveld` (string, required) — E.g., "15.01.01" from BZK's IV3 list.
- `iv3Categorie` (string, optional) — Human-readable category name from IV3.
- `percentage` (number, required) — 0–100. Multiple IV3Mappings per Programma are allowed if the programma spans multiple taakvelden (e.g., 60% sociaal, 40% ruimte). Must sum to 100%.

### ProgrammaTemplate

```json
{
  "@self": {
    "register": "bbv_programma_template",
    "schema": "ProgrammaTemplate",
    "slug": "template-g4-gemeenten-v1"
  },
  "naam": "G4 Gemeenten Template",
  "doelgroep": "G4",
  "versie": "1.0.0",
  "geldigVanaf": "2025-01-01",
  "geldigTotEnMet": "2099-12-31",
  "brongemeente": "Amsterdam",
  "licentie": "CC-BY-4.0",
  "taal": "nl",
  "beschrijving": "Pre-configured programma structure voor de vier grote gemeenten (Amsterdam, Rotterdam, Den Haag, Utrecht) aligned met BBV en IV3. Includes standaard doelen en BZK-required indicators."
}
```

**Fields:**
- `naam` (string, required)
- `doelgroep` (enum: G4 | M50 | klein | provincie | waterschap | nldesign, required)
- `versie` (string, required) — Semver (1.0.0, 1.1.0, etc.).
- `geldigVanaf` (date, required)
- `geldigTotEnMet` (date, required)
- `brongemeente` (string, optional) — Source gemeente if this template was authored by one.
- `licentie` (string, optional) — E.g., "CC-BY-4.0".
- `taal` (string, required) — "nl", "en", etc.
- `beschrijving` (string, optional)

### TemplateProgramma, TemplateDoel, TemplateIndicator

Schema mirrors Programma, Doel, Indicator but with `_templateId` field pointing back to the parent ProgrammaTemplate, and a `_sequenceOrder` field so the clone operation knows the order to create child records.

```json
{
  "@self": {
    "register": "bbv_template_programma",
    "schema": "TemplateProgramma",
    "slug": "template-g4-sociaal-domein"
  },
  "_templateId": "template-uuid",
  "_sequenceOrder": 1,
  "code": "01",
  "naam": "Sociaal Domein",
  ...
}
```

## API Surface

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/api/bbv/programmas` | logged-in user | Create a new Programma (uses ObjectService under the hood) |
| GET | `/api/bbv/programmas` | logged-in user | List Programma's in the tenant with filtering (uses IndexService) |
| GET | `/api/bbv/programmas/{programmaId}` | logged-in user | Get one Programma with related Doelen + Indicatoren (uses relation system) |
| PUT | `/api/bbv/programmas/{programmaId}` | programma owner or admin | Update Programma |
| DELETE | `/api/bbv/programmas/{programmaId}` | admin only | Delete Programma (REQ-BBV-001: hiërachie-integriteit guard) |
| POST | `/api/bbv/doelen` | logged-in user | Create a new Doel under a Programma |
| GET | `/api/bbv/doelen` | logged-in user | List Doelen (filter by programmaId) |
| GET | `/api/bbv/doelen/{doelId}` | logged-in user | Get one Doel with Indicatoren + Maatregelen |
| PUT | `/api/bbv/doelen/{doelId}` | doel eigenaar or admin | Update Doel |
| DELETE | `/api/bbv/doelen/{doelId}` | admin only | Delete Doel (REQ-BBV-001 guard) |
| POST | `/api/bbv/indicatoren` | logged-in user | Create Indicator under a Doel |
| GET | `/api/bbv/indicatoren` | logged-in user | List Indicatoren |
| GET | `/api/bbv/indicatoren/{indicatorId}` | logged-in user | Get Indicator + recent IndicatorMetingen |
| PUT | `/api/bbv/indicatoren/{indicatorId}` | indicator owner or admin | Update Indicator |
| DELETE | `/api/bbv/indicatoren/{indicatorId}` | admin only | Delete Indicator |
| POST | `/api/bbv/indicatoren/{indicatorId}/metingen` | openconnector | Create IndicatorMeting (push-based sync) |
| GET | `/api/bbv/indicatoren/{indicatorId}/metingen` | logged-in user | List IndicatorMetingen for an Indicator |
| POST | `/api/bbv/maatregelen` | logged-in user | Create Maatregel under a Doel |
| GET | `/api/bbv/maatregelen` | logged-in user | List Maatregelen |
| GET | `/api/bbv/maatregelen/{maatregelId}` | logged-in user | Get Maatregel |
| PUT | `/api/bbv/maatregelen/{maatregelId}` | maatregel trekker or admin | Update Maatregel |
| DELETE | `/api/bbv/maatregelen/{maatregelId}` | admin only | Delete Maatregel |
| POST | `/api/bbv/maatregelen/{maatregelId}/plan` | maatregel trekker or admin | Link to planix: create a planix project (REQ-BBV-007) |
| POST | `/api/bbv/templates` | admin only | List available ProgrammaTemplates |
| POST | `/api/bbv/templates/{templateId}/install` | admin only | Install template (clone all Template* records, create IV3Mapping) (REQ-BBV-003) |
| GET | `/api/bbv/templates/{templateId}/differences` | admin only | Compare template version to installed version; return per-field diffs (REQ-BBV-004) |
| POST | `/api/bbv/templates/{templateId}/upgrade` | admin only | Apply selected diffs from template upgrade |
| GET | `/api/bbv/iv3/export` | admin only | Generate IV3 export file in BZK format (REQ-BBV-005) |
| GET | `/api/bbv/programmas/{programmaId}/realisatie` | programma owner or admin | Get realisatie-percentage from financeq (REQ-BBV-008) |

## Architecture

### Backend (PHP)

```
ProgrammaController ─┬──> ProgrammaService::createProgramma()
                    ├──> ProgrammaService::listProgrammas()
                    ├──> ProgrammaService::getProgramma()
                    └──> ProgrammaService::deleteProgramma()
                             │
                             ├──> ObjectService::saveObject() [OpenRegister CRUD]
                             ├──> ObjectService::deleteObject()
                             ├──> ObjectService::findAll()
                             └──> RelationService [for Programma → Doelen]

TemplateController ──┬──> ProgrammaTemplateService::listTemplates()
                    ├──> ProgrammaTemplateService::installTemplate()
                    │      ├──> ObjectService::findAll() [TemplateProgramma's]
                    │      ├──> ObjectService::saveObject() [create Programma]
                    │      ├──> ObjectService::saveObject() [create Doel, Indicator, IV3Mapping]
                    │      └──> [all in transaction]
                    └──> TemplateUpgradeService::suggestDifferences()
                           └──> TemplateUpgradeService::applyUpgrade()

IV3Controller ───────> IV3ExportService::generateExport()
                           ├──> ObjectService::findAll() [all Programmas]
                           ├──> RelationService [fetch IV3Mappings]
                           ├──> financeq API call [get begroting/realisatie]
                           └──> IV3Validator::validateMappingSums() [REQ-BBV-005]

IndicatorMeterService ──> openconnector webhook receiver [POST /webhook/indicator-meting]
                            ├──> ObjectService::saveObject() [create IndicatorMeting]
                            └──> NotificationService [notify doel eigenaar if variance]
```

### Frontend (Vue)

```
src/views/ProgrammaTree.vue ─┬──> CnDetailPage [for Programma view/edit]
                            ├──> CnDataTable [list Doelen under Programma]
                            ├──> CnDetailPage [for Doel view/edit]
                            ├──> CnDataTable [list Indicatoren under Doel]
                            ├──> CnDetailPage [for Indicator view/edit with meting history]
                            ├──> CnDataTable [list Maatregelen under Doel]
                            └──> CnDetailPage [for Maatregel view/edit + "Plan in planix" button]

src/views/TemplateInstaller.vue ─> CnFormDialog [template picker]
                                    └──> HTTP POST /api/bbv/templates/{id}/install
                                         [shows install progress report]

src/views/TemplateUpgradeWizard.vue ─> HTTP GET /api/bbv/templates/{id}/differences
                                       [show per-field checkboxes]
                                       └──> HTTP POST /api/bbv/templates/{id}/upgrade
                                            [apply selected changes]

src/stores/bbvStore.js ──────────> createObjectStore('programma')
                                 createObjectStore('doel')
                                 createObjectStore('indicator')
                                 createObjectStore('maatregel')
                                 [auto-generated from schema via ADR-001]
```

## Reuse Analysis

- **ObjectService** (openregister): Full CRUD for Programma, Doel, Indicator, Maatregel, IV3Mapping, all Template entities. No custom persistence logic needed.
- **RelationService** (openregister): Fetch related Doelen for a Programma, Indicatoren for a Doel, Maatregelen for a Doel, IV3Mappings for a Programma.
- **IndexService** (openregister): Full-text search + filtering across Programma, Doel, Indicator, Maatregel.
- **CnDetailPage, CnDataTable, CnFormDialog** (nextcloud-vue): List views (Programmas, Doelen, Indicatoren, Maatregelen), detail views, create/edit forms. No custom Vue components for CRUD.
- **NotificationService** (openregister): Notify doel eigenaar when indicator-meting variance exceeds threshold (REQ-BBV-006).
- **FileService** (openregister): Upload/download programma-export files (IV3 XML, CSV).
- **WebhookService** (openregister): Register webhook endpoint for openconnector push of IndicatorMetingen.

No duplication with existing functionality. Custom code is limited to:
- Template cloning logic (assembly of related objects, atomic transaction).
- Template upgrade diffing (field-level change detection).
- IV3 export formatting + validation (domain-specific transformation).
- financeq API integration (budget/realisatie queries).
- planix project creation on Maatregel link.
- openconnector push receiver for IndicatorMetingen.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Template installation is not atomic; if it fails mid-way, the Programma is incomplete. | Wrap entire clone operation in a database transaction; if any error occurs, rollback all objects. |
| IV3-mapping is incorrect and export sums to <100%; provincia rejects the file. | IV3ExportService::validateMappingSums() checks sum = 100% ±1% and throws before generating the export. Return 400 with message naming missing programmas. |
| Verplichte beleidsindicatoren change in BZK; gemeente is now out of compliance. | Template versioning (REQ-BBV-004): new version ships with updated indicatoren. Gemeente is notified; admin chooses to upgrade or keep old version. Manual responsibility (not auto-upgraded). |
| External data sources (waarstaatjegemeente, CBS) are offline; indicator-meting sync fails. | openconnector retries failed syncs up to N times over M hours. If still failed, system notifies doel eigenaar. Existing metingen remain; no data loss. |
| A user is deleted but they own a Programma/Doel/Maatregel; orphaned records. | On user deletion, TenantLifecycleService reassigns ownership to the TenantAdmin (new nullable `previousOwner` field for audit). |

## Seed Data

Three templates pre-loaded:

### Template: G4 Gemeenten

```json
{
  "@self": {
    "register": "bbv_programma_template",
    "schema": "ProgrammaTemplate",
    "slug": "template-g4-v1"
  },
  "naam": "G4 Gemeenten Template v1.0",
  "doelgroep": "G4",
  "versie": "1.0.0",
  "geldigVanaf": "2025-01-01",
  "geldigTotEnMet": "2099-12-31"
}
```

Template Programma's: Sociaal Domein, Ruimte & Duurzaamheid, Economie & Arbeidsmarkt, Bestuur & Veiligheid (4 total).
Per Programma: 2–3 Doelen (e.g., Sociaal: "Toegankelijke jeugdzorg", "Actieve Ouderen").
Per Doel: 3–5 Indicatoren, including BZK-required ones.

### Template: M50 Gemeenten

4 Programma's, 1–2 Doelen per Programma, 2–3 Indicatoren per Doel (streamlined for smaller gemeenten).

### Template: Kleine Gemeenten

3 Programma's, 1 Doel per Programma, 1–2 Indicatoren per Doel (minimal baseline).

All seed templates include realistic Dutch values:
- Gemeente-like names: "Gemeente Deltameer" (fictional).
- IV3-mapping percentages sum to 100%.
- Example Maatregel records with real-world budget ranges.

## Test Strategy

### Unit Tests (PHPUnit)

- **ProgrammaServiceTest**: Create/read/update/delete Programma; verify hiëarchie-integriteit guard (REQ-BBV-001) on delete.
- **TemplateServiceTest**: List templates; install template (verify atomic transaction, cloning); upgrade template (verify diff detection, selective apply).
- **IV3ExportServiceTest**: Generate export; validate mapping sums = 100% (REQ-BBV-005); test edge cases (unmapped programma, multiple taakvelden).
- **IndicatorMeteringServiceTest**: Create IndicatorMeting via webhook; verify notification sent if variance > threshold (REQ-BBV-006).
- **MaatregelServiceTest**: Create Maatregel; link to planix (REQ-BBV-007); verify sync with financeq realisatie-cijfers (REQ-BBV-008).

### Integration Tests (Vitest + browser)

- **ProgrammaTreeView**: Mount the programma-tree view; create Programma → Doel → Indicator; verify CRUD works end-to-end.
- **TemplateInstallerView**: Select template, click "Install", verify all Programma/Doel/Indicator records created.
- **TemplateUpgradeWizard**: Install v1, upgrade to v2, verify per-field checkboxes work, apply selected changes.
- **IV3ExportFlow**: Create Programma with IV3Mapping, export to IV3 format, verify file structure matches BZK spec.

### API Tests (PHPUnit + HTTP)

- All REST endpoints return correct status codes (200 OK, 201 Created, 400 Bad Request, 403 Forbidden, 404 Not Found).
- PUT /api/bbv/programmas/{id}/delete with active Doelen returns 400 (REQ-BBV-001).
- POST /api/bbv/templates/{id}/install atomically creates all related objects or none (test via rollback scenario).
- POST /api/bbv/maatregelen/{id}/plan integrates with planix API (mocked).

## Documentation (ADR-010)

- Changelog entry: "Add BBV Programma/Doel/Indicator/Maatregel datamodel and one-click template installation. Gemeenten can now stand up a programmastructuur in <1 minute and sync with financeq, planix, and external indicator sources."
- Admin docs: Template installation workflow, template upgrade process, IV3-export instructions, troubleshooting (why is the export invalid?).
- Developer docs: Schema definitions, API reference, custom extension points (e.g., how to add a new field to Programma without breaking existing templates).

## i18n (ADR-007)

- UI strings: "Programma", "Doel", "Indicator", "Maatregel", "IV3 Export", "Template Installatie" are localised to EN + NL.
- Admin docs: Dutch-language by default (target: gemeenten are primarily Dutch-speaking).
- API responses: All enums (status, themaCluster, doelgroep) are English in the JSON; UI translates via i18n keys.
