# Repository conventions

These conventions are **enforced by automation**: `composer conventions`
(`bin/check-conventions.php`) validates every plugin-shaped directory, and
PHPCS/PHPUnit guard style and behavior. When this document and an automated
check disagree, fix the check and this document in the same change.

## Layout and namespaces

| Path | Content | Runtime namespace |
|------|---------|-------------------|
| `connectors/<slug>/` | one standalone plugin | `Deicod\WpConnectors\<Ns>` |
| `connectors/<slug>/src/` | PSR-4 mapped source root | — |
| `shared/` | source-only shared OAuth/token library | none (copied per-plugin at build time) |
| `tests/` | PHPUnit suite + harness | global-namespace WP stubs |
| `bin/` | build/validation tooling (committed) | — |
| `tools/`, `vendor/`, `dist/` | gitignored artifacts (composer.phar, deps, zips) | — |

Namespace-to-path is strict PSR-4: `Deicod\WpConnectors\Zai\Provider\X` lives at
`connectors/zai/src/Provider/X.php`. The namespace segment after
`WpConnectors\` is fixed per plugin (`Zai`, `OpenAiOauth`, `XaiOauth`,
`AnthropicOauth`) and must appear in that plugin's `src/autoload.php` prefix.
Generated shared copies use `Deicod\WpConnectors\<Ns>\Shared\` (record 0005).

## Plugin anatomy rules (checked)

Each plugin directory must satisfy all of the following — `composer
conventions` fails otherwise:

1. Exactly one main plugin file at the plugin root containing the header block
   with **all** of: `Plugin Name`, `Version`, `Requires at least: 6.9`,
   `Requires PHP: 7.4`, `License: GPL-2.0-or-later`, `Text Domain`.
2. `Text Domain` equals the plugin directory slug.
3. The main file defines a version constant named
   `STRAIGHTENED_SLUG_VERSION` (slug uppercased, `-` → `_`, e.g.
   `EXAMPLE_CONNECTOR_VERSION`) whose value equals the header `Version`.
4. `src/autoload.php` exists, registers exactly one PSR-4 autoloader for the
   plugin's `Deicod\WpConnectors\<Ns>\` prefix, and contains no Composer
   reference.
5. No shipped PHP file (`connectors/**`, plus fixture plugins) may
   `require`/`include` anything resolving **outside** the plugin directory, and
   no shipped file may reference `vendor/autoload` or `composer` at runtime —
   plugins are standalone drop-ins (SPEC §5).
6. Shipped PHP is WordPress-coding-standard clean (`composer phpcs`) and
   PHP 7.4–8.4 compatible (`composer phpcs-compat`, `composer lint`).

The same rules apply to test fixture plugins under `tests/fixtures/plugins/`
(the `example-connector` fixture is the reference implementation).

## User-facing text

- English source strings, wrapped in `__()` / `esc_html__()` / … from day one,
  always with the plugin's own text domain (PHPCS enforces the allowlist in
  `phpcs.xml.dist`).
- Data surfaced in HTML is escaped at output time (`esc_html`, `esc_attr`,
  `esc_url`); URLs built from user input use `add_query_arg` + `esc_url`.

## Options and storage

Option ownership, prefixes, autoload, and uninstall rules are specified in
`docs/architecture/0004-option-ownership.md`. Short version: core owns
`connectors_ai_*_api_key`; plugins own `*_connector_*` options (autoload `no`
for anything secret or heavy); deactivation retains everything.

## Versions and changelog

- Plugin version comes from the header `Version` and the matching
  `{SLUG}_VERSION` constant; bump both together (checked).
- User-visible changes land in the root `CHANGELOG.md` under `Unreleased`,
  tagged with the plugin(s) they affect (Keep a Changelog format). Dependency
  pinning changes are `Changed` entries too.

## Generated shared copies

Generated per-plugin copies of `shared/` are **not committed**; they are
produced deterministically by `bin/build.php` into `dist/` zips only
(record 0005). Nothing under `connectors/` may include from another plugin or
from `shared/` at runtime (checked by rule 5 above and by the artifact
inspector in Task 0.5).

## Testing

- Every test extends `WpConnectorsTestCase`; state resets automatically
  between tests.
- Tests are **offline by construction**: `wp_remote_*` and the SDK's PSR
  transport both fail closed unless the test installs a mock
  (`mockHttpResponse()` / `queueSdkResponse()`). A test that leaks an
  unmocked attempt fails the run.
- Live, credentialed tests are opt-in via documented environment variables
  only (Task 0.6) and never run under `composer check`.
- Fixture credentials come from the fake-secret factories (Task 0.6); never
  paste real tokens, keys, or response bodies containing them.

## Commits

Conventional-Commits-style subjects (`feat:`, `fix:`, `docs:`, `test:`,
`build:`, `chore:`) with an imperative summary; body explains the *why* and
references the milestone task when applicable. No secrets, ever.
