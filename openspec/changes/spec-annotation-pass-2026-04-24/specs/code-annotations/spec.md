# Spec delta — code-annotations

## ADDED Requirements

### Requirement: Public methods in `lib/` MUST carry `@spec` PHPDoc tags pointing at the Requirements they implement

Every public method in `lib/` that implements an existing capability Requirement MUST carry a `@spec <capability>:<REQ-ID>` PHPDoc tag. This makes the spec→code link discoverable via grep, supports reverse-spec tooling, and enforces ADR-003 conformance. The 2026-04-24 coverage scan classified 61 lib/ methods as Bucket 1 (REQ matched, ready to annotate) plus 5 methods in the legacy-widget-bridge cluster.

#### Scenario: Existing public methods have a `@spec` tag
- **GIVEN** a public method in `lib/` that implements a capability Requirement
- **WHEN** the source file is grepped for `@spec`
- **THEN** the method's PHPDoc block contains a `@spec <capability>:<REQ-ID>` tag

#### Scenario: New public methods block PR until annotated
- **GIVEN** a PR adds a new public method to `lib/`
- **WHEN** code review evaluates the PR
- **THEN** missing `@spec` tag MUST be flagged as a blocking review concern
- **AND** the PR MUST add a `@spec <capability>:<REQ-ID>` tag before merging
