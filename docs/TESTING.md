# Testing guide

## Offline suite (default)

`tools/` is gitignored (per-developer tooling), so a clean checkout first needs
Composer locally:

```bash
mkdir -p tools
curl -sS -o tools/composer.phar https://getcomposer.org/download/2.10.3/composer.phar
php tools/composer.phar install   # once; pins PHPUnit/PHPCS/SDK in vendor/
php tools/composer.phar check     # full offline validation entry point
```

`check` runs, in order: `php -l` sweep, PHPCS (WordPress standard),
PHPCompatibility (7.4–8.4), PHPStan, convention enforcement, the secret
scanner, and PHPUnit. It never contacts the network: the harness fails both
HTTP layers closed (`wp_remote_*` and the SDK's PSR transport) unless a test
installs a mock.

## Test harness

Extend `WpConnectorsTestCase` (auto-loaded via `tests/bootstrap.php`). You
get per-test reset of options/transients/cron/hooks/users plus:

| Helper | Purpose |
|--------|---------|
| `freezeTime($ts)` / `advanceTime($s)` | deterministic clock (transients, cron, expiry) |
| `asAdministrator()` / `asAnonymous()` | capability contexts |
| `withValidNonce($action)` | injects a valid `_wpnonce` into `$_REQUEST` |
| `loadPlugin($file, $boot)` | require-once plugin load with idempotent re-hook |
| `runInit()` | fires `init` (registration-timing tests) |
| `mockHttpResponse($resp, $matcher)` | mocks `wp_remote_*` via `pre_http_request` |
| `queueSdkResponse($status, $headers, $body)` | mocks the SDK PSR transport |
| `httpAttempts()` / `sdkHttpAttempts()` / `assertNoHttpRequests()` | transport audit |
| `assertOptionNotPlaintext($opt, $secret)` / `assertRedacted($out, $secret)` | secret-at-rest assertions |
| `assertWPError` / `assertNotWPError` | WP_Error assertions |

The SDK under test is the genuine pinned `wordpress/php-ai-client` 1.3.1
(the exact version bundled in WP 7.1), installed into `vendor/` — never a
hand-written mock of the SDK itself.

## Fixtures and secrets

- Use `FakeSecrets` (`tests/harness/FakeSecrets.php`) for every token/key in
  tests: runtime-generated, obviously-fake, scanner-allowlisted.
- Use `HttpResponseFactory` for response bodies (OpenAI shapes, OAuth
  errors, WP/PSR-7 wrappers).
- `bin/scan-secrets.php` (also in `composer check` and the artifact
  inspector) rejects live-credential shapes. Exemptions are structured,
  never a bare word on the line: a line is exempt only when it carries the
  strict comment marker `// secrets:allow` (or `# secrets:allow`), and a
  matched value is exempt only when the value itself is recognizable as a
  fake — placeholder shapes (`${ZAI_KEY}`, `<api-key>`) or well-known dummy
  segments (`sk-proj-TEST-…`, `YOUR_API_KEY`, the `wpct_fixture_` prefix).
  A real-looking key on a line that merely mentions "fixture" IS flagged.
  **Never paste real credentials or raw captured payloads.**

## Live, credentialed tests (opt-in only)

Network tests never run under `composer check`. They are opt-in via the
environment variables below — supply values only in your own shell/CI
secrets, never in the repository:

| Variable | Used by |
|----------|---------|
| `WP_CONNECTORS_TEST_ZAI_API_KEY` | z.ai live probes (M1 `zai` AND M2 `zai_anthropic`; one account key works on both surfaces) |
| `WP_CONNECTORS_TEST_ZAI_REGION` | optional: `intl` (default) or `cn` |
| `WP_CONNECTORS_TEST_ZAI_PLAN` | optional: `coding` (default for `zai`) or `general` (the default for `zai_anthropic`) |
| `WP_CONNECTORS_TEST_OPENAI_REFRESH_TOKEN` | Codex OAuth live tests (M3+) |
| `WP_CONNECTORS_TEST_XAI_REFRESH_TOKEN` | xAI/Grok OAuth live tests (M4+) |
| `WP_CONNECTORS_TEST_ANTHROPIC_REFRESH_TOKEN` | Claude Pro OAuth live tests (M5+, bonus) |

Live tests must skip (not fail) when the variable is absent, never print
token material, and are excluded from every automated gate.

For the z.ai connector there is also a standalone probe CLI (Tasks 1.9/2.7)
that reads the key at runtime from `ZAI_LIVE_API_KEY`,
`WP_CONNECTORS_TEST_ZAI_API_KEY`, or `~/.config/z.ai/api_key` and prints
only safe facts (endpoint URLs, statuses, model IDs, generated text,
timings — never the key):

```bash
php bin/zai-live-probe.php [--surface=openai|anthropic] [--plan=coding|general] [--region=intl|cn]
```

`--surface=anthropic` probes the Messages-protocol provider (its plan
default is `general` per record 0007; the coding-surface Messages routes
cannot generate as of 2026-08-31). Without flags the probe uses each
provider's defaults: openai coding+intl, anthropic general+intl.

Note: a Coding Plan key used against the general (pay-as-you-go)
OPENAI-SURFACE endpoint receives HTTP 429 with z.ai error code 1113
("Insufficient balance or no resource package") — an account property,
surfaced by the plugin as the typed rate-limit error. (The Anthropic
surface did not reproduce that gate during the record-0007 probes.)

## Artifacts

`php bin/build.php` builds self-contained zips into `dist/`;
`php bin/inspect-artifact.php <zip>` re-validates a zip independently
(headers, self-containment, no dev files, syntax, secret scan).
`tests/BuildArtifactsTest.php` exercises both.
