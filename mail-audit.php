<?php
/**
 * Plugin Name: Mail Send Audit
 * Description: Logs every outgoing wp_mail() (recipient, subject, status) so you can
 *              confirm the order emails actually fired. Viewable at
 *              WooCommerce -> Status -> Logs (source: "mail-audit").
 * Author:      dvasadva
 * Version:     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fires when WordPress hands an email off to PHPMailer.
 * If no matching wp_mail_failed follows, the send succeeded.
 */
add_filter(
	'wp_mail',
	function ( $args ) {
		$to      = isset( $args['to'] ) ? $args['to'] : '';
		$to      = is_array( $to ) ? implode( ', ', $to ) : $to;
		$subject = isset( $args['subject'] ) ? $args['subject'] : '(no subject)';

		mail_audit_log( sprintf( 'DISPATCH  to=%s | subject=%s', $to, $subject ) );

		return $args;
	},
	PHP_INT_MAX // run last, after any plugin that rewrites recipients/subject
);

/**
 * Fires only when the actual send fails (SMTP/mail() error).
 */
add_action(
	'wp_mail_failed',
	function ( $error ) {
		$data = $error->get_error_data();
		$to   = '';

		if ( is_array( $data ) && isset( $data['to'] ) ) {
			$to = is_array( $data['to'] ) ? implode( ', ', $data['to'] ) : $data['to'];
		}

		mail_audit_log(
			sprintf( 'FAILED    to=%s | error=%s', $to, $error->get_error_message() ),
			'error'
		);
	}
);

/**
 * Write to the WooCommerce logger if present, otherwise PHP error_log.
 */
function mail_audit_log( $message, $level = 'info' ) {
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->log( $level, $message, array( 'source' => 'mail-audit' ) );
	} else {
		error_log( '[mail-audit] ' . strtoupper( $level ) . ' ' . $message );
	}
}