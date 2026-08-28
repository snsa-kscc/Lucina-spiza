<?php
/**
 * Express checkout (Apple Pay / Google Pay) address handling.
 *
 * Wallet payments cannot ask the shopper for a field the wallet itself does not
 * collect. Stripe works around that in
 * WC_Stripe_Express_Checkout_Ajax_Handler::modify_country_locale_for_express_checkout()
 * by marking "state" optional — but only for the 91 countries listed in
 * WC_Stripe_Express_Checkout_Button_States::STATES. Croatia is not one of them,
 * so WooCommerce keeps billing "state" (Županija) required while neither Apple
 * Pay nor Google Pay can supply it, and the Store API rejects the order.
 *
 * Two things are fixed here:
 *
 * 1. During an express checkout request, "state" is made optional for every
 *    country the express button has no state list for. This extends Stripe's own
 *    rule to the countries missing from its table instead of replacing it.
 *
 * 2. The address labels the error messages use are put back. THWCFE strips
 *    'label' out of the country locale (THWCFD_Public_Checkout::prepare_country_locale,
 *    the "override label" advanced setting) so its own labels win later.
 *    WC_Countries::get_address_fields() overlays the locale onto the defaults so
 *    the forms are unaffected, but the Store API reads the label straight out of
 *    the locale in OrderController::validate_address_fields(), which is why the
 *    failure reads "Došlo je do problema s navedenim adresa za naplatu:
 *    je potrebno" with no field name. Restored on Store API requests only, so
 *    the classic checkout and admin keep THWCFE's behaviour.
 *
 * Also logs which address fields a wallet actually delivered, to source
 * "lucina-spiza-express", whenever an express order arrives with an empty field.
 *
 * @package LucinaSpiza
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether this request is a Store API checkout request.
 *
 * @return bool
 */
function lucina_spiza_is_store_api_checkout() {
	if ( empty( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
		return false;
	}

	return 0 === strpos( $GLOBALS['wp']->query_vars['rest_route'], '/wc/store/v1/checkout' );
}

/**
 * Whether this request is a Stripe express checkout (Apple Pay / Google Pay) order.
 *
 * Mirrors WC_Stripe_Express_Checkout_Helper::is_express_checkout_context(), which
 * is not reachable from outside the gateway. The nonce is not re-checked here:
 * nothing below grants access, it only relaxes a field the wallet cannot fill.
 *
 * @return bool
 */
function lucina_spiza_is_express_checkout() {
	if ( ! lucina_spiza_is_store_api_checkout() ) {
		return false;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$header = isset( $_SERVER['HTTP_X_WCSTRIPE_EXPRESS_CHECKOUT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WCSTRIPE_EXPRESS_CHECKOUT'] ) ) : '';

	return 'true' === $header;
}

/**
 * Whether the express checkout buttons can collect a state for this country.
 *
 * @param string $country Two-letter country code.
 * @return bool
 */
function lucina_spiza_express_has_states( $country ) {
	if ( ! class_exists( 'WC_Stripe_Express_Checkout_Button_States' ) ) {
		return true;
	}

	$states = WC_Stripe_Express_Checkout_Button_States::STATES;

	return ! empty( $states[ $country ] );
}

/**
 * Countries that have their own locale entry.
 */
add_filter(
	'woocommerce_get_country_locale',
	function ( $locale ) {
		if ( ! is_array( $locale ) || ! lucina_spiza_is_express_checkout() ) {
			return $locale;
		}

		foreach ( array_keys( $locale ) as $country ) {
			if ( 'default' === $country || lucina_spiza_express_has_states( $country ) ) {
				continue;
			}

			$locale[ $country ]['state']['required'] = false;
		}

		return $locale;
	},
	// After THWCFE's own locale filters (999).
	1000
);

/**
 * The default locale and the shop base country (Croatia), which WooCommerce
 * fills in after the per-country filter has already run.
 *
 * Countries that need a state — US, CA, AU, IT, ES, … — carry their own locale
 * entry, and wc_array_overlay()/wp_parse_args() give that entry precedence over
 * the default, so relaxing the default here does not reach them.
 */
add_filter(
	'woocommerce_get_country_locale_base',
	function ( $fields ) {
		if ( ! is_array( $fields ) || ! lucina_spiza_is_express_checkout() ) {
			return $fields;
		}

		if ( isset( $fields['state'] ) ) {
			$fields['state']['required'] = false;
		}

		return $fields;
	},
	// After THWCFE's prepare_country_locale() (10).
	20
);

/**
 * Put the address labels back for Store API validation messages.
 */
add_filter(
	'woocommerce_get_country_locale_base',
	function ( $fields ) {
		if ( ! is_array( $fields ) || ! lucina_spiza_is_store_api_checkout() ) {
			return $fields;
		}

		$defaults = WC()->countries->get_default_address_fields();

		foreach ( $fields as $key => $field ) {
			if ( ! empty( $field['label'] ) || empty( $defaults[ $key ]['label'] ) ) {
				continue;
			}

			$fields[ $key ]['label'] = $defaults[ $key ]['label'];
		}

		return $fields;
	},
	21
);

/**
 * Record what the wallet actually sent when a field arrives empty.
 *
 * Priority 5 so it runs before checkout-company.php, which can throw.
 * Field names only — no address values are written to the log.
 */
add_action(
	'woocommerce_store_api_checkout_update_order_from_request',
	function ( $order, $request ) {
		if ( ! lucina_spiza_is_express_checkout() ) {
			return;
		}

		$billing = $request->get_param( 'billing_address' );
		$billing = is_array( $billing ) ? $billing : array();

		$empty    = array();
		$provided = array();

		foreach ( $billing as $key => $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$provided[] = $key;
			} else {
				$empty[] = $key;
			}
		}

		if ( ! $empty ) {
			return;
		}

		wc_get_logger()->info(
			sprintf(
				'Express checkout billing address: country=%s, empty=[%s], provided=[%s]',
				isset( $billing['country'] ) ? $billing['country'] : '?',
				implode( ', ', $empty ),
				implode( ', ', $provided )
			),
			array( 'source' => 'lucina-spiza-express' )
		);
	},
	5,
	2
);

/**
 * Relax the Stripe gateway's own required-customer-field check for express checkout.
 *
 * The Store API address validation is driven by the country locale, which the filters
 * above already relax. The gateway runs a second, independent check when it creates the
 * Stripe customer: WC_Stripe_Customer::validate_create_customer_request() derives its
 * required fields from WC_Checkout::get_checkout_fields( 'billing' ) -- the classic
 * checkout field set.
 *
 * That path does not consult the locale when the checkout field editor is configured to
 * override the required property: THWCFD_Public_Checkout::billing_fields() replaces the
 * billing fields with the saved wc_fields_billing option, and prepare_address_fields()
 * only copies the locale's 'required' back when 'enable_required_override' is off. The
 * locale filters above then have no effect there, so the order is created but the payment
 * is rejected with "Missing required customer field: address->state".
 *
 * The Apple Pay sheet cannot supply a state for countries the gateway has no state list
 * for, so drop that one requirement for exactly those countries, express checkout only.
 */
add_filter(
	'wc_stripe_create_customer_required_fields',
	function ( $required_fields, $create_customer_request = array() ) {
		if ( ! is_array( $required_fields ) || ! lucina_spiza_is_express_checkout() ) {
			return $required_fields;
		}

		if ( empty( $required_fields['address']['state'] ) ) {
			return $required_fields;
		}

		$country = '';
		if ( is_array( $create_customer_request ) && ! empty( $create_customer_request['address']['country'] ) ) {
			$country = $create_customer_request['address']['country'];
		}

		if ( '' === $country || lucina_spiza_express_has_states( $country ) ) {
			return $required_fields;
		}

		unset( $required_fields['address']['state'] );

		if ( empty( $required_fields['address'] ) ) {
			unset( $required_fields['address'] );
		}

		return $required_fields;
	},
	10,
	2
);
