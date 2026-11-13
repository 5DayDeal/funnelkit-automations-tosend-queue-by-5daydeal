<?php
/**
 * Every call this plugin makes into FunnelKit Automations lives here, so a
 * FunnelKit update is checked and fixed in one file.
 *
 * Pre-building only runs when compat_problems() is empty. If an update renames
 * or removes anything listed there, pre-building switches itself off and
 * broadcasts go out the way FunnelKit sends them (still through the queue).
 *
 * Written against FunnelKit Automations 3.8.5 / Pro 3.8.5.2.
 */

defined( 'ABSPATH' ) || exit;

final class CHTQ_FK {

	const STATUS_DRAFT     = 1;
	const STATUS_SCHEDULED = 2;
	const STATUS_RUNNING   = 3;
	const STATUS_COMPLETED = 4;
	const STATUS_HALTED    = 5;

	const CONV_DRAFT = 1;
	const CONV_SENT  = 2;

	/**
	 * FunnelKit 3.9+ sends broadcasts through its own batched flow (engagement records
	 * prepared ahead of time, a mail handler other plugins can replace). The queue plugs
	 * into that handler, and pre-building runs that flow early (CHTQ_Prebuild_V2).
	 */
	public static function is_v2_flow() {
		return class_exists( 'BWFCRM_Broadcast_Helper' ) && class_exists( 'BWFAN_Mail_Handler_Controller' );
	}

	/** What pre-building needs from FunnelKit. Empty array = all present. */
	public static function compat_problems() {
		if ( self::is_v2_flow() ) {
			return CHTQ_Prebuild_V2::compat_problems();
		}
		$need = array(
			'BWFCRM_Broadcast_Processing'     => array( 'get_instance', 'process_broadcast_contact_email', 'get_or_create_multiple_templates', 'get_broadcast_query_args', 'action_schedule_save_last_sent' ),
			'BWFAN_Model_Broadcast'           => array( 'get', 'update', 'update_campaign_offsets', 'update_status_to_complete', 'get_broadcast_tids_stats', 'declare_the_winner_email', 'remove_hold_flag_smart_sending' ),
			'BWFAN_Model_Engagement_Tracking' => array( 'get_specific_rows', 'update', 'query' ),
			'BWFCRM_Contact'                  => array( 'get_contacts' ),
			'BWFCRM_Load_Filters'             => array( 'load_filters' ),
			'BWFAN_PRO_Common'                => array( 'decode_stored_data_column' ),
		);
		$problems = array();
		foreach ( $need as $class => $methods ) {
			if ( ! class_exists( $class ) ) {
				$problems[] = "missing class $class";
				continue;
			}
			foreach ( $methods as $m ) {
				if ( ! method_exists( $class, $m ) ) {
					$problems[] = "missing $class::$m";
				}
			}
		}
		if ( ! function_exists( 'BWFCRM_Core' ) ) {
			$problems[] = 'missing BWFCRM_Core()';
		}
		return $problems;
	}

	public static function processor() {
		return BWFCRM_Broadcast_Processing::get_instance();
	}

	/**
	 * The broadcast row with `data` decoded the way FunnelKit decodes it before a
	 * send (get_formatted_campaign_data: stored-data decode, then admin-format filters).
	 */
	public static function broadcast( $id ) {
		$b = BWFAN_Model_Broadcast::get( absint( $id ) );
		if ( empty( $b ) || ! is_array( $b ) ) {
			return null;
		}
		$data = BWFAN_PRO_Common::decode_stored_data_column( $b['data'] ?? null );
		$data = is_array( $data ) ? $data : array();
		if ( ! empty( $data['filters'] ) && method_exists( 'BWFAN_PRO_Common', 'prepare_stored_filters_for_admin' ) ) {
			$data['filters'] = BWFAN_PRO_Common::prepare_stored_filters_for_admin( $data['filters'] );
		}
		$b['data'] = $data;
		return $b;
	}

	/** Email broadcasts FunnelKit has scheduled but not started. */
	public static function scheduled_email_broadcasts() {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}bwfan_broadcast WHERE status = %d AND type = 1 AND (parent IS NULL OR parent = 0)",
			self::STATUS_SCHEDULED
		) );
	}

	/**
	 * Turn content into FunnelKit template rows, exactly as FunnelKit does when a
	 * scheduled broadcast starts. Template ids are content-deterministic, so they
	 * match what FunnelKit would create.
	 */
	public static function content_with_templates( $content ) {
		return self::processor()->get_or_create_multiple_templates( $content, 1, true );
	}

	/**
	 * FunnelKit's own audience query arguments. The method is protected; calling it
	 * (rather than copying it) keeps the audience identical across FunnelKit updates.
	 */
	public static function query_args( $broadcast ) {
		$p = self::processor();
		$f = function ( $b ) {
			return $this->get_broadcast_query_args( $b, false );
		};
		return Closure::bind( $f, $p, get_class( $p ) )( $broadcast );
	}

	/**
	 * Every contact id in the broadcast's audience, in FunnelKit's send order
	 * (highest id first). Pages with end_id exactly as FunnelKit's sender does,
	 * and keeps going until a page comes back empty.
	 */
	public static function audience_ids( $broadcast, $page = 2000 ) {
		BWFCRM_Load_Filters::load_filters();
		$args = self::query_args( $broadcast );
		if ( ! is_array( $args ) ) {
			return new WP_Error( 'chtq_args', 'FunnelKit returned no query arguments' );
		}
		if ( ! isset( $args['props']['exclude_unsubs'] ) || false === $args['props']['exclude_unsubs'] ) {
			$args['props']['exclude_unsubs'] = apply_filters( 'bwfan_force_exclude_unsubscribe_contact', false );
		}
		$seen = array();
		$ids  = array();
		$end  = PHP_INT_MAX;
		while ( true ) {
			$props                   = $args['props'];
			$props['end_id']         = $end;
			$props['exclude_end_id'] = true;
			$props['grab_totals']    = false;
			$res = BWFCRM_Contact::get_contacts( '', 0, $page, $args['filters'], $props, OBJECT );
			if ( ! is_array( $res ) || ! isset( $res['contacts'] ) || ! is_array( $res['contacts'] ) ) {
				return new WP_Error( 'chtq_audience', 'FunnelKit contact query failed' );
			}
			if ( ! $res['contacts'] ) {
				break;
			}
			$batch = array();
			foreach ( $res['contacts'] as $c ) {
				$batch[] = (int) $c->get_id();
			}
			unset( $res );
			rsort( $batch );
			foreach ( $batch as $id ) {
				if ( ! isset( $seen[ $id ] ) ) {
					$seen[ $id ] = true;
					$ids[]       = $id;
				}
			}
			$next = end( $batch );
			if ( $next >= $end ) {
				return new WP_Error( 'chtq_audience', 'contact paging did not advance' );
			}
			$end = $next;
		}
		return $ids;
	}

	/**
	 * Build (and send through wp_mail, which the queue captures) one contact's email
	 * with FunnelKit's own per-contact function. $template_id forces the variant.
	 */
	public static function build_one( $broadcast, $contact_id, $template_id ) {
		$broadcast['data']['winner'] = (int) $template_id;
		self::processor()->process_broadcast_contact_email( $broadcast, (int) $contact_id );
	}

	/** The engagement row FunnelKit made for this email (hash + contact + broadcast). */
	public static function conversation_id_for_hash( $hash, $contact_id = 0, $broadcast_id = 0 ) {
		$rows = BWFAN_Model_Engagement_Tracking::get_specific_rows( 'hash_code', $hash );
		foreach ( (array) $rows as $r ) {
			if ( ( ! $contact_id || (int) $r['cid'] === (int) $contact_id ) && ( ! $broadcast_id || (int) $r['oid'] === (int) $broadcast_id ) ) {
				return (int) $r['ID'];
			}
		}
		return 0;
	}

	public static function conversation_to_draft( $conversation_id ) {
		return BWFCRM_Core()->conversation->update_conversation_status( (int) $conversation_id, self::CONV_DRAFT );
	}

	/**
	 * Mark sent at the real send time (FunnelKit shows created_at as the sent time).
	 * One statement for a whole batch, through FunnelKit's own model query wrapper.
	 */
	public static function conversations_sent_at( array $ids, $gmt_datetime ) {
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return;
		}
		BWFAN_Model_Engagement_Tracking::query(
			'UPDATE {table_name} SET c_status = %d, created_at = %s, updated_at = %s WHERE ID IN (' . implode( ',', $ids ) . ')',
			array( self::CONV_SENT, $gmt_datetime, $gmt_datetime )
		);
	}

	public static function conversation_failed( $conversation_id, $message ) {
		return BWFCRM_Core()->conversation->fail_the_conversation( (int) $conversation_id, $message );
	}

	/** Remove draft conversations that will never be sent, so reports are not skewed. Drafts only. */
	public static function delete_draft_conversations( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return;
		}
		$in    = implode( ',', $ids );
		$table = $wpdb->prefix . 'bwfan_engagement_tracking';
		$keep  = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $table WHERE ID IN ($in) AND c_status = %d", self::CONV_DRAFT ) );
		if ( ! $keep ) {
			return;
		}
		$in = implode( ',', array_map( 'intval', $keep ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE ID IN ($in) AND c_status = %d", self::CONV_DRAFT ) );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}bwfan_engagement_trackingmeta WHERE eid IN ($in)" );
	}

	/**
	 * Hand a pre-built broadcast to FunnelKit as running with nothing left to build,
	 * but only if it is still scheduled (atomic: FunnelKit starting it in the same
	 * second wins, and the caller then throws the pre-built copy away). Offset 1
	 * means FunnelKit's next fetch (id < 1) finds no one and it completes the
	 * broadcast itself through its normal path. Same fields as FunnelKit's
	 * update_status_to_run plus the processed count.
	 */
	public static function start_with_nothing_left( $broadcast_id, $data, $count, $processed, $park_until = null ) {
		$set = array(
			'offset'        => 1,
			'processed'     => (int) $processed,
			'data'          => wp_json_encode( $data ),
			'count'         => absint( $count ),
			'status'        => self::STATUS_RUNNING,
			'last_modified' => gmdate( 'Y-m-d H:i:s', time() - 6 ),
		);
		if ( $park_until ) {
			// Smart Sending: in the same statement, so FunnelKit never sees it running and due.
			$set['execution_time'] = $park_until;
		}
		$rows = BWFAN_Model_Broadcast::update( $set, array( 'id' => (int) $broadcast_id, 'status' => self::STATUS_SCHEDULED ) );
		return (int) $rows === 1;
	}

	/** Keep FunnelKit's running processor away from a broadcast until $gmt. */
	public static function park_until( $broadcast_id, $gmt_datetime ) {
		return BWFAN_Model_Broadcast::update( array( 'execution_time' => $gmt_datetime ), array( 'id' => (int) $broadcast_id ) );
	}

	public static function complete( $broadcast_id, $data, $processed, $count ) {
		// update_status_to_complete() sets count but not processed; set it first so the
		// broadcast shows every contact processed (Smart Sending sends the rest after release).
		BWFAN_Model_Broadcast::update_campaign_offsets( $broadcast_id, 1, $processed );
		BWFAN_Model_Broadcast::update_status_to_complete( $broadcast_id, $data, $processed, $count );
		BWFCRM_Broadcast_Processing::action_schedule_save_last_sent( $broadcast_id );
		delete_transient( 'bwfan_broadcast_stats' );
	}

	/** Smart Sending: FunnelKit's own winner pick and persistence. Returns winner tid or 0. */
	public static function decide_winner( $broadcast_id ) {
		$stats = BWFAN_Model_Broadcast::get_broadcast_tids_stats( $broadcast_id, 1 );
		if ( empty( $stats ) || empty( $stats['winner'] ) ) {
			BWFAN_Model_Broadcast::remove_hold_flag_smart_sending( $broadcast_id );
			return 0;
		}
		BWFAN_Model_Broadcast::declare_the_winner_email( $broadcast_id, $stats );
		return (int) $stats['winner'];
	}

	public static function daily_limit() {
		$g = class_exists( 'BWFAN_Common' ) ? BWFAN_Common::get_global_settings() : array();
		return isset( $g['bwfan_email_daily_limit'] ) ? absint( $g['bwfan_email_daily_limit'] ) : 10000;
	}
}
