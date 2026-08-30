# Implementation Plan — WordPress 7.0 AI Connectors

**Based on:** [`docs/specs/SPEC.md`](specs/SPEC.md), Draft v1 (2026-08-30)  
**Planning status:** Ready for implementation  
**Target:** WordPress 7.0+ and WordPress 6.9 with the standalone PHP AI Client plugin
(advertised via `Requires at least: 6.9`), PHP 7.4–8.4

## How to use this plan

- Every milestone and task deliberately starts with an unchecked checkbox. **The implementing
  LLM must change a task checkbox to `[x]` only after its implementation, task-level tests,
  documentation, and review are complete.** It must change a milestone checkbox to `[x]` only
  after every task and exit criterion in that milestone is complete.
- Work in milestone order unless a task explicitly says it can run in parallel. **Milestone 6
  (Anthropic OAuth) is bonus/optional:** a v1 release MAY proceed to Milestone 7 (repo
  hardening) with M6 unchecked; only mandatory milestones block release. Do not mark a
  checkbox from design work alone when the task calls for executable code or validation.
- Each task is intended to fit comfortably in an average 200k-token LLM context. A task lists
  the files/components, implementation steps, tests, and completion evidence it should leave
  behind. If repository discovery shows that a task would exceed that context, split it into
  additional unchecked subtasks in this document before implementing it.
- At the start of every task, re-read the relevant SPEC sections and inspect current code rather
  than assuming an earlier task followed the suggested file names exactly. At the end, run the
  narrow checks listed for the task, inspect `git diff`, update documentation where behavior
  differs, and then check the task checkbox.
- Never put real credentials, OAuth codes, tokens, full request bodies, authorization headers,
  or unmasked WordPress salts in fixtures, snapshots, logs, commits, or issue text. Sanitized
  synthetic fixtures (fake tokens, seeded test values, redacted request snapshots constructed
  for tests) are explicitly allowed where tasks require them.

## Cross-cutting implementation decisions

These decisions remove ambiguity without changing the product scope in the SPEC:

1. `connectors/zai` is one plugin from M1 onward. M1 registers and implements only provider ID
   `zai`; M2 adds `zai_anthropic` to that same plugin. This preserves milestone-level acceptance
   testing while reaching the required final two-provider plugin.
2. The shared OAuth source is developed in `shared/`, but every OAuth plugin artifact receives
   its own namespaced copy at build time. No installed plugin may include files from another
   plugin or from the repository-level `shared/` directory.
3. OAuth providers use `RequestAuthenticationMethod` metadata that auto-discovers as `none`,
   because core has no OAuth field. Their own admin pages are the sole token-management UI.
4. Model lists are versioned static catalogs by default. Runtime discovery is an optional,
   validated enhancement with a cached result and a static fallback; an unavailable discovery
   endpoint must never make the provider unusable.
5. Network-dependent provider checks are integration tests requiring explicitly supplied test
   credentials. The default automated suite uses mocked `wp_remote_*` responses and must never
   contact a vendor.
6. Options are per-site, including on multisite. Uninstall behavior must remove connector-owned
   settings and OAuth material only when the plugin's documented data-retention policy permits;
   deactivation alone must retain configuration.

## Definition of done for every implementation task

A task is complete only when its code is PHP 7.4-compatible, user-facing text is translatable,
admin mutations have capability and nonce checks, error paths return typed `WP_Error` values,
relevant unit/integration tests pass, no secret can appear in logs, and affected documentation
matches actual behavior. Apply WordPress coding standards unless an SDK signature requires a
documented exception.

---

## Milestone 0 — Repository foundation and executable test harness

- [ ] **Milestone 0 complete.** Check this milestone only after Tasks 0.1–0.6 are checked and a
  clean checkout can install dependencies, run all offline checks, and build a minimal plugin
  artifact without loading code from outside that artifact.

### Tasks

- [ ] **Task 0.1 — Record architecture and compatibility contracts.** Inspect the exact SDK and
  WordPress 7.0 APIs used by the SPEC; add concise architecture records for provider
  registration, model construction, metadata version guards, option ownership, and standalone
  plugin packaging. Pin development dependencies or test fixtures to known compatible versions.
  Explicitly document how WP 6.9 plus the standalone SDK is detected. Check this task only after
  all referenced class names and signatures have been verified against the pinned source and the
  records identify any divergence from the SPEC.

- [ ] **Task 0.2 — Establish development tooling.** Add Composer development dependencies and
  scripts for PHPCS with WordPress rules, PHPUnit, PHP syntax checks, and any static analysis that
  supports PHP 7.4. Configure generated/vendor paths, test fixtures, and consistent namespaces.
  Avoid a runtime Composer dependency in plugin zips. Check this task only after each script runs
  locally (or a precisely documented environment limitation is demonstrated).

- [ ] **Task 0.3 — Build the WordPress test bootstrap.** Create a repeatable test environment that
  loads connector code against WordPress/SDK stubs or the WordPress test suite, resets options and
  scheduled events between tests, and intercepts HTTP through WordPress hooks. Add helpers for
  deterministic clocks, nonces, users/capabilities, and encrypted-option assertions. Check this
  task only after one passing smoke test proves plugin registration timing and one test proves
  outbound HTTP is blocked unless mocked.

- [ ] **Task 0.4 — Define repository conventions.** Add editor/git attributes, ignore rules,
  namespace-to-path conventions, plugin version constants, text domains, changelog policy, and a
  policy for generated shared-library copies. Decide whether generated copies are committed and
  ensure the build is reproducible either way. Check this task only after the conventions are
  documented and enforced by at least one automated check.

- [ ] **Task 0.5 — Implement the standalone artifact builder.** Add a build command that assembles
  one zip per plugin, excludes tests/development files, includes required licenses/assets, embeds
  namespaced shared OAuth code where applicable, and emits checksums. Add an artifact inspection
  that rejects repository-relative includes and missing plugin headers. Check this task only after
  a minimal fixture plugin can be zipped, extracted elsewhere, and syntax-checked independently.

- [ ] **Task 0.6 — Create secure test-fixture rules.** Provide fake token/key factories, HTTP
  response builders, and automated secret-pattern scanning. Document opt-in environment variable
  names for live tests without supplying values. Check this task only after a seeded fake token
  test passes and the scanner intentionally rejects a known-secret fixture.

### Exit criteria

- Tooling has one documented entry point for a full offline validation run.
- Tests cannot accidentally make real provider requests.
- Plugin artifacts are demonstrably self-contained and contain no development credentials.

---

## Milestone 1 — z.ai OpenAI-compatible provider (`zai`)

- [ ] **Milestone 1 complete.** Check this milestone only after Tasks 1.1–1.9 are checked, all M1
  SPEC acceptance criteria pass, and the unresolved coding-plan `/models` behavior (O1 for the
  OpenAI surface) is recorded from a credentialed probe or explicitly remains behind the tested
  static fallback.

### Tasks

- [ ] **Task 1.1 — Scaffold the z.ai standalone plugin.** Create `connectors/zai` with a valid
  plugin header (`Requires at least: 6.9` — matching the official provider plugins — so
  WordPress does not block activation on 6.9+standalone-SDK sites; the runtime
  `class_exists(AiClient::class)` guard plus admin dependency notice decides actual SDK
  availability), GPL license metadata, guarded bootstrap, classmap/autoloader, admin dependency
  notice, and `init` priority 5 registration. Register only the `zai` provider in this milestone,
  idempotently, and safely no-op when `AiClient` is unavailable. Check this task only after
  activation tests cover WP 7.0, the supported standalone-SDK case (6.9 header accepted), a
  missing SDK, and duplicate `init` execution.

- [ ] **Task 1.2 — Implement plan/region configuration.** Add settings for
  `zai_connector_zai_plan` (`coding` default, `general`) and
  `zai_connector_zai_region` (`intl` default, `cn`) using the Settings API. Sanitize to the known
  enum, fall back safely on corrupt values, require `manage_options`, use nonces, and explain the
  billing/account distinction. A region change (`intl` ↔ `cn`) MUST NOT silently reuse the
  previous region's credential: clear/invalidate the stored key (or gate requests until a new
  key is supplied) because the regions use separate accounts and keys (SPEC §3.3); test the
  switch for the z.ai provider. Check this task only after tests cover defaults, all four valid
  combinations, invalid input, unauthorized submission, region-switch key invalidation, and
  escaped/translatable rendering.

- [ ] **Task 1.3 — Centralize endpoint resolution.** Implement an immutable endpoint resolver for
  all four OpenAI plan/region combinations while keeping provider `baseUrl()` fixed to the
  international-general canonical URL required by the SDK. Ensure model and directory requests
  resolve options at request time rather than construction time. Check this task only after a
  table-driven test verifies exact URLs and proves an option change retargets a subsequent
  request without rebuilding the registry.

- [ ] **Task 1.4 — Implement provider metadata and authentication.** Add provider ID, display name,
  description, API-key authentication metadata, logo, and availability mapping. Availability MUST
  be more than key presence: an authenticated probe (or equivalent validated state) is required
  so a nonempty-but-invalid key (HTTP 401 on the probe) reports unavailable/not-connected, per
  the M1 exit criterion. Guard description
  and logo features according to detected SDK support rather than merely assuming methods exist;
  rely on core's `connectors_ai_zai_api_key` store and inject `Authorization: Bearer <key>`.
  Check this task only after metadata tests cover the minimum and newer SDK shapes, header tests
  prove correct injection, and failures/logs prove the key is always redacted.

- [ ] **Task 1.5 — Implement the model metadata directory.** Start with a maintained static GLM
  text-model catalog, newest-first. The static fallback MUST be plan-specific: separate coding
  and general catalogs (coding subscriptions expose a restricted model set; a shared fallback
  can advertise general-only models while the coding endpoint is selected, per SPEC §3.3). Add an
  optional `/models` discovery path only if responses can be normalized, cached, and merged with
  capability metadata without losing the static fallback. The discovery cache MUST be scoped by
  endpoint identity: include provider, plan, and region in the cache key (or invalidate it on
  plan/region settings changes) so a warm cache can never serve models from a previous endpoint
  after an administrator switches settings. Do not claim image support without model-specific
  evidence. Check this task only after tests cover sorting, cache expiry, cache scoping across a
  plan/region switch (verify the other endpoint's catalog is re-fetched, not served stale, before
  expiry), fallback contents for BOTH plan selections, malformed/401/404 discovery responses,
  fallback behavior, and capability/option
  declarations.

- [ ] **Task 1.6 — Implement chat-completions request mapping.** Build `/chat/completions` requests
  for text generation and chat history, mapping system instruction, temperature, max tokens,
  top-p, stop sequences, JSON MIME type/schema, function declarations, and supported text/image
  inputs exactly as the SDK exposes them. Reject unsupported option/model combinations before
  transport. Check this task only after request snapshots cover minimal, conversation, structured
  output, tool, and multimodal cases without including credentials.

- [ ] **Task 1.7 — Implement response, streaming, and error mapping.** Normalize non-streaming and
  SSE responses into SDK result objects, including tool calls, finish reasons, usage when present,
  split SSE frames, `[DONE]`, and malformed events. Map 401, 403, 429, transport errors, and 5xx
  to stable typed `WP_Error` codes and safe messages; do not add custom retries in v1. Check this
  task only after fixture tests cover success, partial streams, upstream error bodies containing
  secrets, and every required status mapping.

- [ ] **Task 1.8 — Add safe observability and admin links.** Add an option-gated debug logger for
  method, redacted URL, status, and duration only. Add plugin-row settings access and a settings
  section that makes plan/region choices discoverable without competing with core's key field.
  Check this task only after tests demonstrate logging is off by default and cannot expose query
  secrets, headers, keys, prompt bodies, schemas, OAuth-like values, or response bodies.

- [ ] **Task 1.9 — Validate and document M1.** Add user instructions, supported options/models,
  endpoint selection behavior, API-key storage disclosure, multisite behavior, and troubleshooting.
  Run the offline matrix across supported PHP targets and an opt-in live coding+international
  smoke test when a key is available. Record the O1 probe result and preserve fallback if it is
  inconclusive. Check this task only after documentation and test evidence match shipped behavior.

### Exit criteria

- Core auto-discovers a `zai` card with an API-key field.
- Each plan/region selection routes to the exact SPEC endpoint at request time.
- `wp_ai_client_prompt(...)->using_provider('zai')->generate_text()` works with mocked transport
  and, when credentials are supplied, coding+international live transport.
- An invalid key produces a redacted, actionable error and unavailable/not-connected state.

---

## Milestone 2 — z.ai Anthropic-compatible provider (`zai_anthropic`)

- [ ] **Milestone 2 complete.** Check this milestone only after Tasks 2.1–2.7 are checked, the one
  z.ai plugin registers both providers without collisions, and the M2 SPEC acceptance criteria
  pass across every plan/region endpoint through mocked tests.

### Tasks

- [ ] **Task 2.1 — Add the second provider and independent settings.** Extend the existing z.ai
  plugin to register `zai_anthropic` idempotently and add its own
  `zai_connector_zai_anthropic_plan` and `_region` options with the same defaults and controls.
  Keep API-key storage/auth metadata distinct as core derives it from provider ID. A region
  change MUST invalidate this provider's key exactly as in Task 1.2 (`intl` ↔ `cn` are separate
  accounts/keys): clear/invalidate `connectors_ai_zai_anthropic_api_key` or gate requests
  until a new key is supplied; test the region switch for this provider. The second
  provider's availability MUST be validated independently (authenticated probe or equivalent
  per-provider validated state): Task 1.4's validated state for `zai` cannot establish
  `zai_anthropic`'s status, so add an invalid-key → not-connected test for this provider too.
  Check this task
  only after tests prove the providers coexist, settings do not bleed between them, the
  invalid-key status test above, and failure of
  one registration cannot silently replace the other.

- [ ] **Task 2.2 — Extend endpoint resolution for Anthropic URLs.** Map coding/general × intl/cn to
  the four `/anthropic` bases and append `/v1/messages` or `/v1/models` exactly once. Read settings
  at request time while retaining the required canonical `baseUrl()`. Check this task only after
  table-driven tests cover all final request URLs and option changes between requests.

- [ ] **Task 2.3 — Implement Bearer authentication and protocol headers.** Inject
  `Authorization: Bearer <key>`, `anthropic-version: 2023-06-01`, and safe content headers; do not
  depend on unverified `x-api-key` support. Check this task only after exact-header tests prove no
  duplicate/conflicting auth header and logging tests prove full redaction.

- [ ] **Task 2.4 — Implement Anthropic metadata/catalog.** Create the custom metadata directory,
  static GLM fallback, capability declarations, sorting, optional cached `/v1/models` discovery,
  and graceful failure policy. The discovery cache MUST be scoped by endpoint identity
  (provider/plan/region in the cache key, or invalidation on settings change) exactly as in
  Task 1.5, including a pre-expiry plan/region switch test asserting the new endpoint's catalog
  is re-fetched rather than served stale. The static fallback MUST be plan-partitioned: separate coding and
  general catalogs (coding subscriptions expose a restricted model set; a single shared fallback
  would advertise general-only models while the coding endpoint is selected, per SPEC §3.3).
  Share neutral GLM catalog data where useful without coupling the two protocol adapters.
  Check this task only after static/discovered/fallback tests pass for BOTH plan selections,
  and the Anthropic half of O1 is documented accurately.

- [ ] **Task 2.5 — Implement Messages request mapping.** Translate system instruction, alternating
  chat content blocks, text and supported images, tools/tool results, JSON output guidance,
  `outputSchema`, max tokens, temperature, top-p, and stop sequences to the Anthropic-compatible
  Messages format. Handle protocol constraints such as required max tokens and role ordering.
  Check this task only after focused fixtures cover every advertised option and unsupported input
  fails before HTTP.

- [ ] **Task 2.6 — Implement Messages response/stream mapping.** Normalize message content,
  tool-use blocks, stop reasons, usage, and Anthropic SSE event sequences into SDK results. Reuse
  only protocol-neutral error/redaction helpers from M1. Check this task only after success,
  interleaved content/tool deltas, malformed stream, 401/403/429/5xx, and transport tests pass.

- [ ] **Task 2.7 — Validate and document M2.** Update the plugin/readme documentation for two
  cards, two independent endpoint selectors and key fields, known model-list behavior, and examples
  for system instructions, tools, and structured output. Run the full z.ai regression suite and
  optional live tests without committing output containing credentials. Check this task only after
  the documentation, package contents, and M2 acceptance evidence agree.

### Exit criteria

- One standalone plugin exposes both `zai` and `zai_anthropic` cards.
- Messages requests use Bearer authentication and the required version header for all endpoints.
- Tools and `outputSchema` round-trip through representative mocked Claude-Code-style workloads.

---

## Milestone 3 — Shared OAuth security/runtime foundation

- [ ] **Milestone 3 complete.** Check this milestone only after Tasks 3.1–3.8 are checked and the
  shared source can be embedded into two fixture plugins under distinct namespaces, with encrypted
  storage, concurrency-safe refresh, admin protection, and zero runtime cross-plugin dependency.

### Tasks

- [ ] **Task 3.1 — Define provider-neutral OAuth contracts.** In `shared/`, define PHP 7.4-safe
  interfaces/value objects for token sets, clocks, HTTP transport, OAuth grants, token storage,
  refresh policy, availability, and typed errors. Keep provider endpoints/client IDs out of generic
  classes. Check this task only after contract tests cover token validation/serialization and an
  architecture review confirms no WordPress global is hidden inside pure value objects.

- [ ] **Task 3.2 — Implement encrypted token storage.** Encrypt one versioned envelope per provider
  with `sodium_crypto_secretbox`, random nonce, authenticated ciphertext, and a key derived from
  WordPress auth salts. If salts are unusable, the fallback key MUST come from an external
  source outside the WordPress database (e.g. a `WP_CONNECTORS_*_KEY` constant or environment
  variable); if no external source exists, fail closed (provider unavailable with a clear admin
  notice) rather than persisting a decrypt-capable key alongside the ciphertext. Define
  salt-change/external-key rotation, corruption, migration, and
  deletion behavior; never return partial plaintext. Check this task only after round-trip,
  tamper, wrong-key, legacy-version, rotation, missing-sodium-compat, autoload, fail-closed,
  and deletion tests.

- [ ] **Task 3.3 — Implement refresh coordination.** Add lazy expiry-minus-skew refresh, a short
  per-provider lock to prevent refresh-token races, atomic replacement, scheduled single-event
  backup, and cleanup on revoke/uninstall. The lock MUST be fenced against lease expiry:
  atomic acquisition plus renewal/a lease longer than the transport timeout, or a fencing
  generation checked before every refresh commit — a refresh returning after its lease expired
  may never overwrite a newer (rotated) token set; test the lock-expiry overlap. Revocation MUST be serialized against in-flight
  refreshes: revoke participates in the refresh lock or advances a persisted grant
  generation/tombstone that refresh checks before committing, so a refresh that returns after
  revoke can never write a fresh token set and silently reconnect the provider (add a
  deterministic revoke-versus-refresh race test). Token-set replacement MUST use merge semantics:
  preserve the stored refresh token unless the response contains a nonempty replacement
  `refresh_token` (providers may omit it on refresh; discarding a still-valid token forces
  unnecessary reconnection). Treat terminal authorization failures as dead while
  retaining safe diagnostic state; treat transient failures as retryable without erasing the last
  token prematurely. Terminal classification MUST be restricted to definitive authorization
  errors (`invalid_grant`, revoked grant); HTTP 429 and other transient/transport failures MUST
  remain retryable and MUST NOT mark the grant dead or force a reconnect. Repeated transient
  failures MUST enter a persisted, bounded cooldown (honoring `Retry-After` where provided, else
  exponential backoff with a cap) so sequential callers do not hammer the token endpoint during
  an outage. Check this task only after deterministic concurrency, clock-boundary, scheduling,
  terminal (including a 429-during-
  refresh test asserting the grant survives), and transient failure tests pass.

- [ ] **Task 3.4 — Implement authenticated request retry.** Supply a provider-neutral wrapper that
  obtains/refreshed access tokens, makes an inference request, and on the first 401 performs at
  most one refresh and one replay. A 401 on a still-unexpired token MUST bypass the
  expiry-minus-skew freshness check and force a refresh (providers can revoke access tokens
  before recorded expiry; replaying the same credential would loop the failure). Never replay
  non-repeatable bodies or loop. Check this task only
  after tests cover fresh, proactively refreshed, single-401 recovery with a still-unexpired
  token (forced refresh), second-401 failure,
  refresh failure, and concurrent refresh paths.

- [ ] **Task 3.5 — Implement availability semantics.** Compute availability from grant presence,
  decryptability, expiry/refresh outcome, and an optional cheap provider probe. Distinguish
  disconnected, reconnect-required, temporarily unavailable, and connected internally while
  mapping safely to the SDK interface. Check this task only after each state and its admin/core
  representation has a deterministic test.

- [ ] **Task 3.6 — Build reusable protected admin components.** Implement status presentation,
  account-label redaction, Connect/Re-connect/Revoke actions, plugin-row link helpers, a
  reusable Connectors-card-adjacent action (so users arriving through Settings → Connectors have
  a direct route to the provider's Connect page, per SPEC §2.2), nonce and `manage_options`
  enforcement, and admin notices including the unofficial OAuth/ToS disclosure. The shared
  Revoke action MUST also cancel any pending authorization flow: cancel scheduled polling
  (single events) and delete all provider- and user-scoped pending device/PKCE state, so a
  flow that was mid-air during revocation cannot later install a fresh grant and undo the
  revoke. Authorization exchanges are covered by the same serialization as refreshes
  (Task 3.3): the exchange MUST check the persisted grant generation/tombstone (or hold the
  revoke lock) before persisting, so an in-flight exchange returning after revoke discards
  its tokens; add a deterministic revoke-versus-exchange race test. Authorization exchanges
  and refreshes MUST share the same lock/fencing generation: a reconnect exchange that
  installs a new grant advances the generation, so an in-flight refresh for the OLD grant
  returning afterwards can never overwrite the new account's tokens; add a
  reconnect-versus-refresh race test. Ensure GET renders
  but never mutates state. Check this task only after authorized, unauthorized, CSRF, escaping,
  revoke (including a pending-flow-cannot-reconnect-after-revoke test), token-non-disclosure,
  and card-action-visibility tests (one per OAuth provider) pass.

- [ ] **Task 3.7 — Implement safe OAuth HTTP/error utilities.** Wrap `wp_remote_*` with explicit
  timeouts, accepted content types, bounded response sizes, TLS defaults, structured redacted
  debug events, `Retry-After` parsing, and provider-error normalization. Redirects MUST be
  disabled (or every redirect target revalidated against the approved provider origin without
  forwarding headers/body) for EVERY credential-bearing request — OAuth token/refresh/exchange/
  device requests AND authenticated inference/model/discovery requests (shared Responses
  adapter, both z.ai adapters) — so a cross-origin 307/308 can never replay the Authorization
  header or prompt body at an attacker-controlled location; assert
  rejection of a cross-origin redirect on both an OAuth request and an inference request. Debug event emission
  MUST be gated behind an explicit shared debug option that is disabled by default (SPEC §6.2);
  no OAuth endpoint, status, timing, or failure metadata may be recorded without administrator
  opt-in. Check this task only after tests cover malformed JSON, HTML errors, redirects, timeout,
  429 date/seconds headers, 5xx, hostile strings, secret-bearing URLs/bodies, and a
  disabled-by-default assertion proving no events are emitted without the option.

- [ ] **Task 3.8 — Build namespaced shared copies.** Extend the artifact builder to rewrite or
  generate each plugin's private namespace, include license/source provenance, and verify copies
  match the shared source. Add a collision test activating all fixture plugins together. Check
  this task only after isolated artifacts and simultaneous activation pass with `shared/` absent.

### Exit criteria

- Tokens are never stored plaintext and corrupted/rotated ciphertext fails closed.
- Refreshes are bounded, scheduled, concurrency-safe, and never enter a 401 loop.
- Every admin mutation requires both capability and a valid nonce.

---

## Milestone 4 — OpenAI Codex OAuth connector (`codex`)

- [ ] **Milestone 4 complete.** Check this milestone only after Tasks 4.1–4.9 are checked and the
  SPEC's M3 acceptance criteria pass, including a complete mocked device flow, encrypted refresh,
  core availability, and Responses-based text generation.

### Tasks

- [ ] **Task 4.1 — Scaffold the OpenAI OAuth plugin.** Create `connectors/openai-oauth` with the
  `Deicod\WpConnectors\OpenAiOauth` namespace, provider ID `codex`, dependency guard, standalone
  shared-runtime copy, settings submenu, plugin-row link, registration of the shared
  card-adjacent Connect action (Task 3.6 helper — assert it renders for this plugin), metadata,
  static logo, and idempotent
  priority-5 registration. Check this task only after activation/dependency/collision tests and
  artifact isolation pass.

- [ ] **Task 4.2 — Implement device authorization start.** POST JSON with the specified client ID
  to the user-code endpoint, validate `user_code`, `device_auth_id`, and interval, cap stored flow
  lifetime at 15 minutes, and display the exact verification URL/code safely. Concurrent or
  double-submitted starts MUST be deterministic: scope transient flow state per admin user (or
  explicitly cancel-and-replace the previous flow), so a second start can never orphan or corrupt
  the first flow's `device_auth_id`/`user_code`; add a duplicate-start test. Persisted pending
  flow state (`device_auth_id`, `user_code`) MUST use the encrypted store (Task 3.2) — these
  values let a database reader hijack the flow after the administrator authorizes; test that no
  plaintext device-flow credentials appear in options/transients. Apply bounded 429
  retry using `Retry-After` or 2/4/8-second backoff, maximum four attempts, with every single
  retry delay clamped to at most 60 seconds even when the endpoint returns an oversized
  `Retry-After` value. Retries MUST NOT sleep inside the initiating admin request: schedule
  delayed AJAX/cron retries or return a retriable response to the UI — three synchronous
  60-second delays would exceed common proxy timeouts and hold a PHP worker without ever
  rendering the code. If the start's retry budget is exhausted, surface a retriable UI response
  (no flow exists yet to preserve — there is no `device_auth_id`/`user_code`/expiry before a
  successful start); state preservation after 429 exhaustion applies to polling
  (Task 4.3), not to start. Check this task only after success, malformed response, timeout, retry,
  cap (including an oversized-`Retry-After` clamp test), async-retry scheduling,
  start-exhaustion retriable-response, duplicate-start, CSRF, and capability
  tests pass.

- [ ] **Task 4.3 — Implement device polling and exchange.** Poll no faster than `max(interval, 3)`
  using bounded admin/AJAX requests or scheduled work rather than a long PHP request; treat only
  documented pending 403/404 responses as pending. Polling and code exchange MUST be mutually
  exclusive per flow (per-flow lease or idempotent completion guard): when an AJAX poll and a
  scheduled poll overlap after authorization succeeds, only one may exchange the one-time
  authorization code — the loser observes completed state, never a terminal failure or
  overwrite; add a deterministic overlapping-polls test (same invariant for the xAI flow in
  Task 5.4). Cancellation is fenced like revocation (Task 3.6): cancel acquires the lease or
  advances a generation/tombstone checked immediately before the exchange persists tokens,
  so a cancelled flow can never install a grant after the UI reports cancellation; add a
  cancel-versus-exchange race test. If polling's 429 retry budget is exhausted, the pending flow MUST be preserved
  until its original 15-minute expiry with the next permitted poll scheduled (mirroring the
  xAI rule in Task 5.4) — exhausted 429 retries are not terminal here. The poll request MUST be asserted exactly:
  JSON POST to `https://auth.openai.com/api/accounts/deviceauth/token` containing both
  `device_auth_id` and `user_code` (per SPEC §4.1) — form-encoding or omitting either field
  must fail the test. A 429 from the device-token endpoint MUST
  follow the same bounded backoff policy as Task 4.2 (`Retry-After`/2-4-8-second, max four
  attempts, per-delay 60-second clamp). The authorization-code exchange MUST be asserted
  exactly: form-encoded POST to `https://auth.openai.com/oauth/token` containing
  `grant_type=authorization_code`, `code`, `code_verifier`, the exact `redirect_uri`
  (`https://auth.openai.com/deviceauth/callback`), and the exact client ID (per SPEC §4.1) —
  missing or mis-encoded fields must fail the test. A non-consuming transient response from
  the exchange (HTTP 429) MUST preserve the encrypted code/verifier state for bounded retry
  (claim released, never consumed on 429 — mirroring the Claude rule in Task 6.3); test
  exchange-retry-after-429. Check this task only after
  pending→success, expiry, cancellation (including the cancel-versus-exchange race),
  throttling (including the bounded 429 backoff and exchange-preservation tests),
  malformed success, and terminal error state-machine tests pass.

- [ ] **Task 4.4 — Implement Codex token lifecycle.** Validate access/refresh/id token fields,
  derive expiry from trusted response data (using JWT claims only as a non-authoritative aid),
  store the encrypted token set, refresh at a 120-second skew, and derive a safely escaped account
  label when possible. The refresh MUST be asserted as an exact request: form-encoded POST to
  `https://auth.openai.com/oauth/token` containing `grant_type=refresh_token`, the stored refresh
  token, and the exact client ID (per SPEC §4.1). Check this task only after storage, refresh
  (including the exact-request assertion), label, invalid JWT, missing refresh token, revoke, and
  reconnect-required tests pass.

- [ ] **Task 4.5 — Implement the parameterized Responses adapter.** In shared source, create the
  protocol adapter used later by xAI: configurable base URL, endpoint path, headers, model catalog,
  refresh skew, and error hooks. Map SDK text/chat/system instructions, structured output, tools,
  reasoning controls only when supported, and streaming Responses events. Check this task only
  after provider-neutral request/response/stream contract tests pass with two fake configurations.

- [ ] **Task 4.6 — Configure Codex inference and metadata.** Target
  `https://chatgpt.com/backend-api/codex/responses` (service base plus the Responses endpoint
  path), use OAuth Bearer tokens, and inject the `ChatGPT-Account-Id` header: extract the
  account ID from the OAuth token set — the JWT carries it in the namespaced
  `https://api.openai.com/auth` claim object (`chatgpt_account_id` inside it), NOT as a
  top-level claim; the exact-header test fixture must use that namespaced structure — without
  the header, multi-workspace users route to the wrong workspace or fail entitlement checks;
  cover it in the exact-header test. Expose a conservative static
  GPT Codex catalog, and map only verified capabilities. Ensure URLs are constructed once and do
  not accidentally target `api.openai.com`. Check this task only after exact URL/header/model and
  unsupported-option tests pass.

- [ ] **Task 4.7 — Resolve optional model discovery (O2).** With explicit credentials, probe the
  proposed Codex models endpoint without logging tokens; record status/shape/date. Implement
  cached discovery only if stable enough to normalize, otherwise retain the static catalog and
  document the result. If implemented, the cache MUST be scoped to the connected account:
  key by validated account ID/grant generation or invalidate on new-grant install (reconnect
  to another ChatGPT account/workspace must not serve the previous account's catalog); test
  an account switch before expiry. Check this task only after fallback behavior is tested and O2 is marked
  resolved or explicitly inconclusive with no acceptance dependency.

- [ ] **Task 4.8 — Complete availability, errors, and disclosure.** Connect provider availability
  to token state; map 401 to reconnect guidance, device 429 to throttling guidance, entitlement
  403 separately from generic failures, and 5xx to upstream errors. An inference-time HTTP 429
  from the Responses endpoint MUST map to the typed rate-limit error (SPEC §6.2) — covered by
  its own test, separate from device-authorization throttling. Put the unofficial-client-ID
  and account-risk notice beside Connect. Check this task only after core/admin status tests,
  disclosure rendering, and redaction tests pass.

- [ ] **Task 4.9 — Validate and document Codex.** Document device steps, popup/manual navigation,
  token storage and salt rotation, refresh/revoke behavior, static models, limitations, and ToS
  risk. Run full offline and optional credentialed login/inference/refresh tests; do not automate
  destructive account actions. Check this task only after the plugin zip is standalone and all
  M3 acceptance evidence is captured safely.

### Exit criteria

- An administrator can complete, cancel, expire, reconnect, and revoke the device flow.
- Tokens are encrypted, refresh 120 seconds early, and survive ordinary page requests safely.
- Responses text/chat calls work and one post-401 refresh/retry is enforced.

---

## Milestone 5 — xAI Grok OAuth connector (`grok`)

- [ ] **Milestone 5 complete.** Check this milestone only after Tasks 5.1–5.8 are checked and the
  SPEC's M4 criteria pass, including discovery fallback, RFC 8628 behavior, shared Responses
  adapter reuse, and actionable entitlement errors.

### Tasks

- [ ] **Task 5.1 — Scaffold the xAI OAuth plugin.** Create `connectors/xai-oauth` with namespace
  `Deicod\WpConnectors\XaiOauth`, provider ID `grok`, priority-5 registration, own admin page
  including registration of the shared card-adjacent Connect action (assert it renders),
  metadata/logo, dependency notice, and private shared-runtime copy. Check this task only after it
  activates alongside every existing plugin without symbol, option, hook, or provider collision.

- [ ] **Task 5.2 — Implement OIDC discovery with fallback.** Fetch
  `https://auth.x.ai/.well-known/openid-configuration`, validate HTTPS issuer/endpoints and cache
  the result with bounded lifetime. The discovered issuer MUST match `https://auth.x.ai` exactly,
  and every consumed endpoint (authorization/token/device) MUST be constrained to explicitly
  approved origins — a poisoned or misconfigured response must trigger fallback, never use. On
  network, schema, or security validation failure use the hardcoded device/token endpoints from
  the SPEC. Check this task only after valid, poisoned (asserting fallback rather than use),
  redirect, stale-cache, offline, and fallback tests pass.

- [ ] **Task 5.3 — Implement xAI device authorization.** Form-post the exact client ID and scopes,
  validate the device response, display `verification_uri` and `user_code`, and persist only the
  minimum flow state until `expires_in` — in the encrypted store (Task 3.2), never a plaintext
  transient: the `device_code` lets a database/cache reader poll the public-client token
  endpoint first and steal the grant; include a plaintext-absence assertion like Task 4.2.
  Concurrent starts MUST be deterministic exactly as in Task 4.2: per-user isolation of pending
  state or explicit atomic cancel-and-replace — a second start may never silently orphan a
  first user's still-valid code; test overlapping starts.
  Check this task only after request,
  output escaping, expiration, duplicate-start, at-rest encryption, CSRF, and capability tests pass.

- [ ] **Task 5.4 — Implement RFC 8628 polling.** Poll at the server interval, handle
  `authorization_pending`, denial, expiry, and success without tying up a PHP worker. A
  `slow_down` response MUST increase the polling interval by at least five seconds for this and
  all subsequent requests (RFC 8628 §3.5); assert the updated schedule in the clock-driven tests.
  An HTTP 429 from the token endpoint is transient: retain the device-flow state until its
  original expiry, honor `Retry-After` or a bounded backoff, and assert the resulting next-poll
  schedule in the clock-driven tests. The token request MUST be asserted exactly: form-encoded POST containing all three of
  the device-code grant type (`urn:ietf:params:oauth:grant-type:device_code`), `device_code`, and
  `client_id` (per SPEC §4.2) — omitting any field must fail the test. Exchange with the exact
  device-code grant type and reject ambiguous HTTP/body states. Check this task only after a
  clock-driven state-machine suite covers every terminal and nonterminal response.

- [ ] **Task 5.5 — Implement xAI token lifecycle.** Encrypt token sets, and refresh with an
  exactly-asserted request: form-encoded POST to the discovered (issuer-pinned) token endpoint
  containing `grant_type=refresh_token`, the stored `refresh_token`, and the exact client ID
  (per SPEC §4.2). Use a 3600-second skew without refreshing repeatedly, schedule backup refresh,
  show a redacted account label if safely derivable, and revoke locally. Check this task only after
  expiry-boundary, concurrency, refresh rotation, invalid-grant, revoke, and recovery tests pass.

- [ ] **Task 5.6 — Configure xAI Responses inference.** Reuse—not fork—the shared adapter against
  `https://api.x.ai/v1/responses` (Responses endpoint path included, per the Task 4.6 rule),
  set the conservative static catalog with default `grok-4.6`, and configure
  only verified reasoning/tools/streaming/caching features. Check this task only after exact URL,
  auth, request, response, streaming, default-model, and regression tests for Codex pass.

- [ ] **Task 5.7 — Implement entitlement-aware errors and availability.** Map inference HTTP 403
  to a stable typed tier/allowlist error with a non-accusatory hint; keep 401, 429, and 5xx distinct.
  Do not mark a valid grant disconnected merely because a transient probe fails. Check this task
  only after mocked core/admin status and every error class are verified without leaking bodies.

- [ ] **Task 5.8 — Validate and document Grok.** Document eligible subscriptions as uncertain,
  discovery fallback, requested scopes, six-hour typical access lifetime versus one-hour skew,
  revoke semantics, static model behavior, and unofficial OAuth risk. Run the combined adapter
  regression suite and optional credentialed smoke test. Check this task only after M4 acceptance
  evidence and an independently installable zip are complete.

### Exit criteria

- The discovery document is used only after validation and hardcoded fallback always remains.
- Grok and Codex share one source adapter while retaining isolated plugin artifacts.
- xAI 403 responses provide the tier hint and never masquerade as an authentication failure.

---

## Milestone 6 — Anthropic Claude Pro/Max OAuth connector (`claude_pro`, bonus)

- [ ] **Milestone 6 complete.** Check this milestone only after Tasks 6.1–6.8 are checked and the
  SPEC's M5 criteria pass, including PKCE/state validation, paste-code exchange, required headers,
  refresh/revoke behavior, and the token-endpoint User-Agent rule.

### Tasks

- [ ] **Task 6.1 — Scaffold the Anthropic OAuth plugin.** Create `connectors/anthropic-oauth` with
  namespace `Deicod\WpConnectors\AnthropicOauth`, provider ID `claude_pro`, guarded registration,
  own admin page including registration of the shared card-adjacent Connect action (assert it
  renders), metadata/logo, dependency notice, and private shared-runtime copy. Check this
  task only after all plugins activate together and the artifact remains standalone.

- [ ] **Task 6.2 — Implement PKCE authorization start.** Generate a high-entropy verifier and
  state with CSPRNG, derive S256 base64url challenge without padding, store transient flow state
  with expiry, and construct the exact authorize URL/client ID/redirect URI/scopes from the SPEC.
  Concurrent starts MUST be deterministic exactly as in Tasks 4.2/5.3: per-admin isolation of
  the pending PKCE verifier/state or explicit atomic cancel-and-replace — a second start may
  never silently invalidate a first administrator's pending `code#state` submission; test two
  simultaneous administrators.
  Check this task only after RFC PKCE vectors, entropy/encoding, URL, expiry, replacement,
  concurrent-start isolation, nonce,
  and capability tests pass.

- [ ] **Task 6.3 — Implement paste-code parsing and state validation.** Accept the displayed
  `code#state` form with conservative whitespace handling, split unambiguously, compare state in
  constant time, enforce one-time/expiry semantics, and never log either value. Submission
  MUST use a per-flow claim/lease: concurrent or replayed submissions are blocked, but a
  definitively non-consuming transient response (HTTP 429) releases the claim and PRESERVES
  the encrypted verifier/state + authorization code for retry — never consume on 429; on 429
  with a nonzero `Retry-After`, persist a per-flow not-before time (cooldown) and reject or
  schedule earlier attempts so the cooldown is honored before the claim can be reacquired;
  test retry-after-429 honoring the advertised delay, and concurrent-submission rejection.
  Check this task
  only after valid, malformed, missing delimiter, wrong state, replay, expired, oversized, CSRF,
  429-retryable, and unauthorized cases pass.

- [ ] **Task 6.4 — Implement token exchange and refresh.** JSON-post the exact authorization grant
  fields and later refresh grant fields to the console endpoint. Set a plain connector User-Agent
  such as `wp-connectors/<version>` and add a regression assertion that it never begins with
  `claude-code/`. Encrypt returned tokens and apply the shared refresh coordinator. Check this
  task only after exact request, UA, success, malformed, 429, invalid-grant, rotation, and revoke
  tests pass.

- [ ] **Task 6.5 — Implement Anthropic OAuth inference.** Adapt the tested Messages mapping from
  z.ai without coupling plugin runtimes; target `https://api.anthropic.com/v1/messages`, use
  `Authorization: Bearer <oauth-access-token>` (SPEC §4.3), include `anthropic-beta:
  oauth-2025-04-20` and the required `anthropic-version: 2023-06-01` header (assert all three
  explicitly). Check this task only after exact headers/URL and full
  request/response/SSE/tool/structured-output regression fixtures pass.

- [ ] **Task 6.6 — Implement model metadata and availability.** Ship a conservative static Claude
  catalog sorted newest Sonnet first, advertise only tested capabilities, and connect encrypted
  grant health to core availability. Keep model identifiers/data maintainable independently of
  the adapter. Check this task only after sorting, default selection, stale model, unavailable,
  refreshable, and reconnect-required tests pass.

- [ ] **Task 6.7 — Complete admin UX, errors, and disclosure.** Present start URL, paste form,
  status, reconnect and revoke actions; map 401/403/429/5xx safely and put the unofficial OAuth
  and account-risk disclosure adjacent to Connect. Check this task only after accessibility,
  escaping, capability, nonce, state, redaction, and error-presentation tests pass.

- [ ] **Task 6.8 — Validate and document Claude Pro/Max.** Document the paste-code flow, scopes,
  storage/rotation, required beta header, token-endpoint UA behavior, models, refresh/revoke, and
  ToS risk. Run all offline protocol/shared-runtime tests and an optional credentialed smoke test.
  Check this task only after the standalone zip and M5 acceptance evidence are complete.

### Exit criteria

- State is one-time, constant-time validated, and bound to an expiring PKCE verifier.
- The exchange/refresh User-Agent regression test prevents the known throttled prefix.
- Messages inference includes the OAuth beta header and one-refresh retry semantics.

---

## Milestone 7 — Repository hardening, release, and distribution decision

- [ ] **Milestone 7 complete.** Check this milestone only after Tasks 7.1–7.9 are checked, all
  required SPEC M6 criteria pass, release artifacts are reproducible and tested, and the selected
  distribution path resolves O5 in both the SPEC and release documentation.

### Tasks

- [ ] **Task 7.1 — Enforce code quality in CI.** Add workflows for Composer validation, PHPCS,
  PHP syntax on 7.4–8.4, PHPUnit, static analysis, build reproducibility, secret scanning, and a
  simultaneous-plugin activation smoke test. Pin third-party actions by immutable commit where
  practical and use least-privilege permissions. Check this task only after a pull request run is
  green or each unavailable runner limitation is documented with an equivalent local result.

- [ ] **Task 7.2 — Add WordPress compatibility integration tests.** Exercise WordPress 7.0 and
  WordPress 6.9 with the standalone SDK — mandatory, not conditional, while the plugins
  advertise `Requires at least: 6.9` (per the plan target). Test single site and multisite,
  network activation with per-site options, supported PHP extremes, cron disabled behavior, and
  missing sodium/SDK degradation. Check this task only after the compatibility table reflects
  actual automated results rather than aspirations.

- [ ] **Task 7.3 — Perform a security review.** Trace every secret from input to deletion, audit
  capability/nonces/CSRF/PKCE, HTTP allowlists and redirects, option autoloading, output escaping,
  log redaction, refresh locks, JWT handling, uninstall, and built artifacts. Add adversarial tests
  for findings. Check this task only after all high/critical findings are fixed and lower findings
  are fixed or explicitly accepted with rationale.

- [ ] **Task 7.4 — Perform protocol and SDK conformance review.** Compare provider classes,
  metadata, advertised options, result types, streaming events, and availability behavior with the
  pinned WordPress SDK/reference plugin. Test older supported SDK feature guards. Check this task
  only after mismatches are corrected or documented and no provider advertises an unsupported
  capability.

- [ ] **Task 7.5 — Finalize user and operator documentation.** Update root and per-plugin readmes
  with installation, screenshots if useful, settings, model limitations, live-test instructions,
  privacy/data flow, encrypted OAuth versus core API-key storage, salt rotation, multisite, cron,
  debugging, support boundaries, and prominent unofficial-flow disclosures. Check this task only
  after every command/link/example is verified and translations remain possible.

- [ ] **Task 7.6 — Define versioning, upgrades, and uninstall.** Add tested schema/version upgrade
  routines, model-catalog update policy, OAuth client-ID patch path, deactivation behavior, and
  explicit opt-in or documented uninstall cleanup for options, cron hooks, locks, fallback keys,
  and ciphertext. Check this task only after upgrade/rollback and uninstall tests show no orphaned
  secrets or cross-plugin deletion.

- [ ] **Task 7.7 — Produce reproducible release artifacts.** Build each plugin independently,
  verify headers/licenses/text domains/classmaps/shared copies, run syntax and malware/secret
  scans on extracted zips, generate checksums/SBOM or dependency inventory, and compare two clean
  builds. Check this task only after artifacts are byte-reproducible or all unavoidable metadata
  differences are normalized and documented.

- [ ] **Task 7.8 — Resolve distribution strategy (O5).** Decide WordPress.org per-plugin slugs
  versus GitHub-only zips after reviewing unofficial OAuth policy/ToS implications. Record chosen
  slugs, ownership, signing/provenance, release approval, rollback, and security-reporting process;
  do not claim WordPress.org availability before approval. Check this task only after O5 and the
  SPEC risk table are updated with an actionable decision.

- [ ] **Task 7.9 — Run release-candidate acceptance.** On a clean WordPress install, install only
  the zips and walk every connector's connect/settings, inference, streaming,
  refresh, failure, reconnect, revoke, deactivation/reactivation, and uninstall paths. Every
  MANDATORY unofficial OAuth connector (Codex, xAI) MUST get a controlled credentialed
  end-to-end smoke test before release — device-start/exchange plus at least one authorized
  inference round-trip; a connector that cannot be live-verified needs an explicit
  release-blocking waiver flagging it as unverified. (Bonus Anthropic: live test when
  credentials exist, else waiver.) Confirm no
  runtime repository dependency and review all visible disclosures. Check this task only after a
  signed-off matrix has no unresolved release-blocking defects.

### Exit criteria

- CI produces independently installable, checked artifacts for all completed connectors.
- Compatibility, security, disclosure, upgrade, and distribution documentation reflects tested
  behavior.
- O1, O2, and O5 are resolved or explicitly documented as inconclusive/non-blocking with safe
  fallback; O3 and O4 remain clearly deferred unless separately scoped.

---

## Post-v1 backlog (not part of milestone completion)

- [ ] **Backlog Task B.1 — Investigate z.ai `x-api-key` support (O3).** Use a safe credentialed
  probe and add it only as an optional compatible mode if it provides concrete value; Bearer must
  remain supported. Check this task only after evidence, tests, and documentation are complete.
- [ ] **Backlog Task B.2 — Add image generation and embeddings (O4).** Write a separate capability
  specification for CogView/embedding models, SDK surfaces, endpoint availability, limits, and
  billing before implementation. Check this task only after that specification and its own testable
  plan are approved and implemented.
- [ ] **Backlog Task B.3 — Track Connectors API OAuth support.** Reassess custom admin pages if core
  gains native OAuth fields; include migration and backward compatibility rather than switching
  automatically. Check this task only after an upstream stable API exists and migration tests pass.

## Final plan/spec consistency review

The following checks were applied while producing this plan:

- The SPEC labels product milestones M1–M6, while this plan adds foundation and shared-runtime
  milestones and therefore numbers release hardening as Milestone 7. The mapping is: plan M1→SPEC
  M1, plan M2→SPEC M2, plan M4→SPEC M3, plan M5→SPEC M4, plan M6→SPEC M5, plan M7→SPEC M6.
- The SPEC simultaneously says the z.ai surfaces ship as one plugin and assigns them sequential
  milestones. The plan resolves this by creating the plugin in M1 and adding its second provider
  in M2; no temporary second plugin is created.
- The SPEC says OAuth token stores use core-bundled `sodium_compat`, but runtime availability can
  vary on supported deployments. The plan requires a tested fail-closed/dependency path rather
  than plaintext fallback.
- The SPEC requires runtime z.ai endpoint selection even though `baseUrl()` is canonical. The plan
  explicitly tests late option reads in both directory and inference requests.
- O1 and O2 cannot be safely answered by unauthenticated probes. The plan makes credentialed probes
  optional and keeps static catalogs as acceptance-safe fallbacks, so implementation never blocks
  or silently relies on an unverified endpoint.
- The SPEC names newer model families/defaults that can age quickly. The plan treats catalogs as
  maintainable versioned data and forbids advertising capabilities based solely on family names.
- The SPEC's “revoke” requirement has no remote revocation endpoint for these flows. In this plan,
  revoke means local encrypted-token deletion and scheduled-event cleanup unless a verified remote
  endpoint is later documented; user-facing copy must not imply remote invalidation.
- OAuth admin polling is described behaviorally in the SPEC. The plan forbids a single long-lived
  PHP request and requires a bounded state machine suitable for WordPress request lifetimes.
- The plan keeps PHP 7.4 syntax compatibility, uses only `wp_remote_*` for provider traffic,
  preserves per-site multisite settings, and maintains standalone plugin artifacts as required.

If implementation discovers a genuine contradiction with the SDK or live provider behavior, the
implementing LLM must update the SPEC and this plan in the same change, add regression evidence,
and leave affected milestone/task checkboxes unchecked until the revised acceptance criteria pass.
