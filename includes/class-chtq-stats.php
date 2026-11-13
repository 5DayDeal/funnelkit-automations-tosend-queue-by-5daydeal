<?php
/**
 * Performance figures for the status page: how long each large broadcast took to
 * send and how it was sent.
 *
 * Send time is measured the same way for every broadcast: from the first to the last
 * "sent" engagement record FunnelKit keeps for it. FunnelKit stamps those when the
 * email goes out, and this plugin re-stamps them with the moment toSend accepted
 * each queued email, so the figures are comparable before and after the plugin.
 *
 * Measuring a 55,000-recipient broadcast reads that many rows of the engagement
 * table (several seconds), so each finished broadcast is measured once, in the
 * background (refresh(), called from the cron tick), and saved. The status page only
 * reads saved figures.
 */

defined( 'ABSPATH' ) || exit;

final class CHTQ_Stats {

	const LARGE  = 10000;              // broadcasts this size or bigger are compared
	const KEEP   = 12;                 // most recent large broadcasts shown
	const OPTION = 'chtq_stats_rows';  // broadcast id => measured row (not autoloaded)

	const METHODS = array(
		'funnelkit' => 'FunnelKit only',
		'queue'     => 'toSend queue',
		'prebuilt'  => 'Pre-built + queue',
	);

	/** The most recent finished large broadcasts, oldest first. Cheap: broadcast table only. */
	private static function candidates() {
		global $wpdb;
		return array_reverse( $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title FROM {$wpdb->prefix}bwfan_broadcast WHERE type = 1 AND status = 4 AND count >= %d ORDER BY execution_time DESC LIMIT %d",
			self::LARGE, self::KEEP
		), ARRAY_A ) );
	}

	/**
	 * Measure broadcasts not measured yet, until the time budget runs out.
	 *
	 * @return int how many were measured
	 */
	public static function refresh( $budget_seconds = 15 ) {
		global $wpdb;
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$qt    = CHTQ::table();
		$jt    = CHTQ_Prebuild::jobs_table();
		$start = microtime( true );
		$done  = 0;
		foreach ( self::candidates() as $b ) {
			$id = (int) $b['id'];
			if ( isset( $saved[ $id ] ) ) {
				continue;
			}
			if ( microtime( true ) - $start > $budget_seconds ) {
				break;
			}
			// Still sending through the queue: measure once it has drained.
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $qt WHERE broadcast_id = %d AND status IN (0,1,6) LIMIT 1", $id ) ) ) {
				continue;
			}
			$e = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) sent, MIN(created_at) first_at, MAX(created_at) last_at FROM {$wpdb->prefix}bwfan_engagement_tracking WHERE oid = %d AND type = 2 AND c_status = 2",
				$id
			), ARRAY_A );
			$sent     = (int) ( $e['sent'] ?? 0 );
			$queued   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $qt WHERE broadcast_id = %d AND status = 2", $id ) );
			$prebuilt = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $jt WHERE broadcast_id = %d AND state IN ('done','v2_done') AND total > 0", $id ) );
			$secs     = ( $sent && $e['first_at'] ) ? max( 1, strtotime( $e['last_at'] . ' UTC' ) - strtotime( $e['first_at'] . ' UTC' ) ) : 0;
			$saved[ $id ] = array(
				'id'      => $id,
				'title'   => (string) $b['title'],
				'date'    => $e['first_at'],
				'sent'    => $sent,
				'seconds' => $secs,
				'rate'    => $secs ? $sent / $secs : 0,
				'method'  => $queued ? ( $prebuilt ? 'prebuilt' : 'queue' ) : 'funnelkit',
			);
			$done++;
		}
		if ( $done ) {
			update_option( self::OPTION, $saved, false );
		}
		return $done;
	}

	/** Saved figures plus cards. Never measures; see refresh(). */
	public static function data() {
		global $wpdb;
		$saved   = get_option( self::OPTION, array() );
		$saved   = is_array( $saved ) ? $saved : array();
		$rows    = array();
		$pending = 0;
		foreach ( self::candidates() as $b ) {
			$id = (int) $b['id'];
			if ( ! isset( $saved[ $id ] ) ) {
				$pending++;
			} elseif ( $saved[ $id ]['sent'] >= self::LARGE && $saved[ $id ]['seconds'] > 0 ) {
				$rows[] = $saved[ $id ];
			}
		}

		$before = array_filter( $rows, function ( $r ) {
			return 'funnelkit' === $r['method'];
		} );
		$after  = array_filter( $rows, function ( $r ) {
			return 'funnelkit' !== $r['method'];
		} );
		$avg    = function ( $set, $key ) {
			return $set ? array_sum( array_column( $set, $key ) ) / count( $set ) : 0;
		};

		$queue = $wpdb->get_row(
			'SELECT SUM(status = 2) sent, SUM(status IN (3,5)) problems, COUNT(DISTINCT broadcast_id) broadcasts, MIN(created_at) since FROM ' . CHTQ::table(),
			ARRAY_A
		);

		return array(
			'rows'    => $rows,
			'pending' => $pending,
			'cards'   => array(
				'before_secs' => $avg( $before, 'seconds' ),
				'before_rate' => $avg( $before, 'rate' ),
				'after_secs'  => $avg( $after, 'seconds' ),
				'after_rate'  => $avg( $after, 'rate' ),
				'fastest'     => $after ? min( array_column( $after, 'seconds' ) ) : 0,
				'before_n'    => count( $before ),
				'after_n'     => count( $after ),
			),
			'queue'   => array(
				'sent'       => (int) ( $queue['sent'] ?? 0 ),
				'problems'   => (int) ( $queue['problems'] ?? 0 ),
				'broadcasts' => (int) ( $queue['broadcasts'] ?? 0 ),
				'since'      => $queue['since'] ?? null,
			),
		);
	}

	/** "3 h 35 m", "6 m 4 s", "45 s" */
	public static function duration( $secs ) {
		$secs = (int) round( $secs );
		if ( $secs >= 3600 ) {
			return sprintf( '%d h %d m', intdiv( $secs, 3600 ), intdiv( $secs % 3600, 60 ) );
		}
		if ( $secs >= 60 ) {
			return sprintf( '%d m %d s', intdiv( $secs, 60 ), $secs % 60 );
		}
		return $secs . ' s';
	}
}
