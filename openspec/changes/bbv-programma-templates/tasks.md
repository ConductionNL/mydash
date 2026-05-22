# Tasks — BBV Programma Templates

## Schema Setup Tasks

- [ ] Task 1: Create OpenRegister schema definitions for `Programma`, `Doel`, `Indicator`, `IndicatorMeting`, `Maatregel`, `IV3Mapping` in `lib/Settings/bbv_register.json`; include all required and optional fields per design.md; ensure schema.org vocabulary alignment and ADR-001 compliance
- [ ] Task 2: Create template-entity schemas `TemplateProgramma`, `TemplateDoel`, `TemplateIndicator` with `_templateId` and `_sequenceOrder` fields in same `bbv_register.json`
- [ ] Task 3: Create `ProgrammaTemplate` schema definition
- [ ] Task 4: Register all schemas with OpenRegister via migration step (app install / repair phase)
- [ ] Task 5: Create seed data (3 templates: G4, M50, kleine gemeenten) in `components.objects[]` within `bbv_register.json` using `@self` envelope; ensure Dutch values (gemeente names, realistic IV3 mappings, budget amounts)

## Backend Service Tasks

- [ ] Task 6: Create `lib/Service/ProgrammaService.php` with methods:
  - `createProgramma(array $data): Object`
  - `listProgrammas(array $filters): array`
  - `getProgramma(string $id): Object`
  - `updateProgramma(string $id, array $data): Object`
  - `deleteProgramma(string $id): void` — implements REQ-BBV-001 (hiërarchie-integriteit guard)
  - All CRUD uses `ObjectService` (no custom DB access); deletion checks for active child Doelen and throws exception with blocker details

- [ ] Task 7: Create `lib/Service/DoelService.php` with CRUD methods mirroring ProgrammaService; deletion checks for active Indicatoren and Maatregelen

- [ ] Task 8: Create `lib/Service/IndicatorService.php` with CRUD; includes required-indicator auto-creation logic for REQ-BBV-002
  - `createIndicator(string $doelId, array $data): Object`
  - `listRequiredIndicatorsForDoel(string $doelId): array` — queries BZK configuration
  - `createRequiredIndicators(string $doelId): array` — auto-creates missing BZK-required indicators

- [ ] Task 9: Create `lib/Service/MaatregelService.php` with CRUD + planix linking (REQ-BBV-007):
  - `linkToPlanix(string $maatregelId): void` — calls planix API to create project, saves projectId to Maatregel.planixProjectId
  - `syncPlanixStatus(string $maatregelId): void` — polls planix and updates Maatregel.status

- [ ] Task 10: Create `lib/Service/TemplateService.php` with:
  - `listTemplates(): array` — returns all ProgrammaTemplate objects
  - `installTemplate(string $templateId): InstallationReport` — REQ-BBV-003: atomically clones all Template* records to Programma/Doel/Indicator/IV3Mapping, wraps in transaction, returns report
  - `suggestDifferences(string $templateId): array` — REQ-BBV-004: compares installed template version to new version, returns per-field differences
  - `applyUpgrade(string $templateId, array $selectedDiffs): void` — applies selected diffs from upgrade

- [ ] Task 11: Create `lib/Service/IV3ExportService.php` with:
  - `generateExport(string $templateFormat): string` — REQ-BBV-005: generates IV3-compliant export (XML or CSV format per BZK spec)
  - `validateMappingSums(): void` — checks each Programma's IV3Mapping percentages sum to 100% ±1%, throws exception with details if invalid
  - `exportToFile(string $path): void` — writes export to filesystem

- [ ] Task 12: Create `lib/Service/IndicatorMeteringService.php` with:
  - `createMeteringFromWebhook(array $webhook): IndicatorMeting` — REQ-BBV-006: receives push from openconnector, creates IndicatorMeting record
  - `checkVariance(IndicatorMeting $meting): void` — compares waarde against streefwaarde, notifies doel-eigenaar if variance > threshold

- [ ] Task 13: Create `lib/Service/ProgrammaBudgetService.php` with:
  - `syncFromFinanceq(string $programmaId): void` — REQ-BBV-008: queries financeq API for begroting/realisatie per IV3-taakveld, applies IV3Mapping to distribute across Programma, calculates realisatie-percentage, saves to Programma.geraamdBudget / gerealiseerdBudget
  - `calculateRealisatiePercentage(Programma $programma): float` — helper

- [ ] Task 14: Create exception classes in `lib/Exception/`:
  - `HierarchyIntegrityException` — thrown when deleting Programma/Doel with active children
  - `MissingRequiredIndicatorException` — thrown when Doel missing BZK-required indicators
  - `InvalidIV3MappingException` — thrown when IV3 mapping sums don't equal 100%
  - `PlanixIntegrationException` — thrown when planix API call fails
  - `FinanceqIntegrationException` — thrown when financeq API call fails

## Backend Controller Tasks

- [ ] Task 15: Create `lib/Controller/ProgrammaController.php` with endpoints per design.md's API Surface:
  - POST /api/bbv/programmas (create)
  - GET /api/bbv/programmas (list)
  - GET /api/bbv/programmas/{programmaId} (detail with relations)
  - PUT /api/bbv/programmas/{programmaId} (update)
  - DELETE /api/bbv/programmas/{programmaId} (delete with guard)

- [ ] Task 16: Create `lib/Controller/DoelController.php` with CRUD endpoints (POST, GET, GET/{id}, PUT, DELETE)

- [ ] Task 17: Create `lib/Controller/IndicatorController.php` with CRUD + webhook receiver:
  - Endpoints: POST, GET, GET/{id}, PUT, DELETE
  - POST /api/bbv/indicatoren/{id}/metingen (openconnector webhook receiver for REQ-BBV-006)
  - GET /api/bbv/indicatoren/{id}/metingen (list IndicatorMetingen)

- [ ] Task 18: Create `lib/Controller/MaatregelController.php` with CRUD + planix linking:
  - Endpoints: POST, GET, GET/{id}, PUT, DELETE
  - POST /api/bbv/maatregelen/{id}/plan (link to planix, REQ-BBV-007)

- [ ] Task 19: Create `lib/Controller/TemplateController.php` with:
  - GET /api/bbv/templates (list available templates)
  - POST /api/bbv/templates/{id}/install (install template, REQ-BBV-003)
  - GET /api/bbv/templates/{id}/differences (get upgrade diff, REQ-BBV-004)
  - POST /api/bbv/templates/{id}/upgrade (apply upgrade)

- [ ] Task 20: Create `lib/Controller/IV3Controller.php` with:
  - GET /api/bbv/iv3/export (generate IV3 export, REQ-BBV-005)

- [ ] Task 21: Create `lib/Controller/BudgetController.php` with:
  - GET /api/bbv/programmas/{id}/realisatie (get realisatie from financeq, REQ-BBV-008)
  - POST /api/bbv/sync/financeq (trigger manual sync)

- [ ] Task 22: Register all controller routes in `appinfo/routes.php`

## Frontend Store Tasks

- [ ] Task 23: Create `src/stores/bbvStore.js` using `createObjectStore()` pattern (ADR-001):
  - `createObjectStore('programma')` — auto-generated CRUD store for Programma
  - `createObjectStore('doel')` — auto-generated CRUD store for Doel
  - `createObjectStore('indicator')` — auto-generated CRUD store for Indicator
  - `createObjectStore('maatregel')` — auto-generated CRUD store for Maatregel
  - `createObjectStore('iv3Mapping')` — auto-generated CRUD store for IV3Mapping
  - Add computed getters: `programmasWithDoelen()`, `doelWithIndicators(doelId)`, `indicatorWithMetings(indicatorId)`, `maatregel WithPlanixStatus(maatregelId)`

## Frontend View Tasks

- [ ] Task 24: Create `src/views/ProgrammaTree.vue`:
  - Uses CnDetailPage for Programma view/edit
  - Uses CnDataTable to list Doelen under selected Programma (filter by programmaId)
  - Uses CnDetailPage for Doel view/edit
  - Uses CnDataTable to list Indicatoren under selected Doel
  - Uses CnDetailPage for Indicator view/edit with historical IndicatorMetingen list
  - Implements REQ-BBV-001 error handling: shows blocker details when user tries to delete non-empty entity
  - Implements REQ-BBV-002: shows modal with required indicator list when Doel created without them

- [ ] Task 25: Create `src/views/MaatregelManager.vue`:
  - Uses CnDetailPage for Maatregel view/edit
  - Includes "Plan in Planix" button that calls POST /api/bbv/maatregelen/{id}/plan (REQ-BBV-007)
  - Shows planix project link + status once linked

- [ ] Task 26: Create `src/views/TemplateInstaller.vue`:
  - CnFormDialog or custom component for template selection
  - Template picker dropdown (GET /api/bbv/templates)
  - "Installeer" button → POST /api/bbv/templates/{id}/install
  - Progress indicator + installation report showing counts per entity type (REQ-BBV-003)

- [ ] Task 27: Create `src/views/TemplateUpgradeWizard.vue`:
  - GET /api/bbv/templates/{id}/differences and display per-field checkboxes (REQ-BBV-004)
  - Each diff shows: field name, old value, new value, [checkbox]
  - If local customization detected (before/after value differs from template), show warning modal
  - Apply button → POST /api/bbv/templates/{id}/upgrade with selected diffs

- [ ] Task 28: Create `src/views/IV3Export.vue`:
  - GET /api/bbv/iv3/export to trigger export
  - Shows validation status (REQ-BBV-005: mapping sums check)
  - Download button for resulting file
  - Error handling: displays IV3MappingException details (unmapped programmas, incorrect percentages)

- [ ] Task 29: Create `src/views/BudgetSyncPanel.vue`:
  - Shows last-sync timestamp from financeq (REQ-BBV-008)
  - "Sync nu" button → POST /api/bbv/sync/financeq
  - Displays per-Programma begraamd/gerealiseerd/percentage (via GET /api/bbv/programmas/{id}/realisatie)
  - Shows variance warnings if realisatie <80% or >120% of begraamd

- [ ] Task 30: Create component `src/components/ProgrammaHierarchy.vue`:
  - Visual tree widget showing Programma → Doel → Indicator → Maatregel structure
  - Used in ProgrammaTree.vue as secondary visualization
  - Click to navigate to detail view

## Integration & Testing Tasks

- [ ] Task 31: Create database migration `lib/Migration/VersionXXXXDate2025...php`:
  - Register all BBV schemas via `ConfigurationService::importFromApp('bbv', $registerDefinition)`
  - Seed templates and example data via same import pipeline

- [ ] Task 32: PHPUnit tests in `tests/unit/Service/`:
  - ProgrammaServiceTest: CRUD, deletion guard (REQ-BBV-001), audit trail
  - DoelServiceTest: CRUD, deletion guard
  - IndicatorServiceTest: CRUD, required-indicator auto-creation (REQ-BBV-002)
  - MaatregelServiceTest: CRUD, planix linking (REQ-BBV-007), mock planix API
  - TemplateServiceTest: list, install atomicity (REQ-BBV-003), install rollback on error, upgrade diffing (REQ-BBV-004), selective apply
  - IV3ExportServiceTest: generate export format, mapping validation (REQ-BBV-005), error cases (unmapped, incorrect sums)
  - IndicatorMeteringServiceTest: webhook receiver (REQ-BBV-006), variance notification
  - BudgetServiceTest: financeq sync (REQ-BBV-008), multi-taakveld distribution, percentage calculation

- [ ] Task 33: Vitest integration tests in `src/__tests__/`:
  - ProgrammaTree.spec.js: mount component, create Programma → Doel → Indicator flow, verify CRUD works, verify deletion guard error shown
  - TemplateInstaller.spec.js: select template, click install, mock API, verify install report displayed
  - TemplateUpgradeWizard.spec.js: mock differences, select some checkboxes, apply, verify API call
  - IV3Export.spec.js: verify validation error shown for invalid mapping sums, verify download works
  - BudgetSync.spec.js: mock financeq API, verify sync button triggers update, verify variance warning displayed

- [ ] Task 34: API integration tests (Postman / Vitest + HTTP mock):
  - POST /api/bbv/programmas — 201 Created
  - GET /api/bbv/programmas — 200 OK with list
  - DELETE /api/bbv/programmas/{id} with child Doelen — 400 Bad Request with blocker details
  - POST /api/bbv/templates/{id}/install — 200 OK with installation report
  - GET /api/bbv/iv3/export with invalid mapping — 400 Bad Request with validation error
  - POST /api/bbv/maatregelen/{id}/plan (mock planix) — 200 OK with projectId set

- [ ] Task 35: Create `tests/integration/openconnector-webhook.php`:
  - Mock openconnector webhook POST /api/bbv/indicatoren/{id}/metingen
  - Verify IndicatorMeting created, variance notification sent if applicable

## Documentation & Configuration Tasks

- [ ] Task 36: Create changelog entry in `CHANGELOG.md`:
  - "feat: Add BBV Programma/Doel/Indicator/Maatregel datamodel with one-click template installation. Gemeenten can stand up a programmastructuur in <1 minute. Includes IV3-export, financeq integration, planix linking, and openconnector sync of external indicator sources."

- [ ] Task 37: Create admin documentation (markdown in `docs/admin/bbv-templates.md`):
  - Template installation workflow (screenshots)
  - Template upgrade process (how to handle local customizations)
  - IV3-export instructions + troubleshooting (why is validation failing?)
  - financeq sync configuration
  - planix project linking
  - Required indicators: how to add new ones (developer docs reference)

- [ ] Task 38: Create developer documentation (markdown in `docs/developer/bbv-data-model.md`):
  - Schema definitions (Programma, Doel, Indicator, Maatregel, IV3Mapping)
  - Custom extension points (how to add fields without breaking templates)
  - Template structure (TemplateProgramma, TemplateDoel, TemplateIndicator)
  - API reference (all endpoints + request/response examples)
  - Integration points (financeq, planix, openconnector webhooks)

- [ ] Task 39: Configure BZK-required indicators in `lib/Config/BzkIndicators.php`:
  - Static mapping: programma themaCluster + doel type → list of required indicator codes
  - Example: (themaCluster='sociaal', doelType='jeugdzorg') → ['BZK-JZ-001', 'BZK-JZ-002', 'BZK-JZ-003']
  - Source: BZK's published mandatory indicators list

- [ ] Task 40: Configure IV3-taakvelden mapping in `lib/Config/IV3Taxonomy.php`:
  - Map programma themaCluster to IV3-taakveld codes
  - Example: themaCluster='sociaal' → '15.01.01', themaCluster='ruimte' → '33.01.01'
  - Source: BZK's IV3 list

## Quality Gates Tasks

- [ ] Task 41: Quality — `composer check:strict` passes; no PHPStan errors
- [ ] Task 42: Quality — ESLint clean (`npm run lint`)
- [ ] Task 43: Quality — All tests pass (`composer test` + `npm run test`)
- [ ] Task 44: Quality — Code coverage >80% for new services
- [ ] Task 45: Deduplication check: verify no overlap with existing ObjectService, RelationService, TemplateService (if exists in other apps); document findings in REUSE section (design.md already done)

## Verification

- `openspec validate` exits clean (all artifacts pass schema)
- Manual QA: Install G4 template in dev environment, verify all 4 Programma's + 10 Doelen + 35 Indicatoren created
- Manual QA: Upload template v1.1, verify upgrade wizard shows differences
- Manual QA: Export IV3 with invalid mapping (80%), verify validation error shown + export blocked
- Manual QA: Create Maatregel, click "Plan in Planix" (mock planix), verify projectId saved
- Manual QA: Mock openconnector webhook POST IndicatorMeting, verify notification sent and meting visible in UI

## Seed Data Generation (during app install)

Per ADR-001, the app MUST include 3–5 realistic objects per schema:

- [ ] Task 46: Seed 1 Programma "Sociaal Domein" (code=01, themaCluster=sociaal, status=vastgesteld)
- [ ] Task 47: Seed 2 Doelen under Sociaal (Jeugdzorg, Ouderenzorg)
- [ ] Task 48: Seed 3–5 Indicatoren per Doel (including BZK-required ones)
- [ ] Task 49: Seed 1 Maatregel per Doel ("Opschaling capaciteit jeugdzorg", "Digitalisering ouderenregistratie")
- [ ] Task 50: Seed 1 IV3Mapping per Programma (map to realistic taakveld)
