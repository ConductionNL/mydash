# Spec: mydash-ai-dashboard-assistant

**Status:** proposed
**Scope:** mydash
**Tier:** widget-capabilities
**Depends on:** widgets, widget-add-edit-modal, runtime-shell, initial-state-contract, permissions; cross-app runtime sources: openconnector (LLM source `local-llm`), openregister (MCP discovery + GraphQL, read-only)

## Purpose

Add an embedded AI assistant widget (`mydash_ai_assistant`) that lets
the dashboard viewer ask natural-language questions about their
dashboard's data and receive a streamed reply — summarising open
cases, surfacing consultation responses, explaining an aggregate
trend. The widget is a **thin chat surface** that delegates inference
to the openconnector-registered LLM source (Ollama + Qwen via
`local-llm` per `reference_llphant-ollama-think-false`). mydash MUST
NOT carry its own LLM client SDK or call any inference endpoint
directly.

Tool calls (the LLM reading sibling-app data) MUST go through OR's
MCP discovery (ADR-022 table row "MCP discovery") — every callable
tool is an OR-published MCP capability already scoped by OR's RBAC.
The assistant is read-only by default; write tools (creating an
object, dispatching a notification) MUST require an explicit
admin-side opt-in.

Sourced from Specter draft `ai-dashboard-assistant` (2 features:
case summary, consultation-response summary).

## ADDED Requirements

### REQ-ADA-001: The system SHALL register a `mydash_ai_assistant` widget type

The widget MUST appear in `src/constants/widgetRegistry.js` and the
unified Add Widget modal (REQ-WDG-010 / REQ-WDG-014). The registry
entry MUST carry `displayName`, `defaultContent`, `renderer`, `form`,
`icon`, and a soft `requires.openconnectorSources: ['local-llm']`
declaration. The widget MUST NOT add `openconnector` to
`manifest.dependencies` — runtime checks gate behaviour.

#### Scenario: Widget registered and discoverable

- **GIVEN** the registry completeness test
- **WHEN** it runs
- **THEN** `ai-assistant` MUST be in EXPECTED_TYPES
- **AND** the entry MUST surface in `listWidgetTypes()`

#### Scenario: Widget appears in the Add modal

- **GIVEN** the Add Widget modal is open
- **WHEN** the user opens the type picker
- **THEN** `AI assistant` MUST be selectable
- **AND** picking it MUST mount the `AiAssistantForm` sub-form

### REQ-ADA-002: The widget content shape SHALL describe the assistant's bounded conversation surface

The placement persists `{type: 'ai-assistant', content: {...}}` with:

| Field | Type | Required | Default | Purpose |
|---|---|---|---|---|
| `modelAlias` | string | Yes | `'local-llm'` | openconnector source alias to route through (NEVER a model name) |
| `systemPromptKey` | string | No | `'mydash.ai.default'` | i18n key (per ADR-007 + ADR-025) resolved at render time; widget MUST NOT ship raw prompt strings |
| `scope` | enum | Yes | `'dashboard'` | `'dashboard' \| 'workspace' \| 'tenant'` — bounds the tool calls (REQ-ADA-005) |
| `allowWriteTools` | boolean | No | `false` | Opt-in for MCP write tools (default read-only) |
| `historyMaxTurns` | integer | No | `10` | Per-session conversation length cap |
| `temperature` | number | No | `0.2` | LLM sampling parameter forwarded to openconnector |

#### Scenario: Defaults applied on minimal placement

- **GIVEN** a placement with `{type: 'ai-assistant', content: {modelAlias: 'local-llm', scope: 'dashboard'}}`
- **WHEN** validation runs
- **THEN** it MUST pass
- **AND** unset fields MUST inherit the defaults above

#### Scenario: Raw model name rejected

- **GIVEN** the form attempts `modelAlias = 'qwen3.5-optimized'`
- **WHEN** `validate()` runs
- **THEN** it MUST return an error indicating model aliases must
  point at openconnector sources, not raw model names
- **AND** the Add button MUST be disabled

### REQ-ADA-003: The widget SHALL declare a `mydash_ai_assistant` entry in `src/manifest.json` with a soft `requires` clause

The manifest entry MUST live in `widgets[]` (per ADR-024). Its
`requires.openconnectorSources` MUST list `'local-llm'`. The
manifest's top-level `dependencies` MUST NOT include `openconnector`.

#### Scenario: Manifest entry present

- **GIVEN** the mydash manifest
- **WHEN** parsed
- **THEN** `manifest.widgets[].find(w => w.id === 'mydash_ai_assistant')` MUST exist
- **AND** `manifest.dependencies` MUST NOT include `openconnector`

#### Scenario: Manifest validation passes

- **GIVEN** `npm run check:manifest`
- **WHEN** the script runs
- **THEN** the widget entry MUST validate against the canonical
  schema (per ADR-024 §5)

### REQ-ADA-004: Inference SHALL route exclusively through the openconnector LLM source — mydash MUST NOT contain an LLM client

Every inference call MUST POST to the openconnector source URL for
`modelAlias`. The widget MUST consume the Server-Sent-Events stream
exposed by openconnector per the established
`project_ai-chat-companion-end-to-end` pattern (ADR-034 chain). mydash
source files MUST NOT import any LLM SDK, MUST NOT call
`localhost:11434` (Ollama default) directly, and MUST NOT contain
prompt-engineered system prompts checked into source — every prompt
is an i18n string keyed at render time.

#### Scenario: Renderer issues SSE call to openconnector

- **GIVEN** the user submits a question
- **WHEN** the widget issues the inference call
- **THEN** the target MUST be openconnector's source endpoint for
  `local-llm`
- **AND** the response MUST be consumed as SSE event chunks
- **AND** chunks MUST stream into the bubble incrementally

#### Scenario: No LLM SDK or direct-URL imports

- **GIVEN** the assistant widget source files
- **WHEN** scanned for `import .* from '@ollama'`, `from 'openai'`,
  `from '@anthropic-ai'`, `from 'llphant'`, or
  `localhost:11434`
- **THEN** zero matches MUST exist

#### Scenario: Source absent disables widget gracefully

- **GIVEN** openconnector is not installed OR `local-llm` source is
  not configured
- **WHEN** the widget mounts
- **THEN** the chat input MUST render disabled with a tooltip
  identifying the missing source
- **AND** the widget MUST NOT throw — the rest of the dashboard
  MUST remain interactive

### REQ-ADA-005: Tool calls SHALL be resolved against OR's MCP discovery — scoped by `content.scope`

When the LLM emits a tool call, the widget MUST resolve the tool
via OR's MCP discovery endpoint (ADR-022 row "MCP discovery"). Tools
not present in OR's discovery MUST be rejected. The discovery
filter MUST honour `content.scope`:

| Scope | Tool inclusion |
|---|---|
| `dashboard` | Only tools backed by registers consumed by widgets on the current dashboard |
| `workspace` | Tools backed by every register the viewer can read on this Nextcloud instance |
| `tenant` | Tools backed by every register, including cross-tenant — admin opt-in |

When `content.allowWriteTools === false` (default), write-shaped
tools (any tool whose MCP descriptor declares
`x-mutation: true`) MUST be filtered out client-side before the
LLM sees them.

#### Scenario: Read-only by default

- **GIVEN** `content.allowWriteTools === false`
- **WHEN** the widget mounts
- **THEN** the tool list passed to the LLM MUST exclude every
  tool whose MCP descriptor sets `x-mutation: true`

#### Scenario: Out-of-scope tool rejected

- **GIVEN** scope `dashboard` and the LLM emits a tool call for a
  register NOT consumed by any widget on the current dashboard
- **WHEN** the widget validates the call
- **THEN** the call MUST be rejected with an error returned to the
  LLM ("Tool out of scope")
- **AND** the call MUST NOT reach the sibling app

#### Scenario: Write tool with allow flag enabled

- **GIVEN** `content.allowWriteTools === true` AND the viewer has
  the OR-side RBAC capability to invoke a write tool
- **WHEN** the LLM emits the call
- **THEN** the call MUST be forwarded to the sibling app
- **AND** the call MUST be recorded in OR's audit-trail-immutable
  (the sibling app does this; mydash does NOT write to audit)

### REQ-ADA-006: The widget SHALL render two reply modes — streamed chat and inline summary

When the placement is large enough (≥6 grid cells), the widget MUST
render a full chat interface (input box + scrollable history +
streamed response). When the placement is smaller (`<6` cells), it
MUST render in **summary mode**: a single tap-to-refresh "Summarise
this dashboard" call that uses `systemPromptKey` as the prompt and
fills the cell with the rendered Markdown reply.

#### Scenario: Large placement renders full chat

- **GIVEN** a placement with `gridWidth × gridHeight ≥ 6`
- **WHEN** the widget renders
- **THEN** an input box + conversation history MUST be visible
- **AND** the user MUST be able to submit follow-up turns up to
  `content.historyMaxTurns`

#### Scenario: Small placement renders summary mode

- **GIVEN** a placement with `gridWidth × gridHeight < 6`
- **WHEN** the widget renders
- **THEN** a single "Summarise" button MUST be visible alongside the
  most recent reply
- **AND** clicking the button MUST issue a fresh inference call

#### Scenario: Open cases summary acceptance (Specter source)

- **GIVEN** a case worker with open cases on the dashboard AND the
  widget in summary mode
- **WHEN** the viewer clicks Summarise
- **THEN** the widget MUST stream a reply whose first sentence
  names the total open count
- **AND** each case row in the reply MUST show identifier,
  status, and last-updated date (sourced from procest GraphQL via
  the MCP tool call)

#### Scenario: Consultation responses summary acceptance (Specter source)

- **GIVEN** a consultation has received responses AND the widget
  is in summary mode
- **WHEN** the viewer triggers a summary
- **THEN** the reply MUST surface total responses + response
  breakdown without the viewer needing to navigate away

### REQ-ADA-007: History SHALL persist on the placement, not on a mydash backend

Conversation turns MUST persist only inside the Vue component's
session-local state. mydash MUST NOT add a backend table or endpoint
to store chat history — the workspace already carries no chat
history surface, and adding one would create the install-time
dependency this spec exists to avoid.

#### Scenario: History scoped to session

- **GIVEN** a chat with 3 turns
- **WHEN** the user reloads the page
- **THEN** history MUST be empty after reload
- **AND** the widget MUST NOT issue any history-load API call

#### Scenario: No backend persistence route added

- **GIVEN** the mydash backend route table after this widget ships
- **WHEN** inspected
- **THEN** zero routes matching `chat/history`, `ai/conversation`,
  or `assistant/messages` MUST exist

## Non-Functional Requirements

- **Performance:** First streamed chunk SHOULD reach the viewer
  within 1 s on the local Ollama path (warm cache). The widget MUST
  surface a thinking indicator if no chunk arrives within 500 ms.
- **Accessibility:** Streamed text MUST update via `aria-live="polite"`
  regions so screen readers announce new content. The input box
  MUST carry a proper `aria-label` and be reachable via keyboard.
- **Localisation:** Default system prompts MUST be available in
  English and Dutch (i18n keys per ADR-007 + ADR-025).
- **Privacy:** The default openconnector source MUST be `local-llm`
  (Ollama + Qwen, on-prem). Hosted LLM sources are admin-configurable
  only and MUST surface a "data leaves the instance" banner in the
  Add modal form when selected.
- **Cost:** Token usage MUST be reported by openconnector; mydash
  does NOT meter usage locally.

## Reuses (mydash)

- `widgets`, `widget-add-edit-modal`, `widget-collision-placement`
- `runtime-shell` + `initial-state-contract`
- `permissions` for view gating
- `responsive-grid-breakpoints` for mode-switch (chat vs summary)

## Standards & References

- ADR-022 — OR abstractions consumed: MCP discovery (tool list),
  RBAC (per-tool authorisation by sibling app), audit-trail-immutable
  (writes recorded by sibling app, not mydash).
- ADR-024 — manifest widget entry + soft `requires`.
- `feedback_mydash-no-or-dependency.md`.
- `project_ai-chat-companion-end-to-end.md` — proven SSE +
  openconnector pattern on decidesk.
- `reference_llphant-ollama-think-false.md` — `think: false` +
  `keep_alive: -1` are openconnector-side concerns, not mydash's.
- WCAG 2.1 AA — `aria-live="polite"` for streamed output.
