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

## Full LFW-parity cleanup (v1.0.34+)

LFW's own removal path (`remove_lottery_ticket_for_order_cancel`) bails when either
`lty_lottery_ticket_created_once` or `lty_ticket_ids_in_order` order meta is missing,
and its create path bails when `created_once` is still present. The 1.0.32 fallback
deleted pending tickets but never cleared those metas — so a revived order
(failed → late payment → processing, or untrash → pay) hit the `created_once` guard:
**the customer paid and received zero tickets**. Routine exposure: WooCommerce
auto-cancels unpaid orders after 60 minutes and gateway webhooks can arrive late.

`nera_iwt_cleanup_dead_order( $order_id, $mode )` replaces the pending-only cleanup:

- **status mode** (cancelled/refunded/failed, priority 20): removes this order's
  tickets of ANY status (LFW parity — full refund forfeits entries), resets
  instant-win logs the way LFW's cancel path does (`remove_won_prize` on won logs,
  then release + `lty_available`), decrements `_lty_ticket_count` by the number of
  deleted *promoted* (buyer/winner) tickets only, prunes both hold metas, flushes
  product **and per-user** LFW transients, and ALWAYS clears the three order metas +
  order-item ticket metas — restoring LFW's revive contract.
- **trash mode** (`woocommerce_trash_order` / `woocommerce_before_delete_order`):
  pending tickets only (admins trash paid orders during manual cleanup); clears the
  revive metas only when the order holds zero lottery tickets afterwards, so a paid
  order untrashes untouched and a fully-unpaid order revives cleanly.

Untrash needs no handler: WooCommerce restores the pre-trash status via
`set_status()`, so the normal transition hooks fire and LFW recreates tickets where
appropriate. The redundant `wp_trash_post`/`before_delete_post` registrations were
dropped — WooCommerce fires the two `woocommerce_*` hooks in both HPOS and legacy
storage. The same revive-meta clearing was added to the repair tool (1.0.1), which
also flushes per-user transients and shows an informational "paid tickets on dead
orders" count in the scan table.

## Stock recalc on order death (v1.0.33+)

Second incident, 2026-07-02, same product: LFW's save-time recalc counts **pending**
tickets as placed. Order 154701 (1000 tickets, pending payment) sat in checkout when
the product was saved at ~08:15 GMT → its reservation was baked into `_stock` (set to
11,767). The order auto-cancelled at 09:18: LFW deleted the pending tickets but never
recalculates stock on cancel, and WooCommerce's restock was a no-op (stock was never
*reduced* for the unpaid order — only reserved via `wp_wc_reserved_stock`). Stock ran
permanently 1000 low and drained to 0 at 28,999/30,000 sold — false "out of stock"
with 1000 tickets left.

`nera_iwt_recalc_lottery_stock_for_order()` (same file) closes this: priority 30 on
the same six hooks, after LFW (10) and the orphan cleanup (20). For every lottery
product in the dead order it flushes LFW's counter transients, then re-applies LFW's
own formula `max_tickets − placed_ticket_count` via `wc_update_product_stock(...,
'set', true)` + explicit `wc_update_product_stock_status()`. It runs unconditionally
(not only when orphans were found) because LFW's own priority-10 removal is the common
path and touches no stock; the formula is idempotent and self-heals prior drift.
Closed lotteries and unlimited pools (`max_tickets` = 0) are skipped.

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
