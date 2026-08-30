# 0004 — Option ownership

Verified against: `wp-includes/connectors.php` (setting derivation, REST masking,
key pass-through), the SPEC §3.3/§4.4 requirements, and the implementation plan's
cross-cutting decision 6.

## Ownership table

| Option | Owner | Written by | Read by | autoload |
|--------|-------|-----------|---------|----------|
| `connectors_ai_zai_api_key` | **WordPress core** (Settings API group `connectors`) | core only (settings screen / REST) | core (pass-through at init 20), zai plugin (read-only) | core default |
| `connectors_ai_zai_anthropic_api_key` | **WordPress core** | core only | same | core default |
| `zai_connector_zai_plan` / `_region` | zai plugin | plugin Settings API page (`manage_options` + nonce) | plugin (read **at request time**, not construction time) | yes (core default; tiny non-secret enums — first write goes through options.php's `update_option`, which creates the row autoloaded; WP 6.6+ stores `auto`) |
| `zai_connector_zai_debug` | zai plugin | plugin Settings API page | plugin logging helpers | yes (core default; single '0'/'1' flag) |
| `zai_connector_zai_debug_log` | zai plugin | plugin logger only | plugin log viewer | **no** (`update_option(..., false)`) |
| `zai_connector_zai_key_state` | zai plugin | plugin availability probe (hash only, never the key) | plugin | **no** (`update_option(..., false)`) |
| `zai_connector_zai_anthropic_plan` / `_region` | zai plugin | plugin Settings API page | same | same as zai plan/region |
| OAuth token envelopes (`*_connector_*_tokens`) | each OAuth plugin | plugin only (encrypted, versioned envelope) | plugin only | **no** |
| Pending-flow state (device/PKCE) | each OAuth plugin | plugin only (encrypted store or transient) | plugin only | **no** |
| Discovery caches (model list) | plugin | plugin; cache key includes provider+plan+region | plugin | transient (short-lived) |

Rules:

1. **Connector plugins never write core-owned options.** Core owns the write path
   (Settings API registration at init 20, REST masking/validation). Reading them via
   `get_option()` for request building is fine; env/constant sources take precedence
   over the DB value, matching core's own resolution order (record 0001). The ONE
   sanctioned exception: a z.ai region switch DELETES the stored key (see the
   region-switch implication below) — deleting, never creating or updating.
2. **Per-site scope, including multisite** (`get_option`/`update_option` are
   site-scoped). Network activation still yields per-site settings and per-site OAuth
   grants; salts are shared across sites, which is why the M3 encrypted envelope must
   bind provider **and** site ID (plan Task 3.2).
3. **Plugin-owned secrets are never autoloaded** (`autoload => false` via
   `add_option()`) to avoid shipping ciphertext on every uncached request unless
   explicitly fetched.
4. **Uninstall** removes plugin-owned options, scheduled events, locks, and ciphertext
   only. Core-owned API keys are left to core. **Deactivation** retains everything
   (plan cross-cutting decision 6).
5. **Option name prefixes are namespaced per plugin** (`zai_connector_`,
   `openai_connector_`, `xai_connector_`, `anthropic_connector_`) so simultaneous
   activation can never collide.

## Settings write path (admin mutations)

Every admin mutation in this suite follows: `current_user_can( 'manage_options' )` +
nonce check (`wp_nonce_field` / `check_admin_referer`) + sanitization to a known enum
or shape + typed `WP_Error` on failure. Corrupt stored values fall back to the
documented default (coding-plan/international for z.ai) rather than failing requests.

## z.ai region-switch implication (SPEC §3.3)

`intl` and `cn` are separate accounts with separate keys. A region switch deletes the
stored key (the rule-1 exception above) and invalidates the plugin-owned
credential-derived state (validated-key verdict) and the discovery caches — the cache
key already includes region, so a warm cache can never serve the other region's
catalog. Clearing the key itself is required, not just the verdict: the China
`/models` probe route is unprobed and 404s (inconclusive → configured-pending), so a
cleared verdict alone would leave the connector "connected" and send the OLD region's
key against the NEW endpoint indefinitely. After a switch no key is stored — the
connector stays not-connected until an admin supplies a key for the new region;
core's key-save validation then accepts a new key despite the inconclusive probe
(pending). Implemented and tested in Tasks 1.2/1.4/1.5.
