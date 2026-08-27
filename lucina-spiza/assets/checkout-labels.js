/**
 * Strip "(opcionalno)" from block checkout field labels.
 *
 * Only text nodes are touched, so any markup inside the label (required markers,
 * screen-reader spans) is left intact.
 */
( function () {
	'use strict';

	var SELECTOR =
		'.wc-block-components-text-input label, .wc-block-components-address-form label';
	var PATTERN = /\s*\(opcionalno\)/gi;

	var observer = null;
	var scheduled = false;

	function stripLabels() {
		scheduled = false;

		document.querySelectorAll( SELECTOR ).forEach( function ( label ) {
			label.childNodes.forEach( function ( node ) {
				if ( node.nodeType !== Node.TEXT_NODE ) {
					return;
				}

				var updated = node.textContent.replace( PATTERN, '' );

				// Only write when something changed, so we do not retrigger ourselves.
				if ( updated !== node.textContent ) {
					node.textContent = updated;
				}
			} );
		} );
	}

	/**
	 * Coalesce bursts of mutations into a single pass on the next frame.
	 */
	function schedule() {
		if ( scheduled ) {
			return;
		}

		scheduled = true;
		window.requestAnimationFrame( stripLabels );
	}

	function start() {
		var root = document.querySelector( '.wc-block-checkout' ) || document.body;

		observer = new MutationObserver( schedule );
		observer.observe( root, { childList: true, subtree: true } );

		stripLabels();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
