# 0005 — Standalone plugin packaging

Verified against: `anthropic-plugin/plugin.php` + `src/autoload.php` + `composer.json`
(v1.0.4 packaging model), SPEC §5, plan cross-cutting decisions 2 and 5.

## Plugin anatomy (per plugin, one zip)

```
connectors/<slug>/<slug>.php    ← plugin header + guarded bootstrap (no logic)
connectors/<slug>/src/autoload.php   ← PSR-4 spl_autoload_register, own classmap
connectors/<slug>/src/…              ← provider/models/metadata/auth/settings code
connectors/<slug>/assets/…           ← logo, per SPEC record 0001 logo path rule
connectors/<slug>/readme.txt         ← WordPress-style user documentation
connectors/<slug>/uninstall.php      ← plugin-owned cleanup only (record 0004)
```

- **No Composer at runtime.** Dev dependencies live only in the repository's
  gitignored `vendor/`; a built zip must never contain `vendor/`, `composer.json`,
  tests, or tools. The plugin's own `src/autoload.php` registers a PSR-4 autoloader
  for its namespace — exactly the official plugin's pattern (a ~25-line
  `spl_autoload_register` closure).
- **Plugin header fields** (required in every artifact, enforced by the artifact
  inspector): `Plugin Name`, `Description`, `Requires at least: 6.9`, `Requires PHP:
  7.4`, `Version`, `Author`, `License: GPL-2.0-or-later`, `License URI`, `Text Domain`
  matching the plugin slug.
- **Namespaces**: `Deicod\WpConnectors\Zai`, `…\OpenAiOauth`, `…\XaiOauth`,
  `…\AnthropicOauth` (SPEC §5). One plugin directory = one top-level namespace.
- **Version constant** per plugin main file (e.g. `ZAI_CONNECTOR_VERSION`) defined
  from the header `Version`, used by the shared User-Agent rule (SPEC §4.3) and by
  the build (zip name `connectors-<slug>-<version>.zip`).

## Shared OAuth source (`shared/`)

- `shared/` is **source-only** and never loaded at runtime from a plugin. At build
  time the builder copies it into each OAuth plugin as
  `src/Shared/` under that plugin's namespace
  (`Deicod\WpConnectors\{Plugin}\Shared\…`) via a deterministic namespace rewrite,
  with provenance headers recording the source revision.
- **Generated copies are not committed.** They are reproducible from `shared/` at any
  time by `bin/build.php` (deterministic: sorted file list, fixed mtimes, fixed zip
  entry order), which is what keeps the repo single-sourced. An automated check in
  the offline validation suite asserts a fresh copy matches the last build output for
  every OAuth plugin that exists.
- **Self-containment is tested, not assumed**: the artifact inspector rejects any
  `require`/`include` resolving outside the plugin directory, any `vendor/` or
  `.git` entry, and any zip without a valid main-file header; a collision test
  activates all built plugins together (Task 3.8+).

## Artifacts

- Zips are built into the gitignored `dist/` directory as
  `connectors-<slug>-<version>.zip`, alongside `<zip>.sha256` checksum files and a
  `dist/checksums.txt` manifest.
- Reproducibility (decision): staged copies are touched to a fixed epoch and zipped
  in sorted order with a fixed entry order, so two clean builds of the same commit
  are byte-identical on the same toolchain (Task 7.7 hardens/normalizes any residual
  metadata differences).

## Build & inspection entry points

- `php bin/build.php [--slug=<slug>]` — builds one or all plugin zips + checksums.
- `php bin/inspect-artifact.php <zip>` — standalone validation of a built zip
  (headers, self-containment, no dev files); exit code non-zero on rejection.
- Both run offline; the full offline validation entry point is
  `php tools/composer.phar run validate` (record 0002/0003 toolchain; composer binary
  itself is gitignored, `composer.json` documents the pinned dev dependencies).
