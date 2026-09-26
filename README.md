# wp-connectors

> [!CAUTION]
> **RETIRED — A cautionary tale about recursive AI code reviews.**
>
> We set out to implement OAuth.
>
> **The reviewers implemented PHP.**

This repository is intentionally preserved as a monument to what happens when an
agentic review loop is allowed to keep reviewing and fixing its own review fixes.

The final M3 Task 3.1 pull request contained:

- **1,233 commits**
- **88 changed files**
- **+91,088 / -2,720 lines**
- a large custom PHP source-analysis stack
- and, somewhere underneath all of that, OAuth contracts

At some point the review process stopped primarily reviewing the feature and started
reviewing infrastructure created by previous review rounds.

This is known here as **Scope Colonization**.

## Historical significance

The original requirement was approximately:

> Add provider-neutral OAuth contracts.

The resulting implementation eventually learned about PHP token streams, namespace
rewriting, imports, group imports, trait `use`, identifier grammars, include/require
analysis, variable assignments, variable callees, Composer loading channels, and many
other fascinating subjects that were not OAuth.

The repository therefore serves as an important reminder:

> **A review finding may identify a real defect and still be the wrong thing to fix.**

Sometimes the correct response is not:

> Add another parser rule.

Sometimes the correct response is:

> Why do we have a parser?

## Preservation notice

**Do not fix the parser.**

There was never supposed to be a parser.

Do not restart the review loop to make the monument more correct.
Do not attempt to reach "zero findings".
Do not add support for one final obscure PHP syntax edge case.

The artifact is considered complete in its current philosophical state.

Suggested ceremonial release name:

`v0.0.1-what-have-we-done`

Possible side effects of working on this repository include accidental creation of a
programming-language toolchain.

---

## Original README

The remainder below is preserved so future archaeologists can understand what the
project was actually supposed to do before the review loop achieved sentience.

---

# wp-connectors

WordPress 7.0 AI connector plugins — provider plugins for the native
**Settings → Connectors** screen, built on the PHP AI Client SDK (`wordpress/php-ai-client`,
bundled in WordPress 7.0).

## Connectors

| Connector | Provider(s) | Surface | Auth |
|---|---|---|---|
| `zai` | z.ai GLM | OpenAI-compatible API (Coding Plan default, General optional; International default, China optional) | API key |
| `zai_anthropic` | z.ai GLM | Anthropic-compatible API (same plan × region matrix) | API key |
| `codex` | OpenAI (ChatGPT/Codex subscription) | Codex backend (Responses API) | OAuth device flow |
| `grok` | xAI (SuperGrok / X Premium+) | xAI Responses API | OAuth device flow |
| `claude_pro` | Anthropic (Claude Pro/Max) | Anthropic Messages API | OAuth PKCE (paste-code) |

## Status

- **M0 (foundation)** and **M1 (`zai`, z.ai OpenAI-compatible provider)** are complete — the
  `connectors/zai` plugin registers the `zai` provider with plan/region endpoint selection,
  validated-key availability, live model discovery with a plan-partitioned static fallback,
  text generation (tools, structured output, SSE aggregation), typed errors, and option-gated
  debug logging. Live smoke evidence: [`docs/architecture/0006-zai-models-evidence.md`](docs/architecture/0006-zai-models-evidence.md).
- Next: M2 (`zai_anthropic`, Anthropic-compatible surface in the same plugin).

See [`docs/specs/SPEC.md`](docs/specs/SPEC.md) and the detailed
[`docs/IMPLEMENTATION_PLAN.md`](docs/IMPLEMENTATION_PLAN.md). The plan expands the specification's
M1–M6 delivery sequence with explicit foundation and shared-runtime milestones.

## ⚠️ Disclosure

The OAuth connectors use authorization flows and client identifiers originating from
first-party vendor CLI tools (OpenAI Codex CLI, Grok CLI, Claude Code). They are not
officially documented public OAuth programs. Using subscription-backed endpoints outside
the official clients may conflict with the respective provider's terms of service. Use at
your own risk; tokens are stored encrypted, but account risk sits with the site owner.

## Layout

```
docs/specs/     specifications (SPEC.md = current suite)
connectors/     one directory per plugin (self-contained drop-ins)
shared/         build-time shared OAuth/token helpers (copied into each plugin)
```

## License

GPL-2.0-or-later (matches WordPress plugin ecosystem requirements).
