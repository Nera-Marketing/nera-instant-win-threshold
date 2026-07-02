<?php
/**
 * One-off remediation: ticket 19984 / order 19806 / product 6399
 *
 * Ticket post 19984 was assigned the held instant-win number 49994 (rule 7554,
 * 60% sold threshold) because the Nera "prize hold" sync did not run on the
 * Store API (block-based) checkout path. The plugin bug has since been fixed
 * (woocommerce_store_api_checkout_order_processed hook added), but this script
 * cleans up the one ticket that already escaped.
 *
 * What this script does (apply mode):
 *   1. Verifies ticket post 19984 still holds number 49994 for product 6399.
 *   2. Verifies rule 7554 is still a ticket_pct rule with ticket number 49994.
 *   3. Verifies instant-winner log 7555 is the buyer-assigned log for that rule
 *      (post_parent=7554, lty_ticket_id=19984, lty_order_id=19806, post_status=lty_won).
 *   4. Draws a replacement ticket number from the lottery's available pool
 *      (mt_rand within nera_iwt_resolve_shuffle_random_pool_max, screened by
 *      lty_check_is_ticket_number_exists AND by the set of all instant-winner
 *      rule prize numbers on this product — held/placed/other-prize numbers
 *      are all skipped).
 *   5. Resets winner log 7555 via the canonical LFW path
 *      $log->remove_instant_winner() → clears lty_ticket_id, lty_order_id,
 *      lty_user_id, lty_user_name, lty_user_email and sets post_status back
 *      to lty_available. (remove_won_prize() is called internally; for the
 *      "physical" prize type this is a filter-only no-op — no coupon to
 *      delete, no gift product to remove from the order.)
 *   6. Updates ticket post 19984's lty_ticket_number meta to the replacement.
 *   7. Updates order item 118 meta (_lty_lottery_tickets serialized array and
 *      the "Ticket Number( s )" display string) to swap 49994 -> replacement.
 *   8. Re-runs nera_iwt_sync_prize_hold_tickets() so the hold post for 49994
 *      is in place.
 *   9. Verifies 49994 is now held only (no buyer/winner ticket carries it),
 *      the replacement is unique on the product, and log 7555 is back to
 *      lty_available with buyer meta cleared.
 *  10. Adds an order note recording the swap.
 *  11. Busts the instant-wins REST cache for the product.
 *
 * Usage — two ways to run, both default to a safe dry-run.
 *
 *   CLI (preferred — no auth surface, no extra files):
 *     php wp-content/plugins/nera-instant-win-threshold/scripts/fix-ticket-19984.php
 *     php wp-content/plugins/nera-instant-win-threshold/scripts/fix-ticket-19984.php --apply
 *
 *   Web URL (no shell access): drop the companion mu-plugin in place, then visit
 *     https://YOUR-SITE/wp-admin/admin-post.php?action=nera_iwt_fix_ticket_19984
 *   The dry-run page prints a one-click APPLY URL (carries a WP nonce). The
 *   URL endpoint enforces is_user_logged_in() + current_user_can('manage_options')
 *   and (for apply mode) wp_verify_nonce(). Copy the companion mu-plugin from
 *   scripts/nera-iwt-fix-ticket-19984-loader.php to
 *   wp-content/mu-plugins/nera-iwt-fix-ticket-19984.php — if this script file
 *   is not deployed, the loader exits quietly (no fatal on other sites).
 *
 * Safe to re-run: dry-run is the default. Apply mode is idempotent — once the
 * ticket no longer holds 49994 the function aborts with a clear message.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || defined( 'NERA_IWT_FIX_19984_BOOTSTRAPPING' ) || define( 'NERA_IWT_FIX_19984_BOOTSTRAPPING', true );

if ( ! function_exists( 'nera_iwt_run_fix_ticket_19984' ) ) {

	/**
	 * Run the ticket-19984 remediation.
	 *
	 * Writes progress to stdout (echo). Caller decides where stdout goes —
	 * CLI streams to the terminal; the URL loader buffers and ships as text/plain.
	 *
	 * @param bool $apply  False = dry-run (default). True = commit changes.
	 * @param bool $is_cli Tweaks dry-run footer wording only.
	 * @return int 0 on success / no-op, 1 on failure.
	 */
	function nera_iwt_run_fix_ticket_19984( $apply = false, $is_cli = false ) {

		// --- Constants describing the one ticket we're fixing. ---
		$TICKET_POST_ID  = 19984;
		$PRODUCT_ID      = 6399;
		$ORDER_ID        = 19806;
		$ORDER_ITEM_ID   = 118;
		$EXPECTED_NUMBER = '49994';
		$RULE_ID         = 7554;
		$WINNER_LOG_ID   = 7555;

		$step = static function ( $msg ) { echo '[STEP] ' . $msg . "\n"; };
		$info = static function ( $msg ) { echo '       ' . $msg . "\n"; };
		$fail = static function ( $msg ) { echo "[FAIL] $msg\n"; return 1; };

		// --- Required dependencies. ---
		if ( ! function_exists( 'wc_get_order' ) ) {
			return $fail( 'WooCommerce is not loaded.' );
		}
		if ( ! function_exists( 'lty_check_is_ticket_number_exists' ) ) {
			return $fail( 'Lottery for WooCommerce helpers are not loaded.' );
		}
		if ( ! function_exists( 'lty_get_instant_winner_log' ) ) {
			return $fail( 'lty_get_instant_winner_log() is not loaded.' );
		}
		if ( ! function_exists( 'nera_iwt_sync_prize_hold_tickets' ) ) {
			return $fail( 'Nera Instant Win Threshold sync function is not loaded.' );
		}

		// The mu-plugin shim hard-codes an absolute path that can be wrong on
		// cloned environments; require the override file directly to make sure
		// nera_iwt_resolve_shuffle_random_pool_max() is available.
		$override_file = dirname( __DIR__ ) . '/inc/ticket-generation-override.php';
		if ( is_readable( $override_file ) ) {
			require_once $override_file;
		}
		if ( ! function_exists( 'nera_iwt_resolve_shuffle_random_pool_max' ) ) {
			return $fail( "Nera ticket-generation override helpers not loaded ($override_file)." );
		}

		echo $apply
			? "*** RUNNING IN APPLY MODE — changes will be written ***\n\n"
			: '--- DRY RUN — no changes will be made. ' . ( $is_cli ? 'Re-run with --apply to commit.' : 'See the apply URL at the bottom of this output.' ) . " ---\n\n";

		// --- 1. Verify ticket post. ---
		$step( 'Verifying ticket post ' . $TICKET_POST_ID );
		$ticket_post = get_post( $TICKET_POST_ID );
		if ( ! $ticket_post ) {
			return $fail( 'Ticket post not found.' );
		}
		if ( 'lty_lottery_ticket' !== $ticket_post->post_type ) {
			return $fail( "Wrong post_type: {$ticket_post->post_type}" );
		}
		if ( (int) $ticket_post->post_parent !== $PRODUCT_ID ) {
			return $fail( "Wrong post_parent (expected $PRODUCT_ID, got {$ticket_post->post_parent})." );
		}

		$current_number = (string) get_post_meta( $TICKET_POST_ID, 'lty_ticket_number', true );
		if ( $current_number !== $EXPECTED_NUMBER ) {
			if ( '' === $current_number ) {
				return $fail( 'Ticket has no lty_ticket_number meta — unexpected.' );
			}
			$info( "Ticket already holds number $current_number (not $EXPECTED_NUMBER). Nothing to fix." );
			echo "\nSCRIPT EXITED — no changes needed.\n";
			return 0;
		}

		if ( 'lty_won' === $ticket_post->post_status ) {
			return $fail( 'Ticket has already been declared a winner — manual review required.' );
		}
		if ( 'lty_ticket_buyer' !== $ticket_post->post_status ) {
			$info( "WARNING: ticket post_status is '{$ticket_post->post_status}', expected 'lty_ticket_buyer'." );
		}

		$order_id_meta = (int) get_post_meta( $TICKET_POST_ID, 'lty_order_id', true );
		if ( $order_id_meta !== $ORDER_ID ) {
			return $fail( "Ticket's lty_order_id is $order_id_meta, expected $ORDER_ID." );
		}
		$info( "OK: ticket post $TICKET_POST_ID currently holds number $EXPECTED_NUMBER" );

		// --- 2. Verify rule. ---
		$step( 'Verifying instant-win rule ' . $RULE_ID );
		$rule_type = (string) get_post_meta( $RULE_ID, 'nera_iwt_public_rule_type', true );
		$rule_num  = (string) get_post_meta( $RULE_ID, 'lty_ticket_number', true );
		$rule_pct  = (int) get_post_meta( $RULE_ID, 'nera_iwt_ticket_pct', true );

		if ( 'ticket_pct' !== $rule_type ) {
			return $fail( "Rule type is '$rule_type', expected 'ticket_pct'." );
		}
		if ( $rule_num !== $EXPECTED_NUMBER ) {
			return $fail( "Rule's lty_ticket_number is '$rule_num', expected $EXPECTED_NUMBER." );
		}
		$info( "OK: rule $rule_type, ticket number $rule_num, threshold {$rule_pct}%" );

		// --- 2b. Verify the winner log for this rule. ---
		$step( 'Verifying instant-winner log ' . $WINNER_LOG_ID );
		$log_post = get_post( $WINNER_LOG_ID );
		if ( ! $log_post ) {
			return $fail( "Winner log $WINNER_LOG_ID not found." );
		}
		if ( 'lty_ins_winner_log' !== $log_post->post_type ) {
			return $fail( "Wrong post_type on log $WINNER_LOG_ID: {$log_post->post_type}" );
		}
		if ( (int) $log_post->post_parent !== $RULE_ID ) {
			return $fail( "Log $WINNER_LOG_ID parent is {$log_post->post_parent}, expected $RULE_ID." );
		}
		$log_ticket_id = (int) get_post_meta( $WINNER_LOG_ID, 'lty_ticket_id', true );
		$log_order_id  = (int) get_post_meta( $WINNER_LOG_ID, 'lty_order_id', true );
		$log_user_id   = (int) get_post_meta( $WINNER_LOG_ID, 'lty_user_id', true );
		$log_status    = $log_post->post_status;
		$needs_log_reset = ( $log_ticket_id === $TICKET_POST_ID || $log_order_id === $ORDER_ID || 'lty_won' === $log_status );
		if ( $needs_log_reset ) {
			$info( "Log $WINNER_LOG_ID currently: status=$log_status, ticket_id=$log_ticket_id, order_id=$log_order_id, user_id=$log_user_id" );
		} else {
			$info( "Log $WINNER_LOG_ID is already clean (status=$log_status, no buyer meta) — log reset will be skipped." );
		}

		// --- 3. Load product. ---
		$step( 'Loading product ' . $PRODUCT_ID );
		$product = wc_get_product( $PRODUCT_ID );
		if ( ! $product || 'lottery' !== $product->get_type() ) {
			return $fail( 'Product not found or not a lottery product.' );
		}
		$info( 'OK: ' . $product->get_name() );

		if ( function_exists( 'nera_iwt_get_lottery_ticket_sold_percent' ) ) {
			$pct = nera_iwt_get_lottery_ticket_sold_percent( $product );
			if ( null !== $pct ) {
				$info( sprintf( 'Sold so far: %.2f%% (threshold %d%%)', $pct, $rule_pct ) );
				if ( $pct >= (float) $rule_pct ) {
					$info( 'NOTE: threshold has already been reached — rule would be public now anyway.' );
				}
			}
		}

		// --- 4. Draw a replacement ticket number.
		//
		// Excluded from the draw:
		//   - the number we're swapping out (49994)
		//   - any number currently held by a lty_lottery_ticket post in any
		//     LFW status (placed/won/held) — caught by lty_check_is_ticket_number_exists
		//   - every other instant-winner rule's prize number on this product —
		//     lty_check_is_ticket_number_exists does NOT see rule posts, so if
		//     we hit e.g. number 100 (an instant £100 site credit prize), we'd
		//     silently make the buyer the winner of another prize. Build a set
		//     of all rule prize numbers up front and skip them. ---
		global $wpdb;

		$prize_numbers = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.meta_value
				 FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				 WHERE p.post_type = 'lty_instant_winners'
				   AND p.post_parent = %d
				   AND p.post_status = 'publish'
				   AND pm.meta_key = 'lty_ticket_number'",
				$PRODUCT_ID
			)
		);
		$prize_lookup = array_flip( array_map( 'strval', (array) $prize_numbers ) );

		$step( 'Drawing replacement ticket number' );

		$pool_max = nera_iwt_resolve_shuffle_random_pool_max( $product );
		if ( $pool_max < 1 ) {
			return $fail( 'Resolved ticket pool max is 0 — refusing to draw.' );
		}
		$info( 'Ticket pool range: 1..' . $pool_max . ' (excluding ' . count( $prize_lookup ) . ' prize numbers)' );

		$replacement  = null;
		$max_attempts = 5000;
		$tried        = array();

		for ( $i = 0; $i < $max_attempts; $i++ ) {
			$num = (string) mt_rand( 1, $pool_max );
			if ( $num === $EXPECTED_NUMBER || isset( $tried[ $num ] ) || isset( $prize_lookup[ $num ] ) ) {
				continue;
			}
			$tried[ $num ] = true;

			$existing_ids = lty_check_is_ticket_number_exists( array( $num ), $PRODUCT_ID );
			if ( empty( $existing_ids ) ) {
				$replacement = $num;
				break;
			}
		}

		if ( null === $replacement ) {
			return $fail( "Could not draw a replacement after $max_attempts attempts. Pool may be near-exhausted." );
		}
		if ( ! ctype_digit( $replacement ) || (int) $replacement < 1 || (int) $replacement > $pool_max ) {
			return $fail( "Drawn replacement '$replacement' is outside the expected range 1..$pool_max." );
		}
		$info( "Chosen replacement: $replacement" );

		// --- 5. Planned changes summary. ---
		$step( 'Planned changes' );
		if ( $needs_log_reset ) {
			$info( "Reset winner log $WINNER_LOG_ID: clear lty_ticket_id/lty_order_id/lty_user_id/lty_user_name/lty_user_email, post_status -> lty_available" );
		} else {
			$info( "Winner log $WINNER_LOG_ID is already clean — no log reset needed" );
		}
		$info( "Ticket post $TICKET_POST_ID meta lty_ticket_number: $EXPECTED_NUMBER -> $replacement" );
		$info( "Order item $ORDER_ITEM_ID meta _lty_lottery_tickets: replace one $EXPECTED_NUMBER with $replacement" );
		$info( "Order item $ORDER_ITEM_ID meta \"Ticket Number( s )\": replace one $EXPECTED_NUMBER with $replacement" );
		$info( "Run nera_iwt_sync_prize_hold_tickets() for product $PRODUCT_ID" );
		$info( "Add order note on order $ORDER_ID" );
		$info( "Bust REST cache for product $PRODUCT_ID" );

		if ( ! $apply ) {
			echo "\n*** DRY RUN COMPLETE — nothing changed. ***\n";
			// The caller (CLI bootstrap or admin-post handler) is responsible for
			// printing a "next step" hint, because only it knows whether we are
			// running on CLI or HTTP and what the apply URL should look like.
			return 0;
		}

		// --- 5b. Apply: reset the instant-winner log to lty_available. ---
		if ( $needs_log_reset ) {
			$step( "Resetting instant-winner log $WINNER_LOG_ID" );
			$log = lty_get_instant_winner_log( $WINNER_LOG_ID );
			if ( ! is_object( $log ) || ! method_exists( $log, 'remove_instant_winner' ) ) {
				return $fail( "Could not load winner log entity for $WINNER_LOG_ID." );
			}
			// remove_instant_winner() handles status=lty_won → remove_won_prize()
			// → delete buyer metas → update_status('lty_available').
			$log->remove_instant_winner();
			$info( "Log $WINNER_LOG_ID reset to lty_available, buyer meta cleared." );
		} else {
			$info( "Log $WINNER_LOG_ID reset skipped (already clean)." );
		}

		// --- 6. Apply: update ticket post meta. ---
		$step( 'Updating ticket post meta' );
		$ok = update_post_meta( $TICKET_POST_ID, 'lty_ticket_number', $replacement );
		if ( false === $ok ) {
			return $fail( "update_post_meta returned false for ticket $TICKET_POST_ID" );
		}
		$info( "Ticket $TICKET_POST_ID lty_ticket_number set to $replacement" );

		// --- 7. Apply: update order item meta. ---
		$step( 'Updating order item ' . $ORDER_ITEM_ID );

		$tickets_raw = wc_get_order_item_meta( $ORDER_ITEM_ID, '_lty_lottery_tickets', true );
		$tickets     = is_array( $tickets_raw ) ? $tickets_raw : array();
		$replaced    = 0;
		foreach ( $tickets as $idx => $val ) {
			if ( (string) $val === $EXPECTED_NUMBER ) {
				$tickets[ $idx ] = $replacement;
				++$replaced;
			}
		}
		if ( $replaced > 0 ) {
			wc_update_order_item_meta( $ORDER_ITEM_ID, '_lty_lottery_tickets', $tickets );
		}
		$info( "_lty_lottery_tickets: replaced $replaced occurrence(s)" );

		$display = (string) wc_get_order_item_meta( $ORDER_ITEM_ID, 'Ticket Number( s )', true );
		if ( '' !== $display ) {
			$pattern     = '/(?<!\d)' . preg_quote( $EXPECTED_NUMBER, '/' ) . '(?!\d)/';
			$new_display = preg_replace( $pattern, $replacement, $display, 1, $count );
			if ( $count > 0 ) {
				wc_update_order_item_meta( $ORDER_ITEM_ID, 'Ticket Number( s )', $new_display );
			}
			$info( 'Ticket Number( s ): replaced ' . (int) $count . ' occurrence(s)' );
		}

		// --- 8. Sync prize hold posts (creates / confirms hold for 49994). ---
		$step( 'Syncing prize hold posts' );
		nera_iwt_sync_prize_hold_tickets( $product );
		$info( 'Sync complete.' );

		// --- 9. Verify final state. ---

		$step( "Verifying winner log $WINNER_LOG_ID is reset" );
		$log_final = get_post( $WINNER_LOG_ID );
		if ( ! $log_final ) {
			return $fail( "Winner log $WINNER_LOG_ID vanished after reset." );
		}
		$final_ticket_id = (int) get_post_meta( $WINNER_LOG_ID, 'lty_ticket_id', true );
		$final_order_id  = (int) get_post_meta( $WINNER_LOG_ID, 'lty_order_id', true );
		$final_user_id   = (int) get_post_meta( $WINNER_LOG_ID, 'lty_user_id', true );
		if ( 'lty_available' !== $log_final->post_status ) {
			return $fail( "Log $WINNER_LOG_ID status is '{$log_final->post_status}', expected 'lty_available'." );
		}
		if ( $final_ticket_id !== 0 || $final_order_id !== 0 || $final_user_id !== 0 ) {
			return $fail( "Log $WINNER_LOG_ID still has buyer meta (ticket_id=$final_ticket_id, order_id=$final_order_id, user_id=$final_user_id)." );
		}
		$info( "OK: log $WINNER_LOG_ID is lty_available with buyer meta cleared." );

		$step( "Verifying hold post for $EXPECTED_NUMBER" );
		$hold_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				 FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				 WHERE p.post_type = 'lty_lottery_ticket'
				   AND p.post_status = 'nera_prize_hold'
				   AND p.post_parent = %d
				   AND pm.meta_key = 'lty_ticket_number'
				   AND pm.meta_value = %s",
				$PRODUCT_ID,
				$EXPECTED_NUMBER
			)
		);
		if ( empty( $hold_ids ) ) {
			return $fail( "Hold post for $EXPECTED_NUMBER missing after sync. Investigate manually." );
		}
		$info( 'Hold post id(s): ' . implode( ', ', $hold_ids ) );

		$step( "Verifying $EXPECTED_NUMBER is not assigned to any non-hold ticket" );
		$bad_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				 FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				 WHERE p.post_type = 'lty_lottery_ticket'
				   AND p.post_status <> 'nera_prize_hold'
				   AND p.post_parent = %d
				   AND pm.meta_key = 'lty_ticket_number'
				   AND pm.meta_value = %s",
				$PRODUCT_ID,
				$EXPECTED_NUMBER
			)
		);
		if ( ! empty( $bad_ids ) ) {
			return $fail( "Number $EXPECTED_NUMBER is still on non-hold ticket(s): " . implode( ', ', $bad_ids ) );
		}
		$info( "OK: $EXPECTED_NUMBER is held only." );

		$step( "Verifying replacement $replacement is unique on this lottery" );
		$dup_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				 FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				 WHERE p.post_type = 'lty_lottery_ticket'
				   AND p.post_parent = %d
				   AND pm.meta_key = 'lty_ticket_number'
				   AND pm.meta_value = %s",
				$PRODUCT_ID,
				$replacement
			)
		);
		if ( count( $dup_ids ) !== 1 || (int) $dup_ids[0] !== $TICKET_POST_ID ) {
			return $fail( "Replacement number $replacement appears on unexpected ticket(s): " . implode( ', ', $dup_ids ) );
		}
		$info( "OK: $replacement is held by ticket $TICKET_POST_ID only." );

		// --- 10. Order note + cache bust. ---
		$order = wc_get_order( $ORDER_ID );
		if ( $order instanceof WC_Order ) {
			$order->add_order_note(
				sprintf(
					'Nera IWT remediation: ticket post %d swapped from %s (held prize, rule %d) to %s. Winner log %d reset to lty_available. Hold post(s): %s.',
					$TICKET_POST_ID,
					$EXPECTED_NUMBER,
					$RULE_ID,
					$replacement,
					$WINNER_LOG_ID,
					implode( ', ', $hold_ids )
				)
			);
			$info( "Order note added on order $ORDER_ID" );
		}

		if ( function_exists( 'nera_iwt_maybe_clear_theme_instant_wins_cache' ) ) {
			nera_iwt_maybe_clear_theme_instant_wins_cache( $PRODUCT_ID );
			$info( "REST cache cleared for product $PRODUCT_ID" );
		}

		echo "\n*** APPLY COMPLETE ***\n";
		return 0;
	}
}

// ---------------------------------------------------------------------------
// CLI bootstrap — only runs when this file is invoked as `php fix-ticket-19984.php`.
// Skipped when the file is `require`d from a WordPress context (mu-plugin etc).
// ---------------------------------------------------------------------------

if ( PHP_SAPI === 'cli' && ! defined( 'ABSPATH' ) && isset( $argv ) && realpath( $argv[0] ) === __FILE__ ) {

	$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
	if ( ! file_exists( $wp_load ) ) {
		fwrite( STDERR, "Cannot locate wp-load.php (looked at: $wp_load)\n" );
		exit( 1 );
	}
	require_once $wp_load;

	$apply = in_array( '--apply', $argv, true );
	$rc    = nera_iwt_run_fix_ticket_19984( $apply, true );

	if ( ! $apply && 0 === $rc ) {
		echo "Re-run with --apply to commit:\n";
		echo "  php " . $argv[0] . " --apply\n";
	}
	exit( $rc );
}
