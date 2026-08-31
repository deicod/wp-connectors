# 0003 — SDK version guards & WP 6.9 standalone detection

Verified against: `wp-includes/php-ai-client/src/AiClient.php` (VERSION const, line 87),
`wp-settings.php:291` (SDK load), `wp-includes/ai-client.php` (WP 7.0 helpers),
`anthropic-plugin/plugin.php` + `src/AnthropicProvider.php` (guard patterns),
`wp-includes/version.php` (`$wp_version = '7.1'`).

## SDK version and feature gates

- The SDK ships in WP 7.x core as `WordPress\AiClient`, version
  `AiClient::VERSION === '1.3.1'` in the pinned WP 7.1 tree.
- Feature availability is gated by `version_compare( AiClient::VERSION, …, '>=' )`,
  never by method_exists guessing — the reference plugin's pattern
  (AnthropicProvider.php:73-85):
  - `ProviderMetadata` **description**: SDK >= 1.2.0
  - `ProviderMetadata` **logoPath**: SDK >= 1.3.0
- Any connector code touching `description`/`logoPath`/newer SDK surfaces must use the
  same guard and degrade gracefully on older SDKs (a 6.9 site may run an older
  standalone SDK plugin). Guarding is *feature-level*, not class-level: the classes
  themselves exist in all 1.x.

## WP 6.9 + standalone SDK plugin detection

The plugins advertise `Requires at least: 6.9` (matching the official provider
plugins, e.g. anthropic plugin.php:7) so WordPress does not block activation on a
6.9 site. Actual SDK availability is a **runtime** concern, detected per request:

```php
if ( ! class_exists( \WordPress\AiClient\AiClient::class ) ) {
    return; // + persistent admin notice (dependency is missing).
}
```

- On WP 7.0+, `wp-settings.php` requires `wp-includes/php-ai-client/autoload.php`
  (line 291) **before** plugins load, so the class always exists when plugin code runs.
- On WP 6.9, the class exists only when the standalone PHP AI Client plugin is active;
  the guard + `admin_notices` dependency notice covers the gap. Header-based
  activation blocking is intentionally *not* used for the SDK dependency because core
  cannot express "requires an active plugin".
- `wp_ai_client_prompt()` (the SPEC's acceptance API) exists in WP 7.0+ core
  (`wp-includes/ai-client.php:60`, returns `WP_AI_Client_Prompt_Builder`) and is
  therefore unavailable on 6.9 — tests must not assume it; acceptance code paths
  targeting 6.9 use `AiClient::prompt()` directly.

## Compatibility contract

| Dimension | Contract | Enforcement in this repo |
|-----------|----------|--------------------------|
| PHP syntax | 7.4-compatible, runs on 7.4–8.4 (developed/tested on 8.5 host) | `php -l` over plugin trees; PHPCompatibility PHPCS rule set in the offline validation entry point |
| WordPress | 7.0+ core; 6.9 only with the standalone SDK plugin active | `class_exists` guard + dependency notice; integration tests cover both WP 7.0 and 6.9-with-SDK fixtures (M7.2) |
| HTTP | `wp_remote_*` only in connector code; no cURL/PHP-HTTP dependency at runtime (core's `WP_AI_Client_Http_Client` adapter provides the SDK transporter) | PHPCS + custom sniff in artifact inspection |
| Text domains | one per plugin, English source strings, `__()`-wrapped from day one | PHPCS `WordPress.WP.I18n` + convention check |
| Plugin bootstrap | no Composer autoload at runtime; own PSR-4 classmap autoloader per plugin (record 0005) | artifact inspection rejects `vendor/` and `require` of anything outside the plugin dir |

## Pinned development toolchain

Dev-only (never shipped): Composer 2.10.3 (`tools/composer.phar`, gitignored), with
pinned dev dependencies installed into a gitignored `vendor/` — see `composer.json`
and record 0005. The plugin zips must load and run with **none** of these present.

## Divergence note

The SPEC's "verified facts" cite WP 7.0 sources; our pinned tree is WP 7.1 with SDK
1.3.1. No API this suite uses differs between 7.0 and 7.1 for our surface
(`api_key`/`none` auth, provider registration, `wp_ai_client_prompt`). The 7.1-only
`application_password` connector method is deliberately unused (record 0001).
