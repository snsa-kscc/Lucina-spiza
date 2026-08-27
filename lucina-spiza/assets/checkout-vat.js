/**
 * Inline VAT / OIB validation on the block checkout.
 *
 * Patterns come from PHP via wp_localize_script (window.lucinaSpizaVat) so the
 * regex table lives in exactly one place.
 */
( function () {
	'use strict';

	var config = window.lucinaSpizaVat || {};
	var patterns = config.patterns || {};
	var messages = config.messages || {};
	var FIELD_ID = 'billing-thwcfe-block-_billing_eu_vat_number';

	/**
	 * Croatian OIB check digit — ISO 7064 MOD 11,10.
	 */
	function validateOib( value ) {
		var oib = value.replace( /^HR/i, '' ).trim();

		if ( ! /^\d{11}$/.test( oib ) ) {
			return false;
		}

		var remainder = 10;

		for ( var i = 0; i < 10; i++ ) {
			remainder = ( ( ( remainder + parseInt( oib[ i ], 10 ) ) % 10 ) || 10 ) * 2 % 11;
		}

		return ( 11 - remainder ) % 10 === parseInt( oib[ 10 ], 10 );
	}

	function normalize( value ) {
		return value.replace( /[\s\.\-]/g, '' ).toUpperCase();
	}

	function getCountry() {
		var candidates = [ 'billing-country', 'billing_country', 'shipping-country' ];

		for ( var i = 0; i < candidates.length; i++ ) {
			var el = document.getElementById( candidates[ i ] );

			if ( el && el.value ) {
				return el.value;
			}
		}

		var byName = document.querySelector(
			'select[name="billing_country"], input[name="billing_country"]'
		);

		if ( byName && byName.value ) {
			return byName.value;
		}

		var fuzzy = document.querySelector( '[id$="-country"], [id$="_country"]' );

		if ( fuzzy && fuzzy.value ) {
			return fuzzy.value;
		}

		return '';
	}

	function isValid( country, raw ) {
		var value = normalize( raw );

		if ( 'HR' === country ) {
			return validateOib( value );
		}

		var pattern = patterns[ country ];

		// Unknown (non-EU) country — nothing to check against.
		if ( ! pattern ) {
			return true;
		}

		return new RegExp( pattern ).test( value );
	}

	function errorMessage( country ) {
		return 'HR' === country
			? ( messages.oib || 'Neispravan OIB. Provjerite uneseni broj.' )
			: ( messages.vat || 'Neispravan PDV broj. Provjerite format.' );
	}

	document.addEventListener(
		'focusout',
		function ( event ) {
			if ( event.target.id !== FIELD_ID ) {
				return;
			}

			var value = event.target.value.trim();
			var wrapper = event.target.closest( '.wc-block-components-text-input' );

			if ( ! wrapper ) {
				return;
			}

			var existing = wrapper.querySelector( '.ls-oib-error' );

			if ( existing ) {
				existing.remove();
			}

			if ( ! value ) {
				return;
			}

			var country = getCountry();

			if ( isValid( country, value ) ) {
				return;
			}

			var error = document.createElement( 'div' );
			error.className = 'ls-oib-error';
			error.textContent = errorMessage( country );
			wrapper.appendChild( error );
		},
		true
	);
} )();
