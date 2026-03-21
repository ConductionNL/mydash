# Conditional Visibility Tasks

- [x] **T01**: Define `ConditionalRule` entity with type constants, `getRuleConfigArray()` / `setRuleConfigArray()`, and `jsonSerialize()` — `lib/Db/ConditionalRule.php`

- [x] **T02**: Create `RulesTableBuilder` to define the `mydash_conditional_rules` schema (id, widget_placement_id, rule_type, rule_config, is_include, created_at) with placement index — `lib/Migration/RulesTableBuilder.php`

- [x] **T03**: Register `createConditionalRulesTable()` in `MigrationTableBuilder` facade — `lib/Migration/MigrationTableBuilder.php`

- [x] **T04**: Wire conditional rules table creation into the initial migration via `MigrationTableBuilder::createConditionalRulesTable()` — `lib/Migration/Version001000Date20240101000000.php`

- [x] **T05**: Implement `ConditionalRuleMapper` extending `QBMapper<ConditionalRule>` with `find()`, `findByPlacementId()` (ordered by created_at ASC), and `deleteByPlacementId()` — `lib/Db/ConditionalRuleMapper.php`

- [x] **T06**: Implement `UserAttributeResolver` with `getUserAttributeValue()` mapping `locale`, `email`, `displayName`, `quota` to Nextcloud `IUser` methods, and `evaluateOperator()` supporting `equals`, `not_equals`, `contains`, `starts_with`, `ends_with` — `lib/Service/UserAttributeResolver.php`

- [x] **T07**: Implement `RuleEvaluatorService` with `evaluateRule()` dispatching via `match` to private methods `evaluateGroupRule()`, `evaluateTimeRule()`, `evaluateDateRule()`, `evaluateAttributeRule()` — `lib/Service/RuleEvaluatorService.php`

- [x] **T08**: Implement group rule evaluation using `IGroupManager::getUserGroupIds()` and `array_intersect()` against the `groups` config array — `lib/Service/RuleEvaluatorService.php`

- [x] **T09**: Implement time rule evaluation with `startTime` / `endTime` string comparison and optional `days` array filter — `lib/Service/RuleEvaluatorService.php`

- [x] **T10**: Implement date rule evaluation with `startDate` / `endDate` string comparison supporting open-ended ranges and inclusive boundaries — `lib/Service/RuleEvaluatorService.php`

- [x] **T11**: Implement attribute rule evaluation delegating to `UserAttributeResolver::getUserAttributeValue()` and `evaluateOperator()` — `lib/Service/RuleEvaluatorService.php`

- [x] **T12**: Implement `VisibilityChecker` with `checkRules()` splitting rules into include/exclude sets, applying OR logic for include rules (`passesIncludeRules`) and any-match-hides for exclude rules (`passesExcludeRules`) — `lib/Service/VisibilityChecker.php`

- [x] **T13**: Implement `ConditionalService` with `isWidgetVisible()`, `evaluateRule()`, `getRules()`, `addRule()`, `updateRule()`, and `deleteRule()` orchestrating mapper and evaluator — `lib/Service/ConditionalService.php`

- [x] **T14**: Implement `RuleApiController` with `getRules()`, `addRule()`, `updateRule()`, `deleteRule()`, and private `buildRuleUpdateData()` helper; inject `ConditionalService` and `PermissionService` — `lib/Controller/RuleApiController.php`

- [x] **T15**: Register the four rule API routes in `appinfo/routes.php` (`GET /api/widgets/{placementId}/rules`, `POST /api/widgets/{placementId}/rules`, `PUT /api/rules/{ruleId}`, `DELETE /api/rules/{ruleId}`) — `appinfo/routes.php`

- [x] **T16**: Add `verifyPlacementOwnership()` to `PermissionService` that traverses placement → dashboard → userId and throws on mismatch; use it in `getRules()` and `addRule()` controller actions — `lib/Service/PermissionService.php`
