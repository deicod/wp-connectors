# Changelog

All notable changes to this repository are documented here, per plugin and per
tooling area. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning per plugin follows its own header `Version` (no monorepo version).

## [Unreleased]

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
