<?php
/**
 * Plugin Name: Nera – Instant Win Rules
 * Plugin URI: https://github.com/Nera-Marketing/nera-instant-win-threshold
 * Description: Instant win rule types (instant, scheduled, ticket sold %), public prize visibility, and optional instant-win UI overrides for Lottery for WooCommerce.
 * Version: 1.0.27
 * Author: Nera
 * Text Domain: nera-instant-win-threshold
 * Requires at least: 6.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * Requires Plugins: lottery-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;

use YahnisElsts\PluginUpdateChecker\v5p5\Vcs\GitHubApi;

define( 'NERA_IWT_VERSION', '1.0.27' );
define( 'NERA_IWT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NERA_IWT_PLUGIN_FILE', __FILE__ );

/**
 * Upper bound of the ticket-number pool for shuffle and random lottery products
 * Fallback when a product has no Ticket Number Max meta: ticket numbers are drawn from  1 … NERA_IWT_MAX_TICKET_NUMBER.
 * Prefer setting Ticket Number Max on each lottery product (Ticket Generation Settings).
 * When 0 or unset in wp-config (after removing the default below): falls back to
 * _lty_maximum_tickets + count( nera_iwt_get_unavailable_prize_ticket_numbers() ).
 * Override in wp-config.php:  define( 'NERA_IWT_MAX_TICKET_NUMBER', 49999 );
 * Use 0 for LFW-style cap + unavailable offset:  define( 'NERA_IWT_MAX_TICKET_NUMBER', 0 );
 */
if ( ! defined( 'NERA_IWT_MAX_TICKET_NUMBER' ) ) {
	define( 'NERA_IWT_MAX_TICKET_NUMBER', 999 );
}

/**
 * When 1 (or true): “Schedule Prize” appears in the Rule type dropdown on instant-win rules.
 * When 0 (default): that option is hidden. Existing rules already set to Schedule Prize remain
 * editable until switched away; new rules cannot use the type while disabled.
 * Override: define( 'NERA_IWT_ENABLE_SCHEDULE_PRIZE_TYPE', 1 ); in wp-config.php.
 */
if ( ! defined( 'NERA_IWT_ENABLE_SCHEDULE_PRIZE_TYPE' ) ) {
	define( 'NERA_IWT_ENABLE_SCHEDULE_PRIZE_TYPE', 0 );
}

require_once NERA_IWT_PLUGIN_DIR . 'inc/upgrade-temp-backup-helper.php';

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
require_once NERA_IWT_PLUGIN_DIR . 'inc/admin-ticket-generation-rule-guard.php';
require_once NERA_IWT_PLUGIN_DIR . 'inc/admin-sequential-ticket-guard.php';
require_once NERA_IWT_PLUGIN_DIR . 'inc/admin-instant-win-ticket-range.php';
require_once NERA_IWT_PLUGIN_DIR . 'inc/admin-product-ticket-pool-max.php';
require_once NERA_IWT_PLUGIN_DIR . 'inc/admin-instant-win-export-import.php';
require_once NERA_IWT_PLUGIN_DIR . 'inc/visibility.php';
require_once NERA_IWT_PLUGIN_DIR . 'inc/rest-instant-wins.php';
require_once NERA_IWT_PLUGIN_DIR . 'inc/woocommerce-lottery-template-override.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once NERA_IWT_PLUGIN_DIR . 'inc/cli-instant-win-diagnostics.php';
}

// ---------------------------------------------------------------------------
// TICKET NUMBERS OVERRIDE — prize-hold mechanism (see inc/ticket-numbers-override.php)
// ---------------------------------------------------------------------------

require_once NERA_IWT_PLUGIN_DIR . 'inc/ticket-numbers-override.php';

// ---------------------------------------------------------------------------
// TICKET GENERATION OVERRIDE — mu-plugin shim management
//
// lty_get_random_ticket_numbers() and lty_get_remaining_shuffle_ticket_numbers()
// are pluggable functions in LFW (if !function_exists guards). Because
// lottery-for-woocommerce loads before this plugin alphabetically, we cannot
// redeclare them from a regular plugin file.
//
// Solution: manage a tiny mu-plugin shim that:
//   1. Defines NERA_IWT_MAX_TICKET_NUMBER before any plugin loads.
//   2. Requires inc/ticket-generation-override.php which declares the override
//      functions before LFW can.
//
// Activation hook creates the shim. Deactivation hook removes it.
// plugins_loaded @ 1 lazily recreates it if deleted manually.
// ---------------------------------------------------------------------------

/** Absolute path to the ticket-generation mu-plugin shim. */
define( 'NERA_IWT_GEN_MU_SHIM_PATH', WPMU_PLUGIN_DIR . '/nera-iwt-ticket-generation.php' );

/**
 * Build the content of the mu-plugin shim for ticket generation.
 *
 * @return string PHP file content.
 */
function nera_iwt_gen_mu_shim_content() {
	$override_file = wp_normalize_path( WP_PLUGIN_DIR . '/nera-instant-win-threshold/inc/ticket-generation-override.php' );
	$max           = NERA_IWT_MAX_TICKET_NUMBER;

	return "<?php\n"
		. "/**\n"
		. " * MU Plugin: Nera IWT — Ticket Generation Override Loader\n"
		. " *\n"
		. " * AUTO-GENERATED by nera-instant-win-threshold. Do not edit.\n"
		. " * Recreated on plugin activation; removed on deactivation.\n"
		. " *\n"
		. " * Declares lty_get_random_ticket_numbers() and\n"
		. " * lty_get_remaining_shuffle_ticket_numbers() before\n"
		. " * lottery-for-woocommerce can, so shuffle/random ticket numbers\n"
		. " * are drawn from the range  1 … NERA_IWT_MAX_TICKET_NUMBER.\n"
		. " */\n\n"
		. "defined( 'ABSPATH' ) || exit;\n\n"
		. "if ( ! defined( 'NERA_IWT_MAX_TICKET_NUMBER' ) ) {\n"
		. "\tdefine( 'NERA_IWT_MAX_TICKET_NUMBER', {$max} );\n"
		. "}\n\n"
		. "\$nera_iwt_gen_override = '{$override_file}';\n"
		. "if ( is_readable( \$nera_iwt_gen_override ) ) {\n"
		. "\trequire_once \$nera_iwt_gen_override;\n"
		. "}\n";
}

/**
 * Write the ticket-generation mu-plugin shim to disk.
 *
 * @return void
 */
function nera_iwt_write_gen_mu_shim() {
	if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {
		wp_mkdir_p( WPMU_PLUGIN_DIR );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( NERA_IWT_GEN_MU_SHIM_PATH, nera_iwt_gen_mu_shim_content() );
}

/**
 * Remove the ticket-generation mu-plugin shim from disk.
 *
 * @return void
 */
function nera_iwt_remove_gen_mu_shim() {
	if ( file_exists( NERA_IWT_GEN_MU_SHIM_PATH ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( NERA_IWT_GEN_MU_SHIM_PATH );
	}
}

register_activation_hook( NERA_IWT_PLUGIN_FILE, 'nera_iwt_write_gen_mu_shim' );
register_deactivation_hook( NERA_IWT_PLUGIN_FILE, 'nera_iwt_remove_gen_mu_shim' );

/**
 * Lazy-recreate / self-heal the shim.
 *
 * Recreates the shim when it is missing OR when it does not reference the current
 * install's override path. The latter matters because the shim stores an ABSOLUTE
 * path: cloning or restoring the site from another environment (e.g. production
 * `/srv/htdocs/...` copied onto a local `D:/laragon/...` install) leaves a stale
 * shim whose `is_readable()` check fails, so the override never loads and LFW's
 * native generator — which does NOT exclude locked instant-win prize numbers —
 * runs instead, letting locked prizes be assigned/won before their threshold.
 * Validating the path on every load makes the protection survive environment moves.
 *
 * @return void
 */
function nera_iwt_ensure_gen_mu_shim() {
	$expected = wp_normalize_path( WP_PLUGIN_DIR . '/nera-instant-win-threshold/inc/ticket-generation-override.php' );

	$needs_write = true;
	if ( file_exists( NERA_IWT_GEN_MU_SHIM_PATH ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = (string) file_get_contents( NERA_IWT_GEN_MU_SHIM_PATH );
		if ( '' !== $expected && false !== strpos( $contents, $expected ) ) {
			$needs_write = false;
		}
	}

	if ( $needs_write ) {
		nera_iwt_write_gen_mu_shim();
	}
}
add_action( 'plugins_loaded', 'nera_iwt_ensure_gen_mu_shim', 1 );

// ---------------------------------------------------------------------------
// PUBLIC — Instant Win section (inner template via theme-instant-wins-section-override.php)
// ---------------------------------------------------------------------------

/**
 * Front assets for plugin instant-win section inner template.
 *
 * CSS  — badge row height tweaks.
 * JS   — syncs instant-wins REST response stats into the collapsible header counts.
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

/**
 * Checkout loading overlay assets.
 *
 * Large lottery orders take a few seconds to generate ticket numbers during the
 * place-order AJAX request. These assets replace WooCommerce's default blockUI
 * spinner with branded green bouncing dots + a status message so the customer
 * knows the order is processing.
 *
 * @return void
 */
function nera_iwt_enqueue_checkout_loading_assets() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return;
	}

	$css_rel  = 'assets/checkout-loading.css';
	$css_path = NERA_IWT_PLUGIN_DIR . $css_rel;
	if ( is_readable( $css_path ) ) {
		wp_enqueue_style(
			'nera-iwt-checkout-loading',
			plugins_url( $css_rel, __FILE__ ),
			array(),
			(string) filemtime( $css_path )
		);
	}

	$js_rel  = 'assets/checkout-loading.js';
	$js_path = NERA_IWT_PLUGIN_DIR . $js_rel;
	if ( is_readable( $js_path ) ) {
		wp_enqueue_script(
			'nera-iwt-checkout-loading',
			plugins_url( $js_rel, __FILE__ ),
			array(),
			(string) filemtime( $js_path ),
			true
		);

		wp_localize_script(
			'nera-iwt-checkout-loading',
			'neraIwtCheckout',
			array(
				'message' => __( 'Processing your order&hellip; this can take a moment for large ticket quantities.', 'nera-instant-win-threshold' ),
			)
		);
	}
}
add_action( 'wp_enqueue_scripts', 'nera_iwt_enqueue_checkout_loading_assets', 20 );
