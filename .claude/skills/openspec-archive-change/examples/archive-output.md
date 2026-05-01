<!-- Example output — openspec-archive-change for change: add-webhook-support -->

Checking status for **add-webhook-support**...

**Schema:** spec-driven
**Artifacts:** proposal ✓ | specs ✓ | design ✓ | tasks ✓ — All complete

**Task completion:** 6/6 tasks `[x]` — All complete

**Delta specs found:** `openspec/changes/add-webhook-support/specs/webhooks/spec.md`

---

### Delta Spec Assessment

Comparing `changes/add-webhook-support/specs/webhooks/spec.md` with `openspec/specs/webhooks/spec.md`...

Main spec at `openspec/specs/webhooks/spec.md` does **not exist yet** — this change creates a new capability.

Delta spec contains:
- ADDED Requirement: "Webhook Subscription Management"
- ADDED Requirement: "Webhook Event Dispatching"
- ADDED Requirement: "Delivery Logging and Retry"

**Changes that would be applied:**
- Create new `openspec/specs/webhooks/spec.md` with 3 requirements from the delta spec

**Recommendation:** Sync now (recommended)

---

> **Sync now (recommended)** — will create openspec/specs/webhooks/spec.md

User confirmed: Sync now

Syncing delta spec via openspec-sync-specs...
✓ Created `openspec/specs/webhooks/spec.md` with 3 requirements

---

### Archiving change...

```
mv openspec/changes/add-webhook-support openspec/changes/archive/2026-04-10-add-webhook-support
```

✓ Archived to `openspec/changes/archive/2026-04-10-add-webhook-support/`

---

### Updating CHANGELOG.md...

Version from `appinfo/info.xml`: `1.4.0`

Added under `## [1.4.0]`:
```
### Added
- Webhook subscription management — POST/GET/PUT/DELETE /api/webhook-subscriptions
- Webhook event dispatching on object.created, object.updated, object.deleted
- HMAC-SHA256 signature verification header (X-OpenRegister-Signature)
- Delivery logging with retry up to 3 attempts
```

✓ CHANGELOG.md updated

---

## Archive Complete

**Change:** add-webhook-support
**Schema:** spec-driven
**Archived to:** openspec/changes/archive/2026-04-10-add-webhook-support/
**Specs:** ✓ Synced — created openspec/specs/webhooks/spec.md (3 requirements)
**Changelog:** ✓ Updated CHANGELOG.md (v1.4.0, 4 entries added)

All artifacts complete. All tasks complete.
