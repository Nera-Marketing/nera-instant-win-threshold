# Theme Instant Wins Vue (reference copy)

This folder holds a **non-enqueued snapshot** of the theme’s `InstantWinsContainer.vue` so the plugin repo documents the JSON contract the REST layer must satisfy **without** shipping a second Vue bundle.

## Canonical source

The live file used at build time remains:

`wp-content/themes/nera-competitions-standard/frontend/components/InstantWins/InstantWinsContainer.vue`

When that file changes, update this copy if you rely on it for documentation.

## REST `stats` contract

The transform expects:

- `availableCount = data.stats.total_available - data.stats.total_won`
- So **`stats.total_available` must be `won + remaining`** (total storefront-visible slots), not “remaining only”.

With **Nera – Instant Win Rules** active, `GET /wp-json/nera/v1/instant-wins/{product_id}` is short-circuited by the plugin; see `inc/rest-instant-wins-theme-adapter.php` and `inc/rest-instant-wins.php`.

When the plugin is **deactivated**, WordPress does not load this plugin; the theme’s own REST handler runs and the storefront behaves as a standard LFW + theme setup.
