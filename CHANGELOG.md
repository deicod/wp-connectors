# Changelog

All notable changes to this repository are documented here, per plugin and per
tooling area. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning per plugin follows its own header `Version` (no monorepo version).

## [Unreleased]

### Fixed (zai / M2 — GLM5 code review)

- The zai (OpenAI-compatible) surface reached inbound-hardening parity
  with the zai_anthropic surface: four guards that had landed on the
  Anthropic surface only now protect this surface's ordinary tool loop
  too. Tool-call arguments are re-derived from a RAW (non-associative)
  decode through the shared `Support\ToolArgsObjectNess` walk (moved
  out of the Anthropic model, GLM1 #2), so a nested `{}` or
  numeric-keyed object no longer re-encodes as a JSON list on the
  conversation's very next replay — silently altered arguments the
  model never produced. The shared `ToolArgsReplayGuard` (GLM4 #2)
  rejects arguments decoding to INF (`1e999`) or a lossy
  beyond-`PHP_INT_MAX` float before acceptance, where the SDK parent's
  outbound plain `json_encode()` previously shipped
  `"arguments": false` on every later request. And both transports
  validate their usage member through the now-neutral shared validator
  (`Support\UsageValidator`, renamed from `AnthropicUsageValidator`
  and parameterized by member set): a string/INF member was a raw
  strict-types `TypeError` from `TokenUsage` (generic 500), and a
  streamed INF member collapsed the consolidated body to `''`, masking
  the real cause as "payload was malformed". (Verifier round: the
  replay guard also runs on OUTBOUND caller-supplied arguments — the
  SDK parent's mapper plain-`json_encode()`s them, silently shipping
  `"arguments": false` on a generation that then succeeded — and the
  streamed usage validation now decides through a real object-ness
  oracle the aggregator hands along, so a streamed final `"usage": []`
  no longer slips past where the identical non-streamed member rejects.)

- A Messages refusal turn that cannot be replayed is no longer a
  generation (GLM5 #4, completing the GLM3 #1 parse/replay contract):
  the documented tolerance for `content: []` under `stop_reason:
  "refusal"` manufactured a ZERO-part assistant Message that this
  adapter's own outbound mapper rejects pre-transport — appending it to
  the history poisoned every later request of the conversation, and
  `toText()` threw the SDK's untyped `RuntimeException`. Zero parts now
  reject through the typed channel regardless of the stop reason; the
  protocol's ordinary shape (refusal WITH content) still parses as a
  successful `contentFilter` result and replays cleanly.

- Two Anthropic-surface parser edges closed: the content-block `type`
  member is `is_string`-guarded before the `switch` (loose `==`
  semantics accepted `true`/`0` as `'text'` on the declared PHP 7.4
  target, bypassing the typed unsupported-type rejection — the GLM2 #5
  coercion class), and `tool_use` id uniqueness spans the WHOLE outbound
  history instead of resetting at every role change (the same id reused
  across two properly answered assistant turns shipped ambiguous
  identities).

- `data: [DONE]` is terminal on the OpenAI streaming surface: the
  sentinel set a flag nothing consulted, so frames an intermediary
  APPENDED after it still merged into the aggregated payload — content
  concatenated, finish reason and usage overwritten — silently mutating
  a completed generation (parity with the Anthropic twin's
  message_stop latch). On the Anthropic aggregator, the `event:` field
  parser trims field-value whitespace (spaces AND tabs, both ends) and
  treats an EMPTY name as absent, so a spec-legal `event:` value or a
  tab-separated `event:\t<name>` no longer trips the event/payload
  agreement rule and invalidates an otherwise valid whole stream.

- Plan/region settings invalidation compares hook payloads type-aware:
  the previous `(string)` casts raised an Array-to-string warning per
  side and equated two DIFFERENT corrupt array values
  (`'Array' === 'Array'`), silently skipping the availability-state and
  discovery-cache invalidation a plan change must perform. Scalars keep
  their comparison; non-scalars compare by strict identity (distinct
  arrays are CHANGED — the safe direction).

- A DATABASE-only API key validates for real: the SDK registry wires
  provider credentials from env/constant only, so whenever no auth was
  wired the availability probe threw the binding `RuntimeException`,
  counted inconclusive, and `isConfigured()` reported connected
  (configured-pending) forever without a single validation request —
  defeating the class's own "nonempty-but-invalid key must report
  not-connected" contract. The unwired probe now authenticates with the
  EFFECTIVE key through a per-surface `fallback_authentication()` hook
  (protocol-wrapped on zai_anthropic). The credential binding is also
  stable across the save→store transition: the `'runtime'` save-time
  candidate label normalizes to the `'database'` identity at binding
  construction, so a fresh invalid verdict persisted while the candidate
  was wired still refuses the identical credential once it is read back
  from the stored option.

- Uninstall deletes the DERIVABLE probe-miss transients directly
  through the transients API (the current env/constant/stored credential
  under every source label, across every plan × region endpoint of both
  surfaces): the wpdb option-name LIKE sweep sees nothing when a
  persistent object cache (Redis/Memcached) backs transients, so the
  binding-hashed markers survived uninstall with no path that deleted
  them. The sweep itself is null-guarded (real `get_col()` returns null
  on a database error — a foreach over null warned and silently skipped
  the cleanup while uninstall reported success), and the debug flag
  gained the `add_option_{option}` fresh-install companion so the first
  persisted save of a disabled flag still clears the log. `ZAI_VERSION`
  is defined behind a `defined()` guard: a foreign plugin defining the
  same generic constant first no longer triggers a per-request notice.

- Cleanup, same round: the raw-`json_encode()` encodability oracle is
  single-sourced on `Support\JsonEncodeGuard` (seven inlined call sites
  with drifted messages unified); the credential-refusal gate WRAPPERS
  are absorbed into the availability layer's
  `refuse_generation()`/`refuse_discovery()` (four hand-synced copies,
  each surface now contributing only its wiring); the Anthropic SSE
  aggregator runs every frame — including post-`message_stop` trailing
  frames — through ONE decode/agreement pipeline with a single
  post-termination policy handler (verifier round: trailing frames skip
  the declared-object-shape gate, so an `event: error` frame with a
  malformed payload still sets the error flag exactly as the GLM4 #6
  policy documents); and two provably dead branches were removed with
  their docblocks corrected (`advance_answer_window()`'s non-user
  expiry and the empty-array `elseif` in `parse_content_block()`).

### Fixed (zai / M2 — GLM4 code review)

- The R18/R19/R20 unencodable-value guards now use the RAW
  `json_encode()` oracle (the GLM3 #4 primitive) instead of
  `wp_json_encode()`: core's sanity fallback lossily rescues invalid
  UTF-8 and never returns `false` for a string in production, so the
  guards were dead code on real sites — only the deliberately stricter
  test stub made the committed rejection tests pass. An invalid-UTF-8
  tool result was silently re-encoded and shipped (the model was told
  altered tool output), an unencodable declared tool schema or
  outputSchema threw the transport's raw `JsonException` as the generic
  500 `zai_error`, and the outbound `tool_use` input had no
  encodability check at all (only shape checks) — all three oracles are
  raw now and the outbound tool arguments gain the same typed
  pre-transport rejection.

- Tool arguments decoded from responses are round-trip checked before
  acceptance: `1e999` decodes to INF and an integer beyond
  `PHP_INT_MAX` to a lossy float, and both flowed into `FunctionCall`
  args whose replay threw at the transport's whole-request encode —
  poisoning every later request of that conversation (the GLM3 #1
  "a turn that cannot be replayed cannot be a generation" contract,
  applied to argument values). The new shared
  `Support\ToolArgsReplayGuard` (raw-encode oracle, decode/re-encode
  stability, and an integral-float-beyond-int-range walk) is enforced
  at all three inbound acceptance points — the non-streaming parser
  and both SSE acceptance points — so the two transports of one
  generation can never diverge on what replays. (Verifier round: the
  one benign encoding instability — negative zero, whose shortest
  encoding `-0` decodes to the int `0` — is compared semantically and
  accepted, so a valid `{"delta":-0.0}` argument still parses.)

- The zai (OpenAI) surface's event-stream sniff reached parity with the
  Anthropic twin through one shared `Support\EventStreamSniff`: the old
  inline copy recognized only a leading `data:` line, so a gateway that
  mangles or omits the `text/event-stream` Content-Type and prepends a
  UTF-8 BOM, a `: keepalive` comment, or an `event:`/`id:`/`retry:`
  field misrouted the stream to the JSON parser and every such streamed
  generation died as "The chat-completions payload was malformed"
  although the shared frame buffer would have parsed the stream fine.
  The sniff recognizes all five SSE-only body leads now (a JSON body
  never starts with one) and lives once, so the two surfaces cannot
  drift again.

- Explicitly-set falsy values of the wire-forwarded unsupported options
  (`presencePenalty`, `frequencyPenalty`, `logprobs`, `topLogprobs`)
  are rejected typed before transport: the SDK's OpenAI request builder
  forwards every non-null value of these options onto the wire, so
  neutralizing with the only non-nullable neutral value
  (`setLogprobs(false)`, `setTopLogprobs(0)`, `setPresencePenalty(0.0)`)
  passed the `!empty()` guard and then shipped `"logprobs":false` /
  `"top_logprobs":0` / `"presence_penalty":0` anyway — a spec-faithful
  OpenAI-compatible endpoint rejects `top_logprobs` without
  `logprobs=true`, and the caller got the generic upstream error where
  the guard exists to give the precise local one. The never-forwarded
  options (`topK`, `webSearch`, the output-* family) keep the falsy
  tolerance: a set value there is wire-inert.

- A usage total past `PHP_INT_MAX` is a typed `zai_invalid_response`
  instead of an uncaught `TypeError`: every member passed the
  is_int/non-negative validation individually, but int+int overflow
  silently promoted the sum to float and `TokenUsage`'s int-typed
  constructor threw, surfacing as the generic 500 "The z.ai request
  failed." The totals are computed with an explicit per-member bound
  check (so no intermediate ever promotes and the exact-boundary total
  `PHP_INT_MAX` stays representable), and the streamed input-side sum
  is overflow-checked too.

- Trailing SSE frames after `message_stop` are judged by the same
  dispatch rules the main path applies instead of a private
  post-termination whitelist: the old copy accepted a trailing
  `event: error` regardless of a contradicting payload type (the main
  path rejects event/payload contradictions), and any trailing event
  name outside its whitelist — a future benign telemetry/heartbeat
  frame — marked the whole stream corrupt and discarded an otherwise
  fully-received generation. Error events still set the error flag,
  frames declaring a known content-bearing event still invalidate (they
  would mutate a completed generation), and unknown trailing names are
  tolerated noise.

- The x-api-key header strip no longer doubles a GET request's query
  parameters: the request rebuild passed `getUri()` — which already
  folds GET array data into the query string — together with the data
  component, so the rebuilt request appended every parameter a second
  time on its own next `getUri()` call. For that one shape the folded
  URI now travels without the data component (wire-identical for GETs);
  unreachable from the current plugin callers, but it is the
  defense-in-depth reuse case the strip documents.

- The credential-refusal gate lives once on
  `AbstractZaiProviderAvailability` instead of copy-pasted at four
  credential consumers (both model surfaces, both metadata
  directories) with already-divergent wiring:
  `generation_refusal_for_wired_authentication()` is the one predicate
  all four consult and `refusal_message()` the one builder for both
  model surfaces' fixed wording — the next gate-rule change can no
  longer land on some surfaces and leave the others authenticating a
  region-pending or definitively-rejected key against the newly
  selected endpoint. The same single-source treatment went to the
  discovery-cache orchestration (`Metadata\ZaiDiscoveryCache`: cache-id
  build, negative marker, TTLs, plan fallback, and the chat-filtered
  map — previously duplicated line-for-line between the two
  directories) and to the Messages usage validation
  (`Support\AnthropicUsageValidator` — the parser and the aggregator's
  streamed copies had to be fixed in lockstep once already). The
  unknown-model rejection test now pins the SDK-typed
  `InvalidArgumentException` the directory actually throws (the file
  never imported it, so the assertion bound the global class the SDK
  exception merely subclasses — an untyped regression would have kept
  passing).

### Fixed (zai / M2 — GLM3 code review)

- An inbound turn with zero translatable parts no longer parses as a
  successful generation: the parser accepted an empty text block
  (`{"type":"text","text":""}`) and thinking-only turns, but the
  outbound mapper drops exactly those parts and rejects the turn on
  replay — one such response permanently poisoned the conversation
  history, and every later request in that conversation failed
  pre-transport until the turn was removed (the streamed path shared
  the gap). The parser now applies the outbound contract at parse time:
  a turn that cannot be replayed cannot be a generation.

- A response whose content list is empty or consists only of unmapped
  block types no longer parses as a SUCCESS with zero parts: the
  stop-reason/content consistency check cross-checked `tool_use` alone,
  contradicting the guarantee documented at the `parse_content_block()`
  drop site — consumers hit the SDK's untyped `toText()`
  RuntimeException instead of the typed `zai_invalid_response` channel.
  Zero-part responses now reject regardless of the stop reason, with a
  message naming the dropped blocks when that was the case. One pinned
  tolerance is preserved: `content:[]` under `stop_reason` `refusal` is
  the protocol's pre-output-refusal shape and keeps surfacing as a
  successful `contentFilter` result.

- Stop sequences are validated per element before transport: entries
  were only checked for list-ness, so a non-string or empty-string
  entry (`[0]`, `['']`, `['END', null]`) reached the wire verbatim and
  failed upstream with the generic misattributed client-error message
  instead of the typed `zai_invalid_request` rejection every
  neighboring malformed input already receives.

- Invalid-UTF-8 strings in text parts, the system instruction, and stop
  sequences are rejected before transport via the raw `json_encode()`
  oracle — the same primitive the SDK transport's request-body encode
  throws on (`mb_check_encoding()` was considered and rejected:
  WordPress does not require ext-mbstring; core's `wp_json_encode()`
  was rejected too after the verifier round confirmed empirically that
  its sanity fallback lossily rescues invalid UTF-8 and never returns
  `false` for a string, which would have made the guards dead code in
  production). Previously these strings detonated as a raw
  `JsonException` in the transport's whole-request encode and surfaced
  as the generic 500 `zai_error` while the same unencodable value in a
  tool result got the precise typed 400 rejection.

- A stream-shaped body opening with a legal SSE comment line
  (`: keepalive`) or a UTF-8 BOM is no longer misrouted to the JSON
  parser when the gateway omits or mangles the `text/event-stream`
  Content-Type (it failed with `Missing the "content" key` instead of
  returning the aggregated completion): the body sniff recognizes a
  leading comment line — `:` can only be SSE framing, a JSON body never
  starts with one — and skips a leading BOM.

- Trailing frames after `message_stop` are routed by their `event:`
  name: the keepalive whitelist matched only data payloads decoding to
  exactly `{"type":"ping"}`, so a type-less ping (`event: ping` +
  `data: {}`), an OpenAI-style `data: [DONE]` sentinel appended by a
  gateway, or an error event all marked the stream malformed and
  discarded an otherwise fully-received generation. Pings and `[DONE]`
  sentinels are now tolerated, error events set the error flag (the
  typed stream-error message, not the generic corruption one), and
  every other post-termination frame is still corrupt; where both the
  event name and the payload type declare, they must agree.

- A leading UTF-8 BOM prepended by a gateway/CDN is stripped once at
  stream start in the shared SSE frame splitter (both surfaces
  inherit): the BOM previously glued itself to the first frame, which
  then matched no `data:`/`event:` prefix and was silently dropped —
  not even counted malformed — so a single-event stream aggregated to
  null and a multi-event stream lost its first delta. A BOM split
  across chunks is held until its bytes disambiguate; a mid-stream BOM
  stays frame content.

- The settings-save guard requires a string `option_page` before
  calling `sanitize_key()`, and the harness's `sanitize_key` stub now
  mirrors core exactly (scalar-only sanitization, no string coercion,
  the `sanitize_key` filter firing with the raw value). Verified
  against core source: `sanitize_key()` guards with `is_scalar()` and
  returns `''` for non-scalars, so there is no production TypeError —
  the defect was the stub's `(string)` cast silently masking array
  POSTs (`option_page[]=x`), which a `FoundationHarnessTest` fidelity
  pin now prevents from recurring.

- A foreign (non-API-key) request-authentication wiring on the
  zai_anthropic surface no longer surfaces as a 400
  `zai_invalid_request` thrown before option validation: the credential
  gate consulted its protocol-wrapping `getRequestAuthentication()`
  override, whose `wrap()` threw through the gate's
  RuntimeException-only catch. The gate now reads the raw wired
  instance (the OpenAI twin's instanceof early-return pattern), and
  `wrap()` refuses foreign wiring with the binding-family
  `RuntimeException` — so wherever a wiring failure eventually surfaces
  (model request-build, directory discovery, availability probe) it
  maps to 500 `zai_error`, never the caller-input 400 channel.

- OpenAI-surface model discovery parses and caches with the endpoint
  captured at request time, matching the zai_anthropic twin: the parse
  re-resolved the current settings, so a plan save landing during the
  HTTP round-trip filtered the old endpoint's response with the new
  plan's catalog and cached the wrong list under the old endpoint's key
  for the 12-hour discovery TTL.

### Fixed (zai / M2 — GLM2 code review)

- A conversation history ending on an assistant turn with unanswered
  `tool_use` blocks is now rejected before transport with the adapter's
  typed tool-linkage error. The end-of-history completeness check
  required the last turn to be a `user` turn, so the trailing unanswered
  tool turn shipped to the wire and failed as an upstream 400 surfaced
  through the generic client-error channel. A trailing assistant TEXT
  turn (the prefill shape) stays legitimate.

- Scalar tool-call arguments are rejected before transport: the outbound
  `tool_use` input validation caught only non-empty sequential arrays, so
  a scalar argument (a string like `'Oslo'`, an int, a bool, NAN/INF
  floats) reached the wire as a non-object `input` — an upstream 400
  with the generic surface, or a raw `JsonException` from the transport's
  whole-request encode for the unencodable floats — despite the adjacent
  comment claiming scalars were rejected. The empty string joins null
  and the empty array in the `{}` no-argument normalization; `stdClass`
  arguments (the inbound parser's nested object-ness preservation) pass
  untouched.

- The zai_anthropic credential gate no longer reads the wired
  authentication before `validate_request()` runs: an unbound model
  (directly constructed, without the registry binding) carrying invalid
  options threw the SDK's binding `RuntimeException` instead of the
  typed option rejection — the exact divergence the zai (OpenAI)
  surface's gate already guards against. The gate is extracted to
  `refuse_refused_credentials()` mirroring the twin, and skips unbound
  models so the typed rejection wins on both surfaces.

- NAN temperature/top_p values are rejected by the sampling-parameter
  range guard: NAN compares false against both bounds of the
  closed-interval check, so the unencodable float reached the transport
  and threw a raw `JsonException` instead of the typed rejection the
  guard exists to produce. `is_nan()` is checked explicitly alongside
  the bounds.

- A non-string `stop_reason` is rejected by an `is_string` shape guard
  like every sibling envelope member (type, role, id): the bare
  `(string)` cast on a list-shaped value emitted an Array-to-string
  warning before the typed rejection and, on warning-strict installs,
  aborted the parse with an `ErrorException`-family throwable that
  bypassed the `zai_invalid_response` channel.

- The live probe (`bin/zai-live-probe.php`) clears the negative-cache
  markers alongside the positive caches before its acceptance steps: a
  re-run within the 60-second marker TTL of one transient failure served
  the cached inconclusive verdicts (`DISCOVERY FALLBACK`,
  `INCONCLUSIVE`) with zero live requests instead of exercising the live
  network path. New
  `AbstractZaiProviderAvailability::clear_probe_miss_marker()` derives
  and deletes the exact binding-scoped marker the next consult would
  read (the transient-name construction is shared with the writer).

- Uninstall enumerates and deletes the availability probe-miss
  transients, whose names embed an md5 of the credential+endpoint
  binding and are therefore unknowable at uninstall time: the rows are
  matched by option-name prefix via one prepared LIKE query per state
  option and removed through `delete_transient()` (which also removes
  the timeout companion row), per site on multisite. The test harness
  gains a minimal `wpdb` stub so the sweep is exercised by the existing
  single-site and multisite uninstall tests.

- One unauthorized settings save records exactly one
  `zai_connector_unauthorized` settings error: both provider settings
  classes hook their guard on the shared option group, so the strip-and-
  notify path ran once per provider and appended byte-identical errors.
  The strip stays per-guard (idempotent); the emission consults the
  core getter `get_settings_errors()` scoped to the group's setting slug
  and stays silent when the notice already exists. (The verifier round
  caught the first cut consulting `settings_errors()` — a display
  function that echoes and returns void in real WordPress — and a
  harness wpdb LIKE emulation that stripped `esc_like()`'s underscore
  escapes; both are fixed with regression pins.)

### Changed (zai / M2 — GLM2 code review)

- The five request-usage rejections the two surfaces advertise
  identically (candidateCount, text-only output modalities, the MIME
  whitelist, text-only input, custom options) and the output MIME list
  are shared once in `Support/AdvertisedUsageGuard`, consumed by both
  model classes' `validate_request()` — they were verbatim twins under
  the `AdvertisedOptionGuard` call introduced to stop exactly that
  duplication pattern. Messages are byte-identical; surface-specific
  checks stay in the owning models.

- The streamed Messages parse passes the aggregator's consolidated
  payload through decoded instead of round-tripping it through the wire
  format (one `wp_json_encode` into a synthetic Response plus two whole-
  payload re-decodes per streamed generation are gone). The non-streamed
  parser keeps both decodes exactly as before; the streamed path relies
  on the aggregator's already-unambiguous shapes (tool input stays the
  raw-decoded object, usage is object-keyed, content is a constructed
  list), with object-ness guarantees unchanged.

### Fixed (zai / M2 — GLM1 code review)

- Failed model discovery is negatively cached for 60 seconds per
  endpoint: every metadata lookup (and every model instantiation) on a
  persistently failing route — the China-region 404 shape — re-issued a
  blocking doomed remote GET. The short miss marker keeps failure
  non-fatal and retryable (after the TTL the endpoint is probed again)
  and never touches the positive cache; the settings invalidation and
  uninstall clear it with the positive cache.

- Inconclusive AVAILABILITY probes carry the same 60-second binding-scoped
  miss marker (verifier completion of the same finding): the per-request
  `isConfigured()` consult no longer pays a doomed blocking GET on a
  persistently inconclusive route. The marker stores no verdict — a cached
  inconclusive returns exactly what a live one returns — and the
  region-switch distrust flow stays EXEMPT so the definitive validation
  happens as soon as the endpoint can answer.

- A keyless `isConfigured()` no longer calls `delete_option()` on every
  invocation: the availability state row is deleted only when it actually
  exists, removing a needless database DELETE per request.

### Fixed (zai / M2 — Codex PR review, round 14)

- A streamed envelope that explicitly declares a nested `message.type`
  other than `message` (e.g. an error object wearing an assistant role)
  now invalidates the stream: `aggregated()` hardcodes the envelope
  type, so the contradictory shape succeeded where the non-streaming
  path rejects it. An explicit `message` type and an absent type member
  keep the documented tolerance.

- A stop reason that contradicts the parsed content is now rejected:
  `stop_reason: tool_use` without any `tool_use` block returned a
  `toolCalls()` signal with nothing to execute, and tool blocks paired
  with an ordinary completion reason (`end_turn`, `stop_sequence`,
  `pause_turn`, `refusal`) executed nothing while signaling completion.
  The check runs in the shared conversion (non-streaming and
  consolidated streams) after the typed truncation exceptions, so
  `max_tokens`/`model_context_window_exceeded` keep their precedence.

- The forward-compatible unknown-delta seed is intact after round 13's
  start-member validation: an unknown future delta type for an index
  with NO preceding content_block_start seeded a bare type-only text
  block, which the new guard marks malformed — breaking the intended
  tolerance. The synthesized block now carries an explicit empty text
  value, so such streams still complete with empty text. Known delta
  types on an unseen index remain rejected.

- Discovery data on the zai_anthropic surface must be a JSON list: an
  object-shaped catalog (`{"data":{"only":{"id":"glm-5.3"}}}`) passed
  the associative `is_array()` check and iterated its VALUES as
  entries, so a malformed `/v1/models` response was treated as
  successful live discovery and cached for 12 hours. The raw
  object-ness oracle now rejects an object-shaped `data` member, and
  discovery falls back to the static catalog exactly like every other
  malformed response. List-shaped catalogs (including the empty list's
  existing fallback) are unchanged.

- A malformed `usage` member on a successful response is now rejected:
  a list-shaped `[1,2]` passed `is_array()`, and strings (`"5"`),
  bools (`true`), floats, and negatives survived the integer casts as
  plausible prompt/completion/total accounting. A present usage member
  must now be a JSON object whose supplied token counts
  (`input_tokens`, `cache_creation_input_tokens`,
  `cache_read_input_tokens`, `output_tokens`) are non-negative
  integers; an absent member keeps the documented default-zero
  tolerance, and a valid `{}` still means zero tokens.

### Fixed (zai / M2 — Codex PR review, round 15)

- Streamed usage is validated BEFORE the aggregator's casts store it:
  a numeric string, float, boolean, or negative in
  `message_delta.usage.output_tokens` (or the three input-token fields
  of `message_start`) was normalized into an integer before the
  consolidated response reached the strict usage validator, and a
  list-shaped usage silently became zero because the named member was
  absent. A present streamed usage must now be object-shaped with
  non-negative integer members (same rule as the response validator);
  absent members keep the default-zero tolerance.

- The final `message_delta` is no longer accepted while a content
  block is still open: a stream that started a block but lost its
  `content_block_stop` frame completed successfully with a truncated
  block lifecycle. Every started (or seeded) block index must be
  stopped before the final metadata is accepted; streams with no blocks
  and fully-stopped multi-block streams behave exactly as before.

- A paginated `/v1/models` response (`has_more: true`) on the
  zai_anthropic surface is now treated as discovery failure: the parser
  previously cached the single returned page for 12 hours, freezing the
  directory to a partial list that could omit known in-plan models.
  The static plan catalog is served instead and nothing is cached.
  Cursor-following was deliberately not implemented — discovery here is
  opportunistic and the plan catalog is authoritative — and the check
  is strict: a present `has_more` that is not exactly `false`
  (including `"true"`, `1`, `null`) fails the same way. `has_more:
  false` and an absent member discover exactly as before.

- Draining the SSE frame queue is now constant-time per frame: every
  `array_shift()` reindexed the remaining PHP array, so consuming a
  long stream (thousands of token-delta frames) was quadratic in the
  frame count — ~57 s to drain 200k frames versus ~5 ms with the new
  read cursor. Public behavior is unchanged (same frames, same order,
  same null-on-exhaustion); the queue compacts itself once drained, so
  a reused buffer instance accepts new feeds.

### Fixed (zai / M2 — Codex PR review, round 16)

- `message_stop` is now required before an aggregation is returned: a
  transport that ended after a valid `message_delta` left `stop_reason`
  populated, so the truncated stream was returned as a successful
  generation while `is_done()` was false. A missing terminal event is
  the same corruption class as the missing `message_start` — the stream
  is marked malformed and no payload is built. This inverts an earlier
  tolerance that treated `message_stop` as conventional; stream
  fixtures now close their lifecycle explicitly.

- Content-block events now require `message_start` at dispatch time:
  the missing-start guard only fired when the flag was still false at
  the END of the stream, so a late-but-valid `message_start`
  legitimized content blocks that had arrived before it and a complete
  lifecycle then produced a successful response from an invalidly
  ordered stream. The malformed flag is sticky — the late start cannot
  launder the early content. Normal ordering (message_start, blocks,
  metadata, message_stop) is unchanged.

### Fixed (zai / M2 — Codex PR review, round 17)

- `message_delta` — and, by the same rule, `message_stop` — now
  require `message_start` at dispatch time, like content-block events
  since round 16: final metadata or a terminal event that arrived
  before the envelope was laundered by a later valid start into a
  successful (empty) completion carrying the late metadata. The
  malformed flag is sticky, so the late start cannot repair it.

- Streamed block starts must form the contiguous zero-based sequence:
  a truncated stream that lost block 0 but delivered a complete block
  at index 1 passed the non-negative-integer index check, and the
  arrival-order repacking made the gap invisible — the surviving block
  became content position 0 of a successful but truncated completion.
  Any gap or reorder (a started index other than the next expected one)
  now invalidates the stream; synthesized seeds from the unknown-delta
  compatibility path obey the same rule, and in-order multi-block
  streams are unchanged.

### Fixed (zai / M2 — Codex PR review, round 17 review body)

- The live probe reports an INCONCLUSIVE availability verdict instead
  of a vacuous `connected`: after the round-13 state-option clear, a
  probe request that fails outright (transport error, 5xx, 429, 404,
  region distrust) answers `isConfigured()` with the credential-"not
  yet disproven" default without persisting a verdict — the step now
  requires the freshly persisted definitive state and fails otherwise.
- The live probe fails on empty generation output: a successfully
  parsed but blank (or whitespace-only) answer to the sentinel prompt
  was reported without affecting the verdict, so all three acceptance
  steps could end in `PASS` despite producing no answer. Empty output
  now fails the probe like the other acceptance steps.

### Fixed (zai / M2 — Codex PR review, round 18)

- A `FunctionDeclaration` with an empty name is rejected before
  transport: the declaration path had none of the identity validation
  the tool-call and tool-result paths already perform, so a malformed
  configuration reached the endpoint's `tools` array only to fail with
  an upstream 400. Identity errors surface before the schema checks
  (first-bad-wins); valid multi-tool configs are unchanged.

- Splitting fed SSE bodies is linear-time as well: the frame splitter
  copied the entire unconsumed suffix once per delimiter, so feeding a
  complete response with thousands of token-delta frames (one
  `feed($body)` call, as both model parsers do) was quadratic — ~3.1 s
  to split 80k small frames versus ~12 ms with the new offset scan,
  which discards the consumed prefix once after the loop. Draining was
  already constant-time since round 15; chunk-boundary semantics,
  `finish()` flushing, and buffer reuse are byte-identical.

### Fixed (zai / M2 — Codex PR review, round 18, second batch)

- An unencodable tool-result value is rejected before transport:
  `wp_json_encode()` failure on a `FunctionResponse` (NAN, a resource,
  a recursive structure) was string-cast to `''`, so the request
  succeeded structurally while telling the model the tool returned no
  content — corrupting the conversation instead of reporting the
  serialization failure. The typed pre-transport rejection now fires in
  the same channel as the other tool-result validations, and the test
  harness's `wp_json_encode` stub matches core's string-or-false
  semantics (it previously masked failures behind a `"null"` string).

- Duplicate declared tool names are rejected before transport: two
  `FunctionDeclaration` entries under one name were both emitted even
  though a returned `tool_use` identifies the selected declaration only
  by that name — the caller could not determine which declaration the
  model selected and might validate or execute the call against the
  wrong tool. Duplicates now throw the typed pre-transport rejection
  next to the empty-name check; distinct-name configurations are
  unchanged.

### Fixed (zai / M2 — Codex PR review, round 19)

- An unencodable `outputSchema` is rejected before transport: a
  constructible but non-JSON-encodable value (NAN, invalid UTF-8, a
  recursive structure) made the guidance encoder return false, which
  the string cast silently turned into an empty string — the request
  succeeded with guidance ending in `JSON Schema: `, so the model
  produced unconstrained output even though the caller requested a
  schema. The typed pre-transport rejection now fires in the same
  channel as the round-18 tool-result encoding failure.

- A successful response must carry a non-empty string `id`: the
  fallback returned a `GenerativeAiResult` with no message identity
  when `id` was absent, empty, or non-string, and the consolidated-
  stream path shared the gap because the aggregator fabricates an empty
  id when `message_start.message.id` is absent. One rejection where the
  two paths merge covers both.

- The public direct-generation path refuses stale environment keys: a
  credential from `ZAI_ANTHROPIC_API_KEY` cannot be deleted by a region
  switch, so the settings layer marks that exact key region-pending and
  the availability layer persists definitive invalid verdicts — yet
  generation authenticated unconditionally, sending the old region's
  credential to the newly selected regional endpoint even while the
  connector reported disconnected. A new availability-layer gate
  (reusing its own state readers, no probe request) is consulted with
  the model's exact credential before authenticating, and generation is
  refused while the key is region-pending or carries a fresh matching
  invalid verdict.

### Fixed (zai / M2 — Codex PR review, round 20)

- Model enumeration no longer discloses a stale environment key to a
  newly selected region: an env/constant credential that survived an
  `intl`/`cn` switch is region-pending (or carries a definitive invalid
  verdict), and while the generation path already refused it, the
  `/v1/models` discovery request still authenticated with it. The
  directory now consults the same availability gate — skipped
  discovery degrades to the static plan catalog (never fatal, never
  cached), and no authenticated request leaves until the credential is
  revalidated.

- A declared tool's parameter schema is JSON-validated before
  transport: an unencodable value (NAN, invalid UTF-8, a resource, a
  recursive structure) previously reached the request untouched and
  failed in the transport's whole-request serialization instead of
  producing the adapter's typed pre-transport configuration error —
  the same `wp_json_encode()` oracle the output-schema and tool-result
  rejections already use. Empty schemas keep their empty-object
  normalization.

### Fixed (zai / M2 — Codex PR review, round 13)

- Content-block events (`content_block_start`/`delta`/`stop`) arriving
  AFTER the final `message_delta` now invalidate the stream: the
  duplicate-delta guard only forbade another `message_delta`, so a
  damaged stream could keep mutating the accumulators after the final
  message metadata and complete successfully with text or tool
  arguments received post-`message_delta`. Streams whose content events
  all precede the `message_delta` aggregate exactly as before.
- A second `message_start` — even a valid one — now invalidates the
  stream: the R12 payload validation ran on every event, so a duplicate
  passed it and overwrote the first message id and input-token usage
  while the generated content still succeeded. The protocol sends
  exactly one; the already-started state is now guarded like duplicate
  block starts and duplicate message deltas.
- A `text`/`thinking` start block that omits its content member or
  supplies a non-string now invalidates the stream instead of silently
  fabricating an empty initial value; later valid deltas could then
  produce a successful response from a known-malformed start payload.
  Valid starts (including an initial empty string) keep their values.
- A response — non-streaming or consolidated stream — containing two
  `tool_use` blocks with the same NON-EMPTY id is now rejected as
  malformed: both parsed independently into two ambiguous `FunctionCall`
  parts, which a consumer cannot correlate results to and which hits
  this adapter's own outbound duplicate-id rejection when the assistant
  turn is replayed after tools may already have executed. Distinct ids
  and single tool calls are unchanged.
- The openai-surface `/v1/models` parse requires its discovery data to
  be a JSON list too (the R14 verifier's twin of the fix above): an
  object-shaped catalog passed the same associative `is_array()` check
  on the zai surface's directory and was likewise cached as successful
  live discovery. Same oracle, same fallback.

- The live probe now clears the selected provider's validation-state
  option before its availability step: within the five-minute TTL the
  persisted verdict previously satisfied the "live" acceptance check
  without any network request, so a revoked credential or unavailable
  route could still report `connected` (mirroring the discovery-transient
  clear the probe already performs).

### Fixed (zai / M2 — Codex PR review, round 12)

- A `message_start` whose `message` member is missing, null, a list, or
  a scalar now invalidates the stream instead of satisfying the
  completion prerequisite: later valid content and `message_delta`
  previously fabricated an assistant envelope with a blank id and zero
  input usage — a bypass of the missing-message_start guard. The
  prerequisite is set only after the payload carries a valid message
  object; a well-formed message_start aggregates exactly as before.
- A user wire turn answering tool calls must carry its `tool_result`
  blocks BEFORE any text block: text preceding a result (across the
  coalescing merge or within a single SDK message's block order) passed
  local validation — the linkage checks consume IDs regardless of
  position — and failed upstream with a 400. Misordered turns are now a
  typed pre-transport rejection; results-then-text order and text-only
  turns are unaffected.

### Fixed (zai / M2 — Codex PR review, round 11)

- Tool-answer completeness is now evaluated at the coalesced WIRE-turn
  boundary, not per SDK message: a multi-tool answer split across
  ADJACENT user `Message` objects was rejected after the first message
  even though the adapter's same-role coalescing emits one valid wire
  user turn containing every result. The window now advances only at a
  role change (or end of history) — the partial-answer rejection fires
  exactly there. Consistently, the stale/expiry semantics are also
  wire-level now: an intervening ASSISTANT text message coalesces into
  the tool turn (its following result is wire-adjacent and valid — the
  R9 SDK-level 'intervening turn' rejection is superseded), adjacent
  assistant tool messages form ONE answerable turn (partially answering
  it is the R10 partial rejection, superseding the R9 window-replacement
  probe), and only an intervening USER turn genuinely breaks adjacency.
- The region-change hook compares EFFECTIVE regions before invalidating
  the credential: a corrupt stored value ('bogus', empty, whitespace,
  wrong case) already routes to the default region on every read, so
  saving the displayed default on top of it is not a switch — the
  raw-vs-sanitized comparison previously deleted a valid key whose
  effective endpoint never changed. Both hook values now run through
  the same normalization get_region() uses; only genuinely different
  effective regions invalidate. Genuine switches and same-value saves
  behave exactly as before.

### Fixed (zai / M2 — Codex PR review, round 10)

- A partially answered tool-call turn is now rejected before transport:
  when the user turn following an assistant tool turn answers only some
  of its tool calls (or none), the unanswered remainder was silently
  discarded and the history sent — Anthropic requires that one user turn
  to carry results for ALL of the preceding turn's tool calls, so the
  request failed upstream with a 400. Fully-answered multi-tool turns
  and end-of-history unanswered turns (the normal tool loop) are
  unaffected.
- Two FunctionCall parts sharing the same ID within ONE assistant turn
  are rejected before transport: the map assignment silently overwrote
  the first entry, so a single later result satisfied linkage while the
  wire carried two ambiguous tool_use identities. ID reuse across
  DIFFERENT turns stays legal.
- A text or thinking delta for a stream index whose
  `content_block_start` was never received now invalidates the stream
  (the same typed error the tool-delta path already raised): the
  synthesized accumulator returned a successful truncated completion
  with the content before the missing start silently absent. Unknown
  (future) delta types keep the seeded tolerance — they carry no
  content this aggregator maps.
- The duplicate tool-call-id check is scoped to the coalesced WIRE turn,
  not the SDK message: two adjacent assistant Messages sharing a tool id
  coalesce into one wire turn and previously slipped the per-message
  check while emitting the ambiguous duplicate-identity shape
  (verifier probe on round 10).

### Fixed (zai / M2 — Codex PR review, round 9)

- Tool results must now arrive in the user turn IMMEDIATELY following
  the assistant tool-use turn: an intervening turn (assistant or user)
  expires the outstanding tool-use IDs, so a stale result later in the
  history is rejected before transport. Multi-tool assistant turns must
  be answered entirely by that one next user turn — a result arriving a
  turn later is stale even if its ID was never answered.
- A duplicate `message_delta` event now invalidates the stream: the
  protocol sends exactly one, and the later one silently overwrote the
  first stop reason and usage (end_turn then tool_use made the result
  report `toolCalls()` with no corresponding function call). Even a
  byte-identical repeat is rejected — the payloads may differ, so a
  repeat is never an idempotent no-op (supersedes the R8 verifier's
  tolerance judgment).
- Empty tool-call identities are rejected before transport in BOTH
  directions: a FunctionCall or FunctionResponse with `''` as its ID
  (or `''` name) passed the null-only guards and emitted a tool_use /
  tool_result block with an empty identity — Messages requires
  non-empty ids and names — and an inbound `tool_use` block with an
  empty id/name now fails the typed parse error instead of producing a
  FunctionCall with an empty identity.

### Fixed (zai / M2 — Codex PR review, round 8)

- A delta arriving after an index's `content_block_stop` still appended
  to the closed block, and a `content_block_stop` for a never-started
  (or twice-stopped) index passed silently: stopped indexes are now
  tracked, and both shapes invalidate the stream with the typed parse
  error.
- `message_stop` is now terminal: any non-keepalive data event after it
  (including a second `message_stop`) invalidates the stream —
  post-termination frames could previously modify the returned
  text/tool args/stop reason/usage while the response succeeded.
  Ping/comment keepalive frames stay tolerated.
- A stream omitting `message_start` now invalidates instead of
  fabricating an assistant envelope with blank id/usage (which had also
  bypassed the R6 streamed-role validation): message_start receipt is
  required before aggregation produces a payload.
- A `content_block_start` whose `content_block.type` is missing or
  non-string now invalidates the stream: it silently became a `text`
  block that a following text_delta completed on fabricated state.
- Outbound tool results are validated against the preceding tool calls:
  every `tool_result` must answer a preceding `tool_use`'s ID exactly
  once — stale, mistyped, out-of-order, or duplicate results are
  rejected before transport instead of failing upstream with a 400.
- The live probe's discovery step now detects fallback results: the
  directory silently returns the static catalog when the live /v1/models
  request fails or malforms, so the probe used to report a model count
  (and PASS) with no live discovery. Successful discovery is cached in a
  per-endpoint transient while fallbacks never are — the probe clears
  that transient first and reports `discovery source` as `live /v1/models`
  or `DISCOVERY FALLBACK`, failing (nonzero exit) on the latter.

### Fixed (zai / M2 — Codex PR review, round 7)

- A `tool_use` response block OMITTING its `input` member or setting it
  to `null` is now rejected as a typed parse error instead of being
  normalized into a no-argument FunctionCall: the Messages protocol
  requires the member, and an empty call is represented by `{}`
  alone (supersedes the R2-era tolerance; `{}` stays legitimate). The
  streamed `content_block_start` path got the same strictness (verifier
  sweep sibling): an absent or explicitly-null start-block input also
  invalidates the stream instead of fabricating a no-argument call.
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
- The live probe validates `--plan` and `--region` exactly like
  `--surface`: a typo previously printed (e.g. `china`) while the
  settings getters silently fell back to defaults, making the evidence
  misleading and potentially exercising the wrong billing surface.
  Invalid values exit 2 before any key lookup or network call.
- The live probe now fails when the availability step fails, instead of
  continuing with `$exit = 0`: a later generation success could print a
  final PASS although the first documented acceptance step failed (the
  two routes can apply different access policy).

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
  fallback is not cached beyond a 60-second short-TTL negative cache
  (still retryable), so a later valid key can still discover.
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
