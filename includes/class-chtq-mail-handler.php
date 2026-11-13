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
 */

defined( 'ABSPATH' ) || exit;

class CHTQ_Mail_Handler extends BWFAN_Mail_Handler_Controller {

	/** engagement id => broadcast id, so each email costs one primary-key lookup at most */
	private static $broadcast_of = array();

	public function send_mail( $mail_data ) {
		if ( false === $mail_data || ! is_array( $mail_data ) ) {
			return false;
		}
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
