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
 *   lty_lottery_instant-winner-rule_imported (fires once after each import batch)
 * then re-read that batch's rows from the CSV, extract the nera columns, find each
 * rule by product_id + ticket_number, and call nera_iwt_persist_rule_visibility_meta()
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
 * Only Rule Type and Ticket % are exported. Schedule datetimes
 * (nera_iwt_schedule_at_local / nera_iwt_schedule_end_local) are intentionally NOT
 * included, so a Schedule-type rule does not round-trip through import — it imports as a
 * schedule rule with no dates (treated as ungated). Schedule Prize is disabled by default
 * (NERA_IWT_ENABLE_SCHEDULE_PRIZE_TYPE), so this is a known, accepted limitation.
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

	// Process ONLY the current import batch's rows.
	//
	// LFW imports in chunks (default 100 rows per `lty_run_import` request) and fires
	// `lty_lottery_instant-winner-rule_imported` once at the END of each batch. `position` in the
	// request is the byte offset where this batch started reading; the importer's
	// get_position_count() is where it stopped. Bounding the re-read to [start, end) keeps the whole
	// import O(N) instead of re-reading the entire file (O(N^2)) on every batch. Every rule in the
	// batch already exists here because run_import() processes the batch before firing the hook.
	// Fallback: when the end offset is unavailable, read to EOF (original behaviour).
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$batch_start = isset( $_REQUEST['position'] ) ? max( 0, intval( wp_unslash( $_REQUEST['position'] ) ) ) : 0;
	$batch_end   = method_exists( $importer, 'get_position_count' ) ? (int) $importer->get_position_count() : 0;
	$bounded     = $batch_end > 0;

	// First batch (start 0): handle is already just past the header. Later batches start mid-file.
	// Unbounded fallback: do not seek — read from after the header to EOF (original behaviour).
	if ( $bounded && $batch_start > 0 ) {
		fseek( $handle, $batch_start );
	}

	while ( true ) {
		if ( $bounded && ftell( $handle ) >= $batch_end ) {
			break;
		}

		$csv_row = fgetcsv( $handle, 0, ',', '"', "\0" );
		if ( false === $csv_row ) {
			break; // EOF or read error.
		}
		if ( ! is_array( $csv_row ) ) {
			continue; // Defensive: skip a malformed row without ending the batch.
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

// ─── Cross-product copy + import range guard ────────────────────────────────────

/**
 * Resolve the launch (target) lottery product ID for the current import request.
 *
 * LFW posts the `extra_data` JSON (containing `product_id`) on BOTH the
 * `lty_upload_import_form` and `lty_run_import` AJAX requests.
 *
 * @return int Product ID, or 0 when not resolvable.
 */
function nera_iwt_get_import_launch_product_id() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$raw = isset( $_REQUEST['extra_data'] ) ? wp_unslash( $_REQUEST['extra_data'] ) : '';
	if ( '' === $raw || ! is_string( $raw ) ) {
		return 0;
	}

	$data = json_decode( $raw, true );

	return ( is_array( $data ) && isset( $data['product_id'] ) ) ? absint( $data['product_id'] ) : 0;
}

/**
 * RFC-4180 row encoder: quote a field only when it contains " , CR or LF; double embedded quotes.
 *
 * Used instead of fputcsv() because fputcsv()'s escape handling with escape="\0" is
 * version-dependent; this matches how LFW re-reads the file (enclosure '"', escape "\0").
 *
 * @param array $fields Row fields.
 * @return string Encoded CSV line (no trailing newline).
 */
function nera_iwt_csv_encode_row( array $fields ) {
	$out = array();
	foreach ( $fields as $f ) {
		$f = (string) $f;
		if ( preg_match( '/["\n\r,]/', $f ) ) {
			$f = '"' . str_replace( '"', '""', $f ) . '"';
		}
		$out[] = $f;
	}

	return implode( ',', $out );
}

/**
 * Rewrite the uploaded import CSV so rows referencing a rule that does NOT belong to the
 * target product are created fresh under the target (rather than silently updating — and
 * corrupting — the source product's rules).
 *
 * For each data row whose `ID` references a rule whose post_parent is not the target product,
 * blank the `ID` cell (forces LFW's CREATE-under-target branch) and rewrite the `Product ID`
 * cell to the target. Same-product re-import (ID belongs to target) is left untouched, so the
 * intended ID-based UPDATE flow still works.
 *
 * @param string $file              Absolute path to the uploaded CSV.
 * @param int    $target_product_id Launch product ID.
 * @return void
 */
function nera_iwt_blank_foreign_ids_in_import_csv( $file, $target_product_id ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	$handle = fopen( $file, 'r' );
	if ( false === $handle ) {
		return;
	}

	$headers = fgetcsv( $handle, 0, ',', '"', "\0" );
	if ( ! is_array( $headers ) ) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return;
	}

	// Column detection only: trim + strip the UTF-8 BOM that prefixes headers[0]
	// (the exporter writes a BOM; LFW strips it in read_file()). 'ID' is the FIRST
	// exported column, so without this strip array_search( 'ID' ) would miss it.
	$detect = array_map( 'trim', $headers );
	if ( isset( $detect[0] ) ) {
		$detect[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $detect[0] );
	}

	$col_id  = array_search( 'ID', $detect, true );
	$col_pid = array_search( 'Product ID', $detect, true );
	if ( false === $col_id ) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return; // Not our CSV shape — leave it alone.
	}

	$rows   = array();
	$rows[] = $headers; // Header row written back verbatim (preserves BOM).

	while ( false !== ( $r = fgetcsv( $handle, 0, ',', '"', "\0" ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		if ( is_array( $r ) ) {
			$rid = isset( $r[ $col_id ] ) ? absint( $r[ $col_id ] ) : 0;
			if ( $rid > 0 && (int) wp_get_post_parent_id( $rid ) !== (int) $target_product_id ) {
				$r[ $col_id ] = ''; // Force CREATE under the target product.
				if ( false !== $col_pid ) {
					$r[ $col_pid ] = (string) $target_product_id;
				}
			}
		}
		$rows[] = $r;
	}

	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	$out = fopen( $file, 'w' );
	if ( false === $out ) {
		return;
	}
	foreach ( $rows as $row ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $out, nera_iwt_csv_encode_row( (array) $row ) . "\n" );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
}

/**
 * On upload of an instant-winner-rule import CSV, rewrite it for safe cross-product copy.
 *
 * Hooks WordPress core `add_attachment`, which fires inside LFW's handle_upload() during the
 * `lty_upload_import_form` AJAX request — after LFW has already verified the import nonce and
 * capability. Fires once per upload; the rewritten file is what every later run_import() batch
 * re-reads.
 *
 * @param int $attachment_id Newly inserted attachment ID.
 * @return void
 */
function nera_iwt_rewrite_import_csv_for_cross_product( $attachment_id ) {
	if ( ! wp_doing_ajax() ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$action      = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
	$action_type = isset( $_POST['action_type'] ) ? sanitize_key( wp_unslash( $_POST['action_type'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	if ( 'lty_upload_import_form' !== $action || 'instant-winner-rule' !== $action_type ) {
		return;
	}

	// Defensive re-check (LFW already verified these upstream in upload_import_form()).
	if ( ! check_ajax_referer( 'lty-import', 'lty_security', false ) || ! current_user_can( 'import' ) ) {
		return;
	}

	$target = nera_iwt_get_import_launch_product_id();
	if ( $target <= 0 ) {
		return;
	}

	$file = get_attached_file( $attachment_id );
	if ( ! $file || ! is_readable( $file ) || ! is_writable( $file ) ) {
		return;
	}

	nera_iwt_blank_foreign_ids_in_import_csv( $file, $target );
}
add_action( 'add_attachment', 'nera_iwt_rewrite_import_csv_for_cross_product' );

/**
 * Enforce the numeric ticket-pool range on import rows.
 *
 * LFW's importer does not range-check ticket numbers (that guard otherwise only runs on the
 * inline Add/Save AJAX flows). This filter fires in validate_item() for all display modes;
 * returning a WP_Error marks the row failed with a message and the rest of the batch continues.
 * Only all-digit ticket strings are range-checked (prefixed/suffixed values are skipped).
 *
 * @param mixed $is_invalid Current validation result (false = valid, WP_Error/string = invalid).
 * @param array $item       Parsed import row.
 * @return mixed
 */
function nera_iwt_import_validate_ticket_range( $is_invalid, $item ) {
	if ( ! empty( $is_invalid ) ) {
		return $is_invalid; // Already failing — preserve it.
	}

	if ( ! function_exists( 'nera_iwt_validate_instant_win_ticket_number_range' ) ) {
		return $is_invalid;
	}

	$target = nera_iwt_get_import_launch_product_id();
	if ( $target <= 0 ) {
		return $is_invalid;
	}

	$product = wc_get_product( $target );
	if ( ! $product ) {
		return $is_invalid;
	}

	$ticket = isset( $item['ticket_number'] ) ? $item['ticket_number'] : '';
	$result = nera_iwt_validate_instant_win_ticket_number_range( $product, $ticket );

	return is_wp_error( $result ) ? $result : $is_invalid;
}
add_filter( 'lty_validate_instant_winner_rule_import_item', 'nera_iwt_import_validate_ticket_range', 10, 2 );

/**
 * Resolve the display mode the importer will use for the current import request.
 *
 * Mirrors LTY_Instant_Winner_Rule_Importer::get_display_mode(): the importer branches on the
 * `display_mode` carried in the request `extra_data` JSON (set by the import button from the
 * product's saved mode), NOT the live product meta. We read the same source so the mismatch guard
 * predicts the importer's actual branch even if the product meta momentarily diverges. Defaults to
 * '1' (Default) when absent — same default as the importer.
 *
 * @return string '1' (Default) or '2' (Group).
 */
function nera_iwt_get_import_launch_display_mode() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$raw = isset( $_REQUEST['extra_data'] ) ? wp_unslash( $_REQUEST['extra_data'] ) : '';
	if ( '' === $raw || ! is_string( $raw ) ) {
		return '1';
	}

	$data = json_decode( $raw, true );

	return ( is_array( $data ) && isset( $data['display_mode'] ) ) ? (string) $data['display_mode'] : '1';
}

/**
 * Block a mode-mismatched import row: a group-mode CSV (row carries a Group Prize Title) imported
 * into a product whose Instant Win Prize Display Mode is Default ('1').
 *
 * Without this guard LFW's Default-mode branch silently flattens the row — it copies the CSV prize
 * fields straight onto the rule and never sets `lty_prize_group_id`, so the prize-group association
 * is lost with no error shown. Returning a WP_Error fails the row with a clear, actionable message
 * in the import error log instead.
 *
 * The reverse direction (Default/flat CSV into a Group-mode product) is already non-silent: LFW's
 * own validate_item() fails each such row with "Group Prize Title is empty" before this filter runs.
 *
 * Deliberate flatten is still possible via:
 *   add_filter( 'nera_iwt_allow_import_mode_mismatch', '__return_true' );
 *
 * @param mixed $is_invalid Current validation result (false = valid, WP_Error/string = invalid).
 * @param array $item       Parsed import row.
 * @return mixed
 */
function nera_iwt_import_guard_mode_mismatch( $is_invalid, $item ) {
	if ( ! empty( $is_invalid ) ) {
		return $is_invalid; // Already failing — preserve it.
	}

	$target = nera_iwt_get_import_launch_product_id();
	if ( $target <= 0 ) {
		return $is_invalid;
	}

	$product = wc_get_product( $target );
	if ( ! $product ) {
		return $is_invalid;
	}

	// Use the same display-mode source the importer branches on (request extra_data), not product meta.
	$target_mode  = nera_iwt_get_import_launch_display_mode();
	$row_is_group = ! empty( $item['prize_group_title'] );

	// Only the dangerous direction: group-mode CSV row → Default-mode product.
	if ( '1' !== $target_mode || ! $row_is_group ) {
		return $is_invalid;
	}

	/**
	 * Allow a group-mode CSV row to be imported (flattened) into a Default-mode product.
	 *
	 * @since 1.0.29
	 * @param bool       $allow   Whether to bypass the mode-mismatch guard. Default false.
	 * @param array      $item    Parsed import row.
	 * @param WC_Product $product Target product.
	 */
	if ( apply_filters( 'nera_iwt_allow_import_mode_mismatch', false, $item, $product ) ) {
		return $is_invalid;
	}

	return new WP_Error(
		'nera_iwt_import_mode_mismatch',
		sprintf(
			/* translators: %s: prize group title from the CSV row. */
			__( 'This CSV is a group-mode export (row prize group: "%s") but the target product\'s Instant Win Prize Display Mode is Default. Set the product to "Display Prizes by Group" on the Instant Win Prizes tab and save before importing, or import a Default-mode CSV.', 'nera-instant-win-threshold' ),
			sanitize_text_field( (string) $item['prize_group_title'] )
		)
	);
}
add_filter( 'lty_validate_instant_winner_rule_import_item', 'nera_iwt_import_guard_mode_mismatch', 10, 2 );
