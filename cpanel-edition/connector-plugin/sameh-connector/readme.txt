=== SAMEH Connector ===
Contributors: sameh
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.0.0-rc1

Thin signed REST bridge for SAMEH 12.0 Core (cPanel Edition). Settings page only. No AI.

== Description ==

SAMEH Connector is a thin WordPress plugin that exposes signed REST endpoints for SAMEH Core:

* GET /wp-json/sameh-connector/v1/health
* GET /wp-json/sameh-connector/v1/discover
* POST /wp-json/sameh-connector/v1/ping

Security:

* HMAC-SHA256 headers: X-Sameh-Timestamp, X-Sameh-Nonce, X-Sameh-Signature
* Payload: timestamp.nonce.METHOD.path.body_hash
* Timestamp skew ±5 minutes; rejects unreasonable future timestamps
* One-time nonce store (WP transients) — anti-replay
* Optional X-Sameh-Site-Token check

This plugin does NOT include Missions, Hermes, Page Factory, Growth, or any AI workforce features.
Those belong to later SAMEH phases — not 12.0 Platform Foundation / Control Plane.

== Installation ==

1. Upload the `sameh-connector` folder to `wp-content/plugins/` OR install via zip from Core dist.
2. Activate the plugin.
3. Settings → SAMEH Connector — paste Core URL, Site ID, Shared HMAC Secret, Connector Token from Core pairing.
4. From SAMEH Core: Test Health then Discover.

== Changelog ==

= 1.0.0-rc1 =
* Anti-replay nonce store (transients)
* Future timestamp rejection
* hash_equals for signatures
* Docs honesty: foundation phase only
