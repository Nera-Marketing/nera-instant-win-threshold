<?php
/**
 * WordPress 6.3+ moves the current plugin/theme into wp-content/upgrade-temp-backup before unpacking.
 * On some Windows/Laragon setups rename/move fails; this pre-creates dirs and can skip that step when opted in.
 *
 * Enable skip via any one of: define( 'NERA_SKIP_UPGRADE_TEMP_BACKUP', true );
 * WP_ENVIRONMENT_TYPE=local; add_filter( 'nera_skip_upgrade_temp_backup', '__return_true' );
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nera_upgrade_temp_backup_helper_boot' ) ) {
	/**
	 * Register hooks once (theme + plugin may both load this file).
	 *
	 * @return void
	 */
	function nera_upgrade_temp_backup_helper_boot() {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		add_action(
			'admin_init',
			static function () {
				if ( ! is_admin() || wp_installing() ) {
					return;
				}
				wp_mkdir_p( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins' );
				wp_mkdir_p( WP_CONTENT_DIR . '/upgrade-temp-backup/themes' );
			},
			1
		);

		add_filter(
			'upgrader_package_options',
			static function ( $options ) {
				$skip = ( defined( 'NERA_SKIP_UPGRADE_TEMP_BACKUP' ) && NERA_SKIP_UPGRADE_TEMP_BACKUP )
					|| ( function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() )
					|| (bool) apply_filters( 'nera_skip_upgrade_temp_backup', false );

				if ( $skip && isset( $options['hook_extra']['temp_backup'] ) ) {
					unset( $options['hook_extra']['temp_backup'] );
				}

				return $options;
			},
			5
		);
	}
}

nera_upgrade_temp_backup_helper_boot();
