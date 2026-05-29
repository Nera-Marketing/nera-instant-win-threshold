<?php
/**
 * Export / Import: include Nera rule-type fields in the LFW instant-winner-rules CSV.
 *
 * Export — hooks provided by LFW:
 *   lty_instant_winner_rules_export_heading  — append column headers.
 *   lty_instant_winner_rule_export_row_data  — append per-row values.
 *
 * Import — LFW's get_map_columns() has no filter, so nera columns are not
 * automatically parsed into $item during the normal flow. Instead we hook:
 *   lty_lottery_instant-winner-rule_imported (fires after every row is processed)
 * then re-read the CSV, extract the nera columns, find each rule by
 * product_id + ticket_number, and call nera_iwt_persist_rule_visibility_meta()
 * which also syncs meta to child log posts automatically.
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

// CSV column header strings — must stay identical between export and import.
define( 'NERA_IWT_CSV_COL_RULE_TYPE',  'Rule Type' );
define( 'NERA_IWT_CSV_COL_TICKET_PCT', 'Ticket %' );

// ─── Export ───────────────────────────────────────────────────────────────────

/**
 * Append Nera column headers to the instant-winner-rules CSV heading row.
 *
 * @param array $columns Existing LFW column map.
 * @return array
 */
function nera_iwt_export_add_headings( array $columns ) {
	$columns[ NERA_IWT_CSV_COL_RULE_TYPE ]  = NERA_IWT_CSV_COL_RULE_TYPE;
	$columns[ NERA_IWT_CSV_COL_TICKET_PCT ] = NERA_IWT_CSV_COL_TICKET_PCT;
	return $columns;
}
add_filter( 'lty_instant_winner_rules_export_heading', 'nera_iwt_export_add_headings' );

/**
 * Append Nera field values to each instant-winner-rule export row.
 *
 * Schedule fields are exported as local wall-clock strings (nera_iwt_schedule_at_local /
 * nera_iwt_schedule_end_local) so they round-trip correctly through
 * nera_iwt_persist_rule_visibility_meta() on import.
 *
 * @param array  $row                 LFW row data.
 * @param object $instant_winner_rule Instant winner rule object.
 * @return array
 */
function nera_iwt_export_add_row_data( array $row, $instant_winner_rule ) {
	$empty = array(
		NERA_IWT_CSV_COL_RULE_TYPE  => '',
		NERA_IWT_CSV_COL_TICKET_PCT => '',
	);

	if ( ! is_object( $instant_winner_rule ) || ! method_exists( $instant_winner_rule, 'get_id' ) ) {
		return array_merge( $row, $empty );
	}

	$rule_id = (int) $instant_winner_rule->get_id();
	$type    = (string) get_post_meta( $rule_id, 'nera_iwt_public_rule_type', true );

	if ( '' === $type || ! in_array( $type, nera_iwt_public_rule_type_slugs(), true ) ) {
		$type = NERA_IWT_RULE_TYPE_INSTANT;
	}

	$ticket_pct = NERA_IWT_RULE_TYPE_TICKET_PCT === $type
		? (string) max( 0, min( 100, (int) get_post_meta( $rule_id, 'nera_iwt_ticket_pct', true ) ) )
		: '';

	$row[ NERA_IWT_CSV_COL_RULE_TYPE ]  = $type;
	$row[ NERA_IWT_CSV_COL_TICKET_PCT ] = $ticket_pct;

	return $row;
}
add_filter( 'lty_instant_winner_rule_export_row_data', 'nera_iwt_export_add_row_data', 10, 2 );

// ─── Import ───────────────────────────────────────────────────────────────────

/**
 * After LFW finishes processing an instant-winner-rule import batch, re-read the
 * CSV and apply Nera rule-type meta to each rule matched by product_id + ticket_number.
 *
 * LFW's LTY_Instant_Winner_Rule_Importer::get_map_columns() has no filter hook,
 * so nera columns are not parsed into $item during the normal import flow.
 * This hook re-parses the same file to extract them, then calls
 * nera_iwt_persist_rule_visibility_meta() — which also syncs meta to child log posts.
 *
 * @param object $importer LTY_Instant_Winner_Rule_Importer instance.
 */
function nera_iwt_import_apply_nera_fields( $importer ) {
	if ( ! function_exists( 'nera_iwt_persist_rule_visibility_meta' ) ) {
		return;
	}

	// File path is passed via the ongoing AJAX request (same request that ran the import).
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$file = isset( $_REQUEST['file'] ) ? wc_clean( wp_unslash( $_REQUEST['file'] ) ) : '';
	if ( ! $file || ! file_exists( $file ) ) {
		return;
	}

	$product_id = method_exists( $importer, 'get_product_id' ) ? absint( $importer->get_product_id() ) : 0;
	if ( $product_id <= 0 ) {
		return;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	$handle = fopen( $file, 'r' );
	if ( false === $handle ) {
		return;
	}

	// Parse header row to locate each nera column by index.
	$headers = fgetcsv( $handle, 0, ',', '"', "\0" );
	if ( ! is_array( $headers ) ) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return;
	}
	$headers = array_map( 'trim', $headers );

	$col_ticket     = array_search( 'Ticket Number', $headers, true );
	$col_rule_type  = array_search( NERA_IWT_CSV_COL_RULE_TYPE, $headers, true );
	$col_ticket_pct = array_search( NERA_IWT_CSV_COL_TICKET_PCT, $headers, true );

	// No nera columns in this CSV — nothing to do.
	if ( false === $col_rule_type ) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return;
	}

	$rule_post_type = class_exists( 'LTY_Register_Post_Types' )
		? LTY_Register_Post_Types::LOTTERY_INSTANT_WINNER_RULE_POSTTYPE
		: 'lty_instant_winners';

	while ( false !== ( $csv_row = fgetcsv( $handle, 0, ',', '"', "\0" ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		if ( ! is_array( $csv_row ) ) {
			continue;
		}

		$ticket_number = ( false !== $col_ticket ) ? trim( (string) ( $csv_row[ $col_ticket ] ?? '' ) ) : '';
		if ( '' === $ticket_number ) {
			continue;
		}

		$rule_type  = ( false !== $col_rule_type )  ? trim( (string) ( $csv_row[ $col_rule_type ] ?? '' ) )  : NERA_IWT_RULE_TYPE_INSTANT;
		$ticket_pct = ( false !== $col_ticket_pct ) ? trim( (string) ( $csv_row[ $col_ticket_pct ] ?? '' ) ) : '0';

		// Default to instant if the value from the CSV is unrecognised.
		if ( ! in_array( $rule_type, nera_iwt_public_rule_type_slugs(), true ) ) {
			$rule_type = NERA_IWT_RULE_TYPE_INSTANT;
		}

		// Find the rule post by product + ticket number.
		$rule_ids = get_posts(
			array(
				'post_type'      => $rule_post_type,
				'post_parent'    => $product_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => 'lty_ticket_number',
				'meta_value'     => $ticket_number,
			)
		);

		if ( empty( $rule_ids ) ) {
			continue;
		}

		$rule_id = absint( $rule_ids[0] );
		if ( $rule_id <= 0 ) {
			continue;
		}

		nera_iwt_persist_rule_visibility_meta(
			$rule_id,
			array(
				'nera_public_rule_type' => $rule_type,
				'nera_ticket_pct'       => $ticket_pct,
			)
		);
	}

	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
}
add_action( 'lty_lottery_instant-winner-rule_imported', 'nera_iwt_import_apply_nera_fields' );
