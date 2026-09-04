# Refutation Ledger — PR #3 review loop

Purpose: single source of truth for review candidates that were **refuted or consciously accepted** during this branch's review loop (Codex R1–R20, claude-glm GLM1–GLM11). Re-open an entry ONLY with new evidence (empirical repro, vendor-doc proof, or a demonstrated production path) — not by re-deriving the same hypothetical. Reviewers: read before flagging.

## Behavior decisions (test-pinned or vendor-documented)

- **Post-`[DONE]` usage/finish frames are merged** (GLM6 #2, glm6-2): appending-gateway behavior documented in repo records; post-sentinel data completes the payload. Do not flag post-sentinel merging as data-loss.
- **Legacy-zai usage semantics: absent/null members → 0** (GLM7 #8, glm7-8): master parity; the Anthropic-surface strictness is the newer design and does NOT apply to the legacy zai surface.
- **Explicit `stop_reason: null` is schema-legal** (GLM8 #4 + GLM10-era streamed twin): Anthropic types stop_reason string|null; accepted, mapped as successful stop. Not missing-data corruption.
- **Strict `is_int` index rejection** (GLM7 #1, pinned): malformed choice/block indexes are typed rejections, not int-coerced. Deliberate hardening vs master.
- **Plan-intersected discovery catalog** (pinned): /models advertising is filtered to the configured plan on both surfaces by design.
- **First-registration-wins provider skip**: registering a second provider with the same ID is silently ignored (registry semantics), documented.
- **Plain unescaped exception messages for constant-only strings** (GLM9-ref): messages built from constant-only strings need no esc_html__; escaping applies to interpolated data.
- **Falsy unsupported-option values ignored** (GLM5-era, re-refuted GLM9): setLogprobs(false) etc. are neutral no-ops, not errors.
- **System-role coercion unreachable via SDK enum** (GLM9-ref): the SDK MessageRole enum cannot express the flagged input; not triggerable.
- **Empty-arguments strings keep null-args semantics** (glm6-15): an empty string arguments field is not corruption; it denotes a no-argument call.
- **`items: []` with additionalItems sibling keeps tuple semantics** (glm10-v2): the empty array is data, not a missing object schema.
- **Bare `event:` wire format absent in production** (GLM1 #14): live z.ai SSE capture (70 frames) contained zero bare event: lines; the handling exists as wire-robustness, not a vendor-verified feature.
- **Whitespace-then-BOM sniff claim** (GLM6-ref): refuted in that round; the GLM8 #2 canonical-prefix fix later made the layers consistent — flag the layers ONLY if a new divergence appears.
- **Binding race in record_definitive_verdict** (GLM8-verifier-ref): not reachable on real WordPress (per-request option pinning); harness-only artifact.
- **Version bump on sub-PR releases** (GLM3-ref): consciously deferred to M7 release tooling, not a review finding.
- **Unknown content-block types dropped → stop-reason consistency documented** (GLM1 #15, commit 2cf214c): vendor-quirk dependent, not triggerable via own requests; documented in code, not fixed.
- **zai-surface `has_more` forever-degradation** (GLM3 #14-tenant, R15 design): conscious design (R15); degradation path documented. Do not re-flag as a bug; propose as an improvement only with new impact evidence.

## Verification-protocol decisions

- **Verification verdicts come from transcripts, not subagent vote tallies** (GLM1 lesson): the 12/3 CONFIRMED/PLAUSIBLE tally was corrected from a 9/2/4 vote read.
- **Empiricism over introspection** (GLM1 #14 lesson): wire-format claims are settled by live capture (curl), not model self-knowledge.
- **Test pins may be consciously superseded** (GLM10 #4 lesson): when an invariant has been extended twice (glm9-16), superseding the older pin is correct — document the supersession in the CHANGELOG.

## Drift-guard scope (post-GLM11)

- The label drift guard covers the model + discovery paths (ZaiModelListParser, both metadata directories, availability base). The **endpoint layer deliberately brands UNKNOWN_ENDPOINT_LABEL with 'z.ai'/'z.ai Anthropic'** (ZaiEndpoint.php:123, ZaiAnthropicEndpoint.php:150) — that is product naming, not drift; do not flag.

## Known deferred items (improvements, not bugs)

- GLM2's 5 minor cleanup items + latent #8 (duplicate settings errors masked by wp_die) — deferred by user triage.
- GLM8-perf trio (negative-caching conflict with M1 decision; /models parsing duplication was since fixed in glm1-13) — superseded or deferred.
- GLM9 cut-over-cap items: credential/hook/render twins, is_object_shape() vs JsonShape::is_list() (since unified glm9), triple property_exists, usage-oracle duplication (since unified), whole-payload encode oracle on zai streamed path (since swapped glm10-10), per-call chat-ID set rebuild, ~15 source-pinning tests, $ships_forwarded_values hand-flag, live probe's nine surface ternaries (since table-ized glm10-15).

Ledger maintained by the review loop; update when a decision changes, not when a reviewer disagrees.
