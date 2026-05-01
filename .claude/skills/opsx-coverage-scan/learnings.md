# opsx-coverage-scan — Learnings

## Patterns That Work

- **2026-04-20 (procest, full pass):** CamelCase-aware tokenization with noise-word stripping (Controller/Service/Helper/...) is essential — without it, method `index()` in `MetricsController.php` fails to match REQ-PROM-001 "Metrics Endpoint" because `metricscontroller` is one token. Splitting on lowerUpper + ACRUpper boundaries fixed scoring across ~200 controller methods.
- **2026-04-20 (procest):** Encoding the pilot's judgment calls as an explicit `(file, method) -> (capability, req_id, confidence, signal)` override table is the right shape for the classifier. The table captures things that keyword scoring cannot recover — e.g. `ZrcController = ZRC API = REQ-003`. Keyword scoring is the fallback for everything else.
- **2026-04-20 (procest):** Conservative Bucket 3b — only REQs in capabilities with **zero** Bucket 1 methods count as unimplemented. Listing every REQ that didn't get a confident method match would have produced 290 false positives; the conservative rule yielded 73 honest unimplemented REQs across 19 untouched capabilities.

## Mistakes to Avoid

- **2026-04-20 (procest):** Initial keyword scoring at threshold 0.70 produced **zero** Bucket 1 matches — the scorer was too strict because: (1) `noise` words like "Controller" weren't stripped, (2) no CamelCase split, (3) no bonus for method-name vs. path-only overlap. The 0.70 threshold itself is fine; the signal calculation underneath needs all three features.
- **2026-04-20 (procest):** The spec heading parser must accept `####` (not just `###`) — `zgw-business-rules-compliance/spec.md` uses `#### ZRC-007: ...` and was silently dropped by a `^###\s` regex that looked correct.
- **2026-04-20 (procest):** Compound REQ IDs like `ZRC-005b/023h` or `ZRC-016/018/019/020` are real in procest's delta specs. A strict `REQ-[A-Z]+-\d+` regex drops them. Accept `[A-Z]{2,4}-[0-9]+[a-z]*` and don't try to split the compounds — keep them as a single REQ.

## Domain Knowledge

- **2026-04-20 (procest):** `case-management/spec.md` has duplicated REQ blocks (lines 63–945 then 1013–1946). The counter reports 45 REQs; only ~22 are unique. Pre-retrofit cleanup recommended before `/opsx-annotate` runs against this spec.
- **2026-04-20 (procest):** `zgw-business-rules-compliance` is a delta spec with 11 ZRC-specific REQs, but procest ships ZgwDrcRulesService, ZgwZtcRulesService, ZgwBrcRulesService with the same pattern. These files land in Bucket 2a (extend the capability) rather than 1 — the spec simply doesn't cover DRC/ZTC/BRC rules yet.
- **2026-04-20 (procest):** Procest's large ZGW controller files (ZrcController 39 methods, DrcController 32, ZtcController 21, BrcController 18) all fit the "one controller = one ZGW API = one REQ-00N in zgw-api-mapping spec" pattern. Per-method assignment under that REQ is deferred to `/opsx-annotate` because the spec's REQ-001..REQ-021 don't split cleanly per REST verb.

## Open Questions

- **2026-04-20 (procest):** For frontend Vue components, should per-method classification try harder (read `methods:` block bodies, match against REQ scenarios) or is file-level "inherit capability's first REQ with NEEDS-REVIEW" the right fidelity? For procest, 286 FE units all landed at 0.78 confidence — useful for annotating at file level but shallow at method level.
- **2026-04-20 (procest):** `workflow-import-export` spec exists (2 REQs) and procest ships `CaseDefinitionController + CaseDefinition{Export,Import}Service`. Classifier kept them in 2a — is it Bucket 1 (export/import of workflow templates) or 2a (case definitions are different from workflow templates)? Need a human read of the spec body vs. the controller to decide.
- **2026-04-20 (procest):** Bucket 1 at 678 methods exceeds the "chunk by capability" guidance (150-method threshold). If `/opsx-annotate` doesn't grow a `--capability` flag, this will produce one very large ghost-change PR that's hard to review. Recommend the scanner emit a capability-list hint the annotator can consume.
