# SPEC — WordPress 7.0 AI Connectors (deicod/wp-connectors)

**Status:** Draft v1
**Date:** 2026-08-30
**Scope:** Initial connector suite — z.ai (OpenAI + Anthropic API), OpenAI (OAuth), xAI (OAuth), Anthropic (OAuth, bonus)

---

## 1. Purpose

This repository hosts a collection of WordPress 7.0 AI provider connector plugins built on the
**PHP AI Client SDK** (`wordpress/php-ai-client`, shipped in WP 7.0 core as `WordPress\AiClient`).
Each connector is an independent WordPress plugin that registers one or more providers with
`AiClient::defaultRegistry()`, which makes them auto-discoverable on the native
**Settings → Connectors** screen — no manual connector registration required.

Initial suite:

| # | Connector | Protocol / Surface | Auth | Priority |
|---|-----------|--------------------|------|----------|
| 1 | `zai` | z.ai OpenAI-compatible API | API key (Bearer) | M1 |
| 2 | `zai_anthropic` | z.ai Anthropic-compatible API | API key (Bearer) | M2 |
| 3 | `codex` | OpenAI Codex (ChatGPT subscription) | OAuth device flow | M3 |
| 4 | `grok` | xAI Grok (SuperGrok / X Premium+) | OAuth device flow | M4 |
| 5 | `claude_pro` | Anthropic (Claude Pro/Max subscription) | OAuth PKCE (paste code) | M5 (bonus) |

Connector #1/#2 ship as **one plugin** (`connectors-zai`) registering two providers.
#3–#5 are **dedicated plugins** each (OAuth needs its own admin flow; one connector each
keeps blast radius small — per user decision: split when too much for one connector).

---

## 2. WordPress 7.0 integration model (verified facts)

Sources: make/core dev notes 2026-03-18 (Connectors API) + 2026-03-24 (AI Client),
`wp-includes/connectors.php` @ 7.0, `WordPress/php-ai-client` trunk, `WordPress/ai-provider-for-anthropic` v1.0.4.

### 2.1 Registration (the only required glue)

```php
add_action('init', function (): void {
    if (!class_exists(\WordPress\AiClient\AiClient::class)) { return; } // WP < 7.0 without SDK plugin
    $registry = \WordPress\AiClient\AiClient::defaultRegistry();
    if (!$registry->hasProvider(ZaiProvider::class)) {
        $registry->registerProvider(ZaiProvider::class);
    }
}, 5);  // priority 5 = BEFORE _wp_connectors_init() → auto-discovery picks it up
```

Auto-discovery derives per-connector metadata from `ProviderMetadata`:
- Card name/description/logo (description needs SDK ≥ 1.2.0, logoPath ≥ 1.3.0 — guard with
  `version_compare(AiClient::VERSION, …)`)
- Auth: `RequestAuthenticationMethod::apiKey()` → connector `method: api_key`,
  key setting `connectors_ai_{id}_api_key`, env/constant `{ID}_API_KEY`.
  Anything else → connector `method: 'none'` (no key field in core UI).

### 2.2 Core UI limits (design constraints)

- Connectors screen supports **only** `api_key` and `none` auth. No OAuth UI in core
  (tracked upstream; frontend integration missing).
- Consequence: OAuth connectors register as `none` + ship their **own minimal admin page**
  (Connect / status / revoke) linked from the plugin row and via a card-adjacent action.
- API keys in DB are not encrypted by core (masked only). OAuth token stores MUST
  self-encrypt (see §4.4 and §6.1).

### 2.3 Provider plugin anatomy (per provider)

| Piece | Base class | Job |
|---|---|---|
| Provider | `AbstractApiProvider` | `baseUrl()`, `createProviderMetadata()`, `createModelMetadataDirectory()`, `createModel()`, `createProviderAvailability()` |
| ModelMetadataDirectory | `AbstractOpenAiCompatibleModelMetadataDirectory` (zai-openai) / custom (zai-anthropic, oauth) | Model discovery: List-Models call or static list → `ModelMetadata[]` with `CapabilityEnum` + `SupportedOption` |
| Model class | `AbstractOpenAiCompatibleTextGenerationModel` (zai-openai) / `AbstractApiBasedModel` | Request build + response parse per capability |
| Authentication | `ApiKeyRequestAuthentication` subclass (optional) | Header injection |

z.ai OpenAI-compatible surface can reuse the SDK's `AbstractOpenAiCompatible*` base classes
(same as the community Ollama plugin). The Anthropic-compatible surface mirrors the official
`ai-provider-for-anthropic` plugin (messages API, `x-api-key`-style headers — see §3.2).

---

## 3. z.ai — verified endpoint facts

Verified 2026-08-30 via live probes (HTTP status on unauthenticated/dummy-token requests)
and production usage (`~/.local/bin/claude-glm`, `deicod/zai`).

### 3.1 Endpoint matrix

| Surface | Plan | International (default) | China (optional) |
|---|---|---|---|
| OpenAI-compatible | Coding (default) | `https://api.z.ai/api/coding/paas/v4` | `https://open.bigmodel.cn/api/coding/paas/v4` |
| OpenAI-compatible | General (pay-as-you-go) | `https://api.z.ai/api/paas/v4` | `https://open.bigmodel.cn/api/paas/v4` |
| Anthropic-compatible | Coding | `https://api.z.ai/api/coding/anthropic` | `https://open.bigmodel.cn/api/coding/anthropic` |
| Anthropic-compatible | General | `https://api.z.ai/api/anthropic` | `https://open.bigmodel.cn/api/anthropic` |

Probe evidence: all six hosts+paths exist (401 «token expired or incorrect» with dummy
Bearer = auth reached, not 404); `/v1/models` under `api.z.ai/api/anthropic` and
`api.z.ai/api/coding/anthropic` respond 401/200-shaped; `deicod/zai` defaults to
`api.z.ai/api/coding/paas/v4` with `DefaultBaseURLGeneral` override; `claude-glm` uses
`api.z.ai/api/anthropic` daily.

### 3.2 Auth & protocol details

- **Auth on BOTH surfaces:** `Authorization: Bearer <key>` (the Anthropic-style surface
  accepts Bearer — production-proven via `ANTHROPIC_AUTH_TOKEN`; `x-api-key` support
  unverified → **do not rely on it**, use Bearer like the Go client).
- OpenAI surface: `/chat/completions`, `/models`; SSE streaming; 429/5xx retry is policy
  in `deicod/zai` (client-side retries enabled by default) — the WP SDK handles its own
  transport, no retry port needed in v1.
- Anthropic surface: `/v1/messages`, `/v1/models`; `anthropic-version` header not required
  by z.ai (Bearer only) — send `anthropic-version: 2023-06-01` anyway for safety (harmless).
- **Same API key works across coding/general and openai/anthropic surfaces on the same
  account/region.** Intl (z.ai) and China (bigmodel.cn) keys/accounts are **separate**.

### 3.3 Plan & region model (per user requirements)

- **Defaults: Coding Plan + International.** "General API" and "China region" are opt-in
  selections (plugin settings), because:
  - Coding Plan is subscription-backed (cheaper/included usage, restricted to coding-suitable
    GLM models — e.g. `glm-4.5-air`, `glm-5.x`).
  - General API is pay-as-you-go, full model catalog.
- Settings per provider (zai, zai_anthropic), stored as WP options:
  - `zai_connector_{provider}_plan` ∈ `coding` (default) | `general`
  - `zai_connector_{provider}_region` ∈ `intl` (default) | `cn`
- URL resolution is **runtime** (option read per request build in the model/directory layer),
  NOT via static `baseUrl()` dispatch — `AbstractApiProvider::baseUrl()` stays per-surface
  (returns intl-general as canonical) and request URLs are assembled from the active
  plan×region matrix. Admin UI: simple settings section (two dropdowns) — core Connectors
  screen has no concept of per-connector options beyond the key.
- Models:
  - **Open question O1:** does `/models` exist on the coding base URLs with a valid key?
    Probes can't tell (401). If yes → dynamic directory. If no → static directory per plan
    (coding: GLM 5.x family; general: full list) with SDK-style sort (newest GLM first).
    Static fallback is the safe default; verify during M1 implementation.
  - Capabilities v1: `textGeneration`, `chatHistory`. Options: systemInstruction,
    temperature, maxTokens, topP, stopSequences, outputMimeType (json), outputSchema,
    functionDeclarations (tools), inputModalities (text; image if model supports).
    Image generation (CogView) / embeddings (embedding-3) via general API = later milestone,
    not in v1.

---

## 4. OAuth providers — verified flow facts

All parameters below are **battle-proven** (Hermes Agent ships these exact flows in
production; extracted from its source on this host, 2026-08-30). None of the three
providers offers officially documented public OAuth for API use — all flows originate from
first-party CLI clients. Flag ToS risk in README (§8).

### 4.1 OpenAI / Codex (`provider id: codex`)

Device-authorization flow (best fit for WP: no redirect URI, no browser callback needed):

```
1. POST https://auth.openai.com/api/accounts/deviceauth/usercode
   JSON {"client_id": "app_EMoamEEZ73f0CkXaXp7hrann"}
   → {user_code, device_auth_id, interval}
2. Admin opens https://auth.openai.com/codex/device and enters user_code
3. Poll POST https://auth.openai.com/api/accounts/deviceauth/token
   JSON {"device_auth_id", "user_code"}   (interval ≥ 3s; 403/404 = keep waiting; 15 min cap)
   → {authorization_code, code_verifier}
4. Exchange POST https://auth.openai.com/oauth/token   (x-www-form-urlencoded)
   grant_type=authorization_code, code, redirect_uri=https://auth.openai.com/deviceauth/callback,
   client_id=app_EMoamEEZ73f0CkXaXp7hrann, code_verifier
   → {access_token, refresh_token, id_token, expires_in}
5. Refresh: same endpoint, grant_type=refresh_token&refresh_token=…&client_id=…
```

- Rate limits: both usercode and token endpoints throttle with HTTP 429 → retry with
  `Retry-After` backoff (2s/4s/8s cap 60s), max 4 attempts; surface clear error, don't loop.
- Inference: `https://chatgpt.com/backend-api/codex/responses` (Responses endpoint — service
  base plus `/responses` path; NOT api.openai.com).
  Bearer = OAuth access token. v1 = text generation + chat history via Responses-shaped
  adapter; model list static (gpt-5.x codex family) — O2 open: discover via
  `/backend-api/codex/models` if reachable with token.
- Access tokens are short-lived JWTs (ChatGPT account claims inside); refresh skew 120s.

### 4.2 xAI / Grok (`provider id: grok`)

Device-code flow per RFC 8628 against `https://auth.x.ai`:

```
1. POST https://auth.x.ai/oauth2/device/code
   client_id=b1a00492-073a-47ea-816f-4c329264a828
   scope="openid profile email offline_access grok-cli:access api:access"
   → {device_code, user_code, verification_uri, interval, expires_in}
2. Admin opens verification_uri, enters user_code
3. Poll POST https://auth.x.ai/oauth2/token  grant_type=urn:ietf:params:oauth:grant-type:device_code
   device_code + client_id  → tokens (403 authorization_pending = keep polling)
4. Refresh: grant_type=refresh_token&client_id=…&refresh_token=…
```

- Discovery doc: `https://auth.x.ai/.well-known/openid-configuration` (fetch at runtime,
  fall back to hardcoded endpoints).
- Inference: `https://api.x.ai/v1` — **Responses-style endpoint** (Hermes reuses its
  `codex_responses` adapter for it): reasoning, tools, streaming, caching work. v1 adapter:
  Responses shape (same adapter as codex, parameterized base URL) — big synergy.
- Access tokens ~6h → refresh skew 3600s (refresh up to 1h early; keeps cron/gated usage warm).
- Known risk: xAI allowlists OAuth API surface by tier; SuperGrok may get HTTP 403
  (entitlement issue, not our bug) → surface as typed error with hint. Default model
  `grok-4.6`; model list static v1.

### 4.3 Anthropic / Claude Pro-Max (`provider id: claude_pro`) — bonus

PKCE authorization-code with **paste-back code** (no callback server needed — ideal for WP):

```
1. Generate PKCE (S256) + state.
2. Admin opens:
   https://claude.ai/oauth/authorize?code=true&client_id=9d1c250a-e61b-44d9-88ed-5944d1962f5e
     &response_type=code&redirect_uri=https://console.anthropic.com/oauth/code/callback
     &scope=org:create_api_key%20user:profile%20user:inference
     &code_challenge=…&code_challenge_method=S256&state=…
   → page shows "code#state" to copy.
3. Admin pastes code#state into plugin page. Validate state (CSRF).
4. Exchange POST https://console.anthropic.com/v1/oauth/token  (JSON)
   {grant_type: authorization_code, client_id, code, state, redirect_uri, code_verifier}
5. Refresh: same endpoint, grant_type=refresh_token (JSON body), client_id + refresh_token.
```

- **Token-endpoint User-Agent must NOT start with `claude-code/`** (Anthropic 429-throttles
  those UAs on the token endpoint; verified empirically in Hermes). Use a plain UA like
  `axios/1.7.9`-style or `wp-connectors/1.0`. Inference calls are not throttled this way.
- Inference: `https://api.anthropic.com/v1/messages`, `Authorization: Bearer <oauth token>`
  + header `anthropic-beta: oauth-2025-04-20`. Model list: static v1 (Claude 4/5 family,
  sorted like the official anthropic plugin: newest sonnet first).

### 4.4 Shared OAuth runtime (identical for #3–#5)

- Token store: single WP option per provider, **encrypted at rest** with
  libsodium (`sodium_crypto_secretbox`) via core-bundled sodium_compat; key derived from
  `AUTH_KEY`-style salts. If salts are unusable, the fallback key MUST come from an external
  source outside the WordPress database (e.g. `WP_CONNECTORS_*_KEY` constant or env var);
  if none exists, **fail closed** (provider unavailable, clear admin notice) — never persist a
  decrypt-capable key beside the ciphertext. Never exposed via REST/settings API.
- Lazy refresh on use (expiry − skew) + `wp_schedule_single_event` background refresh as
  backup; 401 during inference triggers one refresh+retry, then typed error.
- Terminal refresh failure = definitive authorization errors only (`invalid_grant`, revoked
  grant): mark grant dead, show
  "Re-connect required" on admin page + connector availability = false. HTTP 429 and other
  transient/transport failures stay retryable (bounded cooldown, honor `Retry-After`).
- Availability check (`ProviderAvailabilityInterface`): token present && (refresh succeeds
  || cheap model-list/inference probe). Core Connectors screen shows status from this.
- Admin page per plugin (submenu under Settings): provider status, Connect/Re-connect,
  Revoke (delete tokens), account label if derivable (e.g. JWT claims email for codex/xai).
- Capability `manage_options` guards everything; nonces on all actions.

---

## 5. Repository layout

```
wp-connectors/
├── docs/
│   └── specs/
│       └── SPEC.md              ← this file (more specs land here per connector family)
├── connectors/
│   ├── zai/                     ← plugin 1: "deicod Connectors: z.ai" (providers zai + zai_anthropic)
│   │   ├── zai-connector.php    (plugin header, registers 2 providers @ init prio 5)
│   │   ├── src/…                (Provider/, Models/, Metadata/, Authentication/, Settings/)
│   │   └── assets/logo-zai.svg
│   ├── openai-oauth/            ← plugin 2: provider codex
│   ├── xai-oauth/               ← plugin 3: provider grok
│   └── anthropic-oauth/         ← plugin 4 (bonus): provider claude_pro
├── shared/                      ← source-only OAuth/token helper lib; build step copies into
│                                  each plugin (WP plugins must stay self-contained; no runtime
│                                  cross-plugin includes)
├── .github/workflows/           ← lint (phpcs WP), php -l matrix, plugin zip artifacts (later)
└── README.md
```

Rules:
- Each plugin is standalone-drop-in (no Composer autoloader requirement at runtime —
  simple `src/autoload.php` classmap, like the official provider plugins).
- `Requires at least: 6.9` (matching the official provider plugins) — on 7.0 the SDK ships in
  core, on 6.9 the standalone PHP AI Client plugin must be active; guard with
  `class_exists(AiClient::class)` and admin notice if missing.
- `Requires PHP: 7.4` (SDK minimum). Use `wp_remote_*` for all HTTP (no cURL ext dependency).
- Plugin text domains per plugin; English strings, `__()` wrapped from day one.
- Namespace per plugin: `Deicod\WpConnectors\Zai`, `…\OpenAiOauth`, `…\XaiOauth`, `…\AnthropicOauth`.

---

## 6. Non-functional requirements

### 6.1 Security
- No secrets in logs; mask tokens/keys (`…last4`).
- All admin actions nonce'd + `current_user_can('manage_options')`.
- OAuth state validated (CSRF); PKCE everywhere it exists in the source flow.
- Token encryption at rest (§4.4). Connector API keys (z.ai) stay in core's store (user's
  choice; note in README that AI-Experiments plugin can encrypt those).

### 6.2 Observability
- Optional debug log (option-gated) of request method/URL/status/duration — never payloads
  with auth headers.
- Typed WP_Error messages mapping provider errors (401 → re-auth hint, 403 xAI → tier hint,
  429 → rate limit, 5xx → upstream).

### 6.3 Compatibility
- WordPress 7.0+ (6.9 with standalone SDK plugin), PHP 7.4–8.4, no cURL ext needed.
- Multisite: per-site (network-activated = per-site settings; document, don't special-case v1).

---

## 7. Milestones & acceptance criteria

**M1 — zai (OpenAI-compatible)**
- Plugin activates clean on WP 7.0; card "z.ai" on Settings → Connectors with key field.
- Plan/region dropdowns (Settings page) default coding+intl; switching re-targets requests
  (verified by request-log/debug output).
- `wp_ai_client_prompt('…')->using_provider('zai')->generate_text()` works on coding+intl
  with a Coding Plan key; O1 resolved (dynamic vs static model list, documented in code).
- Invalid key → core screen shows not-connected; clear WP_Error.

**M2 — zai_anthropic**
- Card "z.ai (Anthropic API)"; Claude-Code-style workloads (system instruction, tools,
  JSON output) work via `generate_text()` incl. `outputSchema`.
- Same plan/region matrix incl. coding-anthropic base URL.

**M3 — codex (OpenAI OAuth)**
- Device flow from plugin admin page (URL + code shown; poll; token stored encrypted).
- Token auto-refresh incl. skew; revocation clears store; status on core Connectors card
  via availability check.
- Text generation through `wp_ai_client_prompt()`; 429 login throttle handled with backoff.

**M4 — grok (xAI OAuth)**
- Same as M3 with xAI device flow; discovery-doc fetch with hardcoded fallback.
- Responses-adapter shared with M3 (parameterized base URL) — dedupe into shared lib.
- 403 tier error surfaced as typed message.

**M5 — claude_pro (bonus)**
- PKCE + paste-code flow; token-endpoint UA rule respected.
- Inference with `anthropic-beta: oauth-2025-04-20`; refresh loop; revoke.

**M6 — repo hardening**
- CI (phpcs, php -l), plugin zip artifacts per connector, README badges, deploy story
  (wordpress.org SVN submission) decided.

---

## 8. Risks & disclosures

| Risk | Impact | Mitigation |
|---|---|---|
| Client IDs are extracted from first-party CLIs (unofficial, may rotate) | Login breaks | Keep IDs in one constants file per plugin; release note + quick patch path |
| Subscription-endpoint usage outside official clients (ChatGPT/Claude/Grok plans) may violate provider ToS | Account risk for end users | Prominent README disclosure + admin-page notice; opt-in usage |
| xAI OAuth tier allowlist (HTTP 403 for some SuperGrok tiers) | Support load | Typed error with explanation + link |
| Anthropic token-endpoint UA throttling | Silent login failures | UA allowlist rule in shared lib (§4.3) |
| z.ai coding-plan model list unknown (O1) | Directory design | Static fallback shipped; probe at runtime when key present |
| Core Connectors API evolves (encryption ticket #64789, more auth methods) | Rework | Follow make/core; SPEC revisited per milestone |

---

## 9. Open questions

- **O1:** `/models` on coding base URLs (both protocols) with valid key? → resolve in M1.
- **O2:** Codex model discovery via `chatgpt.com/backend-api/codex/models`? → M3.
- **O3:** z.ai `x-api-key` header support on Anthropic surface (nice-to-have, Bearer suffices).
- **O4:** CogView (image) / embedding models on general API — defer post-v1, directory is
  capability-driven so extension is additive.
- **O5:** wordpress.org hosting (per-plugin slugs under one org account) vs GitHub-only
  distribution zips → decide at M6.

---

## Appendix A — Source index

- WP 7.0 Connectors API dev note: make.wordpress.org/core/2026/03/18/…
- WP 7.0 AI Client dev note: make.wordpress.org/core/2026/03/24/…
- Core source: `wp-includes/connectors.php`, `wp-includes/ai-client.php` (7.0 branch)
- SDK: github.com/WordPress/php-ai-client (trunk)
- Reference provider plugin: github.com/WordPress/ai-provider-for-anthropic (v1.0.4)
- Community connectors (Ollama keyless precedent): make.wordpress.org/ai/2026/03/25/…
- z.ai Go client (endpoint defaults): github.com/deicod/zai
- OAuth flow parameters: verified against Hermes Agent implementation (auth flows in
  production use) — OpenAI device flow, xAI device flow, Anthropic PKCE incl. UA rule.
- Live endpoint probes: 2026-08-30, see §3.1.
