# 0006 — z.ai `/models` evidence (open question O1: RESOLVED for intl)

**Probe date:** 2026-08-30 (live requests with a real API key; no credentials, tokens,
or headers were recorded — only the model lists below).

**Evidence (kept outside the repo, no credentials):**
`~/wp-ai-research/zai/models-coding-intl.json`,
`~/wp-ai-research/zai/models-general-intl.json`.

## Result

`GET {base}/models` returns **HTTP 200** with the standard OpenAI shape
(`{"object":"list","data":[{"id":…,"object":"model","created":…,"owned_by":"z-ai"}…]}`)
on **both** international endpoints:

- Coding + intl: `https://api.z.ai/api/coding/paas/v4/models`
- General + intl: `https://api.z.ai/api/paas/v4/models`

Both responses list the **same 10 models**:

`glm-4.5`, `glm-4.5-air`, `glm-4.6`, `glm-4.7`, `glm-5`, `glm-5-turbo`, `glm-5.1`,
`glm-5.2`, `glm-5.3`, `glm-5.3-flash`

(each entry carries an `owned_by: "z-ai"` and a unix `created` timestamp; the
evidence JSONs hold the raw values).

## Consequences for implementation

1. **O1 (OpenAI surface) is resolved: `/models` exists and works** — including on the
   coding base URL, which was the open part. M1 implements a dynamic
   `AbstractOpenAiCompatibleModelMetadataDirectory` with a validated, cached
   discovery response merged with capability metadata, **plus** the tested
   plan-partitioned static fallback (SPEC §3.3): discovery failure, 401/404/malformed
   responses, or an unprobed endpoint must never make the provider unusable.
2. **Still unprobed (fallback stays authoritative there):** the `cn` region
   (`open.bigmodel.cn`) and the Anthropic-compatible surface's `/v1/models`. The
   evidence says nothing about those; do not advertise discovery behavior for them
   beyond the fallback. A later credentialed probe may extend this record.
3. The identical 10-model list across coding/general intl **does not collapse the
   plan-partitioned fallback catalogs**: coding subscriptions expose a restricted
   model set (SPEC §3.3), and the observed equality is a point-in-time fact, not a
   contract. The static fallbacks stay separate data per plan; discovery results are
   cached per endpoint identity (provider+plan+region in the cache key).
4. The response carries **no capability metadata** (OpenAI shape) — capabilities/
   options are attached from our own catalog data, exactly as the official Anthropic
   plugin hardcodes capabilities for its discovered models (record 0002).
