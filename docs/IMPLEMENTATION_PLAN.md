# Implementation Plan — WordPress 7.0 AI Connectors

**Based on:** [`docs/specs/SPEC.md`](specs/SPEC.md), Draft v1 (2026-08-30)  
**Planning status:** Ready for implementation  
**Target:** WordPress 7.0+ and WordPress 6.9 with the standalone PHP AI Client plugin
(advertised via `Requires at least: 6.9`), PHP 8.2–8.4 (floor per the 2026-09-11 user decision)

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

A task is complete only when its code is PHP 8.2-compatible, user-facing text is translatable,
admin mutations have capability and nonce checks, error paths return typed `WP_Error` values,
relevant unit/integration tests pass, no secret can appear in logs, and affected documentation
matches actual behavior. Apply WordPress coding standards unless an SDK signature requires a
documented exception.

---

## Milestone 0 — Repository foundation and executable test harness

- [x] **Milestone 0 complete.** Check this milestone only after Tasks 0.1–0.6 are checked and a
  clean checkout can install dependencies, run all offline checks, and build a minimal plugin
  artifact without loading code from outside that artifact.

### Tasks

- [x] **Task 0.1 — Record architecture and compatibility contracts.** Inspect the exact SDK and
  WordPress 7.0 APIs used by the SPEC; add concise architecture records for provider
  registration, model construction, metadata version guards, option ownership, and standalone
  plugin packaging. Pin development dependencies or test fixtures to known compatible versions.
  Explicitly document how WP 6.9 plus the standalone SDK is detected. Check this task only after
  all referenced class names and signatures have been verified against the pinned source and the
  records identify any divergence from the SPEC.

- [x] **Task 0.2 — Establish development tooling.** Add Composer development dependencies and
  scripts for PHPCS with WordPress rules, PHPUnit, PHP syntax checks, and any static analysis that
  supports PHP 8.2. Configure generated/vendor paths, test fixtures, and consistent namespaces.
  Avoid a runtime Composer dependency in plugin zips. Check this task only after each script runs
  locally (or a precisely documented environment limitation is demonstrated).

- [x] **Task 0.3 — Build the WordPress test bootstrap.** Create a repeatable test environment that
  loads connector code against WordPress/SDK stubs or the WordPress test suite, resets options and
  scheduled events between tests, and intercepts HTTP through WordPress hooks. Add helpers for
  deterministic clocks, nonces, users/capabilities, and encrypted-option assertions. Check this
  task only after one passing smoke test proves plugin registration timing and one test proves
  outbound HTTP is blocked unless mocked.

- [x] **Task 0.4 — Define repository conventions.** Add editor/git attributes, ignore rules,
  namespace-to-path conventions, plugin version constants, text domains, changelog policy, and a
  policy for generated shared-library copies. Decide whether generated copies are committed and
  ensure the build is reproducible either way. Check this task only after the conventions are
  documented and enforced by at least one automated check.

- [x] **Task 0.5 — Implement the standalone artifact builder.** Add a build command that assembles
  one zip per plugin, excludes tests/development files, includes required licenses/assets, embeds
  namespaced shared OAuth code where applicable, and emits checksums. Add an artifact inspection
  that rejects repository-relative includes and missing plugin headers. Check this task only after
  a minimal fixture plugin can be zipped, extracted elsewhere, and syntax-checked independently.

- [x] **Task 0.6 — Create secure test-fixture rules.** Provide fake token/key factories, HTTP
  response builders, and automated secret-pattern scanning. Document opt-in environment variable
  names for live tests without supplying values. Check this task only after a seeded fake token
  test passes and the scanner intentionally rejects a known-secret fixture.

### Exit criteria

- Tooling has one documented entry point for a full offline validation run.
- Tests cannot accidentally make real provider requests.
- Plugin artifacts are demonstrably self-contained and contain no development credentials.

---

## Milestone 1 — z.ai OpenAI-compatible provider (`zai`)

- [x] **Milestone 1 complete.** Check this milestone only after Tasks 1.1–1.9 are checked, all M1
  SPEC acceptance criteria pass, and the unresolved coding-plan `/models` behavior (O1 for the
  OpenAI surface) is recorded from a credentialed probe or explicitly remains behind the tested
  static fallback.

  M1 completion evidence (2026-08-30): O1 resolved by credentialed probe (record 0006: 200
  OpenAI-shape on coding+intl and general+intl; dynamic discovery shipped WITH the tested
  plan-partitioned static fallback, which stays authoritative for the unprobed `cn` region).
  Offline matrix green (`composer check`: 162 tests, 813 assertions, PHPCS/PHPCompatibility/
  PHPStan/conventions/secret-scan clean). Live smoke coding+intl PASS through the plugin
  classes (availability → discovery → generation, glm-5.3, 114 tokens); general+intl inference
  returns the account-plan 429/1113 surfaced as the typed rate-limit error. Artifact
  `dist/connectors-zai-0.1.0.zip` builds reproducibly and passes the standalone inspector.

### Tasks

- [x] **Task 1.1 — Scaffold the z.ai standalone plugin.** Create `connectors/zai` with a valid
  plugin header (`Requires at least: 6.9` — matching the official provider plugins — so
  WordPress does not block activation on 6.9+standalone-SDK sites; the runtime
  `class_exists(AiClient::class)` guard plus admin dependency notice decides actual SDK
  availability), GPL license metadata, guarded bootstrap, classmap/autoloader, admin dependency
  notice, and `init` priority 5 registration. Register only the `zai` provider in this milestone,
  idempotently, and safely no-op when `AiClient` is unavailable. Check this task only after
  activation tests cover WP 7.0, the supported standalone-SDK case (6.9 header accepted), a
  missing SDK, and duplicate `init` execution.

- [x] **Task 1.2 — Implement plan/region configuration.** Add settings for
  `zai_connector_zai_plan` (`coding` default, `general`) and
  `zai_connector_zai_region` (`intl` default, `cn`) using the Settings API. Sanitize to the known
  enum, fall back safely on corrupt values, require `manage_options`, use nonces, and explain the
  billing/account distinction. A region change (`intl` ↔ `cn`) MUST NOT silently reuse the
  previous region's credential: clear/invalidate the stored key (or gate requests until a new
  key is supplied) because the regions use separate accounts and keys (SPEC §3.3); test the
  switch for the z.ai provider. Check this task only after tests cover defaults, all four valid
  combinations, invalid input, unauthorized submission, region-switch key invalidation, and
  escaped/translatable rendering.

- [x] **Task 1.3 — Centralize endpoint resolution.** Implement an immutable endpoint resolver for
  all four OpenAI plan/region combinations while keeping provider `baseUrl()` fixed to the
  international-general canonical URL required by the SDK. Ensure model and directory requests
  resolve options at request time rather than construction time. Check this task only after a
  table-driven test verifies exact URLs and proves an option change retargets a subsequent
  request without rebuilding the registry.

- [x] **Task 1.4 — Implement provider metadata and authentication.** Add provider ID, display name,
  description, API-key authentication metadata, logo, and availability mapping. Availability MUST
  be more than key presence: an authenticated probe (or equivalent validated state) is required
  so a nonempty-but-invalid key (HTTP 401 on the probe) reports unavailable/not-connected, per
  the M1 exit criterion. Persisted validated state MUST be bound to the credential: hash the
  COMPLETE key value together with the source (a prefix hash collides when a replacement key
  shares the provider prefix — common API-key format) or use a generation counter bumped on
  every replacement; invalidate on EVERY key-source change (env/constant/DB replacement) — a newly
  invalid key must not appear connected, a corrected key must not stay unavailable; test
  valid→invalid and invalid→valid replacement (apply the same rule to `zai_anthropic`,
  Task 2.1). Guard description
  and logo features according to detected SDK support rather than merely assuming methods exist;
  rely on core's `connectors_ai_zai_api_key` store and inject `Authorization: Bearer <key>`.
  Check this task only after metadata tests cover the minimum and newer SDK shapes, header tests
  prove correct injection, and failures/logs prove the key is always redacted.

- [x] **Task 1.5 — Implement the model metadata directory.** Start with a maintained static GLM
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

- [x] **Task 1.6 — Implement chat-completions request mapping.** Build `/chat/completions` requests
  for text generation and chat history, mapping system instruction, temperature, max tokens,
  top-p, stop sequences, JSON MIME type/schema, function declarations, and supported text/image
  inputs exactly as the SDK exposes them. Reject unsupported option/model combinations before
  transport. Check this task only after request snapshots cover minimal, conversation, structured
  output, tool, and multimodal cases without including credentials.

- [x] **Task 1.7 — Implement response, streaming, and error mapping.** Normalize non-streaming and
  SSE responses into SDK result objects, including tool calls, finish reasons, usage when present,
  split SSE frames, `[DONE]`, malformed events, and a final frame that ends without a trailing
  blank line. Map 401, 403, 429, transport errors, and 5xx to safe messages from one shared
  catalog, delivered on two surfaces: through the core prompt builder (core converts the
  exception itself with its own fixed codes, passing the message through verbatim — no filter),
  callers get core codes + the redacted zai message + the correct HTTP status — proven against
  the genuine `WP_AI_Client_Prompt_Builder`; the stable typed `zai_*` `WP_Error` codes are the
  plugin's direct model-use API (`generate_text()` / `ErrorMapper`) and cannot be delivered
  through the core builder (core limitation). Do not add custom retries in v1. Check this
  task only after fixture tests cover success, partial streams, upstream error bodies containing
  secrets, every required status mapping, and the real core-builder dispatch path.

- [x] **Task 1.8 — Add safe observability and admin links.** Add an option-gated debug logger for
  method, redacted URL, status, and duration only. Add plugin-row settings access and a settings
  section that makes plan/region choices discoverable without competing with core's key field.
  Check this task only after tests demonstrate logging is off by default and cannot expose query
  secrets, headers, keys, prompt bodies, schemas, OAuth-like values, or response bodies.

- [x] **Task 1.9 — Validate and document M1.** Add user instructions, supported options/models,
  endpoint selection behavior, API-key storage disclosure, multisite behavior, and troubleshooting.
  Run the offline matrix across supported PHP targets and an opt-in live coding+international
  smoke test when a key is available. Record the O1 probe result and preserve fallback if it is
  inconclusive. Check this task only after documentation and test evidence match shipped behavior.

### Exit criteria

- [x] Core auto-discovers a `zai` card with an API-key field. — apiKey()
  metadata derives the Connectors card and `connectors_ai_zai_api_key`
  (ZaiPluginScaffoldTest, record 0001 derivation test).
- [x] Each plan/region selection routes to the exact SPEC endpoint at request time. —
  table-driven exact-URL tests plus request-time retarget proof with the provider's
  cached directory and model (ZaiEndpointResolverTest, ZaiModelDirectoryTest,
  ZaiRequestMappingTest).
- [x] `wp_ai_client_prompt(...)->using_provider('zai')->generate_text()` works with mocked
  transport and, when credentials are supplied, coding+international live transport. —
  mocked end to end via the genuine SDK prompt path in the test suite; live coding+intl
  PASS through the plugin classes (ZaiLiveSmokeTest opt-in, `bin/zai-live-probe.php`,
  record 0006).
- [x] An invalid key produces a redacted, actionable error and unavailable/not-connected
  state. — nonempty-invalid key reports not connected
  (ZaiProviderMetadataAndAvailabilityTest) with a redacted actionable 401 error
  (ZaiResponseMappingTest, ErrorMapper).

---

## Milestone 2 — z.ai Anthropic-compatible provider (`zai_anthropic`)

- [x] **Milestone 2 complete.** Check this milestone only after Tasks 2.1–2.7 are checked, the one
  z.ai plugin registers both providers without collisions, and the M2 SPEC acceptance criteria
  pass across every plan/region endpoint through mocked tests.

  M2 completion evidence (2026-08-31): offline matrix green (`composer check`:
  391 tests, 1807 assertions, PHPCS/PHPCompatibility/PHPStan/conventions/
  secret-scan clean; M1 regressions green throughout). Live probe (record 0007,
  coding-plan key): `zai_anthropic` availability → `/v1/models` discovery → one
  Messages generation PASS end-to-end on general+intl (the provider's default
  after the record-0007 amendment: glm-5.3, 130 tokens); the coding+intl
  Anthropic base serves `/v1/models` but its Messages routes return wrapped
  404s for every probed combination (model IDs plain and `[1m]`, Bearer and
  x-api-key), so the SPEC (§3.1 route matrix, §3.2 note, §3.3 defaults) was
  amended in the same change and the default moved to general — the
  production-proven path (`claude-glm`). The O1 Anthropic half is resolved:
  `/v1/models` returns HTTP 200 with the Anthropic list shape and the same
  10-model GLM list on both plans; dynamic discovery ships with the tested
  plan-partitioned static fallback. Uninstall removes both providers' options
  and caches; the artifact test proves the standalone zip ships both
  providers' trees.

### Tasks

- [x] **Task 2.1 — Add the second provider and independent settings.** Extend the existing z.ai
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

- [x] **Task 2.2 — Extend endpoint resolution for Anthropic URLs.** Map coding/general × intl/cn to
  the four `/anthropic` bases and append `/v1/messages` or `/v1/models` exactly once. Read settings
  at request time while retaining the required canonical `baseUrl()`. Check this task only after
  table-driven tests cover all final request URLs and option changes between requests.

- [x] **Task 2.3 — Implement Bearer authentication and protocol headers.** Inject
  `Authorization: Bearer <key>`, `anthropic-version: 2023-06-01`, and safe content headers; do not
  depend on unverified `x-api-key` support. Check this task only after exact-header tests prove no
  duplicate/conflicting auth header and logging tests prove full redaction.

- [x] **Task 2.4 — Implement Anthropic metadata/catalog.** Create the custom metadata directory,
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

- [x] **Task 2.5 — Implement Messages request mapping.** Translate system instruction, alternating
  chat content blocks, text and supported images, tools/tool results, JSON output guidance,
  `outputSchema`, max tokens, temperature, top-p, and stop sequences to the Anthropic-compatible
  Messages format. Handle protocol constraints such as required max tokens and role ordering.
  Check this task only after focused fixtures cover every advertised option and unsupported input
  fails before HTTP.

- [x] **Task 2.6 — Implement Messages response/stream mapping.** Normalize message content,
  tool-use blocks, stop reasons, usage, and Anthropic SSE event sequences into SDK results. Reuse
  only protocol-neutral error/redaction helpers from M1. Check this task only after success,
  interleaved content/tool deltas, malformed stream, 401/403/429/5xx, and transport tests pass.

- [x] **Task 2.7 — Validate and document M2.** Update the plugin/readme documentation for two
  cards, two independent endpoint selectors and key fields, known model-list behavior, and examples
  for system instructions, tools, and structured output. Run the full z.ai regression suite and
  optional live tests without committing output containing credentials. Check this task only after
  the documentation, package contents, and M2 acceptance evidence agree.

### Exit criteria

- [x] One standalone plugin exposes both `zai` and `zai_anthropic` cards. —
  both providers registered idempotently (ZaiAnthropicPluginScaffoldTest:
  both IDs before core discovery at init 15, no duplication, no silent
  replacement of a foreign ID registration); cards distinct ('z.ai' /
  'z.ai (Anthropic API)'); both trees inside the built zip
  (BuildArtifactsTest::testRealZaiArtifactShipsBothProvidersAndStaysStandalone).
- [x] Messages requests use Bearer authentication and the required version header for all endpoints. —
  exact header-set tests on generation and the availability probe
  (ZaiAnthropicAuthHeadersTest): one `Authorization: Bearer <key>`, one
  `anthropic-version: 2023-06-01`, Content-Type on generation, never
  `x-api-key`, never a duplicate credential header — with the endpoint
  matrix resolved per plan/region at request time
  (ZaiAnthropicEndpointResolverTest) and the plan-dependent Messages route
  verified live (record 0007).
- [x] Tools and `outputSchema` round-trip through representative mocked Claude-Code-style workloads. —
  request snapshots for the tool round trip (declaration → tool_use →
  tool_result) and structured output (tests/fixtures/snapshots/zai-anthropic/),
  response mapping of tool_use blocks and interleaved SSE tool deltas, and
  JSON guidance embedding the outputSchema (ZaiAnthropicRequestMappingTest,
  ZaiAnthropicResponseMappingTest); the genuine WP_AI_Client_Prompt_Builder
  path proves `using_provider('zai_anthropic')->generate_text()` end to end
  with mocked transport.

---

## Milestone 3 — Shared OAuth security/runtime foundation

- [ ] **Milestone 3 complete.** Check this milestone only after Tasks 3.1–3.8 are checked and the
  shared source can be embedded into two fixture plugins under distinct namespaces, with encrypted
  storage, concurrency-safe refresh, admin protection, and zero runtime cross-plugin dependency.

### Tasks

- [x] **Task 3.1 — Define provider-neutral OAuth contracts.** In `shared/`, define PHP 8.2-safe
  interfaces/value objects for token sets, clocks, HTTP transport, OAuth grants, token storage,
  refresh policy, availability, and typed errors. Keep provider endpoints/client IDs out of generic
  classes. Check this task only after contract tests cover token validation/serialization and an
  architecture review confirms no WordPress global is hidden inside pure value objects.
  — Done: `shared/src` (source namespace `Deicod\WpConnectors\Shared`, the namespace
  `bin/build.php`'s rewriter targets) ships the token-set VO (validation matrix, strict
  microsecond-exact `to_array()`/`from_array()`, null-vs-empty refresh token modelled distinctly,
  merge semantics), the clock port + system implementation, the HTTP transport port with
  redaction-safe request/response VOs (masked `…last4`, query/userinfo dropped, body omitted,
  CRLF rejected at the boundary), the grant VO with the fencing generation and the
  three-class terminal model (connected / reconnect-required / configuration-error / revoked
  tombstone — `revoke()` the only tombstone producer), the storage port with the envelope
  invariants documented (atomic replace, versioned, provider+site bound, never partial
  plaintext, fail closed), the refresh policy (neutral numbers; expiry-minus-skew; one
  cooldown cap governing both Retry-After forms), the availability vocabulary (five states
  incl. update-required; the GET-render read-only contract rides the context parameter), the
  typed error hierarchy (transient marker; rate-limit Retry-After aware), and the device/PKCE
  flow shapes (S256 pinned against the RFC 7636 vector; user-scoped pending state). Contract
  tests: 148 new (token/clock, errors, HTTP incl. redaction + CRLF pins, grant, policy,
  availability, flow) plus `SharedOAuthArchitectureTest`, the architecture sweep proving zero
  WordPress reach, provider neutrality, rewrite-safe namespace spelling, no static mutable
  state, and PSR-4 discipline (non-vacuity-guarded, mutation-batteried). Architecture review:
  a two-lens review round (four dimension reviewers, adversarial verification — 4 confirmed
  findings fixed as 9c41baa/ec2ba0d, 4 refuted with two consciously deferred to the plugin
  flow tasks) confirmed no WordPress global is hidden inside the pure value objects. Fix
  round t31-r1 (21 commits over the review's 15 findings): DST-proof, saturation-proof
  absolute-second arithmetic with canonical-UTC serialization (Support\InstantArithmetic);
  serializability bounds on expires_in and obtained-at; Http\HeaderMap as the single
  header-map owner (RFC 7230 token names, control-byte + C1/line-separator class in values,
  case-variant duplicate fence) with the redaction hole the verifier reproduced closed;
  character-wise SecretMask (never invalid UTF-8); the architecture sweep loud on PCRE
  aborts and unreadable files, typed/DNF statics caught; revoke() and the instant
  arithmetic reject typed at their int boundaries; canonical-only serialized instants;
  the storage port widened to a generation-checked compare-and-set (Task 3.3's fence,
  fixed before 3.2/3.3 pin the shape) and token-set reads made forward-tolerant (Task
  4.4's sixth key loads on older readers) — both scope decisions and the raw
  retry-after adjudication ledgered; a two-lens verifier pass re-drove every fix
  empirically (six findings fixed as t31-r1-16..21). Fix round t31-r2 (18
  commits over round-2's 15 findings, 11 counted): the URL surface screened
  with the shared control-byte vocabulary (forged-line + bidi/override classes;
  one owner, HeaderMap's constant, covering both safe-debug surfaces); the
  harness DeterministicClock routed through InstantArithmetic (DST/saturation
  repro shapes pinned); the revocation tombstone structurally mint-only-by-
  revoke() (constructor private, StoredGrant::in_state() the public entry
  rejecting Revoked — NOTE FOR TASK 3.2: hydrating a persisted tombstone needs
  its own deliberate producer, never the reopened constructor); all-digit
  header names accepted as the RFC 7230 tokens they are; the architecture
  sweep whole-file (multiline statics caught, line-located), loud on failed
  reads under both runtime spellings, refusing PCRE aborts, and banning direct
  clock/environment reach (the README's reached-only-through-the-ports claim
  now enforced); header lookup on the constructor-built folded index with a
  locale-independent ASCII fold at every fold site; one token-grammar owner
  for names and methods; embed_shared shipping exactly the shared PHP sources
  with a validated namespace suffix (pre-existing master bugs, fixed forward);
  the from_array contract and the repeated-header collapse documented
  honestly (the latter deferred to Task 3.7). A two-lens verifier pass
  (adversarially verified per finding) held every fix-claim and confirmed
  four findings, fixed as t31-r2-16..19 (the whole-file gate's PCRE-abort
  fail-open — found by both lenses independently; getdate()/localtime()
  joining the clock vocabulary; non-PHP files inside shared/src shipping;
  the backreference-material namespace rewrite). Fix round t31-r3 (16
  commits over round-3's 14 findings, the embed pipeline's six landed as
  ONE coherent seam — config validation first, staging try/finally,
  rewrite/whitelist/collect vocabularies agreeing, one PHP-sources-only
  assertion): build.json validated once at a config seam before any
  filesystem mutation (readable JSON OBJECT — trailing comma, empty,
  scalar, and the verifier-caught top-level ARRAY each refuse; the
  old shape silently shipped a library-less zip with exit 0); the
  staging lifecycle try/catch/finally with the failure cleanup scoped
  to what the run wrote (a failed build keeps the last good zip,
  sidecar, and manifest entry consistent, and a corrupted archive
  takes its sidecar and entry with it); every shared PHP source ships
  from anywhere in the source tree (no exclusion segments,
  case-insensitive extension, ONE collector vocabulary shared by the
  build and the architecture sweep); every shared-namespace use
  spelling rewritten (plain/aliased/function/const/fully-qualified/
  exact/brace-group) with a survivor-refusing postcondition; the
  namespace derivation producing legal labels for digit-initial slugs
  ('3cx-oauth' → '_3cxOauth', documented in CONVENTIONS.md);
  should_refresh total at the extreme corners (the unrepresentable
  threshold decided deterministically inside the predicate); the
  WP-reach and provider-name gates whole-file (multiline spellings
  caught, abort = refusal, mutation-tested end to end); the method
  fold locale-independent (AsciiFold::upper, class-closure pinned);
  HeaderMap single-structure (the folded index alone); the sweep
  loud-read and cached per run. A two-lens verifier pass (each finding
  adversarially verified) held all 14 fix-claims and confirmed two
  defects, fixed as t31-r3-15/16 (the build.json array-top-level
  silent skip — found independently by both lenses; the failed
  build's orphaned-checksum state). Suite 1513 → 1521 tests, 43122 →
  43256 assertions, 2 skipped unchanged. Fix round t31-r4 (18 commits
  over round-4's 13 findings, the two biggest as structural
  class-kills — per-spelling patching declared exhausted): the rewrite
  postcondition became a TOTAL scan (zero occurrences of the source
  namespace in the rewritten output, case-insensitive, whitespace-
  tolerant, brace-aware — the group-use member spelling the rewriter
  now owns, the multiline string/docblock spelling that defeated every
  contiguous probe, and the case-variant posture the ledger had
  accepted; the sweep's namespace gate whole-file on the same
  pattern), and build.json became a CLOSED SCHEMA (unknown keys,
  non-boolean embed_shared, non-string suffixes, duplicate keys, a
  non-regular file, and a suffix mismatching the slug-derived
  autoloader prefix each refuse loudly — the silent-no-embed and
  unloadable-library classes killed). Artifact integrity closed at
  every seam: checked zip finalization (a failed close after OVERWRITE
  destroyed the previous good zip), a per-run-atomic checksum manifest
  (the CLI pre-run wipe dropped other plugins' entries and left the
  manifest gone on failing rebuilds), and checked publication writes
  (a blocked sidecar path shipped a sidecar-less zip at exit 0). Both
  source collectors refuse symlinks loudly (dev-loads/zip-misses
  divergence); one case-insensitive php-extension owner serves
  collect/strip/classify/lint/inspect; the PSR-4 vocabulary covers
  trait and readonly class; the WP-reach stems match _-suffixed twins
  with the curation doctrine ledgered; Url rejects truncated raw ports
  (adjudicated fix, ledgered with the empty-port narrowing) and
  requires whole-URL UTF-8 (a raw C1 byte made json_encode of the log
  line return false). A two-lens verifier pass held all 11 fix-claims
  and confirmed four defects, fixed as t31-r4-15..18; residuals
  adjudicated and ledgered (headline: split-composed namespace
  spellings — runtime concatenation/interpolation — are outside a
  spelling-level scan's charter). Suite 1521 → 1538 tests, 43256 →
  43498 assertions, 2 skipped unchanged. Fix round t31-r5 (18
  commits over round-5's counted findings, executed as the
  round's own class-killer mandate): the publication seam
  restructured so every byte of the artifact set — zip, sidecar,
  manifest — is produced and verified at temp paths first (the
  archive PID-unique, the manifest tempnam-unique) and landed by
  checked renames behind a landing pre-flight, deleting the
  catch-based compensating apparatus and its bug history; a
  build-seam property battery (tests/BuildSeamPropertyTest.php)
  enumerating the adversarial build states and asserting the
  invariant — exit 0 means a complete, sound, inspector-accepted
  artifact; any failure means a loud refusal with the previous
  good set byte-untouched — authored red-first (8 states failing
  pre-fix); the embed seam's reads made loud at both collection
  points (the ledgered t31-r3 unreadable-file note's reopen
  condition consumed: 0-byte ships refuse); the plugin-owned
  src/Shared collision refusing (case-folded); the source-less
  shared tree refusing; the shared-source collector accepting only
  the canonical lowercase .php casing plus a near-source fence
  (doctrine change recorded); the inspector's forbidden-entry
  vocabulary scoped to plugin-owned paths and then unified with
  the builder's into one shared list; build.json duplicate keys
  counted on DECODED top-level keys (an escaped duplicate was
  last-wins no-embed at exit 0); the collector's exclusion filter
  ordered before the symlink refusal; the version-constant
  derivation extended to digit-initial and dotted slugs (legal,
  bare-code-reachable labels both). A two-lens verifier pass held
  all 11 fix-claims (the battery proven non-vacuous by mutation)
  and confirmed 7 findings, fixed as t31-r5-10..16 — headlined by
  the manifest merge's concurrent lost update (now flock-guarded
  across read-through-landing), the unreadable-manifest silent
  entry destruction, and the Version-header traversal staging.
  Suite 1538 → 1549 tests, 43498 → 43676 assertions, 2 skipped
  unchanged. Fix round t31-r6 (7 commits over round-6's three
  counted findings — one theme: the branch's case-insensitivity
  doctrine applied inconsistently in the build tooling): the
  repo-LICENSE injection rides the collision fence's case
  comparison (a case-variant plugin license deferred to, never
  shipped beside the injected copy); the near-source fence's
  edge-junk byte class owned once and applied to BOTH edges
  (a trailing C0/DEL byte hid the extension; a leading edge byte
  or junk directory segment shipped a dead, unautoloadable
  entry); the development-entry vocabulary compared
  case-insensitively over the trailing-junk-stripped segment at
  ONE owner serving builder and inspector. A proportionate
  two-lens verifier pass held all three fix-claims (each pin
  mutation-proven red) and raised ten findings — four fixed
  in-round (the two falsifying the round's own claims plus two
  hygiene items), the remaining seven adjudicated and ledgered
  with repros and fix shapes (headlined by the secret scanner
  pruning the subtree the embed ships by design, and the
  case-variant 'Plugin Name:'/build.json spellings). Suite
  1549 → 1552 tests, 43676 → 43763 assertions, 2 skipped
  unchanged. Fix round t31-r7 (5 commits over round-7's five
  counted findings, all in the build/rewrite seam — the K1
  survivor surface one spelling away for the third time): THE
  TERMINAL FIX, replacing text-level namespace detection with
  TOKEN-level detection in one shared detector
  (wp_connectors_shared_family_references(), one implementation
  the build's rewrite postcondition and the sweep's namespace
  gate both ride — name runs reassembled across comments and
  whitespace so the interrupted spelling dies by construction,
  string literals judged by their unescaped runtime value
  closing the ledgered K1 boundary's static half, siblings
  under the vendor prefix banned outright with the r4-era
  "foreign namespace" pins flipped, the sweep verifying
  ownership by rewriting through the build's own
  postcondition so the two gates give one verdict by
  construction, and the legal tree's import vocabulary
  enumerated and pinned); plus the generated-member write
  made checked and staged-verified (writeNormalized owns
  file_put_contents' return and re-reads the landed bytes —
  the r5-S verified-whole claim now holds for generated
  members). A two-lens verifier pass (every finding
  adversarially re-derived before fixing, all four CONFIRMED)
  falsified the round's own implementation three times —
  the run assembly dropped the separator byte on
  trivia-after-separator joins (a genuine regression: the
  whitespace spellings the r4 regex refused became exit-0
  ships), the alias skip survived its statement's end and
  ate the next name run, and target-rooted code/string
  references shipped as dangling spellings the sweep
  refused — fixed as t31-r7-6/7/8, each pin red under the
  pre-fix code. Suite 1552 → 1554 tests, 43763 → 44107
  assertions, 2 skipped unchanged. Fix round t31-r8 (11
  commits over round-8's seven counted findings — three
  state-machine/predicate gaps in the r7 token detector,
  two HeaderMap surface defects, a directory-casing axis,
  a tokenization cleanup): the use-statement boundary
  became a SET (';' plus every PHP-mode tag token — a
  close tag is a terminator exactly like ';', and the
  r7-7 reset had named only one spelling, leaving the
  alias skip armed across the mode boundary to eat the
  next name run); the relative operator (T_NAME_RELATIVE)
  resolves against the file's declared namespace before
  the family predicates — with the adaptation carve-out:
  a relative under a rewrite-owned tree adapts through
  the rewrite in any position and reports nothing, only
  a family-resolving relative under a non-owned base
  reports, under its own 'relative' kind (never 'use',
  whose rewritable-position reading would drift the two
  gates); the text lens applies the FULL family predicate
  (bare vendor prefix and sibling continuations via a
  generated sibling pattern whose exclusion owns exactly
  the dedicated patterns' below-vendor tails); the ONE
  collector gained the PSR-4 casing-agreement fence
  (declared namespace's below-root segments must equal
  the staged path's directories case-exactly, depth
  included, one declaration per file); the bidi screen
  gained the direction marks LRM/RLM/ALM at its single
  owner; rendered_lines() is always valid UTF-8 (invalid
  bytes percent-encoded at the render seam — the r4-13
  outcome without rejecting legal obs-text); and one
  tokenization feeds both lenses with one line semantics.
  A two-lens verifier pass over the round diff (every
  finding adversarially re-derived, all four CONFIRMED
  with end-to-end repros — two found independently by
  both lenses) falsified the round's own fixes: the fence
  judged only a file's FIRST namespace block (a two-block
  source shipped with the second block's class
  unloadable through the real shipped autoloader), the
  sibling exclusion over-covered the target segment
  (target-SEGMENT docblock siblings shipped past the
  postcondition while the sweep refused them — the r7-8
  drift class one segment inside the target tree), a
  parse-error fully-qualified declaration corrupted the
  relative-resolution base and laundered a family-
  resolving relative past both gates, and the line
  refactor drifted the two lenses' lines apart on
  CR-only files — fixed as t31-r8-8/9/10/11. Suite
  1554 → 1560 tests, 44107 → 44212 assertions, 2 skipped
  unchanged. Fix round t31-r9 (11 commits over round-9's
  nine findings + the noted item): the r8-5 ALM ban's
  bytes corrected (it banned \xD9\x9C — U+065C, a
  VISIBLE Arabic vowel sign — while the real U+061C
  mark's \xD8\x9C passed: the reorder-spoof channel
  reopened and legitimate content falsely refused; the
  swap verified against the Unicode database, the vowel
  sign returned to allowed obs-text, byte-swap pinned on
  both surfaces); the build's self-containment gate
  extended to the COMPOSED staged tree (an escaping
  include in a shared source published at exit 0 while
  inspect refused — one verdict restored, the WP-reach
  curation item staying ledgered); token material
  VSCHAR-screened at construction per RFC 6749's token
  grammar (non-UTF-8 bytes made the 3.2 envelope payload
  unencodable); the display_errors file-scope leak in
  bin/build.php + bin/inspect-artifact.php moved inside
  the CLI guard (both files are required by the test
  suite — the glm17-16 class); the storage fake
  round-trips grants through serialize/unserialize with
  the port contract stating identity never survives the
  storage boundary (port tests re-pinned by value); the
  provenance banner inserted after ANY legal opener
  (case-insensitive + BOM-preserving, zero-match refuses
  — '<?PHP' shipped banner-less); the unused-import gate
  grown to shared/src; the short-write pin's stream
  scratch kept off the host filesystem (two leaked
  scheme-named dirs in the repo root removed); and the
  rewriter's four hand-spelled family literals derived
  from the namespace helper via preg_quote (byte parity
  verified). The noted item fixed as the one-line shape:
  the clock port disclaims instance identity. A two-lens
  verifier pass (correctness lens clean; the security
  lens's one finding adversarially CONFIRMED with
  end-to-end repros) falsified r9-1's own docblock —
  "only format controls are banned" was false coverage:
  the invisible bidi-ACTIVE Cf siblings (U+070F strong-
  RTL, U+110BD/U+13430-3F strong-LTR, U+0600-0605/U+06DD/
  U+0890/U+0891/U+08E2 AN) plus ZWSP/ZWNJ/ZWJ/U+FEFF/
  U+00AD passed on both surfaces — all banned at the one
  owner as t31-r9-10, the vocabulary restated as a
  curated byte list (never a category derivation), and
  completeness verified exhaustively (zero bidi-ACTIVE
  Cf pass; the invisible residual ledgered as curation
  decisions). Suite 1560 → 1569 tests, 44212 → 44283
  assertions, 2 skipped unchanged. Fix round t31-r10
  (13 commits over round-10's eight findings + five
  adversarially-confirmed verifier follow-ups): the
  walk's trait-adaptation misparse (the r8-noted debt)
  closed — a glued brace opens an ADAPTATION block whose
  clause and members report as un-composed code
  positions, where the old group-prefix composition
  laundered a fully-qualified family reference to zero
  carriers (exit-0 ship reproduced); the value lens
  strips the value-free b/B prefix before taking the
  quote (a b-prefixed family class-string was invisible
  while its unprefixed twin refused); lint-php.php's
  file scope moved inside the CLI guard (the r9-4 class,
  closed repo-wide); the stage tree PID-named with a
  dead-pid startup sweep (the r5-11 reopen — concurrent
  same-plugin builds survive); both VO headers()
  docblocks state the all-digit int-key caveat in
  byte-identical wording; the provider-name pattern
  derives from a SPEC-tracked provider set (single
  source, fail-loud both directions); the HeaderMap
  duplicate fence probes the one folded index (the
  seen_lowercase parallel deleted); and both
  slug→identifier spellings (namespace suffix, constant
  stem) derive from one core with byte parity pinned.
  The two-lens verifier pass raised ten findings — five
  distinct defects, ALL CONFIRMED with reproduced
  evidence, zero refuted — fixed in-round: three more
  zero-carrier spellings of the same walk machinery (a
  fully-qualified group member against a non-family
  prefix — an exit-0 ship through the real builder; a
  qualified name after `as` eaten by the alias skip; an
  empty group body reporting nothing); the sweep
  deleting a FOREIGN tree through a stage-shaped symlink
  (links never touched now, own-name link refuses
  loudly); the multi-trait clause classification stated
  honestly in the contract docblock (+ the missing
  clause-list battery row); a row-count fence on the
  SPEC-sync pin (deviant table rows fail loudly, never
  skip); and the sweep's crashed-run charter extended to
  the pid-named zip temps (dead pid → unlinked; pid-less
  manifest temps exempt, never raced). Suite 1569 →
  1572 tests, 44283 → 44418 assertions, 2 skipped
  unchanged. Fix round t31-r11 (11 commits over
  round-11's eight counted findings + three
  adversarially-confirmed verifier follow-ups): the
  rewriter owns the namespace-relative USE spelling
  (the adjudicated r8-2 premise-false finding —
  rewriteRelativeUseImports() resolves the operator
  against the declaration in effect and splices the
  rewritten fully-qualified import; the detector drops
  the carve-out in use positions, code positions keep
  it); the sweep's liveness probe falls through to the
  signal-0 check on an INVISIBLE /proc entry (hidepid
  made a live sibling read dead — the injectable-probe
  regression pins EPERM alive); the URL port screen
  bans the whole glued-bracket class (']' then ':' or
  end, abort-refusing); the dotted-slug test rides the
  artifact-state machinery (no more dist/ zip leak
  whose checksum the manifest records nowhere — the
  leaked pair deleted); every secret-carrying VO
  defines __debugInfo() mirroring the masked vocabulary
  (HeaderMap is the one render owner for headers,
  masked_headers() shared by line/map/dump forms); the
  slug→identifier core folds ASCII-only (bin-side byte
  tables, the tr_TR regression); CONVENTIONS states
  the hyphen-or-dot separator the check enforces; and
  octal escapes past \377 unescape deprecation-free
  (the engine's own low-byte wrap, explicit). The
  two-lens verifier pass raised SIX findings — three
  distinct defects after the cross-lens duplicates
  (both lenses independently found the relative-member
  splice and the interrupted-relative ride), ALL
  CONFIRMED with reproduced evidence (two exit-0 ships
  through the real builder, both produced by the
  round's own new code path), one REFUTED (the
  var_export/serialize bypass — the docblocks already
  enumerate the covered set; ledgered), all fixed
  in-round: a relative group-use MEMBER refuses (its
  FQ splice was an illegal spelling the postcondition
  waved through); the INTERRUPTED relative spellings
  (trivia between keyword and name drops the fused
  token) are owned — resolved across the trivia,
  rewritten, or refused by the same battery; and the
  bracket screen generalizes from digits to the whole
  glued class (159 visible-ASCII spellings misread
  identically by parse_url). Suite 1572 → 1581
  tests, 44418 → 44539 assertions, 2 skipped
  unchanged. Fix round t31-r12 (20 commits over
  round-12's ten counted findings, executed per the
  driver's two adjudications — the r6 ledger line's
  secret-scanner pruning owned as this round's
  material, and `location` re-opened on RFC 6749
  §4.1.2 vendor-doc proof): the inspector's partial
  extraction refuses loudly (both zip returns owned,
  the captured engine warning silenced and surfaced
  as the reason, no content check over a partial
  tree); a lone `]` refuses and the bracket pair
  wraps the whole host non-empty; artifact secret
  scans run unpruned (the r6 HIGH closed — the
  prune is a repo-walk concept, and the src/Shared
  dev-entry exemption composes as classification-
  only); `location` joins the one sensitive-header
  catalog; device/user codes and the pending flow's
  provider id ride ONE control-byte guard at the
  vocabulary owner; the success line's digest and
  the checksums manifest are both guarded honestly
  (a blank sha256= never prints at exit 0;
  regeneration drops entries whose artifact is gone
  — the manifest is an inventory now); the rebuilt
  authority is re-validated post-parse (locale-
  independent folds since the 8.2 floor, the guard
  is the class-killer, the manufactured-locale pin
  restores LOCPATH before the locale with a checked
  restore); the lint gate's exclusions ride the ONE
  development-entry vocabulary root-relative; the
  embed destination prefix has ONE owner with two
  fold roles (the writer's fence folds, the
  inspector's exemption stays canonical); the CLI
  guard + diagnostics idiom is ONE helper on the
  auto-global argv (scan-secrets.php ledgered for
  its own round); the embed postcondition walks the
  subtree anchored at the composed root; the HTTP
  VOs' header facade is ONE trait; buildPlugin's
  docblock states the shared-source layout coupling.
  The round's two-lens verifier pass raised SIX
  findings — a HIGH (byte-exact duplicate entry
  names extracting last-wins: the unlanded copy's
  webshell and live key shipped green, reproduced
  end-to-end; the inspector fences byte-exact and
  case-fold duplicates now, the r6 collision line's
  inspector half consumed), two MEDIUM regressions
  of the round's own first cuts (the folded embed
  exemption went REJECTED→ACCEPTED on hostile zips
  and is canonical again; the CLI guard read
  $_SERVER['argv'], a silent exit-0 no-op under
  variables_order=GPC), and three hardening items
  with stated runtime boundaries (the printable
  seam for captured diagnostics, the whole-host
  bracket wrap, the exception-safe capture restore)
  — all six fixed in-round as t31-r12-15..20, and
  the pass also caught the round's own locale pin
  leaking the manufactured locale into the suite
  (glibc resolves the restore through LOCPATH; a
  random-order flake, ~1 run in 3, fixed in-place).
  Suite 1581 → 1596 tests, 44540 → 44708
  assertions, 2 skipped unchanged. Fix round
  t31-r13 (five commits over round-13's three
  counted findings — one MEDIUM security whose
  fresh repro satisfied the ledger's re-open rule,
  one LOW correctness, one cleanup — plus two
  verifier-round commits): every inspector verdict
  line renders archive-controlled names through
  the ONE printable seam — the repro (a raw stored
  zip's entry name survives getNameIndex()
  byte-exact with its newline; neither ZipArchive
  side sanitizes names, both probed) falsified the
  r12 ledger entry's "does not reproduce" premise,
  and the six sites it left open (plus the
  invalid-slug refusal, the same class one grammar
  step earlier) forged verdict lines beside the
  real REJECTED verdict; StoredGrant's provider id
  joins the r12-5 control-byte guard at the
  constructor funnel (a '\n'-bearing label forged a
  line beside the masked token set, reproduced);
  and the use-rewrite walk's three fence predicates
  (closure-use fence, r8-1 boundary set, r8-10
  declaration shapes) hoisted to single owners in
  plugin-tools.php — behavior-identical by
  existing batteries plus a byte-identical
  before/after differential. The round's two-lens
  verifier pass raised ONE finding independently by
  both lenses — the MERGED helper lines (main-file
  names, header values, version-constant value,
  self-containment paths and include text) still
  interpolated archive-controlled bytes raw,
  reproduced on every channel — fixed in-round as
  t31-r13-4 (the seam rides the merge points; the
  helpers stay pure producers over trusted repo
  bytes); one precision item adopted (libzip's
  captured warning SUBSTITUTES control bytes with
  visible glyphs, probed byte-level) and one lens
  claim REFUTED (php -l echoes the raw newline —
  od -c). Two hardening boundaries ledgered with
  their no-producer rationale (the seam's C0+DEL
  class; the CLI argv channel); the dangling-
  symlink build.json shape forwarded onto the r6
  config-seam ledger line. Suite 1596 → 1599
  tests, 44708 → 44742 assertions, 2 skipped
  unchanged. OCR-tool round t31-ocr1
  (fourteen commits — the first pass of a
  complementary deterministic reviewer over
  the full branch diff, 9/9 findings accepted
  at triage as seams the manual rounds
  missed, plus five verifier-round commits):
  the one unguarded createFromFormat() in
  the repo rejects typed instead of escaping
  an engine Error (InstantArithmetic's
  reconstruction, guard at the extracted
  seam); the empty-host URL spelling refuses
  explicitly on every build; rrmdir never
  deletes through a link at EITHER removal
  seam (build + harness twin, the latter a
  both-lens verifier find — the pre-fix body
  reproduced emptying the target tree
  through a planted root link); the URL
  scheme/host folds ride AsciiFold with the
  disputed engine-history docblock claim
  gone (glibc's Turkish tolower divergence
  C-probe verified; this engine's string
  folds don't consult LC_CTYPE, probed over
  256 bytes); the scanner prune rides the
  development-entry vocabulary's own fold,
  below the root only (a dev-named checkout
  ancestor silently blinded the scan,
  reproduced — exact-case shape pre-round);
  setlocale snapshots query with '0' (null
  SETS from env, reproduced) behind a
  discriminating pin; save()'s provider
  identity is contract with the reference
  fake rejecting mismatched keys loudly and
  screening the key through the control-byte
  guard; serialize() rides the masked view
  with __unserialize/__set_state refusing
  and var_export the named exclusion; and
  the scratch-tree helpers consolidated once
  into the harness (loud policy, no
  non-compound use statements in
  namespace-less harness files — the
  verifier's MEDIUM). Suite 1599 → 1605
  tests, 44742 → 44798 assertions, 2 skipped
  unchanged. OCR-tool round t31-ocr2 (eleven
  commits — the second pass of the same
  deterministic reviewer, two runs over the
  full branch diff, 13/13 findings accepted,
  THREE of them direct follow-ons of the
  round-1 fixes: the tool auditing our own
  work): serialize() masked on the token VOs
  (AccessTokenSet + StoredGrant hook
  __serialize() to the dump's own masked
  view, reconstruction refuses, and the
  storage fake's detachment moved off the
  closed channel to the strict-storage
  re-state through the private-constructor
  hydration the VO's forward note names) and
  on HeaderMap itself (parity with its own
  drift doctrine, pinned against print_r());
  the storage CAS fence gained its
  monotonicity leg (a grant staler than its
  own expectation regressed the persisted
  fence and resurrected revoked tokens — the
  contract requires generation >= expected
  now, the fake rejects lower loudly); the
  save() docblock states the control-byte
  screen the fake performs; a bracketed URL
  host must BE a well-formed IPv6 literal,
  not merely a well-placed bracket pair; the
  refresh totality corner consumes
  InstantArithmetic's own named underflow
  predicate (the guard's lower leg, one
  owner); negative Retry-After has one truth
  — the constructor clamps to zero, the
  parser reality the policy already
  documented; the PKCE constructor enforces
  the S256 binding (constant-time, one
  derivation owner); and three test-side
  fences: exception finality pinned, the PCRE
  burner's abort pinned deterministic via a
  small backtrack limit restored in finally,
  and the namespace scan reads
  abort-as-refusal. The round's two-lens
  verifier pass (a deterministic workflow:
  independent correctness + security agents,
  every finding to an adversarial refuter
  with driven probes) raised 3 raw — one a
  both-lens find — deduped to 2, BOTH refuted
  (the flow VOs' serialize exposure is
  pre-existing, producer-gated, and twice
  ledger-covered by name; the rate-limit
  accessor's RAW adjective is an adjudication
  record whose re-open condition is unmet)
  and ledgered as boundaries. Suite 1605 →
  1613 tests, 44798 → 44911 assertions, 2
  skipped unchanged.
OCR-tool round
  t31-ocr3 (eight commits — the third
  pass of the same deterministic
  reviewer, 9/9 findings accepted,
  several the tool's audit of our own
  prior rounds: the round-2 serialize
  refutation's named re-open condition
  — "a round finding that names them" —
  fired): the serialize-masking doctrine
  covers the last credential-bearing VOs
  (the PKCE pair, the device session,
  and the pending-authorization carrier
  whose payload rides as the OBJECT so
  the mask decision stays the payload's),
  both render hooks pinned to the ONE
  masked view so the dump and serialize
  channels cannot drift; a build.json
  symlink — dangling or resolving —
  refuses at the config seam
  (file_exists() follows links, so a
  dangling link silently meant no-embed
  and a library-less zip at exit 0); a
  mid-name or alias-slot relative
  operator in a use statement refuses
  loudly (the splice once started at the
  keyword, left the preceding separator
  standing, and shipped a
  double-separated parse error at exit 0
  — reproduced and php -l-verified; the
  uninterrupted mid-name spelling demotes
  the keyword to a plain name piece on
  this lexer, a legal non-family import
  that rides untouched); the scanner
  library owns its plugin-tools
  dependency by require_once (a fresh
  process requiring only
  secret-scanner.php fataled mid-scan
  before); the clock pin drops the
  ordering assertion the port disclaims;
  the instant arithmetic declares its
  range rejection on both public
  spellings; and the fifth entry script
  rides wp_connectors_cli_entry(),
  closing the r12-11 sweep. The round's
  two-lens verifier pass (deterministic
  workflow, independent correctness +
  security agents, 79 driven tool calls
  between them) raised ZERO findings.
  Suite 1613 → 1616 tests, 44911 →
  44974 assertions, 2 skipped unchanged.
OCR-tool round
  t31-ocr4 (nine commits — the fourth
  pass, 12/12 findings accepted, SIX one
  defect class swept together; the
  trajectory is the signal: shared/src
  correctness and security findings are
  EMPTY — the loop is scraping the test
  harness now): every chmod-0000 leg
  skips under a root runner (uid 0 reads
  through mode 0000; ONE guard in the
  harness parent, row-level skips in the
  build-seam battery — never a
  whole-battery skip — and a grep census
  that every leg is guarded);
  WpHarness::copyTree() strips the source
  prefix positionally (str_replace()
  stripped every occurrence — a source
  path repeating inside itself collapsed)
  and refuses symlinks of BOTH shapes
  loudly (the copy twin of rrmdir()'s
  no-symlinks doctrine: a linked file was
  followed, a linked dir silently
  skipped); Url's scheme-separator probe
  is false-first like every sibling (the
  construction-unreachable arm guarded
  per the file's own doctrine); the
  rewrite ledger expires BRACED namespace
  blocks at their closing brace (a
  post-block use is legal PHP in global
  scope and misattributed to the expired
  declaration); the ancestor-boundary
  sub-test's ancestor is a real
  case-variant above the root (the former
  spelling matched nothing — the pin
  stayed green over the regression it
  documented); and the concurrent-build
  legs collect proc exits before
  asserting (an in-loop assertion leaked
  un-reaped children racing their own
  cleanup). The round's two-lens verifier
  pass (deterministic workflow,
  independent correctness + refutation
  agents, 67 driven tool calls between
  them) raised 2 findings — one per lens,
  both reproduced — fixed in-round: the
  symlink pin's nothing-landed assertion
  over-claimed (yield order is the
  filesystem's — false-fail on ext4) and
  the ledger's brace view left inline
  HTML unmasked (an HTML '{'/'}'
  counterfeited the block close both
  directions; blanked in the ledger's own
  view, never in the shared masker
  owner). Suite 1616 → 1618 tests, 44974
  → 44985 assertions, 2 skipped
  unchanged.
OCR-tool round
  t31-ocr5 (ten commits — the fifth
  pass, union coverage complete, 9/9
  findings accepted: ONE seam edge and
  eight test-infra pins, shared/src
  still clean): the build rewriter owns
  the bare `namespace` keyword TOTALLY
  outside use statements — a keyword
  that does not open a declaration (a
  name, or a braced block) standing at
  a statement boundary refuses loudly,
  judged across mode boundaries (the
  detector's walk drops the bare
  keyword per the r8-10 rule, and the
  zip ships through no lint gate — the
  interrupted relative spelling once
  rode every gate at exit 0); the
  conventions summary counts by source
  (plugin-tree / shared/src / repo — a
  pooled count attributed shared/src
  violations to plugin dirs); the
  symlink-refusal legs assert OUTSIDE
  the catch (AssertionFailedError
  extends RuntimeException — the
  fail()-inside-try was swallowed by
  the catch meant for copyTree); five
  vacuous pid-less stage pins ride the
  glob helper; the exception-family
  list is pinned against its directory
  (glob == list + anchors, composing
  with the one-type-per-file gate); the
  payload-API audit covers INHERITED
  methods over a reflection-derived
  \Exception baseline; the whole-file
  diagnostic speaks \R and refuses on
  /u abort; and the provider pattern's
  trailing fence is the r4-11
  letter-aware lookahead (the '_'-extended
  twins once escaped it — the
  exact mechanism the file adjudicated
  for the WP stems). The round's
  two-lens verifier pass (140 driven
  tool calls) verified all eight fixes
  red/green — correctness lens ZERO
  findings — while the refutation
  lens's 4 findings triaged 3 confirmed
  (fixed in-round: the fence's mode-
  boundary blindness and expression-
  position shapes, one predicate; the
  diagnostic's silent /u abort) and 1
  REFUTED (the claimed escape already
  owned by the one-type-per-file gate).
  Suite 1618 → 1623 tests, 44985 →
  45028 assertions, 2 skipped
  unchanged.
OCR-tool round
  t31-ocr6 (sixteen commits — the
  sixth pass, union coverage
  complete, 13/13 findings accepted:
  the FIRST shared/src security
  finding since round 3 — the
  SecretMask tail-length policy, not
  a new channel): the visible-tail
  threshold is 12 at the mask owner
  (OTP-class values — RFC 8628 user
  codes, device codes ≤ 12 chars —
  render the bare mask; 'BCJK-3502'
  once rendered '…3502', half the
  code's entropy; raising only masks
  more, no long-value render loses
  its tail); the storage port's
  control-byte screen is a
  THREE-METHOD contract (load/delete
  accept the same caller-controlled
  key save() screens — one
  screen_key() owner at three call
  sites in the fake); provider_id
  snapshots escape invalid UTF-8
  through the r8-6 one rendering
  owner (constructor stays
  byte-permissive — a config label
  is opaque; the exception-message
  sprintf residual named for the
  next round); copyTree()'s
  prefix-strip no-match arm refuses
  loudly (a trailing-slash source
  once silently nested every file);
  BOTH re-grown fixture-copy twins
  ride the one copy owner
  (makeScratchRepo's, the finding,
  and copyFixturePlugin()'s, the
  lens's catch — same str_replace
  strip, same missing isLink()
  guard, survived both ocr4 fencing
  rounds); the prune flag is typed
  bool; the forced-add pin pins the
  add+close CONTRACT (libzip's
  stat-at-add vs deferred-read is a
  build detail); fixture mutations
  derive their needles with asserted
  counts; GrantState's header count
  agrees with its list; the
  nested-name pin names the real
  pre-fix glue ('vendornested.php');
  symlink-capability probes
  @-suppress the probe call (the
  warning conversion errors the test
  at the call line before
  markTestSkipped — both idiom
  sites, driver-reproduced); the
  locale-pressure half skips
  visibly on manufacture failure;
  and the exception-family pin sees
  subdirectories (recursive
  file-set derivation, still
  composing with the
  one-type-per-file gate). The
  round's two-lens verifier pass
  (152 driven tool calls, every fix
  re-driven red/green) raised 2
  distinct confirmed findings plus
  one dead-code note — all three
  fixed in-round (ocr6-14/15/16),
  ZERO refuted; one finding's stated
  mechanism corrected by the driver
  in-commit (the collector-refusal
  legs fail as a confusing red
  through the RuntimeException-
  swallowing catch on incapable
  hosts, never a green pass — the
  probe+skip fix is the same either
  way). Suite 1623 → 1627 tests,
  45028 → 45068 assertions, 2
  skipped unchanged.
OCR-tool round
  t31-ocr7 (nine commits — the
  seventh pass, union coverage
  complete, 8/8 findings accepted,
  6 commits + 3 verifier-pass
  fixes): the ocr4-5 braced-
  namespace expiry defect class
  REPRISED in the sibling
  declaration ledger — the
  DETECTOR's resolution walk kept
  a braced block in effect to EOF
  while the rewriter's ledger
  (fixed in r4) refused the same
  bytes; the ledger is ONE shared
  owner now
  (wp_connectors_namespace_
  declaration_ledger() +
  wp_connectors_declaration_in_
  effect() in plugin-tools.php,
  verbatim extraction), detector
  and rewriter both ride it — the
  class lesson ledgered: sweep the
  CLASS, not the site. Legal
  import spellings the use
  pattern's byte grammar cannot
  see (comma lists, close-tag
  termination, comments inside)
  refuse NAMED at the postcondi-
  tion's throw path (green builds
  pay nothing), with the verifier
  pass's two additions: the
  TRAIT-CONTEXT carve (a use
  inside a non-namespace block is
  a trait clause list, NO class —
  the r10-1 doctrine's own
  refusal) and the CASE-VARIANT
  axis (USE keyword, case-variant
  family prefix) refusing named —
  owning it would flip the
  r7-pinned refuse doctrine. The
  bare-keyword fence's follower is
  code-adjacent (a mode boundary
  ENDS the scan; the lens's
  verdict-diff killed a braced-
  global-block-behind-a-boundary
  exit-0 ship for free).
  copyTree() refuses missing/file
  sources and self/nested targets
  before iterating (mechanisms
  PROBED and corrected in-round
  over both lenses' convergent
  refutations: the self-copy is a
  silent no-op success on this
  engine, the nested copy one
  self-polluting duplication —
  never truncation, never
  unbounded). The PSR-4 gate
  derives the namespace from the
  one owner. The OTP-class tail
  pins derive from the actual
  values (the '3502' channel
  literal collided with the
  device code's own random mask
  tail at 1/65,536). The round's
  two-lens verifier pass (127
  driven tool calls, both lenses
  driven at both ends) raised 3
  accepted findings, all fixed
  in-round (ocr7-7/8/9), ZERO
  refuted; one named residual
  ledgered (declaration-position
  unowned spellings still
  anonymous — the next round's
  candidate). Suite 1627 → 1628
  tests, 45068 → 45095
  assertions, 2 skipped
  unchanged.
OCR-tool round
  t31-ocr8 (fifteen
  commits — the eighth pass,
  main + two fill-ins, union
  coverage complete, 15/15
  findings accepted, 11 fix
  commits + 3 verifier-pass
  fixes + the docs): the
  fail()-inside-catch masking
  class swept to ONE owner —
  refusalOf() hoisted on
  WpConnectorsTestCase
  (collect inside, fail on
  no-throw OUTSIDE, caller
  asserts fragments on the
  returned verdict), 72 sites
  round one and 25 more the
  verifier's refutation lens
  found under the census's
  own tokenizer blind spot
  (PHP 8 tokenizes
  \RuntimeException as
  T_NAME_FULLY_QUALIFIED —
  the FQ catch spelling was
  invisible to a T_STRING
  census; four sites
  partially masked TODAY,
  two at the widest
  catch (\Throwable)
  spelling) — the double
  class lesson ledgered:
  sweep the SHAPE, and
  match every spelling the
  engine tokenizes; a
  census claiming
  completeness needs an
  independent re-derivation.
  The link-probe class has
  ONE owner
  (link_probe_spelling():
  trailing '/' AND '/.'
  both force stat through
  a final link — both
  spellings driven red
  both rounds; '/..' is
  out, it names the
  parent); copyTree's
  containment sees through
  the '..'-woven and
  symlinked-ancestor alias
  class via the nearest
  EXISTING ancestor
  (degenerate '/'/''
  targets accepted
  without fix — no
  caller, ledgered). The
  exceptions family's
  payload audit pins
  declaring-class,
  IS_PUBLIC, and the
  DECLARED property set
  exactly (print_r dumps
  non-public properties
  raw — driven). lint-php
  consumes its OWN named
  exclusion subset (the
  vocabulary ride silently
  cut nested tests trees
  from coverage). Secret-
  Mask's UTF-8 grammar is
  ONE spelling and the
  collapse REFUTED the
  regex twin (its F4
  quantifier demanded
  five bytes: the invalid
  beyond-U+10FFFF shape
  accepted — mask() could
  ship invalid UTF-8 —
  and the valid
  U+100000 plane shed to
  the bare mask); the
  walk matches the engine
  on every disagreement
  class (both lenses'
  corpora, 220k+ cases).
  The round's two-lens
  verifier pass (222
  driven tool calls):
  correctness 11/11 HOLDS
  (one prose miscount
  corrected in the round
  record), refutation 4
  findings — 3 fixed
  in-round (ocr8-12/13/
  14), 1 accepted-
  without-fix, ZERO
  refuted. Suite 1628
  tests unchanged,
  45095 → 45129
  assertions, 2 skipped
  unchanged.
OCR-tool round
  t31-ocr9 (twelve
  commits — the ninth
  pass, main 56/61 +
  the fill-in over
  tests/Zai, 10/10
  findings accepted,
  8 fix commits + 3
  verifier-pass fixes
  + the docs): r8's
  '/..' carve-out RE-
  FUTED by driven
  evidence — the
  parent it names is
  the LINK TARGET'S
  parent, so
  rrmdir('link/..')
  deleted THROUGH the
  link with a LARGER
  blast radius than
  the target and
  copyTree('link/..')
  copied the parent
  tree through it; the
  tail joins the stat-
  transparent family
  in link_probe_
  spelling() while
  same_directory_
  spelling() (the old
  body) stays the WALK
  spelling — a
  stripped '/..' names
  a different
  directory. THE
  CARVE-OUT RULE
  ledgered: carve-outs
  need DRIVEN
  justification, never
  naming arguments
  (r8's build.php
  no-caller conclusion
  re-driven and still
  holds). copyTree
  refuses the MIRROR
  containment (target
  CONTAINS source —
  driven: a nested
  same-name segment
  resolved the copy
  inside the tree
  being read), plus
  the verifier-driven
  ROOT collapse (a
  target resolving to
  '/' built the prefix
  '//' and passed both
  guards — the root,
  ancestor of every
  source, refuses as
  the universal
  container now).
  THE ROUND'S CLASS
  LESSON ledgered: the
  r8 refusalOf() sweep
  mechanically dropped
  the exception FAMILY
  each original catch
  enforced (a planted
  TypeError carrying
  the fragments kept
  the pin green —
  driven); refusalOf()
  pins the family its
  site's original
  catch declared (97
  sites: 94 family
  args from the r8
  DIFFS — the census
  source of truth,
  commit prose was
  wrong twice — + 3
  legitimate \Throwable
  defaults), and
  mechanical refactors
  must carry the
  ORIGINAL contract's
  full semantics,
  enforced by a
  census. The THIRD
  removal owner
  (bin/inspect-
  artifact.php's
  rrmdir) joined the
  link doctrine its
  siblings already
  carried (planted-
  link drive emptied
  the victim). The
  Retry-After future-
  claim class swept to
  its siblings (Re-
  freshPolicy's header
  and the exception's
  file header matched
  to the no-parser
  reality; parser =
  3.2, coordination =
  3.3 design intent);
  GrantState words the
  terminal classes as
  terminal-INTENT (the
  r2-3 adjudication:
  the VO is transition-
  permissive). Scanner
  prune walk skipped
  when pruning is off
  (constant guard,
  artifact path); rrm-
  dir's docblock
  attached to its
  declaration. The
  round's two-lens
  verifier pass (111
  driven tool calls):
  correctness 8/8
  HOLDS (97-site
  census re-derived
  exactly; one prose
  miscount — ocr9-6's
  message said 1628
  tests/45355, true
  1629/45357 —
  corrected in the
  round record),
  refutation 3 findings
  — ALL fixed in-round
  (ocr9-9/10/11), ZERO
  refuted. Suite 1628 →
  1629 tests, 45129 →
  45362 assertions, 2
  skipped unchanged.
  OCR-tool round
  t31-ocr10 (nineteen
  commits — the TENTH
  pass and the FIRST
  fully complete one,
  61/61 no fill-in,
  19/19 findings
  accepted, 14 fix
  commits + 4
  verifier-pass fixes
  + the docs):
  the FIRST fully-
  complete round, and
  the trajectory rule
  ledgered — 9→13→9→
  13→8→15→10→19 is
  NOT monotone because
  each round audits
  the PRIOR round's
  fixes and carries
  older doctrines to
  seams they hadn't
  reached; the loop
  converges when a
  round finds nothing
  NEW, and round 10
  did not (its own fix
  pass DROVE the next
  round's lead: the
  detector's PCRE /i
  text lens consults
  the active locale —
  under a manufactured
  tr_TR the /i stem
  finding over a case-
  variant family
  spelling drops,
  every fold-seam
  finding survives —
  ledgered with the
  repro). The round's
  own two high fixes:
  rrmdir() mirrors
  copyTree's ROOT
  clause (realpath ===
  '/' refuses, driven
  at both spellings —
  rrmdir('/') walked
  into unlink() over
  the filesystem root)
  and the inspector's
  extraction dir goes
  UNIQUE-OWNED
  (random-suffix mkdir
  — the planted-link
  WRITE half driven:
  extraction landed
  inside the victim
  tree through the
  pre-planted name;
  uniqueness pinned
  black-box through
  the NAME_MAX refusal
  naming the full
  extraction path).
  Also: the ocr1-1
  probe FORCES its
  subject (the refused
  spelling is now the
  grammar's refusal,
  negative
  microseconds, and
  the precondition is
  pinned assertFalse
  before the act); the
  family-verdict folds
  swept to the ASCII
  owner (r11-6) on
  every producer and
  consumer site; the
  shared zip reader
  gates open() on
  strict equality (the
  truthy ER_NOZIP=19
  drove the vacuous
  []); refusalOf()'s
  family parameter is
  REQUIRED (census
  zero, three omitting
  sites named); lint
  skips non-regular
  files (a *.php dir
  symlink passed php
  -l vacuously over a
  directory); a
  dev-entry top-level
  dir is never an
  embed territory
  (hostile
  'vendor/src/Shared'
  exempted wholesale,
  driven); copyTree
  refuses realpath-
  false and FILE-in-
  chain targets; three
  @return annotations
  widened to the
  pinned int|string
  shape; the t31-r12-
  20 structural pin is
  whitespace-normalized
  (its own class
  demonstrated LIVE —
  the ocr10-2 rename
  reddened it
  mid-round); proc_open
  gated + pipes reset
  per spawn; the
  symlink platform
  guards ride ONE
  capability-probe
  owner (canSymlink()
  on WpConnectorsTestC
  ase, the skip_on_
  root pattern).
  VERIFIER PASS (two
  lenses): correctness
  8/8 HOLDS (the 14-
  commit count chain
  re-derived by
  running the suite at
  EVERY commit; the
  refusalOf census re-
  driven at 99 calls /
  0 two-argument;
  exactly the two
  known miscounts, no
  others); refutation
  4 confirmed + 1
  trace — ALL fixed
  in-round as ocr10-
  15/16/17/18, ZERO
  refuted: r9's
  ledgered re-open
  condition FIRED
  (the inspector's
  rrmdir twin — the
  workDir is a PUBLIC
  parameter, and
  'link/.' emptied
  the victim past
  the r9 plain guard,
  driven; both
  sibling clauses
  now ride the twin,
  the root as a
  silent return),
  the truthy open()
  gate swept WRITE-
  side too (4 sites,
  the lens's 2 + the
  grep's 2), the
  ocr10-2 retry loop
  captures its own
  failure (16 raw
  mkdir warnings
  leaked, driven —
  the r12-19 doctrine
  one screen below),
  and the round's own
  canSymlink() probe
  gets a random-
  suffix name (its
  first cut planted
  a predictable pid
  name — the ocr10-2
  model on fresh
  code, traced). The
  lens also confirmed
  the /i lead END-TO-
  END (a comment-only
  stem SHIPPED in a
  real zip with the
  inspector ACCEPTED
  under an in-process
  tr locale; driven
  mitigator: PHP does
  NOT adopt env
  LC_CTYPE at startup
  — in-process
  setlocale required)
  — the next round's
  first finding, the
  repro in the
  ledger.
  Suite 1629 → 1634
  tests, 45362 →
  45417 assertions, 2
  skipped unchanged.
  Two prose miscounts
  corrected in the
  round record
  (ocr10-1 said 45369,
  true 45370; ocr10-2
  said 45380, true
  45379 — the
  correctness lens).
OCR-tool round
  t31-ocr11 (twenty-six
  commits — the
  eleventh pass, 61/61
  complete, 27/27
  findings accepted:
  the reviewer reached
  the group-use
  COMPOSITION internals
  and the suite's own
  HOST-PORTABILITY;
  19→27 is the loop's
  shape, not
  divergence — it
  converges when a
  round finds nothing
  NEW): a relative
  group-use member
  never composes with
  its prefix (the
  operator resolves
  against the DECLARED
  namespace; the
  verifier pass
  completed the guard
  — token-id
  relativeness in any
  case, a pending arm
  for the interrupted
  spellings, and the
  LEDGER never opening
  a declaration from a
  namespace keyword
  inside a use
  statement, the
  r8-10 corruption
  class on the round's
  own resolution
  owner); the
  root-collapse TEST
  legs are ROOT-
  ANCHORED, never a
  temp-parent '..'
  (deep-temp hosts
  made them
  destructive, the
  production guard
  correct — tests
  carry the
  portability doctrine
  too); the duplicate
  fence folds case +
  trailing edge-junk +
  segment collapse
  ('.'/empty segments
  name the same file
  at extraction,
  driven); the group
  fence arms on
  COMPOSITION (a body
  nothing composes
  reports its prefix,
  judged once);
  copyTree() judges
  its target through
  an ABSOLUTE
  spelling and
  refuses the
  degenerate targets
  the new walk
  un-refused (the
  ''-target
  filesystem-root
  regression, driven
  by the lens); the
  suite's hygiene
  class (strict
  reopen/extract
  gates, the battery
  artifact name
  derived from the
  fixture header,
  temp+random scratch
  roots, quote-bounded
  dev-entry pins,
  real fixture
  newlines); the
  spawn/link
  capability sweep
  completed (canSymlink()
  is ONE owner on
  WpHarness,
  function_exists-
  guarded — an
  ungated @symlink
  probe FATALS under
  disable_functions,
  the last inline
  twin driven and
  swept); boundary
  contracts enforced
  (zipEntryNames'
  string|false gate,
  the scanRoot
  absolute+inside+
  DIRECTORY
  validation, cli_args
  answering [] outside
  a CLI process
  through the symbol
  table); display and
  fold doctrines
  (derived refusal
  spellings, ASCII
  folds, every
  caller-path
  interpolation in
  the inspector on
  the printable seam
  — the CLI guard's
  full-path line once
  forged a verdict
  line, driven);
  rrmdir()'s
  realpath-false is
  the policy refusal.
  The round's two-lens
  verifier pass
  (every finding
  driven) re-derived
  the 26-commit count
  chain at EVERY
  commit (all green,
  deltas exact, five
  red-at-HEAD replays
  confirmed,
  message-vs-diff
  audit clean) and
  found the assertion
  figures each +1
  above the recorded
  claims, uniformly
  from the pre-round
  base (origin
  unisolated,
  ledgered as a
  trace); refutation
  confirmed 2 + drove
  4 traces — all six
  fixed in-round
  (ocr11-21..26), ZERO
  refuted, one named
  residual (the EOF-
  unterminated group
  statement — the
  next round's
  candidate). Suite
  1634 → 1637 tests,
  45417 → 45466
  assertions as
  measured (+1-offset
  trace applies), 2
  skipped unchanged.
OCR-tool round
  t31-ocr12 (eight
  commits — the
  twelfth pass,
  62/62 complete,
  10/10 findings
  accepted; the
  trajectory 27→10
  is the loop
  converging on the
  older doctrines'
  own seams — this
  round's findings
  are mostly
  residuals of prior
  rounds' fix
  classes): the
  canSymlink sweep
  completed, its
  LAST two inline
  @symlink probes
  fatal under
  disable_functions
  ridden by the ONE
  owner — and the
  round's recurring-
  class lesson
  LEDGERED: a sweep
  commits its CENSUS
  (grep pattern +
  full site list) in
  the commit message,
  never just the
  converted count
  (the sweep needed
  three passes before
  the tree read
  zero); copyTree's
  root-refusal
  symmetry closed as
  a TRIPLE (the
  SOURCE side now
  refuses a source
  whose realpath is
  the universal
  container — driven
  red at HEAD: 135
  root-tree entries
  landed in a
  scratch target
  before a symlink
  stopped the walk);
  the root-runner
  skip hoisted ABOVE
  the ZipArchive
  creation (never a
  throw over an open
  handle while the
  finally deletes
  its destination);
  the serializability
  ceiling guard
  respelled overflow-
  free (the
  subtraction runs
  on the constructor-
  bounded expires_in,
  the READING never
  an arithmetic
  operand; the deep
  corner pinned both
  sides); the two
  clean-direction
  fixture writes
  gated (empty
  content passed the
  gates VACUOUSLY);
  the corrupt-zip
  fixture name made
  unpredictable
  (random suffix over
  the pid, the
  ocr10-18 shape);
  RefreshPolicy's
  backoff_initial
  documented as the
  sequence's SEED
  (never a floor —
  the SPEC-checked
  adjudication: one
  shared cap,
  "retry immediately"
  honored as given,
  no caller exists,
  docblock-only).
  The round's
  two-lens verifier
  pass CLEAN on both
  lenses (zero
  findings, zero
  refuted). Suite
  1637 → 1638 tests,
  45466 → 45475
  assertions
  (measured; the r11
  +1 trace did not
  reproduce), 2
  skipped unchanged.
OCR-tool round
  t31-ocr13 (nine
  commits — the
  thirteenth pass,
  62/62 complete,
  11/11 findings
  accepted; the
  trajectory 27→10→11,
  residual level):
  the ASCII-fold
  class surfaced one
  more site-cluster
  (the build's
  collision fences
  and the vocabulary
  fold owner) and the
  ocr12 census
  doctrine rode the
  sweep — ONE commit
  converted every
  strcasecmp-feeding-
  verdict in bin/
  (five code sites:
  the finding's three
  plus the two
  anonymous-verdict
  label folds), the
  census in the
  commit message, the
  tree reading zero
  outside the ASCII
  owner; the
  locale-driven red
  honestly recorded
  as NOT producible
  on this 8.5.10
  engine (strcasecmp
  folds through the
  engine's ASCII
  table — the r11-6
  posture: spelling
  pins everywhere,
  pressure wherever
  the locale exists);
  the battery's
  symlink row rides
  needs_symlink
  (fatal → skip,
  driven under
  disable_functions);
  same_directory_
  spelling() collapses
  '/.'/'//' to the
  root they name (the
  silent rrmdir no-op
  now rides the loud
  ocr10-1 root
  refusal naming the
  CALLER's spelling);
  the localedef exec
  capability-probed
  (undefined-function
  fatal → visible
  skip, both
  pre-existing
  sites); the strict-
  open class closed
  over 28 ZipArchive
  sites in
  BuildArtifactsTest
  (census committed);
  classifyClean()
  speaks FAIL rows
  only (planted-extra
  drive: row verdict,
  battery completes);
  two dead $refused
  initializations
  dropped; the
  still-fails lint
  controls hoisted
  above the
  canSymlink skip;
  copyTree's @param
  words the relative-
  target truth. The
  round's two-lens
  verifier pass CLEAN
  on both lenses for
  fix behavior (zero
  findings, zero
  refuted; two
  reportable items
  ledgered — the
  ocr13-5 census
  figures corrected
  to 31/3/28 at the
  conversion parent,
  and the ocr13-6
  catch width as the
  named residual).
  Suite
  1638 → 1639 tests,
  45475 → 45516
  assertions (every
  per-commit delta
  exact, re-measured
  at every commit;
  the r11 +1 trace
  did not reproduce —
  the base measured
  exactly 45475), 2
  skipped unchanged.
OCR-tool round
  t31-ocr14 (seven
  commits — the
  fourteenth pass,
  62/62 complete,
  7/7 findings
  accepted; the
  trajectory 27→10→
  11→7): the round's
  substantive
  SECURITY close is
  the masked_view URI
  leak — the device
  session rendered
  the RAW provider
  verification_uri
  into every masked
  channel while
  parse_validated()
  accepts userinfo
  and query by
  design, so a
  credential-carrying
  URI leaked in
  cleartext; the view
  now renders the
  scheme://authority/
  path rebuild
  (redacted_url()'s
  own seam), the
  raw property stays
  for the redirect,
  and the ledger
  carries the 3.2
  one-owner note
  (extract
  Url::redacted() at
  the THIRD rider).
  The round also
  carries the loop's
  first REFUTED
  driver finding:
  the merge-contract
  claim (bug:medium)
  did not survive
  contact with the
  tree — every leg
  probed deliverable,
  the pins green at
  HEAD — committed as
  the derivation plus
  the two missing
  legs (null-on-null,
  whitespace-only
  rejection). The
  manifest prune
  splits at the
  WRITER's separator
  from the right (a
  double-space entry
  name survives); the
  lint below-root
  offset rides the
  sibling's rtrim
  spelling (the
  trailing-separator
  root ate the first
  byte of every first
  segment — the pin
  drives the excluded
  tree AS the first
  segment); the type-
  declaration
  vocabulary is ONE
  const owner with
  the extension-owner
  site CALLING the
  gate; the clock
  port's now()
  contract states
  absolute time
  (worded inside the
  architecture
  fences — the first
  spelling tripped
  both, docblocks are
  scanned prose);
  HeaderMap's
  property annotation
  matches the
  ocr10-11 int|string
  contract. The
  round's two-lens
  verifier pass CLEAN
  on both lenses for
  fix behavior (zero
  fix findings, zero
  surviving
  counterexamples;
  two latent
  pre-existing
  residuals ledgered
  — the manifest
  prefix-skip edge,
  a readonly-blind
  pattern in one Zai
  test). Suite
  1639 → 1643 tests,
  45516 → 45543
  assertions (every
  per-commit delta
  measured from
  output), 2 skipped
  unchanged.
OCR-tool round
  t31-ocr15 (eight
  commits — the
  fifteenth pass,
  62/62 complete,
  11/11 findings
  accepted; the
  trajectory 27→10→
  11→7→11): the
  round's substantive
  SECURITY close is
  the sensitive-name
  CLASS rule — the
  closed six-spelling
  catalog let
  'api-key' (the
  documented auth
  header of a whole
  cloud-AI vendor
  class, an
  archetypal 3.7
  transport binding)
  render its full
  secret verbatim
  while 'x-api-key'
  sat covered (the
  r12-4 leak class
  reopened); the
  policy now judges
  any folded name
  that IS a
  credential token,
  or whose final
  hyphen-token is
  one (api-key,
  subscription-key,
  auth-token, auth
  — the boundary is
  the hyphen), with
  NO extension seam
  (3.7's decision,
  ledgered). The
  expectation domain
  joined save()'s
  contract (below
  EXPECT_NO_GRANT is
  a caller typo,
  rejected typed —
  never a silent
  false to retry
  on); the
  is-a-php-source
  fold rides the
  ASCII owner (the
  census leaves
  THREE live folds —
  the scanner's
  extension gate and
  the two
  plugin-header key
  folds — so the
  ocr13-1
  class-terminal
  promise stays
  open); the grant
  serialize pin
  names WHICH guard
  fires (nested
  members rebuild
  before the
  enclosing hook —
  the set-less
  shape is the leg
  that reaches the
  grant's own);
  refusalOf() is ONE
  static on WpHarness
  (hoisted over the
  wrapper — the
  plain-TestCase
  suites ride it —
  and the '$caught =
  null' census reads
  zero); the README
  leg, the burner,
  and the offender
  loop carry loud
  existence/write
  gates; the '/..'
  control's scratch
  pair rides a
  finally. The
  round's two-lens
  verifier pass CLEAN
  on both lenses for
  fix behavior (zero
  fix findings, both
  behavior reds
  re-reproduced on a
  scratch worktree;
  a 36-probe
  boundary battery
  over the suffix
  rule — zero
  surviving
  counterexamples).
  Suite
  1643 → 1645 tests,
  45543 → 45385
  assertions (every
  per-commit delta
  measured from
  output; the −221
  at ocr15-7 is the
  family check's
  spelling moving
  from
  assertInstanceOf
  to the owner's
  identical
  instanceof
  verdict — no pin
  lost), 2 skipped
  unchanged.
OCR-tool round
  t31-ocr16 (twenty
  commits — the
  sixteenth pass,
  62/62 complete,
  33/33 findings
  accepted, raw 33
  but ~15 distinct
  classes: 9
  ungated-write
  sites + 3
  staging leaks +
  2 exec guards +
  5 doc-glitch
  singles inflate
  the count; the
  trajectory
  27→10→11→7→11→33
  is the audit
  shape — the
  round censused
  the test-hygiene
  classes prior
  doctrines named
  but never drove
  to zero): the
  substantive
  closes are the
  security:high
  edge-junk '..'
  bypass (the
  traversal
  refusal now owns
  every spelling
  that RESOLVES to
  '..' — trailing
  junk stripped,
  dots-only runs of
  two or more
  counted, twelve
  boundary
  spellings
  unit-probed, the
  pre-fix zip
  judged at 0
  traversal
  violations), a
  REFUTED test:high
  premise (the
  var_export enum
  pin is green on
  every supported
  engine — the 8.1
  defect was the
  missing
  backslash,
  fixed in 8.2.0,
  the project
  floor; the
  engine premise
  is now pinned as
  itself), and
  three bug:medium
  (the classify
  Clean reopen
  gate hoisted to
  own the
  corrupt-artifact
  FAIL row, its
  ValueError
  close dead; the
  relative-use
  tail riders
  judged through
  the lexer's own
  boundaries with
  the comma list
  green and the
  keyword-alias
  vocabulary
  php-l-derived;
  the ancestor
  walk's '/'
  sentinel
  refusing before
  the iterator).
  The round's TWO
  REFUTED PREMISES
  derived before
  any fix
  (var_export
  throws on enums;
  by-value use
  captures mutate
  across calls —
  both false,
  probed, ledgered
  with re-open
  rules). The
  two-lens
  verifier pass:
  refutation
  caught ONE
  surviving
  counterexample
  in the round's
  OWN tail gate
  (keyword aliases
  lexing as plain
  T_STRING) and
  the closure's
  $tail_display
  collision
  (silently
  dropping every
  aliased import's
  last below-root
  segment), both
  closed
  in-round;
  correctness
  re-drove every
  red at the
  pre-fix sources
  and ran a
  6-probe boundary
  battery — zero
  false refusals,
  zero fix
  findings. Suite
  1645 → 1650
  tests,
  45385 → 45446
  assertions
  (every per-
  commit delta
  measured from
  output; the
  class-only
  commits moved
  zero by
  construction), 2
  skipped
  unchanged.
OCR-tool round
  t31-ocr17
  (ELEVEN
  commits — the
  seventeenth
  pass, 62/62
  complete, 9/9
  findings
  accepted;
  trajectory
  27→10→11→7→
  11→33→9, the
  round-16
  spike's census
  held): the
  substantive
  close is the
  LANDING-policy
  hole in two
  layers — the
  ocr16-5
  sentinel
  judged the
  SPELLING'S
  chain, so a
  '..'-woven
  target
  anchoring at
  an existing
  component
  collapsed
  elsewhere and
  died in raw
  first-level
  warnings
  having
  returned
  normally
  (driven; the
  collapsed
  resolution
  walked by
  the
  sentinel's
  own rule);
  the round's
  two-lens
  verifier
  pass (a
  two-agent
  workflow)
  then DROVE a
  counterexample
  through that
  first close
  — the
  collapse was
  still LEXICAL
  and a '..'
  popping above
  the resolved
  anchor
  crosses
  symlinks
  nothing
  resolves,
  the copy
  landing
  INSIDE the
  source while
  the plain
  spelling
  refuses —
  closed
  in-round by
  the
  resolve-until
  -stable loop:
  every
  judgment
  (sentinel,
  crossings,
  containment,
  landing)
  rides the
  PHYSICAL
  resolution,
  termination
  construction
  -evident.
  Doctrine:
  destructive
  legs pin
  their REFUSAL
  PRECONDITION
  (ceiling
  ledgered —
  the pin sees
  spelling
  drift, never
  guard
  regression;
  realpath('')
  answers the
  CWD, the
  driven
  discovery
  behind the
  ocr11-22
  lexical
  judgment).
  The verifier
  also drove
  the exec
  guard's
  unprobed
  spawn
  function
  (proc_open;
  closed
  in-round).
  Residuals
  ledgered: the
  production
  rrmdir twins'
  pre-fix
  probes, 17
  ungated exec
  consumers,
  the
  wpct-locale
  pid trio, the
  symlinked
  -temp-host
  portability
  note. Suite
  1650 → 1651
  tests,
  45446 →
  45493
  assertions
  (every
  per-commit
  delta
  measured
  from output),
  2 skipped
  unchanged.
OCR-tool round
  t31-ocr18
  (SIX commits —
  the
  eighteenth
  pass, 62/62
  complete, 9/9
  findings
  accepted,
  classes
  folded (×3
  exec-gate
  sites one
  commit, ×3
  staging/
  spawn heads
  one);
  trajectory
  27→10→11→7→
  11→33→9→9 —
  the
  plateau):
  the round's
  substantive
  item is the
  never-green
  data row and
  ITS HISTORY —
  premise
  REFUTED at
  birth (the
  'sydney
  fall-back'
  DST row born
  consistent
  in 7c6a754,
  never edited
  since, runs
  green every
  build — no
  skip flag,
  no provider
  filter; the
  quoted
  '01:00:00'
  is a
  spelling the
  row never
  carried)
  while the
  derivation
  found what
  seventeen
  green runs
  had hidden:
  HALF THE ROW
  SET never
  crossed its
  transition
  (a fall-back
  transition
  sits one
  ambiguous
  wall hour
  after any
  unambiguous
  pre-
  transition
  reading, so
  the 3600s
  fall-back
  lifetimes
  ended short
  of every
  transition,
  and the
  delta
  assertion is
  zone-
  independent
  arithmetic —
  a window
  with no
  transition
  exercises no
  transition)
  — closed:
  fall-back
  lifetimes
  3600→7200,
  expiry
  constants
  re-derived,
  and a
  per-row pin
  asserts the
  crossing
  premise
  (reading
  offset ≠
  expiry
  offset; a
  stale tzdata
  reddens the
  premise
  itself); the
  pin's
  ceiling (a
  transition
  exactly AT
  the expiry
  instant — no
  current row
  is there)
  ledgered.
  The r17-
  named
  exec-gate
  tail
  converted
  (five tests
  gated, both
  disable
  directions
  driven, the
  pre-fix
  fatal driven
  first); the
  census
  remainder is
  15 sites,
  ALL in
  BuildArtifacts
  Test (the
  17-site
  census's
  '7 in
  BuildArtifacts'
  column was
  the seven
  php -l
  loops —
  partial,
  not wrong);
  staging
  moved inside
  the try (a
  failed copy
  leaked the
  scratch
  tree); both
  spawn loops
  collect-
  before-
  assert (the
  ocr4-7
  doctrine on
  the SPAWN
  loop — a
  mid-loop
  assertion
  aborted with
  earlier
  children
  still
  writing
  under the
  finally's
  rrmdir); Url
  rides
  HeaderMap's
  control-byte
  callable and
  the masked
  debug twin
  extracts to
  one private
  owner — both
  byte-
  identity
  md5-verified
  by the
  verifier.
  Two-lens
  verifier
  pass (both
  lenses
  driven): ZERO
  surviving
  counter-
  examples
  (every DST
  row
  re-derived,
  the census
  re-counted
  site by
  site); one
  PRE-EXISTING
  ±1 ledgered
  (a
  conditional
  cleanup
  assertion
  keyed on
  leftover
  dist/
  checksums.
  txt runner
  state —
  every round
  delta exact
  in either
  state;
  re-open when
  absolute
  totals
  become
  load-
  bearing).
  Suite 1651
  tests,
  45493 →
  45499 →
  45493
  assertions
  (every
  per-commit
  delta
  measured
  from output:
  +6 the
  crossing
  pins, 0 the
  guards by
  construction,
  −6 the
  spawn-loop
  restructure),
  2 skipped
  unchanged.
OCR-tool round
  t31-ocr19
  (FIVE commits —
  the nineteenth
  pass, main
  57/62 + bin/
  fill-in 7/7
  (union
  complete),
  4/4 findings
  accepted;
  trajectory
  27→10→11→7→
  11→33→9→9→4 —
  the decline
  holds, bin/
  read zero in
  the fill-in):
  the round's
  substantive
  close is the
  LINK PROBE
  ANCHOR
  (t31-ocr19-2,
  bug:medium,
  the round-17
  portability
  residual
  closed) — the
  ocr17-2
  full-chain
  walk judged
  every
  component
  from '/', so
  on a host
  whose temp
  spelling
  crosses a
  system-layout
  link (macOS
  /var, /tmp)
  the probe
  named the
  HOST'S link
  for every
  temp-rooted
  path —
  rrmdir
  silently
  skipped all
  cleanup,
  copyTree
  false-refused
  every
  source; the
  walk anchors
  at the temp
  root now
  (components
  of the temp
  spelling are
  host layout,
  everything
  strictly
  beneath
  keeps the
  full-chain
  reach),
  driven red
  then green
  in a
  redirected-
  TMPDIR child
  engine (the
  macOS sim —
  sys_get_
  temp_dir()
  is cached
  per process,
  so the shape
  needs a
  fresh
  engine;
  planted
  links below
  the anchor
  keep the
  doctrine).
  The round's
  REFUTATION
  of record
  (t31-ocr19-1,
  the round-18
  precedent):
  the accepted
  premise —
  omitted-flags
  ZipArchive::
  open()
  CREATES an
  empty
  archive on
  the 8.2
  floor — was
  refuted at
  three levels
  (a real
  8.2.33/libzip
  engine
  returns
  ER_NOENT and
  creates
  nothing,
  re-driven
  independently
  after the
  pass; the
  php-src
  stubs and
  UPGRADING
  know no 8.3
  default
  change; the
  strict gate
  was already
  loud
  everywhere)
  — the
  explicit
  RDONLY flag
  stays as
  pinned
  read-only
  intent, the
  missing-
  archive pin
  holds the
  loud
  contract,
  the 13-site
  census is
  re-framed as
  style
  variance,
  and the two
  docblocks
  the first
  commit wrote
  in the false
  premise are
  corrected in
  the docs
  commit. Two
  small pins:
  the device
  session's
  happy path
  round-trips
  the poll
  credential
  (ocr19-3),
  and the
  refusal
  owner's
  family-
  mismatch
  verdict
  chains the
  original
  exception —
  same
  instance on
  getPrevious
  (), the
  chain not
  the render
  (PHPUnit 9's
  __toString
  strips the
  previous
  chain;
  ocr19-4).
  Two-lens
  verifier
  pass (both
  lenses
  driven):
  correctness
  4/4, zero
  findings
  (edge-shape
  reflection
  battery,
  17-shape
  hostile
  spelling
  battery
  byte-
  identical
  pre vs
  post off
  the
  symlinked-
  temp class);
  refutation
  lens's one
  surviving
  finding IS
  the ocr19-1
  refutation
  above;
  ocr19-2/3/4
  confirmed
  (incl. the
  '..'-pop-out
  attack and
  the
  above-root
  escape —
  both still
  name their
  links).
  Suite 1651 →
  1652 → 1653
  → 1654
  tests,
  45493 →
  45497 →
  45504 →
  45505 →
  45508
  assertions
  (every
  per-commit
  delta
  measured
  from output),
  2 skipped
  unchanged.
OCR-tool round
  t31-ocr20
  (ELEVEN
  commits —
  the twentieth
  pass, 62/62,
  12/12
  findings
  accepted;
  trajectory
  27→10→11→7→
  11→33→9→9→4→
  12 — the
  count
  RE-SPIKES on
  NEW ground:
  the r16
  edge-junk
  class at a
  NEW seam,
  the
  value-lens
  backslash
  twins a new
  class; the
  round's
  lesson: a
  class closed
  at one fence
  is not
  closed at
  the next —
  the census
  doctrine
  must name
  the FENCE,
  not just
  the shape):
  the headliner
  is the
  NEAR-SOURCE
  EXTRACTION
  FENCE
  (t31-ocr20-1,
  security:
  high) —
  is_php_
  source()
  judges the
  last four
  bytes, so
  'shell.php ',
  'shell.php.',
  'shell.php\
  x01' entries
  were PHP
  sources to
  every
  path-
  normalizing
  host while
  every gate
  judged them
  as not one:
  extracted,
  unlinted,
  ACCEPTED at
  0 violations
  (driven red
  at HEAD) —
  the fence
  refuses the
  artifact
  whole BEFORE
  extractTo(),
  per-segment,
  raw lens
  first, fold
  lens second,
  riding the
  ONE
  near-source
  predicate
  the
  verifier
  pass
  extracted
  (both
  channels —
  the
  collector's
  throw and
  the
  inspector's
  refusal);
  two engine
  facts
  pinned on
  the way:
  an un-flagged
  \x01 name
  byte never
  survives
  this
  engine's
  libzip
  reader
  (CP437 remap
  to U+263A —
  driven; the
  byte-exact
  spelling
  carries the
  UTF-8 flag
  bit, driven)
  and the
  census's
  tenth site
  found by
  the verifier
  (the
  glob('*.php')
  main-file
  discovery —
  UNFENCED,
  repo-side
  class,
  ledgered).
  The round's
  REFUTATION
  OF RECORD:
  the
  superglobal
  /i premise
  (t31-ocr20-6)
  — the
  pattern
  carries NO
  flag; its
  case-
  insensitivity
  is scoped
  to the call
  stems
  through the
  inline
  (?i:...)
  group since
  birth
  (t31-r2-7),
  driven at
  engine,
  bytes, and
  history —
  but the
  docblock's
  contract
  was pinned
  by NO row;
  the three
  lowercase
  twins join
  the battery
  (construction).
  The two
  detector
  classes:
  the alias
  skip's
  qualifiedness
  composes
  the
  relative
  arm
  (t31-ocr20-2
  — an
  interrupted
  alias-slot
  relative
  with a
  single-
  segment tail
  was silently
  eaten while
  resolving
  into the
  family; the
  driver's
  glued
  example
  already
  reported,
  one spelling
  off the
  hole, driven
  both ways),
  and the
  value lens
  folds the
  leading
  backslash
  (t31-ocr20-3
  — a literal
  whose VALUE
  is the
  fully-
  qualified
  family name,
  the
  escape-
  produced
  backslash
  the text
  lens cannot
  see, both
  lenses blind
  at zero
  references,
  driven). The
  test-class
  commits:
  the
  root-collapse
  preconditions
  (ocr20-4),
  the exec
  gates +
  battery-skip
  narrowing +
  spawn anchor
  (ocr20-5,
  seven
  CLI-build
  sites the
  census
  remainder)
  with two
  record
  corrections
  from the
  verifier
  (the 482
  figure was
  the
  seven-test
  filter
  total, the
  battery
  alone 23;
  the
  remainder
  line
  numbers
  were
  pre-commit
  spellings).
  Two-lens
  verifier
  pass (a
  two-agent
  workflow,
  both lenses
  driven):
  correctness
  lens's one
  finding
  FIXED (the
  one guard
  whose skip
  stranded
  the
  extraction
  tree —
  finally-
  owned now,
  driven);
  refutation
  lens's
  three —
  the census
  correction
  (ledgered),
  the
  one-predicate
  extraction
  (FIXED,
  byte-
  identical
  through
  both
  channels),
  the figures
  correction
  (ledgered);
  its
  re-derivations
  confirmed
  every other
  claim (each
  new pin
  driven red
  under a
  revert).
  Suite 1654 →
  1655 tests
  (one new:
  the
  near-source
  extraction
  battery),
  45508 →
  45534
  assertions
  (+7 the
  fence, +7
  the
  alias-slot
  battery, +6
  the
  value-lens
  legs, +3
  the
  preconditions,
  +0 the
  guards by
  construction,
  +3 the
  case rows,
  +0 the
  verifier
  fixes),
  2 skipped
  unchanged.
OCR-tool
  round
  t31-ocr21
  (EIGHT
  commits —
  the
  twenty-first
  pass, 62/62,
  8/8 findings
  accepted;
  trajectory
  27→10→11→7→
  11→33→9→9→4→
  12; the
  round's
  lesson: an
  engine-matrix
  premise is a
  VENDOR-RECORD
  claim —
  the phantom
  "php.net
  8.3.0 chip"
  on RDONLY
  survived
  driver
  triage AND
  the fix
  commit
  because
  nobody
  fetched the
  record until
  the
  verifier;
  drive
  version-history
  premises at
  the
  stub/constants
  page BEFORE
  the fix
  lands): the
  headliner's
  premise
  REFUTED by
  both lenses
  independently
  (RDONLY is
  7.4.3/PECL
  zip 1.17.1
  when zip is
  built
  against
  libzip >=
  1.0.0 —
  the PHP-8.2
  stub
  registers it
  identically,
  and the
  repo's own
  r19 drive
  ran the bare
  constant on
  8.2.33 to
  ER_NOENT,
  never
  \Error); the
  guarded
  spelling
  stands as
  the libzip <
  1.0.0 build
  corner's
  guard
  (verdict-identical
  elsewhere,
  driven), the
  three pinned
  texts
  corrected
  in-round,
  the shape
  pin's
  no-escape
  claim driven
  (four
  escapes
  dead). The
  test-fence
  exec census
  closes
  (ocr21-2):
  the
  r20-named
  seven-site
  remainder
  gated across
  six tests
  (driven:
  visible
  skips under
  both flags);
  census
  corrected
  (rd-2): 20
  own spawn
  sites, not
  22 (a
  command-build
  line
  double-counted),
  all behind
  probes at
  the TEST
  fence —
  but "in the
  suite" dies
  at the
  PRODUCER
  fence:
  inspect-artifact's
  syntax loop
  exec()s
  in-process,
  21 suite
  errors under
  the flag —
  the r20
  fence lesson
  applied to
  the round's
  own census;
  the
  producer-side
  gate is the
  next round's
  head.
  ocr21-3 +
  sc-1: the
  spawn
  verdict
  moved inside
  the try,
  then the
  verifier
  closed the
  warn-then-false
  arm
  (failOnWarning
  throws at
  the call,
  pre-assignment;
  the finally
  read an
  unassigned
  $live and
  its error
  unwound past
  the rrmdir)
  — $live
  null-initializes
  now, driven
  both shapes.
  ocr21-4: the
  capability
  guard's ONE
  owner (the
  WpHarness
  canSpawnChildren
  owner + the
  wrapper, the
  canSymlink
  hoist shape;
  the variadic
  extra for
  the proc
  trio; the
  messages at
  the sites)
  — 24
  conversions,
  the commit's
  arithmetic
  corrected
  (rd-3); the
  only raw
  spelling
  left is the
  owner body.
  ocr21-5: the
  separator
  pins
  (fragments
  built with
  DIRECTORY_SEPARATOR
  the way the
  producers'
  iterator
  pathnames
  spell them;
  creation
  paths and
  zip-entry
  fragments
  stay
  literal).
  Two-lens
  verifier
  pass: sc-1
  FIXED
  (driven both
  shapes),
  rd-1 FIXED
  (both
  lenses'
  refutation),
  rd-2/rd-3
  RECORD;
  everything
  else stood
  (the guard
  placements,
  the census
  zero at the
  test file,
  the owner
  sweep, the
  separators,
  every green
  figure
  re-run).
  Suite 1655
  → 1656
  tests (one
  new: the
  shape pin),
  45534 →
  45535
  assertions
  (+1 the pin,
  +0 the rest
  by
  construction),
  2 skipped
  unchanged.
OCR-tool
  round
  t31-ocr22
  (ELEVEN
  commits —
  the
  twenty-second
  pass, 62/62,
  7/7 findings
  accepted;
  trajectory
  27→10→11→7→11→33→9→9→4→12→8→7;
  the round's
  shape:
  test-fence
  order-dependence
  and
  silent-residue
  classes,
  zero
  shared/src
  findings —
  the
  production
  core's
  silence
  continues;
  the round's
  lesson: this
  round's
  fabrications
  were of
  NARRATION,
  not premise
  — an
  "established
  idiom"
  citing what
  is the
  repo's FIRST
  platform
  gate, a leak
  mechanism
  whose own
  parentheticals
  the drive
  refuted, a
  five-that-was-seven
  leg count —
  drive the
  CITATIONS
  and the
  ARITHMETIC,
  not just the
  premise):
  the
  headliner —
  the symlink
  battery's
  SEVEN
  refusal legs
  and one
  nothing-landed
  pin rode one
  SHARED
  target while
  the in-tree
  link
  refusals
  fire
  mid-walk
  (entries
  yielded
  before the
  link
  legitimately
  land), so
  the
  file-shape
  leg's
  residue
  failed the
  mid-path
  leg's pin on
  yield-order
  hosts
  (driven both
  ways; a
  fresh uniqid
  target per
  leg now,
  order-proof
  by
  construction);
  the platform
  gate — five
  root-anchored
  leg-groups
  (the a-root
  pair, the
  degenerate
  targets with
  their safety
  net, the
  root
  sentinel,
  the landing
  sentinel,
  the mirror
  root) moved
  byte-identical
  into their
  own
  DIRECTORY_SEPARATOR-gated
  battery
  (both
  lenses'
  multiset
  comparisons:
  nothing
  lost,
  nothing
  altered, the
  set exactly
  the
  root-anchored
  class); the
  harness leak
  — rrmdir's
  final rmdir
  targeted a
  resolution
  through a
  component
  the walk
  itself
  removed (a
  real
  parent/sub/..
  tree leaked
  its top
  directory
  per call,
  driven at
  HEAD; the
  finding's
  parentheticals
  REFUTED: the
  walk empties
  ALL
  children,
  and the
  warning is
  unsuppressed
  under
  failOnWarning
  — the shape
  was never
  silent
  in-suite,
  merely never
  reached),
  fixed to the
  pre-walk
  collapsed
  spelling,
  then closed
  WHOLE at the
  verifier
  from BOTH
  sides
  independently
  — the walk's
  item
  pathnames
  carried the
  same
  dead-resolution
  class (the
  yield-order
  shape
  stranding
  the
  siblings,
  the double
  tail
  stranding
  under every
  harness; the
  walk builds
  on the
  collapsed
  root now, no
  pathname
  carries a
  consumable
  .. at any
  yield order,
  the spelling
  matrix
  re-driven
  byte-identical
  for every
  plain
  class); the
  guard triple
  — the TMPDIR
  sim's child
  fatals at
  its
  premise-critical
  first
  putenv() on
  putenv-disabled
  hosts
  (driven),
  the variadic
  declares it,
  and the
  verifier
  drove the
  three
  locale-pressure
  siblings
  with the
  same
  parent-side
  putenv
  (three
  visible
  skips under
  the flag,
  zero errors;
  the
  NON-SPAWN
  putenv
  consumer at
  BuildArtifactsTest:4564
  carried as a
  decision-plus-fence
  residual,
  the next
  round's
  head); the
  three small
  pins — the
  relative
  scan-root
  arm pins
  both named
  paths, the
  payload-API
  allow sets
  grant
  retry_after_seconds
  to the
  rate-limit
  type ALONE
  (mutation-driven:
  a wrong
  grant passed
  green under
  the old
  family-wide
  sets), and
  the
  never-created
  zip pin
  judges the
  state BEFORE
  the
  finally's
  unlink (a
  creating
  helper
  passed the
  old
  post-unlink
  pin green,
  driven).
  Suite 1656 →
  1657 tests
  (+1 the
  POSIX
  battery),
  45535 →
  45541
  assertions
  (+1 the
  survival
  pin, +2 the
  message
  pins, +1 the
  dotdot leg,
  +2 the
  verifier's
  order and
  double-tail
  legs; +0 the
  fresh-target
  factory, the
  gate, the
  guards, the
  sets, and
  the
  relocation),
  2 skipped
  unchanged.
OCR-tool
  round
  t31-ocr23
  (ELEVEN
  commits —
  the
  twenty-third
  pass, 62/62,
  9/9 findings
  accepted;
  trajectory
  27→10→11→7→11→33→9→9→4→12→8→7→9;
  the round's
  shape: the
  SCANNER
  SIDE
  finally
  under the
  lens —
  teardown
  masking,
  silent-return
  contracts,
  failure-channel
  discipline,
  fence-pair
  consistency
  — zero
  shared/src
  findings;
  the driver's
  own "FOUR
  bin/
  findings"
  corrected
  at the docs
  pass: SIX
  of the
  nine lived
  under bin/
  — the
  r21 rd-3
  arithmetic
  class in
  the
  driver's
  mouth):
  build's
  finally
  teardown
  never
  masks the
  primary
  (the
  unguarded
  rrmdir
  walk, its
  SPL throw
  REPLACING
  the
  in-flight
  refusal —
  silent
  contract
  now); the
  inspector
  twin's
  walk
  honors its
  own
  docblock's
  silent
  return;
  the
  scan-root
  boundary
  throws the
  channel's
  own
  RuntimeException;
  the \u{}
  escape
  validates
  hex and
  magnitude
  before
  hexdec;
  the
  redirected-TMPDIR
  sim gates
  its POSIX
  premise
  (and the
  third
  consumer
  hoists
  WpHarness::
  isPosixHost(),
  the ONE
  platform-probe
  owner);
  the link
  probe
  anchors at
  the
  harness's
  own
  territory
  — temp
  spelling
  plus
  repository
  root, the
  planted
  class
  keeping
  its full
  reach
  beneath
  them (the
  first
  temp-only
  cut
  DELETED
  the
  committed
  dist
  mid-path
  leg's
  victim —
  the suite
  caught it
  before
  commit);
  the fused
  relative
  operator
  inside a
  closure
  use list
  refuses
  like its
  interrupted
  twin; the
  plugin-tree
  collector
  skips
  near-source
  names
  through
  the ONE
  predicate
  both
  fences
  ride (the
  r20
  residual's
  trace
  landed);
  the r22
  residual
  head
  converted
  — the
  non-spawn
  putenv
  leg split
  behind
  function_
  exists('putenv').
  The
  two-lens
  verifier
  pass: 9/9
  CONFIRMED
  (premises
  driven red
  at the
  base,
  mutation
  reverts
  reddening
  every
  committed
  test,
  precedents
  verified),
  two closes
  — the
  iterator
  CONSTRUCTION
  shape of
  the new
  silent
  guards
  (found by
  both
  lenses),
  the \u{}
  MAGNITUDE
  shape —
  and three
  ledgered
  records:
  the
  probe's
  territory
  narrowing
  (foreign
  linked
  roots now
  walk
  through —
  unreachable
  in the
  committed
  suite),
  a Zai-suite
  putenv
  census (26
  errors
  under the
  flag) as
  the next
  round's
  head, and
  a one-off
  order/runner
  flake over
  shared
  dist-path
  artifacts.
  Suite 1657
  → 1663
  tests (+1
  the
  teardown,
  +1 the
  inspector
  twin, +0
  the
  channel
  swap, +0
  the
  unescape
  battery,
  +0 the
  gate, +1
  the
  anchor
  sim, +1
  the
  closure-use
  arm, +1
  the
  collector
  fence, +1
  the split;
  +2 the
  verifier's
  two
  closes),
  45541 →
  45569
  assertions,
  2 skipped
  unchanged.

  OCR-tool
  round
  t31-ocr24
  (FIVE
  commits —
  the
  twenty-fourth
  pass,
  62/62, 4/4
  findings
  accepted;
  trajectory
  27→10→11→7→11→33→9→9→4→12→8→7→9→4;
  the round's
  shape:
  shared/src
  speaks
  again after
  nine rounds
  of silence
  — the
  SecretMask
  class
  rule's own
  implication
  was the
  leak —
  and the
  walker-fence
  census
  completes,
  the
  scanner-internal
  walk
  ledgered as
  residual):
  the suffix
  class owns
  'token'/'secret'/'authorization'
  (the vendor
  spellings
  riding them
  rendered
  verbatim
  — the
  first
  shared/src
  finding
  since round
  14); the
  php -l walk
  rides the
  glm31-4
  fence —
  refusal to
  a named
  violation,
  the
  whole-or-not-at-all
  early
  return —
  and the
  shared
  self-containment
  scan's own
  CONSTRUCTION
  rides its
  try (the
  census one
  seam over
  the
  finding's
  count,
  driven);
  the removal
  twin's
  refusal
  path
  reclaims
  the root
  (the
  best-effort
  @rmdir, the
  sweeper
  asymmetry
  against
  build's
  twin); the
  canonical
  fold gains
  the segment
  boundary
  and the
  verifier's
  rd-1
  deleted the
  vendor
  branch
  (every
  label it
  could emit
  the same
  dead errand
  one branch
  over,
  driven).
  The
  verifier
  pass (two
  lenses,
  three
  findings,
  ALL
  confirmed):
  the rd-1
  close, and
  two
  narration
  corrections
  ledgered
  — the
  ocr24-2
  message's
  'conventions
  gate'
  consumer
  (the gate
  never loads
  the
  scanner;
  the true
  others are
  the
  scan-secrets
  CLI and the
  suite) and
  the ocr24-4
  message's
  vendor-branch
  justification
  (refuted by
  the driven
  round-trip).
  Suite 1663
  → 1665
  tests (+1
  the suffix
  test, +1
  the
  walk-refusal
  test),
  45569 →
  45603
  assertions
  (+20/+5/+1/+4
  the four,
  +4 the
  close), 2
  skipped
  unchanged.

  OCR-tool
  round
  t31-ocr25
  (TEN
  commits —
  the
  twenty-fifth
  pass: main
  57/62 with
  3
  findings,
  the
  fill-in
  over 25
  files with
  8, the
  union
  complete —
  11
  findings,
  all
  accepted;
  trajectory
  27→10→11→7→11→33→9→9→4→12→8→7→9→4→11;
  the
  round's
  shape: Url
  speaks
  TWICE —
  the
  parse_url/WHATWG
  split-divergence
  family,
  the raw
  screen and
  the
  rebuilt
  authority
  disagreeing
  over the
  multi-'@'
  userinfo
  boundary
  and the
  leading-zero
  port — the
  destructive-root-leg
  probe-before-fire
  doctrine
  lands
  (safety
  must not
  rest on
  post-hoc
  assertions
  over
  destroyed
  trees),
  and the
  fixed-name-scratch
  census
  converts
  the
  concurrent-run
  collision
  class
  reborn in
  test
  form): the
  multi-'@'
  authority
  splits
  ONCE
  authority-wide
  (the
  rebuild
  rides the
  raw
  last-'@'
  derivation
  whole —
  host and
  port, the
  range
  check on
  the same
  int;
  engine
  premise
  probed
  twice:
  this
  build's
  parse_url
  is itself
  a last-'@'
  splitter,
  a
  30,000-shape
  battery at
  zero
  divergence,
  the close
  BY
  CONSTRUCTION
  per the
  t31-ocr1-2
  doctrine;
  the
  verifier's
  300,000-shape
  differential
  fuzz found
  only the
  intended
  class and
  the
  tab-host
  spelling,
  now
  pinned);
  the
  archive
  lands
  FIRST, its
  descriptors
  after (the
  pre-flight
  rules out
  non-file
  targets
  only; the
  old order
  stranded
  the
  archive-rename
  refusal —
  the NEW
  checksum
  beside the
  OLD zip —
  driven
  both sides
  on a
  capability
  runner
  through
  chattr +i;
  a
  descriptor
  refusal
  leaves the
  new
  archive
  standing
  with the
  prior
  descriptors,
  stale-not-wrong,
  healed by
  the next
  build's
  regeneration;
  the seam
  charter
  docblock
  restated);
  the
  leading-zero
  port
  refuses
  instead of
  diverging
  (url()
  holds the
  caller's
  bytes, so
  agreement
  means
  refusing
  ':0443' —
  the r4-12
  acceptance
  clause
  reversed;
  the range
  screen
  rides
  first so
  ':000'
  wears the
  range
  sentence;
  parse_url
  answers
  false past
  five port
  digits,
  probed);
  the
  destructive
  root legs
  gain
  PROBE-BEFORE-FIRE
  (the fires
  run only
  where the
  process
  cannot
  WRITE '/'
  — first
  level
  unmutable,
  the root
  runner
  skipping
  visibly —
  the canary
  asserted
  standing
  before
  every
  fire, the
  scratch-rooted
  sim
  driving
  the
  sentinel
  shape
  against an
  unguarded
  walker;
  honest
  boundary
  restated:
  the deep
  user-writable-subtree
  walk on a
  regressed
  guard is
  the
  ledgered
  residual,
  a
  sandboxed
  '/'
  construction
  the new
  head); the
  fixed-name
  scratch
  census
  converts
  to one
  scratchPath
  owner
  (label +
  random
  suffix,
  the
  stage-dir
  doctrine;
  the census
  lists
  every
  converted
  label and
  every
  deliberate
  non-conversion;
  the
  scratch
  trees
  coexist —
  the
  file-level
  claim
  narrowed
  by the
  verifier:
  the
  retained
  artifact-name
  class
  still
  collides);
  the
  umask-0444
  leg skips
  visibly on
  root
  runners
  before any
  construction;
  the
  skip-before-mutation
  pair (the
  deleted-zip
  leg healed
  before the
  uid-0
  gate,
  makeScratchRepo's
  needle
  validated
  before
  anything
  exists);
  the
  scanner-library
  path
  asserted
  resolved
  before the
  child
  embed —
  and rd-1
  completes
  the class
  at every
  child-embed
  site in
  the suite;
  the
  refusal
  verdict
  resolves
  without
  PHPUnit
  (one
  verdict
  owner:
  AssertionFailedError
  where
  PHPUnit is
  loaded,
  the base
  Exception
  in a bare
  engine,
  the chain
  intact —
  driven in
  the child;
  the driven
  mis-answer
  shape
  ledgered).
  The
  two-lens
  verifier
  pass
  CONFIRMED
  all nine
  closes and
  returned
  five
  follow-ups,
  all
  applied as
  rd-1 (the
  charter
  rewrite,
  the
  realpath
  census,
  the
  honest-boundary
  gate
  narration,
  the ':000'
  reorder,
  the
  verdict
  docblock
  and the
  tab-host
  pin), with
  three
  narration
  corrections
  ledgered.
  Residuals:
  the
  genuinely-sandboxed
  '/'
  construction
  is the new
  head; the
  artifact-name/tearDown
  concurrency
  class; the
  crashed-run
  scratch
  residue;
  the
  ZAI-SUITE
  putenv
  census
  still open
  (two
  rounds
  running);
  the
  scan_paths
  walk
  unfenced;
  the
  pid-suffixed
  scratch
  class; the
  r21/r22
  list
  otherwise
  unchanged;
  the
  order/runner
  flake not
  reappearing
  across ten
  full-check
  runs.
  Suite 1665
  → 1669
  tests (+1
  the
  multi-@
  battery,
  +1 the
  landing-refusal
  leg, +1
  the
  leading-zero
  battery,
  +1 the
  bare-engine
  verdict
  leg),
  45603 →
  45651
  assertions,
  2 → 3
  skipped
  (the
  chattr
  capability
  skip).

  OCR-tool
  round
  t31-ocr26
  (THIRTEEN
  commits —
  the
  twenty-sixth
  pass,
  62/62, 15
  findings,
  all
  accepted;
  trajectory
  27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15;
  the
  round's
  shape: the
  trait-use
  position —
  the
  relative-use
  rewriter
  refused
  the
  operator
  everywhere
  except
  where it
  ARMED,
  fence
  doctrine
  again, the
  finder
  fences the
  closure
  spelling
  and the
  trait
  spelling
  rode
  through):
  the trait
  fence
  derives
  from the
  brace-kind
  stack the
  classifier
  already
  rides — a
  use
  statement
  with an
  'other'
  frame
  below it
  refuses
  with the
  position
  named, the
  class-body
  spelling
  being
  LEGAL PHP
  the splice
  silently
  retargeted
  (driven: a
  different
  trait
  shipped at
  exit 0);
  the comma
  carve
  stops one
  member
  early no
  more — the
  rider
  judgment
  walks
  every list
  member
  through a
  separator-aware
  grammar (a
  second
  name with
  no
  separator
  reads as
  the rider
  it is;
  kind
  keywords,
  per-member
  aliases,
  the empty
  member;
  'as A as
  B' still
  refuses);
  the
  removal
  seam's
  real-directory
  '/..' tail
  never
  walks the
  parent
  (the strip
  loop
  tracks the
  tail it
  consumed;
  the
  territory
  doctrine
  extended
  from the
  link
  channel to
  every tail
  spelling)
  and the
  reclaim
  rides the
  stripped
  spelling
  (the
  'dir/.'
  root no
  longer
  leaks to
  rmdir
  EINVAL);
  the
  byte-exact
  duplicate
  answers
  one
  verdict
  line (the
  first
  fence
  wins);
  both
  getLastErrors()
  shapes are
  clean (the
  8.3+
  empty-array
  premise
  guarded
  beside the
  pre-8.3
  false —
  the keys
  read only
  when
  populated;
  this
  engine
  still
  hands
  false, the
  pin
  shape-driven);
  the
  degenerate
  repo
  anchor
  rides the
  '/' prefix
  (construction-evident,
  the driven
  sim a
  root-writable-host
  ceiling);
  the
  manifest
  staging
  temp rides
  the
  crashed-run
  charter
  ('.checksums-<pid>-'
  swept
  beside the
  stage and
  zip
  temps);
  fifteen
  extract-and-lint
  inspector
  arms gain
  the
  exec-capability
  gate (the
  census
  found the
  class
  bigger
  than the
  four named
  sites —
  violations
  accumulate,
  extraction
  and the
  internal
  php -l
  spawn run
  anyway;
  the
  early-return
  and
  extraction-refusal
  arms own
  no spawn,
  gated by
  census
  verdict;
  driven
  under the
  flag:
  visible
  skips);
  the libzip
  read
  warning is
  optional
  never the
  premise
  (the
  refusal
  owns the
  contract,
  the
  capture
  stays the
  silencer);
  makeScratchRepo
  owns its
  creation-phase
  cleanup
  (rrmdir
  and
  rethrow,
  the
  ocr25-7
  validation
  close's
  twin); the
  conventions-gate
  pin
  asserts
  staging at
  each site
  before the
  child
  spawns
  (staging
  failures
  fail as
  staging).
  Residuals:
  the
  sandboxed-
  '/'
  construction
  stays the
  head; the
  artifact-name/tearDown
  concurrency
  class; the
  crashed-run
  scratch
  residue
  narrowed
  (the
  manifest
  temp
  swept);
  the
  ZAI-SUITE
  putenv
  census
  still open
  (three
  rounds
  running);
  the
  scan_paths
  walk
  unfenced;
  the
  pid-suffixed
  scratch
  class; the
  ocr26-7
  driven
  sim; the
  r21/r22
  list
  otherwise
  unchanged;
  the
  order/runner
  flake not
  reappearing
  across
  twelve
  full-check
  runs.
  Suite 1669
  → 1672
  tests (+1
  the trait
  battery,
  +1 the
  tail-reclaim
  leg, +1
  the
  clean-parse
  pin),
  45651 →
  45686
  assertions,
  3 skipped
  unchanged.


  OCR-tool
  round
  t31-ocr27
  (ELEVEN
  commits
  — the
  twenty-seventh
  pass,
  62/62, 15
  findings,
  all
  accepted;
  trajectory
  27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15;
  the
  round's
  shape: the
  FENCE-GENERATION
  TREADMILL
  — the
  round-16
  edge-junk
  close and
  the
  round-26
  tail close
  each begat
  the next
  spelling
  generation,
  and the
  round's
  own fix
  was
  audited
  against
  its
  comment):
  the
  traversal
  predicate
  judges the
  RESOLVED
  segment
  now —
  junk folds
  out
  ANYWHERE
  it sits
  (the ONE
  edge-junk
  owner's
  class
  minus the
  dot,
  derived
  not
  twinned),
  then a
  dots-only
  remainder
  of two or
  more dots
  refuses,
  so '. .',
  '..<tab>..',
  and
  '..<0x01>.'
  (junk
  BETWEEN
  the dots,
  red at
  HEAD: zero
  traversal
  violations)
  refuse
  beside the
  round-16
  spellings
  while
  content
  verdicts
  ride
  unchanged
  (unit-probed
  census in
  the
  commit);
  the
  removal
  seam
  fences the
  WHOLE-PATH
  degenerates
  — '..',
  './', '.'
  carry no
  '/..'
  tail,
  passed
  every
  probe, and
  the walk
  EMPTIED
  the parent
  of the
  caller's
  CWD
  (driven
  red at
  HEAD from
  a
  controlled
  child CWD;
  a spelling
  that names
  no
  caller-named
  root at
  all is
  never
  walked, ''
  the
  strips'
  residue
  riding the
  same
  fence);
  the
  group-prefix
  fence
  flushes at
  EOF — a
  'use
  Prefix\{'
  or 'use
  Prefix\{\Member'
  truncated
  at
  end-of-file
  once
  dropped
  the prefix
  without
  its
  report,
  and EOF is
  the last
  boundary
  (single-report
  by
  construction,
  the
  handlers
  null the
  prefix
  when they
  fire). The
  drift lens
  turned on
  the
  round's
  own fixes:
  the
  byte-duplicate
  verdict is
  ACTUALLY
  deduped
  per name
  (the r26-5
  comment
  claimed it
  while the
  emission
  answered
  N−1
  lines for
  N copies
  — one
  offense,
  one line
  now, a
  triple
  answering
  exactly
  one);
  label()'s
  unknown
  value
  answers a
  named
  LogicException
  (the
  value, the
  LABELS
  table, and
  the
  add-them-together
  duty — a
  desync is
  a
  programmer
  error, the
  exception
  imported
  and pinned
  into the
  shared
  tree's
  enumerated
  vocabulary,
  the sync
  construction-evident
  in both
  directions);
  the nowdoc
  guidance
  names the
  real
  escape
  semantics
  (the
  single-quote
  branch
  resolves
  \\ and \',
  never
  "nothing
  to do" —
  following
  the old
  advice
  would
  corrupt
  exactly
  the bodies
  whose
  distinguishing
  feature is
  that
  nothing
  resolves;
  the only
  caller did
  it right
  since
  round 7
  while the
  docblock
  was
  wrong).
  The test
  fences:
  the
  exec-capability
  census
  closes
  WHOLE (the
  r26-9
  census was
  incomplete
  — the
  re-census
  of every
  inspector
  call site
  found
  SEVEN
  ungated
  spawn-riding
  arms, the
  finding's
  four plus
  the
  repo-relative-include,
  missing-header,
  and
  dev-files
  arms its
  own
  five-site
  count
  missed;
  violations
  ACCUMULATE,
  the header
  verdict is
  POST-extraction;
  driven
  under
  disable_functions=exec:
  seven
  visible
  skips
  where
  \Errors
  stood, the
  ungated
  arms
  enumerated
  by verdict
  seam —
  every one
  refuses
  before the
  spawn);
  the
  dotted-slug
  probe no
  longer
  leaks on
  the skip
  path (the
  guard
  fires
  before the
  write now
  — at
  HEAD one
  scratch
  file per
  spawn-less
  host per
  run,
  driven
  both
  ways); the
  toolchain
  batteries'
  staging
  sites are
  asserted
  (staging
  failures
  fail as
  staging,
  the r26-12
  doctrine's
  next two
  sites) and
  the
  dirlink
  leg
  asserts
  its own
  symlink
  creation
  (a silent
  false once
  left the
  leg's
  pinned '5
  file(s)
  checked'
  green with
  or without
  the link
  —
  vacuous);
  the seam
  battery's
  statIndex()
  false is a
  FAIL row
  naming the
  return
  (the
  is_array()
  guard was
  a silent
  skip over
  the
  emptiness
  judgment,
  the file's
  own
  strict-gate
  doctrine).
  Residuals:
  NEW and
  driven at
  close —
  the
  INSPECTOR
  twin's
  mid-path-link
  NO-TAIL
  spelling
  walks and
  deletes
  through
  the link
  (wp_connectors_inspect_rrmdir('<symlink-to-dir>/sub')
  removed
  the
  victim's
  subtree in
  a live
  probe;
  is_link()
  follows an
  intermediate
  link, and
  the r17-2
  component-chain
  resolution
  lives only
  in the
  HARNESS
  twin —
  the
  inspector
  twin's own
  test
  comment
  claims
  "the probe
  resolves
  the FULL
  component
  chain
  now",
  comment-vs-behavior
  drift over
  a live
  channel
  reachable
  through
  the public
  workDir
  parameter).
  The r26
  head
  residuals
  stand: the
  sandboxed
  '/'
  construction;
  the
  artifact-name/tearDown
  concurrency
  class; the
  crashed-run
  scratch
  residue;
  the
  ZAI-SUITE
  putenv
  census
  still open
  (four
  rounds);
  the
  scan_paths
  walk
  unfenced;
  the
  pid-suffixed
  scratch
  class; the
  ocr26-7
  driven
  sim; the
  r21/r22
  list; the
  order/runner
  flake not
  reappearing
  across
  this
  round's
  eleven
  full-check
  runs.
  Suite 1672
  → 1673
  tests (+1
  the
  label-sync
  pin),
  45686 →
  45732
  assertions
  (+3/+4/+2/+0/+10/+0/+0/+0/+27/+0,
  every
  delta
  measured
  from
  output —
  the 27-4
  commit's
  trailing
  count one
  high,
  self-caught
  at this
  docs
  pass), 3
  skipped
  unchanged.

  OCR-tool
  round
  t31-ocr28
  (NINE
  commits
  —
  the
  twenty-eighth
  pass,
  62/62,
  9
  findings,
  all
  accepted;
  trajectory
  27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9;
  the
  round's
  shape:
  the
  TWIN
  PROBLEM
  AGAIN
  —
  the
  heredoc
  lens
  missed
  the
  EOF
  flush
  its
  name-walk
  sibling
  got
  one
  round
  earlier
  (census
  lesson:
  a
  fix
  applies
  to
  the
  CLASS,
  and
  the
  sibling
  lens
  of
  the
  same
  detector
  IS
  the
  class),
  plus
  the
  trivia-separated
  leading
  separator
  (the
  interrupted-absolute
  spelling
  —
  token-stream
  shapes,
  not
  byte
  shapes,
  are
  the
  frontier
  now),
  and
  one
  finding's
  engine
  premise
  driven
  and
  refuted
  in
  flight):
  the
  heredoc
  text
  lens
  flushes
  at
  EOF
  —
  the
  flush
  lived
  inline
  under
  T_END_HEREDOC
  alone,
  so
  a
  source
  truncated
  inside
  the
  heredoc
  met
  no
  flush
  and
  every
  finding
  the
  body
  carried
  dropped
  without
  its
  report
  (driven
  red
  at
  HEAD:
  zero
  references
  where
  the
  terminated
  twin
  reports
  two,
  the
  build
  shipping
  at
  exit
  0;
  the
  flush
  rides
  ONE
  closure
  —
  the
  lens
  half
  —
  while
  the
  loop
  keeps
  the
  state
  half,
  the
  label
  boundary
  and
  EOF
  calling
  the
  same
  judgments);
  the
  interrupted
  ABSOLUTE
  keeps
  its
  leading
  separator's
  verdict
  —
  a
  separator
  standing
  apart
  from
  its
  name
  (trivia
  between)
  lexes
  as
  a
  standalone
  T_NS_SEPARATOR
  the
  walk
  consumed,
  the
  qualified
  name
  rode
  on
  as
  relative,
  and
  the
  group
  prefix
  composed
  it
  past
  every
  gate
  (the
  r10-9
  laundering
  verdict,
  zero
  references
  at
  HEAD
  while
  the
  glued
  twin
  reported)
  —
  a
  standalone
  separator
  the
  run
  assembly
  did
  not
  swallow
  ARMS
  the
  absolute
  expectation
  now
  (the
  interrupted-relative
  sibling's
  pending-arm
  pattern),
  the
  following
  name
  judged
  fully-qualified
  regardless
  of
  the
  intervening
  trivia
  at
  every
  verdict:
  composition,
  the
  empty-body
  fence,
  the
  alias
  slot;
  the
  copyTree
  resolution
  machinery
  gates
  its
  POSIX
  premise
  at
  the
  owner
  (the
  cwd-prepend
  arm
  consults
  isPosixHost()
  and
  a
  drive-letter/UNC
  root-shape
  check
  —
  a
  Windows
  absolute
  target
  answers
  a
  named
  refusal
  on
  a
  non-POSIX
  host,
  never
  a
  cwd-prepend
  into
  garbage;
  the
  POSIX-side
  exactness
  driven,
  the
  drive-letter
  spelling
  landing
  as
  a
  legal
  relative
  target);
  the
  surrogate
  \u{}
  premise
  DRIVEN
  AND
  REFUTED
  (the
  finding
  claimed
  a
  compile-time
  refusal;
  the
  driven
  engine
  —
  8.5.10,
  php
  -l
  and
  runtime,
  byte-hexed
  —
  compiles
  every
  surrogate
  spelling
  clean
  and
  computes
  ED
  A0
  80,
  the
  RFC-era
  refusal
  lifted
  upstream;
  making
  the
  class
  literal
  would
  invent
  a
  refusal
  the
  engine
  does
  not
  give,
  the
  ocr23-4
  defect
  class
  inverted
  —
  the
  production
  behavior
  stands
  and
  the
  pin
  drives
  the
  ENGINE
  ITSELF
  as
  the
  oracle
  over
  every
  range
  boundary,
  the
  day
  an
  engine
  generation
  refuses
  the
  class
  again
  the
  pin
  fails
  naming
  the
  drift);
  the
  text
  lens's
  line
  derivation
  answers
  a
  PCRE
  abort
  (the
  false
  return
  once
  rode
  the
  arithmetic
  as
  false
  +
  1
  =
  1
  —
  line
  0,
  the
  named
  unknowable,
  now,
  the
  sibling
  pcre-abort
  shape);
  the
  URL
  authority
  refuses
  the
  backslash
  (DERIVED
  FIRST:
  harmless
  on
  the
  PHP
  side,
  but
  the
  device-flow
  verification
  URI
  passes
  raw
  to
  the
  authorization
  redirect
  where
  a
  WHATWG
  browser
  terminates
  the
  authority
  at
  the
  byte
  —
  the
  screen
  rides
  the
  derived
  authority
  segment,
  userinfo
  included,
  and
  the
  round-1
  space/tab
  adjudication
  stands
  beside
  it,
  pinned
  still-legal);
  the
  manifest-unreadable
  row's
  finally
  restores
  the
  third
  chmod'd
  path
  (the
  restore
  at
  the
  row
  that
  broke
  it,
  never
  in
  rrmdir's
  removal
  contract);
  and
  the
  copyTree
  battery's
  four
  ungated
  legs
  ride
  the
  platform
  probe
  while
  the
  root-source
  leg
  pins
  its
  DISTINCTIVE
  vocabulary
  (the
  generic
  contains-'/'
  check
  rode
  inside
  the
  policy
  prefix
  —
  vacuous;
  the
  source-side
  root-collapse
  sentence
  is
  a
  substring
  only
  that
  refusal
  carries).
  Residuals
  carried
  forward:
  NEW
  —
  the
  surrogate
  version-drift
  note
  (the
  unescaper
  mirrors
  the
  driven
  engine;
  an
  older
  engine
  generation
  answers
  the
  eval-oracle
  pin
  RED
  naming
  the
  drift,
  a
  cross-version
  mirror
  declined
  on
  the
  unverifiable
  boundary);
  the
  r27
  head
  residual
  stands
  (the
  inspector
  twin's
  mid-path-link
  no-tail
  spelling);
  the
  ZAI-SUITE
  putenv
  census
  now
  five
  rounds
  open;
  the
  r26
  list
  otherwise
  unchanged;
  the
  order/runner
  flake
  not
  reappearing
  across
  the
  round's
  nine
  green
  full-check
  runs.
  Suite
  1673
  →
  1676
  tests
  (+1
  the
  heredoc
  EOF
  pin,
  +1
  the
  interrupted-absolute
  pin,
  +1
  the
  backslash
  battery),
  45732
  →
  45764
  assertions
  (+8/+10/+1/+5/+0/+8/+0/+0,
  every
  delta
  measured
  from
  output),
  3
  skipped
  unchanged.

  OCR-tool
  round
  t31-ocr29
  (TEN
  commits
  —
  the
  twenty-ninth
  pass,
  the
  union
  complete
  (main
  61/62
  with
  11;
  fill-in
  3
  files
  with
  2;
  BuildArtifactsTest
  unchanged
  since
  r27,
  fully
  audited
  r28),
  13
  findings,
  all
  accepted;
  trajectory
  27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13;
  the
  round's
  shape:
  the
  print_r/
  __debugInfo
  ENGINE
  TRUTH
  driven
  to
  an
  INVERTED
  verdict
  (the
  finding's
  premise
  was
  the
  fabrication,
  refuted
  on
  the
  runner
  engine
  AND
  the
  8.2
  support
  floor),
  the
  platform-separator
  family's
  third
  generation
  (tails,
  containment
  comparisons,
  patch
  spellings,
  the
  super-root
  precondition),
  and
  the
  fill-in
  operational
  note
  (BuildArtifactsTest
  exceeds
  the
  default
  per-group
  token
  ceiling
  —
  future
  fill-ins
  need
  --max-tokens
  64000)):
  the
  print_r
  premise
  REFUTED
  on
  both
  engines
  (print_r
  and
  var_dump
  share
  the
  engine's
  one
  get_debug_info
  handler;
  the
  r11-5
  pin
  already
  drove
  print_r
  masked;
  the
  close
  is
  the
  driven
  adjudication
  plus
  the
  var_export
  boundary
  pinned
  by
  its
  adjudicated
  shape);
  the
  exact
  family
  ROOT
  a
  member
  (never
  a
  "SIBLING",
  the
  empty
  below-root
  tail
  never
  riding
  the
  prefix
  form's
  separator);
  the
  inspector
  tail
  probe
  fences
  BOTH
  separator
  spellings
  (the
  spelling
  CLASS,
  never
  the
  host;
  driven
  red
  at
  HEAD
  through
  a
  real
  backslash-named
  tree);
  copyTree's
  containment
  verdicts
  compare
  in
  one
  vocabulary
  (the
  realpath
  arm
  of
  the
  ocr28-3
  doctrine);
  'auth-token'
  subsumed
  by
  'token'
  and
  gone;
  the
  degenerate-root
  precondition
  derives
  the
  host's
  two-slash
  shape;
  the
  ToolchainSmokeTest
  POSIX-premise
  pair
  gates;
  the
  staging
  read/write
  pair
  asserted;
  the
  indent
  scar
  gone;
  the
  RDONLY
  source
  pin
  whitespace-normalized.
  Suite
  1676
  tests
  unchanged,
  45764 →
  45776
  assertions
  (+4/+3/+2/+0/+0/+0/+0/+2/+0/+1,
  every
  delta
  measured
  from
  output),
  3
  skipped
  unchanged.

  OCR-tool
  round
  t31-ocr30
  (SEVEN
  commits
  —
  the
  thirtieth
  pass,
  main
  61/62
  with
  7;
  BuildArtifactsTest
  OCR-unreachable
  (context
  compression
  kills
  it
  deterministically
  even
  solo
  at
  the
  200k
  ceiling;
  its
  test
  legs
  ride
  the
  later
  claude-glm
  code-review
  phase),
  7
  findings,
  all
  accepted;
  trajectory
  27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7;
  the
  round's
  shape:
  the
  trait-adaptation
  ';'
  —
  the
  r26-1
  trait-fence
  class's
  third
  generation,
  corrupting
  the
  frame
  stack
  from
  INSIDE
  the
  adaptation
  block
  (both
  walks,
  the
  driven
  red
  the
  fence's
  worst
  case:
  a
  silent
  trait
  RETARGET
  at
  exit
  0),
  and
  the
  scan_paths
  residual
  HEAD
  landed
  (the
  lint
  walk
  fenced
  at
  its
  owner)):
  the
  ';'
  rides
  the
  adaptation's
  frame
  and
  the
  closing
  '}'
  terminates
  the
  trait
  use
  (the
  census
  over
  every
  ';'
  consumer
  rides
  the
  ocr30-1
  commit);
  the
  classifier's
  stack
  balanced
  through
  the
  adaptation
  (both
  trait
  twins
  anonymous,
  imports
  keep
  the
  named
  errand);
  the
  lint
  walk
  fenced
  (named
  FAIL,
  partial
  count
  kept,
  permission
  probe);
  the
  landing
  loop's
  mkdir/copy
  returns
  owned
  (the
  policy
  refusal,
  never
  the
  engine's
  vocabulary);
  the
  interrupted
  member
  routed
  (derived
  first;
  both
  spellings
  one
  mid-name
  verdict);
  the
  apply()
  throw
  a
  FAIL
  row;
  the
  eighth
  platform
  gate.
  Suite
  1676 →
  1681
  tests
  (+1
  the
  adaptation-state
  pin,
  +1
  the
  classifier-adaptation
  pin,
  +1
  the
  lint-walk
  lock
  pin,
  +1
  the
  mid-landing
  IO
  pin,
  +1
  the
  apply-throw
  leg),
  45776 →
  45821
  assertions
  (+7/+5/+20/+6/+2/+5/+0,
  every
  delta
  measured
  from
  output),
  3
  skipped
  unchanged.

  OCR-tool
  round
  t31-ocr31
  (NINE
  commits
  —
  the
  thirty-first
  pass,
  main
  61/62
  with
  9;
  BuildArtifactsTest
  OCR-unreachable
  (context
  compression
  kills
  it
  deterministically
  even
  solo
  at
  the
  200k
  ceiling;
  its
  test
  legs
  ride
  the
  later
  claude-glm
  code-review
  phase),
  9
  findings,
  all
  accepted;
  trajectory
  27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9;
  the
  round's
  shape:
  the
  heredoc
  re-entry
  (a
  one-open-construct
  state
  machine
  must
  own
  the
  NESTED
  spelling
  of
  that
  construct
  —
  the
  lexer
  stack
  genuinely
  produces
  it,
  driven
  depth
  2);
  the
  member-grammar
  pair
  (two
  owners
  —
  the
  member-start
  walk
  and
  the
  group-use
  callback
  —
  each
  accepted
  engine-rejected
  member
  spellings,
  one
  doctrine:
  the
  grammar
  rejects
  what
  the
  engine
  rejects);
  and
  the
  hex-arm
  case
  premise
  refuted
  on
  the
  runner
  engine
  ("\X41"
  computes
  'A'
  —
  the
  unescaper
  already
  answered
  the
  engine's
  own
  bytes,
  the
  oracle
  pinned
  live).
  The
  fixes:
  the
  heredoc
  lens
  a
  stack
  of
  frames
  (the
  outer
  head's
  finding
  was
  lost
  at
  HEAD);
  the
  member
  walk's
  open
  separator
  owing
  exactly
  one
  name
  piece
  (the
  lexer
  bakes
  legal
  separators
  into
  the
  name
  tokens
  —
  the
  token
  facts);
  the
  group-use
  reassembly
  validating
  before
  it
  rebuilds
  (the
  empty-body
  survivor
  row
  moved
  with
  it,
  its
  charge
  one
  seam
  earlier);
  the
  \X
  hex
  arm
  pinned
  to
  the
  driven
  engine
  over
  both
  cases;
  the
  scan_paths
  root
  strip
  both
  separator
  spellings;
  the
  redaction
  rebuild
  one
  owner
  (Url::redacted(),
  the
  callerless
  wrapper
  deleted);
  the
  brace-offset
  seeded
  from
  the
  running
  counter
  (byte-identical,
  driven);
  the
  non-directory
  arm's
  exact-class
  pin
  (the
  plant
  caught
  one
  seam
  earlier
  by
  the
  lazy-iterator
  fence);
  the
  cli_args
  pin
  out
  of
  the
  exec
  gate.
  Suite
  1681 →
  1683
  tests
  (+1
  the
  nested-heredoc
  battery,
  +1
  the
  ungated
  cli_args
  method),
  45821 →
  45838
  assertions
  (+1/+8/+5/+1/+0/+1/+0/+1/+0,
  every
  delta
  measured
  from
  output),
  3
  skipped
  unchanged.
  OCR-tool
  round
  t31-ocr32
  (NINE
  commits
  —
  the
  thirty-second
  pass,
  main
  61/62
  with
  10
  findings
  per
  the
  round
  doc,
  nine
  numbered;
  BuildArtifactsTest
  OCR-unreachable
  (context
  compression
  kills
  it
  deterministically
  even
  solo
  at
  the
  200k
  ceiling;
  its
  test
  legs
  ride
  the
  later
  claude-glm
  code-review
  phase),
  all
  accepted;
  trajectory
  27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10;
  the
  round's
  shape:
  the
  alias-grammar
  family
  —
  the
  round-31
  member
  grammar
  validated
  members
  but
  not
  their
  ALIASES,
  and
  the
  engine-illegal
  alias
  class
  leaked
  through
  three
  different
  re-emit
  seams
  in
  one
  file;
  the
  fence
  lesson
  in
  its
  fifth
  generation:
  a
  grammar
  close
  at
  one
  seam
  never
  closes
  the
  class
  at
  the
  re-emit
  seams
  —
  closed
  under
  one
  census
  and
  one
  reserved-vocab
  owner
  (aliasIdentifierIsEngineIllegal(),
  the
  relative
  walk's
  hand-rolled
  list
  folded
  into
  it)).
  The
  fixes:
  the
  use-statement
  alias
  pattern
  a
  callback
  refusing
  the
  fourteen
  engine-illegal
  identifiers
  (php
  -l-derived,
  case-insensitively)
  and
  the
  alias+brace
  rider
  composition;
  the
  member
  alias
  the
  same
  owner;
  the
  member's
  non-identifier
  as-tail
  refused
  at
  the
  grammar
  (`AS`
  case-insensitive
  spellings
  keep
  riding);
  the
  inspector
  removal
  walk's
  per-entry
  IO
  returns
  owned
  (the
  named
  refusal
  through
  the
  printable
  seam,
  the
  callsites
  converting
  —
  the
  teardown
  finally
  keeping
  its
  silent
  contract);
  the
  traversal/near-source
  collectors
  keyed
  per
  name
  (first-verdict-wins,
  one
  offense
  one
  line);
  the
  unused-import
  scan's
  three
  FAIL
  sites
  the
  rtrim
  parity
  (the
  t31-ocr14-4
  spelling);
  the
  harness
  removal
  walk's
  IO
  returns
  owned
  (the
  ocr30-4
  two-way
  escape
  closed
  at
  both
  removal
  twins);
  the
  link-probe
  anchor
  comparisons
  one
  vocabulary
  (posix_comparison_vocabulary
  off-POSIX,
  POSIX
  identity
  byte-unchanged);
  the
  copyTree
  source
  gate
  probing
  readability
  (opendir,
  the
  iterator's
  own
  capability).
  Suite
  1683
  →
  1686
  tests
  (+1
  the
  inspector
  removal-IO
  pin,
  +1
  the
  N-copy
  collectors
  pin,
  +1
  the
  harness
  removal-IO
  pin),
  45838
  →
  45867
  assertions
  (+8/+3/+6/+3/+3/+0/+4/+0/+2,
  every
  delta
  measured
  from
  output),
  3
  skipped
  unchanged.

  OCR-tool
  round
  t31-ocr33
  (EIGHT
  commits
  —
  the
  thirty-third
  pass,
  main
  61/61,
  FULLY
  COMPLETE,
  the
  first
  complete
  round
  since
  the
  BuildArtifactsTest
  ceiling
  issue;
  all
  accepted;
  trajectory
  27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10;
  the
  round's
  shape:
  the
  hard-keyword
  census
  hole
  —
  soft
  keywords
  lex
  as
  T_STRING,
  hard
  keywords
  lex
  as
  their
  own
  token
  ids,
  a
  two-token-class
  distinction
  no
  fourteen-entry
  enumeration
  can
  own,
  the
  hard
  half
  DERIVED
  from
  the
  lexer
  at
  the
  ONE
  owner
  with
  a
  php
  -l
  oracle
  leg
  tying
  the
  whole
  class
  to
  the
  engine;
  the
  drive-root
  family
  —
  the
  universal-container
  refusals
  POSIX-spelling-only
  (rrmdir('C:\')
  walks
  the
  drive
  root),
  closed
  through
  ONE
  container
  owner
  (resolvesToUniversalContainer,
  comparison
  folded,
  both
  spellings)
  at
  rrmdir,
  the
  copyTree
  source
  side,
  and
  the
  mirror
  clause,
  with
  the
  link
  probe
  folding
  BEFORE
  the
  tail
  strips).
  Small
  ones:
  the
  text
  lens's
  line
  counter
  spells
  the
  tokenizer's
  exact
  terminator
  class
  (\R
  also
  matched
  /
  /�
  and
  inflated
  lines);
  three
  dead
  sensitive-header
  catalog
  entries
  drop
  (the
  suffix
  class
  subsumes
  them,
  the
  t31-ocr29-5
  doctrine);
  the
  removal
  walk
  fences
  its
  recursion
  boundary
  (the
  r30-3
  harness
  twin
  —
  an
  unreadable
  mid-tree
  subdirectory
  answers
  the
  harness's
  refusal,
  never
  SPL
  vocabulary);
  the
  copy
  battery's
  finally
  cleanup
  guarded
  through
  one
  owner
  (an
  environmental
  failure
  never
  replaces
  the
  verdict
  in
  flight)
  and
  every
  stage
  write
  asserted
  battery-wide
  (the
  t31-ocr29-10
  doctrine).
  NEW
  RESIDUAL:
  the
  copyTree
  walk's
  mid-tree
  unlistable
  twin
  stands
  (the
  round
  fenced
  the
  removal
  walk).
  Suite
  1686
  →
  1689
  tests
  (+1
  the
  /
  line-class
  pin,
  +1
  the
  recursion-boundary
  pin,
  +1
  the
  planted-throw
  leg),
  45867
  →
  46192
  assertions
  (+296/+2/+0/+0/+0/+5/+1/+21,
  every
  delta
  measured
  from
  output),
  3
  skipped
  unchanged.

  OCR-tool
  round
  t31-ocr34
  (SEVEN
  commits
  —
  the
  thirty-fourth
  pass,
  main
  61/61,
  FULLY
  COMPLETE;
  all
  accepted;
  trajectory
  27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9;
  the
  round's
  shape:
  the
  twin-sweep
  generation
  —
  every
  r33
  close
  had
  missed
  an
  owner,
  and
  each
  lands
  at
  the
  owner
  the
  doctrine
  already
  spells.
  The
  root
  fence's
  THIRD
  owner:
  the
  inspector's
  rrmdir
  refused
  only
  the
  POSIX
  '/'
  while
  round
  33
  closed
  the
  class
  at
  both
  WpHarness
  owners
  —
  the
  fence
  judges
  the
  container
  CLASS
  now
  (the
  answer
  folded
  through
  the
  separator
  vocabulary,
  refused
  as
  '/'
  or
  a
  drive-letter
  root),
  ONE
  census
  comment
  naming
  all
  three
  owners
  of
  the
  universal-container
  class,
  construction-evident,
  the
  '/'
  refusal
  riding
  unchanged.
  The
  copyTree
  recursion
  twin
  —
  the
  r33
  ledger's
  OWN
  residual
  line
  landed:
  the
  copy
  walk
  fences
  its
  recursion
  boundary
  the
  removal
  twin's
  exact
  shape
  (a
  chmod-000
  child
  mid-tree
  died
  in
  the
  SPL
  vocabulary,
  driven
  red
  at
  HEAD;
  the
  construction
  rides
  the
  try,
  the
  SPL
  message
  rides
  parenthetically,
  the
  per-entry
  refusals
  pass
  untouched),
  the
  regression
  gated
  by
  the
  opendir
  probe.
  The
  one-guarded-consumer
  release:
  the
  ocr33-7
  doctrine
  swept
  to
  EVERY
  rrmdir
  caller
  —
  ONE
  owner,
  WpHarness::releaseScratch,
  hoisted
  beside
  rrmdir
  with
  the
  census
  naming
  every
  caller
  battery,
  every
  release
  call
  site
  (startup
  reclaims,
  mid-phase
  removals,
  every
  finally)
  routed
  through
  it
  across
  the
  nine
  batteries,
  the
  private
  twin
  deleted
  and
  the
  planted-throw
  leg
  driving
  the
  shared
  owner,
  rrmdir's
  under-test
  callers
  staying
  bare
  by
  design
  (their
  subject
  IS
  the
  throw).
  The
  Win32-premise
  family:
  the
  needs_posix
  row
  flag
  consults
  the
  ONE
  platform
  owner
  at
  runState()'s
  head
  (chmod
  0000
  sets
  the
  read-only
  attribute
  only
  —
  reads
  succeed,
  the
  LOUD
  row
  a
  phantom
  pass;
  trailing
  edge
  junk
  strips
  or
  rejects
  —
  the
  staged
  near-source
  a
  DIFFERENT
  valid
  file)
  and
  the
  forced-close
  leg
  carries
  its
  inline
  twin
  hoisted
  above
  the
  archive's
  creation
  (libzip
  still
  reads
  the
  read-only
  source,
  the
  assertNotNull
  a
  phantom
  finalization
  defect).
  The
  super-root
  spelling
  landed
  at
  the
  guard
  itself:
  the
  predicate
  derives
  the
  host's
  realpath('//')
  answer
  (the
  r29-6
  doctrine,
  never
  the
  literal)
  —
  on
  this
  engine
  the
  probe
  collapses
  to
  '/'
  (driven),
  the
  arm
  the
  preserving
  host's
  belt
  exactly
  the
  way
  the
  C:/
  spelling
  is,
  the
  '//'
  source
  leg
  pinning
  the
  contract
  green-both-sides
  here
  with
  the
  driven
  reality
  stated.
  Small
  ones:
  the
  embedded-vs-sources
  completeness
  comparison
  folds
  one
  separator
  vocabulary
  (zip
  names
  always
  slash-joined,
  getPathname()
  host-joined
  —
  off-POSIX
  a
  permanent
  phantom
  FAIL,
  both
  sides
  of
  the
  strip
  folding
  first,
  identity
  on
  POSIX);
  one
  WP-styled
  leg
  restyled
  to
  the
  file's
  PSR.
  No
  new
  residuals
  (the
  r33
  copyTree
  twin
  LANDED).
  Suite
  1689
  →
  1690
  tests
  (+1
  the
  copy-walk
  recursion-boundary
  pin),
  46192
  →
  46200
  assertions
  (+0/+6/+2/+0/+0/+0/+0,
  every
  delta
  measured
  from
  output),
  3
  skipped
  unchanged.

  OCR-tool
  round
  t31-ocr35
  (EIGHT
  commits
  —
  the
  thirty-fifth
  pass,
  main
  61/61,
  FULLY
  COMPLETE;
  9
  findings,
  all
  accepted,
  one
  refuted
  in-round
  at
  its
  premise;
  trajectory
  27→10→11→7→11→33→9→9→4→12→8→7→9→4→11→15→15→9→13→7→9→10→10→9→9;
  the
  round's
  shape:
  the
  case-variant
  keyword
  axes
  —
  the
  fourth
  generation
  of
  the
  use-grammar
  family,
  this
  time
  in
  the
  keyword
  SPELLING
  rather
  than
  the
  member/alias
  grammar;
  the
  \R
  twins
  —
  the
  same
  drift
  the
  r33
  detector
  close
  left
  in
  the
  test
  file's
  own
  readers;
  the
  vacuous-green
  silent
  skip;
  and
  one
  refutation
  of
  record,
  the
  apostrophe-label
  heredoc
  premise
  driven
  dead
  before
  the
  fix
  landed,
  the
  r21
  doctrine
  applied
  in-round).
  ocr35-1
  (bug:medium):
  the
  four
  keyword
  axes
  —
  use,
  function,
  const,
  as
  —
  ride
  scoped
  (?i:...)
  groups
  at
  every
  pattern
  seam
  (the
  plain
  use-statement
  pattern,
  the
  group-use
  prefix,
  the
  member-kind
  extraction),
  the
  keyword
  casing
  riding
  the
  output
  verbatim
  while
  the
  family
  NAME
  keeps
  its
  byte-exact
  matching
  and
  refuse-named
  label;
  the
  classifier's
  keyword
  label
  died
  with
  its
  dead
  errand
  (every
  surviving
  keyword-case
  spelling
  refuses
  through
  its
  OTHER
  cause),
  the
  r7-9
  pinned
  row
  flipping
  to
  the
  owned
  battery.
  ocr35-2
  (maintainability:low):
  the
  manifest
  prune
  reclaims
  the
  pruned
  entry's
  checksum
  sidecar
  inside
  the
  merge
  lock,
  fenced
  to
  a
  plain
  file
  name
  beside
  the
  manifest.
  ocr35-3
  (bug:low,
  PREMISE
  REFUTED,
  driven
  in-round):
  heredoc
  labels
  are
  identifiers
  —
  the
  ASCII
  apostrophe
  is
  not
  a
  label
  byte
  and
  php
  -l
  refuses
  <<<"E'OT"
  at
  the
  opener,
  while
  the
  legal
  high-byte
  quote-lookalikes
  never
  trip
  an
  ASCII
  strpos;
  what
  survives
  is
  the
  delimiter
  reading
  (the
  engine's
  own
  rule,
  behavior-identical
  on
  every
  tokenizable
  spelling)
  and
  the
  opener-spelling
  pins
  —
  every
  legal
  heredoc
  opener
  resolving
  escapes,
  the
  nowdoc
  opener
  resolving
  nothing.
  ocr35-4
  (documentation:low):
  the
  self-containment
  walk's
  @throws
  tag.
  ocr35-5
  (bug:low):
  the
  containment
  verdicts
  speak
  the
  host's
  DERIVED
  path-case
  vocabulary
  —
  one
  probe
  owner
  (isCaseInsensitivePathHost,
  the
  isPosixHost
  shape)
  and
  a
  sibling
  fold
  arm
  beside
  posix_comparison_vocabulary;
  engine-premise
  note:
  this
  Linux
  runner
  answers
  case-sensitive
  (driven),
  the
  arm
  inert
  here,
  the
  leg
  pinned
  green-both-sides
  the
  r34-3
  way.
  ocr35-6
  (bug:low
  x2,
  one
  commit):
  numberedLines
  and
  the
  whole-file
  diagnostic's
  two
  derivations
  spell
  the
  tokenizer's
  exact
  three
  terminators
  now,
  pinned
  by
  the
  vertical-tab
  drift
  fixture.
  ocr35-7
  (maintainability:low):
  the
  architecture
  file's
  five
  scratch-tree
  mkdir
  setups
  (eight
  sites)
  assert
  their
  own
  landing,
  the
  t31-ocr29-10
  doctrine.
  ocr35-8
  (test:low):
  the
  chmod-0000
  leg
  markTestSkips
  loudly
  on
  root
  runners
  —
  the
  ocr32-9
  pin
  never
  runs
  vacuously
  green.
  Residuals
  carried
  forward:
  none
  new;
  the
  r28
  surrogate
  version-drift
  note,
  the
  r27
  inspector
  mid-path-link
  spelling,
  the
  ZAI-SUITE
  putenv
  census
  (now
  twelve
  rounds
  open),
  the
  scan_paths
  walk,
  and
  the
  r26
  list
  stand;
  the
  order/runner
  flake
  not
  reappearing
  across
  the
  round's
  eight
  green
  full-check
  runs.
  Suite
  1690
  →
  1692
  tests
  (+1
  the
  containment
  case-vocabulary
  leg,
  +1
  the
  sweep-line-terminators
  pin),
  46200
  →
  46241
  assertions
  (+6/+3/+10/+0/+4/+10/+8/+0
  the
  eight
  finding
  commits,
  every
  delta
  measured
  from
  output),
  3
  skipped
  unchanged.

- [ ] **Task 3.2 — Implement encrypted token storage.** Encrypt one versioned envelope per provider
  with `sodium_crypto_secretbox`, random nonce, authenticated ciphertext, and a key derived from
  WordPress auth salts. The key derivation (or the envelope itself) MUST bind the ciphertext to
  its provider AND site context (multisite shares salts across sites: derive per-site — e.g.
  include the site ID — or embed and verify provider+site inside the envelope), so a ciphertext
  transplanted from another site or provider fails authentication rather than granting access;
  add cross-provider and cross-site swap tests. If salts are unusable, the fallback key MUST come from an external
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
  remain retryable and MUST NOT mark the grant dead or force a reconnect. Deterministic
  client/configuration failures — OAuth error `invalid_client`/`unauthorized_client` (rotated
  or disabled extracted client ID, a documented SPEC risk) — form a THIRD state: permanent
  configuration error that suppresses retries until connector configuration changes
  (update required), distinct from both reconnect-required and transient; test it separately
  from invalid-grant handling. Repeated transient
  failures MUST enter a persisted, bounded cooldown (honoring `Retry-After` where provided, else
  exponential backoff with a cap) so sequential callers do not hammer the token endpoint during
  an outage; the cap applies to BOTH forms — provider-supplied `Retry-After` (seconds and
  HTTP-date) and fallback backoff — so an oversized throttle cannot suppress refreshes far
  past the outage; test oversized seconds and HTTP-date values as Task 4.2 does for login
  retries. Check this task only after deterministic concurrency, clock-boundary, scheduling,
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
  decryptability, expiry/refresh outcome, and an optional cheap provider probe. Availability
  checks invoked from GET renders (provider page, core Connectors screen) MUST be read-only:
  report from cached grant state only (stale-expiry = temporarily unavailable with a
  scheduled refresh), never perform HTTP or commit rotated tokens — refreshes run exclusively
  through POST/scheduled paths. Distinguish
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
  the first flow's `device_auth_id`/`user_code`; add a duplicate-start test. Starts by the
  SAME administrator (double-submit/two tabs) MUST be serialized through an atomic per-user
  start lease/CAS before state is stored — both requests may never obtain different server
  flows and overwrite one per-user slot while both display valid-looking codes; add a
  genuinely overlapping same-user test (Tasks 5.3 and 6.2 inherit this guarantee). Starts
  are fenced against revocation: capture the grant generation before the vendor call (or
  serialize start writes with the revoke lock) and recheck before storing the pending record,
  so a start whose HTTP response was in flight during Revoke cannot adopt the post-revoke
  generation and later install a grant; add a deterministic revoke-versus-start race test.
  Persisted pending
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
  (claim released, never consumed on 429 — mirroring the Claude rule in Task 6.3); on 429 with
  a nonzero `Retry-After`, persist a per-flow not-before time (cooldown) and reject or
  reschedule earlier reacquisition so the cooldown is honored before another poll retries;
  test exchange-retry-after-429 with early-retry rejection. Check this task only after
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
  first user's still-valid code; test overlapping starts. Replacement is fenced like Codex
  cancellation (Task 4.3): a replacement start acquires the polling lease or advances a
  generation checked immediately before token commit, so an old flow's in-flight exchange
  cannot install its grant after the new code is presented; add a start-versus-poll race test.
  Check this task only after request,
  output escaping, expiration, duplicate-start, replacement fencing, at-rest encryption, CSRF,
  and capability tests pass.

- [ ] **Task 5.4 — Implement RFC 8628 polling.** Poll at the server interval — defaulting to
  five seconds when the device response omits the optional `interval` member (RFC 8628 §3.3);
  reject nonpositive or malformed supplied values and cover the omitted-field path — and handle
  `authorization_pending`, denial, expiry, and success without tying up a PHP worker. A
  transport-layer timeout on a poll is non-terminal (RFC 8628 §3.5): preserve the still-valid
  device flow and reduce the polling frequency with bounded backoff for subsequent polls;
  cover the timeout path in the clock-driven suite. A
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
  simultaneous administrators. Replacement (cancel-and-replace path) is fenced like xAI
  Task 5.3: it acquires the old flow's claim/lease or advances a generation checked
  immediately before token persistence, so an in-flight exchange for a replaced flow cannot
  install the old grant after the new authorization URL is displayed; add a
  start-versus-exchange race test.
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
  PHP syntax on 8.2–8.4, PHPUnit, static analysis, build reproducibility, secret scanning, and a
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
  explicit opt-in or documented uninstall cleanup for options, cron hooks, locks, and
  ciphertext. External key configuration (Task 3.2 fallback) is never stored by the plugin and
  therefore never deleted by uninstall. Check this task only after upgrade/rollback and uninstall tests show no orphaned
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
- The plan keeps PHP 8.2 syntax compatibility, uses only `wp_remote_*` for provider traffic,
  preserves per-site multisite settings, and maintains standalone plugin artifacts as required.

If implementation discovers a genuine contradiction with the SDK or live provider behavior, the
implementing LLM must update the SPEC and this plan in the same change, add regression evidence,
and leave affected milestone/task checkboxes unchecked until the revised acceptance criteria pass.
