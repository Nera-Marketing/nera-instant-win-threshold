<?php
/**
 * MU Plugin loader: copy to wp-content/mu-plugins/nera-iwt-fix-ticket-19984.php
 *
 * Exposes admin-post.php?action=nera_iwt_fix_ticket_19984 for the one-off
 * remediation in scripts/fix-ticket-19984.php (dry-run by default).
 *
 * If fix-ticket-19984.php is not on this site, the loader does nothing (no
 * fatal error, no admin notice). Deploy the script only on installs that need
 * the ticket-19984 remediation.
 *
 * Delete this mu-plugin after the fix has been applied.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

$nera_iwt_fix_script = WP_PLUGIN_DIR . '/nera-instant-win-threshold/scripts/fix-ticket-19984.php';

if ( ! is_readable( $nera_iwt_fix_script ) ) {
	return;
}

require_once $nera_iwt_fix_script;

if ( ! function_exists( 'nera_iwt_run_fix_ticket_19984' ) ) {
	return;
}

add_action(
	'admin_post_nera_iwt_fix_ticket_19984',
	static function () {

		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'nera-instant-win-threshold' ), 'Forbidden', array( 'response' => 403 ) );
		}

		$nonce_action = 'nera_iwt_fix_ticket_19984';
		$want_apply   = isset( $_GET['apply'] ) && '1' === (string) $_GET['apply'];

		if ( $want_apply ) {
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
				wp_die(
					esc_html__( 'Apply mode requires a valid _wpnonce. Visit the dry-run URL first; the apply link is printed at the bottom of its output.', 'nera-instant-win-threshold' ),
					'Forbidden',
					array( 'response' => 403 )
				);
			}
		}

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		$rc = nera_iwt_run_fix_ticket_19984( $want_apply, false );

		if ( ! $want_apply && 0 === $rc ) {
			$apply_url = add_query_arg(
				array(
					'action'   => $nonce_action,
					'apply'    => '1',
					'_wpnonce' => wp_create_nonce( $nonce_action ),
				),
				admin_url( 'admin-post.php' )
			);
			echo "To commit the changes shown above, open this URL while still logged in as administrator:\n";
			echo '  ' . esc_url_raw( $apply_url ) . "\n";
		}

		exit( $rc );
	}
);
