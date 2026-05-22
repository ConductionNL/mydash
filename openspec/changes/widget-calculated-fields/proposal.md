# Widget Calculated Fields

## Why

Currently, every value shown in a mydash widget comes from a single source: a register query, an OpenConnector call, or a simple aggregate computed by the widget engine. The moment a user wants something composite — "revenue minus refunds as a percentage of revenue", "average ticket resolution time across two queues", "this widget's count divided by another widget's count" — they must either build a custom widget, change the underlying register, or copy numbers into a spreadsheet. Calculated fields make those compositions a first-class feature of the widget itself.

This change introduces a new `widget.calculatedFields` array enabling users to define named expressions that derive values from other fields in the same widget, fields in other widgets on the same dashboard, constants, and a set of built-in functions. The formula language is deliberately small — enough for ratios, conditional formatting, date arithmetic, and string composition, but constrained to prevent silent bugs.

## What Changes

- Add a new `calculatedFields` array to the widget definition schema, with entries containing `id`, `name`, `label`, `expression`, `compiledExpression` (AST), `returnType`, `evaluationMode`, `formatOptions`, and optional `description`.
- Implement a dual-evaluation strategy: client-side evaluation (instant, no round-trip, non-persistent) for dashboards, and optional server-side evaluation via OpenRegister's `computed-fields` capability for queries/alerts/export.
- Build a TypeScript expression parser producing a deterministic AST that validates types at save time and refuses expressions with parsing errors or type mismatches.
- Implement a TypeScript evaluator on the client that recomputes calculated fields reactively as filters and source widgets change.
- Implement a PHP evaluator on the server that mirrors the TypeScript evaluator exactly (sharing the same test suite) so both produce identical results.
- Add cycle-detection for cross-widget references (`widget:<id>` syntax) to prevent infinite-loop dependencies.
- Surface parse and type errors inline next to widgets so dashboards never show silent wrong numbers.

## Affected Code Units

- **Data layer**: `lib/Db/Widget.php` schema extends with `calculatedFields` array field
- **Service layer**: new `CalculatedFieldService.php` with parser, type-checker, cycle detector, and evaluators
- **API**: `lib/Controller/WidgetController.php` validates expressions on save via the service
- **Frontend store**: `src/stores/widgets.js` — evaluator runs on widget data changes, cross-widget dependency tracking
- **Frontend UI**: widgets display calculated-field values and inline error indicators with error details on hover
- **Server-side integration**: `CalculatedFieldService::materializeForOpenRegister()` pushes server-mode fields to OpenRegister's `computed-fields` on widget save

## Capabilities

### New Capability: `calculated-fields`

- **REQ-CALC-001**: Formula parser produces deterministic AST, validates before save
- **REQ-CALC-002**: Type checking at parse time, rejects type mismatches
- **REQ-CALC-003**: Client-side evaluator reactive to filter and widget changes, no server round-trip
- **REQ-CALC-004**: Server-side evaluation via OpenRegister `computed-fields`, keeper-upper synchronisation on widget save
- **REQ-CALC-005**: Identical results between client and server evaluators (IEEE 754 precision, null propagation)
- **REQ-CALC-006**: Cross-widget references with cycle detection
- **REQ-CALC-007**: Cross-widget references downgraded to client when source mismatch
- **REQ-CALC-008**: Inline error display preserves dashboard usability
- **REQ-CALC-009**: Allowlisted function set with named signatures
- **REQ-CALC-010**: Format options applied at render time only

## Dependencies

- **openregister `computed-fields` capability** — server-mode fields delegate to this for materialisation
- **widget infra** — existing renderers (table, chart, single-value) display calculated fields without modification
- **alerting integration** (future: widget-alerting change) — alert rules can reference calculated-field paths
- No new composer or npm dependencies; re-uses existing `NumberFormatter` (PHP) and `Intl.NumberFormat` (browser) for locale-aware formatting

## Target Users

- **Data analysts** — primary authors; get spreadsheet-familiar syntax with strict types, instant client-side feedback as they iterate
- **Dashboard consumers** — see calculated values like any other field; get inline error indicators when evaluation fails
- **API consumers** — server-mode calculated fields are materialised and queryable via OpenRegister directly
- **Platform administrators** — govern the function allowlist per-instance via configuration

## Notes

- The choice to evaluate client-side by default (not persisting) mirrors how Looker and dbt separate cheap derivations (client) from shared derivations (server). Both paths are available; the user chooses via `evaluationMode`.
- The AST-based approach with shared test suites between TypeScript and PHP ensures the two implementations cannot drift and makes it easy for contributors to understand or extend the evaluator.
- Cycle detection uses classic topological sort, proven in Excel and Google Sheets.
- No schema migration required; calculated fields live inside the widget definition, not as separate register rows.
