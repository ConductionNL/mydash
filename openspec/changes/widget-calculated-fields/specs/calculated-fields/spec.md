---
capability: calculated-fields
delta: false
status: draft
---

# Calculated Fields

A calculated field is a named expression attached to a widget that produces a value derived from other fields in the same widget, fields in other widgets on the same dashboard, constants, and built-in functions.

## Requirement: REQ-CALC-001 Formula parser produces deterministic AST

The system SHALL parse a calculated-field expression into a canonical AST at save time and SHALL refuse to persist any widget whose calculated-field expressions fail to parse.

### Scenario: Valid expression parsed to AST

- GIVEN a valid expression `(revenue - refunds) / revenue * 100`
- WHEN the widget is saved
- THEN the parser SHALL produce a `Binary` AST with operator `*` and SHALL store it in `compiledExpression`
- AND the AST SHALL be deep-equal to any other parse of the same expression

### Scenario: Parse error on mismatched parentheses

- GIVEN an expression with mismatched parentheses `(revenue - refunds`
- WHEN the widget is saved
- THEN the API SHALL respond 400 with a parse error that includes the column position (column 21)
- AND SHALL NOT persist the widget

### Scenario: Whitespace normalization in AST

- GIVEN two expressions that differ only by whitespace: `(a+b)` and `( a + b )`
- WHEN both are parsed
- THEN their `compiledExpression` ASTs SHALL be deep-equal

## Requirement: REQ-CALC-002 Type checking at save time

The system SHALL infer the type of every sub-expression at parse time and SHALL reject expressions whose operator/function arguments do not match the declared signature.

### Scenario: Type mismatch on binary operator

- GIVEN an expression `revenue + customerName` where `revenue:number` and `customerName:string`
- WHEN the widget is saved
- THEN the API SHALL respond 400 with a type error naming both operands
- AND SHALL NOT persist the widget

### Scenario: Function signature validation

- GIVEN an expression `round(revenue, "two")` where `round` expects (number, integer)
- WHEN the widget is saved
- THEN the API SHALL respond 400 with a function-signature error
- AND SHALL NOT persist the widget

### Scenario: Type inference through coalesce

- GIVEN an expression `coalesce(null, revenue, 0)` where `revenue:number`
- WHEN the widget is saved
- THEN the inferred `returnType` SHALL be `number`
- AND the widget SHALL persist successfully

## Requirement: REQ-CALC-003 Client-side evaluator reactive to filter changes

The client-side evaluator SHALL recompute every `client` and `both` mode calculated field whenever any source field, source widget, or filter changes, and SHALL never round-trip to the server for an evaluation.

### Scenario: Recompute on filter change

- GIVEN a widget with calculated field `profit = revenue - cost`
- AND the widget displays `revenue = 100000, cost = 60000, profit = 40000`
- WHEN the user changes a dashboard filter that affects `revenue` and `cost` to new values `110000, 70000`
- THEN `profit` SHALL be recomputed inside the browser to `40000` and the new value SHALL appear without an HTTP request
- AND the UI SHALL update within one animation frame

### Scenario: Cross-widget dependency tracking

- GIVEN a calculated field referencing `widget:abc123`
- WHEN the source widget `abc123` refreshes its data
- THEN the dependent calculated field SHALL recompute synchronously in the same tick
- AND the updated value SHALL be rendered before the next animation frame

### Scenario: Filter chain with multiple affected fields

- GIVEN a dashboard with widgets W1 (revenue), W2 (cost), W3 (calculated: W1.x / W2.y)
- WHEN the user changes a filter that affects both W1 and W2
- THEN W3's calculated field SHALL be recomputed after both W1 and W2 complete their refresh
- AND the order of recomputation shall be deterministic (topological sort order)

## Requirement: REQ-CALC-004 Server-side evaluation via OpenRegister computed-fields

The system SHALL, for every calculated field in `evaluationMode=server` or `both`, create a corresponding `computed-fields` entry on the underlying schema and SHALL keep the two in sync on widget save.

### Scenario: Create server-mode computed-field entry

- GIVEN a new calculated field with `evaluationMode=server`, `expression="revenue - cost"`
- WHEN the widget is saved
- THEN a `computed-fields` entry SHALL be created on the source schema with the same expression
- AND `serverComputedFieldId` SHALL be populated with the OpenRegister entry's ID

### Scenario: Update server-mode entry on expression change

- GIVEN an existing server-mode calculated field with `serverComputedFieldId="cf-123"`
- WHEN the widget is saved with a new expression `"profit * 0.9"`
- THEN the linked `computed-fields` entry SHALL be updated with the new expression in the same transaction
- AND the update SHALL be atomic (no partial state visible to other readers)

### Scenario: Delete computed-field entry when widget is deleted

- GIVEN a server-mode calculated field with `serverComputedFieldId="cf-123"`
- WHEN the widget is deleted
- THEN the linked `computed-fields` entry SHALL be removed from the schema
- AND no orphaned OpenRegister entries shall remain

## Requirement: REQ-CALC-005 Identical results between evaluators

Both evaluators SHALL produce identical results for every expression in the shared test suite, with documented and tested handling of numeric precision and null propagation.

### Scenario: Numeric precision across evaluators

- GIVEN any expression in the shared evaluator test suite that produces a number
- WHEN evaluated by the TypeScript and PHP evaluators on identical input
- THEN the two results SHALL be deep-equal OR equal to within 1e-9 of each other (accounting for IEEE 754 rounding)

### Scenario: Null propagation

- GIVEN an expression `a + null` where `a` is any non-null value
- WHEN evaluated by either the TypeScript or PHP evaluator
- THEN the result SHALL be `null`
- AND SHALL NOT throw an exception

### Scenario: Cross-evaluator test matrix

- GIVEN the shared test suite contains 50+ test cases covering: literals, field refs, binary ops, unary ops, function calls, conditionals, null handling, type coercion at boundaries
- WHEN the test suite runs against both evaluators
- THEN 100% of tests SHALL pass on both

## Requirement: REQ-CALC-006 Cross-widget references with cycle detection

The system SHALL allow a calculated field to reference fields on other widgets on the same dashboard and SHALL refuse any dependency graph that contains a cycle.

### Scenario: Cycle detection on save

- GIVEN widget A's calculated field references `widget:B.x` and widget B's calculated field references `widget:A.y`
- WHEN either widget is saved
- THEN the API SHALL respond 400 with a `cycleDetected` error naming both widgets
- AND SHALL NOT persist

### Scenario: Acyclic cross-widget reference accepted

- GIVEN widget A's calculated field references `widget:B.x` where B has no calculated fields pointing back to A
- WHEN A is saved
- THEN the save SHALL succeed
- AND A's calculated field SHALL re-evaluate whenever B refreshes (REQ-CALC-003)

### Scenario: Self-reference rejected

- GIVEN a calculated field named `x` with expression `widget:self.x` (self-referential)
- WHEN the widget is saved
- THEN the API SHALL respond 400 with a `cycleDetected` error
- AND SHALL NOT persist

## Requirement: REQ-CALC-007 Cross-widget downgrade on source mismatch

When a `server` or `both` mode calculated field references a widget whose data does not live in the same OpenRegister register/schema, the system SHALL downgrade the field to `client` mode at save time and SHALL warn the user.

### Scenario: Downgrade on different register

- GIVEN a calculated field with `evaluationMode=server` that references a widget backed by a different register (e.g., widget A uses register:sales, widget B uses register:crm)
- WHEN the widget is saved
- THEN the field's `evaluationMode` SHALL be persisted as `client`
- AND the API response SHALL include a warning: `{warning: 'downgraded_evaluation_mode', field: 'name', reason: 'Cross-register reference'}`

### Scenario: Preserve server mode on same register

- GIVEN a calculated field with `evaluationMode=server` that references a widget backed by the same register and schema
- WHEN the widget is saved
- THEN `evaluationMode` SHALL remain `server`
- AND a `computed-fields` entry SHALL be created

## Requirement: REQ-CALC-008 Inline error display preserves dashboard usability

When a calculated field fails to evaluate at runtime, the system SHALL render an inline error indicator on the widget without breaking other widgets on the dashboard.

### Scenario: Division by zero in client-side evaluation

- GIVEN a calculated field `ratio = a / b` where the user has NOT added a guard and `b` is 0 in a given row
- WHEN the widget renders
- THEN the field's cell SHALL display an error icon
- AND hovering the icon SHALL show the expression and the value of `b`
- AND other widgets on the dashboard SHALL render normally (not affected)

### Scenario: Server-side evaluation error

- GIVEN the same calculated field on the server-side evaluator
- WHEN the materialiser runs and encounters division by zero
- THEN the materialised value SHALL be `null`
- AND the OpenRegister computed-field error log SHALL record: `{expression, inputs, error: 'division by zero'}`

### Scenario: Error does not block widget render

- GIVEN a widget with 10 calculated fields, 1 of which fails to evaluate
- WHEN the widget renders
- THEN the 9 successful fields SHALL display normally
- AND the 1 failed field SHALL show an error indicator
- AND the widget SHALL be usable (filtering, exporting, etc. do not break)

## Requirement: REQ-CALC-009 Allowlisted function set with named signatures

The parser SHALL accept only functions on the documented allowlist and SHALL reject any call to an unknown identifier as a function with a clear error.

### Scenario: Unknown function rejected

- GIVEN an expression `eval("revenue * 2")`
- WHEN the widget is saved
- THEN the API SHALL respond 400 with `{error: 'unknownFunction', name: 'eval'}`
- AND SHALL NOT persist

### Scenario: Variadic function accepted with correct args

- GIVEN an expression `sum(revenue, cost, profit)` where `sum` accepts variadic numbers
- WHEN the widget is saved
- THEN the parser SHALL validate that `sum` is on the allowlist and accepts variadic args
- AND the widget SHALL persist successfully

### Scenario: Allowlist is per-instance configurable

- GIVEN a MyDash instance with `allowedFunctions: ['abs', 'round', 'sum']` (restricted)
- WHEN a user tries to save an expression using `now()` (not in allowlist)
- THEN the API SHALL respond 400 with `{error: 'functionNotAllowed', name: 'now'}`

## Requirement: REQ-CALC-010 Format options applied at render time

The system SHALL apply `formatOptions` only at render time on the client, and SHALL store and evaluate the underlying value in its native type.

### Scenario: Currency formatting preserves numeric value

- GIVEN a calculated field with `returnType=number`, `formatOptions={style:'currency', currency:'EUR', locale:'nl-NL'}`, evaluated value of `1234.5`
- WHEN the widget renders for a Dutch user
- THEN the displayed string SHALL be `€ 1.234,50`
- AND the underlying value passed to alerting/export SHALL remain `1234.5` (not a string)

### Scenario: Date formatting preserves ISO instant

- GIVEN a calculated field with `returnType=date`, `formatOptions={pattern:'dd MMM yyyy', locale:'nl-NL'}`, evaluated value of `2026-05-22T14:30:00Z`
- WHEN the widget renders
- THEN the displayed string SHALL be `22 mei 2026`
- AND the underlying value passed to filters/queries SHALL remain `2026-05-22T14:30:00Z` (ISO 8601)

### Scenario: Format options ignored at server-side evaluation

- GIVEN the same calculated field in server-side mode (materialised)
- WHEN the field is evaluated on the server
- THEN the materialised value SHALL be the raw number/date (no formatting)
- AND the format options SHALL be applied only by the widget renderer, not by OpenRegister
