<!-- Example output — openspec-propose for change: add-webhook-support -->

Creating change: **add-webhook-support**

```bash
openspec new change "add-webhook-support"
```

Change created at `openspec/changes/add-webhook-support/`

Schema: **spec-driven** (proposal → specs → design → tasks)
Apply requires: tasks

---

### Creating proposal.md

✓ Created proposal

---

### Creating specs/webhooks/spec.md

✓ Created spec

---

### Creating design.md

✓ Created design

---

### Creating tasks.md

✓ Created tasks

---

### Final Status

```
openspec status --change "add-webhook-support"

add-webhook-support (spec-driven)
  ✓ proposal      — done
  ✓ specs         — done (1 capability: webhooks)
  ✓ design        — done
  ✓ tasks         — done (6 tasks)

All required artifacts complete. Ready for implementation!
```

---

## All Artifacts Created

**Change:** add-webhook-support
**Location:** openspec/changes/add-webhook-support/

| Artifact | Description |
|----------|-------------|
| proposal.md | Why: MKB vendors need event-driven integration without polling. What: CRUD API for webhook subscriptions + HMAC-signed dispatching on object lifecycle events |
| specs/webhooks/spec.md | 3 requirements: Subscription Management, Event Dispatching, Delivery Logging & Retry |
| design.md | PHP WebhookSubscriptionMapper + WebhookDispatcherService + Guzzle HTTP client + HMAC-SHA256 signing; Seed Data: 3 sample subscriptions for demo organizations |
| tasks.md | 6 implementation tasks with acceptance criteria |

All artifacts created! Ready for implementation.

Run `/opsx-apply` or ask me to implement to start working on the tasks.
