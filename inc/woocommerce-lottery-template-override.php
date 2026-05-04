<?php
/**
 * Card layout for LFW instant winners tab: only when no theme override exists.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

/**
 * Template names supplied by this plugin when the storefront uses LFW defaults.
 *
 * @return string[]
 */
function nera_iwt_lottery_instant_winners_template_names() {
	return array(
		'single-product/tabs/instant-winners-logs-data.php',
		'single-product/tabs/instant-winners-logs.php',
		'single-product/tabs/instant-winners-logs-layout.php',
	);
}

/**
 * Point WooCommerce/LFW at plugin templates only if the resolved file is not from a theme.
 *
 * Child or parent theme overrides in `lottery-for-woocommerce/` keep priority.
 *
 * @param string $template      Located template path.
 * @param string $template_name Relative template name.
 * @param string $template_path WC template path (e.g. lottery-for-woocommerce/).
 * @param string $default_path  Plugin default templates directory (LFW).
 * @return string
 */
function nera_iwt_woocommerce_locate_lottery_instant_winners_templates( $template, $template_name, $template_path, $default_path = '' ) {
	if ( ! in_array( $template_name, nera_iwt_lottery_instant_winners_template_names(), true ) ) {
		return $template;
	}

	if ( stripos( (string) $template_path, 'lottery-for-woocommerce' ) === false ) {
		return $template;
	}

	$normalized  = wp_normalize_path( (string) $template );
	$themes_root = wp_normalize_path( trailingslashit( get_theme_root() ) );

	if ( strpos( $normalized, $themes_root ) === 0 ) {
		return $template;
	}

	$plugin_tpl = NERA_IWT_PLUGIN_DIR . 'templates/lottery-for-woocommerce/' . $template_name;

	if ( is_readable( $plugin_tpl ) ) {
		return $plugin_tpl;
	}

	return $template;
}

add_filter( 'woocommerce_locate_template', 'nera_iwt_woocommerce_locate_lottery_instant_winners_templates', 10, 4 );
