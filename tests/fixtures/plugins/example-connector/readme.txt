=== Example Connector (Test Fixture) ===
Contributors: deicod
Tags: ai, connectors, test-fixture
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://spdx.org/licenses/GPL-2.0-or-later.html

Minimal production-shaped plugin used by the wp-connectors test suite. Not a real connector; do not install.

== Description ==

This fixture exists so the repository test harness can prove provider-registration
timing against the real PHP AI Client SDK, and so the artifact builder has a
realistic plugin to zip and inspect. It registers a single `example` provider with
a static model catalog and never contacts the network.

== Installation ==

Do not install this plugin on a live site.
