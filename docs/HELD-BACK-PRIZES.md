# Held-back prizes (Option B)

A **held-back prize** is shown on the public product page as an available prize **with no ticket
number**, and cannot be won, until it is **activated**. Activation assigns a definitely-unsold
ticket number; the next customer who buys that number wins. The winning number stays secret — the
storefront never reveals an unwon prize's number.

Built entirely in this plugin's layer on top of Lottery for WooCommerce (LFW); no LFW core changes.

## Enabling

```php
// wp-config.php
define( 'NERA_IWT_ENABLE_HELD_PRIZE_TYPE', 1 );
```

Then on the product's instant-win rules: **Add Rule → Rule type → "Held-back Prize" → Save**. Leave
the ticket number blank. In the rules table the row shows an **Activate** control.

## Rule type & state

Rule type slug `held` (`nera_iwt_public_rule_type` meta). Lifecycle in `nera_iwt_held_state`:

| State | Meaning | Public page | Admin dot |
| --- | --- | --- | --- |
| `held` | created, no number | available, no number | red (locked) |
| `active` | activated on an unsold number | available, no number (secret) | green |
| `unplaceable` | safety net could not place it | **hidden** | red |
| `drawn` | awarded by end-of-competition draw | (competition closed) | orange (won) |

Winner matching is LFW's exact `lty_ticket_number` compare (`lty_get_rule_id_by_ticket_number`), so
a `held` rule with an empty number can never match a sold ticket — it is genuinely un-winnable
until activated.

## Activation

`nera_iwt_activate_held_prize( $rule_id, $typed_number = '' )`:

- **Blank** → the system picks a random unsold number (`nera_iwt_held_pick_unsold_number`).
- **Typed** → the number is canonicalised (see below) and must be currently unsold; an already-sold
  or reserved number is **rejected** (no silent swap, no retro-award).
- The chosen number is re-checked unsold immediately before commit to minimise the pick→assign race.
- On commit the number is written to the rule + its child logs and the state becomes `active`.

Excluded from selection: placed tickets (buyer/winner/pending), in-flight cart holds, and numbers
already assigned to other instant-win rules.

`nera_iwt_deactivate_held_prize( $rule_id )` clears the number and returns to `held` (refused once a
winner exists).

## Lettered / user-chooses competitions

On a user-chooses (manual) competition with the alphabet-with-sequence option (or a prefix/suffix),
the ticket a buyer holds is stored as the **formatted** string (e.g. `L11382`), not `11382`. The
canonicaliser (`inc/instant-win-ticket-canonical.php`, hooked on
`lty_instant_winner_rule_data_before_save`) resolves whatever the admin types to the exact stored
form using LFW's own `get_overall_tickets()` list — so plain `11382` becomes `L11382`, and a wrong
letter / out-of-range / ambiguous entry is rejected instead of silently saved (which previously made
the prize impossible to win). The same canonicalisation is applied to a typed activation number.

## Safety net (mandatory — cannot be switched off)

`inc/held-prizes-safety.php`, run after every order (priority 20) and hourly via
`nera_iwt_sync_hold_cron`:

- **Auto-activation** — once tickets sold reach the threshold, every still-held prize is activated,
  **most-valuable first** (by `lty_prize_amount`). Threshold precedence: product meta
  `nera_iwt_held_autotrigger_pct` → constant `NERA_IWT_HELD_AUTOTRIGGER_PCT` (default 90) → filter
  `nera_iwt_held_autotrigger_pct`. Never 0 — always 1–100. Set it per product in the **General**
  product tab ("Held-prize auto-activation %").
- **Low-margin warning** — when unsold numbers run low relative to prizes still held, a product-edit
  dashboard notice + a throttled admin email are raised.
- **Unplaceable** — a prize that cannot be given any unsold number is marked `unplaceable`, removed
  from the public page immediately, and the admin is emailed.

Why mandatory: advertising an "available" prize that can no longer be won is a UK prize-competition
compliance risk. The threshold is tunable; the automation is not removable.

## End-of-competition draw (remedy, admin-reviewed)

`inc/held-prizes-remedy.php`. On close (`lty_lottery_product_after_finished`), any held prize still
unwon (`active` with no winner, or `unplaceable`) is flagged **needs a draw** and the admin is
emailed. The rules table then shows a **Run draw** button: it draws a random **sold** ticket
(`lty_ticket_buyer`, current relist) and awards the prize to that entrant through LFW's own
instant-winner-log path (`update_status('lty_won')` + `assign_winning_prize()`), marking the rule
`drawn`. A human reviews before any real prize is awarded; the draw is refused once a winner exists.

## WP-CLI

```bash
wp nera-iwt held-status --product-id=<id>   # threshold, sold %, unsold count, per-prize state
```

## Caveats

- The end-of-competition draw's actual prize grant (coupon / gift product) runs LFW's own
  `assign_winning_prize()`; verify a coupon/gift held prize on staging before production use.
- Activation is atomic-with-recheck, not transaction-locked; the pick→assign window is tiny (admin
  action) and re-checked, but a determined concurrent buyer could still, in theory, take the number
  a hair before commit — in which case activation fails cleanly and is retried.
```
