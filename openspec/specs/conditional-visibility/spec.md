# Conditional Visibility Specification

## Purpose

Conditional visibility allows widget placements to be shown or hidden based on dynamic rules. This enables dashboards that adapt to the user's context -- for example, showing a "Team Updates" widget only during business hours, displaying a "Holiday Schedule" widget only in December, or restricting certain widgets to specific user groups. Rules are evaluated at render time and can be inclusive (show when matched) or exclusive (hide when matched). Multiple rules on a single placement are combined with AND logic.

## Data Model

### Conditional Rules (oc_mydash_conditional_rules)
- **id**: Auto-increment integer primary key
- **placement_id**: Foreign key to oc_mydash_widget_placements
- **rule_type**: One of `group`, `time`, `date`, `attribute`
- **rule_config**: JSON blob containing the rule parameters (varies by rule_type)
- **is_include**: Boolean -- if true, the rule is an inclusion rule (show when matched); if false, it is an exclusion rule (hide when matched)

### Rule Config Schemas by Type

**group** rule_config:
```json
{"groups": ["admin", "marketing"]}
```

**time** rule_config:
```json
{"start_time": "09:00", "end_time": "17:00", "timezone": "Europe/Amsterdam"}
```

**date** rule_config:
```json
{"start_date": "2026-12-01", "end_date": "2026-12-31"}
```

**attribute** rule_config:
```json
{"attribute": "language", "operator": "equals", "value": "nl"}
```

## Requirements

### REQ-VIS-001: Create Conditional Rule

Users MUST be able to add conditional visibility rules to widget placements on their dashboards.

#### Scenario: Create a group-based inclusion rule
- GIVEN user "alice" has widget placement id 10 on her dashboard
- WHEN she sends POST /api/widgets/10/rules with body:
  ```json
  {
    "rule_type": "group",
    "rule_config": {"groups": ["marketing", "sales"]},
    "is_include": true
  }
  ```
- THEN the system MUST create a conditional rule linked to placement 10
- AND the response MUST return HTTP 201 with the full rule object
- AND the placement's `visibility` MUST be automatically set to "conditional" if it was "visible"

#### Scenario: Create a time-based exclusion rule
- GIVEN widget placement id 10 on alice's dashboard
- WHEN she sends POST /api/widgets/10/rules with body:
  ```json
  {
    "rule_type": "time",
    "rule_config": {"start_time": "18:00", "end_time": "08:00", "timezone": "Europe/Amsterdam"},
    "is_include": false
  }
  ```
- THEN the system MUST create an exclusion rule that hides the widget between 18:00 and 08:00
- AND `is_include: false` MUST mean the widget is hidden when the rule matches

#### Scenario: Create a date-based inclusion rule
- GIVEN widget placement id 10 on alice's dashboard
- WHEN she sends POST /api/widgets/10/rules with body:
  ```json
  {
    "rule_type": "date",
    "rule_config": {"start_date": "2026-12-01", "end_date": "2026-12-31"},
    "is_include": true
  }
  ```
- THEN the system MUST create a rule that shows the widget only during December 2026

#### Scenario: Create an attribute-based rule
- GIVEN widget placement id 10 on alice's dashboard
- WHEN she sends POST /api/widgets/10/rules with body:
  ```json
  {
    "rule_type": "attribute",
    "rule_config": {"attribute": "language", "operator": "equals", "value": "nl"},
    "is_include": true
  }
  ```
- THEN the system MUST create a rule that shows the widget only for users with language set to "nl"

#### Scenario: Create rule with invalid rule_type
- GIVEN widget placement id 10 on alice's dashboard
- WHEN she sends POST /api/widgets/10/rules with body `{"rule_type": "weather", "rule_config": {}, "is_include": true}`
- THEN the system MUST return HTTP 400 with an error indicating the rule_type is invalid
- AND only `group`, `time`, `date`, and `attribute` MUST be accepted

#### Scenario: Create rule on another user's placement
- GIVEN widget placement id 10 belongs to alice's dashboard
- WHEN user "bob" sends POST /api/widgets/10/rules
- THEN the system MUST return HTTP 403

### REQ-VIS-002: List Conditional Rules

Users MUST be able to retrieve all conditional rules for a widget placement.

#### Scenario: List rules for a placement with multiple rules
- GIVEN widget placement id 10 has 3 conditional rules:
  - Rule 1: group-based, include, groups: ["marketing"]
  - Rule 2: time-based, include, 09:00-17:00
  - Rule 3: date-based, exclude, 2026-07-01 to 2026-07-31
- WHEN the user sends GET /api/widgets/10/rules
- THEN the system MUST return HTTP 200 with an array of all 3 rules
- AND each rule MUST include: id, placement_id, rule_type, rule_config, is_include

#### Scenario: List rules for a placement with no rules
- GIVEN widget placement id 11 has no conditional rules
- WHEN the user sends GET /api/widgets/11/rules
- THEN the system MUST return HTTP 200 with an empty array

### REQ-VIS-003: Update Conditional Rule

Users MUST be able to modify existing conditional rules.

#### Scenario: Update rule configuration
- GIVEN conditional rule id 5 with `rule_config: {"groups": ["marketing"]}`
- WHEN the user sends PUT /api/rules/5 with body:
  ```json
  {"rule_config": {"groups": ["marketing", "sales", "management"]}}
  ```
- THEN the system MUST update the rule_config
- AND the response MUST return HTTP 200 with the updated rule

#### Scenario: Change rule from inclusion to exclusion
- GIVEN conditional rule id 5 with `is_include: true`
- WHEN the user sends PUT /api/rules/5 with body `{"is_include": false}`
- THEN the system MUST update the rule to an exclusion rule
- AND the widget MUST now be hidden when this rule matches

#### Scenario: Update rule type
- GIVEN conditional rule id 5 with `rule_type: "group"`
- WHEN the user sends PUT /api/rules/5 with body:
  ```json
  {"rule_type": "time", "rule_config": {"start_time": "09:00", "end_time": "17:00", "timezone": "Europe/Amsterdam"}}
  ```
- THEN the system MUST update both the rule_type and rule_config
- AND the old rule_config MUST be fully replaced (not merged)

#### Scenario: Update another user's rule
- GIVEN rule id 5 belongs to a placement on alice's dashboard
- WHEN user "bob" sends PUT /api/rules/5
- THEN the system MUST return HTTP 403

### REQ-VIS-004: Delete Conditional Rule

Users MUST be able to remove conditional rules from their widget placements.

#### Scenario: Delete a rule
- GIVEN widget placement id 10 has 3 conditional rules, including rule id 5
- WHEN the user sends DELETE /api/rules/5
- THEN the system MUST delete rule 5
- AND the response MUST return HTTP 200
- AND placement 10 MUST still have 2 remaining rules

#### Scenario: Delete the last rule on a placement
- GIVEN widget placement id 10 has only 1 conditional rule (id 5)
- AND the placement has `visibility: "conditional"`
- WHEN the user sends DELETE /api/rules/5
- THEN the system MUST delete the rule
- AND the placement's `visibility` SHOULD be automatically changed back to "visible" (since no rules remain to evaluate)

#### Scenario: Delete another user's rule
- GIVEN rule id 5 belongs to a placement on alice's dashboard
- WHEN user "bob" sends DELETE /api/rules/5
- THEN the system MUST return HTTP 403

### REQ-VIS-005: Group-Based Rule Evaluation

Group-based rules MUST show or hide widgets based on the current user's Nextcloud group memberships.

#### Scenario: User is in a matching group (inclusion rule)
- GIVEN widget placement id 10 has a group inclusion rule with `groups: ["marketing", "sales"]`
- AND user "alice" is a member of the "marketing" group
- WHEN the dashboard is rendered for alice
- THEN the widget MUST be visible (rule matches, is_include=true means show)

#### Scenario: User is not in any matching group (inclusion rule)
- GIVEN widget placement id 10 has a group inclusion rule with `groups: ["marketing", "sales"]`
- AND user "bob" is a member of only the "engineering" group
- WHEN the dashboard is rendered for bob
- THEN the widget MUST be hidden (rule does not match, is_include=true means only show on match)

#### Scenario: User is in a matching group (exclusion rule)
- GIVEN widget placement id 10 has a group exclusion rule with `groups: ["contractors"]`
- AND user "carol" is a member of the "contractors" group
- WHEN the dashboard is rendered for carol
- THEN the widget MUST be hidden (rule matches, is_include=false means hide on match)

#### Scenario: User in multiple groups with partial match
- GIVEN widget placement id 10 has a group inclusion rule with `groups: ["marketing"]`
- AND user "dave" is a member of groups ["engineering", "marketing", "all-staff"]
- WHEN the dashboard is rendered for dave
- THEN the widget MUST be visible (user is in at least one of the specified groups)

### REQ-VIS-006: Time-Based Rule Evaluation

Time-based rules MUST show or hide widgets based on the current time of day in the specified timezone.

#### Scenario: Current time is within the time window (inclusion rule)
- GIVEN widget placement id 10 has a time inclusion rule with `start_time: "09:00", end_time: "17:00", timezone: "Europe/Amsterdam"`
- AND the current time in Europe/Amsterdam is 14:30
- WHEN the dashboard is rendered
- THEN the widget MUST be visible

#### Scenario: Current time is outside the time window (inclusion rule)
- GIVEN widget placement id 10 has a time inclusion rule with `start_time: "09:00", end_time: "17:00", timezone: "Europe/Amsterdam"`
- AND the current time in Europe/Amsterdam is 20:00
- WHEN the dashboard is rendered
- THEN the widget MUST be hidden

#### Scenario: Time window spanning midnight
- GIVEN widget placement id 10 has a time inclusion rule with `start_time: "22:00", end_time: "06:00", timezone: "Europe/Amsterdam"`
- AND the current time in Europe/Amsterdam is 02:00
- WHEN the dashboard is rendered
- THEN the widget MUST be visible (the time window wraps around midnight)

#### Scenario: Time evaluation uses specified timezone
- GIVEN a time rule with `timezone: "Europe/Amsterdam"` and `start_time: "09:00", end_time: "17:00"`
- AND the server is in UTC where it is 08:00 UTC (09:00 CET)
- WHEN the dashboard is rendered
- THEN the rule MUST evaluate using Europe/Amsterdam time (09:00), not UTC
- AND the widget MUST be visible

### REQ-VIS-007: Date-Based Rule Evaluation

Date-based rules MUST show or hide widgets based on the current date.

#### Scenario: Current date is within the date range (inclusion rule)
- GIVEN widget placement id 10 has a date inclusion rule with `start_date: "2026-12-01", end_date: "2026-12-31"`
- AND today is 2026-12-15
- WHEN the dashboard is rendered
- THEN the widget MUST be visible

#### Scenario: Current date is outside the date range (inclusion rule)
- GIVEN widget placement id 10 has a date inclusion rule with `start_date: "2026-12-01", end_date: "2026-12-31"`
- AND today is 2026-11-15
- WHEN the dashboard is rendered
- THEN the widget MUST be hidden

#### Scenario: Open-ended date range
- GIVEN a date inclusion rule with `start_date: "2026-01-01"` and no `end_date`
- AND today is 2027-06-15
- WHEN the dashboard is rendered
- THEN the widget MUST be visible (no end date means the rule matches indefinitely from the start date)

#### Scenario: Date range boundary inclusivity
- GIVEN a date inclusion rule with `start_date: "2026-12-01", end_date: "2026-12-31"`
- AND today is 2026-12-01 (the start date)
- WHEN the dashboard is rendered
- THEN the widget MUST be visible (both start and end dates are inclusive)

### REQ-VIS-008: Attribute-Based Rule Evaluation

Attribute-based rules MUST show or hide widgets based on user profile attributes.

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

#### Scenario: Non-existent attribute
- GIVEN an attribute rule referencing `attribute: "department"` which does not exist for the current user
- WHEN the dashboard is rendered
- THEN the rule MUST evaluate as not matching
- AND for inclusion rules, the widget MUST be hidden
- AND for exclusion rules, the widget MUST be visible

### REQ-VIS-009: Multiple Rule Combination

When a widget placement has multiple conditional rules, they MUST be combined using AND logic.

#### Scenario: All rules match -- widget shown
- GIVEN widget placement id 10 has two inclusion rules:
  - Rule 1: group rule, groups: ["marketing"] -- user is in marketing
  - Rule 2: time rule, 09:00-17:00 -- current time is 14:00
- WHEN the dashboard is rendered
- THEN the widget MUST be visible (both rules match)

#### Scenario: One rule does not match -- widget hidden
- GIVEN widget placement id 10 has two inclusion rules:
  - Rule 1: group rule, groups: ["marketing"] -- user is in marketing
  - Rule 2: time rule, 09:00-17:00 -- current time is 20:00
- WHEN the dashboard is rendered
- THEN the widget MUST be hidden (second rule does not match, AND logic requires all to match)

#### Scenario: Mixed inclusion and exclusion rules
- GIVEN widget placement id 10 has:
  - Rule 1: group inclusion rule, groups: ["marketing"] -- user is in marketing (matches)
  - Rule 2: date exclusion rule, 2026-07-01 to 2026-07-31 -- today is 2026-07-15 (matches)
- WHEN the dashboard is rendered
- THEN the widget MUST be hidden
- AND the evaluation logic MUST be: all inclusion rules must match AND no exclusion rules must match

#### Scenario: No rules on conditional placement
- GIVEN widget placement id 10 has `visibility: "conditional"` but no rules exist
- WHEN the dashboard is rendered
- THEN the widget MUST default to visible (no rules means no restrictions)

## Non-Functional Requirements

- **Performance**: Rule evaluation for a single placement with up to 10 rules MUST complete within 50ms. Total evaluation for a dashboard with 30 placements and 100 rules MUST complete within 500ms.
- **Timezone handling**: Time-based rules MUST use proper timezone conversion via PHP's DateTimeZone or equivalent. The system MUST NOT assume server timezone equals user timezone.
- **Data integrity**: Deleting a widget placement MUST cascade-delete all its conditional rules. Rules MUST NOT reference non-existent placements.
- **Accessibility**: Conditional visibility MUST NOT affect the accessibility tree for visible widgets. Hidden widgets MUST be fully removed from the DOM, not just hidden via CSS.
- **Localization**: Rule type labels and validation messages MUST support English and Dutch.
