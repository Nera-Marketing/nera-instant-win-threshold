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
 * When the product's instant-winner display mode is GROUP ('2'), delegates to
 * {@see nera_iwt_rest_build_group_prizes()} and emits a group-shaped payload.
 * Default mode ('1') output is byte-for-byte identical to before, plus `display_mode`.
 *
 * @param int $product_id Product ID.
 * @return array<string,mixed>|WP_Error
 */
function nera_iwt_rest_instant_wins_build_payload( $product_id ) {
	try {
		$product = wc_get_product( $product_id );

		$display_mode = ( $product && method_exists( $product, 'get_lty_instant_winner_display_mode' ) )
			? (string) $product->get_lty_instant_winner_display_mode()
			: '1';

		$instant_winner_ids = nera_iwt_get_rest_instant_winner_log_ids( $product_id );

		if ( empty( $instant_winner_ids ) ) {
			return array(
				'display_mode' => ( '2' === $display_mode ? 'group' : 'default' ),
				'prizes'       => array(),
				'stats'        => nera_iwt_rest_instant_wins_stats_for_theme_vue( $product ),
			);
		}

		if ( '2' === $display_mode ) {
			return nera_iwt_rest_build_group_prizes( $product );
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

			$schedule_fields = nera_iwt_rest_instant_wins_derive_schedule_fields(
				$vis ? $vis : array(),
				$rule_type
			);

			$prizes_grouped[ $key ] = array(
				'id'              => $key,
				'title'           => wp_strip_all_tags( $prize_message ),
				'image'           => nera_iwt_rest_instant_wins_extract_image_url( $rep_winner->get_image() ),
				'total_available' => 0,
				'won_count'       => 0,
				'winners'         => array(),
				'rule_type'       => $rule_type,
				'ticket_pct'      => $vis ? (int) $vis['ticket_pct'] : 0,
				'schedule_at'     => $schedule_fields['schedule_at'],
				'schedule_end'    => $schedule_fields['schedule_end'],
				'schedule_at_utc' => $schedule_fields['schedule_at_utc'],
				'schedule_end_utc' => $schedule_fields['schedule_end_utc'],
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
			'display_mode' => 'default',
			'prizes'       => array_values( $prizes_grouped ),
			'stats'        => $stats,
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
 * Derive schedule display fields from a resolved visibility array + rule meta.
 *
 * Shared between the default single-log branch and the group branch so the output
 * is identical for schedule prizes regardless of display mode.
 *
 * @param array    $vis     Resolved visibility array from {@see nera_iwt_resolve_rule_visibility_for_log()}.
 * @param string   $rule_type Rule type string (one of NERA_IWT_RULE_TYPE_* constants).
 * @return array{schedule_at:string,schedule_end:string,schedule_at_utc:string,schedule_end_utc:string}
 */
function nera_iwt_rest_instant_wins_derive_schedule_fields( array $vis, $rule_type ) {
	$schedule_at      = '';
	$schedule_end     = '';
	$schedule_at_utc  = '';
	$schedule_end_utc = '';

	if ( NERA_IWT_RULE_TYPE_SCHEDULE === $rule_type && $vis ) {
		$rule_id       = (int) $vis['rule_id'];
		$at_gmt_row    = '';
		$end_gmt_row   = '';
		$at_local_row  = '';
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

	return array(
		'schedule_at'      => trim( (string) $schedule_at ),
		'schedule_end'     => trim( (string) $schedule_end ),
		'schedule_at_utc'  => $schedule_at_utc,
		'schedule_end_utc' => $schedule_end_utc,
	);
}

/**
 * Map an instant-winner log post status to a simplified ticket status string.
 *
 * @param string $status Raw LFW log status (e.g. 'lty_won', 'lty_available', 'lty_pending').
 * @return string One of: 'won', 'pending', 'available'.
 */
function nera_iwt_map_log_status( $status ) {
	switch ( $status ) {
		case 'lty_won':
			return 'won';
		case 'lty_pending':
			return 'pending';
		default:
			return 'available';
	}
}

/**
 * Reduce an array of log IDs to the most-permissive effective visibility rule.
 *
 * Precedence (most to least permissive):
 *   1. `instant`    — if any log has an instant rule, the group is instant (show always).
 *   2. `ticket_pct` — if any log has a ticket-% rule, use ticket_pct type with the
 *                     MINIMUM threshold (and the rule_id that owns that minimum).
 *   3. `schedule`   — use the schedule rule with the EARLIEST schedule_gmt start.
 *
 * Falls back to `instant` if no rules resolve at all.
 *
 * Returns the same associative shape as {@see nera_iwt_resolve_rule_visibility_for_log()}
 * so the schedule-derivation block can consume it without changes.
 *
 * @param int[] $log_ids Log post IDs belonging to one prize group.
 * @return array{rule_id:int,type:string,schedule_gmt:string,schedule_local:string,schedule_end_gmt:string,schedule_end_local:string,ticket_pct:int}
 */
function nera_iwt_group_effective_visibility( array $log_ids ) {
	$has_instant     = false;
	$min_pct         = null;
	$min_pct_vis     = null;
	$earliest_sched  = null;
	$earliest_vis    = null;

	foreach ( $log_ids as $log_id ) {
		$log_id = absint( $log_id );
		if ( $log_id <= 0 ) {
			continue;
		}
		$vis = nera_iwt_resolve_rule_visibility_for_log( $log_id );
		if ( null === $vis ) {
			continue;
		}

		if ( NERA_IWT_RULE_TYPE_INSTANT === $vis['type'] ) {
			$has_instant = true;
			// Instant is the most permissive — short-circuit as soon as we find one.
			break;
		}

		if ( NERA_IWT_RULE_TYPE_TICKET_PCT === $vis['type'] ) {
			$pct = (int) $vis['ticket_pct'];
			if ( null === $min_pct || $pct < $min_pct ) {
				$min_pct     = $pct;
				$min_pct_vis = $vis;
			}
			continue;
		}

		if ( NERA_IWT_RULE_TYPE_SCHEDULE === $vis['type'] ) {
			$sched_gmt = trim( (string) $vis['schedule_gmt'] );
			if ( null === $earliest_vis ) {
				$earliest_sched = $sched_gmt;
				$earliest_vis   = $vis;
			} elseif ( '' !== $sched_gmt ) {
				// Compare raw datetime strings lexicographically (MySQL 'Y-m-d H:i:s' format is sortable).
				if ( '' === $earliest_sched || strcmp( $sched_gmt, $earliest_sched ) < 0 ) {
					$earliest_sched = $sched_gmt;
					$earliest_vis   = $vis;
				}
			}
		}
	}

	if ( $has_instant ) {
		return array(
			'rule_id'            => 0,
			'type'               => NERA_IWT_RULE_TYPE_INSTANT,
			'schedule_gmt'       => '',
			'schedule_local'     => '',
			'schedule_end_gmt'   => '',
			'schedule_end_local' => '',
			'ticket_pct'         => 0,
		);
	}

	if ( null !== $min_pct_vis ) {
		return $min_pct_vis;
	}

	if ( null !== $earliest_vis ) {
		return $earliest_vis;
	}

	// Default: treat as instant when nothing resolved.
	return array(
		'rule_id'            => 0,
		'type'               => NERA_IWT_RULE_TYPE_INSTANT,
		'schedule_gmt'       => '',
		'schedule_local'     => '',
		'schedule_end_gmt'   => '',
		'schedule_end_local' => '',
		'ticket_pct'         => 0,
	);
}

/**
 * Build a group-mode prize payload for GET /nera/v1/instant-wins/{product_id}.
 *
 * Called when the product's `_lty_instant_winner_display_mode` meta is '2'.
 * Iterates LFW prize groups, fetches their log IDs for the current relist, and
 * builds one entry per group (including a `tickets[]` sub-array).
 *
 * @param WC_Product $product Lottery product (already validated as lottery + instant winner).
 * @return array{display_mode:string,prizes:array,stats:array}
 */
function nera_iwt_rest_build_group_prizes( WC_Product $product ) {
	$product_id = $product->get_id();

	$relist = method_exists( $product, 'get_current_relist_count' )
		? (int) $product->get_current_relist_count()
		: 0;

	$image_on = method_exists( $product, 'get_lty_display_instant_winner_image' )
		? ( '1' === (string) $product->get_lty_display_instant_winner_image() )
		: true;

	$group_ids = function_exists( 'lty_get_instant_winner_prize_group_ids' )
		? lty_get_instant_winner_prize_group_ids( $product_id )
		: array();

	if ( ! is_array( $group_ids ) ) {
		$group_ids = array();
	}

	$groups_keyed = array();

	foreach ( $group_ids as $gid ) {
		$gid = absint( $gid );
		if ( $gid <= 0 ) {
			continue;
		}

		$group = lty_get_instant_winner_prize_group( $gid );
		if ( ! is_object( $group ) || ! method_exists( $group, 'exists' ) || ! $group->exists() ) {
			continue;
		}

		$log_ids = lty_get_instant_winner_log_ids_by_group_id( $gid, $relist, 'all' );
		if ( empty( $log_ids ) || ! is_array( $log_ids ) ) {
			continue;
		}

		// Title: prefer prize message, fall back to group title.
		$prize_message = method_exists( $group, 'get_prize_message' ) ? (string) $group->get_prize_message() : '';
		$title         = '' !== trim( $prize_message )
			? wp_strip_all_tags( $prize_message )
			: ( method_exists( $group, 'get_title' ) ? wp_strip_all_tags( $group->get_title() ) : '' );

		// Image: get_image_url() returns the plain URL (not HTML); returns placeholder when no image_id.
		// We return null when the image toggle is off OR when the group has no image attachment.
		$image = null;
		if ( $image_on && method_exists( $group, 'get_image_id' ) && method_exists( $group, 'get_image_url' ) ) {
			$image_id = $group->get_image_id();
			if ( ! empty( $image_id ) ) {
				$raw_url = (string) $group->get_image_url();
				$image   = ( '' !== $raw_url ) ? esc_url( $raw_url ) : null;
			}
		}

		// Effective visibility across all logs in this group.
		$vis       = nera_iwt_group_effective_visibility( $log_ids );
		$rule_type = $vis['type'];

		// Derive schedule display fields using the shared helper.
		$schedule_fields = nera_iwt_rest_instant_wins_derive_schedule_fields( $vis, $rule_type );

		$total_available = 0;
		$won_count       = 0;
		$winners         = array();
		$tickets         = array();

		foreach ( $log_ids as $log_id ) {
			$log_id = absint( $log_id );
			if ( $log_id <= 0 ) {
				continue;
			}
			$log = lty_get_instant_winner_log( $log_id );
			if ( ! is_object( $log ) || ! method_exists( $log, 'exists' ) || ! $log->exists() ) {
				continue;
			}

			$total_available++;

			$raw_status    = (string) get_post_status( $log_id );
			$mapped_status = nera_iwt_map_log_status( $raw_status );

			$ticket_number = method_exists( $log, 'get_formatted_ticket_number' )
				? sanitize_text_field( (string) $log->get_formatted_ticket_number() )
				: '';

			$tickets[] = array(
				'number' => $ticket_number,
				'status' => $mapped_status,
			);

			if ( method_exists( $log, 'has_status' ) && $log->has_status( 'lty_won' ) ) {
				$won_count++;
				$winner_details = nera_iwt_rest_instant_wins_format_winner_details( $log );
				if ( $winner_details ) {
					$winners[] = $winner_details;
				}
			}
		}

		$group_key              = 'group-' . $gid;
		$groups_keyed[ $group_key ] = array(
			'id'              => $group_key,
			'title'           => $title,
			'image'           => $image,
			'total_available' => $total_available,
			'won_count'       => $won_count,
			'winners'         => $winners,
			'rule_type'       => $rule_type,
			'ticket_pct'      => $vis ? (int) $vis['ticket_pct'] : 0,
			'schedule_at'     => $schedule_fields['schedule_at'],
			'schedule_end'    => $schedule_fields['schedule_end'],
			'schedule_at_utc' => $schedule_fields['schedule_at_utc'],
			'schedule_end_utc' => $schedule_fields['schedule_end_utc'],
			'tickets'         => $tickets,
		);
	}

	$stats = nera_iwt_rest_instant_wins_stats_from_grouped_prizes( $groups_keyed );

	return array(
		'display_mode' => 'group',
		'prizes'       => array_values( $groups_keyed ),
		'stats'        => $stats,
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
