<?php
/**
 * Broadcast mail handler for FunnelKit Automations 3.9+.
 *
 * FunnelKit 3.9 sends broadcasts through BWFAN_Mail_Handler_Controller::send_mail()
 * and documents the bwfcrm_override_broadcast_mail_handler filter for replacing it.
 * This handler builds exactly the headers FunnelKit's own handler builds and fires
 * the same hooks, then puts the email in the toSend queue instead of calling
 * wp_mail(), so the drain sends it in batches. FunnelKit records the email as sent
 * when this returns true; the drain later stamps the time toSend accepted it.
 *
 * If the queue is switched off, or an email cannot be queued, it is sent straight
 * away through wp_mail() as FunnelKit would have, so nothing is lost. During an early
 * pre-build run it is held instead, and refused if it cannot be held.
 *
 * Written against FunnelKit Automations Pro 3.9.0.RC2.
 *
 * It also applies bwfan_before_send_email_body, which FunnelKit stopped applying to
 * broadcasts in 3.9. See apply_body_filter() below for why that is this plugin's
 * problem to solve rather than something to leave alone.
 */

defined( 'ABSPATH' ) || exit;

class CHTQ_Mail_Handler extends BWFAN_Mail_Handler_Controller {

	/** engagement id => broadcast id, so each email costs one primary-key lookup at most */
	private static $broadcast_of = array();

	public function send_mail( $mail_data ) {
		if ( false === $mail_data || ! is_array( $mail_data ) ) {
			return false;
		}

		// Before anything branches, so the queue path and the wp_mail fallback below behave
		// the same way. A reader must never get a different email depending on whether the
		// queue happened to be on.
		$mail_data['body'] = $this->apply_body_filter( $mail_data );

		// Inside an early pre-build run (CHTQ_Prebuild_V2) nothing may go out before the
		// scheduled minute: an email that cannot be held is refused, never sent.
		$holding = ! empty( CHTQ::$context['hold'] );
		$s       = CHTQ::settings();
		if ( ! $holding && ( empty( $s['enabled'] ) || ! empty( $mail_data['attachments'] ) ) ) {
			return parent::send_mail( $mail_data );
		}

		// The same headers, in the same order, as BWFAN_Mail_Handler_Controller::send_mail().
		$headers = array(
			'MIME-Version: 1.0',
			'From: ' . ( $mail_data['senders_name'] ?? '' ) . ' <' . ( $mail_data['senders_email'] ?? '' ) . '>',
			'Content-type: text/html;charset=UTF-8',
		);
		if ( ! empty( $mail_data['replyto'] ) && class_exists( 'BWFAN_PRO_Common' ) ) {
			$reply_to = BWFAN_PRO_Common::build_reply_to_header( $mail_data['replyto'], '', $mail_data['senders_name'] ?? '' );
			if ( ! empty( $reply_to ) ) {
				$headers[] = $reply_to;
			}
		}
		if ( ! empty( $mail_data['unsubscribe_link'] ) ) {
			$headers[] = "List-Unsubscribe: <{$mail_data['unsubscribe_link']}>";
			$headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
		}

		$broadcast_data = isset( $mail_data['broadcast_data'] ) && is_array( $mail_data['broadcast_data'] ) ? $mail_data['broadcast_data'] : array();
		do_action( 'bwfan_before_send_email', $broadcast_data, $mail_data['body'], $mail_data );
		$headers = apply_filters( 'bwfan_email_headers', $headers );

		$engagement_id = (int) ( $mail_data['engagement_id'] ?? 0 );
		$result        = CHTQ::enqueue(
			$mail_data['to'] ?? '',
			$mail_data['subject'] ?? '',
			$mail_data['body'] ?? '',
			$headers,
			$this->broadcast_id( $engagement_id ),
			(int) ( $mail_data['contact_id'] ?? 0 ),
			$engagement_id
		);
		if ( true === $result || 'duplicate' === $result ) {
			return true;
		}
		if ( $holding ) {
			if ( class_exists( 'BWFCRM_Common' ) ) {
				BWFCRM_Common::$captured_email_failed_message = 'toSend queue could not hold this pre-built email';
			}
			return false;
		}

		// Could not queue it: send it now, the way FunnelKit's own handler does.
		try {
			$sent = wp_mail( $mail_data['to'], $mail_data['subject'], $mail_data['body'], $headers );
		} catch ( Error|Exception $e ) {
			$sent = false;
			if ( class_exists( 'BWFCRM_Common' ) && empty( BWFCRM_Common::$captured_email_failed_message ) ) {
				BWFCRM_Common::$captured_email_failed_message = $e->getMessage();
			}
		}
		return (bool) $sent;
	}

	/**
	 * Run the body through bwfan_before_send_email_body, which 3.9 stopped doing for broadcasts.
	 *
	 * Up to FunnelKit Automations 3.8, a broadcast email was sent by the v1 sender in
	 * class-bwfan-wp-sendemail.php, which applies this filter. 3.9 moved broadcasts to
	 * BWFCRM_Broadcast_V2_Email_Controller and BWFAN_Mail_Handler_Controller, and neither
	 * applies it: the controller builds the body and passes it straight on, and the handler
	 * touches headers only. The filter is still applied on the automation path, so it did not
	 * disappear, it narrowed. Nothing errors and nothing is logged, so a site that was
	 * rewriting broadcast bodies through it simply stops, silently.
	 *
	 * Reported to FunnelKit in November 2026; the proper fix is one line in their own handler.
	 * Until that ships, this plugin is already standing in that exact spot on every site that
	 * uses it, so it applies the filter itself.
	 *
	 * The second argument is the per-mail array, which is not the shape the automation path
	 * passes. That path passes its own data blob, with keys like template, append_utm and
	 * utm_source. FunnelKit's only listener, BWFAN_UTM_Tracking::maybe_add_utm_parameters(),
	 * returns the body untouched unless append_utm is 1, which a broadcast's array never has,
	 * so it is a no-op here rather than a double-application. Broadcast UTM parameters are
	 * added by the controller through append_to_email_body_links() long before this point.
	 *
	 * Guarded, because this sits in the path of every broadcast email:
	 *   - chtq_apply_before_send_email_body turns it off without an update, for a bad day.
	 *   - A listener that throws must not take the send with it.
	 *   - A listener that returns a non-string, or nothing, must not blank the email.
	 *
	 * @param array $mail_data
	 * @return string The body to send.
	 */
	private function apply_body_filter( $mail_data ) {
		$body = isset( $mail_data['body'] ) ? $mail_data['body'] : '';

		if ( ! is_string( $body ) || '' === $body ) {
			return $body;
		}
		if ( ! apply_filters( 'chtq_apply_before_send_email_body', true, $mail_data ) ) {
			return $body;
		}

		try {
			$filtered = apply_filters( 'bwfan_before_send_email_body', $body, $mail_data );
		} catch ( Error|Exception $e ) {
			error_log( 'chtq: bwfan_before_send_email_body threw, sending the body unchanged: ' . $e->getMessage() );
			return $body;
		}

		if ( ! is_string( $filtered ) || '' === trim( $filtered ) ) {
			error_log( 'chtq: bwfan_before_send_email_body returned ' . gettype( $filtered ) . ', sending the body unchanged' );
			return $body;
		}

		return $filtered;
	}

	private function broadcast_id( $engagement_id ) {
		if ( ! $engagement_id ) {
			return 0;
		}
		if ( ! isset( self::$broadcast_of[ $engagement_id ] ) ) {
			global $wpdb;
			self::$broadcast_of[ $engagement_id ] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT oid FROM {$wpdb->prefix}bwfan_engagement_tracking WHERE ID = %d",
				$engagement_id
			) );
		}
		return self::$broadcast_of[ $engagement_id ];
	}
}
