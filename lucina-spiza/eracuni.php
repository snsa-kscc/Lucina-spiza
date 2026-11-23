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

/**
 * Send e-Računi one webhook per new order instead of two.
 *
 * e-Računi is hooked up through two WooCommerce webhooks with the same
 * delivery URL: `order.created` and `order.updated`. Paying for an order
 * fires both, so Action Scheduler delivers two identical payloads to e-Računi
 * within a second (both are built at delivery time from the current order).
 * e-Računi answers 200 to both and sometimes imports neither, probably because
 * the two imports of the same order overlap (#574, #578).
 *
 * Just before WooCommerce queues this request's webhooks (`shutdown`, 10),
 * drop an e-Računi `order.updated` delivery when an `order.created` one for
 * the same order and URL is queued in this request or is still pending.
 * Updates to an order e-Računi already got still go through.
 */
add_action( 'shutdown', 'lucina_eracuni_dedupe_order_webhooks', 9 );

function lucina_eracuni_dedupe_order_webhooks() {
	global $wc_queued_webhooks;

	if ( empty( $wc_queued_webhooks ) || ! is_array( $wc_queued_webhooks ) ) {
		return;
	}

	// Orders with an order.created delivery queued in this request, per URL.
	$created = array();
	foreach ( $wc_queued_webhooks as $item ) {
		if ( 'order.created' === $item['webhook']->get_topic() ) {
			$created[ $item['webhook']->get_delivery_url() ][ (int) $item['arg'] ] = true;
		}
	}

	foreach ( $wc_queued_webhooks as $key => $item ) {
		$webhook = $item['webhook'];
		$url     = $webhook->get_delivery_url();

		if ( 'order.updated' !== $webhook->get_topic() || false === strpos( $url, 'e-racuni.com' ) ) {
			continue;
		}

		$order_id = (int) $item['arg'];
		if ( isset( $created[ $url ][ $order_id ] ) || lucina_eracuni_created_webhook_pending( $url, $item['arg'] ) ) {
			unset( $wc_queued_webhooks[ $key ] );

			wc_get_logger()->info(
				sprintf( 'Order ID %d: skipped order.updated webhook #%d, order.created already queued for e-Računi', $order_id, $webhook->get_id() ),
				array( 'source' => 'lucina-spiza-eracuni' )
			);
		}
	}
}

/**
 * Whether an `order.created` delivery to $url for this order is still waiting
 * in Action Scheduler (queued by an earlier request).
 *
 * @param string $url Delivery URL.
 * @param mixed  $arg Webhook argument (order ID) as passed to the hook.
 */
function lucina_eracuni_created_webhook_pending( $url, $arg ) {
	static $created_ids = null;

	if ( null === $created_ids ) {
		$created_ids = array();
		foreach ( WC_Data_Store::load( 'webhook' )->get_webhooks_ids( 'active' ) as $id ) {
			$webhook = wc_get_webhook( $id );
			if ( $webhook && 'order.created' === $webhook->get_topic() ) {
				$created_ids[ $id ] = $webhook->get_delivery_url();
			}
		}
	}

	foreach ( $created_ids as $id => $created_url ) {
		if ( $created_url === $url && null !== WC()->queue()->get_next(
			'woocommerce_deliver_webhook_async',
			array(
				'webhook_id' => $id,
				'arg'        => $arg,
			),
			'woocommerce-webhooks'
		) ) {
			return true;
		}
	}

	return false;
}
