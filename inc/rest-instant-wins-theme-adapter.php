<?php
/**
 * REST ↔ theme Vue contract for instant-wins stats (no theme file changes required).
 *
 * The nera-competitions-standard InstantWinsContainer.vue transform uses:
 * `availableCount = stats.total_available - stats.total_won`.
 * So `stats.total_available` must be **won + remaining** (total storefront-visible slots),
 * not “remaining only”.
 *
 * For responses that include `prizes`, {@see nera_iwt_rest_instant_wins_stats_from_grouped_prizes()}
 * derives `stats` from the same grouped rows so the Vue stats bar cannot drift from the card list.
 * Empty payloads still use {@see nera_iwt_rest_instant_wins_stats_for_theme_vue()}.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build `stats` for GET /nera/v1/instant-wins/{id} for unchanged theme Vue.
 *
 * @param WC_Product|null $product Lottery product.
 * @return array{total_available:int,total_won:int}
 */
function nera_iwt_rest_instant_wins_stats_for_theme_vue( $product ) {
	if ( ! $product instanceof WC_Product || ! function_exists( 'nera_iwt_get_public_instant_wins_section_counts' ) ) {
		return array(
			'total_available' => 0,
			'total_won'       => 0,
		);
	}

	$counts = nera_iwt_get_public_instant_wins_section_counts( $product );

	return array(
		'total_available' => (int) ( $counts['available'] + $counts['won'] ),
		'total_won'       => (int) $counts['won'],
	);
}

/**
 * Build REST `stats` from grouped prize rows (same source as `prizes` array).
 *
 * @param array<string, array<string, mixed>> $prizes_grouped Keyed by prize hash (before array_values).
 * @return array{total_available:int,total_won:int}
 */
function nera_iwt_rest_instant_wins_stats_from_grouped_prizes( array $prizes_grouped ) {
	$won       = 0;
	$remaining = 0;
	foreach ( $prizes_grouped as $row ) {
		$w = (int) ( $row['won_count'] ?? 0 );
		$t = (int) ( $row['total_available'] ?? 0 );
		$won += $w;
		$remaining += max( 0, $t - $w );
	}

	return array(
		'total_available' => $remaining + $won,
		'total_won'       => $won,
	);
}
