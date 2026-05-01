---
name: hydra-gate-redundant-controller
description: Detect controller / service methods whose body is a literal pass-through to OpenRegister's ObjectService — wrappers that ship dead code because the frontend already hits `/apps/openregister/api/objects` via `useObjectStore`. Per ADR-022 (apps-consume-or-abstractions). Observed 2026-04-19 on decidesk#60 — 5 MeetingController CRUD methods + 4 MeetingService CRUD methods (~260 lines) with zero callers. Invoked by the builder before push, by the reviewer as part of the mandatory mechanical block, and by the fixer during retry.
metadata:
  category: Hydra pipeline gate
  tags: [hydra, mechanical-gate, redundant-controller, adr-022, dead-code]
---

## Purpose

Per ADR-022 (`apps-consume-or-abstractions`), Conduction apps consume OpenRegister's CRUD endpoint directly via `useObjectStore` from `@conduction/nextcloud-vue` — the frontend hits `/apps/openregister/api/objects?register=<app>&schema=<type>` and gets pagination, search, RBAC, audit trail, archival, and relations for free. Wrapping that in a per-schema controller (`MeetingController::index/create/show/update/destroy`) plus a parallel service method (`MeetingService::create/read/update/delete`) is dead code: the frontend never calls it, and the wrapper adds no domain logic.

This gate flags methods whose:

1. **Name shapes like generic CRUD** — `index`, `show`, `read`, `find`, `create`, `update`, `delete`, `destroy`, etc. Methods named after a domain action (`publishDecision`, `transitionMeeting`, `generateDraft`, `reviseAgenda`, `submitForApproval`) are **not flagged** even when their body is short, because the name signals intent.
2. **Body's effective work is one ObjectService call** — `$this->objectService->find/createFromArray/updateFromArray/deleteFromId/findObjects` (or via a local `$objectService = $this->container->get('OCA\OpenRegister\…')`). After stripping wrapper noise (logger calls, try/catch, `JSONResponse` construction, null/false returns), the only significant statement is the CRUD call.
3. **Has no rescue patterns** — methods that also touch `requireAdmin`, `isAdmin`, role checks, `notify*`, `sendMail`, `BackgroundJob`, validation calls, or any other domain service are presumed to be doing real work and are not flagged.

Scope: `lib/Controller/*.php` + `lib/Service/*.php`. The gate participates in PR-scoped runs (Phase G) when `--scope-to-diff` / `CHANGED_FILES` are provided.

**Observed pattern (decidesk#60, 2026-04-19):**

```php
// MeetingController::index — 21 lines that just call ObjectService.
public function index(): JSONResponse {
    if ($this->userSession->getUser() === null) {
        return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
    }
    $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
    $meetings = $objectService->findObjects(
        register: 'decidesk', schema: 'meeting',
        filters: ['_limit' => $limit, '_offset' => $offset],
    );
    return new JSONResponse($meetings);
}

// MeetingService::create — wraps createFromArray + a log line.
public function create(array $meetingData): array {
    $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
    $object = $objectService->createFromArray(
        register: 'decidesk', schema: 'meeting', object: $meetingData,
    );
    $this->logger->info('Decidesk: meeting created', ['id' => $object->getId()]);
    return $object->jsonSerialize();
}
```

Both got built, merged via PR #60, and never called from the frontend (which already used `useObjectStore` against openregister directly). Deleted in 2026-04-28 retrofit. This gate prevents the same class from recurring.

## Check

The detection logic lives in `scripts/lib/detect-redundant-controllers.py` — a Python helper invoked from the bash gate runner so the multi-line PHP body parsing doesn't have to live in fragile regex. Outline:

1. Tokenise each `lib/Controller/*.php` + `lib/Service/*.php` file into `(method_name, line_no, body)` tuples by walking brace depth (handles nested control flow).
2. Skip methods whose name doesn't match the CRUD shape — domain-named methods are presumed legitimate.
3. Collapse the method body into logical statements, joining lines that span open parens (PHP's named-arg formatting routinely splits a single call across 4+ lines, which would otherwise inflate the significant-statement count).
4. Strip wrapper noise: blank lines, comments, `try {` / `} catch`, `throw $e`, log calls, `return new JSONResponse(...)`, `return $entity->jsonSerialize()`, `return null/true/false`, plain returns, `if (... === null/false)`, `$objectService = $this->container->get('…OpenRegister…')`.
5. Apply rescue patterns: anything matching `requireAdmin`, `isAdmin`, `transition`, `publish`, `approve`, `forward`, `generate*`, `extractActionItems`, `notify*`, `sendMail`, `BackgroundJob(`, any `$this->\w+Service->` call other than ObjectService, or validation methods → escape.
6. If after all that, the remaining significant work is exactly one match of `(\$this->...Service|\$objectService|\$registerService|\$schemaService|\$openRegister)->(find|findObjects|createFromArray|updateFromArray|deleteFromId|saveObject)\(` → flag.

Run from the gate runner:

```bash
python3 scripts/lib/detect-redundant-controllers.py [--changed-files=…] [APP_DIR]
```

Output: one line per finding (`<file>:<line> method=<name> rule=pass-through-to-ObjectService`), then a `# count=<N>` summary. Exit 0 on clean, 1 if any findings.

## Fix action

Two legitimate outcomes:

1. **Delete the wrapper.** Remove the controller method, its route in `appinfo/routes.php`, the matching service method, and any tests that assert on the wrapper. The frontend should hit `/apps/openregister/api/objects?register=<app>&schema=<type>` via `useObjectStore.fetchCollection('<type>')` / `saveObject` / `deleteObject` — register the type in `initializeStores()` if it isn't already.

   ```js
   // store/store.js — add the missing registration
   objectStore.registerObjectType('meeting', 'meeting', 'decidesk')
   ```

2. **Rename and add the missing domain logic.** If the spec really did need a server-side gate beyond per-object ACLs (state machine, admin-only mutation, multi-write transaction), rename the method to reflect the domain action (`transition`, `publish`, `submitForApproval`, …) and add the actual logic. Do NOT keep the CRUD-shaped name once the body grows beyond a wrapper — the name is what gets the gate to escape.

The wrong fix is to add a no-op log line or comment to push the body over the "looks like real work" threshold. Reviewers will flag it.

## Scope + false positives

- **Methods with domain-shaped names always escape.** `publishDecision`, `transitionMeeting`, `generateDraft`, `reviseAgenda` won't be flagged even when their body is one `ObjectService::saveObject(...)` call with a hardcoded state field. The detector defers to the human author's naming.
- **Multiple ObjectService calls escape** — the gate flags only methods with **exactly one** ObjectService call as the only significant statement. A method that fetches, then mutates, then re-saves is doing more than wrap CRUD.
- **DI container fetch is treated as plumbing.** `$objectService = $this->container->get('OCA\OpenRegister\…')` is wrapper noise — its presence neither confirms nor escapes the rule.
- **Magic call sites** (NC `__call`, reflection) are not detected. Rare for the wrapper pattern; if you hit a false positive, the cleanest fix is to rename the method to a domain verb.

## Implementation notes

- Logic lives in `scripts/lib/detect-redundant-controllers.py` so we don't have to maintain multi-line PHP regex in bash.
- Wired into `scripts/run-hydra-gates.sh` as Gate 10. Same `_pass` / `_fail` convention as the existing 9 gates.
- Honors `--scope-to-diff` / `CHANGED_FILES` for PR-scoped runs.
