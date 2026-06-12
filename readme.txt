=== Nera – Instant Win Rules ===
Contributors: nera
Tags: woocommerce, lottery, instant win, competition, giveaway
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.30
License: GPLv2 or later

Instant win rule types, public prize visibility, and optional instant-win UI overrides for Lottery for WooCommerce.

== Description ==

This plugin extends **Lottery for WooCommerce** (Giveaway for WooCommerce) with:

* Instant win rule types: instant, scheduled, and ticket-sold percentage thresholds
* Public prize visibility controls
* Optional instant-win section UI overrides for compatible themes

**Requires** the Lottery for WooCommerce plugin to be installed and active.

== Installation ==

1. Install and activate **Lottery for WooCommerce**.
2. Upload and activate **Nera – Instant Win Rules**.
3. Configure instant win rules and visibility on competition products as supported by your theme and Lottery for WooCommerce.

== Changelog ==

= 1.0.30 =
* Fix — Export/import: after each import batch, stamp `lty_lottery_id` and lottery start/end dates on imported instant-winner logs via LFW's `maybe_update_instant_winner_log()` so imported prizes appear in the admin list and public winning-ticket lookup can match them.
* Fix — Export/import: flush LFW's instant-winner-rules count transient after import so the Instant Win Prizes tab paginates correctly (no stale cached count hiding rules beyond page 1).

= 1.0.29 =
* Admin — Export/import: batch-bounded CSV re-read when applying Nera Rule Type / Ticket % after each import batch (uses `position` + `get_position_count()` — O(N) total instead of re-reading the full file every batch).
* Admin — Export/import: safe cross-product copy — on upload, blank foreign rule IDs and rewrite Product ID so rows from another competition create fresh rules under the target product instead of corrupting the source.
* Admin — Export/import: enforce numeric ticket-pool range on import rows (same bounds as Add Rule / Save; all-digit ticket numbers only).
* Admin — Export/import: block group-mode CSV rows (rows with Group Prize Title) from importing into a Default-mode product — fails the row with a clear error instead of silently flattening and losing prize-group association. Override: `nera_iwt_allow_import_mode_mismatch` filter.
* Admin — Export/import: document that Schedule datetimes are not exported (Schedule Prize disabled by default); Rule Type + Ticket % round-trip only.
* REST — Group display mode: `GET /wp-json/nera/v1/instant-wins/{product_id}` returns a group-shaped payload when the product's Instant Win Prize Display Mode is "Display Prizes by Group" — one entry per LFW prize group with `tickets[]`, merged visibility (instant > ticket-% > schedule), and shared schedule field derivation.
* REST — Default display mode payload unchanged except new top-level `display_mode` key (`default` | `group`).
* REST — Refactor schedule field derivation into shared helper used by both default and group branches.
* Removed — Internal dev test scripts (`tests/test-buy-all-rounds-e2e.php`, `tests/test-projection-drain.php`); not shipped in release zip.

= 1.0.28 =
* Fix — Part A: thread a projected in-flight ticket count through threshold evaluation during checkout so that a purchase which crosses a ticket-sold-% threshold releases the prize number into the same order's assignable pool (no longer requires a subsequent purchase or cron run).
* Fix — Part B: `lty_get_random_ticket_numbers()` now uses the same exact-drain strategy as shuffle for pools ≤ NERA_IWT_SHUFFLE_MATERIALIZE_MAX (default 50000), eliminating random under-fill near pool exhaustion on `random`-type products.
* Hard guarantee: automatic shuffle/random products at full sellout assign every prize ticket number (instant + ticket-%) to a buyer, whether bought in one order or split across many.
* Admin — Won prizes: order link below status dot in the Instant Win rules table.

= 1.0.27 =
* Fix — Ticket generator excludes locked instant-win prize numbers on every path (shuffle/random pools); REST API `woocommerce_after_order_object_save` hold sync before LFW assigns tickets.
* Fix — MU-plugin shim self-heals when the override path changes (site clone / environment move).
* Admin — Instant Win rules table: row status dots and legend (locked / available / won).
* Removed — One-off `scripts/fix-ticket-19984.php` remediation and mu-plugin loader (no longer shipped).

= 1.0.26 =
* Fix — Checkout: bypass LFW hold-ticket NOT REGEXP guard for large orders (avoids MySQL regex timeout and infinite retry on big ticket quantities; threshold configurable via `NERA_IWT_HOLD_REGEXP_BYPASS_MIN`).
* Performance — Shuffle/random ticket pools: use rejection sampling for large pools instead of materialising full `range(1, max)`; faster lookups when drawing random numbers.
* Storefront — Checkout loading overlay (branded dots + status message) while place-order AJAX runs for large lottery orders.

= 1.0.25 =
* Fix — Ticket pool max: when Ticket Number Max is unset, default the shuffle/random pool ceiling to the product's LFW maximum tickets; configured cap never falls below that value (avoids pool exhaustion before all buyer slots are filled).

= 1.0.24 =
* Admin — Instant Win rules CSV export/import: include **Rule Type** and **Ticket %** columns (LFW export hooks + post-import pass to persist Nera meta via `nera_iwt_persist_rule_visibility_meta()`).

= 1.0.23 =
* Maintenance release: version bump and GitHub release asset sync for Plugin Update Checker.

= 1.0.22 =
* Admin — Instant Win rules table: search/filter rows by ticket number, prize message, or prize group (persists across pagination).
* Release — version bump and GitHub release asset sync for Plugin Update Checker.

= 1.0.21 =
* Scripts — Ship `scripts/fix-ticket-19984.php` (one-off WP-CLI / admin-post remediation for a held prize number assigned before the v1.0.20 Store API checkout fix) and `scripts/nera-iwt-fix-ticket-19984-loader.php` (mu-plugin loader that skips quietly when the remediation script is not deployed).

= 1.0.20 =
* Fix — Sync prize hold tickets on block checkout / Store API (`woocommerce_store_api_checkout_order_processed`) as well as classic checkout, so held instant-win ticket numbers are not assigned when orders use Cart/Checkout blocks or Store API gateways (e.g. woo-wallet).

= 1.0.19 =
* Public — Drop duplicate section wrapper; theme `nera_competitions_render_instant_win_prizes_section()` owns layout; plugin supplies inner template via template-part bridge only.
* Removed `instant-win-prizes-below-hero.php` and `nera_competitions_instant_win_prizes_section_html` filter.

= 1.0.18 =
* Admin — Per-product Ticket Number Max (Ticket Generation Settings) drives numeric ticket pool upper bound with site/LFW fallbacks; instant-win validation and helpers aligned (`ticket-generation-override`, `admin-instant-win-ticket-range`).
* Admin — Guards when Ticket Generation is Automatic: block Schedule / Ticket Sold % rule types and switching away from Automatic while those rules exist (server + client).
* Admin — Instant Win rules note shows the current allowed numeric ticket range for the product (effective start through resolved max).
* Admin — Rule visibility JS: robust parsing for localized ticket cap; Ticket Number Max field placement next to LFW ticket prefix.

= 1.0.15 =
* Tooling — `release.sh` sets release commit author from repository `.git/config` (local), then global Git config, with existing defaults only as fallback.

= 1.0.14 =
* Admin — Instant Win rules: validate Ticket Number is within the product’s numeric pool (Add Rule modal + Save on the rules table). Lower bound = effective ticket starting number (manual or automatic; supports 0 and any LFW-configured start). Upper bound = NERA_IWT_MAX_TICKET_NUMBER when &gt; 0 in wp-config, otherwise start + maximum tickets − 1 (aligned with Lottery for WooCommerce). Client `alert` and blocked AJAX on failure.
* New: `inc/admin-instant-win-ticket-range.php` (server validation on `lty_add_instant_winner_rule` / `lty_save_instant_winners_rules` after the sequential pattern guard). JS uses `admin-rule-visibility.js` and localized `neraIwtAdmin` bounds.

= 1.0.13 =
* Admin — Block saving instant-win rules when Ticket Number Pattern is Sequential and Rule type is Schedule or Ticket Sold % (AJAX + modal guard); supports Automatic and User Chooses ticket modes.
* Admin — After changing Ticket Number Pattern to Random/Shuffled in the product form, clear the Lottery “unsaved instant win rules” lock so WordPress Update works without a full reload (capture-phase submit + SelectWoo-friendly handlers).
* Storefront — Instant Win REST / counts list all CMS-configured prizes (schedule / ticket-% metadata preserved); optional client header sync without hiding scheduled rows.
* REST — Prize payload includes `ticket_pct` for themes.

= 1.0.12 =
* Admin — Instant Win rules: Add Rule modal fields aligned with Lottery for WooCommerce (same `<p>` layout and 50% widths as Ticket / Prize rows; `datetime-local` + ticket % covered where LFW omits them).
* Admin — product rules table: Rule type column sizing; `select.nera-iwt-public-rule-type` uses full cell width (`100% !important`) so it matches Schedule at / Schedule end / Ticket sold (%) beside Lottery’s global `130px` select rule.

= 1.0.11 =
* WordPress 6.3+ updates: pre-create `upgrade-temp-backup` plugin/theme dirs; optional skip of Core’s temp-backup move via `NERA_SKIP_UPGRADE_TEMP_BACKUP`, `WP_ENVIRONMENT_TYPE=local`, or `nera_skip_upgrade_temp_backup` (helps Windows/Laragon when updates fail with “Could not move the old version to the upgrade-temp-backup directory”).

= 1.0.10 =
* Maintenance release: version bump and GitHub release asset (`release.sh`).

= 1.0.9 =
* Restore `release.sh` and align releases with the Nera Competitions Standard pattern: PHP `ZipArchive`-first zip, sync back into this repo, commit, push `main` + tag (no orphan `git init` force-push), so tooling stays on GitHub.

= 1.0.8 =
* Plugin list and Dashboard → Updates thumbnail: ship `assets/icon-128x128.png` and `assets/icon-256x256.png` (from the project logo) for WordPress and Plugin Update Checker.

= 1.0.7 =
* Release zip is built with forward-slash paths only (Info-ZIP `zip` or PHP `ZipArchive`) so WordPress updates no longer fail with "Could not copy file …\\lib\\" when the archive was created with PowerShell `Compress-Archive`. Includes `build-wp-release-zip.php` and `release.sh` updates.

= 1.0.6 =
* Release tooling and PUC metadata alignment (readme.txt, plugin.json). Full notes: https://github.com/Nera-Marketing/nera-instant-win-threshold/releases

= 1.0.5 =
* Maintenance release. Full notes: https://github.com/Nera-Marketing/nera-instant-win-threshold/releases
