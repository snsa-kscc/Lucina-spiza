<?php
/**
 * WooCommerce block checkout: company invoice handling.
 *
 * When "Trebam račun na tvrtku" is checked the first/last name fields are hidden
 * and company name + OIB are shown; unchecked does the reverse. First/last name
 * are optional in the THWCFE plugin settings, so the server validates that either
 * a personal name or a company name is present.
 *
 * Also bridges the Themehigh block-checkout VAT key to the key e-Računi reads.
 *
 * Requires: THWCFE (Themehigh Checkout Field Editor) for block checkout.
 * Fields:   billing-thwcfe-block-is_company_invoice (checkbox)
 *           billing-thwcfe-block-_billing_eu_vat_number (OIB)
 *
 * @package LucinaSpiza
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side: validate name OR company, and clear the fields that do not apply.
 */
add_action(
	'woocommerce_store_api_checkout_update_order_from_request',
	function ( $order, $request ) {
		$billing    = $request->get_param( 'billing_address' );
		$billing    = is_array( $billing ) ? $billing : array();
		$is_company = ! empty( $billing['thwcfe-block/is_company_invoice'] );

		if ( ! $is_company ) {
			if ( '' === trim( $billing['first_name'] ?? '' ) || '' === trim( $billing['last_name'] ?? '' ) ) {
				throw new \Exception( 'Ime i prezime su obavezni.' );
			}

			// Clean company fields.
			$order->set_billing_company( '' );
			$order->update_meta_data( '_billing_eu_vat_number', '' );

			return;
		}

		$company = trim( $billing['company'] ?? '' );

		if ( '' === $company ) {
			throw new \Exception( 'Naziv tvrtke je obavezan za račun na tvrtku.' );
		}

		$country = $billing['country'] ?? '';
		$vat_raw = trim( $billing['thwcfe-block/_billing_eu_vat_number'] ?? '' );

		if ( '' === $vat_raw ) {
			throw new \Exception( 'OIB je obavezan za račun na tvrtku.' );
		}

		if ( ! lucina_spiza_validate_vat_for_country( $country, $vat_raw ) ) {
			throw new \Exception(
				'HR' === $country
					? 'Neispravan OIB. Provjerite uneseni broj.'
					: 'Neispravan PDV broj. Provjerite format.'
			);
		}

		// Clear personal name fields for company invoices.
		$order->set_billing_first_name( '' );
		$order->set_billing_last_name( '' );

		// Stripe rejects an empty customer name, so fall back to the company name.
		$stripe_name_fix = function ( $args ) use ( $company ) {
			if ( '' === trim( $args['name'] ?? '' ) ) {
				$args['name'] = $company;
			}

			return $args;
		};

		add_filter( 'wc_stripe_create_customer_args', $stripe_name_fix );
		add_filter( 'wc_stripe_update_customer_args', $stripe_name_fix );
	},
	10,
	2
);

/**
 * Patch Stripe payment intent metadata to use the company name when billing name is empty.
 */
add_filter(
	'wc_stripe_payment_metadata',
	function ( $metadata, $order ) {
		if ( '' !== trim( $metadata['customer_name'] ?? '' ) ) {
			return $metadata;
		}

		$company = $order->get_billing_company();

		if ( $company ) {
			$metadata['customer_name'] = $company;
		}

		return $metadata;
	},
	10,
	2
);

/**
 * Bridge: copy the Themehigh block checkout VAT field to the key e-Računi reads.
 */
add_action(
	'woocommerce_store_api_checkout_order_processed',
	function ( $order ) {
		lucina_spiza_copy_vat_to_eracuni( $order );
	}
);

add_action(
	'woocommerce_checkout_order_processed',
	function ( $order_id, $posted_data, $order ) {
		lucina_spiza_copy_vat_to_eracuni( $order );
	},
	10,
	3
);

/**
 * Copy the block-checkout VAT meta onto the plain key e-Računi reads.
 *
 * @param WC_Order|int $order Order or order ID.
 * @return void
 */
if ( ! function_exists( 'lucina_spiza_copy_vat_to_eracuni' ) ) {
	function lucina_spiza_copy_vat_to_eracuni( $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}

		if ( ! $order ) {
			return;
		}

		$vat = $order->get_meta( '_wc_billing/thwcfe-block/_billing_eu_vat_number' );

		if ( $vat ) {
			$order->update_meta_data( '_billing_eu_vat_number', $vat );
			$order->save();
		}
	}
}

/**
 * Frontend: field visibility toggle + inline VAT validation.
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		wp_enqueue_style(
			'lucina-spiza-checkout',
			LUCINA_SPIZA_URL . '/assets/checkout-company.css',
			array(),
			LUCINA_SPIZA_VERSION
		);

		wp_enqueue_script(
			'lucina-spiza-checkout-company',
			LUCINA_SPIZA_URL . '/assets/checkout-company.js',
			array(),
			LUCINA_SPIZA_VERSION,
			true
		);

		wp_enqueue_script(
			'lucina-spiza-checkout-vat',
			LUCINA_SPIZA_URL . '/assets/checkout-vat.js',
			array(),
			LUCINA_SPIZA_VERSION,
			true
		);

		// Hand the PHP pattern table to JS so the two cannot drift apart.
		wp_localize_script(
			'lucina-spiza-checkout-vat',
			'lucinaSpizaVat',
			array(
				'patterns' => lucina_spiza_vat_patterns(),
				'messages' => array(
					'oib' => 'Neispravan OIB. Provjerite uneseni broj.',
					'vat' => 'Neispravan PDV broj. Provjerite format.',
				),
			)
		);
	}
);

/**
 * Firefox: make the value visible in the block checkout country/state selects.
 *
 * Blocksy 2.1.52 applies a block of !important typography to the select built
 * from --theme-form-* variables. Several of those variables are not defined on
 * this site, and unlike font-size they carry no fallback:
 *
 *     line-height: var(--theme-form-line-height) !important;
 *
 * An unresolvable var() is invalid at computed-value time, so the property
 * falls back to its inherited value rather than WooCommerce's own 25px, while
 * the !important still beats WooCommerce's rule. In Firefox the selected text
 * then lays out outside the 50px field box; Chrome tolerates it.
 *
 * These declarations re-state the geometry with real values. Printed in the
 * footer because the enqueued stylesheet loads before Blocksy's checkout CSS,
 * and at equal specificity with !important on both sides source order wins.
 *
 * Remove once Blocksy fixes this upstream.
 */
add_action(
	'wp_footer',
	function () {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		?>
		<style id="lucina-spiza-select-visibility">
		.wc-blocks-components-select .wc-blocks-components-select__container select.wc-blocks-components-select__select {
			line-height: 25px !important;
			height: 50px !important;
			padding: 16px 9px 0 !important;
			font-size: 16px !important;
			text-indent: 0 !important;
			color: #2b2d2f !important;
			background-color: #fff !important;
		}
		</style>
		<?php
	},
	99
);
