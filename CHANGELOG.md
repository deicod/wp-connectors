# Changelog

All notable changes to this repository are documented here, per plugin and per
tooling area. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning per plugin follows its own header `Version` (no monorepo version).

## [Unreleased]

### Fixed (zai / M2 — Codex PR review, round 7)

- A `tool_use` response block OMITTING its `input` member or setting it
  to `null` is now rejected as a typed parse error instead of being
  normalized into a no-argument FunctionCall: the Messages protocol
  requires the member, and an empty call is represented by `{}`
  alone (supersedes the R2-era tolerance; `{}` stays legitimate).
- A FunctionDeclaration whose parameter schema is a non-empty
  SEQUENTIAL array is rejected before transport: it serializes
  `input_schema` as a JSON LIST and the tools contract requires an
  object (upstream 400). String-keyed and mixed-key schemas still
  encode as objects; null/empty keep their `{}` normalization.
- A duplicate `content_block_start` for an already-started index now
  invalidates the stream: the silent accumulator reset discarded every
  fragment collected before the duplicate while the completion still
  reported success with altered content.
- An SSE frame whose `event:` field and `data.type` member are BOTH
  present as strings but disagree now invalidates the stream: the field
  always won, so `event: ping` carrying a `content_block_delta` payload
  was ignored as keep-alive and the answer completed with the content
  chunk missing. Frames with only one declaration keep their behavior.

### Fixed (zai / M2 — Codex PR review, round 6)

- An explicitly-null response envelope `type` (`"type": null`) is now
  rejected like any contradictory type value: isset() treated null as an
  omitted member; the presence check now uses array_key_exists(), so
  only a genuinely omitted member keeps the documented tolerance.
- A streamed `message_start` declaring a role other than `assistant`
  (explicit `user`, `null`, or any other value) is now rejected: the
  aggregator hardcodes `role:assistant` in the consolidated payload, so
  such a stream previously bypassed the model's exact-role check — a
  bypass the non-streaming path never had. An omitted streamed role
  keeps the documented assistant default.
- A `content_block_start`/`content_block_delta`/`content_block_stop`
  event whose `index` member is missing or not a non-negative integer
  (string, float, null, negative) now invalidates the stream: the value
  was previously coerced to index 0, so a malformed event mutated the
  WRONG block while the stream still reported success.
- A delta whose type conflicts with the started block's type now
  invalidates the stream instead of being silently discarded:
  `input_json_delta` on a text/thinking block previously finished with
  `stop_reason: tool_use` while omitting the tool call entirely, and
  text/thinking deltas on a tool block accumulated then dropped.
  Unknown delta types keep their forward-compatible tolerance. As a
  side effect, a thinking delta on a start-less index now seeds a
  thinking accumulator, so its content surfaces instead of being
  dropped (supersedes the R5 tolerance note).
- A top-level `content` member that is a JSON OBJECT (`"content": {}`
  or numeric-keyed) is now rejected: associative decoding collapses it
  onto the empty-array/PHP-array shapes of a legal list, so it
  previously parsed as a successful candidate with no parts. The raw
  decode's array/object distinction decides; `"content": []` stays
  protocol-legal.

- The Debug logging checkbox renders again on Settings → z.ai: the
  field was still attached to the pre-refactor option-group section id,
  which no registered section uses — `do_settings_sections()` renders
  only fields of registered sections, so the toggle silently
  disappeared when the settings sections became per-provider. It now
  attaches to the registered zai section (one shared debug toggle,
  exactly the M1 UX), pinned by a test asserting every registered
  field's section id belongs to a registered section of the page.

### Fixed (zai / M2 — Codex PR review, round 5)

- Tool parts are validated against their message's role before
  transport: a FunctionCall in a user message or a FunctionResponse in
  an assistant message (histories the Messages protocol rejects with a
  400) now fail with the typed invalid-request error. The SDK's Message
  DTO already blocks both pairings at every construction path, so the
  guard is defense-in-depth for SDK bypasses (relaxed future DTO,
  unserialized state) — pinned by a reflection-bypass regression test.
- An `input_json_delta` for a stream index whose `content_block_start`
  was never received now invalidates the stream: the previous tolerant
  default created a text accumulator, so the tool's JSON fragments were
  collected and ignored and a tool_use completion "succeeded" with no
  FunctionCall at all. A genuine text (or thinking) delta on an unseen
  index keeps the documented tolerance — the chunk is still surfaced.
- A Messages generation response must identify itself with the exact
  `assistant` role: a missing, unknown, or `user` role previously
  fabricated an assistant turn or exposed the payload as a generated
  USER message — both now fail as a typed parse error instead of
  mis-attributing content into downstream history.
- `model_context_window_exceeded` is no longer folded into the
  `max_tokens` case: the model's overall CONTEXT window being exhausted
  cannot be recovered by raising maxTokens (it leaves even less room),
  so it now carries its own advice — reduce the input, truncate the
  history, shorten the prompt — and a null typed maxTokens payload that
  ErrorMapper keys on; the raise-maxTokens advice stays on genuine
  `max_tokens` stop reasons only.
- A success-shaped body whose top-level envelope identifies as anything
  other than `"message"` (e.g. a `type:"error"` envelope carrying a
  valid-looking payload) is now rejected as a typed parse error instead
  of parsing as a generation (verifier residual on the R5 round). The
  unseen-index tolerance note was also corrected: a thinking delta on a
  start-less index is tolerated with its thought content dropped, not
  surfaced.

### Fixed (zai / M2 — Codex PR review, round 4)

- `authenticateRequest()` now strips any pre-existing `x-api-key`
  header (case-insensitively) before setting Bearer + anthropic-version:
  a reused or decorated request could otherwise transmit a stale second
  credential alongside the Bearer key, violating the never-x-api-key
  contract. The SDK's header collection has no removal API, so the
  request is rebuilt from its remaining headers with method, URI,
  body/data, and transport options carried over verbatim.
- Empty text parts are dropped from outbound Messages requests: the
  protocol rejects empty text blocks with a 400, and a message whose
  visible parts are all empty now falls through to the existing
  no-translatable-content pre-transport rejection instead of failing
  upstream.
- A FunctionCall from chat history whose arguments are a non-empty
  SEQUENTIAL array (which would encode `input` as a JSON list) is
  rejected before transport with the typed invalid-request error — the
  Messages tool schema requires an object. Mixed/string-keyed arrays
  still encode as objects and the empty-array→`{}` normalization is
  unchanged.
- An `input_json_delta` whose `partial_json` member is NULL or OMITTED
  is now flagged as malformed streamed arguments (isset() is false for
  both, so both were silently ignored — letting the initial `{}` become
  an executable no-argument call). The legitimate empty-string fragment
  keeps its no-op semantics.
- A frame DECLARING a known event name (message_start, content_block_*,
  message_delta, message_stop, error) with an undecodable payload now
  fails the whole stream as a typed parse error: silently dropping a
  malformed content_block_delta returned a successful completion with
  that chunk of the answer missing. Unknown event names and
  ping/keep-alive frames stay ignorable for forward compatibility.
- The independent verification sweep extended that invalidation to the
  same corruption class it found still slipping through: a declared
  event with a valid-JSON LIST payload (is_array cannot distinguish a
  JSON list from an object — the raw non-associative decode can), a
  decodable-but-wrong-shape `content_block_delta` (delta member
  missing/null/scalar, non-string delta.type, non-string text/thinking
  members), the same shapes arriving via data-only frames dispatched by
  their type member, and a `content_block_start` whose content_block
  member is absent or not an object (which silently swallowed the
  block's deltas). Unknown delta types stay ignorable.

### Fixed (zai / M2 — Codex PR review, round 3)

- `"input": []` (the empty JSON LIST) and boolean tool inputs are now
  rejected on the regular Messages response path: associative decoding
  collapses `{}` and `[]` to the same empty PHP array, so object-ness is
  decided against a parallel NON-associative decode of the body (the raw
  value is stdClass only for objects). `{}` and a missing input member
  stay legitimate no-argument calls. The SSE consolidated payload now
  preserves `{}` as an object across the aggregator/model boundary for
  the reason.
- A malformed `content_block_start` tool input (scalar, list — including
  `[]` — or boolean, with no argument deltas following) is no longer
  silently replaced with `{}`: the stream start block's ORIGINAL input
  shape is validated against a non-associative decode of the same frame
  and flagged as the same typed stream-parse error; object inputs become
  the initial argument value and missing/null stay the no-argument
  placeholder.
- The build-cleanup regression test now drives the guard with a
  throwaway artifact name, so it runs (and passes) against a prepared
  release `dist/` too instead of failing on its unconditional
  no-sidecar precondition.
- Successful `zai_anthropic` model discovery is intersected with the
  active plan's catalog before caching: the live `/v1/models` route
  returns the full list on the coding plan (record 0007), but the coding
  subscription exposes only its restricted model set, so general-only
  GLM 4.x entries are no longer advertised or cached while coding is
  selected; the general plan keeps the full discovered list. A discovery
  response with no in-plan models falls back to the plan catalog without
  caching.
- A non-string `partial_json` member inside an `input_json_delta` event
  (a corrupt streamed-arguments shape the protocol types as a string) is
  now flagged as the same typed stream-parse error instead of being
  silently dropped, which could surface a no-argument call built from a
  broken stream (verifier finding on the R3 round).

### Fixed (zai / M2 — Codex PR review, round 2)

- The non-streaming Messages response path now applies the same
  tool-argument object-ness validation as the streaming path (R1): a
  tool_use `input` that is not a JSON object (scalars, lists) fails as a
  typed parse error instead of passing fabricated/invalid arguments to a
  FunctionCall; `{}` and a missing input member stay legitimate
  no-argument calls.
- The artifact build test's dist/ preservation moved into a reusable
  two-level-finally guard: a failing build assertion can no longer skip
  the removal of the seeded checksum sidecar/manifest (which tearDown
  does not clean), so no introduced file lingers to contaminate later
  runs.
- Settings invalidation no longer autoloads SDK classes: on WP 6.9
  without the optional PHP AI Client plugin the settings UI still boots,
  and a plan/region save previously reached dynamic class-constant
  accesses on the availability/directory classes (which implement
  missing SDK types) — a fatal error right after the option write. The
  invalidation identifiers now live in the SDK-free settings layer (the
  availability/directory constants mirror them; the discovery-cache key
  is composed inline), the region-pending implementation moved there
  too, and a consistency test pins every identifier pair plus the
  cache-key format. An out-of-process test runs the invalidation with
  the SDK genuinely absent and proves it completes cleanly.

### Fixed (zai / M2 — Codex PR review, round 1)

- Streamed tool arguments that do not decode to a JSON object (truncated
  input_json_delta fragments, scalars) now fail as a typed stream-parse
  error instead of silently becoming a no-argument tool call — a consumer
  could have executed a side-effecting tool with inputs the model never
  produced. The legitimate empty-object stream (`{}`) still yields a
  no-argument call.
- Consecutive same-role turns (two user turns in a row, etc.) are now
  coalesced into one message with merged content blocks — the protocol's
  own combining rule — instead of being rejected pre-transport; generic
  chat histories with repeated turns now round-trip. An empty prompt and
  a non-user first message are still rejected.
- The real-artifact build test no longer damages release-verification
  state: the zip, its `.sha256` sidecar, and the zip's entry in
  `dist/checksums.txt` are all snapshotted and restored (or removed when
  the test introduced them), and the class tearDown restores a prepared
  manifest instead of deleting it — a prepared `dist/` directory is left
  exactly as it was, verified by post-restore assertions.
- An `outputSchema` now requests the JSON guidance on its own — the MIME
  option and the schema are advertised independently, and a schema
  without `outputMimeType: application/json` was silently discarded into
  an unconstrained request.
- A parameterless tool declaration whose parameters are an empty array
  (not null) now normalizes to the empty-object input schema — the raw
  `[]` encoding failed the Messages tool-schema validation upstream.

### Fixed (zai / M2 — independent review round)

- Two independent reviews of the full M2 diff (security-focused and
  correctness-focused) found no critical/high issues; their actionable
  findings are fixed:
- A message whose parts all drop (thought-only replay, or no parts at
  all) is now rejected before transport with a precise message instead of
  degrading to an empty text block the Messages API answers with a
  misleading 400.
- A stream truncated before `message_delta` now fails with the fixed
  parse-error message instead of fabricating a clean `end_turn` stop.
- `TokenLimitReachedException` now carries the applied limit in its typed
  `maxTokens` payload (consumers of the accessor no longer see null).
- `ZaiAnthropicRequestAuthentication::wrap()` fails closed on a foreign
  authentication implementation instead of silently sending the request
  unauthenticated.
- The first persisted PLAN change on a fresh install now runs the state/
  cache invalidation (add_option_{plan} companion hooks, symmetric with
  the region hooks).
- The zai_anthropic live smoke test defaults to the general plan (the
  coding surface cannot generate per record 0007), several SSE fixtures
  now use genuine single-frame event:/data: pairing, the webSearch
  rejection gained its own test, and record 0007's note about the
  thinking `signature` member was corrected (the SDK can carry thought
  signatures since 1.3.0; this adapter drops them deliberately).

### Added (zai / M2 — validation, docs, live evidence; Task 2.7)

- Uninstall now removes BOTH providers' options and discovery caches
  (still leaving the core-owned key options), and the artifact test suite
  proves the standalone zip ships both providers' source trees.
- `bin/zai-live-probe.php` grew a `--surface=anthropic` mode; opt-in live
  smoke test for the second provider
  (`WP_CONNECTORS_TEST_ZAI_API_KEY`).
- Live evidence (2026-08-31, record 0007): `/v1/models` works on both
  Anthropic plans (same 10-model GLM list, Anthropic shape) — the
  Anthropic half of O1 is resolved; the two plans route Messages
  differently (general `/v1/messages`, coding `/messages`), and the
  coding surface cannot generate at all as of that date (wrapped 404s for
  every probed model/auth combination). `zai_anthropic` therefore now
  defaults to general+intl — the production-proven path for Coding-Plan
  keys — and the SPEC (§3.1–§3.3) was amended in the same change.
  End-to-end live PASS on general+intl through the plugin classes.

### Changed (zai / M2 — live-evidence amendment, record 0007)

- `zai_anthropic` default plan general (was coding): the coding-surface
  Anthropic Messages routes answer with wrapped 404 errors for every
  probed combination, so a coding default would fail out of the box.
  The zai provider keeps its coding default; the coding Anthropic base
  stays selectable and its `/v1/models` route works.

### Added (zai / M2 — `zai_anthropic` endpoint resolution, auth, metadata, Messages protocol; Tasks 2.2–2.6)

- Anthropic-surface endpoint resolver with the four SPEC §3.1 `/anthropic`
  bases; `/v1/messages` and `/v1/models` are appended exactly once (a base
  already carrying a suffix is normalized first), and the per-surface
  canonical `baseUrl()` stays fixed.
- Requests authenticate with `Authorization: Bearer <key>` plus
  `anthropic-version: 2023-06-01`; `x-api-key` is never sent (unverified on
  z.ai). Exactly one of each header leaves the authentication method, even
  over a stale preset value.
- Custom (non-OpenAI-compat) model directory: opportunistic, transient-
  cached `/v1/models` discovery keyed provider+plan+region (12 h) with the
  shared plan-partitioned GLM fallback on every failure shape; the
  fallback is never cached, so a later valid key can still discover.
- Full Messages request mapping: system instruction, alternating
  user/assistant content blocks, tools + tool results (empty arguments
  encode as `{}`), JSON output guidance with `outputSchema` embedded in
  the system prompt (native `output_format` is unverified on z.ai), and
  the protocol-required `max_tokens` with a 4096 default. Role-order
  violations and unsupported options fail before any transport work.
- Messages response and stream mapping: content blocks (text, thinking,
  tool_use), stop reasons, usage (cache token variants included), and the
  Anthropic SSE event sequence (message_start, content_block_start/delta/
  stop, message_delta, message_stop, ping, error) incl. interleaved
  text/thinking/tool deltas, split frames, malformed events, and a final
  event without a trailing blank line. Errors map through the one shared
  redacted catalog; a max_tokens stop surfaces as the typed token-limit
  error. The protocol-neutral SSE frame splitting moved into a shared
  `SseFrameBuffer` used by both aggregators (extend, not fork).

### Added (zai / M2 — second provider `zai_anthropic`, Task 2.1)

- The `zai` plugin now registers a second provider, `zai_anthropic`
  (card "z.ai (Anthropic API)"), alongside `zai`. Both registrations are
  individually idempotent, and the registrar refuses to register onto a
  provider ID a foreign class already holds — the SDK's `registerProvider()`
  would silently overwrite it — so one provider's registration can never
  replace the other's.
- `zai_anthropic` has its own plan/region options
  (`zai_connector_zai_anthropic_plan` / `_region`; initially coding +
  intl, later amended to general + intl by the record-0007 live evidence
  above) rendered as a second section on the shared settings page; the
  two providers' selections cannot bleed into each other.
- A `zai_anthropic` region switch clears that provider's validated state,
  discovery caches, region-pending flag, and stored key
  (`connectors_ai_zai_anthropic_api_key`) — never the `zai` provider's data.
- Availability is validated independently per provider: separate state
  options and endpoint-scoped bindings mean a validated `zai` key can never
  establish `zai_anthropic`'s connected status (and vice versa); a
  nonempty-but-invalid key reports not-connected for this provider too.
- The M1 settings/availability logic moved into shared per-provider base
  classes (`AbstractPlanRegionSettings`, `AbstractZaiProviderAvailability`)
  with the `zai` classes as thin children — no behavior change for `zai`.

### Fixed (zai / M1 — Codex PR review, round 3)

- Network uninstall pagination actually advances: the multisite cleanup
  passed `paged` to `get_sites()`, an argument WP_Site_Query does not
  support — on networks of 100+ sites every batch re-returned the same
  first 100 sites, so the loop never advanced (request timeout) and later
  sites were never cleaned. The batches now advance the supported `offset`
  (0, 100, 200, …) and stop on the first short batch; the test harness's
  `get_sites()` is core-faithful (ignores `paged`, fails a non-advancing
  loop fast) so the tests prove the advance across multiple batches.
- A region switch now also distrusts environment/constant credentials
  until they are DEFINITIVELY validated for the new region: those sources
  are immutable (the plugin cannot delete them like the stored key), so an
  inconclusive probe (the China /models route 404s → configured-pending)
  previously left the connector "connected" with the old-region env key
  against the new endpoint indefinitely. The switch records a
  region-pending flag (new region + SHA-256 fingerprint of the riding
  credential): while exactly that key is effective, an inconclusive probe
  reports not-connected; an authenticated 2xx (connected) or 401/403
  (rejected) settles it, and any different credential — including the
  candidate core wires during key-save validation — keeps the normal
  pending-accept semantics. New option `zai_connector_zai_region_pending`
  (non-autoloaded, fingerprint only, removed on uninstall; record 0004
  updated).

### Fixed (zai / M1 — Codex PR review, round 2)

- Region switch now deletes the STORED key, not just the validated-state
  verdict: with an inconclusive probe (the China /models route 404s) the
  connector previously stayed "connected" and would send the old
  international key against the China endpoint indefinitely. After a
  switch no key is stored, so the connector stays not-connected until a
  key for the new region is supplied (plan changes never touch the key —
  coding/general share one account). Pending-accept semantics remain only
  for core's key-save validation path (record 0004 region-switch note).
- Generation through the real core prompt path works again: the model
  catalog now advertises `outputModalities` (text). The SDK's prompt
  builders — including core's `wp_ai_client_prompt()` — require that
  option during model resolution, so every builder-driven `generate_text()`
  previously matched no zai model at all; covered by a test that drives the
  genuine `WP_AI_Client_Prompt_Builder` end to end.
- Error mapping is honest about the two surfaces it lives on (SPEC §6.2,
  plan Task 1.7, readme updated): through the core builder, callers get
  core's fixed codes (`prompt_client_error`, …) with the message passed
  through VERBATIM — the plugin now builds every model exception from the
  one shared `ErrorMapper::safe_http_message()` catalog, which is what
  keeps that path redacted; the typed `zai_*` codes remain the direct
  model-use API (`generate_text()`/`ErrorMapper`) and cannot be delivered
  through the core builder (WordPress core limitation, no filter exists).
- SSE streams ending directly after the last `data:` line (no trailing
  blank line) no longer lose that frame: `finish()` flushes the remaining
  buffered frame — single-event streams previously failed as
  `zai_invalid_response`, multi-event streams lost their final content.
- Network uninstall removes plugin-owned data from EVERY site: options and
  transients are per-site, so a network-activated uninstall now iterates
  the sites (offset batches of 100, blog context restored) instead of
  cleaning only the current site.

### Fixed (tooling — Codex PR review, round 2)

- `bin/inspect-artifact.php`: a zip whose sole top-level entry is a FILE
  (e.g. `plugin.php`) is now rejected as a normal violation instead of
  crashing the directory iterator, and the temp extraction tree is cleaned
  up on every path (try/finally).
- `bin/lib/plugin-tools.php`: exactly one main plugin file is now enforced
  — archives with two root-level `Plugin Name` headers (something WordPress
  would expose as two plugins) are rejected by the conventions check, the
  builder, and the inspector, which previously all accepted the first match
  and ignored the second.
- `bin/build.php`: the version-constant check is part of the build refusal
  gate — a bumped header with a stale `{SLUG}_VERSION` constant can no
  longer be packaged as a mislabeled zip.

### Fixed (zai / M1 — independent multi-agent review)

- Directory caching hardened: the SDK-level cache key (`getBaseCacheKey()`)
  is now endpoint-scoped (plan+region), so a warm SDK cache — including a
  persistent PSR-16 cache configured via `AiClient::setCache()` — can never
  serve the previous endpoint's catalog after a settings switch, and the
  static fallback is never persisted in ANY cache layer (previously the
  24h SDK cache could pin a fallback or a stale catalog; plan switches now
  also invalidate plugin transients like region switches).
- Availability probe: only 401/403 persist an invalid verdict; 429 and other
  4xx stay transient — z.ai returns 429 "Insufficient balance" (code 1113)
  for plan mismatches on an otherwise VALID key, which must not poison the
  connected state (nor let core's REST key validation erase a good key).
- SSE framing per spec: CR/LF/CRLF terminators mixed freely are now
  recognized (a `\n\r\n` boundary previously merged two events and lost both).
- Malformed-payload exceptions carry fixed messages: the SDK embeds the
  upstream `finish_reason` verbatim in its parse exceptions; the model now
  re-wraps them so no response-body content can reach error surfaces.
- Live probe builds the model through `getProviderModel()` (a PHPStan fix had
  broken transporter/auth binding, making the recorded evidence
  non-reproducible with the committed tool); re-verified coding+intl PASS.
- Settings save guard restructured so the capability strip path is genuinely
  reachable (nonce failures are terminal in core via `check_admin_referer`).
- Record 0004 autoload table amended to match shipped behavior (tiny
  non-secret options use the core autoload default; log and key-state
  options stay non-autoloaded).

### Added (zai / M1)

- `connectors/zai` plugin scaffold: 6.9-compatible header, guarded bootstrap
  with missing-SDK admin notice, own PSR-4 autoloader, idempotent provider
  registration at `init` priority 5; registers only the `zai` provider in M1
  (Task 1.1).
- Plan/region settings (`zai_connector_zai_plan` coding|general,
  `zai_connector_zai_region` intl|cn) via the Settings API on a dedicated
  Settings → z.ai page: whitelist sanitization with corrupt-value fallback,
  capability+nonce save guard, and region-switch invalidation of plugin-owned
  credential-derived state (the core-owned key option is never written)
  (Task 1.2).
- Immutable endpoint resolver (`ZaiEndpoint`) covering the four SPEC §3.1
  plan × region base URLs with request-time option reads; provider
  `baseUrl()` stays fixed to the intl-general canonical URL (Task 1.3).
- Provider metadata with SDK version guards (description ≥1.2.0, logo ≥1.3.0,
  shipped logo asset) and availability as an authenticated /models probe with
  a persisted verdict bound to the complete key hash + key source + endpoint
  identity; transport/5xx failures stay transient and never persist a verdict
  (Task 1.4).
- Model metadata directory (O1 resolved, record 0006): dynamic `/models`
  discovery with a 12-hour transient cache scoped to provider+plan+region,
  newest-first GLM sorting, plan-partitioned static fallbacks (coding: GLM
  5.x family; general: full catalog), malformed/401/404/transport responses
  falling back without poisoning the cache, and conservative text-only
  capability/option declarations (Task 1.5).
- Chat-completions request mapping on the SDK OpenAI-compatible base class
  with request-time endpoint resolution; unsupported option/model
  combinations (image input, candidateCount, penalties, topK, logprobs,
  web search, non-text output modalities/MIME types, custom options) are
  rejected before transport. Committed redacted request snapshots cover
  minimal, conversation, structured-output, tool, and multimodal-text cases
  (Task 1.6).
- Response/streaming/error mapping: non-streaming and SSE responses (split
  frames, `[DONE]`, comments, malformed events, tool-call delta merging,
  finish reasons, usage) normalize into SDK results; 401/403/429/4xx/5xx/3xx
  throw SDK-typed exceptions whose messages never include upstream bodies,
  with `ErrorMapper::to_wp_error()` exposing stable typed codes
  (`zai_unauthorized`, `zai_rate_limited`, …); no retries in v1 (Task 1.7).
- Observability and admin links: option-gated debug logging (default OFF) of
  method + redacted URL (query stripped) + status + duration only, recorded
  via a transporter decorator across inference, availability, and discovery
  requests into a bounded ring buffer; a plugin-row Settings link and the
  debug checkbox + log viewer on the settings page (Task 1.8).
- M1 validation and documentation: WordPress-style `readme.txt` (usage, key
  storage disclosure, endpoint behavior, multisite, troubleshooting),
  `uninstall.php` removing plugin-owned options/caches only, opt-in live
  smoke tooling (`bin/zai-live-probe.php` + env-gated `ZaiLiveSmokeTest`,
  key read at runtime from `ZAI_LIVE_API_KEY`/`~/.config/z.ai/api_key`),
  and recorded live evidence in record 0006 — coding+intl PASS end to end,
  general-plan 429/1113 (account property) surfaced as the typed rate-limit
  error (Task 1.9).

### Added (tooling / M0 foundation)

- Architecture records (`docs/architecture/`) verifying the WP 7.0/7.1
  Connectors API and PHP AI Client SDK 1.3.1 contracts; z.ai `/models`
  evidence resolving SPEC open question O1 for the OpenAI/intl surface.
- Composer dev toolchain (PHPUnit, PHPCS + WordPress standards,
  PHPCompatibility, PHPStan) with the pinned genuine SDK 1.3.1; offline
  validation entry point `composer check`.
- WordPress API test harness (`tests/harness/`): deterministic clock, option/
  cron reset between tests, users/capabilities, nonces, fail-closed HTTP on
  both `wp_remote_*` and SDK transport, encrypted-option/secret assertions.
- Repository conventions document with automated enforcement
  (`composer conventions`).
- Standalone artifact builder (`bin/build.php`) with deterministic zips +
  SHA-256 checksums and an artifact inspector
  (`bin/inspect-artifact.php`) rejecting repo-relative includes, missing
  headers, dev files, and embedded secrets.
- Secure test-fixture rules: fake secret factories, HTTP response builders,
  secret-pattern scanner (`composer scan-secrets`), documented opt-in
  environment variables for live tests.

### Fixed (M0 independent review hardening)

- Unmocked-HTTP leak audit moved from `tearDown()` (a no-op under PHPUnit
  9.6) to `assertPostConditions()`, covering SDK-transport attempts too;
  a leaking test now fails the run as documented.
- Shared-source namespace rewrite inserts provenance after `<?php` so
  generated files stay valid PHP with `declare(strict_types=1)`.
- Builder never follows file symlinks (out-of-tree files can no longer be
  packaged) and excludes more development files (package.json, Makefile…).
- Secret scanner: fixture-marker allowlist narrowed to unambiguous markers
  (generic prose words no longer suppress a scan), `toml` scanned, and the
  default scan covers the whole repository root (mise.toml, .github/,
  composer.json…) instead of enumerated subdirectories.
- Harness semantics aligned with WordPress core: filter/action stack
  membership (`doing_action`, `current_filter` inside `apply_filters`),
  `has_filter()` priority return, negative transient TTL, `add_query_arg`
  replace semantics, case-insensitive response headers, Settings API state
  reset between tests.
- Artifact inspector matches forbidden entries on whole path segments
  (no more false rejects like `assets/latest/…`).
- `composer conventions` now enforces the autoloader PSR-4 prefix and
  single-registration rule via the shared helpers (no duplicated logic).
- Docs corrected: offline entry point is `composer check`, enforced header
  list and shared-copy-freshness timing in record 0005, Composer bootstrap
  step for clean checkouts in the testing guide; `.env`/`*.pem`/`*.key`
  gitignored; fixture plugins now covered by PHPCS/compat scans.
