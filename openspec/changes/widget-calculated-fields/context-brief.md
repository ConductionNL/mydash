---
status: draft
---
# Widget Calculated Fields

## Placement & Information Architecture

**Placement type:** `WIDGET` — Widget shown on a dashboard or another page. Has no dedicated page of its own; renders inside an existing surface as a tile/panel/card.

**Lives at:** Dashboards + Catalog / Widget context-menu + Catalog reference

**Rationale:** Per-widget editor + reference docs  
_Source: /tmp/ia-mydash-openregister.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Today every value shown in a mydash widget comes from a single data source: a register query, an OpenConnector call, or a simple aggregate computed by the widget engine. The moment a user wants something composite — "revenue minus refunds as a percentage of revenue", "average ticket resolution time across two queues", "this widget's count divided by another widget's count" — they have to either build a custom widget, change the underlying register, or copy numbers into a spreadsheet. Calculated fields make those compositions a first-class feature of the widget itself.

A calculated field is a named expression attached to a widget that produces a value derived from other fields in the same widget, fields in other widgets on the same dashboard, constants, and a small set of built-in functions. The formula language is a deliberately small subset of the Excel/JEL family — enough for ratios, conditional formatting, date arithmetic, and string composition, but small enough that the parser and evaluator can be implemented twice (once in TypeScript for client-side recomputation as filters change, once in PHP for server-side evaluation when the field needs to be materialised back into OpenRegister through the `computed-fields` capability).

The dual evaluation is the central design choice. A calculated field that only feeds the widget can stay client-side: it recomputes instantly as the user drags a filter, never round-trips to the backend, and never persists. A calculated field that the user wants to use in alerts (see widget-alerting), share through the API, or query from outside mydash needs to be evaluated server-side and ideally stored on the underlying object — that path delegates to OpenRegister's `computed-fields` so the same expression runs once at write time and the materialised value is indexed, searchable, and consistent with whatever any other consumer sees. Both evaluators share a single AST and a single test suite so the two implementations cannot drift.

The type system is intentionally minimal — `number`, `string`, `boolean`, `date`, `duration`, `null` — but strict: an expression that adds a number to a string fails at parse time with a clear error pointing at the offending sub-expression. The error display is part of the brief because the most common failure mode in spreadsheet-style formulas is a silent `#REF!` that nobody notices until a report is wrong; mydash will surface errors inline next to the widget and prevent saving a widget whose calculated fields don't parse.

## Data Model

A calculated field is stored inside the widget definition as an entry in `widget.calculatedFields`. It does not need its own register object because its lifecycle is bound to the widget — delete the widget and the calculated field goes with it.

Each entry has: `id` (UUID, stable across renames), `name` (string, the identifier used in other formulas — must be a valid identifier, unique within the widget), `label` (string, the human-readable label shown in the UI), `expression` (string, the formula source), `compiledExpression` (object, the AST cached at save time), `returnType` (one of the supported types, inferred from the AST and stored for fast filtering), `evaluationMode` (`client`, `server`, or `both`), `serverComputedFieldId` (UUID, populated when `evaluationMode` is `server` or `both` — references the OpenRegister `computed-fields` entry that materialises the value), `formatOptions` (object: number format string, date locale/pattern, currency code, etc.), `description` (optional markdown shown as a tooltip), `createdAt`, `updatedAt`.

The AST node types are: `Literal` (`{type:'literal', valueType, value}`), `FieldRef` (`{type:'fieldRef', source: 'self'|'widget:<id>', path}`), `CalculatedRef` (`{type:'calculatedRef', name}` — references another calculated field on the same widget), `Binary` (`{type:'binary', op, left, right}` — ops: `+ - * / %`, `< <= > >= == !=`, `and or`), `Unary` (`{type:'unary', op, operand}` — ops: `- not`), `If` (`{type:'if', cond, then, else}`), `Call` (`{type:'call', name, args}` — calls a built-in function). A `Call` whose function name is not in the allowlist fails at parse time.

The built-in function set in v1: math (`abs`, `round`, `ceil`, `floor`, `min`, `max`, `pow`, `sqrt`, `log`, `sum`, `avg`), aggregate (`count`, `countIf`, `sumIf`, `avgIf` — operate over array-valued field refs), date/time (`now`, `today`, `dateDiff`, `dateAdd`, `formatDate`, `parseDate`), duration (`durationBetween`, `durationFormat`), string (`concat`, `upper`, `lower`, `trim`, `contains`, `startsWith`, `endsWith`, `replace`, `format`), conditional (`coalesce`, `ifNull`, `case`), type coercion (`toNumber`, `toString`, `toBool`, `toDate`). Every function has a documented signature with named typed parameters; the parser validates arity and types at save time, not at evaluation time.

A cross-widget reference (`widget:<id>` in a `FieldRef`) is resolved at evaluation time. On the client, the dashboard runtime maintains a dependency graph and re-evaluates affected calculated fields when any source widget refreshes. On the server, cross-widget refs are only allowed when both widgets resolve to data in the same OpenRegister register/schema — otherwise the field is forced to `evaluationMode=client`.

## Requirements

### REQ-CALC-001 — Formula parser produces a deterministic AST

The system SHALL parse a calculated-field expression into a canonical AST at save time and SHALL refuse to persist any widget whose calculated-field expressions fail to parse.

- GIVEN a valid expression `(revenue - refunds) / revenue * 100`
  WHEN the widget is saved
  THEN the parser SHALL produce a `Binary` AST and SHALL store it in `compiledExpression`.
- GIVEN an expression with mismatched parentheses `(revenue - refunds`
  WHEN the widget is saved
  THEN the API SHALL respond 400 with a parse error that includes the column position and SHALL NOT persist the widget.
- GIVEN two expressions that differ only by whitespace
  WHEN both are parsed
  THEN their `compiledExpression` ASTs SHALL be deep-equal.

### REQ-CALC-002 — Type checking at save time, not at evaluation time

The system SHALL infer the type of every sub-expression at parse time and SHALL reject expressions whose operator/function arguments do not match the declared signature.

- GIVEN an expression `revenue + customerName` where `revenue:number` and `customerName:string`
  WHEN the widget is saved
  THEN the API SHALL respond 400 with a type error naming both operands and SHALL NOT persist the widget.
- GIVEN an expression `round(revenue, "two")`
  WHEN the widget is saved
  THEN the API SHALL respond 400 with a function-signature error.
- GIVEN an expression `coalesce(null, revenue, 0)` where `revenue:number`
  WHEN the widget is saved
  THEN the inferred `returnType` SHALL be `number` and the widget SHALL persist successfully.

### REQ-CALC-003 — Client-side evaluator reactive to filter changes

The client-side evaluator SHALL recompute every `client` and `both` mode calculated field whenever any source field, source widget, or filter changes, and SHALL never round-trip to the server for an evaluation.

- GIVEN a widget with a calculated field `profit = revenue - cost`
  WHEN the user changes a dashboard filter that affects `revenue` and `cost`
  THEN `profit` SHALL be recomputed inside the browser and the new value SHALL appear without an HTTP request.
- GIVEN a calculated field referencing `widget:abc123`
  WHEN the source widget `abc123` refreshes its data
  THEN the dependent calculated field SHALL recompute in the same tick.

### REQ-CALC-004 — Server-side evaluation via OpenRegister `computed-fields`

The system SHALL, for every calculated field in `evaluationMode=server` or `both`, create a corresponding `computed-fields` entry on the underlying schema and SHALL keep the two in sync on widget save.

- GIVEN a new calculated field with `evaluationMode=server`
  WHEN the widget is saved
  THEN a `computed-fields` entry SHALL be created on the source schema with the same expression and `serverComputedFieldId` SHALL be populated.
- GIVEN an existing server-mode calculated field whose expression is edited
  WHEN the widget is saved
  THEN the linked `computed-fields` entry SHALL be updated with the new expression in the same transaction.
- GIVEN a server-mode calculated field whose widget is deleted
  WHEN the deletion completes
  THEN the linked `computed-fields` entry SHALL be removed.

### REQ-CALC-005 — Identical results between client and server evaluators

Both evaluators SHALL produce identical results for every expression in the shared test suite, with documented and tested handling of numeric precision (IEEE 754 double on both sides) and null propagation.

- GIVEN any expression in the shared evaluator test suite
  WHEN evaluated by the TypeScript and PHP evaluators on the same input
  THEN the two results SHALL be deep-equal (for numbers: equal to within 1e-9 of each other).
- GIVEN an expression `a + null` where `a` is any non-null value
  WHEN evaluated by either evaluator
  THEN the result SHALL be `null` and SHALL NOT throw.

### REQ-CALC-006 — Cross-widget references with cycle detection

The system SHALL allow a calculated field to reference fields on other widgets on the same dashboard and SHALL refuse any dependency graph that contains a cycle.

- GIVEN widget A's calculated field references `widget:B.x` and widget B's calculated field references `widget:A.y`
  WHEN either widget is saved
  THEN the API SHALL respond 400 with a `cycleDetected` error naming both widgets.
- GIVEN widget A's calculated field references `widget:B.x` where B has no calculated fields pointing back
  WHEN A is saved
  THEN the save SHALL succeed and A's calculated field SHALL re-evaluate whenever B refreshes.

### REQ-CALC-007 — Cross-widget references downgraded to client when source mismatch

When a `server` or `both` mode calculated field references a widget whose data does not live in the same OpenRegister register/schema, the system SHALL downgrade the field to `client` mode at save time and SHALL warn the user.

- GIVEN a calculated field with `evaluationMode=server` that references a widget backed by a different register
  WHEN the widget is saved
  THEN the field's `evaluationMode` SHALL be persisted as `client` and the API response SHALL include a warning explaining the downgrade.
- GIVEN the same field where both widgets share the same register and schema
  WHEN the widget is saved
  THEN `evaluationMode` SHALL remain `server` and a `computed-fields` entry SHALL be created.

### REQ-CALC-008 — Inline error display preserves dashboard usability

When a calculated field fails to evaluate at runtime (null deref through a `not null` path, division by zero where divisor is not guarded, runtime function error), the system SHALL render an inline error indicator on the widget without breaking other widgets on the dashboard.

- GIVEN a calculated field `ratio = a / b` where the user has not added a divide-by-zero guard and `b` is 0 in a given evaluation
  WHEN the widget renders
  THEN the field's cell SHALL display an error indicator with the expression and the value of `b` on hover; other widgets on the dashboard SHALL render normally.
- GIVEN the same calculated field on the server-side evaluator
  WHEN the materialiser runs
  THEN the materialised value SHALL be `null` and the OpenRegister computed-field error log SHALL record the same expression + input.

### REQ-CALC-009 — Allowlisted function set with named signatures

The parser SHALL accept only functions on the documented allowlist and SHALL reject any call to an unknown identifier as a function with a clear error.

- GIVEN an expression `eval("revenue * 2")`
  WHEN the widget is saved
  THEN the API SHALL respond 400 with `unknownFunction: eval` and SHALL NOT persist.
- GIVEN an expression `sum(revenue, cost, profit)`
  WHEN the widget is saved
  THEN the parser SHALL validate that `sum` accepts variadic numbers and SHALL accept the expression.

### REQ-CALC-010 — Format options applied at render time only

The system SHALL apply `formatOptions` (number format, date format, currency, locale) only at render time on the client, and SHALL store and evaluate the underlying value in its native type.

- GIVEN a calculated field with `returnType=number`, `formatOptions={style:'currency', currency:'EUR', locale:'nl-NL'}`, and an evaluated value of `1234.5`
  WHEN the widget renders for a Dutch user
  THEN the displayed string SHALL be `€ 1.234,50` and the underlying value passed to alerting/export SHALL remain `1234.5`.
- GIVEN a calculated field with `returnType=date` and `formatOptions={pattern:'yyyy-MM-dd'}`
  WHEN the widget renders
  THEN the displayed string SHALL match the pattern and the underlying value SHALL remain an ISO 8601 instant.

## Standards & Sources

The formula language draws on three lineages. Operator precedence and the conditional `if(cond, then, else)` shape follow Excel — the most familiar spreadsheet language for the target user. The strict typing, named function signatures, and refusal-to-evaluate-on-type-mismatch behaviour follow JEXL/JEL and OpenFormula's strict mode rather than Excel's permissive coercion, because silent coercion is exactly the bug class that turns dashboards into wrong answers. The AST node taxonomy is conventional Pratt-parser output (literal/binary/unary/call) so a future contributor can pick up the parser without project-specific knowledge.

The split between client-side and server-side evaluation mirrors how Looker (LookML's `dimension` vs `measure`) and dbt (`view` vs `materialized`) think about derived columns: cheap derivations stay near the consumer, expensive or shared derivations are pushed into the source of truth. The choice to delegate server-side evaluation to OpenRegister's `computed-fields` rather than re-implementing materialisation in mydash is the same principle — one source of truth for "this field is derived from these inputs by this formula", consumed by mydash, openregister API consumers, and (through it) anything else.

Number handling on both sides is IEEE 754 double — PHP's float and JavaScript's Number are the same primitive — so equality within 1e-9 is sufficient and avoided the temptation to introduce a decimal library that would diverge on rounding rules. Date handling uses ISO 8601 instants on the wire and IANA timezone names for any tz-aware function. Currency formatting uses the ICU MessageFormat / CLDR data already shipped with both the browser (`Intl.NumberFormat`) and PHP (`NumberFormatter`); both targets pull from the same CLDR version so rendered strings are identical for the same locale.

The cycle-detection rule borrows the classic topological-sort approach used by every formula engine that supports cross-cell references (Excel, Google Sheets); the deliberate refusal to even try iterative recalculation (Excel's opt-in) keeps the semantics simple and explains every error to the user at save time rather than at evaluation time.

## Cross-app integration

- **openregister `computed-fields`**: server-mode fields delegate to this capability so the materialised value is written back to the underlying object, indexed, and consistent with anything else that reads the object. The mydash field is the source-of-truth for the expression; the OpenRegister entry is the materialised view. The two are kept in sync on every widget save, and the OpenRegister side carries the canonical evaluation history.
- **widget infra**: calculated fields are first-class fields on the widget's result set, so existing widget renderers (table, chart, single-value, etc.) display them without changes. Alerting (see widget-alerting brief) treats calculated fields exactly like source fields, so an alert rule can use `condition.field` pointing at a calculated-field path.
- **openconnector**: not used at evaluation time. When the source widget queries an OpenConnector source, the calculated field evaluates over the response payload like any other source.
- **AI Chat Companion (ADR-034)**: the chat MAY help a user author an expression by translating "show me revenue minus refunds as a percentage" into the formula syntax — that flow uses the parser to validate the LLM's output before showing it to the user.
- **docudesk**: no integration in v1.

## Target users

- **Data analysts** are the primary authors — they get spreadsheet-familiar syntax with stricter types, can iterate live on the dashboard because client-side mode is instant, and can promote a field to server-side when they want the value to be queryable elsewhere.
- **Dashboard consumers** see the calculated values like any other field; they get inline error indicators (with the expression on hover) when a field can't evaluate so a wrong number never silently appears.
- **Application authors** who build composite KPIs across multiple registers benefit from server-mode promotion — the materialised value is then queryable from openregister directly, not only from inside mydash.
- **Platform administrators** govern the function allowlist (it is per-instance configurable) so an organisation that wants to disable, say, `now()` in formulas to keep evaluations deterministic can do so without forking the parser.
