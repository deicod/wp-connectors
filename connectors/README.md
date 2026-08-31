# connectors/

One directory per standalone WordPress plugin. Each plugin is a self-contained
drop-in (own autoloader, own text domain, no runtime Composer dependency) —
see `docs/architecture/0005-standalone-packaging.md`.

Planned plugins (SPEC §1): `zai/` (providers `zai` + `zai_anthropic`),
`openai-oauth/` (`codex`), `xai-oauth/` (`grok`), `anthropic-oauth/`
(`claude_pro`, bonus).
