# shared/

Source-only OAuth/token helper library. Never loaded at runtime by any plugin:
at build time `bin/build.php` copies it into each OAuth plugin under that
plugin's namespace (`Deicod\WpConnectors\{Plugin}\Shared`) — see
`docs/architecture/0005-standalone-packaging.md`.
