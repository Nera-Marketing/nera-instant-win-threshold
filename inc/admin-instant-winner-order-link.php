<?php
/**
 * Admin: link to the WooCommerce order that holds a won instant-win ticket.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve the order for a won instant-winner rule (prize row).
 *
 * @param int $rule_id Instant-winner rule post ID.
 * @return WC_Order|null
 */
function nera_iwt_get_order_for_won_rule( int $rule_id ): ?WC_Order {
	if ( $rule_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
		return null;
	}

	$order_id = 0;
	$log_won  = false;

	if ( function_exists( 'lty_get_instant_winner_log_id_by_rule_id' ) && function_exists( 'lty_get_instant_winner_log' ) ) {
		$log_id = lty_get_instant_winner_log_id_by_rule_id( $rule_id, 0 );
		if ( $log_id ) {
			$log = lty_get_instant_winner_log( $log_id );
			if ( is_object( $log ) && method_exists( $log, 'has_status' ) && $log->has_status( 'lty_won' ) ) {
				$log_won  = true;
				$order_id = absint( $log->get_order_id() );
				if ( $order_id <= 0 && method_exists( $log, 'get_ticket_id' ) ) {
					$order_id = nera_iwt_get_order_id_from_lottery_ticket( absint( $log->get_ticket_id() ) );
				}
			}
		}
	}

	if ( ! $log_won ) {
		return null;
	}

	if ( $order_id <= 0 && function_exists( 'lty_get_ticket_id_by_rule_id' ) ) {
		$order_id = nera_iwt_get_order_id_from_lottery_ticket( absint( lty_get_ticket_id_by_rule_id( $rule_id ) ) );
	}

	if ( $order_id <= 0 && function_exists( 'lty_get_instant_winner_rule' ) && function_exists( 'lty_get_ticket_id_by_ticket_number' ) ) {
		$rule = lty_get_instant_winner_rule( $rule_id );
		if ( is_object( $rule ) && method_exists( $rule, 'get_ticket_number' ) && method_exists( $rule, 'get_product_id' ) ) {
			$ticket_number = trim( (string) $rule->get_ticket_number() );
			$product_id    = absint( $rule->get_product_id() );
			if ( '' !== $ticket_number && $product_id > 0 ) {
				$order_id = nera_iwt_get_order_id_from_lottery_ticket(
					absint( lty_get_ticket_id_by_ticket_number( $ticket_number, $product_id ) )
				);
			}
		}
	}

	if ( $order_id <= 0 ) {
		return null;
	}

	$order = wc_get_order( $order_id );

	return ( $order instanceof WC_Order ) ? $order : null;
}

/**
 * @param int $ticket_id Lottery ticket post ID.
 */
function nera_iwt_get_order_id_from_lottery_ticket( int $ticket_id ): int {
	if ( $ticket_id <= 0 || ! function_exists( 'lty_get_lottery_ticket' ) ) {
		return 0;
	}

	$ticket = lty_get_lottery_ticket( $ticket_id );
	if ( ! is_object( $ticket ) || ! method_exists( $ticket, 'get_order_id' ) ) {
		return 0;
	}

	return absint( $ticket->get_order_id() );
}

/**
 * Print order link under "ID: xxxx" in the instant-winner rules table.
 *
 * @param int    $rule_id        Rule post ID.
 * @param object $instant_winner Rule entity.
 * @param object $product        Lottery product.
 */
function nera_iwt_render_rule_won_order_link( $rule_id, $instant_winner, $product ): void {
	unset( $instant_winner, $product );

	$rule_id = absint( $rule_id );
	if ( $rule_id <= 0 || ! current_user_can( 'edit_shop_orders' ) ) {
		return;
	}

	$order = nera_iwt_get_order_for_won_rule( $rule_id );
	if ( ! $order ) {
		return;
	}

	$order_number = $order->get_order_number();
	$edit_url     = $order->get_edit_order_url();

	if ( '' === $order_number || '' === $edit_url ) {
		return;
	}

	echo '<br><small class="nera-iwt-rule-won-order">';
	printf(
		'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
		esc_url( $edit_url ),
		esc_html( $order_number )
	);
	echo '</small>';
}

add_action( 'lty_instant_winner_rule_after_id', 'nera_iwt_render_rule_won_order_link', 10, 3 );
