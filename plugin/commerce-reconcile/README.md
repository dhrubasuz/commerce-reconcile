# Commerce Reconcile

**Designed and developed by Dhruba Poudel.**

A WordPress plugin for read-only reconciliation of external order-state snapshots with WooCommerce orders. It keeps mismatches, duplicated events and sequence conflicts visible, with a deterministic SHA-256 review hash.

The plugin provides a **Tools → Commerce Reconcile** admin screen and an authenticated same-origin REST endpoint. Its pure PHP engine also powers the separate public fictional-data demonstration. The demonstration does not expose a WordPress administration session or a store.

## What it does

- Compares exact order ID, reference, integer amount in minor units, currency and status.
- Holds mismatches and missing orders for a person to inspect.
- Ignores exact duplicate events and stale sequences, while holding conflicting identifiers and equal-sequence collisions.
- Produces the same report for a repeated analysis of the same snapshot.
- Supports a bounded, read-only WooCommerce adapter using `wc_get_order()` for explicitly requested IDs.

It does not change order state, charge/refund payments, send notifications, connect to arbitrary URLs, store API credentials or write review records to the WordPress database. Report state stays in the admin page memory. Access logs, third-party plugins and server infrastructure remain outside that statement.

## Install in a local or staging WordPress site

1. Use PHP **8.1+** and WordPress **6.5+**. These are declared minimums; consult the repository's current verification record for actually exercised versions.
2. Copy this `commerce-reconcile` directory into `wp-content/plugins/`, or install the packaged plugin ZIP through the normal WordPress upload screen.
3. Activate the plugin, open **Tools → Commerce Reconcile**, and review the bundled fictional sample.
4. Select **Analyse snapshot**. The baseline contains **9 sample orders and 14 events: 3 matched, 9 exception rows and 2 ignored**. Exception rows include both versions of identifier and sequence conflicts.
5. Re-analyse the same JSON. The deterministic report hash stays the same. This is a read-only re-analysis, not an attempt to repeat an external side effect.

Use a staging store when checking WooCommerce integration. No production-store qualification is implied by the standalone checks.

## REST API

`POST /wp-json/commerce-reconcile/v1/analyse`

Requests require a logged-in WordPress user with `manage_woocommerce` when WooCommerce is loaded; otherwise fixture mode requires `manage_options`. The request also requires a valid `X-WP-Nonce` for `wp_rest` and `Content-Type: application/json`. Nonces reduce CSRF; they are not the authorisation boundary.

Fixture mode takes this envelope:

```json
{
  "mode": "fixture",
  "orders": [
    {"order_id": 8101, "reference": "DEMO-8101", "amount_minor": 12900, "currency": "AUD", "status": "processing"}
  ],
  "events": [
    {"event_id": "evt-match", "order_id": 8101, "reference": "DEMO-8101", "amount_minor": 12900, "currency": "AUD", "status": "processing", "sequence": 1, "occurred_at": "2026-10-27T00:00:00Z"}
  ]
}
```

WooCommerce mode takes `{"mode":"woocommerce","events":[...]}`. Caller-supplied orders, prior ledgers, credentials and URLs are rejected. All events are validated before any WooCommerce order read. The adapter reads the exact unique order IDs mentioned in the validated events, at most **100**. Missing IDs become visible `missing_order` exceptions.

Only order ID/number, total, currency and status are read into the report. The total is converted from a decimal string using the store's configured price precision, with no floating-point multiplication or silent rounding. Custom numbering formats outside the documented ASCII-reference contract, custom order statuses, negative amounts and unsupported precision require a deliberate adapter extension; they are rejected rather than coerced. Multi-currency extensions may need per-order precision support.

Responses have `Cache-Control: no-store, private`. Authentication follows the WordPress cookie/nonce flow; application-password-only API access is intentionally unsupported by this admin-oriented adapter.

## Verification

From the repository root with PHP installed:

```sh
php tests/run.php
```

The suite has **31 meaningful checks** covering comparison rules, exact retry output, immutable inputs, out-of-order events, conflicts, bounds, malformed values, money precision, exact-ID order reads, capabilities, nonce handling and REST validation before data access. JSON object/list distinctions are checked before associative decoding. An isolated PHP child process exercises the fallback with WooCommerce functions genuinely absent. WordPress and WooCommerce objects in this suite are deliberate test doubles. A green standalone suite is not an end-to-end WordPress/WooCommerce compatibility claim.

The separate `tests/integration-wordpress.php` script runs against an actual, already-loaded and activated WordPress environment. It requires both `COMMERCE_RECONCILE_DISPOSABLE_TEST=true` and `WP_ENVIRONMENT_TYPE=local` or `development`. It creates/removes fictional local users and, when WooCommerce is active, two fictional orders; mail is blocked during fixture setup. It checks actual REST dispatch and nonce/capability boundaries, the rendered admin markup, and a read-only WooCommerce comparison. It is not run by the standalone suite or a claim that integration has already passed. Consult the retained integration result for the actual runtime versions and outcomes. Never run it on a business store.

`fixtures/scenario.json` in this plugin must stay byte-identical to the repository's root fixture. `fixtures/golden-report.json` at repository root records the reviewed PHP result. Rebuild a golden only after intentionally reviewing a rule change; changing a golden to silence a failure is not a validation method.

## Sources and design references

- [WordPress custom REST endpoints and permission callbacks](https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/)
- [WordPress nonce security guidance](https://developer.wordpress.org/apis/security/nonces/)
- [WooCommerce order-query guidance](https://developer.woocommerce.com/docs/features/orders/wc-get-orders/)
- [`wc_get_order()` factory reference](https://woocommerce.github.io/code-reference/files/woocommerce-includes-wc-order-functions.html#function_wc_get_order)

The domain algorithm, fixtures, adapter and admin interface are original work by Dhruba Poudel. WordPress and WooCommerce retain their own licences and trademarks. No upstream plugin or theme source is bundled.

SPDX-License-Identifier: GPL-2.0-or-later. See the repository licence and the [GNU GPL v2 text](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html).
