<?php
/**
 * Plugin Name: Lucina Spiza — Site Customizations
 * Description: Checkout, gift-download and e-Računi customizations, moved out of the
 *              Blocksy theme so they survive theme updates. Loads the modules in
 *              mu-plugins/lucina-spiza/.
 * Author:      dvasadva
 * Version:     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LUCINA_SPIZA_VERSION', '1.0.0' );
define( 'LUCINA_SPIZA_DIR', __DIR__ . '/lucina-spiza' );
define( 'LUCINA_SPIZA_URL', plugin_dir_url( __FILE__ ) . 'lucina-spiza' );

/**
 * mu-plugins do not autoload subdirectories, so each module is required explicitly.
 * Order matters only for vat.php, which defines the validators the others call.
 */
foreach ( array(
	'vat.php',
	'checkout-company.php',
	'checkout-labels.php',
	'gift-downloads.php',
	'eracuni.php',
) as $lucina_spiza_module ) {
	$lucina_spiza_path = LUCINA_SPIZA_DIR . '/' . $lucina_spiza_module;

	if ( is_readable( $lucina_spiza_path ) ) {
		require_once $lucina_spiza_path;
	}
}
unset( $lucina_spiza_module, $lucina_spiza_path );
