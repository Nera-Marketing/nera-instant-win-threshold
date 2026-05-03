<?php
/**
 * Instant winners logs container (card grid + pagination).
 *
 * @package Nera_Instant_Win_Threshold
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="instant-winners-logs-container">
	<?php
	lty_get_template(
		'single-product/tabs/instant-winners-logs-data.php',
		array(
			'instant_winner_ids' => $post_ids,
			'product'            => $product,
			'columns'            => $columns,
		)
	);
	?>

	<?php if ( isset( $pagination['page_count'] ) && $pagination['page_count'] > 1 ) : ?>
		<div class="instant-wins-pagination mt-8 flex justify-center">
			<div class="pagination-wrapper" data-action_name="lty_instant_winner_logs" data-product_id="<?php echo esc_attr( $product->get_id() ); ?>">
				<?php lty_get_template( 'pagination.php', $pagination ); ?>
			</div>
		</div>
	<?php endif; ?>
</div>
