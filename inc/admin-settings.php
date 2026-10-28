<?php
/**
 * Admin: site-wide settings under WooCommerce → Settings → Products → Nera Instant Wins.
 *
 * Holds the on/off switch for the Held-back Prize rule type so it can be turned off from the UI
 * instead of wp-config.php. Off only stops NEW held-back rules; prizes already set to Held-back
 * keep their full lifecycle — see {@see nera_iwt_is_held_prize_type_enabled()}.
 *
 * When NERA_IWT_ENABLE_HELD_PRIZE_TYPE is defined in wp-config.php it wins: the checkbox shows the
 * constant's value, is greyed out, and is never saved (so removing the constant later falls back
 * to whatever the setting was before, not to a value silently written while locked).
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;

/** @var string Section id under WooCommerce → Settings → Products. */
const NERA_IWT_SETTINGS_SECTION = 'nera_iwt';

/**
 * Register the "Nera Instant Wins" section on the Products settings tab.
 *
 * @param array<string,string> $sections Section id => label.
 * @return array<string,string>
 */
function nera_iwt_settings_add_section( $sections ) {
	$sections[ NERA_IWT_SETTINGS_SECTION ] = __( 'Nera Instant Wins', 'nera-instant-win-threshold' );
	return $sections;
}
add_filter( 'woocommerce_get_sections_products', 'nera_iwt_settings_add_section' );

/**
 * Fields for the "Nera Instant Wins" section.
 *
 * @param array  $settings   Settings for the current section.
 * @param string $section_id Current section id.
 * @return array
 */
function nera_iwt_settings_fields( $settings, $section_id ) {
	if ( NERA_IWT_SETTINGS_SECTION !== $section_id ) {
		return $settings;
	}

	$held_field = array(
		'title'    => __( 'Held-back Prize', 'nera-instant-win-threshold' ),
		'desc'     => __( 'Allow the Held-back Prize rule type on instant-win prizes', 'nera-instant-win-threshold' ),
		'desc_tip' => __( 'When off, “Held-back Prize” and “Hold all in group” are hidden, and no prize can be switched to Held-back (including via CSV import). Prizes already set to Held-back keep working — Activate, auto-activation at the sold threshold, and the end-of-competition draw — until they are won or switched to another rule type.', 'nera-instant-win-threshold' ),
		'id'       => NERA_IWT_HELD_PRIZE_TYPE_OPTION,
		'type'     => 'checkbox',
		'default'  => 'yes',
	);

	if ( nera_iwt_is_held_prize_type_locked() ) {
		$held_field['disabled'] = true;
		$held_field['value']    = nera_iwt_is_held_prize_type_enabled() ? 'yes' : 'no';
		$held_field['desc_tip'] = sprintf(
			/* translators: %s: constant name */
			__( 'Locked by %s in wp-config.php. Remove that line to manage this setting here.', 'nera-instant-win-threshold' ),
			'<code>NERA_IWT_ENABLE_HELD_PRIZE_TYPE</code>'
		);
	}

	return array(
		array(
			'title' => __( 'Nera Instant Wins', 'nera-instant-win-threshold' ),
			'type'  => 'title',
			'id'    => 'nera_iwt_settings',
		),
		$held_field,
		array(
			'type' => 'sectionend',
			'id'   => 'nera_iwt_settings',
		),
	);
}
add_filter( 'woocommerce_get_settings_products', 'nera_iwt_settings_fields', 10, 2 );

/**
 * Skip saving the Held-back switch while wp-config.php pins it (the disabled checkbox posts
 * nothing, which WooCommerce would otherwise store as 'no').
 *
 * @param mixed $value Sanitized value.
 * @return mixed|null Null tells WooCommerce not to save.
 */
function nera_iwt_settings_keep_locked_held_option( $value ) {
	return nera_iwt_is_held_prize_type_locked() ? null : $value;
}
add_filter( 'woocommerce_admin_settings_sanitize_option_' . NERA_IWT_HELD_PRIZE_TYPE_OPTION, 'nera_iwt_settings_keep_locked_held_option' );
