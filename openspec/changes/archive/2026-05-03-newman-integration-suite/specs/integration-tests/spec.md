# Spec delta — integration-tests

## ADDED Requirements

### Requirement: MyDash MUST ship a Newman/Postman collection covering every public OCS endpoint

A Newman/Postman collection at `tests/integration/mydash.postman_collection.json` MUST exercise every public OCS route declared in `appinfo/routes.php`, asserting on the documented happy-path response shape and at least one error envelope per route. The CI reusable workflow already declares `enable-newman: true`; this requirement formalises the contract.

#### Scenario: Collection runs in CI on every PR
- **GIVEN** the `enable-newman: true` reusable workflow is active
- **WHEN** CI executes the collection against a fresh Nextcloud test environment
- **THEN** every public OCS route returns its documented happy-path status
- **AND** every route's stable error code envelope is asserted at least once

#### Scenario: New routes block PR until collection updated
- **GIVEN** a PR adds a new route to `appinfo/routes.php`
- **WHEN** code review evaluates the PR
- **THEN** missing collection coverage MUST be flagged as a blocking review concern
- **AND** the PR MUST add the route to `tests/integration/mydash.postman_collection.json` before merging
