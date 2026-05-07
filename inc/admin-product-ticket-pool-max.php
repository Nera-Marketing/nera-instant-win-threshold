<?php
/**
 * Per-product Ticket Number Max (shuffle/random pool + instant-win numeric bounds).
 *
 * Field is rendered into a hidden mount and moved next to Ticket Prefix via admin JS
 * so it appears inside Ticket Generation Settings without editing Lottery for WooCommerce.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

/**
 * Output mount node; {@see assets/admin-rule-visibility.js} relocates it before `#_lty_ticket_prefix`.
 *
 * @return void
 */
function nera_iwt_render_ticket_number_max_field_mount() {
	global $product_object;

	if ( ! $product_object instanceof WC_Product || ! function_exists( 'lty_is_lottery_product' ) || ! lty_is_lottery_product( $product_object ) ) {
		return;
	}

	$stored   = $product_object->get_meta( '_nera_iwt_ticket_number_max', true );
	$fallback = ( defined( 'NERA_IWT_MAX_TICKET_NUMBER' ) && NERA_IWT_MAX_TICKET_NUMBER > 0 )
		? (string) (int) NERA_IWT_MAX_TICKET_NUMBER
		: '';
	$display = ( '' !== (string) $stored && null !== $stored ) ? (string) absint( $stored ) : $fallback;

	echo '<div id="nera-iwt-ticket-max-field-mount" class="nera-iwt-ticket-max-mount" style="display:none;">';

	woocommerce_wp_text_input(
		array(
			'id'                => '_nera_iwt_ticket_number_max',
			'value'             => $display,
			'label'             => __( 'Ticket Number Max', 'nera-instant-win-threshold' ),
			'placeholder'       => __( 'Pool upper bound (digits)', 'nera-instant-win-threshold' ),
			'type'              => 'number',
			'custom_attributes' => array(
				'min'  => '1',
				'step' => '1',
			),
			'desc_tip'          => true,
			'description'       => __( 'Highest numeric ticket value used for shuffle/random pools on this product and for validating plain numeric instant-win ticket numbers. Leave empty to use NERA_IWT_MAX_TICKET_NUMBER from wp-config or Lottery maximum-tickets logic.', 'nera-instant-win-threshold' ),
		)
	);

	echo '</div>';
}

add_action( 'woocommerce_product_options_lottery_product_data', 'nera_iwt_render_ticket_number_max_field_mount', 1 );

/**
 * Persist `_nera_iwt_ticket_number_max` (runs after LTY core lottery meta save).
 *
 * @param int $post_id Product ID.
 * @return void
 */
function nera_iwt_save_product_ticket_number_max( $post_id ) {
	$post_id = absint( $post_id );
	if ( $post_id <= 0 ) {
		return;
	}

	if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-post_' . $post_id ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( ! isset( $_POST['_nera_iwt_ticket_number_max'] ) ) {
		return;
	}

	$raw = wc_clean( wp_unslash( $_POST['_nera_iwt_ticket_number_max'] ) );
	if ( '' === $raw ) {
		delete_post_meta( $post_id, '_nera_iwt_ticket_number_max' );
		return;
	}

	$n = absint( $raw );
	if ( $n < 1 ) {
		delete_post_meta( $post_id, '_nera_iwt_ticket_number_max' );
		return;
	}

	update_post_meta( $post_id, '_nera_iwt_ticket_number_max', min( $n, 99999999 ) );
}

add_action( 'woocommerce_process_product_meta_lottery', 'nera_iwt_save_product_ticket_number_max', 15 );
