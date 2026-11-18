<?php
/**
 * e-Računi sync monitor.
 *
 * e-Računi sometimes answers an order webhook with HTTP 200 and then never
 * imports the order (#439, #442). Once a day this module looks up recent
 * orders (and older ones still in a problem state) through the e-Računi API, optionally re-sends missing ones through the
 * Eurofaktura plugin's own "Sinkroniziraj" call, and emails a report when
 * something needs attention. Silent when everything is fine.
 *
 * Config (wp-config.php, never the repo):
 *   LUCINA_ERACUNI_API_URL        e.g. https://e-racuni.com/WebServicesHR/API
 *   LUCINA_ERACUNI_API_USER       API user
 *   LUCINA_ERACUNI_API_SECRET     API user's secret key
 *   LUCINA_ERACUNI_API_TOKEN      organization token
 *   LUCINA_ERACUNI_MONITOR_REPAIR true = resync missing orders (default false)
 *   LUCINA_ERACUNI_MONITOR_EMAILS comma-separated alert recipients
 *   LUCINA_ERACUNI_MONITOR_AT     run time "HH:MM" Europe/Zagreb (default 04:00)
 *   WP_ENVIRONMENT_TYPE           'staging' on staging → "[STAGING]" in subject
 *
 * The module does nothing unless the API constants are defined.
 *
 * See docs/eracuni-sync-monitor-proposal.md.
 *
 * @package LucinaSpiza
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LUCINA_ERACUNI_MONITOR_HOOK  = 'lucina_eracuni_monitor_run';
const LUCINA_ERACUNI_MONITOR_GROUP = 'lucina-spiza';
const LUCINA_ERACUNI_MONITOR_META  = '_lucina_eracuni_monitor';
const LUCINA_ERACUNI_MONITOR_LOG   = 'lucina-spiza-eracuni-monitor';

/** Default run time, Europe/Zagreb. Override with LUCINA_ERACUNI_MONITOR_AT in wp-config. */
const LUCINA_ERACUNI_MONITOR_DEFAULT_AT = '04:00';

/** Order window: created between 48 h and 30 min ago. */
const LUCINA_ERACUNI_MONITOR_WINDOW = 172800; // 48 h.
const LUCINA_ERACUNI_MONITOR_GRACE  = 1800;   // 30 min.

/**
 * Orders older than the window are re-checked for up to 14 days while their
 * last outcome is one of these problems, until they come back synced.
 */
const LUCINA_ERACUNI_MONITOR_RECHECK  = 1209600; // 14 days.
const LUCINA_ERACUNI_MONITOR_PROBLEMS = array( 'unknown', 'missing', 'needs_correction', 'synced_no_invoice', 'repair_failed', 'repair_unverified' );

/**
 * "All missing" guard: if the API answers ok but finds none of at least this
 * many orders, suspect wrong credentials / company rather than real drops.
 */
const LUCINA_ERACUNI_MONITOR_SUSPICIOUS_MIN = 3;

/** Stop resyncing an order after this many failed repair runs. */
const LUCINA_ERACUNI_MONITOR_MAX_REPAIRS = 2;

/**
 * API retries on connection errors, HTTP 429 and 5xx (e-Računi has short
 * outages): 2 retries, waiting 15 s then 30 s.
 */
const LUCINA_ERACUNI_MONITOR_RETRIES     = 2;
const LUCINA_ERACUNI_MONITOR_RETRY_DELAY = 15;

/**
 * Whether the monitor is configured on this site.
 */
function lucina_eracuni_monitor_enabled() {
	return defined( 'LUCINA_ERACUNI_API_URL' )
		&& defined( 'LUCINA_ERACUNI_API_USER' )
		&& defined( 'LUCINA_ERACUNI_API_SECRET' )
		&& defined( 'LUCINA_ERACUNI_API_TOKEN' );
}

/**
 * Whether missing orders may be resynced through the Eurofaktura plugin.
 */
function lucina_eracuni_monitor_repair_enabled() {
	return defined( 'LUCINA_ERACUNI_MONITOR_REPAIR' ) && true === LUCINA_ERACUNI_MONITOR_REPAIR
		&& function_exists( 'eurofaktura_map_order_for_request' )
		&& class_exists( 'Eurofaktura_http_client' )
		&& get_option( 'eurofaktura_connected' );
}

function lucina_eracuni_monitor_log( $level, $message ) {
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->log( $level, $message, array( 'source' => LUCINA_ERACUNI_MONITOR_LOG ) );
	}
}

/*
 * ---------------------------------------------------------------------------
 * Schedule: one pending single action at the next 04:00 Europe/Zagreb. Each run
 * schedules the following one; init re-creates it if it went missing. Computing
 * the timestamp in Europe/Zagreb keeps it at 04:00 across DST changes.
 * ---------------------------------------------------------------------------
 */

/**
 * Configured run time "HH:MM" (Europe/Zagreb).
 */
function lucina_eracuni_monitor_at() {
	$at = defined( 'LUCINA_ERACUNI_MONITOR_AT' ) ? (string) LUCINA_ERACUNI_MONITOR_AT : '';

	return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $at ) ? $at : LUCINA_ERACUNI_MONITOR_DEFAULT_AT;
}

function lucina_eracuni_monitor_next_run( $now = null ) {
	$tz   = new DateTimeZone( 'Europe/Zagreb' );
	$now  = $now ? $now : time();
	$at   = lucina_eracuni_monitor_at();
	$next = new DateTimeImmutable( 'today ' . $at, $tz );

	if ( $next->getTimestamp() <= $now + MINUTE_IN_SECONDS ) {
		$next = new DateTimeImmutable( 'tomorrow ' . $at, $tz );
	}

	return $next->getTimestamp();
}

function lucina_eracuni_monitor_schedule() {
	if ( ! function_exists( 'as_next_scheduled_action' ) ) {
		return;
	}

	if ( ! lucina_eracuni_monitor_enabled() ) {
		as_unschedule_all_actions( LUCINA_ERACUNI_MONITOR_HOOK, array(), LUCINA_ERACUNI_MONITOR_GROUP );
		return;
	}

	$pending = as_next_scheduled_action( LUCINA_ERACUNI_MONITOR_HOOK, array(), LUCINA_ERACUNI_MONITOR_GROUP );
	$next    = lucina_eracuni_monitor_next_run();

	if ( false === $pending ) {
		as_schedule_single_action( $next, LUCINA_ERACUNI_MONITOR_HOOK, array(), LUCINA_ERACUNI_MONITOR_GROUP );
		return;
	}

	// Run time changed in wp-config: move the queued run. Leave it alone if it
	// is running (true) or due within 2 minutes, so a due run is never dropped.
	if ( is_int( $pending ) && $pending > time() + 2 * MINUTE_IN_SECONDS && $pending !== $next ) {
		as_unschedule_all_actions( LUCINA_ERACUNI_MONITOR_HOOK, array(), LUCINA_ERACUNI_MONITOR_GROUP );
		as_schedule_single_action( $next, LUCINA_ERACUNI_MONITOR_HOOK, array(), LUCINA_ERACUNI_MONITOR_GROUP );
	}
}
add_action( 'action_scheduler_init', 'lucina_eracuni_monitor_schedule' );

add_action(
	LUCINA_ERACUNI_MONITOR_HOOK,
	function () {
		try {
			lucina_eracuni_monitor_run();
		} finally {
			// Always line up tomorrow's run, even if this one threw. The current
			// action is "in-progress", so as_next_scheduled_action() would see it;
			// schedule directly instead.
			if ( lucina_eracuni_monitor_enabled() && function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( lucina_eracuni_monitor_next_run(), LUCINA_ERACUNI_MONITOR_HOOK, array(), LUCINA_ERACUNI_MONITOR_GROUP );
			}
		}
	}
);

/*
 * ---------------------------------------------------------------------------
 * e-Računi API
 * ---------------------------------------------------------------------------
 */

/**
 * Call an e-Računi API method.
 *
 * @return array|WP_Error The `result` array on success.
 */
function lucina_eracuni_api( $method, array $parameters ) {
	/**
	 * Stub API responses for testing. Return an array (the `result`) or a
	 * WP_Error to skip the HTTP call.
	 */
	$stub = apply_filters( 'lucina_eracuni_monitor_response', null, $method, $parameters );
	if ( null !== $stub ) {
		return $stub;
	}

	$request = array(
		'timeout' => 30,
		'headers' => array( 'Content-Type' => 'application/json' ),
		'body'    => wp_json_encode(
			array(
				'username'   => LUCINA_ERACUNI_API_USER,
				'secretKey'  => LUCINA_ERACUNI_API_SECRET,
				'token'      => LUCINA_ERACUNI_API_TOKEN,
				'method'     => $method,
				'parameters' => (object) $parameters,
			)
		),
	);

	// Retry transient failures with exponential backoff. Bad credentials come
	// back as HTTP 200 with an error status and are not retried.
	$delay = LUCINA_ERACUNI_MONITOR_RETRY_DELAY;
	for ( $attempt = 0; ; $attempt++ ) {
		$response = wp_remote_post( LUCINA_ERACUNI_API_URL, $request );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$failed   = is_wp_error( $response ) || 429 === $code || $code >= 500;

		if ( ! $failed || $attempt >= LUCINA_ERACUNI_MONITOR_RETRIES ) {
			break;
		}

		lucina_eracuni_monitor_log(
			'warning',
			sprintf( '%s: %s — novi pokušaj za %d s', $method, is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code, $delay )
		);
		sleep( $delay );
		$delay *= 2;
	}

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	$data = is_array( $body ) && isset( $body['response'] ) ? $body['response'] : null;

	if ( 200 !== $code || ! is_array( $data ) || 'ok' !== ( $data['status'] ?? '' ) || ! isset( $data['result'] ) || ! is_array( $data['result'] ) ) {
		$why = is_array( $data ) && ! empty( $data['description'] ) ? $data['description'] : 'neočekivan odgovor';
		return new WP_Error( 'lucina_eracuni_api', sprintf( '%s: HTTP %d, %s', $method, $code, $why ) );
	}

	return $data['result'];
}

/**
 * Look up sales orders (narudžbe kupca) by Woo order number.
 *
 * @param string[] $numbers Woo order numbers.
 * @return array|WP_Error number => SalesOrder.
 */
function lucina_eracuni_monitor_fetch( array $numbers ) {
	$found = array();

	foreach ( array_chunk( $numbers, 100 ) as $i => $chunk ) {
		if ( $i > 0 ) {
			sleep( 2 ); // API allows ~1 request/s, no concurrency.
		}

		$result = lucina_eracuni_api( 'SalesOrderList', array( 'number' => implode( ',', $chunk ) ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( $result as $sales_order ) {
			if ( isset( $sales_order['number'] ) ) {
				$found[ (string) $sales_order['number'] ] = $sales_order;
			}
		}
	}

	return $found;
}

/**
 * Classify one order from its e-Računi sales order (or null if absent).
 *
 * @return string synced | synced_no_invoice | needs_correction | missing
 */
function lucina_eracuni_monitor_classify( $sales_order ) {
	if ( ! $sales_order ) {
		return 'missing';
	}
	if ( 'CorrectionPending' === ( $sales_order['status'] ?? '' ) ) {
		return 'needs_correction';
	}
	if ( empty( $sales_order['salesInvoiceRef'] ) && empty( $sales_order['salesInvoicesRef'] ) ) {
		return 'synced_no_invoice';
	}
	return 'synced';
}

/*
 * ---------------------------------------------------------------------------
 * Run
 * ---------------------------------------------------------------------------
 */

/**
 * Orders to check: paid, non-zero, created 48 h … 30 min ago, plus orders up
 * to 14 days old whose last outcome is still a problem.
 *
 * @return WC_Order[]
 */
function lucina_eracuni_monitor_orders( $now = null ) {
	$now = $now ? $now : time();

	$recent = lucina_eracuni_monitor_query( $now - LUCINA_ERACUNI_MONITOR_WINDOW, $now - LUCINA_ERACUNI_MONITOR_GRACE );
	$older  = array_filter(
		lucina_eracuni_monitor_query( $now - LUCINA_ERACUNI_MONITOR_RECHECK, $now - LUCINA_ERACUNI_MONITOR_WINDOW - 1 ),
		function ( $order ) {
			$state = $order->get_meta( LUCINA_ERACUNI_MONITOR_META );
			return is_array( $state ) && in_array( $state['outcome'] ?? '', LUCINA_ERACUNI_MONITOR_PROBLEMS, true );
		}
	);

	return array_merge( $recent, array_values( $older ) );
}

/**
 * Paid, non-zero orders created between two timestamps (inclusive).
 *
 * @return WC_Order[]
 */
function lucina_eracuni_monitor_query( $from, $to ) {
	$orders = wc_get_orders(
		array(
			'status'       => array( 'processing', 'completed' ),
			'date_created' => $from . '...' . $to,
			'limit'        => -1,
			'type'         => 'shop_order',
		)
	);

	return array_values(
		array_filter(
			$orders,
			function ( $order ) {
				return (float) $order->get_total() > 0;
			}
		)
	);
}

/**
 * Re-send an order through the Eurofaktura plugin (the "Sinkroniziraj" button).
 *
 * @return true|string True on success, otherwise the error text.
 */
function lucina_eracuni_monitor_resync( WC_Order $order ) {
	$response = Eurofaktura_http_client::instance()->post_request( 'order.synchronize', eurofaktura_map_order_for_request( $order ), 30 );

	if ( null === $response ) {
		return 'e-Računi se nije javio (timeout)';
	}

	$status = method_exists( $response, 'getStatusCode' ) ? (int) $response->getStatusCode() : 0;
	if ( 200 !== $status ) {
		$body = method_exists( $response, 'getBody' ) ? (string) $response->getBody() : '';
		return sprintf( 'HTTP %d %s', $status, wp_strip_all_tags( substr( $body, 0, 300 ) ) );
	}

	return true;
}

/**
 * The daily job. Returns the per-order results (also useful when run by hand).
 */
function lucina_eracuni_monitor_run() {
	if ( ! lucina_eracuni_monitor_enabled() || ! function_exists( 'wc_get_orders' ) ) {
		return array();
	}

	$orders = lucina_eracuni_monitor_orders();
	if ( ! $orders ) {
		lucina_eracuni_monitor_log( 'info', 'Nema narudžbi za provjeru.' );
		return array();
	}

	$by_number = array();
	foreach ( $orders as $order ) {
		$by_number[ (string) $order->get_order_number() ] = $order;
	}

	$found = lucina_eracuni_monitor_fetch( array_map( 'strval', array_keys( $by_number ) ) );

	// "All missing" guard: an ok answer that finds none of several orders more
	// likely means wrong API credentials / company than real drops. Treat as a
	// failed check (unknown), so nothing is reported as missing or resynced.
	// Orders already known to be absent don't count — re-checking them and not
	// finding them is expected.
	$expected = array_filter(
		$by_number,
		function ( $order ) {
			$state = $order->get_meta( LUCINA_ERACUNI_MONITOR_META );
			return ! is_array( $state ) || ! in_array( $state['outcome'] ?? '', array( 'missing', 'repair_failed' ), true );
		}
	);

	if ( ! is_wp_error( $found ) && ! $found && count( $expected ) >= LUCINA_ERACUNI_MONITOR_SUSPICIOUS_MIN ) {
		$found = new WP_Error(
			'lucina_eracuni_suspicious',
			sprintf( 'e-Računi nije vratio nijednu od %d narudžbi — provjeriti API podatke (korisnik, tvrtka) ili je sinkronizacija potpuno stala. Automatski popravak preskočen.', count( $by_number ) )
		);
	}

	$results = array();

	foreach ( $by_number as $number => $order ) {
		$state = is_array( $order->get_meta( LUCINA_ERACUNI_MONITOR_META ) ) ? $order->get_meta( LUCINA_ERACUNI_MONITOR_META ) : array();

		$result = array(
			'order'    => $order,
			'number'   => (string) $number,
			'previous' => $state['outcome'] ?? '',
			'repairs'  => (int) ( $state['repairs'] ?? 0 ),
			'detail'   => '',
		);

		if ( is_wp_error( $found ) ) {
			$result['outcome'] = 'unknown';
			$result['detail']  = $found->get_error_message();
		} else {
			$sales_order       = $found[ $result['number'] ] ?? null;
			$result['outcome'] = lucina_eracuni_monitor_classify( $sales_order );
			$result['detail']  = lucina_eracuni_monitor_detail( $sales_order );
		}

		$results[ $result['number'] ] = $result;
	}

	// Repair: resync missing orders, then look them up again.
	if ( lucina_eracuni_monitor_repair_enabled() ) {
		$resynced = array();

		foreach ( $results as $number => $result ) {
			if ( 'missing' !== $result['outcome'] || $result['repairs'] >= LUCINA_ERACUNI_MONITOR_MAX_REPAIRS ) {
				continue;
			}

			$ok = lucina_eracuni_monitor_resync( $result['order'] );
			++$results[ $number ]['repairs'];

			if ( true === $ok ) {
				$resynced[] = $number;
			} else {
				$results[ $number ]['outcome'] = 'repair_failed';
				$results[ $number ]['detail']  = $ok;
			}
		}

		if ( $resynced ) {
			sleep( 5 );
			$again = lucina_eracuni_monitor_fetch( $resynced );

			foreach ( $resynced as $number ) {
				if ( is_wp_error( $again ) ) {
					$results[ $number ]['outcome'] = 'repair_unverified';
					$results[ $number ]['detail']  = $again->get_error_message();
					continue;
				}

				$sales_order = $again[ $number ] ?? null;
				$outcome     = lucina_eracuni_monitor_classify( $sales_order );

				$results[ $number ]['outcome'] = 'missing' === $outcome ? 'repair_failed' : 'repaired';
				$results[ $number ]['detail']  = 'missing' === $outcome
					? 'nakon ponovne sinkronizacije narudžba kupca i dalje ne postoji'
					: lucina_eracuni_monitor_detail( $sales_order ) . ( 'synced' === $outcome ? '' : ' — ' . lucina_eracuni_monitor_label( $outcome ) );
			}
		}
	}

	// Persist state and decide what to report (each order once per outcome).
	// Exception: while the check itself fails (API error or "all missing"
	// guard), report every order each night, so a long outage never goes quiet.
	$check_failed = is_wp_error( $found );
	$report       = array();
	foreach ( $results as $number => $result ) {
		$order = $result['order'];
		$order->update_meta_data(
			LUCINA_ERACUNI_MONITOR_META,
			array(
				'outcome' => $result['outcome'],
				'repairs' => $result['repairs'],
				'checked' => time(),
			)
		);
		$order->save_meta_data();

		$changed  = $result['outcome'] !== $result['previous'];
		$resolved = 'synced' === $result['outcome'] && '' !== $result['previous'] && 'synced' !== $result['previous'];

		if ( $check_failed || ( $changed && ( 'synced' !== $result['outcome'] || $resolved ) ) ) {
			$report[] = $result;
		}

		lucina_eracuni_monitor_log(
			'synced' === $result['outcome'] ? 'info' : 'warning',
			sprintf( '#%s (ID %d): %s %s', $number, $order->get_id(), $result['outcome'], $result['detail'] )
		);
	}

	if ( $report ) {
		lucina_eracuni_monitor_email( $report );
	}

	return $results;
}

function lucina_eracuni_monitor_detail( $sales_order ) {
	if ( ! $sales_order ) {
		return '';
	}

	$parts = array( 'narudžba kupca ' . ( $sales_order['number'] ?? '?' ) );

	$invoice = $sales_order['salesInvoicesRef'] ?? ( $sales_order['salesInvoiceRef'] ?? '' );
	if ( $invoice ) {
		$parts[] = 'račun ' . $invoice;
	}
	if ( 'CorrectionPending' === ( $sales_order['status'] ?? '' ) && ! empty( $sales_order['remarks'] ) ) {
		$parts[] = trim( preg_replace( '/\s+/', ' ', $sales_order['remarks'] ) );
	}

	return implode( ', ', $parts );
}

function lucina_eracuni_monitor_label( $outcome ) {
	$labels = array(
		'synced'            => 'Riješeno — narudžba i račun postoje',
		'synced_no_invoice' => 'Narudžba kupca postoji, ali račun nije izdan',
		'needs_correction'  => 'e-Računi traži ispravak narudžbe (račun nije izdan)',
		'missing'           => 'Narudžba ne postoji u e-Računima',
		'repaired'          => 'Popravljeno — ponovno sinkronizirano',
		'repair_failed'     => 'Ponovna sinkronizacija nije uspjela',
		'repair_unverified' => 'Ponovno sinkronizirano, ali provjera nije uspjela',
		'unknown'           => 'Provjera nije uspjela — ponovit će se sutra',
	);

	return $labels[ $outcome ] ?? $outcome;
}

/**
 * Alert recipients: LUCINA_ERACUNI_MONITOR_EMAILS in wp-config.php, a
 * comma-separated list, set per environment.
 *
 * @return string[]
 */
function lucina_eracuni_monitor_recipients() {
	$list = defined( 'LUCINA_ERACUNI_MONITOR_EMAILS' ) ? (string) LUCINA_ERACUNI_MONITOR_EMAILS : '';

	return array_values( array_filter( array_map( 'trim', explode( ',', $list ) ), 'is_email' ) );
}

function lucina_eracuni_monitor_email( array $report ) {
	$recipients = lucina_eracuni_monitor_recipients();
	if ( ! $recipients ) {
		lucina_eracuni_monitor_log( 'warning', 'LUCINA_ERACUNI_MONITOR_EMAILS nije postavljen — izvještaj nije poslan.' );
		return;
	}

	$site  = wp_parse_url( home_url(), PHP_URL_HOST );
	$env   = wp_get_environment_type();
	$tag   = 'production' === $env ? '' : '[' . strtoupper( $env ) . '] ';
	$lines = array(
		'Dnevna provjera sinkronizacije narudžbi s e-Računima (' . $site . ', okruženje: ' . $env . ').',
		'',
	);

	foreach ( $report as $result ) {
		$order   = $result['order'];
		$lines[] = sprintf( 'Narudžba #%s — %s, %s %s', $result['number'], $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd.m.Y. H:i' ) : '', $order->get_total(), $order->get_currency() );
		$lines[] = '  ' . lucina_eracuni_monitor_label( $result['outcome'] );
		if ( $result['detail'] ) {
			$lines[] = '  ' . $result['detail'];
		}
		if ( 'missing' === $result['outcome'] ) {
			$lines[] = '  Ručno: otvori narudžbu i u okviru e-Računi klikni "Sinkroniziraj". Ne izdavati račun ručno.';
		}
		$lines[] = '  ' . $order->get_edit_order_url();
		$lines[] = '';
	}

	$lines[] = 'Automatska poruka, šalje se samo kad nešto treba pažnju.';

	// Send as the shop's WooCommerce sender (e.g. woo@lucinaspiza.com) instead of
	// WordPress' default wordpress@<host>: on staging that is the labouche
	// subdomain, which has no SPF, so Gmail files the alert as spam.
	$headers = array();
	$from    = (string) get_option( 'woocommerce_email_from_address' );
	if ( is_email( $from ) ) {
		$name      = wp_specialchars_decode( (string) get_option( 'woocommerce_email_from_name' ), ENT_QUOTES );
		$headers[] = 'From: ' . ( '' !== $name ? $name . ' <' . $from . '>' : $from );
	}

	$sent = wp_mail(
		$recipients,
		sprintf( '%s[%s] e-Računi: %d narudžbi treba pažnju', $tag, $site, count( $report ) ),
		implode( "\n", $lines ),
		$headers
	);

	if ( ! $sent ) {
		lucina_eracuni_monitor_log( 'error', 'Slanje izvještaja e-poštom nije uspjelo.' );
	}
}
