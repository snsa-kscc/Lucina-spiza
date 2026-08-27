<?php
/**
 * e-Računi integration fixes.
 *
 * @package LucinaSpiza
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prepend "Pristup sadržaju " to product titles in WooCommerce REST API responses
 * so e-Računi invoices show the correct line item description.
 */
add_filter(
	'woocommerce_rest_prepare_shop_order_object',
	function ( $response, $order, $request ) {
		$data = $response->get_data();

		if ( empty( $data['line_items'] ) ) {
			return $response;
		}

		$prefix = 'Pristup sadržaju ';

		foreach ( $data['line_items'] as &$item ) {
			if ( isset( $item['name'] ) && 0 !== strpos( $item['name'], $prefix ) ) {
				$item['name'] = $prefix . $item['name'];
			}
		}
		unset( $item );

		$response->set_data( $data );

		return $response;
	},
	10,
	3
);

/**
 * Neutralize e-Računi's malformed "Content-Encoding: 8-bit" header (cURL error 61).
 */
add_filter(
	'http_request_args',
	function ( $args, $url ) {
		if ( false !== strpos( $url, 'e-racuni.com' ) ) {
			$args['decompress']                 = false;      // Never attempt to decode the response.
			$args['headers']['Accept-Encoding'] = 'identity'; // Ask e-Računi not to "encode".
		}

		return $args;
	},
	10,
	2
);
