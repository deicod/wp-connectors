# Changelog

All notable changes to this repository are documented here, per plugin and per
tooling area. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning per plugin follows its own header `Version` (no monorepo version).

## [Unreleased]

### Added (zai / M2 — second provider `zai_anthropic`, Task 2.1)

- The `zai` plugin now registers a second provider, `zai_anthropic`
  (card "z.ai (Anthropic API)"), alongside `zai`. Both registrations are
  individually idempotent, and the registrar refuses to register onto a
  provider ID a foreign class already holds — the SDK's `registerProvider()`
  would silently overwrite it — so one provider's registration can never
  replace the other's.
- `zai_anthropic` has its own plan/region options
  (`zai_connector_zai_anthropic_plan` / `_region`, defaults coding + intl)
  rendered as a second section on the shared settings page; the two
  providers' selections cannot bleed into each other.
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
