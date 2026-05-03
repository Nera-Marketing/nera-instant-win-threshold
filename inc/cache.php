<?php
/**
 * Instant-wins REST cache helpers + cache-bust hook wiring.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bump when REST payload shape or client-side schedule logic changes.
 * {@see nera_iwt_maybe_flush_instant_wins_api_cache_on_upgrade()} wipes old transients.
 */
const NERA_IWT_INSTANT_WINS_PAYLOAD_SCHEMA = 'v10';

/**
 * Transient key for plugin instant-wins REST JSON.
 * Bump suffix when payload/filter logic changes so old payloads cannot leak across releases.
 *
 * @param int $product_id Lottery product ID.
 * @return string
 */
function nera_iwt_instant_wins_api_cache_key( $product_id ) {
	return 'nera_iwt_instant_wins_api_' . NERA_IWT_INSTANT_WINS_PAYLOAD_SCHEMA . '_' . absint( $product_id );
}

/**
 * Delete every plugin-owned instant-wins REST transient (all product IDs / versions).
 * Used once per payload-schema upgrade so stale JSON cannot survive in wp_options.
 */
function nera_iwt_delete_all_plugin_instant_wins_transients() {
	global $wpdb;
	$patterns = array(
		$wpdb->esc_like( '_transient_nera_iwt_instant_wins_api_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_nera_iwt_instant_wins_api_' ) . '%',
		$wpdb->esc_like( '_transient_nera_instant_wins_cache_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_nera_instant_wins_cache_' ) . '%',
	);
	foreach ( $patterns as $like ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional bulk cache bust.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
	}
}

/**
 * One-time flush when {@see NERA_IWT_INSTANT_WINS_PAYLOAD_SCHEMA} changes (deploy / plugin update).
 */
function nera_iwt_maybe_flush_instant_wins_api_cache_on_upgrade() {
	$opt = 'nera_iwt_instant_wins_payload_schema';
	$cur = (string) get_option( $opt, '' );
	if ( $cur === NERA_IWT_INSTANT_WINS_PAYLOAD_SCHEMA ) {
		return;
	}
	nera_iwt_delete_all_plugin_instant_wins_transients();
	update_option( $opt, NERA_IWT_INSTANT_WINS_PAYLOAD_SCHEMA, false );
}

add_action( 'plugins_loaded', 'nera_iwt_maybe_flush_instant_wins_api_cache_on_upgrade', 5 );

/**
 * Legacy keys we still flush on cache-bust events (forward compatibility cleanup).
 *
 * @param int $product_id Lottery product ID.
 * @return string[]
 */
function nera_iwt_instant_wins_legacy_cache_keys( $product_id ) {
	$pid = absint( $product_id );
	return array(
		'nera_iwt_instant_wins_api_' . $pid,
		'nera_iwt_instant_wins_api_v4_' . $pid,
		'nera_iwt_instant_wins_api_v5_' . $pid,
		'nera_iwt_instant_wins_api_v6_' . $pid,
		'nera_iwt_instant_wins_api_v7_' . $pid,
		'nera_iwt_instant_wins_api_v8_' . $pid,
		'nera_iwt_instant_wins_api_v9_' . $pid,
		'nera_instant_wins_cache_' . $pid,
	);
}

/**
 * Whether the current request should bypass the REST transient cache.
 *
 * Limited to logged-in users who can manage WooCommerce so anonymous traffic cannot
 * thrash the cache. Pass `?nera_iwt_nocache=1` on the REST URL to use it.
 *
 * @return bool
 */
function nera_iwt_request_should_bypass_cache() {
	if ( empty( $_GET['nera_iwt_nocache'] ) ) {
		return false;
	}
	if ( ! is_user_logged_in() ) {
		return false;
	}
	if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
		return true;
	}
	return false;
}

/**
 * Clear theme + plugin instant-wins REST caches for a lottery product.
 *
 * @param int $product_id Lottery product ID.
 */
function nera_iwt_maybe_clear_theme_instant_wins_cache( $product_id ) {
	$product_id = absint( $product_id );
	if ( $product_id <= 0 ) {
		return;
	}
	delete_transient( nera_iwt_instant_wins_api_cache_key( $product_id ) );
	foreach ( nera_iwt_instant_wins_legacy_cache_keys( $product_id ) as $legacy ) {
		delete_transient( $legacy );
	}
	if ( function_exists( 'nera_clear_instant_wins_cache' ) ) {
		nera_clear_instant_wins_cache( $product_id );
	}
}

/**
 * @param int $rule_id Instant-winner rule post ID.
 */
function nera_iwt_maybe_clear_theme_instant_wins_cache_for_rule( $rule_id ) {
	$rule_id = absint( $rule_id );
	if ( $rule_id <= 0 || ! function_exists( 'lty_get_instant_winner_rule' ) ) {
		return;
	}
	$rule = lty_get_instant_winner_rule( $rule_id );
	if ( ! is_object( $rule ) || ! method_exists( $rule, 'get_product_id' ) ) {
		return;
	}
	nera_iwt_maybe_clear_theme_instant_wins_cache( absint( $rule->get_product_id() ) );
}

/**
 * Bridge for LFW action hooks ({@see lty_instant_winner_rule_created}, etc.).
 *
 * @param int $product_id Lottery product ID.
 */
function nera_iwt_bust_instant_wins_caches_for_product( $product_id ) {
	nera_iwt_maybe_clear_theme_instant_wins_cache( absint( $product_id ) );
}

add_action( 'lty_instant_winner_rules_saved', 'nera_iwt_bust_instant_wins_caches_for_product', 10, 1 );
add_action( 'lty_instant_winner_rule_created', 'nera_iwt_bust_instant_wins_caches_for_product', 10, 1 );
add_action( 'lty_instant_winner_rules_deleted', 'nera_iwt_bust_instant_wins_caches_for_product', 10, 1 );

/**
 * Prize-group create/update does not fire LFW rule hooks — bust cache by parent product.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @param bool    $update  Whether this is an update.
 */
function nera_iwt_bust_instant_wins_cache_on_prize_group_save( $post_id, $post, $update ) {
	unset( $update );
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( ! $post instanceof WP_Post ) {
		return;
	}
	$pt = class_exists( 'LTY_Register_Post_Types', false )
		? LTY_Register_Post_Types::LOTTERY_INSTANT_WINNER_PRIZE_GROUP_POST_TYPE
		: 'lty_ins_win_group';
	if ( $pt !== $post->post_type ) {
		return;
	}
	$product_id = (int) $post->post_parent;
	if ( $product_id > 0 ) {
		nera_iwt_maybe_clear_theme_instant_wins_cache( $product_id );
	}
}

add_action(
	'save_post_' . ( class_exists( 'LTY_Register_Post_Types', false ) ? LTY_Register_Post_Types::LOTTERY_INSTANT_WINNER_PRIZE_GROUP_POST_TYPE : 'lty_ins_win_group' ),
	'nera_iwt_bust_instant_wins_cache_on_prize_group_save',
	20,
	3
);

/**
 * Safety net: any rule post save clears storefront cache for its parent product.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @param bool    $update  Whether this is an update.
 */
function nera_iwt_bust_instant_wins_cache_on_rule_post_saved( $post_id, $post, $update ) {
	unset( $update );
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( ! $post instanceof WP_Post ) {
		return;
	}
	$rule_pt = class_exists( 'LTY_Register_Post_Types', false )
		? LTY_Register_Post_Types::LOTTERY_INSTANT_WINNER_RULE_POSTTYPE
		: 'lty_instant_winners';
	if ( $rule_pt !== $post->post_type ) {
		return;
	}
	$product_id = (int) $post->post_parent;
	if ( $product_id > 0 ) {
		nera_iwt_maybe_clear_theme_instant_wins_cache( $product_id );
	}
}

add_action(
	'save_post_' . ( class_exists( 'LTY_Register_Post_Types', false ) ? LTY_Register_Post_Types::LOTTERY_INSTANT_WINNER_RULE_POSTTYPE : 'lty_instant_winners' ),
	'nera_iwt_bust_instant_wins_cache_on_rule_post_saved',
	99,
	3
);

/**
 * Clear cache after checkout so ticket-% visibility updates without waiting on transient TTL.
 *
 * @param int              $order_id    Order ID.
 * @param array            $posted_data Posted checkout data (unused).
 * @param WC_Order|WP_Post $order       Order object.
 */
function nera_iwt_maybe_clear_instant_wins_cache_after_checkout( $order_id, $posted_data, $order ) {
	unset( $posted_data, $order_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	foreach ( $order->get_items() as $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}
		$product = $item->get_product();
		if ( ! $product instanceof WC_Product ) {
			continue;
		}
		if ( 'lottery' !== $product->get_type() ) {
			continue;
		}
		if ( method_exists( $product, 'is_instant_winner' ) && $product->is_instant_winner() ) {
			nera_iwt_maybe_clear_theme_instant_wins_cache( $product->get_id() );
		}
	}
}

add_action( 'woocommerce_checkout_order_processed', 'nera_iwt_maybe_clear_instant_wins_cache_after_checkout', 20, 3 );
