=== Nera – Instant Win Rules ===
Contributors: nera
Tags: woocommerce, lottery, instant win, competition, giveaway
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.12
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
