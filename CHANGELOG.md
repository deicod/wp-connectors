# Changelog

All notable changes to this repository are documented here, per plugin and per
tooling area. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning per plugin follows its own header `Version` (no monorepo version).

## [Unreleased]

### Added (tooling / M0 foundation)

- Architecture records (`docs/architecture/`) verifying the WP 7.0/7.1
  Connectors API and PHP AI Client SDK 1.3.1 contracts; z.ai `/models`
  evidence resolving SPEC open question O1 for the OpenAI/intl surface.
- Composer dev toolchain (PHPUnit, PHPCS + WordPress standards,
  PHPCompatibility, PHPStan) with the pinned genuine SDK 1.3.1; offline
  validation entry point `composer check`.
- WordPress API test harness (`tests/harness/`): deterministic clock, option/
  cron reset between tests, users/capabilities, nonces, fail-closed HTTP on
  both `wp_remote_*` and SDK transport, encrypted-option/secret assertions.
- Repository conventions document with automated enforcement
  (`composer conventions`).
- Standalone artifact builder (`bin/build.php`) with deterministic zips +
  SHA-256 checksums and an artifact inspector
  (`bin/inspect-artifact.php`) rejecting repo-relative includes, missing
  headers, dev files, and embedded secrets.
- Secure test-fixture rules: fake secret factories, HTTP response builders,
  secret-pattern scanner (`composer scan-secrets`), documented opt-in
  environment variables for live tests.
