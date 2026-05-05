<?php
/**
 * Override GET /wp-json/nera/v1/instant-wins/{product_id} so storefront visibility
 * is applied explicitly (plugin-owned), independent of the theme REST class.
 *
 * Hooks into `rest_api_init` at priority 20 (after the theme's priority 10) and
 * calls register_rest_route() with $override = true to replace the theme's
 * Nera_Instant_Wins_API::get_instant_wins callback with our filtered handler.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

require_once NERA_IWT_PLUGIN_DIR . 'inc/rest-instant-wins-theme-adapter.php';

/** @var int */
const NERA_IWT_REST_INSTANT_WINS_CACHE_TTL = 60;

/** @var int */
const NERA_IWT_REST_INSTANT_WINS_RATE_LIMIT = 30;

add_action( 'rest_api_init', 'nera_iwt_register_instant_wins_rest_route', 20 );

/**
 * Register plugin-owned handler for GET /nera/v1/instant-wins/{product_id}.
 *
 * $override = true replaces the theme's registered callback (registered at priority 10).
 */
function nera_iwt_register_instant_wins_rest_route() {
	if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'lty_get_instant_winner_log' ) ) {
		return;
	}
	register_rest_route(
		'nera/v1',
		'/instant-wins/(?P<product_id>\d+)',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'nera_iwt_rest_instant_wins_route_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'product_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'validate_callback' => static function ( $param ) {
						return is_numeric( $param ) && $param > 0;
					},
					'sanitize_callback' => 'absint',
				),
			),
		),
		true // $override — replaces Nera_Instant_Wins_API::get_instant_wins.
	);
}

/**
 * Route handler: applies visibility rules then returns the prize payload to Vue.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function nera_iwt_rest_instant_wins_route_handler( WP_REST_Request $request ) {
	$product_id = absint( $request->get_param( 'product_id' ) );

	$rate = nera_iwt_rest_instant_wins_check_rate_limit( $product_id );
	if ( is_wp_error( $rate ) ) {
		return $rate;
	}

	$bypass    = nera_iwt_request_should_bypass_cache();
	$cache_key = nera_iwt_instant_wins_api_cache_key( $product_id );

	if ( ! $bypass ) {
		$cached = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => $cached,
					'cached'  => true,
					'source'  => 'nera-instant-win-threshold',
				)
			);
		}
	}

	$validation = nera_iwt_rest_instant_wins_validate_product( $product_id );
	if ( is_wp_error( $validation ) ) {
		return $validation;
	}

	$data = nera_iwt_rest_instant_wins_build_payload( $product_id );
	if ( is_wp_error( $data ) ) {
		return $data;
	}

	if ( ! $bypass ) {
		set_transient( $cache_key, $data, NERA_IWT_REST_INSTANT_WINS_CACHE_TTL );
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => $data,
			'cached'  => false,
			'source'  => 'nera-instant-win-threshold',
			'bypass'  => $bypass,
		)
	);
}

// ---------------------------------------------------------------------------
// Helpers.
// ---------------------------------------------------------------------------

/**
 * @param int $product_id Product ID.
 * @return true|WP_Error
 */
function nera_iwt_rest_instant_wins_validate_product( $product_id ) {
	$product = wc_get_product( $product_id );
	if ( ! $product || ! $product->exists() ) {
		return new WP_Error(
			'invalid_product',
			__( 'Product not found.', 'nera-instant-win-threshold' ),
			array( 'status' => 404 )
		);
	}
	if ( 'lottery' !== $product->get_type() ) {
		return new WP_Error(
			'invalid_product_type',
			__( 'Product is not a lottery/competition.', 'nera-instant-win-threshold' ),
			array( 'status' => 400 )
		);
	}
	$has = false;
	if ( method_exists( $product, 'is_instant_winner' ) ) {
		$has = (bool) $product->is_instant_winner();
	}
	if ( ! $has ) {
		return new WP_Error(
			'instant_wins_disabled',
			__( 'Instant wins are not enabled for this product.', 'nera-instant-win-threshold' ),
			array( 'status' => 400 )
		);
	}
	return true;
}

/**
 * Pick which log supplies rule/schedule meta for a prize_message + rule group.
 *
 * Uses the **first** log in LFW list order for that bucket (see md5 key in
 * {@see nera_iwt_rest_instant_wins_build_payload()}).
 * (Changing this breaks merged groups when multiple rules share the same prize text.)
 *
 * @param int[] $log_ids Log post IDs sharing one prize_message key.
 * @return int Representative log ID, or 0.
 */
function nera_iwt_rest_pick_representative_log_for_prize_group( array $log_ids ) {
	return absint( $log_ids[0] ?? 0 );
}

/**
 * Build instant-wins REST payload (prize groups + stats).
 *
 * Uses nera_iwt_get_rest_instant_winner_log_ids() which returns every log for the relist.
 * Each row includes rule_type, ticket_pct, and schedule_* for CMS parity with admin.
 *
 * @param int $product_id Product ID.
 * @return array<string,mixed>|WP_Error
 */
function nera_iwt_rest_instant_wins_build_payload( $product_id ) {
	try {
		$instant_winner_ids = nera_iwt_get_rest_instant_winner_log_ids( $product_id );

		$product = wc_get_product( $product_id );

		if ( empty( $instant_winner_ids ) ) {
			return array(
				'prizes' => array(),
				'stats'  => nera_iwt_rest_instant_wins_stats_for_theme_vue( $product ),
			);
		}

		$key_to_ids = array();

		foreach ( $instant_winner_ids as $instant_winner_id ) {
			$instant_winner_id = absint( $instant_winner_id );
			if ( $instant_winner_id <= 0 ) {
				continue;
			}
			$instant_winner = lty_get_instant_winner_log( $instant_winner_id );
			if ( ! is_object( $instant_winner ) || ! method_exists( $instant_winner, 'get_prize_message' ) ) {
				continue;
			}
			$rule_for_key = nera_iwt_get_instant_winner_rule_id_for_log( $instant_winner_id );
			$key            = md5( $instant_winner->get_prize_message() . "\x1f" . $rule_for_key );
			$key_to_ids[ $key ][] = $instant_winner_id;
		}

		$prizes_grouped = array();

		foreach ( $key_to_ids as $key => $log_ids ) {
			if ( ! is_array( $log_ids ) || empty( $log_ids ) ) {
				continue;
			}

			$rep_id = nera_iwt_rest_pick_representative_log_for_prize_group( $log_ids );
			if ( $rep_id <= 0 ) {
				continue;
			}

			$rep_winner = lty_get_instant_winner_log( $rep_id );
			if ( ! is_object( $rep_winner ) || ! method_exists( $rep_winner, 'get_prize_message' ) ) {
				continue;
			}

			$prize_message = $rep_winner->get_prize_message();
			$vis           = nera_iwt_resolve_rule_visibility_for_log( $rep_id );
			$rule_type     = $vis ? $vis['type'] : NERA_IWT_RULE_TYPE_INSTANT;

			$schedule_at      = '';
			$schedule_end     = '';
			$schedule_at_utc  = '';
			$schedule_end_utc = '';

			if ( NERA_IWT_RULE_TYPE_SCHEDULE === $rule_type && $vis ) {
				$rule_id = (int) $vis['rule_id'];
				$at_gmt_row   = '';
				$end_gmt_row  = '';
				$at_local_row = '';
				$end_local_row = '';
				if ( $rule_id > 0 ) {
					$at_gmt_row    = trim( (string) get_post_meta( $rule_id, 'nera_iwt_schedule_at_gmt', true ) );
					$end_gmt_row   = trim( (string) get_post_meta( $rule_id, 'nera_iwt_schedule_end_gmt', true ) );
					$at_local_row  = trim( (string) get_post_meta( $rule_id, 'nera_iwt_schedule_at_local', true ) );
					$end_local_row = trim( (string) get_post_meta( $rule_id, 'nera_iwt_schedule_end_local', true ) );
				}
				// Match admin table: canonical wall times come from GMT meta when set (avoids stale _local).
				$schedule_at = ( '' !== $at_gmt_row )
					? nera_iwt_schedule_gmt_to_local_input( $at_gmt_row )
					: $at_local_row;
				if ( '' === $schedule_at && '' !== trim( (string) $vis['schedule_gmt'] ) ) {
					$schedule_at = nera_iwt_schedule_gmt_to_local_input( $vis['schedule_gmt'] );
				}
				$schedule_end = ( '' !== $end_gmt_row )
					? nera_iwt_schedule_gmt_to_local_input( $end_gmt_row )
					: $end_local_row;
				$end_gmt_vis = isset( $vis['schedule_end_gmt'] ) ? trim( (string) $vis['schedule_end_gmt'] ) : '';
				if ( '' === $schedule_end && '' !== $end_gmt_vis ) {
					$schedule_end = nera_iwt_schedule_gmt_to_local_input( $end_gmt_vis );
				}
				$schedule_at_utc  = $at_gmt_row;
				$schedule_end_utc = $end_gmt_row;
			}

			$schedule_at  = trim( (string) $schedule_at );
			$schedule_end = trim( (string) $schedule_end );

			$prizes_grouped[ $key ] = array(
				'id'                 => $key,
				'title'              => wp_strip_all_tags( $prize_message ),
				'image'              => nera_iwt_rest_instant_wins_extract_image_url( $rep_winner->get_image() ),
				'total_available'    => 0,
				'won_count'          => 0,
				'winners'            => array(),
				'rule_type'          => $rule_type,
				'ticket_pct'         => $vis ? (int) $vis['ticket_pct'] : 0,
				'schedule_at'        => $schedule_at,
				'schedule_end'       => $schedule_end,
				'schedule_at_utc'    => $schedule_at_utc,
				'schedule_end_utc'   => $schedule_end_utc,
			);

			foreach ( $log_ids as $log_id ) {
				$log_id = absint( $log_id );
				if ( $log_id <= 0 ) {
					continue;
				}
				$instant_winner = lty_get_instant_winner_log( $log_id );
				if ( ! is_object( $instant_winner ) ) {
					continue;
				}
				$prizes_grouped[ $key ]['total_available']++;
				if ( method_exists( $instant_winner, 'has_status' ) && $instant_winner->has_status( 'lty_won' ) ) {
					$prizes_grouped[ $key ]['won_count']++;
					$winner_details = nera_iwt_rest_instant_wins_format_winner_details( $instant_winner );
					if ( $winner_details ) {
						$prizes_grouped[ $key ]['winners'][] = $winner_details;
					}
				}
			}
		}

		$stats = nera_iwt_rest_instant_wins_stats_from_grouped_prizes( $prizes_grouped );

		return array(
			'prizes' => array_values( $prizes_grouped ),
			'stats'  => $stats,
		);
	} catch ( Exception $e ) {
		return new WP_Error(
			'data_fetch_error',
			__( 'Error fetching instant wins data.', 'nera-instant-win-threshold' ),
			array( 'status' => 500 )
		);
	}
}


/**
 * @param string $html Image HTML.
 * @return string|null
 */
function nera_iwt_rest_instant_wins_extract_image_url( $html ) {
	if ( empty( $html ) ) {
		return null;
	}
	if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches ) ) {
		return esc_url( $matches[1] );
	}
	return null;
}

/**
 * @param object $instant_winner LFW log object.
 * @return array<string,string>|null
 */
function nera_iwt_rest_instant_wins_format_winner_details( $instant_winner ) {
	if ( ! is_object( $instant_winner ) || ! method_exists( $instant_winner, 'get_instant_winner_details' ) ) {
		return null;
	}
	$details_html = $instant_winner->get_instant_winner_details();
	if ( empty( $details_html ) ) {
		return null;
	}
	$details_text = wp_strip_all_tags( $details_html );
	$name         = '';
	$date         = '';
	if ( preg_match( '/^(.+?)\s*[–\-|]\s*(.+)$/', $details_text, $matches ) ) {
		$name = trim( $matches[1] );
		$date = trim( $matches[2] );
	} else {
		$name = $details_text;
	}
	$name          = nera_iwt_rest_instant_wins_sanitize_winner_name( $name );
	$ticket_number = method_exists( $instant_winner, 'get_formatted_ticket_number' ) ? $instant_winner->get_formatted_ticket_number() : '';
	return array(
		'name'   => $name,
		'ticket' => $ticket_number ? sanitize_text_field( (string) $ticket_number ) : '',
		'date'   => sanitize_text_field( $date ),
	);
}

/**
 * @param string $name Name.
 * @return string
 */
function nera_iwt_rest_instant_wins_sanitize_winner_name( $name ) {
	$name = sanitize_text_field( $name );
	if ( preg_match( '/^[A-Za-z]+\s+[A-Z]\.$/', $name ) ) {
		return $name;
	}
	$parts = explode( ' ', $name );
	if ( count( $parts ) >= 2 ) {
		$first_name   = $parts[0];
		$last_initial = strtoupper( substr( $parts[ count( $parts ) - 1 ], 0, 1 ) );
		return $first_name . ' ' . $last_initial . '.';
	}
	return $name;
}

/**
 * @param int $product_id Product ID.
 * @return true|WP_Error
 */
function nera_iwt_rest_instant_wins_check_rate_limit( $product_id ) {
	$ip       = nera_iwt_rest_instant_wins_get_client_ip();
	$rate_key = 'nera_iwt_instant_wins_rate_' . md5( $ip . '_' . $product_id );

	$request_count = get_transient( $rate_key );

	if ( false === $request_count ) {
		set_transient( $rate_key, 1, MINUTE_IN_SECONDS );
		return true;
	}

	if ( $request_count >= NERA_IWT_REST_INSTANT_WINS_RATE_LIMIT ) {
		return new WP_Error(
			'rate_limit_exceeded',
			sprintf(
				/* translators: %d: max requests per minute */
				__( 'Rate limit exceeded. Maximum %d requests per minute.', 'nera-instant-win-threshold' ),
				(int) NERA_IWT_REST_INSTANT_WINS_RATE_LIMIT
			),
			array( 'status' => 429 )
		);
	}

	set_transient( $rate_key, $request_count + 1, MINUTE_IN_SECONDS );

	return true;
}

/**
 * @return string
 */
function nera_iwt_rest_instant_wins_get_client_ip() {
	$ip_keys = array(
		'HTTP_CLIENT_IP',
		'HTTP_X_FORWARDED_FOR',
		'HTTP_X_FORWARDED',
		'HTTP_X_CLUSTER_CLIENT_IP',
		'HTTP_FORWARDED_FOR',
		'HTTP_FORWARDED',
		'REMOTE_ADDR',
	);
	foreach ( $ip_keys as $key ) {
		if ( empty( $_SERVER[ $key ] ) ) {
			continue;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		foreach ( explode( ',', (string) wp_unslash( $_SERVER[ $key ] ) ) as $ip ) {
			$ip = trim( $ip );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) !== false ) {
				return $ip;
			}
		}
	}
	return '0.0.0.0';
}
