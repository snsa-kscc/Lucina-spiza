<?php
/**
 * Gift checkbox — block checkout additional field "is_gift".
 *
 * 1. Copies the WooCommerce additional field value to '_is_gift' order meta.
 * 2. Filters downloadable files: gift → voucher PDF (index 1),
 *    non-gift → access link (index 0).
 *
 * @package LucinaSpiza
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Save the gift flag from the block checkout additional field to order meta.
 */
add_action(
	'woocommerce_store_api_checkout_update_order_from_request',
	function ( $order, $request ) {
		$body   = $request->get_json_params();
		$thwcfe = $body['extensions']['thwcfe-additional-fields'] ?? array();

		$is_gift = ! empty( $thwcfe['additional_info']['is_gift'] ) ? 'yes' : 'no';

		$order->update_meta_data( '_is_gift', $is_gift );
		$order->save();
	},
	10,
	2
);

/**
 * Filter downloads: gift gets the voucher PDF (index 1), non-gift the access link (index 0).
 */
add_filter(
	'woocommerce_get_item_downloads',
	function ( $files, $order_item, $order ) {
		$files   = array_values( $files );
		$is_gift = $order->get_meta( '_is_gift' );

		if ( 'yes' === $is_gift ) {
			return isset( $files[1] ) ? array_values( array( $files[1] ) ) : $files;
		}

		return isset( $files[0] ) ? array_values( array( $files[0] ) ) : $files;
	},
	10,
	3
);

/**
 * Remove "Downloads" from the My Account menu.
 */
add_filter(
	'woocommerce_account_menu_items',
	function ( $items ) {
		unset( $items['downloads'] );

		return $items;
	},
	999
);
