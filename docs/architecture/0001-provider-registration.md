# 0001 — Provider registration & Connectors auto-discovery

Verified against: `wp-includes/default-filters.php` (WP 7.1), `wp-includes/connectors.php`,
`wp-includes/class-wp-connector-registry.php`, `wp-includes/php-ai-client/src/Providers/ProviderRegistry.php`,
`anthropic-plugin/plugin.php` (v1.0.4). File/line references are from the pinned WP 7.1 tree.

## The `init` priority ladder

| Priority | What runs | Source |
|----------|-----------|--------|
| 5 | Connector plugin registers its provider(s) with `AiClient::defaultRegistry()` | plugin `add_action('init', …, 5)` — anthropic plugin.php:54 precedent |
| 15 | `_wp_connectors_init()` — creates `WP_Connector_Registry`, auto-discovers AI providers, fires `wp_connectors_init` | default-filters.php:556 |
| 20 | `_wp_register_default_connector_settings()` — Settings API/REST registration of `connectors_ai_*_api_key` options | connectors.php:846 |
| 20 | `_wp_connectors_pass_default_keys_to_ai_client()` — pushes stored DB keys into the SDK registry | connectors.php:891 |

`WP_Connector_Registry::set_instance()` refuses to run outside `doing_action('init')`
(class-wp-connector-registry.php:431). Providers registered at priority 5 are therefore
guaranteed to be visible when auto-discovery snapshots the SDK registry at priority 15.
Priority 20 key pass-through means a provider registered at 5 with an already-stored key
ends up fully configured within the same `init` run.

## Registration code (verified signatures)

```php
add_action( 'init', function (): void {
    if ( ! class_exists( \WordPress\AiClient\AiClient::class ) ) {
        return; // WP < 7.0 without the standalone SDK plugin.
    }
    $registry = \WordPress\AiClient\AiClient::defaultRegistry();
    if ( ! $registry->hasProvider( ZaiProvider::class ) ) {
        $registry->registerProvider( ZaiProvider::class );
    }
}, 5 );
```

- `AiClient::defaultRegistry(): ProviderRegistry` — memoized static singleton (AiClient.php:107).
- `ProviderRegistry::registerProvider( string $className ): void` — validates the class
  exists and implements `WordPress\AiClient\Providers\Contracts\ProviderInterface`, reads
  `$className::metadata()` for the provider ID, and wires the HTTP transporter plus a
  default `RequestAuthenticationInterface` (from env/constant, see below) into the
  provider's availability/directory instances (ProviderRegistry.php:58-108).
- `ProviderRegistry::hasProvider( string $idOrClassName ): bool` accepts **either** the
  provider ID (`'zai'`) or FQCN. The `hasProvider()` guard makes registration idempotent
  when `init` fires twice; re-registering the same class without the guard is only a
  silent map overwrite and still re-runs transporter wiring, so the guard stays.
- `ProviderRegistry::isProviderConfigured( string $idOrClassName ): bool` calls
  `$className::availability()->isConfigured()` — availability is a live check.

## Auto-discovered connector card derivation

`_wp_connectors_register_default_ai_providers()` (connectors.php:287) iterates
`AiClient::defaultRegistry()->getRegisteredProviderIds()` and derives each card from
`ProviderMetadata`:

- `RequestAuthenticationMethod::apiKey()` → connector `method: 'api_key'` with
  `setting_name = "connectors_ai_{id with '-'→'_'}_api_key"`,
  `constant_name = env_var_name = '{CONSTANT_CASE_ID}_API_KEY'` (connectors.php:388-397).
  For us: `connectors_ai_zai_api_key`, `connectors_ai_zai_anthropic_api_key`,
  `ZAI_API_KEY`, `ZAI_ANTHROPIC_API_KEY`.
- Any other auth method (including none) → connector `method: 'none'`, no key field.
  This is why OAuth providers register with no `RequestAuthenticationMethod` at all
  (`null`) and own their token UI entirely.
- `name`/`description` from `ProviderMetadata` (description only shown when non-empty);
  `logo_url` resolved from `logoPath` — the file **must live under the plugins or
  mu-plugins directory**, else `_doing_it_wrong()` and no logo
  (`_wp_connectors_resolve_ai_provider_logo_url`, connectors.php:175).
- `plugin.is_active` defaults to `$ai_registry->hasProvider( $id )` (connectors.php:400).

## Key storage, sources, and validation (core-owned)

- Core registers the `connectors_ai_{id}_api_key` setting (group `connectors`,
  `show_in_rest`, `sanitize_text_field`) at init 20 — the plugin never writes these
  options itself.
- Resolution order for the effective key: environment variable → PHP constant →
  database (`_wp_connectors_get_api_key_source`, connectors.php:444). Core skips pushing
  the DB key into the SDK when env/constant provide one.
- On REST settings **updates**, core validates AI provider keys by
  `setProviderRequestAuthentication()` + `isProviderConfigured()` — i.e. by a live
  availability probe — and reverts an invalid key to `''`
  (`_wp_connectors_is_ai_api_key_valid`, `_wp_connectors_rest_settings_dispatch`,
  connectors.php:591, 736-742). Consequence for Task 1.4: provider availability must be
  an authenticated probe (not mere key presence) or invalid keys would be reported
  connected and then silently cleared by core's REST path.
- The SDK itself also auto-discovers credentials per provider via
  `{CONSTANT_CASE_PROVIDER_ID}_{FIELD}` env/constant names
  (`ProviderRegistry::createDefaultProviderRequestAuthentication`, `getEnvVarName`),
  e.g. `ZAI_API_KEY` for the `apiKey` field of `ApiKeyRequestAuthentication` — the same
  names core advertises, so env/constant keys work on both WP 7.0 (core pass-through)
  and a 6.9 + standalone-SDK site (SDK self-discovery).

## Divergences / notes vs SPEC

- SPEC §2.1 says "priority 5 = BEFORE `_wp_connectors_init()`" — confirmed; the exact
  priority of `_wp_connectors_init` is `init` 15 (SPEC implied only "later than 5").
- The pinned reference tree is WP **7.1**, which additionally supports an
  `application_password` connector auth method (connectors.php @7.1.0). We do not rely
  on it; our target surface is `api_key` and `none` as in 7.0.
