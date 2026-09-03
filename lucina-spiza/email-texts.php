<?php
/**
 * WooCommerce e-mail copy overrides.
 *
 * Lets us reword lines that live inside WooCommerce's own e-mail templates without
 * copying those templates into the theme, where a Blocksy update would overwrite them.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The completed-order e-mail is rendered from one of these templates, depending on
 * whether the customer gets the HTML, block-based or plain-text version.
 *
 * @param string $template_name Template passed to wc_get_template().
 * @return bool
 */
function lucina_spiza_is_completed_order_email( $template_name ) {
	return in_array(
		$template_name,
		array(
			'emails/customer-completed-order.php',
			'emails/block/customer-completed-order.php',
			'emails/plain/customer-completed-order.php',
		),
		true
	);
}

/**
 * Replaces "Evo podsjetnika na ono što ste naručili:" with masterclass wording.
 *
 * Matching happens on the English source string, so it works whether or not the
 * Croatian translation is loaded. Apostrophes are normalised because WooCommerce
 * uses typographic ones and has switched styles between releases.
 *
 * @param string $translation Translated text.
 * @param string $text        Original English text.
 * @param string $domain      Text domain.
 * @return string
 */
function lucina_spiza_completed_order_intro( $translation, $text, $domain ) {
	if ( 'woocommerce' !== $domain ) {
		return $translation;
	}

	$normalized = str_replace( array( '’', '‘' ), "'", $text );

	if ( "Here's a reminder of what you've ordered:" === $normalized ) {
		return 'Ispod se nalazi pristup Vašem masterclassu:';
	}

	return $translation;
}

/**
 * The filter is only attached while the completed-order template renders, so the
 * same WooCommerce string stays untouched in the processing, on-hold and refund
 * e-mails, and everywhere else on the site.
 */
add_action(
	'woocommerce_before_template_part',
	function ( $template_name ) {
		if ( lucina_spiza_is_completed_order_email( $template_name ) ) {
			add_filter( 'gettext', 'lucina_spiza_completed_order_intro', 10, 3 );
		}
	}
);

add_action(
	'woocommerce_after_template_part',
	function ( $template_name ) {
		if ( lucina_spiza_is_completed_order_email( $template_name ) ) {
			remove_filter( 'gettext', 'lucina_spiza_completed_order_intro', 10 );
		}
	}
);
