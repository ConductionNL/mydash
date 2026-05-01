<!-- Example output — openspec-explore session for OpenRegister: thinking through webhook architecture -->

> User: I'm thinking about adding webhooks to OpenRegister. Not sure about the right approach.

Active changes found: none. Starting fresh exploration.

---

Webhooks are one of those things that look simple on the surface but have a surprising amount of depth. Let me think through what you'd actually need here...

```
WEBHOOK ARCHITECTURE SPECTRUM
═══════════════════════════════════════════════════════════

Simple                                            Robust
   │                                                 │
   ▼                                                 ▼
┌────────────┐    ┌──────────────┐    ┌────────────────────┐
│ Fire &     │    │ Fire &       │    │ Fire & Retry with  │
│ Forget     │    │ Log          │    │ Queue + Dead Letter │
│            │    │              │    │                     │
│ Just POST  │    │ POST + store │    │ Async queue,        │
│ and move   │    │ delivery     │    │ backoff, DLQ,       │
│ on         │    │ result       │    │ alerting            │
└────────────┘    └──────────────┘    └────────────────────┘
   Quick to           Good for              Full production
   implement          OpenRegister          grade — overkill?
```

For a Nextcloud app, the queue approach has a problem: Nextcloud doesn't have a native async job queue — it has background jobs (cron-based), but not real-time async processing. So "fire and retry" in Guzzle within the request cycle is probably the pragmatic choice.

A few questions that would shape the design:

**Who subscribes?** Is this for MKB software vendors (Mark Visser type — they'd set up a webhook to their middleware), municipalities, or also end users? The audience affects whether you need per-user webhooks or per-organisation webhooks.

**What events matter most?** `object.created`, `object.updated`, `object.deleted` are obvious. What about schema changes? Register config changes? Some integrators would care, others wouldn't.

**Security model?** Two options:
```
Option A: Shared secret (HMAC-SHA256)
  Subscription stores a secret, payload is signed.
  Receiver verifies signature. Simple, stateless.

Option B: Mutual TLS
  More secure but way more setup friction.
  Overkill for most municipal integrations.

→ HMAC-SHA256 is the pragmatic choice for Dutch gov context.
```

**What does the subscription look like?**

```json
{
  "url": "https://integrator.example.nl/webhooks/openregister",
  "secret": "...",
  "events": ["object.created", "object.updated"],
  "register": "bevolkingsregister",     // optional filter
  "schema": "persoon"                   // optional filter
}
```

The register/schema filter is interesting — without it, a busy register would generate a lot of noise.

---

What's your mental model for the subscriber? Is this primarily for Priya-type developers integrating OpenRegister into their stack, or are you thinking of something that municipalities configure themselves through a UI?

---

> User: Mainly Priya types — developers building integrations. UI would be a plus but not the first priority.

That simplifies things nicely — API-first, UI can come later.

```
PROPOSED ARCHITECTURE
═══════════════════════════════════════════════════════════

Subscriber (Priya's integration server)
         │
         │  POST /api/webhook-subscriptions
         │  {"url":"...","secret":"...","events":["object.created"]}
         ▼
┌─────────────────────────┐
│  WebhookSubscription    │  ← stored in DB per organisation
│  WebhookMapper          │
└───────────┬─────────────┘
            │ hooks into
            ▼
┌─────────────────────────┐
│  ObjectService          │  ← saveObject(), updateObject(), deleteObject()
│  (already exists)       │
└───────────┬─────────────┘
            │ dispatches via
            ▼
┌─────────────────────────┐
│  WebhookDispatcher      │
│  - fetchSubscriptions() │
│  - signPayload()        │  HMAC-SHA256(secret, JSON payload)
│  - dispatch() w/ retry  │  3 attempts, exponential backoff
│  - logDelivery()        │
└─────────────────────────┘
```

One thing to think about: retry within the request cycle means the response to the user could be slow if a webhook endpoint is down. Most implementations solve this with a background job.

In Nextcloud, you can use `\OC\BackgroundJob\TimedJob` or trigger an async job. The webhook dispatch would queue a job, and the background processor handles delivery. This keeps the user-facing response fast.

Worth capturing in design.md when you get there.

---

## What We Figured Out

**The problem**: OpenRegister needs webhooks so MKB integrators (Priya type) can build event-driven integrations without polling.

**The approach**:
- CRUD API for webhook subscriptions (per organisation, with register/schema filters)
- HMAC-SHA256 signing
- Dispatch in background job to avoid blocking user requests
- Delivery logging with 3-retry backoff
- UI admin panel as a later phase

**Open questions**:
- Background job vs. in-request dispatch (depends on whether you want fast user response or simplicity)
- Whether to support per-event secret rotation

**Next steps** (if ready):
- Create a change proposal with `/opsx-ff add-webhook-support` or `/opsx-new add-webhook-support`
