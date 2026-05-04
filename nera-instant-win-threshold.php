<?php
/**
 * Plugin Name: Nera – Instant Win Rules
 * Plugin URI: https://github.com/Nera-Marketing/nera-instant-win-threshold
 * Description: Instant win rule types (instant, scheduled, ticket sold %), public prize visibility, and optional instant-win UI overrides for Lottery for WooCommerce.
 * Version: 1.0.9
 * Author: Nera
 * Text Domain: nera-instant-win-threshold
 * Requires at least: 6.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * Requires Plugins: lottery-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;

use YahnisElsts\PluginUpdateChecker\v5p5\Vcs\GitHubApi;

define( 'NERA_IWT_VERSION', '1.0.9' );
define( 'NERA_IWT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NERA_IWT_PLUGIN_FILE', __FILE__ );

/**
 * GitHub updates (Plugin Update Checker v5.5). On by default when `lib/plugin-update-checker/load-v5p5.php` exists.
 *
 * Disable only if the repo is missing or you are developing without GitHub:
 *   define( 'NERA_IWT_DISABLE_GITHUB_UPDATES', true );
 *
 * Private repo: `define( 'NERA_IWT_GITHUB_TOKEN', 'ghp_...' );`
 * Custom URL: `define( 'NERA_IWT_GITHUB_REPO_URL', 'https://github.com/Owner/repo/' );` or filter `nera_iwt_github_repo_url`.
 *
 * PUC reads the `Version` header in this file from the GitHub ref it selects (not the tag name alone). If the
 * remote header is older than the release, WordPress can show "up to date". Bump `Version` and `NERA_IWT_VERSION`
 * for every release, then tag/push to match.
 *
 * GitHub `GET /repos/.../releases/latest` returns 404 when there is no GitHub "latest" stable release. A custom
 * `setReleaseFilter` callback (always true) plus `maxReleases` > 1 makes `GitHubApi` use `GET .../releases?per_page=...`
 * instead of `/latest`. Releases must not be drafts; GitHub "pre-release" flags are skipped via `RELEASE_FILTER_SKIP_PRERELEASE`.
 *
 * The 4th argument to `PucFactory::buildUpdateChecker` is the check interval in hours (here: 6), not a release count.
 *
 * PUC loads the remote `readme.txt` only when a `readme.txt` is already present in the installed plugin (see
 * `readmeTxtExistsLocally()` in the library). Keep `readme.txt` in the package and bump `Stable tag` on each release
 * (`release.sh` syncs it with the release version). `plugin.json` is optional metadata for a *self-hosted* JSON
 * update URL; it is not used when the metadata URL is the GitHub repository (default).
 *
 * Plugin list / update modal icon: PUC uses `assets/icon-128x128.png`, `assets/icon-256x256.png`, or `assets/icon.svg`
 * (see WordPress.org plugin icon guidelines). This package mirrors `logo.png` into the 128/256 filenames for the
 * thumbnail on the Plugins and Dashboard → Updates screens.
 *
 * @link https://github.com/YahnisElsts/plugin-update-checker
 * @link https://github.com/Nera-Marketing/nera-instant-win-threshold/
 */
if ( ! defined( 'NERA_IWT_DISABLE_GITHUB_UPDATES' ) || ! NERA_IWT_DISABLE_GITHUB_UPDATES ) {
	$nera_iwt_github_repo_default = 'https://github.com/Nera-Marketing/nera-instant-win-threshold/';
	if ( defined( 'NERA_IWT_GITHUB_REPO_URL' ) && is_string( NERA_IWT_GITHUB_REPO_URL ) && NERA_IWT_GITHUB_REPO_URL !== '' ) {
		$nera_iwt_github_repo_default = NERA_IWT_GITHUB_REPO_URL;
	}
	$nera_iwt_github_repo = apply_filters( 'nera_iwt_github_repo_url', $nera_iwt_github_repo_default );

	$nera_iwt_puc_loader = NERA_IWT_PLUGIN_DIR . 'lib/plugin-update-checker/load-v5p5.php';
	if ( is_readable( $nera_iwt_puc_loader ) ) {
		require_once $nera_iwt_puc_loader;
		// Fourth argument: check period in hours (PUC default is 12).
		$nera_iwt_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			$nera_iwt_github_repo,
			__FILE__,
			'nera-instant-win-threshold',
			6
		);
		$nera_iwt_update_checker->setBranch( 'main' );

		if ( defined( 'NERA_IWT_GITHUB_TOKEN' ) && is_string( NERA_IWT_GITHUB_TOKEN ) && NERA_IWT_GITHUB_TOKEN !== '' ) {
			$nera_iwt_update_checker->setAuthentication( NERA_IWT_GITHUB_TOKEN );
		}

		$nera_iwt_puc_vcs = $nera_iwt_update_checker->getVcsApi();
		if ( $nera_iwt_puc_vcs instanceof GitHubApi ) {
			// Force paginated /releases (see docblock): custom filter + maxReleases > 1.
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
	}
}

/**
 * Core-style update notice on admin screens other than Plugins (there, WordPress prints the yellow inline row).
 *
 * @return void
 */
function nera_iwt_admin_notice_plugin_update() {
	if ( ! is_admin() || ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	global $pagenow;
	if ( isset( $pagenow ) && 'plugins.php' === $pagenow ) {
		return;
	}

	$file    = plugin_basename( NERA_IWT_PLUGIN_FILE );
	$current = get_site_transient( 'update_plugins' );
	if ( ! is_object( $current ) || empty( $current->response[ $file ] ) ) {
		return;
	}

	$response = $current->response[ $file ];
	if ( empty( $response->new_version ) ) {
		return;
	}

	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$plugin_data = get_plugin_data( NERA_IWT_PLUGIN_FILE, false, false );
	$plugin_name = wp_strip_all_tags( $plugin_data['Name'] );

	$plugin_slug = isset( $response->slug ) ? $response->slug : '';
	if ( $plugin_slug ) {
		$details_url = self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . rawurlencode( $plugin_slug ) . '&section=changelog' );
	} elseif ( ! empty( $response->url ) ) {
		$details_url = $response->url;
	} else {
		$details_url = ! empty( $plugin_data['PluginURI'] ) ? $plugin_data['PluginURI'] : '';
	}

	if ( $details_url ) {
		$details_url = add_query_arg(
			array(
				'TB_iframe' => 'true',
				'width'     => 600,
				'height'    => 800,
			),
			$details_url
		);
	}

	$new_version = $response->new_version;
	$update_url  = wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $file ) ), 'upgrade-plugin_' . $file );

	if ( ! empty( $response->package ) ) {
		$message = sprintf(
			/* translators: 1: Plugin name, 2: details URL, 3: link attributes, 4: new version, 5: update URL, 6: link attributes */
			__( 'There is a new version of %1$s available. <a href="%2$s" %3$s>View version %4$s details</a> or <a href="%5$s" %6$s>update now</a>.', 'nera-instant-win-threshold' ),
			'<strong>' . esc_html( $plugin_name ) . '</strong>',
			esc_url( $details_url ),
			'class="thickbox open-plugin-details-modal"',
			esc_html( $new_version ),
			esc_url( $update_url ),
			'class="update-link"'
		);
	} else {
		$message = sprintf(
			/* translators: 1: Plugin name, 2: details URL, 3: link attributes, 4: new version */
			__( 'There is a new version of %1$s available. <a href="%2$s" %3$s>View version %4$s details</a>. <em>Automatic update is unavailable for this plugin.</em>', 'nera-instant-win-threshold' ),
			'<strong>' . esc_html( $plugin_name ) . '</strong>',
			esc_url( $details_url ),
			'class="thickbox open-plugin-details-modal"',
			esc_html( $new_version )
		);
	}

	wp_admin_notice(
		wp_kses(
			$message,
			array(
				'strong' => array(),
				'em'     => array(),
				'a'      => array(
					'href'  => array(),
					'class' => array(),
				),
			)
		),
		array(
			'type'               => 'warning',
			'additional_classes' => array( 'update-nag', 'inline' ),
			'paragraph_wrap'     => true,
		)
	);
}
add_action( 'admin_notices', 'nera_iwt_admin_notice_plugin_update', 12 );

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
