---
status: implemented
---

# Conditional Visibility Specification

## Purpose

Conditional visibility allows widget placements to be shown or hidden based on dynamic rules. This enables dashboards that adapt to the user's context -- for example, showing a "Team Updates" widget only during business hours, displaying a "Holiday Schedule" widget only in December, or restricting certain widgets to specific user groups. Rules are evaluated at render time and can be inclusive (show when matched) or exclusive (hide when matched). Include rules use OR logic (at least one must match); exclude rules use AND logic (any match hides the widget).

## Data Model

### Conditional Rules (oc_mydash_conditional_rules)
- **id**: Auto-increment integer primary key
- **widgetPlacementId**: Foreign key to oc_mydash_widget_placements (INTEGER)
- **ruleType**: One of `group`, `time`, `date`, `attribute` (STRING)
- **ruleConfig**: JSON blob containing the rule parameters, varies by ruleType (STRING, nullable). Stored via `json_encode()`, accessed via `getRuleConfigArray()`.
- **isInclude**: Boolean -- if true, the rule is an inclusion rule (show when matched); if false, it is an exclusion rule (hide when matched)
- **createdAt**: Timestamp (DATETIME)

NOTE: There is no separate `visibility: "conditional"` string state on widget placements. The visibility model works as follows: if `isVisible` is 0 (false), the widget is always hidden. If `isVisible` is 1 (true) AND ConditionalRule records exist for the placement, the ConditionalService evaluates those rules via VisibilityChecker. If no rules exist and `isVisible` is 1, the widget is always shown.

### Rule Config Schemas by Type

**group** ruleConfig:
```json
{"groups": ["admin", "marketing"]}
```

**time** ruleConfig:
```json
{"startTime": "09:00", "endTime": "17:00", "days": ["mon", "tue", "wed", "thu", "fri"]}
```
NOTE: Time rules use camelCase keys (`startTime`, `endTime`) and support an optional `days` array (3-letter lowercase day abbreviations). The `timezone` field is NOT supported -- time evaluation uses the server's DateTime (`new DateTime()`), not a user-specified timezone.

**date** ruleConfig:
```json
{"startDate": "2026-12-01", "endDate": "2026-12-31"}
```
NOTE: Date rules use camelCase keys (`startDate`, `endDate`). Both fields are optional -- omitting `startDate` matches from the beginning of time, omitting `endDate` matches indefinitely.

**attribute** ruleConfig:
```json
{"attribute": "language", "operator": "equals", "value": "nl"}
```

## Requirements

### REQ-VIS-001: Create Conditional Rule

Users MUST be able to add conditional visibility rules to widget placements on dashboards they own.

#### Scenario: Create a group-based inclusion rule
- GIVEN user "alice" has widget placement id 10 on her dashboard
- WHEN she sends POST /api/widgets/10/rules with body:
  ```json
  {
    "ruleType": "group",
    "ruleConfig": {"groups": ["marketing", "sales"]},
    "isInclude": true
  }
  ```
- THEN the system MUST create a conditional rule linked to placement 10
- AND the response MUST return HTTP 201 with the full rule object
- NOTE: Adding a rule does NOT change the placement's `isVisible` field. The ConditionalService automatically evaluates rules when `isVisible` is 1 and rules exist.

#### Scenario: Create a time-based exclusion rule
- GIVEN widget placement id 10 on alice's dashboard
- WHEN she sends POST /api/widgets/10/rules with body:
  ```json
  {
    "ruleType": "time",
    "ruleConfig": {"startTime": "18:00", "endTime": "08:00"},
    "isInclude": false
  }
  ```
- THEN the system MUST create an exclusion rule that hides the widget when the current server time matches
- AND `isInclude: false` MUST mean the widget is hidden when the rule matches
- NOTE: The current time comparison uses simple string comparison (`>=` and `<=`) and does NOT handle midnight-spanning windows correctly (startTime > endTime).

#### Scenario: Create a date-based inclusion rule
- GIVEN widget placement id 10 on alice's dashboard
- WHEN she sends POST /api/widgets/10/rules with body:
  ```json
  {
    "ruleType": "date",
    "ruleConfig": {"startDate": "2026-12-01", "endDate": "2026-12-31"},
    "isInclude": true
  }
  ```
- THEN the system MUST create a rule that shows the widget only during December 2026

#### Scenario: Create an attribute-based rule
- GIVEN widget placement id 10 on alice's dashboard
- WHEN she sends POST /api/widgets/10/rules with body:
  ```json
  {
    "ruleType": "attribute",
    "ruleConfig": {"attribute": "language", "operator": "equals", "value": "nl"},
    "isInclude": true
  }
  ```
- THEN the system MUST create a rule that shows the widget only for users with language set to "nl"

#### Scenario: Create rule with invalid ruleType
- GIVEN widget placement id 10 on alice's dashboard
- WHEN she sends POST /api/widgets/10/rules with body `{"ruleType": "weather", "ruleConfig": {}, "isInclude": true}`
- THEN the system SHOULD return HTTP 400 with an error indicating the ruleType is invalid
- AND only `group`, `time`, `date`, and `attribute` SHOULD be accepted
- NOTE: Rule type validation is NOT currently implemented -- any string value is accepted. Unknown rule types evaluate to `false` via the `default => false` case in `evaluateRule()`.

#### Scenario: Create rule on another user's placement
- GIVEN widget placement id 10 belongs to alice's dashboard
- WHEN user "bob" sends POST /api/widgets/10/rules
- THEN the system MUST return HTTP 403 (via `PermissionService::verifyPlacementOwnership()`)

### REQ-VIS-002: List Conditional Rules

Users MUST be able to retrieve all conditional rules for a widget placement they own.

#### Scenario: List rules for a placement with multiple rules
- GIVEN widget placement id 10 has 3 conditional rules:
  - Rule 1: group-based, include, groups: ["marketing"]
  - Rule 2: time-based, include, 09:00-17:00
  - Rule 3: date-based, exclude, 2026-07-01 to 2026-07-31
- WHEN the user sends GET /api/widgets/10/rules
- THEN the system MUST return HTTP 200 with an array of all 3 rules
- AND each rule MUST include: id, widgetPlacementId, ruleType, ruleConfig, isInclude, createdAt

#### Scenario: List rules for a placement with no rules
- GIVEN widget placement id 11 has no conditional rules
- WHEN the user sends GET /api/widgets/11/rules
- THEN the system MUST return HTTP 200 with an empty array

#### Scenario: List rules for another user's placement
- GIVEN widget placement id 10 belongs to alice's dashboard
- WHEN user "bob" sends GET /api/widgets/10/rules
- THEN the system MUST return HTTP 403 (via `verifyPlacementOwnership()`)

### REQ-VIS-003: Update Conditional Rule

Users MUST be able to modify existing conditional rules on placements they own.

#### Scenario: Update rule configuration
- GIVEN conditional rule id 5 with `ruleConfig: {"groups": ["marketing"]}`
- WHEN the user sends PUT /api/rules/5 with body:
  ```json
  {"ruleConfig": {"groups": ["marketing", "sales", "management"]}}
  ```
- THEN the system MUST update the ruleConfig
- AND the response MUST return HTTP 200 with the updated rule

#### Scenario: Change rule from inclusion to exclusion
- GIVEN conditional rule id 5 with `isInclude: true`
- WHEN the user sends PUT /api/rules/5 with body `{"isInclude": false}`
- THEN the system MUST update the rule to an exclusion rule
- AND the widget MUST now be hidden when this rule matches

#### Scenario: Update rule type
- GIVEN conditional rule id 5 with `ruleType: "group"`
- WHEN the user sends PUT /api/rules/5 with body:
  ```json
  {"ruleType": "time", "ruleConfig": {"startTime": "09:00", "endTime": "17:00"}}
  ```
- THEN the system MUST update both the ruleType and ruleConfig
- AND the old ruleConfig MUST be fully replaced (not merged)

#### Scenario: Update another user's rule
- GIVEN rule id 5 belongs to a placement on alice's dashboard
- WHEN user "bob" sends PUT /api/rules/5
- THEN the system MUST return HTTP 403
- NOTE: Ownership verification for update is NOT currently implemented in `RuleApiController`. Only `addRule()` and `getRules()` call `verifyPlacementOwnership()`.

#### Scenario: Partial update preserves unspecified fields
- GIVEN conditional rule id 5 with `ruleType: "group"`, `ruleConfig: {"groups": ["marketing"]}`, `isInclude: true`
- WHEN the user sends PUT /api/rules/5 with body `{"isInclude": false}`
- THEN only `isInclude` MUST be updated to `false`
- AND `ruleType` and `ruleConfig` MUST remain unchanged

### REQ-VIS-004: Delete Conditional Rule

Users MUST be able to remove conditional rules from their widget placements.

#### Scenario: Delete a rule
- GIVEN widget placement id 10 has 3 conditional rules, including rule id 5
- WHEN the user sends DELETE /api/rules/5
- THEN the system MUST delete rule 5
- AND the response MUST return HTTP 200 with `{"status": "ok"}`
- AND placement 10 MUST still have 2 remaining rules

#### Scenario: Delete the last rule on a placement
- GIVEN widget placement id 10 has only 1 conditional rule (id 5)
- AND the placement has `isVisible: 1`
- WHEN the user sends DELETE /api/rules/5
- THEN the system MUST delete the rule
- AND the placement's `isVisible` remains 1 (unchanged)
- AND since no rules exist, the widget will always be shown (ConditionalService returns true when no rules exist)
- NOTE: There is no automatic state change when the last rule is deleted.

#### Scenario: Delete another user's rule
- GIVEN rule id 5 belongs to a placement on alice's dashboard
- WHEN user "bob" sends DELETE /api/rules/5
- THEN the system MUST return HTTP 403
- NOTE: Ownership verification for delete is NOT currently implemented in `RuleApiController`.

### REQ-VIS-005: Group-Based Rule Evaluation

Group-based rules MUST show or hide widgets based on the current user's Nextcloud group memberships, resolved via `IGroupManager::getUserGroupIds()`.

#### Scenario: User is in a matching group (inclusion rule)
- GIVEN widget placement id 10 has a group inclusion rule with `groups: ["marketing", "sales"]`
- AND user "alice" is a member of the "marketing" group
- WHEN the dashboard is rendered for alice
- THEN the widget MUST be visible (rule matches, isInclude=true means show)

#### Scenario: User is not in any matching group (inclusion rule)
- GIVEN widget placement id 10 has a group inclusion rule with `groups: ["marketing", "sales"]`
- AND user "bob" is a member of only the "engineering" group
- WHEN the dashboard is rendered for bob
- THEN the widget MUST be hidden (rule does not match, isInclude=true means only show on match)

#### Scenario: User is in a matching group (exclusion rule)
- GIVEN widget placement id 10 has a group exclusion rule with `groups: ["contractors"]`
- AND user "carol" is a member of the "contractors" group
- WHEN the dashboard is rendered for carol
- THEN the widget MUST be hidden (rule matches, isInclude=false means hide on match)

#### Scenario: User in multiple groups with partial match
- GIVEN widget placement id 10 has a group inclusion rule with `groups: ["marketing"]`
- AND user "dave" is a member of groups ["engineering", "marketing", "all-staff"]
- WHEN the dashboard is rendered for dave
- THEN the widget MUST be visible (user is in at least one of the specified groups via `array_intersect()`)

#### Scenario: Empty groups array in rule config
- GIVEN widget placement id 10 has a group inclusion rule with `groups: []`
- WHEN the dashboard is rendered
- THEN the rule MUST evaluate as not matching (empty target groups returns false)
- AND the widget MUST be hidden (no include rule matches)

### REQ-VIS-006: Time-Based Rule Evaluation

Time-based rules MUST show or hide widgets based on the current time of day using the server's local time via `new DateTime()`.

#### Scenario: Current time is within the time window (inclusion rule)
- GIVEN widget placement id 10 has a time inclusion rule with `startTime: "09:00", endTime: "17:00"`
- AND the current server time is 14:30
- WHEN the dashboard is rendered
- THEN the widget MUST be visible

#### Scenario: Current time is outside the time window (inclusion rule)
- GIVEN widget placement id 10 has a time inclusion rule with `startTime: "09:00", endTime: "17:00"`
- AND the current server time is 20:00
- WHEN the dashboard is rendered
- THEN the widget MUST be hidden

#### Scenario: Time window spanning midnight (KNOWN LIMITATION)
- GIVEN widget placement id 10 has a time inclusion rule with `startTime: "22:00", endTime: "06:00"`
- AND the current server time is 02:00
- WHEN the dashboard is rendered
- THEN the widget SHOULD be visible (the time window wraps around midnight)
- NOTE: The current implementation uses simple string comparison (`currentTime >= startTime && currentTime <= endTime`) which does NOT handle midnight-spanning windows. This is a known limitation.

#### Scenario: Time evaluation uses server timezone (NOT configurable)
- GIVEN a time rule with `startTime: "09:00", endTime: "17:00"` (no timezone field)
- AND the server is in UTC where it is 08:00 UTC
- WHEN the dashboard is rendered
- THEN the rule MUST evaluate using the server's timezone (UTC in this case)
- AND the widget MUST be hidden (08:00 is before 09:00)
- NOTE: The `timezone` field in ruleConfig is NOT supported. `RuleEvaluatorService` creates `new DateTime()` which uses the server's default timezone.

#### Scenario: Time rule with day-of-week filter
- GIVEN widget placement id 10 has a time inclusion rule with `startTime: "09:00", endTime: "17:00", days: ["mon", "tue", "wed", "thu", "fri"]`
- AND the current server day is "sat" (Saturday)
- WHEN the dashboard is rendered
- THEN the widget MUST be hidden (Saturday is not in the allowed days list)
- AND the time check is only performed if the day check passes

#### Scenario: Time rule without day filter
- GIVEN a time inclusion rule with `startTime: "09:00", endTime: "17:00"` and no `days` field
- AND the current day is Saturday at 10:00
- WHEN the dashboard is rendered
- THEN the widget MUST be visible (no day filter means all days are allowed)

#### Scenario: Time rule with default start and end times
- GIVEN a time inclusion rule with neither `startTime` nor `endTime` specified
- WHEN the rule is evaluated
- THEN `startTime` MUST default to `"00:00"` and `endTime` MUST default to `"23:59"`
- AND the rule MUST match at any time of day

### REQ-VIS-007: Date-Based Rule Evaluation

Date-based rules MUST show or hide widgets based on the current date, with optional open-ended ranges.

#### Scenario: Current date is within the date range (inclusion rule)
- GIVEN widget placement id 10 has a date inclusion rule with `startDate: "2026-12-01", endDate: "2026-12-31"`
- AND today is 2026-12-15
- WHEN the dashboard is rendered
- THEN the widget MUST be visible

#### Scenario: Current date is outside the date range (inclusion rule)
- GIVEN widget placement id 10 has a date inclusion rule with `startDate: "2026-12-01", endDate: "2026-12-31"`
- AND today is 2026-11-15
- WHEN the dashboard is rendered
- THEN the widget MUST be hidden

#### Scenario: Open-ended date range (no end date)
- GIVEN a date inclusion rule with `startDate: "2026-01-01"` and no `endDate`
- AND today is 2027-06-15
- WHEN the dashboard is rendered
- THEN the widget MUST be visible (no endDate means the rule matches indefinitely from the start date)

#### Scenario: Open-ended date range (no start date)
- GIVEN a date inclusion rule with `endDate: "2026-12-31"` and no `startDate`
- AND today is 2025-06-15
- WHEN the dashboard is rendered
- THEN the widget MUST be visible (no startDate means matches from the beginning of time)

#### Scenario: Date range boundary inclusivity
- GIVEN a date inclusion rule with `startDate: "2026-12-01", endDate: "2026-12-31"`
- AND today is 2026-12-01 (the start date)
- WHEN the dashboard is rendered
- THEN the widget MUST be visible (both start and end dates are inclusive -- uses `<` and `>` comparisons for exclusion)

### REQ-VIS-008: Attribute-Based Rule Evaluation

Attribute-based rules MUST show or hide widgets based on user profile attributes, resolved by `UserAttributeResolver`.

#### Scenario: Attribute matches with "equals" operator
- GIVEN widget placement id 10 has an attribute inclusion rule with `attribute: "language", operator: "equals", value: "nl"`
- AND user "alice" has her language set to "nl"
- WHEN the dashboard is rendered for alice
- THEN the widget MUST be visible

#### Scenario: Attribute does not match with "equals" operator
- GIVEN widget placement id 10 has an attribute inclusion rule with `attribute: "language", operator: "equals", value: "nl"`
- AND user "bob" has his language set to "en"
- WHEN the dashboard is rendered for bob
- THEN the widget MUST be hidden

#### Scenario: Attribute with "not_equals" operator
- GIVEN an attribute inclusion rule with `attribute: "language", operator: "not_equals", value: "en"`
- AND user "carol" has her language set to "de"
- WHEN the dashboard is rendered for carol
- THEN the widget MUST be visible (language "de" is not equal to "en")

#### Scenario: Attribute with "contains" operator
- GIVEN an attribute inclusion rule with `attribute: "email", operator: "contains", value: "@company.com"`
- AND user "dave" has email "dave@company.com"
- WHEN the dashboard is rendered for dave
- THEN the widget MUST be visible

#### Scenario: Non-existent attribute
- GIVEN an attribute rule referencing `attribute: "department"` which does not exist for the current user
- WHEN the dashboard is rendered
- THEN `UserAttributeResolver` MUST return null
- AND the rule MUST evaluate as not matching (evaluateAttributeRule returns false when userValue is null)
- AND for inclusion rules, the widget MUST be hidden
- AND for exclusion rules, the widget MUST be visible

### REQ-VIS-009: Multiple Rule Combination

When a widget placement has multiple conditional rules, they MUST be combined using the VisibilityChecker logic: include rules use OR (at least one must match), exclude rules use AND (any match hides).

#### Scenario: Multiple include rules -- at least one matches
- GIVEN widget placement id 10 has two inclusion rules:
  - Rule 1: group rule, groups: ["marketing"] -- user is NOT in marketing
  - Rule 2: group rule, groups: ["sales"] -- user IS in sales
- WHEN the dashboard is rendered
- THEN the widget MUST be visible (at least one include rule matches -- OR logic)

#### Scenario: Multiple include rules -- none match
- GIVEN widget placement id 10 has two inclusion rules:
  - Rule 1: group rule, groups: ["marketing"] -- user is NOT in marketing
  - Rule 2: time rule, 09:00-17:00 -- current time is 20:00
- WHEN the dashboard is rendered
- THEN the widget MUST be hidden (no include rule matches)

#### Scenario: Exclude rule overrides include
- GIVEN widget placement id 10 has:
  - Rule 1: group inclusion rule, groups: ["marketing"] -- user is in marketing (matches)
  - Rule 2: date exclusion rule, 2026-07-01 to 2026-07-31 -- today is 2026-07-15 (matches)
- WHEN the dashboard is rendered
- THEN the widget MUST be hidden
- AND the evaluation logic is: first check include rules (OR -- at least one must match), then check exclude rules (AND -- if ANY exclude rule matches, hide)

#### Scenario: No rules on placement with isVisible=1
- GIVEN widget placement id 10 has `isVisible: 1` but no ConditionalRule records exist
- WHEN the dashboard is rendered
- THEN the widget MUST default to visible (no rules means no restrictions)

#### Scenario: No include rules but exclude rules exist
- GIVEN widget placement id 10 has only exclusion rules (no inclusion rules)
- AND no exclusion rule matches
- WHEN the dashboard is rendered
- THEN the widget MUST be visible (passesIncludeRules returns true when no include rules exist, passesExcludeRules returns true when no exclude rule matches)

### REQ-VIS-010: Visibility Evaluation Pipeline

The ConditionalService MUST evaluate visibility through a defined pipeline: isVisible flag check, then rule loading, then VisibilityChecker evaluation.

#### Scenario: Widget with isVisible=0 bypasses rule evaluation
- GIVEN widget placement id 10 has `isVisible: 0` and 3 conditional rules
- WHEN the dashboard is rendered
- THEN the system MUST immediately return false (hidden) without evaluating any rules
- AND rule evaluation MUST be skipped for performance

#### Scenario: Widget with isVisible=1 and rules triggers evaluation
- GIVEN widget placement id 10 has `isVisible: 1` and 2 conditional rules
- WHEN `ConditionalService::isWidgetVisible()` is called
- THEN the system MUST load rules via `ConditionalRuleMapper::findByPlacementId()`
- AND delegate evaluation to `VisibilityChecker::checkRules()`

#### Scenario: Widget with isVisible=1 and no rules is always visible
- GIVEN widget placement id 10 has `isVisible: 1` and no conditional rules
- WHEN `ConditionalService::isWidgetVisible()` is called
- THEN the system MUST return true without calling VisibilityChecker
- AND the widget MUST always be displayed

### REQ-VIS-011: Rule Cascade Deletion

When a widget placement is deleted, all its associated conditional rules MUST also be deleted.

#### Scenario: Delete placement cascades to rules
- GIVEN widget placement id 10 has 5 conditional rules
- WHEN placement 10 is deleted via DELETE /api/widgets/10
- THEN all 5 conditional rules MUST also be deleted
- AND no orphaned rules MUST remain in the database
- NOTE: `PlacementService::removePlacement()` does NOT explicitly cascade-delete conditional rules. This depends on database-level cascade constraints.

#### Scenario: Delete dashboard cascades to placements and rules
- GIVEN dashboard id 5 has 3 placements, each with 2 conditional rules
- WHEN dashboard 5 is deleted
- THEN all 3 placements and all 6 conditional rules MUST be deleted
- NOTE: `DashboardService::deleteDashboard()` deletes placements via `placementMapper->deleteByDashboardId()` but does not explicitly handle conditional rules.

## Non-Functional Requirements

- **Performance**: Rule evaluation for a single placement with up to 10 rules MUST complete within 50ms. Total evaluation for a dashboard with 30 placements and 100 rules MUST complete within 500ms.
- **Timezone handling**: Time-based rules currently use the server's default timezone (`new DateTime()`). A `timezone` config field is NOT currently supported. Future versions SHOULD add timezone support via PHP's DateTimeZone.
- **Data integrity**: Deleting a widget placement MUST cascade-delete all its conditional rules. Rules MUST NOT reference non-existent placements.
- **Accessibility**: Conditional visibility MUST NOT affect the accessibility tree for visible widgets. Hidden widgets MUST be fully removed from the DOM, not just hidden via CSS.
- **Localization**: Rule type labels and validation messages MUST support English and Dutch.

### Current Implementation Status

**Fully implemented:**
- REQ-VIS-001 (Create Conditional Rule): `ConditionalService::addRule()` creates rules. `RuleApiController::addRule()` exposes POST /api/widgets/{placementId}/rules with ownership verification.
- REQ-VIS-002 (List Conditional Rules): `ConditionalService::getRules()` returns rules by placement ID with ownership check.
- REQ-VIS-003 (Update Conditional Rule): `ConditionalService::updateRule()` handles partial updates. `RuleApiController::updateRule()` exposes PUT /api/rules/{ruleId}.
- REQ-VIS-004 (Delete Conditional Rule): `ConditionalService::deleteRule()` removes rules. `RuleApiController::deleteRule()` exposes DELETE /api/rules/{ruleId}.
- REQ-VIS-005 (Group-Based Rule Evaluation): `RuleEvaluatorService::evaluateGroupRule()` uses `IGroupManager::getUserGroupIds()` and `array_intersect()`.
- REQ-VIS-006 (Time-Based Rule Evaluation): `RuleEvaluatorService::evaluateTimeRule()` checks day-of-week filter and time range. Uses `strtolower($now->format('D'))` for day abbreviations.
- REQ-VIS-007 (Date-Based Rule Evaluation): `RuleEvaluatorService::evaluateDateRule()` supports optional `startDate` and `endDate`.
- REQ-VIS-008 (Attribute-Based Rule Evaluation): `RuleEvaluatorService::evaluateAttributeRule()` delegates to `UserAttributeResolver`. Supports operators: `equals`, `not_equals`, `contains`, `starts_with`, `ends_with`.
- REQ-VIS-009 (Multiple Rule Combination): `VisibilityChecker::checkRules()` separates include/exclude rules. Include uses OR, exclude uses AND.
- REQ-VIS-010 (Visibility Evaluation Pipeline): `ConditionalService::isWidgetVisible()` checks `isVisible` flag first, then loads rules, then delegates to `VisibilityChecker`.

**Not yet implemented:**
- REQ-VIS-001 ruleType validation: No server-side validation for ruleType values.
- REQ-VIS-003/004 ownership verification: `updateRule()` and `deleteRule()` in `RuleApiController` do NOT verify placement ownership.
- REQ-VIS-006 midnight-spanning windows: Simple string comparison does not handle time windows spanning midnight.
- REQ-VIS-006 timezone support: No `timezone` field support.
- REQ-VIS-011 cascade delete: `PlacementService::removePlacement()` does not explicitly cascade-delete conditional rules.
- Frontend UI: No Vue component exists for creating or managing conditional rules.

### Standards & References
- Nextcloud Group API: `OCP\IGroupManager::getUserGroupIds()`
- Nextcloud User API: `OCP\IUserManager::get()`, `IUser::getLanguage()`, `IUser::getEMailAddress()`
- PHP DateTime: Server timezone via `new DateTime()` (no timezone parameter)
- WCAG 2.1 AA: Hidden widgets must be removed from DOM, not just CSS-hidden
