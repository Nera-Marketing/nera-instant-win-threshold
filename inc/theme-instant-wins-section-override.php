<?php
/**
 * Load the plugin copy of `template-parts/single-product/instant-wins-section.php`
 * whenever the theme (or child theme) would load that template part.
 *
 * Core {@see locate_template()} has no filter on this WordPress build, so we buffer
 * the theme include in {@see 'wp_before_load_template'} / {@see 'wp_after_load_template'}
 * and replace it with {@see NERA_IWT_PLUGIN_DIR} . 'templates/instant-wins-section.php'.
 *
 * Primary public integration: {@see nera_competitions_render_instant_win_prizes_section()} loads
 * the theme wrapper and calls `get_template_part( ... instant-wins-section )`, which this bridge
 * redirects to the plugin copy of the inner template.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the resolved path is the theme’s instant-wins section (not the plugin file).
 *
 * @param string $path Absolute filesystem path.
 * @return bool
 */
function nera_iwt_is_theme_instant_wins_section_path( $path ) {
	if ( ! is_string( $path ) || '' === $path ) {
		return false;
	}
	$norm = wp_normalize_path( $path );
	if ( false !== strpos( $norm, 'nera-instant-win-threshold' ) ) {
		return false;
	}
	return (bool) preg_match( '#/template-parts/single-product/instant-wins-section\.php$#', $norm );
}

/**
 * @param string $_template_file Path passed to load_template().
 * @param bool   $load_once       load_once flag.
 * @param array  $args            Template args.
 */
function nera_iwt_wp_before_load_instant_wins_section( $_template_file, $load_once, $args ) {
	unset( $load_once );
	if ( ! nera_iwt_is_theme_instant_wins_section_path( $_template_file ) ) {
		return;
	}
	if ( ! isset( $GLOBALS['nera_iwt_iwt_buf_stack'] ) ) {
		$GLOBALS['nera_iwt_iwt_buf_stack'] = array();
	}
	$GLOBALS['nera_iwt_iwt_buf_stack'][] = wp_normalize_path( $_template_file );
	$GLOBALS['nera_iwt_iwt_buf_args'] = $args;
	ob_start();
}

/**
 * @param string $_template_file Path that was just loaded.
 * @param bool   $load_once       load_once flag.
 * @param array  $args            Template args.
 */
function nera_iwt_wp_after_load_instant_wins_section( $_template_file, $load_once, $args ) {
	unset( $load_once );
	if ( empty( $GLOBALS['nera_iwt_iwt_buf_stack'] ) || ! is_array( $GLOBALS['nera_iwt_iwt_buf_stack'] ) ) {
		return;
	}
	$expected = end( $GLOBALS['nera_iwt_iwt_buf_stack'] );
	if ( wp_normalize_path( (string) $_template_file ) !== $expected ) {
		return;
	}
	array_pop( $GLOBALS['nera_iwt_iwt_buf_stack'] );
	if ( ob_get_level() > 0 ) {
		ob_end_clean();
	}
	$tpl_args = array();
	if ( isset( $GLOBALS['nera_iwt_iwt_buf_args'] ) && is_array( $GLOBALS['nera_iwt_iwt_buf_args'] ) ) {
		$tpl_args = $GLOBALS['nera_iwt_iwt_buf_args'];
		unset( $GLOBALS['nera_iwt_iwt_buf_args'] );
	} elseif ( is_array( $args ) ) {
		$tpl_args = $args;
	}
	$plugin_tpl = NERA_IWT_PLUGIN_DIR . 'templates/instant-wins-section.php';
	if ( is_readable( $plugin_tpl ) ) {
		load_template( $plugin_tpl, false, $tpl_args );
	}
}

if ( function_exists( 'add_action' ) ) {
	// Since WP 6.1 — present on supported installs.
	add_action( 'wp_before_load_template', 'nera_iwt_wp_before_load_instant_wins_section', 0, 3 );
	add_action( 'wp_after_load_template', 'nera_iwt_wp_after_load_instant_wins_section', 0, 3 );
}
