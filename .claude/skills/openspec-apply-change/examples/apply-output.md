<!-- Example output — openspec-apply-change for change: add-webhook-support -->

## Implementing: add-webhook-support (schema: spec-driven)

Using change: **add-webhook-support**
Override with: `/openspec-apply-change <other-change>`

**Progress:** 0/6 tasks complete
**Schema:** spec-driven

---

Working on task 1/6: Create WebhookSubscription entity class

Added `lib/Db/WebhookSubscription.php` with properties: id, url, secret, events, registerId, schemaId, organisationId, createdAt, updatedAt.
Added `lib/Db/WebhookSubscriptionMapper.php` extending QBMapper with findAll(), findByOrganisation(), findByEventType() methods.

✓ Task complete

---

Working on task 2/6: Create WebhookSubscriptionController

Added `lib/Controller/WebhookSubscriptionController.php` with CRUD endpoints: index, show, create, update, destroy.
Added routes to `appinfo/routes.php` under `/api/webhook-subscriptions` prefix.
Added `@NoAdminRequired` and `@NoCSRFRequired` annotations on API methods.

✓ Task complete

---

Working on task 3/6: Create WebhookDispatcherService

Added `lib/Service/WebhookDispatcherService.php`.
Service fetches subscriptions matching the event type and target register/schema, signs payloads with HMAC-SHA256, dispatches via Guzzle HTTP client with retry logic (3 attempts, exponential backoff).
Added delivery logging to `lib/Db/WebhookDeliveryLog.php`.

✓ Task complete

---

Working on task 4/6: Hook dispatcher into object lifecycle events

Modified `lib/Service/ObjectService.php`:
- `saveObject()` → dispatches `object.created` event after successful save
- `updateObject()` → dispatches `object.updated` event after successful update  
- `deleteObject()` → dispatches `object.deleted` event before deletion

Event payload includes: `event`, `timestamp`, `register`, `schema`, `object`.

✓ Task complete

---

Working on task 5/6: Write unit tests

Added `tests/Unit/Service/WebhookDispatcherServiceTest.php`:
- `testDispatchesOnObjectCreated()` — verifies dispatcher called on create
- `testSkipsWhenNoSubscriptions()` — verifies graceful no-op with empty subscriptions
- `testSignsPayloadCorrectly()` — verifies HMAC-SHA256 signature in `X-OpenRegister-Signature` header
- `testRetriesOnFailure()` — verifies retry up to 3 times on HTTP failure

All 4 tests pass.

✓ Task complete

---

Working on task 6/6: Document webhook API in README

Updated `openregister/README.md`:
- Added "Webhook Subscriptions" section under API documentation
- Documented endpoint: `POST /api/webhook-subscriptions`
- Documented payload format and event types: `object.created`, `object.updated`, `object.deleted`
- Added signature verification example in PHP

✓ Task complete

---

## Implementation Complete

**Change:** add-webhook-support
**Schema:** spec-driven
**Progress:** 6/6 tasks complete ✓

### Completed This Session
- [x] Create WebhookSubscription entity class
- [x] Create WebhookSubscriptionController
- [x] Create WebhookDispatcherService
- [x] Hook dispatcher into object lifecycle events
- [x] Write unit tests
- [x] Document webhook API in README

All tasks complete! Ready to archive this change.

**What's Next**
Recommended: `/opsx-verify` | Optional: `/team-reviewer`, `/opsx-sync` | Alternative: `/opsx-archive`
