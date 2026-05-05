<?php
/**
 * Instant Wins Section — canonical template (toggle + #instant-wins-root for theme Vue bundle).
 *
 * Overrides the theme file `template-parts/single-product/instant-wins-section.php` at plugin level
 * via {@see nera_iwt_wp_before_load_instant_wins_section()} / {@see nera_iwt_wp_after_load_instant_wins_section()}
 * and via {@see 'nera_competitions_instant_win_prizes_section_html'} (see instant-win-prizes-below-hero.php).
 *
 * Header counts align with the REST payload after fetch via {@see nera_iwt_enqueue_public_assets()}
 * (plugin script syncs toggle badges from full CMS prize list).
 *
 * @package Nera_Instant_Win_Threshold
 *
 * @var array $args Must include key `product` (WC_Product), set by instant-win-prizes-below-hero.php or get_template_part().
 */

defined( 'ABSPATH' ) || exit;

$product = $args['product'] ?? null;

if ( ! $product ) {
	return;
}

$product_id = $product->get_id();

// Check if product has instant wins
$has_instant_wins = false;
$available_count  = 0;
$won_count          = 0;

if (
	function_exists( 'lty_is_lottery_product' ) &&
	lty_is_lottery_product( $product ) &&
	method_exists( $product, 'is_instant_winner' ) &&
	$product->is_instant_winner()
) {
	$has_instant_wins = true;

	$iwt_counts = function_exists( 'nera_iwt_get_public_instant_wins_section_counts' )
		? nera_iwt_get_public_instant_wins_section_counts( $product )
		: null;
	if ( is_array( $iwt_counts ) && isset( $iwt_counts['available'], $iwt_counts['won'], $iwt_counts['total'] ) ) {
		$available_count = absint( $iwt_counts['available'] );
		$won_count       = absint( $iwt_counts['won'] );
	} else {
		if ( method_exists( $product, 'get_instant_winner_available_prizes_count' ) ) {
			$available_count = absint( $product->get_instant_winner_available_prizes_count() );
		}
		if ( method_exists( $product, 'get_instant_winner_won_prizes_count' ) ) {
			$won_count = absint( $product->get_instant_winner_won_prizes_count() );
		}
	}
}

if ( ! $has_instant_wins ) {
	return;
}

$total_prizes = $available_count + $won_count;
?>

<!-- Instant Wins Section (Full Width Below Columns; width/padding from parent container) -->
<div class="instant-wins-section mt-10 w-full">

  <!-- Toggle Button — Premium Light Card (frontend-design: playful/premium) -->
  <button
    id="instant-wins-toggle-btn"
    onclick="window.toggleInstantWins()"
    class="instant-wins-toggle-shine w-full flex items-center justify-between gap-4 px-6 py-5 rounded-2xl transition-all duration-300 focus:outline-none focus:ring-2 focus:ring-warning focus:ring-offset-2 bg-gradient-to-br from-warning-bg via-warning-bg to-warning-bg/90 ring-1 ring-warning-border/60 shadow-[0_4px_14px_0_rgba(251,191,36,0.15)] active:scale-[0.98] animate-[instant-wins-button-glow_5s_ease-in-out_infinite]"
    aria-expanded="false"
    aria-controls="instant-wins-container"
  >
    <!-- Left: Icon + Text -->
    <div class="flex items-center gap-4">
      <!-- Star icon with glow ring -->
      <div class="relative">
        <div class="absolute inset-0 rounded-full bg-warning-border/50 blur-md"></div>
        <div class="relative w-14 h-14 rounded-full bg-gradient-to-br from-warning via-warning to-warning flex items-center justify-center shadow-[0_4px_14px_rgba(245,158,11,0.4)]">
          <span class="material-symbols-outlined text-white text-2xl">stars</span>
        </div>
      </div>

      <div class="text-left">
        <h3 class="text-lg font-bold text-gray-900 mb-1 tracking-tight">
          <?php esc_html_e( 'Instant Win Prizes', 'nera-competitions' ); ?>
        </h3>
        <p class="text-sm text-gray-600 mb-0" data-nera-iwt-prize-summary>
          <?php
          printf(
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pattern matches theme; string is translation + controlled span.
            _n( '%s prize available', '%s prizes available', $total_prizes, 'nera-competitions' ),
            '<span class="font-semibold text-warning" data-nera-iwt-total>' . esc_html( (string) $total_prizes ) . '</span>'
          );
          ?>
        </p>
      </div>
    </div>

    <!-- Right: Badges + Arrow -->
    <div class="flex items-center gap-3">
      <!-- Badge: Available & Won -->
      <div class="nera-iwt-prize-badges hidden sm:flex gap-2">
        <?php if ( $available_count > 0 ) : ?>
          <span data-nera-iwt-available-badge class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-surface/90 border border-warning-border/80 text-success rounded-full text-xs font-semibold shadow-sm">
            <span class="relative flex h-2 w-2">
              <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-success opacity-75"></span>
              <span class="relative inline-flex h-2 w-2 rounded-full bg-success"></span>
            </span>
            <span data-nera-iwt-available-count><?php echo esc_html( (string) $available_count ); ?></span> <?php esc_html_e( 'Available', 'nera-competitions' ); ?>
          </span>
        <?php endif; ?>

        <?php if ( $won_count > 0 ) : ?>
          <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-surface/90 border border-warning-border/80 text-warning rounded-full text-xs font-semibold shadow-sm">
            <span class="material-symbols-outlined text-sm">emoji_events</span>
            <?php echo esc_html( (string) $won_count ); ?> <?php esc_html_e( 'Won', 'nera-competitions' ); ?>
          </span>
        <?php endif; ?>
      </div>

      <!-- Expand/Collapse Arrow -->
      <span class="toggle-arrow material-symbols-outlined text-gray-600">
        expand_more
      </span>
    </div>
  </button>

  <!-- Collapsible Content Container -->
  <div
    id="instant-wins-container"
    class="instant-wins-content hidden overflow-hidden"
    aria-hidden="true"
  >
    <!-- React Mount Point -->
    <div
      id="instant-wins-root"
      data-product-id="<?php echo esc_attr( (string) $product_id ); ?>"
      class="mt-6"
    >
      <!-- Loading Skeleton (visible until React mounts) -->
      <div class="instant-wins-loading space-y-4">
        <!-- Stats skeleton -->
        <div class="rounded-2xl bg-gradient-to-br from-warning-bg to-warning-bg border-2 border-warning-border p-6">
          <div class="flex items-center justify-around gap-4">
            <div class="flex-1 space-y-3">
              <div class="h-3 w-20 rounded-lg bg-[linear-gradient(90deg,#f3f4f6_25%,#e5e7eb_50%,#f3f4f6_75%)] [background-size:200%_100%] animate-[instant-wins-skeleton-shimmer_1.5s_ease-in-out_infinite]"></div>
              <div class="h-8 w-16 rounded-lg bg-[linear-gradient(90deg,#f3f4f6_25%,#e5e7eb_50%,#f3f4f6_75%)] [background-size:200%_100%] animate-[instant-wins-skeleton-shimmer_1.5s_ease-in-out_infinite]"></div>
            </div>
            <div class="w-px h-12 bg-gray-200"></div>
            <div class="flex-1 space-y-3">
              <div class="h-3 w-20 rounded-lg bg-[linear-gradient(90deg,#f3f4f6_25%,#e5e7eb_50%,#f3f4f6_75%)] [background-size:200%_100%] animate-[instant-wins-skeleton-shimmer_1.5s_ease-in-out_infinite]"></div>
              <div class="h-8 w-16 rounded-lg bg-[linear-gradient(90deg,#f3f4f6_25%,#e5e7eb_50%,#f3f4f6_75%)] [background-size:200%_100%] animate-[instant-wins-skeleton-shimmer_1.5s_ease-in-out_infinite]"></div>
            </div>
          </div>
        </div>
        <!-- Card skeletons -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
          <?php for ( $i = 0; $i < 2; $i++ ) : ?>
            <div class="rounded-2xl bg-surface border border-gray-100 p-5">
              <div class="flex items-center gap-4">
                <div class="w-28 h-28 shrink-0 rounded-xl bg-[linear-gradient(90deg,#f3f4f6_25%,#e5e7eb_50%,#f3f4f6_75%)] [background-size:200%_100%] animate-[instant-wins-skeleton-shimmer_1.5s_ease-in-out_infinite]"></div>
                <div class="flex-1 space-y-3">
                  <div class="h-5 w-3/4 rounded-lg bg-[linear-gradient(90deg,#f3f4f6_25%,#e5e7eb_50%,#f3f4f6_75%)] [background-size:200%_100%] animate-[instant-wins-skeleton-shimmer_1.5s_ease-in-out_infinite]"></div>
                  <div class="h-4 w-1/2 rounded-lg bg-[linear-gradient(90deg,#f3f4f6_25%,#e5e7eb_50%,#f3f4f6_75%)] [background-size:200%_100%] animate-[instant-wins-skeleton-shimmer_1.5s_ease-in-out_infinite]"></div>
                  <div class="h-1.5 w-full rounded-full bg-[linear-gradient(90deg,#f3f4f6_25%,#e5e7eb_50%,#f3f4f6_75%)] [background-size:200%_100%] animate-[instant-wins-skeleton-shimmer_1.5s_ease-in-out_infinite]"></div>
                </div>
              </div>
            </div>
          <?php endfor; ?>
        </div>
      </div>
    </div>
  </div>
</div>
