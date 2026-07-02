<?php
/**
 * Instant Win prizes section below hero grid (public) — plugin copy for overrides.
 *
 * Markup mirrors {@see nera_competitions_render_instant_win_prizes_section()} default branch.
 *
 * @package Nera_Instant_Win_Threshold
 *
 * @var WC_Product $product Product in scope from the filter callback.
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $product ) || ! $product instanceof WC_Product ) {
	return;
}

$args = array( 'product' => $product );
?>
    <!-- Instant Win prizes: own section below hero grid (avoids WC flex/float fighting inner flex-col) -->
    <section class="pb-8 lg:pb-10" aria-label="<?php esc_attr_e( 'Instant win prizes', 'nera-competitions' ); ?>">
      <div class="max-w-7xl mx-auto w-full min-w-0 px-4 lg:px-8">
        <?php
        $nera_iwt_inner = NERA_IWT_PLUGIN_DIR . 'templates/instant-wins-section.php';
        if ( is_readable( $nera_iwt_inner ) ) {
          // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- path is built from constant + fixed filename.
          include $nera_iwt_inner;
        }
        ?>
      </div>
    </section>
