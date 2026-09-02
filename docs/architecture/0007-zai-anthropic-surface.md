# 0007 — z.ai Anthropic-compatible surface: credentialed probe evidence

**Date:** 2026-08-31 · **Milestone:** M2 (Task 2.7) · **Status:** Accepted
**Probes:** `php bin/zai-live-probe.php --surface=anthropic` plus targeted raw
requests (curl) with a real Coding-Plan key read at runtime from
`~/.config/z.ai/api_key` — never stored in the repository. Outputs record
statuses, routes, model IDs, timings, and generated text only.

## Context

Before M2, the Anthropic-compatible surface was known only from
existence probes (401 with a dummy Bearer, SPEC §3.1) and the production
`claude-glm` wrapper. Task 2.7's credentialed probe was the first
authenticated characterization; every finding below is live evidence, and
the SPEC (§3.1 route amendment, §3.2 note, §3.3 defaults) was updated in
the same change as the code.

## Findings

### 1. The same account key works on the Anthropic surface (SPEC §3.2 confirmed)

The OpenAI-surface key authenticates on both Anthropic bases: the
availability probe (authenticated `GET {base}/v1/models`) returned
connected on coding+intl and general+intl (~190 ms round trips).

### 2. `/v1/models` works on both plans — O1 resolved for the Anthropic surface

`GET {base}/v1/models` (Bearer + `anthropic-version: 2023-06-01`) returned
HTTP 200 with the **Anthropic list shape** (`data[].id/display_name/
created_at`, `first_id`/`has_more`/`last_id`) on BOTH bases, with an
identical 10-model GLM list (`glm-4.5` … `glm-5.3-flash` — the same list
the OpenAI surface returns, record 0006). Consequences implemented:

- Dynamic discovery is the primary directory path for the intl region
  (transient cached 12 h per provider+plan+region), with the shared
  plan-partitioned static fallback on every failure shape.
- The directory parser accepts `data[].id` from either list shape.

### 3. Messages routes differ per plan — and the coding surface cannot generate

Every Messages-route/model/auth combination was probed with the valid key:

| Request | Result |
|---|---|
| `POST api.z.ai/api/anthropic/v1/messages` (general) | **200**, well-formed Messages response |
| `POST api.z.ai/api/anthropic/messages` (general) | wrapped 404 (`{"code":500,"msg":"404 NOT_FOUND"}`) |
| `POST api.z.ai/api/coding/anthropic/v1/messages` (coding) | HTTP 500 + wrapped 404 body — for plain (`glm-5.3`, `glm-4.6`, `glm-4.5`, `glm-5.2`) and `[1m]` model IDs |
| `POST api.z.ai/api/coding/anthropic/messages` (coding) | HTTP **200 with the wrapped-404 body** — not a real response |
| coding routes with `x-api-key` instead of Bearer | same failures (so `x-api-key` gains nothing either; O3 stays moot) |

Interpretation: the two plans' Anthropic gateways route Messages
differently (general `{base}/v1/messages`, coding `{base}/messages`), and
the coding surface's backing service reports 404 for generation as of the
probe date — only its `/v1/models` route works. The plugin mirrors the
observed routing exactly (`ZaiAnthropicEndpoint::MESSAGES_ROUTE_BY_PLAN`)
and surfaces the coding surface's wrapped failure as a safe parse error
(fixed message, no upstream body content).

### 4. `zai_anthropic` defaults to general+intl (SPEC §3.3 amendment)

The general Messages endpoint is the production-proven path for
Coding-Plan keys on the Anthropic protocol — `~/.local/bin/claude-glm`
sets `ANTHROPIC_BASE_URL=https://api.z.ai/api/anthropic` for exactly this
shape of key. A coding default would fail every out-of-the-box generation
(finding 3), so the second provider defaults to general+intl; the coding
base remains selectable. Notably, the key GENERATED on the general
Anthropic endpoint — the Anthropic surface did not reproduce the OpenAI
surface's general-endpoint 429/1113 plan gate (record 0006).

### 5. End-to-end PASS through the plugin classes

`php bin/zai-live-probe.php --surface=anthropic --plan=general --region=intl`
(2026-08-31, defaults after the amendment): availability **connected** →
discovery **10 models** → one Messages generation through
`ZaiAnthropicTextGenerationModel` returned the expected text
("wp-connectors live probe ok", 129 total tokens, ~2.9 s). The persisted
availability state contained a binding hash, never the key.

### 6. Response shape notes

Real responses carry `content[].type` of `text`, `thinking` (with a
`signature` member; the SDK's `MessagePart` can carry thought signatures
since 1.3.0, but this adapter deliberately never replays thinking blocks,
so the signature is dropped together with the block's replay value), and
`tool_use`; `stop_reason` values observed: `end_turn`, `max_tokens` (small
`max_tokens` with thinking enabled exhausts the budget — the typed
token-limit error path). Usage uses `input_tokens`/`output_tokens` as
mapped. `claude-glm`'s `glm-5.3[1m]` IDs work only through Claude Code's
own flow — the plugin advertises the plain IDs the API itself lists.

**Known limitation — unmapped content block types (documented, not
fixed; code review 2026-09-02):** `server_tool_use`,
`web_search_tool_result`, and `redacted_thinking` blocks carry no SDK
representation and are silently dropped by the response mapping (the
SSE aggregator drops any unknown block type the same way). Accepted
because this is an upstream quirk the connector cannot trigger itself —
its requests send client function tools only (as of 2026-09-01 no live
response has carried these types). The failure surface stays typed, never
silent-empty: a response whose content consists ONLY of unmapped blocks
produces no parts and is rejected by the stop-reason/content consistency
check as `zai_invalid_response`, with the message naming the dropped
blocks when that was the case. Revisit if z.ai starts emitting
server-side tool blocks on this surface.

## Consequences

- `ZaiAnthropicEndpoint::MESSAGES_ROUTE_BY_PLAN` = coding `/messages`,
  general `/v1/messages` (both live-verified); models `/v1/models` on both.
- `ZaiAnthropicPlanRegionSettings::DEFAULT_PLAN = 'general'` (the zai
  provider keeps coding).
- SPEC §3.1/§3.2/§3.3 and the M2 acceptance criteria amended in the same
  change (the plan's live-contradiction rule).
- Revisit finding 3 periodically: if z.ai fixes coding-surface generation,
  flipping the default back is a one-constant change (plus SPEC update).
