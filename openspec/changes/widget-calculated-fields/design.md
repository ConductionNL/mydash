# Design — Widget Calculated Fields

## Context

Today's widget infrastructure displays values directly from a source (register query, OpenConnector endpoint, aggregation). When users need derived values — ratios, composites across widgets, conditional formatting — they reach for spreadsheets or custom code. Calculated fields bridge this gap by evaluating expressions client-side as filters change, and optionally server-side for persistence.

The core design choice is **dual evaluation**: client-side for instant feedback and no persisting, server-side for queryability and consistency with external consumers. Both evaluators share a single AST and test suite so they cannot diverge.

## Goals / Non-Goals

**Goals:**

- Enable spreadsheet-like formulas on widgets without custom code or database changes.
- Provide instant feedback (client-side evaluation) as users change filters and dashboards.
- Make calculated fields queryable via OpenRegister by materialising server-mode fields.
- Fail fast at save time with clear parse and type errors — never show silent wrong numbers.
- Keep the evaluators deterministic and auditable — strict typing, no coercion, no permissive Excel-like silent NaN.

**Non-Goals:**

- Iterative recalculation (Excel's opt-in mode). We reject cycles explicitly.
- Per-instance formula extensions. Function allowlist is configurable but shipped.
- Undo/version history for expressions. Out of scope; can layer on later if needed.
- Spreadsheet-like multi-cell recalc (e.g., fill-down). Calculated fields are per-widget singleton values.
- Visual formula editor or cell-selection picker. Out of scope; future enhancement.

## Decisions

### D1: Dual evaluation — client by default, server on opt-in

**Decision**: Every calculated field has an `evaluationMode: 'client' | 'server' | 'both'`. Client mode evaluates in the browser and does not persist. Server mode creates a `computed-fields` entry in the underlying OpenRegister schema and materialises the value. Both mode does both.

**Alternatives considered:**

- Evaluate everywhere (always server). Rejected — causes latency on filter changes and requires backend round-trip for every keystroke.
- Evaluate nowhere (client-only, never server). Rejected — doesn't satisfy use cases like alerting, export, and cross-system queries.
- Single mode per field, chosen at design time. Rejected — limits flexibility; some fields start client-only, then get promoted to server for wider use.

**Rationale**: Users iterate client-side (instant), promote to server when ready to share. Mirrors Looker (dimension vs measure) and dbt (view vs materialised).

### D2: AST-based parser with compile-time validation

**Decision**: Parse and type-check at save time. Store the AST (`compiledExpression`) alongside the source text. Reject any expression that fails to parse or type-check.

**Alternatives considered:**

- Parse at evaluation time. Rejected — would require parsing on every filter change (perf cost) and defer errors to runtime.
- Use a runtime interpreter (eval-like). Rejected — security risk and enables silent coercion (Excel-style NaN).

**Rationale**: Save-time validation is strict and predictable. The AST is the source of truth for evaluation; the source text is a convenience.

### D3: Shared AST and test suite for TypeScript ↔ PHP evaluators

**Decision**: The AST node types (`Literal`, `FieldRef`, `Binary`, `Unary`, `Call`, `If`) are defined in TypeScript and documented as JSON. The PHP evaluator deserialises the same JSON and evaluates it. Both implementations pass the same test suite (parameterised by AST + input + expected output).

**Alternatives considered:**

- Re-parse on the server (PHP has its own parser). Rejected — parser bugs in one language could slip through; we want one source of truth.
- Express the expression as a domain-specific byte code. Rejected — more complex than JSON, no benefit.

**Rationale**: Shared AST + test suite is the strongest guarantee that evaluators don't drift. Contributors can add test cases and both implementations must pass.

### D4: Strict typing, no coercion

**Decision**: The type system is minimal (`number`, `string`, `boolean`, `date`, `duration`, `null`) and strict: `number + string` is a parse-time error, `abs("hello")` is a type error, `null + 1` evaluates to `null` (null propagation).

**Alternatives considered:**

- Excel-like coercion (string "123" coerces to number). Rejected — silent coercion is the #1 source of spreadsheet bugs.
- TypeScript-like union types. Rejected — too complex for a dashboard-formula language.

**Rationale**: Strict types catch mistakes at save time, when the user is still in the formula editor and can see the error immediately.

### D5: Cycle detection via topological sort

**Decision**: On save, build a dependency graph of all calculated fields and cross-widget references. Run a topological sort. If it fails (cycle detected), reject the widget save with a clear error naming the cycle.

**Alternatives considered:**

- Iterative recalculation (Excel's opt-in mode). Rejected — semantics are ambiguous (max iterations? Convergence criteria?). Topological sort is deterministic.
- Detect cycles only on cross-widget refs, allow self-referential formulas. Rejected — self-reference adds very little value and complicates the evaluator.

**Rationale**: Topological sort is a proven pattern in Excel, Google Sheets, and dbt. It is deterministic and catches errors at save time.

### D6: Format options applied at render time, not evaluation time

**Decision**: Evaluated values are stored in native types (`number`, `date`, `string`). Format options (currency, locale, number format, date pattern) are applied only when rendering the value in the UI.

**Alternatives considered:**

- Format at evaluation time (store formatted strings). Rejected — breaks alerting (alerts need numeric values, not "€1.234,50").
- Store both native and formatted versions. Rejected — unnecessary duplication.

**Rationale**: Keeps the underlying value suitable for export, alerting, and queries. Formatting is a purely UI concern.

### D7: Allowlist-based function set, no dynamic functions

**Decision**: The allowlist of built-in functions (math, aggregate, date, string, etc.) is defined in the parser and is per-instance configurable. Calling an unlisted function is a parse-time error. Users cannot register custom functions.

**Alternatives considered:**

- Scriptable custom functions (UDFs). Rejected — security risk, complex deployment, not needed for v1.
- Allow any identifier as a function call. Rejected — enables typos and makes migration (if we ever add a function) a breaking change.

**Rationale**: Explicit allowlist is auditable and secure. The allowlist is per-instance configurable so operators can dial it down for determinism.

## Risks / Trade-offs

- **Risk:** Cross-widget references require both widgets to share a register. If not, field is downgraded to client-only and user gets a warning. → **Mitigation:** Document the limitation; most dashboards share a single register anyway.
- **Risk:** Client-side evaluation can go stale if the source widget's data is stale. → **Mitigation:** We follow the dashboard's existing refresh semantics — calculated fields re-evaluate whenever any source widget or filter changes.
- **Risk:** Server-side materialisation can fail (e.g., division by zero). → **Mitigation:** Error is logged to OpenRegister's computed-field error log; materialised value is null. Dashboard shows an inline error indicator.
- **Trade-off:** The function allowlist is conservative (no `eval`, no file I/O). This is intentional for security and determinism, but can be a friction point if users need e.g., regex. We accept this and version the allowlist in future changes.

## Migration Plan

1. **Parser + TypeScript evaluator land first** — add parser, type-checker, evaluator, and Vitest coverage. No integration yet; exported as a utility module.
2. **PHP evaluator** — deserialise and evaluate the same AST; cross-language test suite.
3. **Widget schema** — extend with `calculatedFields` array.
4. **Integration** — wire parser/validator into `WidgetController::update`, store AST, call OpenRegister `computed-fields` on save.
5. **Frontend evaluator** — integrates with `useDashboardsStore`, re-evaluates on filter/widget changes, renders results and errors.
6. **Rollback**: Schema change adds a nullable field; reverting the PR leaves the field NULL on all rows — no data loss.

## Seed Data

### Widget with calculated fields

```json
{
  "id": "widget-abc123",
  "title": "Revenue Analysis",
  "type": "table",
  "sourceId": "register-sales",
  "calculatedFields": [
    {
      "id": "calc-001",
      "name": "profit_margin",
      "label": "Profit Margin (%)",
      "description": "Revenue minus cost, expressed as percentage of revenue",
      "expression": "(revenue - cost) / revenue * 100",
      "compiledExpression": {
        "type": "binary",
        "op": "*",
        "left": {
          "type": "binary",
          "op": "/",
          "left": {
            "type": "binary",
            "op": "-",
            "left": {"type": "fieldRef", "source": "self", "path": "revenue"},
            "right": {"type": "fieldRef", "source": "self", "path": "cost"}
          },
          "right": {"type": "fieldRef", "source": "self", "path": "revenue"}
        },
        "right": {"type": "literal", "valueType": "number", "value": 100}
      },
      "returnType": "number",
      "evaluationMode": "both",
      "serverComputedFieldId": "cf-profit-margin-001",
      "formatOptions": {
        "style": "decimal",
        "minimumFractionDigits": 2,
        "maximumFractionDigits": 2,
        "locale": "nl-NL"
      },
      "createdAt": "2026-05-22T10:30:00Z",
      "updatedAt": "2026-05-22T10:30:00Z"
    },
    {
      "id": "calc-002",
      "name": "net_revenue",
      "label": "Net Revenue (EUR)",
      "expression": "revenue - refunds",
      "compiledExpression": {
        "type": "binary",
        "op": "-",
        "left": {"type": "fieldRef", "source": "self", "path": "revenue"},
        "right": {"type": "fieldRef", "source": "self", "path": "refunds"}
      },
      "returnType": "number",
      "evaluationMode": "both",
      "serverComputedFieldId": "cf-net-revenue-002",
      "formatOptions": {
        "style": "currency",
        "currency": "EUR",
        "locale": "nl-NL"
      },
      "createdAt": "2026-05-22T10:31:00Z",
      "updatedAt": "2026-05-22T10:31:00Z"
    },
    {
      "id": "calc-003",
      "name": "resolution_days",
      "label": "Avg. Resolution Time (days)",
      "expression": "dateDiff(closedDate, openedDate, 'days')",
      "compiledExpression": {
        "type": "call",
        "name": "dateDiff",
        "args": [
          {"type": "fieldRef", "source": "self", "path": "closedDate"},
          {"type": "fieldRef", "source": "self", "path": "openedDate"},
          {"type": "literal", "valueType": "string", "value": "days"}
        ]
      },
      "returnType": "duration",
      "evaluationMode": "client",
      "formatOptions": {
        "pattern": "d 'dagen'"
      },
      "createdAt": "2026-05-22T10:32:00Z",
      "updatedAt": "2026-05-22T10:32:00Z"
    },
    {
      "id": "calc-004",
      "name": "status_label",
      "label": "Status Label",
      "expression": "if(revenue > 100000, 'High Value', if(revenue > 10000, 'Medium Value', 'Low Value'))",
      "compiledExpression": {
        "type": "if",
        "cond": {
          "type": "binary",
          "op": ">",
          "left": {"type": "fieldRef", "source": "self", "path": "revenue"},
          "right": {"type": "literal", "valueType": "number", "value": 100000}
        },
        "then": {"type": "literal", "valueType": "string", "value": "High Value"},
        "else": {
          "type": "if",
          "cond": {
            "type": "binary",
            "op": ">",
            "left": {"type": "fieldRef", "source": "self", "path": "revenue"},
            "right": {"type": "literal", "valueType": "number", "value": 10000}
          },
          "then": {"type": "literal", "valueType": "string", "value": "Medium Value"},
          "else": {"type": "literal", "valueType": "string", "value": "Low Value"}
        }
      },
      "returnType": "string",
      "evaluationMode": "client",
      "createdAt": "2026-05-22T10:33:00Z",
      "updatedAt": "2026-05-22T10:33:00Z"
    },
    {
      "id": "calc-005",
      "name": "comparison_to_target",
      "label": "Actual vs Target (%)",
      "description": "Revenue as percentage of sales target from widget-target",
      "expression": "widget:widget-target.target_revenue / revenue * 100",
      "compiledExpression": {
        "type": "binary",
        "op": "*",
        "left": {
          "type": "binary",
          "op": "/",
          "left": {
            "type": "fieldRef",
            "source": "widget:widget-target",
            "path": "target_revenue"
          },
          "right": {"type": "fieldRef", "source": "self", "path": "revenue"}
        },
        "right": {"type": "literal", "valueType": "number", "value": 100}
      },
      "returnType": "number",
      "evaluationMode": "client",
      "formatOptions": {
        "style": "decimal",
        "minimumFractionDigits": 1,
        "maximumFractionDigits": 1,
        "locale": "nl-NL"
      },
      "createdAt": "2026-05-22T10:34:00Z",
      "updatedAt": "2026-05-22T10:34:00Z"
    }
  ]
}
```

## Example Evaluations

For the widget above with source data row: `{revenue: 150000, cost: 90000, refunds: 5000, openedDate: '2026-04-01T00:00:00Z', closedDate: '2026-04-21T00:00:00Z'}`:

- `profit_margin`: `(150000 - 90000) / 150000 * 100 = 40.00`
- `net_revenue`: `150000 - 5000 = 145000.00` (formatted: €145.000,00)
- `resolution_days`: `21` (formatted: 21 dagen)
- `status_label`: "High Value"
- `comparison_to_target`: requires data from widget-target; if target is 120000, then `120000 / 150000 * 100 = 80.0`

## Error Cases

### Parse error

User saves expression `(revenue - refunds` (missing closing paren):
- API response: 400 Bad Request
- Body: `{"error": "parse_error", "message": "Unexpected token at position 21", "column": 21, "expression": "(revenue - refunds"}`

### Type error

User saves expression `revenue + "100"` where revenue is number and `"100"` is string:
- API response: 400 Bad Request
- Body: `{"error": "type_error", "message": "Cannot add number + string", "operands": ["number", "string"]}`

### Cycle detection

Widget A has `calc1 = widget:B.x` and Widget B has `calc1 = widget:A.y`:
- API response: 400 Bad Request
- Body: `{"error": "cycle_detected", "widgets": ["widget-A", "widget-B"], "message": "Circular dependency detected between widgets"}`

### Cross-register mismatch

Widget A (register: sales) tries to reference Widget B (register: crm) in a server-mode calculated field:
- API response: 200 OK (save succeeds)
- Body: `{"warning": "downgraded_evaluation_mode", "field": "calc1", "reason": "Cross-register reference; evaluationMode downgraded from 'server' to 'client'"}`

## Open Questions

- Should calculated fields display in the widget's schema export (OpenAPI / Postman)? Current decision: yes, but marked as derived/read-only at export time.
- Can calculated fields be used in widget filtering (as filter targets)? Current decision: no (v1 — filter.field must reference real data). Future: revisit for v2 if demand is high.
- Should there be a formula function to query sibling calculated fields on the same widget? Current decision: no (use `CalculatedRef` only); forces you to inline formulas if you want to chain them. Future: revisit if complexity justifies it.
