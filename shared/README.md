# shared/

Source-only OAuth/token helper library (Task 3.1+). Never loaded at
runtime by any plugin: at build time `bin/build.php` copies it into each
OAuth plugin under that plugin's namespace
(`Deicod\WpConnectors\{Plugin}\Shared`) — see
`docs/architecture/0005-standalone-packaging.md`.

- Source namespace: `Deicod\WpConnectors\Shared`, PSR-4 mapped to
  `shared/src/` (loaded by the test suite only, via a dev autoloader in
  `tests/bootstrap.php`).
- Pure PHP 8.2 on the compat floor: no WordPress reach of any kind
  (functions, hooks, options, globals, constants) — the host is reached
  only through the ports (`ClockInterface`, `HttpTransportInterface`,
  `TokenStorageInterface`). Enforced by
  `tests/SharedOAuthArchitectureTest.php`.
- Provider-neutral: no provider names, endpoints, or client ids in code,
  defaults, or docs; per-provider values belong to the plugins' provider
  config.
