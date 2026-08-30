=== z.ai ===
Contributors: deicod
Tags: ai, connectors, zai, glm, openai-compatible
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://spdx.org/licenses/GPL-2.0-or-later.html

z.ai GLM models for the WordPress AI Client via the OpenAI-compatible API — coding or general plan, international or China region.

== Description ==

This plugin registers the `zai` provider with the PHP AI Client SDK that
ships in WordPress 7.0 (and is available for WordPress 6.9 as the standalone
PHP AI Client plugin). Once active, a "z.ai" card appears on
Settings → Connectors where you paste your z.ai API key; GLM models then
work everywhere `wp_ai_client_prompt()` is used.

Two settings (Settings → z.ai) select which z.ai endpoint your key is used
against:

* **API plan** — Coding Plan (default; subscription-backed, restricted to
  coding-suitable GLM models) or General API (pay-as-you-go, full catalog).
* **Account region** — International (api.z.ai, default) or China
  (open.bigmodel.cn).

Endpoint URLs are resolved per request, so changing a setting retargets the
very next request without any rebuild.

**Important: the two regions are separate accounts with separate API keys.**
International keys do not work on the China endpoint and vice versa. After
switching the region the connector disconnects until you save a key for the
new region on the Connectors screen.

= API key storage disclosure =

The API key is stored by WordPress core in the `connectors_ai_zai_api_key`
option (site options table), **not encrypted** (core masks it in the UI
only). This follows the native Connectors API behavior; if you need
encryption at rest, a separate security plugin can encrypt core options.
Alternatively, define the constant or environment variable `ZAI_API_KEY`
before WordPress loads and no key is stored in the database at all.

The plugin itself never writes the key option. The single exception: switching
the account region deletes the stored key — the international (z.ai) and China
(bigmodel.cn) accounts are separate, so an international key must never be sent
to the China endpoint and vice versa; save a key for the new region afterwards.
Its own options store only a one-way hash used to remember whether the current
key was validated (never the key), your plan/region selection, and — if
enabled — the debug log.

= Model discovery =

The model list is fetched live from the `{base}/models` endpoint when your
key validates (cached 12 hours per endpoint). When discovery fails — no key
yet, wrong-region key, network error — the plugin falls back to a built-in
per-plan catalog: coding shows the GLM 5.x family; general shows the full
observed GLM list. Capabilities are conservative: text generation and chat
history, text input only. Image/file inputs are rejected before any request
is sent, because no z.ai evidence for image support on these models exists
yet.

= Multisite =

Settings and the key are per-site (`get_option` / `update_option` scope).
On a network-activated install every site configures and stores its own key,
plan, and region. Network activation does not share credentials between
sites. Uninstalling a network-activated plugin removes the plugin-owned
options and caches from every site on the network.

== Installation ==

1. WordPress 7.0+: just activate. WordPress 6.9: install and activate the
   standalone PHP AI Client plugin first.
2. Go to Settings → Connectors and enter your z.ai API key.
3. Optionally adjust plan/region under Settings → z.ai.
4. Verify the Connectors screen shows z.ai as connected (this performs one
   authenticated request to the /models endpoint).

== Frequently Asked Questions ==

= The card says not connected but I entered a key =

The connector validates the key with an authenticated request against the
currently selected endpoint. A wrong-region key (international key while the
China region is selected), a revoked key, or a key without access to the
selected plan all report not connected. Fix the region/plan selection or use
a matching key.

= Requests fail with "rate limiting (429)" =

z.ai returns HTTP 429 both for real rate limits and for plan mismatches — a
Coding Plan key used on the General API endpoint receives 429 with error
code 1113 ("Insufficient balance or no resource package"). Switch the plan
back to Coding Plan, or use a key with pay-as-you-go balance. The plugin
does not retry automatically.

= Where are errors and logs? =

Enable "Debug logging" under Settings → z.ai to record each request's
method, endpoint (query string removed), status, and duration — nothing
else. No keys, prompts, or responses are ever recorded. Saving the setting
as disabled clears the log.

= How do I remove all data? =

Deactivation keeps your settings. Uninstalling removes the plugin-owned
options (plan, region, debug switch and log, key-validation state) and the
model-discovery cache — on multisite, from every site on the network. The
API key option is owned by WordPress core and is left in place; delete it
from the Connectors screen if desired.

= What do error messages and codes look like? =

Through WordPress's own prompt API (`wp_ai_client_prompt()`), errors come
back as `WP_Error` values with WordPress core's codes (for example
`prompt_client_error`) — the message is the plugin's redacted, actionable
text and the HTTP status is preserved. Code that calls the model directly
(`ZaiProvider::model()`) can use `generate_text()` / `ErrorMapper` for the
plugin's own stable codes (`zai_unauthorized`, `zai_rate_limited`,
`zai_upstream_error`, ...). Upstream response bodies never appear in any
message.

== Changelog ==

= 0.1.0 =
* Initial milestone-1 release: `zai` provider, plan/region endpoint
  selection, validated key availability, live model discovery with static
  fallback, text generation with tool calls and structured output, SSE
  streaming aggregation, typed errors, option-gated debug logging.
