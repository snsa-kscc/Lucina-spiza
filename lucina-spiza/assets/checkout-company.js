/**
 * Company invoice field visibility toggle.
 *
 * Checked  → hide first/last name, show company name + OIB.
 * Unchecked → show first/last name, hide company name + OIB.
 *
 * Fields are cleared when toggling so a hidden field never submits a stale value.
 */
( function () {
	'use strict';

	var HIDDEN_CLASS = 'checkout-field-hidden';
	var CHECKBOX_ID = 'billing-thwcfe-block-is_company_invoice';

	var SELECTORS = {
		firstName: '.wc-block-components-address-form__first_name',
		lastName: '.wc-block-components-address-form__last_name',
		company: '.wc-block-components-address-form__company',
		vat: '.wc-block-components-address-form__thwcfe-block-_billing_eu_vat_number'
	};

	var lastChecked = null;

	/**
	 * Clear an input using React's native value setter, so the block checkout's
	 * internal state updates rather than silently keeping the old value.
	 */
	function clearField( wrapper ) {
		if ( ! wrapper ) {
			return;
		}

		var input = wrapper.querySelector( 'input' );

		if ( ! input || ! input.value ) {
			return;
		}

		var setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;

		setter.call( input, '' );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	function sync() {
		var checkbox = document.getElementById( CHECKBOX_ID );

		if ( ! checkbox ) {
			return;
		}

		var fields = {
			firstName: document.querySelector( SELECTORS.firstName ),
			lastName: document.querySelector( SELECTORS.lastName ),
			company: document.querySelector( SELECTORS.company ),
			vat: document.querySelector( SELECTORS.vat )
		};

		if ( ! fields.firstName || ! fields.lastName || ! fields.company || ! fields.vat ) {
			return;
		}

		var isCompany = checkbox.checked;

		// Clear the fields that are about to be hidden.
		if ( null !== lastChecked && lastChecked !== isCompany ) {
			if ( isCompany ) {
				clearField( fields.firstName );
				clearField( fields.lastName );
			} else {
				clearField( fields.company );
				clearField( fields.vat );
			}
		}

		lastChecked = isCompany;

		fields.firstName.classList.toggle( HIDDEN_CLASS, isCompany );
		fields.lastName.classList.toggle( HIDDEN_CLASS, isCompany );
		fields.company.classList.toggle( HIDDEN_CLASS, ! isCompany );
		fields.vat.classList.toggle( HIDDEN_CLASS, ! isCompany );
	}

	// Catch user toggles immediately.
	document.addEventListener( 'change', function ( event ) {
		if ( event.target && event.target.id === CHECKBOX_ID ) {
			sync();
		}
	}, true );

	// The block checkout re-renders its form, so re-apply when the DOM changes.
	function observe() {
		var root = document.querySelector( '.wc-block-checkout' ) || document.body;

		new MutationObserver( sync ).observe( root, {
			childList: true,
			subtree: true
		} );

		sync();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', observe );
	} else {
		observe();
	}
} )();
