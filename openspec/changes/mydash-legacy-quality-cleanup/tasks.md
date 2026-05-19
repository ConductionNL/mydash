# Tasks: MyDash Legacy Quality Cleanup

## Tasks

- [ ] Task 1: Run `composer phpcs` + `composer phpmd` + `composer phpstan` and capture the baseline (starting from the 3 exclude-patterns in `phpcs.xml` and the 81-line `phpstan-baseline.neon`) plus PHPMD violation count + categories
- [ ] Task 2: Decide the PHPMD strategy (fix-outright vs capture baseline) based on Task 1 volume and confirm CI runs `composer check:strict` on every PR before starting burn-down work
- [ ] Task 3: PHPCS — fix sniffs in excluded file 1 and remove its `<exclude-pattern>` entry from `phpcs.xml`; gate stays green
- [ ] Task 4: PHPCS — fix sniffs in excluded file 2 and remove its `<exclude-pattern>` entry; gate stays green
- [ ] Task 5: PHPCS — fix sniffs in excluded file 3, remove its `<exclude-pattern>` entry, and drop the legacy-debt block from `phpcs.xml` entirely
- [ ] Task 6: PHPMD — burn down the captured baseline (or fix-outright per Task 2): reshape `if/else` → early-return (`ElseExpression`), extract methods (`CyclomaticComplexity`/`NPathComplexity`), add `use` statements (`MissingImport`), replace `StaticAccess` with DI, address variable-naming sniffs (`Long/Short/Undefined/UnusedFormalParameter`)
- [ ] Task 7: Once PHPMD baseline reaches 0 lines: delete `phpmd.baseline.xml` and drop `--baseline-file` from the composer.json `phpmd` script
- [ ] Task 8: PHPStan — inventory errors by file/type and fix common patterns (missing return/param types, mixed→generic/union, possibly-null dereferences, `==`→`===` strict-comparison nudges); regenerate baseline and confirm 0 lines, then delete `phpstan-baseline.neon`
- [ ] Task 9: CI — verify `composer check:strict` runs in CI on every PR; add a weekly smoke-test cron that runs it on `development`
- [ ] Task 10: Cleanup — after every baseline is empty, drop the residual legacy-debt section from `phpcs.xml` (if not already gone) and confirm `phpmd.baseline.xml` + `phpstan-baseline.neon` are deleted
- [ ] Task 11: Documentation — update the README quality-gates section, note in `app-config.json` that legacy quality cleanup is done, and close the burn-down tracking issue once the last baseline line is removed
- [ ] Task 12: Verification — final `composer check:strict` exits clean with no baselines, no excludes, no skipped sniffs

## Verification

`composer check:strict` exits clean on `development` with no baselines or excluded files remaining; the weekly cron stays green.

## Tests (company-wide ADR-009)

No new business-logic tests; the change tightens static-analysis gates. The weekly cron (Task 9) is the long-term regression guard.

## Documentation (company-wide ADR-010)

README + `app-config.json` updates per Task 11.

## i18n (company-wide ADR-005)

No user-facing strings introduced — quality-only cleanup.
