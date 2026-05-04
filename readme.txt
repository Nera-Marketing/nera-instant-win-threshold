=== Nera – Instant Win Rules ===
Contributors: nera
Tags: woocommerce, lottery, instant win, competition, giveaway
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.9
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
