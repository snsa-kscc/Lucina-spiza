<?php
/**
 * Removes "(opcionalno)" from WooCommerce block checkout labels.
 *
 * The theme version of this ran on every page of the site with a MutationObserver
 * over the whole body; the labels only exist on checkout, so it is scoped here.
 *
 * @package LucinaSpiza
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		wp_enqueue_script(
			'lucina-spiza-checkout-labels',
			LUCINA_SPIZA_URL . '/assets/checkout-labels.js',
			array(),
			LUCINA_SPIZA_VERSION,
			true
		);
	}
);

/**
 * Console branding.
 */
add_action(
	'wp_footer',
	function () {
		?>
		<script>
		console.log( '%cmade with love by dvasadva.com', 'color: red; font-size: 16px; font-weight: bold;' );
		</script>
		<?php
	}
);
