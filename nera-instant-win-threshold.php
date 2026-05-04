<?php
/**
 * Plugin Name: Nera – Instant Win Rules
 * Description: Instant win rule types (instant, scheduled, ticket sold %), public prize visibility, and optional instant-win UI overrides for Lottery for WooCommerce.
 * Version: 1.0.1
 * Requires Plugins: lottery-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'NERA_IWT_VERSION', '1.0.1' );
define( 'NERA_IWT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NERA_IWT_PLUGIN_FILE', __FILE__ );

/**
 * GitHub updates (Plugin Update Checker). On by default when the lib is present.
 *
 * Disable only if the repo is missing or you are developing without GitHub:
 *   define( 'NERA_IWT_DISABLE_GITHUB_UPDATES', true );
 *
 * Private repo: `define( 'NERA_IWT_GITHUB_TOKEN', 'ghp_...' );`
 * Custom URL: `define( 'NERA_IWT_GITHUB_REPO_URL', 'https://github.com/Owner/repo/' );` or filter `nera_iwt_github_repo_url`.
 *
 * Plugin Update Checker uses the `Version` header inside this file on the GitHub tag/branch (not only
 * the release tag name). If the tag says 1.0.1 but this header is still 1.0.0, WordPress reports "up to date".
 * Bump `Version` and NERA_IWT_VERSION in this file for every release, then publish/tag to match.
 *
 * GitHub's `/releases/latest` returns 404 when there is no qualifying "latest" stable release. We set
 * a release filter with maxReleases>1 so PUC uses the `/releases` list instead of `/latest`. For updates
 * to appear, the release must not be a GitHub draft and should not be marked "pre-release" (unless you change this code).
 *
 * @link https://github.com/Nera-Marketing/nera-instant-win-threshold/
 */
if ( ! defined( 'NERA_IWT_DISABLE_GITHUB_UPDATES' ) || ! NERA_IWT_DISABLE_GITHUB_UPDATES ) {
	$nera_iwt_github_repo_default = 'https://github.com/Nera-Marketing/nera-instant-win-threshold/';
	if ( defined( 'NERA_IWT_GITHUB_REPO_URL' ) && is_string( NERA_IWT_GITHUB_REPO_URL ) && NERA_IWT_GITHUB_REPO_URL !== '' ) {
		$nera_iwt_github_repo_default = NERA_IWT_GITHUB_REPO_URL;
	}
	$nera_iwt_github_repo = apply_filters( 'nera_iwt_github_repo_url', $nera_iwt_github_repo_default );

	require_once NERA_IWT_PLUGIN_DIR . 'lib/plugin-update-checker/load-v5p5.php';
	$nera_iwt_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		$nera_iwt_github_repo,
		__FILE__,
		'nera-instant-win-threshold'
	);
	$nera_iwt_update_checker->setBranch( 'main' );

	if ( defined( 'NERA_IWT_GITHUB_TOKEN' ) && is_string( NERA_IWT_GITHUB_TOKEN ) && NERA_IWT_GITHUB_TOKEN !== '' ) {
		$nera_iwt_update_checker->setAuthentication( NERA_IWT_GITHUB_TOKEN );
	}

	$nera_iwt_puc_vcs = $nera_iwt_update_checker->getVcsApi();
	// Avoid relying on GET /releases/latest (404 when GitHub has no "latest" stable release).
	$nera_iwt_puc_vcs->setReleaseFilter(
		static function ( $version_number, $release_object ) {
			unset( $version_number, $release_object );
			return true;
		},
		\YahnisElsts\PluginUpdateChecker\v5p5\Vcs\Api::RELEASE_FILTER_SKIP_PRERELEASE,
		20
	);
	$nera_iwt_puc_vcs->enableReleaseAssets();
}

/** Lottery for WooCommerce main file (plugin slug / folder). */
const NERA_IWT_LFW_PLUGIN_FILE = 'lottery-for-woocommerce/lottery-for-woocommerce.php';

/**
 * Whether Lottery for WooCommerce is active (required for all hooks in this plugin).
 *
 * @return bool
 */
function nera_iwt_is_lfw_active() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	return is_plugin_active( NERA_IWT_LFW_PLUGIN_FILE );
}

if ( ! nera_iwt_is_lfw_active() ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			$path = WP_PLUGIN_DIR . '/' . NERA_IWT_LFW_PLUGIN_FILE;

			if ( file_exists( $path ) ) {
				$url = wp_nonce_url(
					admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( NERA_IWT_LFW_PLUGIN_FILE ) ),
					'activate-plugin_' . NERA_IWT_LFW_PLUGIN_FILE
				);
				$message = sprintf(
					/* translators: %s: activation URL for Lottery for WooCommerce */
					__( '<strong>Nera – Instant Win Rules</strong> requires <strong>Lottery for WooCommerce</strong> (Giveaway for WooCommerce). <a href="%s">Activate it now</a>.', 'nera-instant-win-threshold' ),
					esc_url( $url )
				);
			} else {
				$message = __( '<strong>Nera – Instant Win Rules</strong> requires the <strong>Lottery for WooCommerce</strong> plugin. Install it in <code>wp-content/plugins/lottery-for-woocommerce</code> and activate it.', 'nera-instant-win-threshold' );
			}

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				wp_kses_post( $message )
			);
		}
	);

	return;
}

require_once NERA_IWT_PLUGIN_DIR . 'inc/cache.php';
require_once NERA_IWT_PLUGIN_DIR . 'inc/theme-instant-wins-section-override.php';
require_once NERA_IWT_PLUGIN_DIR . 'inc/rule-public-display.php';
require_once NERA_IWT_PLUGIN_DIR . 'inc/visibility.php';
require_once NERA_IWT_PLUGIN_DIR . 'inc/rest-instant-wins.php';
require_once NERA_IWT_PLUGIN_DIR . 'inc/woocommerce-lottery-template-override.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once NERA_IWT_PLUGIN_DIR . 'inc/cli-instant-win-diagnostics.php';
}

// ---------------------------------------------------------------------------
// PUBLIC — Instant Win section below hero (theme filter override)
// ---------------------------------------------------------------------------

/**
 * Replace theme default instant-win block with plugin-maintained templates.
 *
 * @param string|null $html    Prior value (always null from theme).
 * @param WC_Product  $product Current product.
 * @return string|null Full section HTML, or null if templates missing (theme fallback).
 */
function nera_iwt_filter_instant_win_prizes_section( $html, $product ) {
	if ( ! $product instanceof WC_Product ) {
		return $html;
	}

	$path = NERA_IWT_PLUGIN_DIR . 'templates/instant-win-prizes-below-hero.php';
	if ( ! is_readable( $path ) ) {
		return $html;
	}

	ob_start();
	// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- plugin template path.
	include $path;
	return ob_get_clean();
}

add_filter( 'nera_competitions_instant_win_prizes_section_html', 'nera_iwt_filter_instant_win_prizes_section', 10, 2 );

/**
 * Front assets for plugin-rendered instant-win section.
 *
 * CSS  — badge row height tweaks.
 * JS   — client-side schedule filter (patches window.fetch to compare
 *         prize schedule_at against the visitor's browser local time).
 *         Loaded in <head> so the patch is in place before Vue's onMounted
 *         fetch call runs.
 */
function nera_iwt_enqueue_public_assets() {
	if ( ! is_singular( 'product' ) ) {
		return;
	}

	$css_rel  = 'assets/public-instant-win.css';
	$css_path = NERA_IWT_PLUGIN_DIR . $css_rel;
	if ( is_readable( $css_path ) ) {
		wp_enqueue_style(
			'nera-iwt-public',
			plugins_url( $css_rel, __FILE__ ),
			array(),
			(string) filemtime( $css_path )
		);
	}

	$js_rel  = 'assets/instant-wins-client-schedule.js';
	$js_path = NERA_IWT_PLUGIN_DIR . $js_rel;
	if ( is_readable( $js_path ) ) {
		wp_enqueue_script(
			'nera-iwt-client-schedule',
			plugins_url( $js_rel, __FILE__ ),
			array(),
			(string) filemtime( $js_path ),
			false // Load in <head> — must run before Vue's onMounted fetch.
		);

		wp_localize_script(
			'nera-iwt-client-schedule',
			'neraIwtClient',
			array(
				'restBase' => esc_url_raw( rest_url( 'nera/v1/instant-wins/' ) ),
			)
		);
	}
}
add_action( 'wp_enqueue_scripts', 'nera_iwt_enqueue_public_assets', 20 );
