<?php
/**
 * EU VAT / OIB validation.
 *
 * Single source of truth for the VAT patterns. The same table was previously
 * duplicated as JavaScript regex literals in the theme; it is now handed to the
 * frontend via wp_localize_script() so the two can never drift apart.
 *
 * @package LucinaSpiza
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EU VAT format patterns, as strings without delimiters so the same source can
 * build both a PCRE pattern and a JavaScript RegExp.
 *
 * @return array<string,string> Country code => pattern body.
 */
function lucina_spiza_vat_patterns() {
	return array(
		'AT' => '^ATU\d{8}$',
		'BE' => '^BE[01]\d{9}$',
		'BG' => '^BG\d{9,10}$',
		'CY' => '^CY\d{8}[A-Z]$',
		'CZ' => '^CZ\d{8,10}$',
		'DE' => '^DE\d{9}$',
		'DK' => '^DK\d{8}$',
		'EE' => '^EE\d{9}$',
		'EL' => '^EL\d{9}$',
		'GR' => '^EL\d{9}$',
		'ES' => '^ES[A-Z0-9]\d{7}[A-Z0-9]$',
		'FI' => '^FI\d{8}$',
		'FR' => '^FR[A-Z0-9]{2}\d{9}$',
		'HR' => '^HR\d{11}$',
		'HU' => '^HU\d{8}$',
		'IE' => '^IE(\d{7}[A-Z]{1,2}|\d[A-Z]\d{5}[A-Z])$',
		'IT' => '^IT\d{11}$',
		'LT' => '^LT(\d{9}|\d{12})$',
		'LU' => '^LU\d{8}$',
		'LV' => '^LV\d{11}$',
		'MT' => '^MT\d{8}$',
		'NL' => '^NL\d{9}B\d{2}$',
		'PL' => '^PL\d{10}$',
		'PT' => '^PT\d{9}$',
		'RO' => '^RO\d{2,10}$',
		'SE' => '^SE\d{12}$',
		'SI' => '^SI\d{8}$',
		'SK' => '^SK\d{10}$',
	);
}

/**
 * Strip spaces, dots and dashes and upper-case, matching the frontend normalizer.
 *
 * @param string $value Raw user input.
 * @return string
 */
function lucina_spiza_normalize_vat( $value ) {
	return strtoupper( preg_replace( '/[\s\.\-]/', '', (string) $value ) );
}

/**
 * Croatian OIB check digit — ISO 7064 MOD 11,10.
 *
 * @param string $oib Eleven digits, no country prefix.
 * @return bool
 */
if ( ! function_exists( 'lucina_spiza_validate_oib' ) ) {
	function lucina_spiza_validate_oib( $oib ) {
		if ( ! preg_match( '/^\d{11}$/', (string) $oib ) ) {
			return false;
		}

		$remainder = 10;

		for ( $i = 0; $i < 10; $i++ ) {
			$remainder += (int) $oib[ $i ];
			$remainder %= 10;

			if ( 0 === $remainder ) {
				$remainder = 10;
			}

			$remainder *= 2;
			$remainder %= 11;
		}

		$check = 11 - $remainder;

		if ( 10 === $check ) {
			$check = 0;
		}

		return $check === (int) $oib[10];
	}
}

/**
 * EU VAT basic format check — prefix + digit count.
 *
 * Unknown (non-EU) countries pass, matching the previous behaviour.
 *
 * @param string $country Two-letter country code.
 * @param string $vat     Normalized VAT number.
 * @return bool
 */
if ( ! function_exists( 'lucina_spiza_validate_eu_vat' ) ) {
	function lucina_spiza_validate_eu_vat( $country, $vat ) {
		$patterns = lucina_spiza_vat_patterns();

		if ( ! isset( $patterns[ $country ] ) ) {
			return true;
		}

		return (bool) preg_match( '/' . $patterns[ $country ] . '/', $vat );
	}
}

/**
 * Validate a VAT/OIB entry for a country, applying the full OIB check for HR.
 *
 * @param string $country Two-letter country code.
 * @param string $raw     Raw user input.
 * @return bool
 */
function lucina_spiza_validate_vat_for_country( $country, $raw ) {
	$vat = lucina_spiza_normalize_vat( $raw );

	if ( 'HR' === $country ) {
		return lucina_spiza_validate_oib( preg_replace( '/^HR/', '', $vat ) );
	}

	return lucina_spiza_validate_eu_vat( $country, $vat );
}
