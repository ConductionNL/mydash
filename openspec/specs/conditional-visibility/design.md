# Conditional Visibility — Technical Design

## Overview

Conditional visibility evaluates a set of rules attached to a `WidgetPlacement` to decide whether
the widget should be rendered for the current user. Rules are evaluated server-side at request time.
The result is communicated to the frontend through the placement's `isVisible` flag; hidden widgets
are excluded from the DOM entirely (they are not returned as visible to the client).

---

## Data Model

### Table: `mydash_conditional_rules`

| Column               | Type       | Notes                                    |
|----------------------|------------|------------------------------------------|
| `id`                 | BIGINT PK  | Auto-increment, unsigned                 |
| `widget_placement_id`| BIGINT     | FK to `mydash_widget_placements.id`      |
| `rule_type`          | VARCHAR 50 | `group`, `time`, `date`, or `attribute`  |
| `rule_config`        | TEXT       | JSON blob, schema varies by rule type    |
| `is_include`         | SMALLINT   | `1` = include rule, `0` = exclude rule   |
| `created_at`         | DATETIME   | Set on insert                            |

Index: `mydash_rule_placement` on `widget_placement_id`.

The rules table was created in the initial migration (`Version001000Date20240101000000`) via
`MigrationTableBuilder::createConditionalRulesTable()` → `RulesTableBuilder::create()`.

### Entity: `ConditionalRule` (`lib/Db/ConditionalRule.php`)

Extends `OCP\AppFramework\Db\Entity` and implements `JsonSerializable`.

**Rule type constants** (used throughout the evaluator):

```php
ConditionalRule::TYPE_GROUP     = 'group'
ConditionalRule::TYPE_TIME      = 'time'
ConditionalRule::TYPE_DATE      = 'date'
ConditionalRule::TYPE_ATTRIBUTE = 'attribute'
```

**JSON serialization output** (camelCase):

```json
{
  "id": 5,
  "widgetPlacementId": 10,
  "ruleType": "group",
  "ruleConfig": {"groups": ["marketing"]},
  "isInclude": true,
  "createdAt": "2026-02-26T09:00:00+01:00"
}
```

`getRuleConfigArray()` / `setRuleConfigArray()` handle JSON encode/decode transparently.

---

## Rule Config Schemas

### `group`

```json
{"groups": ["admin", "marketing"]}
```

Evaluates to `true` if the user is a member of **at least one** of the listed groups.
Uses `IGroupManager::getUserGroupIds()` and `array_intersect()`.

### `time`

```php
// Keys used in code (camelCase in config array):
{"startTime": "09:00", "endTime": "17:00", "days": ["mon", "tue", "wed", "thu", "fri"]}
```

Notes on actual implementation:
- Config keys are `startTime` / `endTime` (camelCase), not `start_time` / `end_time` as shown in the spec.
- An optional `days` array filters by three-letter lowercase weekday abbreviations (`mon`, `tue`, …`sun`).
- Midnight-spanning windows (e.g. 22:00 – 06:00) are **not** currently handled — the evaluator uses
  a simple `>=` / `<=` string comparison. A start of `22:00` and end of `06:00` will always return
  `false` at runtime because `'22:00' <= '06:00'` is false.
- Timezone support from the spec (`timezone` field) is **not yet implemented**; the evaluator creates
  a `new DateTime()` with no timezone argument, so it uses the server's default timezone.

### `date`

```php
{"startDate": "2026-12-01", "endDate": "2026-12-31"}
```

Config keys are `startDate` / `endDate` (camelCase). Both are optional (open-ended ranges work).
Uses string comparison `Y-m-d` format. Boundaries are inclusive.

### `attribute`

```json
{"attribute": "locale", "operator": "equals", "value": "nl"}
```

Supported attributes (resolved via `UserAttributeResolver`):

| Attribute     | Source                                  |
|---------------|-----------------------------------------|
| `locale`      | `IUser::getLanguage()`                  |
| `email`       | `IUser::getEMailAddress()`              |
| `displayName` | `IUser::getDisplayName()`               |
| `quota`       | `(string) IUser::getQuota()`            |

Note: the spec uses `"attribute": "language"` but the implementation maps the key `locale` to
`IUser::getLanguage()`. There is no `language` attribute mapping; `language` would return `null`
and the rule would evaluate to `false`.

Supported operators:

| Operator      | Logic                                   |
|---------------|-----------------------------------------|
| `equals`      | `$userValue === $value`                 |
| `not_equals`  | `$userValue !== $value`                 |
| `contains`    | `str_contains($userValue, $value)`      |
| `starts_with` | `str_starts_with($userValue, $value)`   |
| `ends_with`   | `str_ends_with($userValue, $value)`     |

Unknown operators return `false`.

---

## Include / Exclude Logic

Rules are split into two categories by `VisibilityChecker::checkRules()`:

```
Include rules:  is_include = true
Exclude rules:  is_include = false
```

The final visibility decision:

```
visible = passesIncludeRules(includeRules) AND passesExcludeRules(excludeRules)
```

**Include rules — OR logic** (`passesIncludeRules`):
- If there are no include rules → pass (returns `true`).
- Otherwise: returns `true` if **at least one** include rule evaluates to `true`.
- Returns `false` if none match.

**Exclude rules — ANY-match-hides** (`passesExcludeRules`):
- Iterates exclude rules; returns `false` (hide) as soon as any rule matches.
- If none match → returns `true` (show).

**Important divergence from spec**: The spec states multiple rules use AND logic (all must match).
The implementation uses OR for include rules (any match is sufficient) and early-exit for exclude
rules (first match hides). Mixed include+exclude behaves as: "at least one include matches AND no
exclude matches."

No rules on a placement → `getRules()` returns empty array → `isWidgetVisible()` short-circuits
to `true` (shown).

---

## Service Architecture

```
RuleApiController
  └── ConditionalService
        ├── ConditionalRuleMapper      (DB CRUD)
        ├── RuleEvaluatorService       (single-rule evaluation)
        │     ├── IGroupManager        (group rules)
        │     ├── IUserManager         (group + attribute rules)
        │     └── UserAttributeResolver (attribute evaluation)
        └── VisibilityChecker          (multi-rule combination)
              └── RuleEvaluatorService (delegates per rule)
```

### `ConditionalRuleMapper` (`lib/Db/ConditionalRuleMapper.php`)

Extends `QBMapper<ConditionalRule>`. Table: `mydash_conditional_rules`.

Key methods:
- `find(int $id): ConditionalRule` — throws `DoesNotExistException` if not found.
- `findByPlacementId(int $placementId): array` — ordered by `created_at ASC`.
- `deleteByPlacementId(int $placementId): void` — bulk delete for cascade on placement removal.

Cascade on placement deletion: `ConditionalRuleMapper::deleteByPlacementId()` must be called
explicitly before or after deleting the placement; there is no DB-level foreign key constraint.
The current `PlacementService::removePlacement()` does **not** call `deleteByPlacementId()` —
this is a gap between the spec requirement and the implementation.

### `ConditionalService` (`lib/Service/ConditionalService.php`)

Orchestrates CRUD and the visibility check. All public methods use named arguments to
`ConditionalRuleMapper`.

| Method              | Description                                      |
|---------------------|--------------------------------------------------|
| `isWidgetVisible()` | Short-circuits on `isVisible=0`; delegates to `VisibilityChecker` |
| `evaluateRule()`    | Delegates to `RuleEvaluatorService`              |
| `getRules()`        | Wraps `findByPlacementId`                        |
| `addRule()`         | Creates entity, sets `createdAt`, inserts        |
| `updateRule()`      | Partial update; only updates provided fields     |
| `deleteRule()`      | Find + delete                                    |

Rule type validation (HTTP 400 for unknown types) is **not implemented** in the service or controller.
Invalid types are stored and later evaluate to `false` via the `default => false` match arm in
`RuleEvaluatorService::evaluateRule()`.

### `RuleEvaluatorService` (`lib/Service/RuleEvaluatorService.php`)

Dispatches to a private method per rule type using a `match` expression. Unknown types return `false`.
All four types are handled: `group`, `time`, `date`, `attribute`.

### `VisibilityChecker` (`lib/Service/VisibilityChecker.php`)

Stateless service; receives an array of `ConditionalRule` objects and a `userId`. Splits into
include/exclude, then applies the OR/ANY logic described above.

### `UserAttributeResolver` (`lib/Service/UserAttributeResolver.php`)

Resolves a named user attribute to a string value and evaluates comparison operators.
Attributes map to Nextcloud `IUser` methods. Unknown attributes return `null`, causing the
attribute rule to evaluate as `false`.

---

## API Endpoints

All endpoints require authentication (`#[NoAdminRequired]`). Unauthenticated requests receive
HTTP 401 via `ResponseHelper::unauthorized()`.

| Method | URL                              | Controller Action        |
|--------|----------------------------------|--------------------------|
| GET    | `/api/widgets/{placementId}/rules` | `getRules(placementId)`  |
| POST   | `/api/widgets/{placementId}/rules` | `addRule(placementId, …)`|
| PUT    | `/api/rules/{ruleId}`            | `updateRule(ruleId, …)`  |
| DELETE | `/api/rules/{ruleId}`            | `deleteRule(ruleId)`     |

Authorization for GET and POST verifies placement ownership via
`PermissionService::verifyPlacementOwnership()`, which traverses placement → dashboard → userId.
The DELETE endpoint does **not** verify rule ownership before deletion — it calls
`ConditionalService::deleteRule()` directly without an ownership check. This is a gap from the
spec requirement (HTTP 403 for other users' rules).

Successful responses use `ResponseHelper::success()`. Created rules return HTTP 201.

---

## Visibility Integration with Dashboard Rendering

`ConditionalService::isWidgetVisible()` is available but is **not wired into the dashboard fetch
path**. `DashboardResolver::buildResult()` returns raw placement entities from
`WidgetPlacementMapper::findByDashboardId()` without filtering through `ConditionalService`.
The frontend receives the full placement list with `isVisible` as stored in the DB (not re-evaluated
at render time). Conditional rule evaluation must therefore be triggered explicitly — it is not
automatically applied when the active dashboard is fetched.

---

## Known Gaps vs. Spec

| Spec Requirement                        | Implementation Status                               |
|-----------------------------------------|-----------------------------------------------------|
| HTTP 400 on invalid `rule_type`         | Not implemented; stored silently                    |
| HTTP 403 on DELETE another user's rule  | Ownership check missing on DELETE endpoint          |
| Timezone support in time rules          | Not implemented; server timezone used               |
| Midnight-spanning time windows          | Not handled by string comparison                    |
| Cascade delete rules on placement delete| `deleteByPlacementId` exists but not called from `PlacementService::removePlacement()` |
| Auto-set visibility to "conditional"    | Not implemented; `isVisible` remains a boolean flag |
| Revert visibility on last rule delete   | Not implemented                                     |
| Rule evaluation at dashboard render time| Not wired into `DashboardResolver`                  |
| `language` attribute                    | Mapped as `locale` in `UserAttributeResolver`       |
