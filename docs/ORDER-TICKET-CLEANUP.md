# Orphaned Pending-Ticket Cleanup — Production Runbook

## Background

LFW creates `lty_lottery_ticket` posts with status `lty_ticket_pending` the moment an
order is placed (before payment). When the order dies (cancelled / refunded / failed),
LFW's `remove_lottery_ticket_for_order_cancel()` deletes them — but that path depends
entirely on the `lty_ticket_ids_in_order` order meta. When that meta is missing, or the
order is trashed/deleted directly (no status hook fires), the pending tickets survive
forever.

Why that matters: on product save/relist LFW recalculates WooCommerce stock as
`maximum_tickets − placed_ticket_count`, and **pending tickets count as placed**
(so do `nera_prize_hold` reserve tickets). Enough orphans and the next product save
drives `_stock` to zero or negative → WooCommerce blocks add-to-cart with
*"product is out of stock"* while the progress bar (which counts only buyer/winner
tickets) still shows availability.

Real incident (2026-07-02, product 112077 "WIN a 2026 YZF 250"): three trashed unpaid
bulk orders (112639, 114343, 117654) left 12,942 orphaned pending tickets. A product
save set stock to 30,000 − 30,004 = **−4** → out of stock at 17,058/30,000 sold. The
orphans also locked 11 instant-win prize logs in `lty_pending`.

## Prevention (v1.0.32+)

`inc/order-ticket-cleanup-fallback.php` hooks order cancel/refund/fail (priority 20,
after LFW), plus trash and delete (which LFW does not handle at all), and removes any
leftover pending tickets by querying their `lty_order_id` meta directly. It also resets
instant-win logs the order locked, prunes both hold-ticket metas, and flushes LFW's
counter transients. Deploy this version before running the repair so new orphans cannot
form mid-repair.

## Repair — `scripts/fix-orphaned-lottery-tickets.php`

Finds pending tickets whose order is trashed / cancelled / refunded / failed / missing.
Pending tickets on live orders (pending-payment, on-hold, processing) are never touched.

```bash
# 1. Audit — read-only, per product or site-wide
wp eval-file wp-content/plugins/nera-instant-win-threshold/scripts/fix-orphaned-lottery-tickets.php all --skip-themes

# 2. Backup
wp db export pre-ticket-repair-$(date +%Y%m%d).sql

# 3. Apply per product (repeat per affected product ID from the audit)
wp eval-file wp-content/plugins/nera-instant-win-threshold/scripts/fix-orphaned-lottery-tickets.php <product_id> apply --skip-themes
```

Per product, apply mode:

1. resets locked `lty_ins_winner_log` posts (`lty_pending`/`lty_won` → `lty_available`)
   via LFW's own `remove_instant_winner()`
2. force-deletes the orphaned ticket posts
3. removes their ticket numbers from `_lty_hold_tickets` **and** `lty_hold_tickets`
   (LFW writes the two keys inconsistently)
4. flushes LFW transients (`lty_placed_ticket_count_*`, `lty_purchased_ticket_count_*`, …)
5. recalculates stock with LFW's own math (`max_tickets − placed_ticket_count`) and sets
   the stock status accordingly

### `clear-holds` flag

`... <product_id> apply clear-holds` empties both hold-ticket metas entirely instead of
pruning only the orphaned numbers. Hold entries are number reservations for carts that
never reached checkout; they block number assignment (not stock) and LFW has no expiry
for them. **On production only use this in a quiet window** — live carts hold real
reservations and clearing them risks double-assignment of a number sitting in someone's
cart. The default per-order pruning is always safe.

## Verification

- `SELECT stock_quantity, stock_status FROM wp_wc_product_meta_lookup WHERE product_id = <id>;`
  — expect `max − buyer − prize_hold`, `instock`
- `SELECT post_status, COUNT(*) FROM wp_posts WHERE post_type='lty_lottery_ticket' AND post_parent=<id> GROUP BY post_status;`
  — expect no `lty_ticket_pending` rows (unless live unpaid orders exist)
- Instant-win admin: previously locked prizes back to available
- Frontend: add a ticket to the cart — no "out of stock" notice; sold counter unchanged
  (it reads buyer/winner tickets only)

## Local note (WP-CLI on Local by Flywheel)

System `wp` can't reach Local's MySQL and the theme fatals under CLI; run:

```bash
php -d mysqli.default_socket="$HOME/Library/Application Support/Local/run/<site-id>/mysql/mysqld.sock" \
  /opt/homebrew/bin/wp eval-file <script> <args> --skip-themes
```
