# Reserve-slots (instant-win sellout guarantee)

## Customer guarantee

For numeric automatic ticket products with **Maximum Tickets** = `N`, when all `N` tickets are sold, every configured instant-win **ticket number** inside `1..N` must be assigned to a buyer (and can become won per LFW rules).

## Mechanism

1. **Fixed pool** — shuffle/random generation uses `1..N` only. The pool is **not** expanded by `+ count(locked prizes)` (that expansion caused numbers `N+1..` to be sold instead of reserved prize numbers inside the pool).

2. **Reserve-slots** — while a `ticket_pct` or `schedule` prize is locked, its ticket number is excluded from generation and held via `nera_prize_hold` posts. Those numbers stay inside `1..N` until the threshold opens.

3. **Release** — when the threshold is met, the hold is removed and the number re-enters the remaining unsold pool. With a fixed pool, each release always has a free slot before sellout completes.

## Schedule prizes

**Schedule** rules unlock by **time**, not tickets sold. Full sellout does **not** guarantee a schedule prize number will be sold before `schedule_at` or before `schedule_end`. Admin save shows a notice when using a numeric ticket number on a schedule rule.

## Ticket-% feasibility

Admin validation blocks:

- **100%** threshold (unlocks only after full sellout — no slot left).
- Configurations where `ceil(T% × N)` exceeds `N − (count of ticket-% prizes at or above T%)` — buyers would exhaust sellable numbers before the threshold is reachable (deadlock).

## WP-CLI

```bash
wp nera-iwt pool-status --product-id=<id>
wp nera-iwt test-feasibility --product-id=<id>
wp nera-iwt simulate-sellout --n=10000 --prizes=900 --thresholds=50,80
```

## Win timing (LFW)

- `LTY_Order_Handler::declare_instant_winner()` runs when tickets are created at checkout (matching prize number → log `lty_pending`).
- Win is confirmed when the ticket is confirmed (`lty_won`).
- Excluding locked numbers from assignment prevents early wins for threshold prizes.
