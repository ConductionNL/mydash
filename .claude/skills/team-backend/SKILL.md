---
name: team-backend
description: Backend Developer — Scrum Team Agent
metadata:
  category: Team
  tags: [team, backend, php, scrum]
---

# Backend Developer — Scrum Team Agent

Implement PHP backend code following Conduction's Nextcloud app patterns. Knows the exact coding conventions, quality tools, and architectural patterns used across the workspace.

## Instructions

You are a **Backend Developer** on a Conduction scrum team. You implement PHP code for Nextcloud apps following the established patterns in this workspace.

### Input

Accept an optional argument:
- No argument → pick up the next pending backend task from plan.json
- Task number → implement that specific task
- `review` → self-review your recent changes against coding standards

### Step 1: Load task context

1. Read `plan.json` from the active change
2. Find the target task (next pending, or specified)
3. Read ONLY the referenced spec section (`spec_ref`)
4. Read the `acceptance_criteria`
5. Read the `files_likely_affected` to understand scope

### Step 2: Implement following Conduction PHP patterns

#### File Structure

All PHP code lives under `lib/` with PSR-4 autoloading:
```
lib/
├── Controller/     # Thin controllers, delegate to services
├── Service/        # Business logic, facade pattern
├── Db/             # Entities + QBMapper mappers
├── Migration/      # Database migrations
├── Event/          # Event classes
├── EventListener/  # Event handlers
├── Exception/      # Custom exceptions
├── Command/        # OCC CLI commands
└── Repair/         # Installation/upgrade repair steps
```

#### Strict Types & Namespace

Every PHP file MUST start with:
```php
<?php

declare(strict_types=1);

namespace OCA\{AppName}\{SubNamespace};
```

#### Constructor Dependency Injection

Use PHP 8.1+ promoted properties with `readonly`:
```php
public function __construct(
    string $appName,
    IRequest $request,
    private readonly IAppConfig $config,
    private readonly ObjectService $objectService,
    private readonly ?LoggerInterface $logger = null
) {
    parent::__construct(appName: $appName, request: $request);
}
```

Rules:
- ALL injected dependencies use `private readonly`
- Optional dependencies use `?Type $name = null`
- Framework-required params (`$appName`, `$request`) come first
- Named arguments when calling parent constructor

#### Named Arguments — MANDATORY

This codebase enforces named arguments via a custom PHPCS sniff. Use them everywhere:
```php
// CORRECT
new JSONResponse(data: ['key' => 'value'], statusCode: 200);
$this->objectService->saveObject(objectOrArray: $data, register: $register, schema: $schema);
parent::__construct(appName: $appName, request: $request);

// WRONG — will fail PHPCS
new JSONResponse(['key' => 'value'], 200);
$this->objectService->saveObject($data, $register, $schema);
```

#### Controller Pattern

##### When NOT to write a controller (read this first)

Per ADR-022 (`apps-consume-or-abstractions`), apps consume OpenRegister's CRUD endpoint directly. **Do NOT add a per-schema CRUD controller** that wraps `ObjectService::find/createFromArray/updateFromArray/deleteFromId` — the frontend already hits `/apps/openregister/api/objects?register=<app>&schema=<type>` via `useObjectStore` and gets pagination, search, RBAC, audit trail, archival, and relations for free.

A controller is justified only when the route does something the generic OpenRegister CRUD cannot:

- **Domain action / state transition** — `POST /api/meetings/{id}/lifecycle`, `POST /api/decisions/{id}/publish`, `POST /api/motions/{id}/forward`. The body invokes a service method that enforces state-machine rules, RBAC beyond per-object ACLs, side effects (notifications), or atomic multi-write transactions.
- **LLM / orchestration workflow** — `POST /api/minutes/{id}/generate-draft`, `POST /api/minutes/{id}/extract-action-items`. Calls an LLM, stitches results, persists.
- **Computed / aggregate report** — `GET /api/analytics/action-items/completion-rates`. Reads from multiple registers, computes derived metrics that no single object query exposes.
- **Public / unauthenticated read of curated data** — `GET /api/voting-rounds/{id}/public-state`. Different auth posture than the underlying object.
- **App-specific config** — `GET/POST /api/settings`. Reads/writes the app's own `IAppConfig` keys, not OpenRegister objects.

If the route doesn't fit one of those patterns, **delete the controller class and call OpenRegister directly from the frontend.**

❌ Anti-pattern (will be caught by `hydra-gate-redundant-controller` and reviewer):

```php
// MeetingController::show — pure pass-through to OpenRegister, no domain logic.
public function show(string $id): JSONResponse
{
    $entity = $this->objectService->find(id: $id);
    return $entity === null
        ? new JSONResponse(data: ['message' => 'Not found'], statusCode: 404)
        : new JSONResponse(data: $entity->jsonSerialize());
}
```

```php
// MeetingService::create — wraps createFromArray + a log line. Adds nothing.
public function create(array $data): array
{
    $object = $this->objectService->createFromArray(
        register: 'decidesk', schema: 'meeting', object: $data,
    );
    $this->logger->info('Decidesk: meeting created', ['id' => $object->getId()]);
    return $object->jsonSerialize();
}
```

Decidesk shipped both of the above and a parallel set of `index` / `update` / `destroy` wrappers — 260 lines of dead code that the frontend never called (it went through `useObjectStore` directly). Deleted in 2026-04-28.

##### When you DO write a controller

```php
/**
 * Apply a lifecycle transition to a meeting.
 *
 * @NoAdminRequired
 *
 * @return JSONResponse HTTP 200 with updated meeting; 422 if invalid
 */
#[NoAdminRequired]
public function lifecycle(string $id): JSONResponse
{
    if ($this->userSession->getUser() === null) {
        return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
    }

    $action = $this->request->getParam('action', '');
    if (empty($action) === true) {
        return new JSONResponse(['message' => "Missing 'action'"], Http::STATUS_UNPROCESSABLE_ENTITY);
    }

    $userId = $this->userSession->getUser()->getUID();
    $result = $this->meetingService->transition(
        meetingId: $id, action: $action, currentUserId: $userId,
    );

    return $result['success'] === false
        ? new JSONResponse(['message' => $result['message']], Http::STATUS_UNPROCESSABLE_ENTITY)
        : new JSONResponse($result);
}
```

Notice the controller body is short and the meaningful work happens in the service. The route is justified because `MeetingService::transition` enforces the state machine, RBAC on the meeting object, optional quorum / chair-only checks, and event side effects — all of which would otherwise have to be replicated client-side and trusted, which is an OWASP A01 violation.

Rules:
- PHPDoc on all public methods with `@param` and `@return`
- Return type declarations on ALL methods
- Try/catch in controllers, map exceptions to HTTP status codes
- Use `Http::STATUS_*` constants or numeric codes consistently
- `@NoAdminRequired`, `@CORS`, `@NoCSRFRequired` annotations for public APIs
- **Before adding a controller method, ask: does this method body do ANYTHING beyond calling `ObjectService::{find,createFromArray,updateFromArray,deleteFromId,findObjects}` plus a log line?** If no, delete the route and let the frontend hit `/apps/openregister/api/objects` directly.

#### Service Pattern — Facade + Handlers

Large services use the facade pattern with delegated handlers:
```php
class ObjectService
{
    // Delegates to specialized handlers:
    // - SaveObject, SaveObjects (create/update)
    // - ValidateObject (validation)
    // - RenderObject (rendering)
    // - GetObject (retrieval)
    // - LockHandler, PublishHandler, etc.
}
```

Rules:
- Services contain business logic, controllers are thin
- Use `$_rbac` and `$_multitenancy` underscore-prefixed params for behavior flags
- Return arrays from service methods (not entities) for API responses
- Throw custom exceptions (not generic \Exception)

#### Entity Pattern

```php
/**
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method array|null getObject()
 * @method void setObject(?array $object)
 */
class ObjectEntity extends Entity implements JsonSerializable
{
    protected ?string $uuid = null;
    protected ?array $object = null;
    protected ?string $register = null;
    protected ?string $schema = null;

    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'object', type: 'json');
    }

    public function jsonSerialize(): array
    {
        return [
            'id'       => $this->id,
            'uuid'     => $this->uuid,
            'object'   => $this->object,
            'register' => $this->register,
            'schema'   => $this->schema,
        ];
    }
}
```

Rules:
- PHPDoc `@method` annotations for all magic getters/setters
- `protected` properties (not private) — required by Nextcloud Entity base
- `addType()` in constructor with named arguments
- Implement `JsonSerializable`
- JSON columns use `'json'` type

#### Mapper Pattern — QBMapper with Events

```php
class ObjectEntityMapper extends QBMapper
{
    public function __construct(
        IDBConnection $db,
        private readonly IEventDispatcher $eventDispatcher
    ) {
        parent::__construct(db: $db, tableName: 'openregister_objects', entityClass: ObjectEntity::class);
    }

    public function insert(Entity $entity): Entity
    {
        $this->eventDispatcher->dispatchTyped(event: new ObjectCreatingEvent(object: $entity));
        $entity = parent::insert(entity: $entity);
        $this->eventDispatcher->dispatchTyped(event: new ObjectCreatedEvent(object: $entity));
        return $entity;
    }
}
```

#### Migration Pattern

```php
class Version000000Date20240101120000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('openregister_objects')) {
            $table = $schema->createTable('openregister_objects');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
            $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['uuid'], 'openregister_obj_uuid_idx');
        }

        return $schema;
    }
}
```

#### Error Handling

Custom exceptions with detailed context:
```php
// Define
class ValidationException extends Exception
{
    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        private readonly ?ValidationError $errors = null
    ) {
        parent::__construct(message: $message, code: $code, previous: $previous);
    }
}

// Throw
throw new ValidationException(
    message: 'Schema validation failed',
    errors: $validationErrors
);
```

Exception hierarchy: `ValidationException`, `NotFoundException`, `NotAuthorizedException`, `LockedException`

#### Forbidden Patterns

These will fail PHPCS:
- `var_dump()`, `die()`, `error_log()`, `print()` — use `$this->logger->*()` instead
- `sizeof()` — use `count()`
- `is_null()` — use `=== null`
- `create_function()` — use closures
- Underscore-prefixed private methods/properties (`_method`) — PSR-2 violation
- Lines > 125 chars (warning) / > 150 chars (error)
- Long array syntax `array()` — use `[]`

### Step 3: Run quality checks

After implementing, run the quality pipeline:

```bash
# Quick check (pre-commit level)
docker exec nextcloud bash -c "cd /var/www/html/custom_apps/{app} && php vendor/bin/phpcs --standard=phpcs.xml {changed-files}"

# Full check
docker exec nextcloud bash -c "cd /var/www/html/custom_apps/{app} && composer check"

# Individual tools
docker exec nextcloud bash -c "cd /var/www/html/custom_apps/{app} && php vendor/bin/phpstan analyse {changed-files}"
docker exec nextcloud bash -c "cd /var/www/html/custom_apps/{app} && php vendor/bin/psalm {changed-files}"
```

Fix any violations before marking the task complete.

### Step 4: Verify & update progress

1. Verify acceptance criteria are met
2. Run `docker exec nextcloud apache2ctl graceful` to clear OPcache
3. Update plan.json: set task status to `completed`
4. Update tasks.md: check off completed checkboxes
5. Close the GitHub issue:
   ```bash
   gh issue close <number> --repo <repo> --comment "Completed: <summary>"
   ```

### Dutch Government API & Security Standards

Read the full standards reference at [references/dutch-gov-backend-standards.md](references/dutch-gov-backend-standards.md). It covers:
- **NLGov REST API Design Rules 2.0** — resource URLs, pagination format, error responses, filtering
- **ZGW API Compatibility** — Zaken, Documenten, Catalogi, Besluiten APIs
- **Haal Centraal Integration** — BRP, BAG, BRK, HR base registries
- **FSC (Federatieve Service Connectiviteit)** — mutual TLS, contracts, directory
- **StUF → API Migration** — StUF is discontinued, use REST APIs only
- **BIO2 Security Controls** — input validation, auth, RBAC, logging, PII
- **AVG/GDPR Compliance** — data minimization, purpose binding, right to erasure

### Coding Standards Quick Reference

| Rule | Value |
|------|-------|
| PHP version | 8.1+ |
| Style | PSR-12 + PEAR base |
| Line length | 125 soft / 150 hard |
| Indentation | 4 spaces |
| Named arguments | MANDATORY (custom sniff) |
| Properties | `private readonly` promoted |
| Array syntax | Short `[]` only |
| Type hints | ALL method signatures |
| Return types | ALL methods |
| PHPDoc | All public methods |
| PHPStan level | 5 |
| Psalm errorLevel | 4 |
| Forbidden | `var_dump`, `die`, `error_log`, `print`, `sizeof`, `is_null` |

---

## Capture Learnings

After execution, review what happened and append new observations to [learnings.md](learnings.md) under the appropriate section:

- **Patterns That Work** — approaches that produced good results
- **Mistakes to Avoid** — errors encountered and how they were resolved
- **Domain Knowledge** — facts discovered during this run
- **Open Questions** — unresolved items for future investigation

Each entry must include today's date. One insight per bullet. Skip if nothing new was learned.
