# Architecture records

Concise, verified records of the external APIs this repository builds on and the
cross-cutting decisions that follow from them. Every class name, method signature,
and timing fact below was verified against the pinned local source trees listed in
each record — nothing here is taken from memory or third-party summaries.

| # | Record | Topic |
|---|--------|-------|
| [0001](0001-provider-registration.md) | Provider registration & Connectors auto-discovery | init priority ladder, registry signatures, card derivation, key pass-through |
| [0002](0002-model-construction.md) | Model construction & metadata directory | `AbstractProvider` factory methods, `ModelMetadata`, capabilities/options |
| [0003](0003-sdk-version-guards-and-69-detection.md) | SDK version guards & WP 6.9 standalone detection | `AiClient::VERSION` guards, `Requires at least: 6.9`, runtime SDK guard |
| [0004](0004-option-ownership.md) | Option ownership | core-owned vs plugin-owned options, multisite, uninstall |
| [0005](0005-standalone-packaging.md) | Standalone plugin packaging | no runtime Composer, PSR-4 autoloader, build-time shared copies, artifacts |
| [0006](0006-zai-models-evidence.md) | z.ai `/models` evidence (open question O1) | resolved: discovery works on both intl plans |

Pinned reference sources (kept outside this repository):

- WordPress 7.1 full source: `~/wp-ai-research/wordpress/` —
  `wp-includes/connectors.php`, `wp-includes/class-wp-connector-registry.php`,
  `wp-includes/ai-client.php`, and the bundled PHP AI Client SDK 1.3.1 under
  `wp-includes/php-ai-client/src/` (namespace `WordPress\AiClient`).
- Official provider plugin reference: `ai-provider-for-anthropic` v1.0.4 at
  `~/wp-ai-research/anthropic-plugin/`.
- Live z.ai evidence (2026-08-30): `~/wp-ai-research/zai/` (contains no credentials).
