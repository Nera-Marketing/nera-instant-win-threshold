<?php
/**
 * Instant winners logs data (grouped prize cards).
 *
 * Visibility of rows is not filtered by {@see nera_iwt_posts_results_instant_winner_visibility()}
 * (storefront lists the full CMS prize pool).
 *
 * @package Nera_Instant_Win_Threshold
 *
 * @var array $instant_winner_ids Instant winner IDs.
 * @var object $product Product object.
 * @var array $columns Columns to display.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$prizes_grouped = array();
foreach ( $instant_winner_ids as $instant_winner_id ) {
	$instant_winner = lty_get_instant_winner_log( $instant_winner_id );
	if ( ! is_object( $instant_winner ) ) {
		continue;
	}

	$prize_message = $instant_winner->get_prize_message();
	$key           = md5( $prize_message );

	if ( ! isset( $prizes_grouped[ $key ] ) ) {
		$prizes_grouped[ $key ] = array(
			'prize_message' => $prize_message,
			'prize_image'   => $instant_winner->get_image(),
			'is_won'        => false,
			'total_count'   => 0,
			'won_count'     => 0,
			'winners'       => array(),
		);
	}

	$prizes_grouped[ $key ]['total_count']++;

	if ( $instant_winner->has_status( 'lty_won' ) ) {
		$prizes_grouped[ $key ]['is_won'] = true;
		$prizes_grouped[ $key ]['won_count']++;
		$prizes_grouped[ $key ]['winners'][] = array(
			'details'           => $instant_winner->get_instant_winner_details(),
			'ticket_number'     => $instant_winner->get_formatted_ticket_number(),
			'instant_winner_id' => $instant_winner_id,
		);
	}
}
?>

<div class="instant-wins-grid grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6" x-data="{ openId: null }">
	<?php
	$card_index = 0;
	foreach ( $prizes_grouped as $prize_key => $prize_data ) :
		$card_index++;
		$is_won             = $prize_data['is_won'];
		$prize_message      = $prize_data['prize_message'];
		$prize_image        = $prize_data['prize_image'];
		$winner_count       = $prize_data['won_count'];
		$total_count        = $prize_data['total_count'];
		$display_limit      = 4;
		$has_more           = $winner_count > $display_limit;
		$displayed_winners = array_slice( $prize_data['winners'], 0, $display_limit );
		?>

		<div class="instant-win-card bg-surface rounded-2xl shadow-card hover:shadow-card-hover transition-all duration-300 overflow-hidden <?php echo $is_won ? 'prize-won' : 'prize-available'; ?>">
			<div class="card-image-wrapper relative">
				<?php if ( $prize_image ) : ?>
					<div class="prize-image aspect-[4/3] w-full overflow-hidden bg-gray-100">
						<?php echo wp_kses_post( $prize_image ); ?>
					</div>
				<?php else : ?>
					<div class="prize-image aspect-[4/3] w-full overflow-hidden bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center">
						<span class="material-symbols-outlined text-gray-300 text-6xl">card_giftcard</span>
					</div>
				<?php endif; ?>

				<div class="coin-badge absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-20 h-20 rounded-full bg-gradient-to-br from-warning via-warning to-warning shadow-2xl flex items-center justify-center border-4 border-white">
					<div class="text-center">
						<span class="material-symbols-outlined text-white text-2xl mb-1 block">stars</span>
						<span class="text-white font-bold text-xs uppercase tracking-wide">Prize</span>
					</div>
				</div>
			</div>

			<?php if ( $is_won ) : ?>
				<?php $card_id = esc_js( 'card-' . $card_index ); ?>
				<button
					@click="openId = (openId === '<?php echo $card_id; ?>') ? null : '<?php echo $card_id; ?>'"
					class="prize-info w-full text-left p-5 border-t border-gray-100 hover:bg-gray-50 transition-colors duration-200"
				>
					<div class="flex items-start justify-between gap-3">
						<div class="flex-1 min-w-0">
							<h3 class="text-lg font-bold text-text-primary mb-2 line-clamp-2">
								<?php echo wp_kses_post( $prize_message ); ?>
							</h3>

							<div class="flex items-center gap-2 flex-wrap">
								<span class="inline-flex items-center gap-1.5 px-3 py-1 bg-info-bg text-info-text rounded-full text-xs font-semibold">
									<span class="material-symbols-outlined text-sm">check_circle</span>
									<?php echo (int) $winner_count; ?> Winner<?php echo 1 !== (int) $winner_count ? 's' : ''; ?>
								</span>
								<?php if ( $total_count > $winner_count ) : ?>
									<span class="text-xs text-text-secondary">
										<?php echo (int) ( $total_count - $winner_count ); ?>/<?php echo (int) $total_count; ?> To Be Won
									</span>
								<?php endif; ?>
							</div>
						</div>

						<div class="shrink-0 mt-1">
							<span
								class="material-symbols-outlined text-text-secondary transition-transform duration-200"
								:class="{ 'rotate-180': openId === '<?php echo $card_id; ?>' }"
							>
								expand_more
							</span>
						</div>
					</div>
				</button>

				<div
					x-show="openId === '<?php echo $card_id; ?>'"
					x-collapse
					class="winner-details border-t border-gray-100 bg-gray-50 p-5"
				>
					<div class="space-y-3">
						<?php foreach ( $displayed_winners as $winner ) : ?>
							<div class="p-3 bg-surface rounded-lg border border-gray-200">
								<p class="text-xs font-semibold text-text-secondary uppercase tracking-wide mb-1">
									Winner
								</p>
								<p class="text-sm font-medium text-text-primary">
									<?php echo wp_kses_post( $winner['details'] ); ?>
								</p>
								<?php if ( ! empty( $winner['ticket_number'] ) ) : ?>
									<p class="text-xs text-text-secondary mt-1">
										Ticket: <span class="font-mono font-semibold">#<?php echo esc_html( $winner['ticket_number'] ); ?></span>
									</p>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>

						<?php if ( $has_more ) : ?>
							<button
								class="w-full text-sm font-semibold text-primary hover:text-primary-dark transition-colors py-2 px-3 bg-surface rounded-lg border border-primary/20 hover:border-primary/40 hover:bg-primary/5 opacity-50 cursor-not-allowed"
								disabled
								title="Winners modal functionality has been migrated to React component"
							>
								See all winners → (Legacy - needs React integration)
							</button>
						<?php endif; ?>
					</div>
				</div>

			<?php else : ?>
				<div class="prize-info p-5 border-t border-gray-100">
					<div>
						<h3 class="text-lg font-bold text-text-primary mb-2 line-clamp-2">
							<?php echo wp_kses_post( $prize_message ); ?>
						</h3>

						<div class="flex items-center gap-2 flex-wrap">
							<span class="inline-flex items-center gap-1.5 px-3 py-1 bg-success-bg text-success-text rounded-full text-xs font-semibold">
								<span class="material-symbols-outlined text-sm">card_giftcard</span>
								<?php echo esc_html( lty_get_instant_winners_prize_available_label() ); ?>
							</span>
							<span class="text-xs text-text-secondary">
								<?php echo (int) $total_count; ?> Available
							</span>
						</div>
					</div>
				</div>
			<?php endif; ?>
		</div>

	<?php endforeach; ?>
</div>
