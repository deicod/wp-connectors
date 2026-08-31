=== z.ai ===
Contributors: deicod
Tags: ai, connectors, zai, glm, openai-compatible, anthropic-compatible
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://spdx.org/licenses/GPL-2.0-or-later.html

z.ai GLM models for the WordPress AI Client via the OpenAI-compatible AND Anthropic-compatible APIs — coding or general plan, international or China region, selected independently per provider.

== Description ==

This plugin registers two providers with the PHP AI Client SDK that ships
in WordPress 7.0 (and is available for WordPress 6.9 as the standalone
PHP AI Client plugin). Once active, two cards appear on
Settings → Connectors — **z.ai** and **z.ai (Anthropic API)** — each with
its own API-key field; GLM models then work everywhere
`wp_ai_client_prompt()` is used, whichever surface you pick.

Both cards work with the SAME z.ai account key (international or China —
the regions use separate accounts and separate keys). The surfaces differ
in protocol: `zai` speaks the OpenAI-compatible API, `zai_anthropic`
speaks the Anthropic-compatible Messages API that Claude-Code-style
workloads use (system instructions, tool calling, JSON output).

Each provider has its OWN plan/region selection (Settings → z.ai shows one
section per provider — the two never influence each other):

* **API plan** — per provider: Coding Plan (subscription-backed,
  restricted to coding-suitable GLM models) or General API
  (pay-as-you-go, full catalog). The `zai` provider defaults to Coding
  Plan; the `zai_anthropic` provider defaults to **General API**, because
  z.ai's coding-plan Anthropic endpoint could not generate during our
  2026-08-31 live probes (it answered with wrapped 404 errors) while the
  general endpoint worked — including for Coding-Plan keys. See
  `docs/architecture/0007-zai-anthropic-surface.md` in the repository.
* **Account region** — International (api.z.ai, default) or China
  (open.bigmodel.cn).

Endpoint URLs are resolved per request, so changing a setting retargets
the very next request without any rebuild.

**Important: the two regions are separate accounts with separate API keys.**
International keys do not work on the China endpoint and vice versa. After
switching a provider's region, THAT provider disconnects until you save a
key for the new region on the Connectors screen (the other provider is not
affected).

= API key storage disclosure =

Each API key is stored by WordPress core in its own option
(`connectors_ai_zai_api_key` and `connectors_ai_zai_anthropic_api_key`,
site options tables), **not encrypted** (core masks them in the UI only).
This follows the native Connectors API behavior; if you need encryption at
rest, a separate security plugin can encrypt core options. Alternatively,
define the constant or environment variable `ZAI_API_KEY` (zai) or
`ZAI_ANTHROPIC_API_KEY` (zai_anthropic) before WordPress loads and no key
is stored in the database at all.

The plugin itself never writes the key options. The single exception:
switching a provider's account region deletes that provider's stored key —
the international (z.ai) and China (bigmodel.cn) accounts are separate, so
an international key must never be sent to the China endpoint and vice
versa; save a key for the new region afterwards. Its own options store
only one-way hashes used to remember whether the current key was
validated (never the keys), your plan/region selections, and — if enabled —
the debug log.

= Model discovery =

Each provider's model list is fetched live from its models endpoint when
your key validates (cached 12 hours per provider, plan, and region — a
settings change never serves a stale list). `zai` uses
`{base}/models` (OpenAI shape); `zai_anthropic` uses `{base}/v1/models`
(Anthropic shape) — live-verified 2026-08-31 to return the same 10-model
GLM list on both plans. On the zai_anthropic provider the discovered list
is intersected with the selected plan's catalog, so the Coding Plan card
never advertises general-only GLM 4.x models even though the live route
returns them. When discovery fails — no key yet, wrong-region
key, network error — each provider falls back to its built-in per-plan
catalog: coding shows the GLM 5.x family; general shows the full observed
GLM list. Capabilities are conservative: text generation and chat history,
text input only. Image/file inputs are rejected before any request is
sent, because no z.ai evidence for image support on these models exists
yet.

= Structured output (zai_anthropic) =

The Messages adapter maps `outputMimeType: application/json` plus an
`outputSchema` to JSON guidance in the system prompt (the schema is
embedded verbatim). z.ai's support for Anthropic's native
`output_format`/beta-header structured outputs is unverified, so the
guidance encoding is used: it behaves identically on every
Messages-compatible endpoint.

= Multisite =

Settings and keys are per-site (`get_option` / `update_option` scope). On
a network-activated install every site configures and stores its own keys,
plans, and regions — independently for both providers. Network activation
does not share credentials between sites. Uninstalling a network-activated
plugin removes the plugin-owned options and caches from every site on the
network.

== Installation ==

1. WordPress 7.0+: just activate. WordPress 6.9: install and activate the
   standalone PHP AI Client plugin first.
2. Go to Settings → Connectors and enter your z.ai API key on the card of
   the surface you want (or both — one key works for both cards).
3. Optionally adjust each provider's plan/region under Settings → z.ai.
4. Verify the Connectors screen shows your card(s) as connected (this
   performs one authenticated request to the models endpoint; it checks
   the key's authentication only — a plan/balance mismatch first shows up
   on generation as a 429 error, see the FAQ).

== Frequently Asked Questions ==

= Why two cards, and do I need both? =

One plugin, two protocols. Use `zai` for OpenAI-style integrations and
`zai_anthropic` for Anthropic/Claude-Code-style integrations (system
instructions, tools, JSON output via `outputSchema`). You can connect one
or both; each card validates and uses your key independently.

= Why does zai_anthropic default to the General API plan? =

During our 2026-08-31 live probes, z.ai's coding-plan Anthropic endpoints
returned wrapped 404 errors for every generation request, while the
general Anthropic endpoint worked — including with a Coding-Plan key
(which is also how the `claude-glm` CLI wrapper uses it). The default
therefore selects the working endpoint. You can still select Coding Plan;
if z.ai fixes its coding Anthropic surface, generations will start
working there without any plugin change.

= The card says not connected but I entered a key =

The connector validates the key with an authenticated request against the
currently selected endpoint — an authentication check only. A
wrong-region key (international key while the China region is selected)
or a revoked key reports not connected; fix the region selection or use a
matching key. A validated key on ONE card never makes the OTHER card
connected — each provider validates independently.

= Requests fail with "rate limiting (429)" =

z.ai returns HTTP 429 both for real rate limits and for plan mismatches —
a Coding Plan key used on the General API endpoint receives 429 with
error code 1113 ("Insufficient balance or no resource package"). Switch
the plan of the affected provider, or use a key with pay-as-you-go
balance. The plugin does not retry automatically.

= Where are errors and logs? =

Enable "Debug logging" under Settings → z.ai to record each request's
method, endpoint (query string removed), status, and duration — nothing
else. No keys, prompts, or responses are ever recorded. Saving the
setting as disabled clears the log.

= How do I remove all data? =

Deactivation keeps your settings. Uninstalling removes the plugin-owned
options (both providers' plan/region selections, key-validation states,
region-pending flags, debug switch and log) and the model-discovery
caches — on multisite, from every site on the network. The API key
options are owned by WordPress core and are left in place; delete them
from the Connectors screen if desired.

= What do error messages and codes look like? =

Through WordPress's own prompt API (`wp_ai_client_prompt()`), errors come
back as `WP_Error` values with WordPress core's codes (for example
`prompt_client_error`) — the message is the plugin's redacted, actionable
text and the HTTP status is preserved. Code that calls the model directly
can use `generate_text()` for the plugin's own stable codes —
`zai_unauthorized`, `zai_rate_limited`, `zai_upstream_error`, ... — which
BOTH providers share (one catalog, one redaction path; the plugin is one
unit). Always obtain the model through the AI Client registry —
`AiClient::defaultRegistry()->getProviderModel( 'zai_anthropic', 'glm-5.3' )` —
which binds the HTTP transporter and request authentication for you; a
model built by calling the provider's `model()` directly is unbound, and
its generation fails before any request is sent. Upstream response bodies
never appear in any message.

= Examples =

System instruction and conversation (zai_anthropic):

    $model = AiClient::defaultRegistry()->getProviderModel( 'zai_anthropic', 'glm-5.3' );
    $model->setConfig( ModelConfig::fromArray( array(
        'systemInstruction' => 'You are a terse WordPress expert.',
        'maxTokens'         => 512,
    ) ) );
    $result = $model->generate_text( array(
        new Message( MessageRoleEnum::user(), array( new MessagePart( 'What is the Connectors screen?' ) ) ),
    ) );

Structured output (JSON schema guidance):

    $model->setConfig( ModelConfig::fromArray( array(
        'outputMimeType' => 'application/json',
        'outputSchema'   => array(
            'type'       => 'object',
            'properties' => array( 'answer' => array( 'type' => 'string' ) ),
            'required'   => array( 'answer' ),
        ),
    ) ) );

Tools (function calling):

    $model->setConfig( ModelConfig::fromArray( array(
        'functionDeclarations' => array(
            ( new FunctionDeclaration( 'get_weather', 'Get the weather for a city', array(
                'type'       => 'object',
                'properties' => array( 'city' => array( 'type' => 'string' ) ),
                'required'   => array( 'city' ),
            ) ) )->toArray(),
        ),
    ) ) );
    // ...inspect $result->toMessage()->getParts() for FunctionCall parts, then
    // send the tool result back as a FunctionResponse part in a user message.

Or through core's prompt builder:

    $text = wp_ai_client_prompt( 'Capital of France? One word.' )
        ->using_provider( 'zai_anthropic' )
        ->generate_text();

== Changelog ==

= 0.1.0 =
* Initial milestone-1 release: `zai` provider, plan/region endpoint
  selection, validated key availability, live model discovery with static
  fallback, text generation with tool calls and structured output, SSE
  streaming aggregation, typed errors, option-gated debug logging.
* Milestone-2 (in development, unreleased): second provider
  `zai_anthropic` (card "z.ai (Anthropic API)") — Messages protocol with
  tools, tool results, JSON output guidance + outputSchema, independent
  plan/region selection (general+intl default per live evidence),
  Anthropic SSE aggregation, and per-provider availability/key handling.
