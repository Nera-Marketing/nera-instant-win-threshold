<?php
/**
 * Storefront visibility resolver for instant-winner logs.
 *
 * Single source of truth for "should this log appear on the public product page".
 * Reads rule meta from the **rule post** (parent of the log), not from the log meta —
 * so admin edits to a rule are reflected immediately even if a log's meta was not synced.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolved visibility settings for a log, read from the parent rule post.
 *
 * @param int $log_id Log post ID.
 * @return array{rule_id:int,type:string,schedule_gmt:string,schedule_local:string,ticket_pct:int}|null Null if no rule found.
 */
function nera_iwt_resolve_rule_visibility_for_log( $log_id ) {
	$log_id = absint( $log_id );
	if ( $log_id <= 0 ) {
		return null;
	}

	$rule_id = nera_iwt_get_instant_winner_rule_id_for_log( $log_id );
	if ( $rule_id <= 0 ) {
		return null;
	}

	$type = (string) get_post_meta( $rule_id, 'nera_iwt_public_rule_type', true );
	if ( '' === $type || ! in_array( $type, nera_iwt_public_rule_type_slugs(), true ) ) {
		$type = NERA_IWT_RULE_TYPE_INSTANT;
	}

	return array(
		'rule_id'        => $rule_id,
		'type'           => $type,
		'schedule_gmt'   => trim( (string) get_post_meta( $rule_id, 'nera_iwt_schedule_at_gmt', true ) ),
		'schedule_local' => trim( (string) get_post_meta( $rule_id, 'nera_iwt_schedule_at_local', true ) ),
		'ticket_pct'     => max( 0, min( 100, intval( get_post_meta( $rule_id, 'nera_iwt_ticket_pct', true ) ) ) ),
	);
}

/**
 * Parse a datetime-local style string in the site WordPress timezone (wall clock).
 *
 * Used for the initial PHP storefront count when the REST client has not run yet.
 * Matches the same calendar time the admin entered in {@see nera_iwt_schedule_at_local}.
 *
 * @param string $local_string e.g. "2026-05-03T16:20" or "2026-05-03 16:20:00".
 * @return DateTimeImmutable|null
 */
function nera_iwt_parse_schedule_local_wp_timezone( $local_string ) {
	$local_string = trim( (string) $local_string );
	if ( '' === $local_string ) {
		return null;
	}
	$normalized = str_replace( 'T', ' ', $local_string );
	$tz           = wp_timezone();
	try {
		$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $normalized, $tz );
		if ( $dt instanceof DateTimeImmutable ) {
			return $dt;
		}
		$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $normalized, $tz );
		if ( $dt instanceof DateTimeImmutable ) {
			return $dt;
		}
		return new DateTimeImmutable( $normalized, $tz );
	} catch ( Exception $e ) {
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalized ) ) {
			try {
				return new DateTimeImmutable( $normalized . ':00', $tz );
			} catch ( Exception $e2 ) {
				return null;
			}
		}
		return null;
	}
}

/**
 * Parse a stored UTC schedule string into a DateTimeImmutable, or null on failure.
 *
 * Accepts both 'Y-m-d H:i:s' and 'Y-m-d\TH:i' shapes.
 *
 * @param string $stored UTC datetime string.
 * @return DateTimeImmutable|null
 */
function nera_iwt_parse_schedule_gmt( $stored ) {
	$stored = trim( (string) $stored );
	if ( '' === $stored ) {
		return null;
	}
	$normalized = str_replace( 'T', ' ', $stored );
	try {
		return new DateTimeImmutable( $normalized, new DateTimeZone( 'UTC' ) );
	} catch ( Exception $e ) {
		// Some installs may have stored 'Y-m-d H:i' (no seconds) — pad before retry.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalized ) ) {
			try {
				return new DateTimeImmutable( $normalized . ':00', new DateTimeZone( 'UTC' ) );
			} catch ( Exception $e2 ) {
				return null;
			}
		}
		return null;
	}
}

/**
 * Visibility decision for a non-won log row.
 *
 * @param int        $log_id  Log post ID.
 * @param WC_Product $product Lottery product.
 * @param array      $args    Options:
 *   - skip_schedule (bool) Pass true to skip server-side schedule check; used by the
 *     REST path so schedule filtering is delegated to browser-side JavaScript
 *     (client local time, not server UTC time).
 * @return bool True to show on storefront.
 */
function nera_iwt_available_log_visible_on_storefront( $log_id, $product, array $args = array() ) {
	if ( ! $product instanceof WC_Product ) {
		return true;
	}

	$vis = nera_iwt_resolve_rule_visibility_for_log( $log_id );
	if ( null === $vis ) {
		nera_iwt_debug_log_visibility( $log_id, 'orphan_log_no_parent_rule', true );
		return true;
	}

	if ( NERA_IWT_RULE_TYPE_INSTANT === $vis['type'] ) {
		nera_iwt_debug_log_visibility( $log_id, 'instant', true, $vis );
		return true;
	}

	if ( NERA_IWT_RULE_TYPE_SCHEDULE === $vis['type'] ) {
		if ( ! empty( $args['skip_schedule'] ) ) {
			// Schedule comparison delegated to client-side JavaScript (browser local time).
			nera_iwt_debug_log_visibility( $log_id, 'schedule_delegated_to_client', true, $vis );
			return true;
		}
		$local_raw = isset( $vis['schedule_local'] ) ? trim( (string) $vis['schedule_local'] ) : '';
		if ( '' !== $local_raw ) {
			$at_local = nera_iwt_parse_schedule_local_wp_timezone( $local_raw );
			if ( null !== $at_local ) {
				try {
					$now_local = new DateTimeImmutable( 'now', wp_timezone() );
					$show      = $now_local >= $at_local;
					nera_iwt_debug_log_visibility(
						$log_id,
						$show ? 'schedule_reached_wp_tz' : 'schedule_pending_wp_tz',
						$show,
						$vis + array(
							'now_local' => $now_local->format( 'Y-m-d H:i:s' ),
							'at_local'  => $at_local->format( 'Y-m-d H:i:s' ),
						)
					);
					return $show;
				} catch ( Exception $e ) {
					// Fall through to GMT.
				}
			}
		}
		if ( '' === $vis['schedule_gmt'] ) {
			nera_iwt_debug_log_visibility( $log_id, 'schedule_no_date', true, $vis );
			return true;
		}
		$at_utc = nera_iwt_parse_schedule_gmt( $vis['schedule_gmt'] );
		if ( null === $at_utc ) {
			nera_iwt_debug_log_visibility( $log_id, 'schedule_unparseable', true, $vis );
			return true;
		}
		$show = time() >= $at_utc->getTimestamp();
		nera_iwt_debug_log_visibility(
			$log_id,
			$show ? 'schedule_reached' : 'schedule_pending',
			$show,
			$vis + array( 'now_utc' => gmdate( 'Y-m-d H:i:s' ) )
		);
		return $show;
	}

	if ( NERA_IWT_RULE_TYPE_TICKET_PCT === $vis['type'] ) {
		if ( $vis['ticket_pct'] <= 0 ) {
			nera_iwt_debug_log_visibility( $log_id, 'ticket_pct_zero', true, $vis );
			return true;
		}
		$pct_sold = nera_iwt_get_lottery_ticket_sold_percent( $product );
		if ( null === $pct_sold ) {
			nera_iwt_debug_log_visibility( $log_id, 'ticket_pct_max_unknown', false, $vis );
			return false;
		}
		$show = $pct_sold >= (float) $vis['ticket_pct'];
		nera_iwt_debug_log_visibility(
			$log_id,
			$show ? 'ticket_pct_reached' : 'ticket_pct_pending',
			$show,
			$vis + array( 'pct_sold' => $pct_sold )
		);
		return $show;
	}

	nera_iwt_debug_log_visibility( $log_id, 'unknown_type', true, $vis );
	return true;
}

/**
 * Whether a log should appear in storefront instant-win lists (REST + template queries).
 *
 * Won logs surface unconditionally (winner history). Other statuses follow rule visibility.
 *
 * @param int        $log_id  Log post ID.
 * @param WC_Product $product Lottery product.
 */
function nera_iwt_instant_winner_log_included_in_storefront_list( $log_id, $product ) {
	$st = get_post_status( $log_id );
	if ( 'lty_won' === $st ) {
		return true;
	}
	return nera_iwt_available_log_visible_on_storefront( $log_id, $product );
}

/**
 * Optional debug trace emitted via {@see error_log()} when WP_DEBUG_LOG is on.
 *
 * @param int    $log_id Log post ID.
 * @param string $reason Short reason code.
 * @param bool   $show   Decision.
 * @param array  $ctx    Optional context.
 */
function nera_iwt_debug_log_visibility( $log_id, $reason, $show, array $ctx = array() ) {
	if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
		return;
	}
	$line = sprintf(
		'[nera-iwt] visibility log_id=%d show=%s reason=%s ctx=%s',
		(int) $log_id,
		$show ? 'yes' : 'no',
		$reason,
		wp_json_encode( $ctx )
	);
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- gated by WP_DEBUG_LOG.
	error_log( $line );
}
