<?php
/**
 * Pre-building: build a scheduled FunnelKit broadcast's emails ahead of time with
 * several workers, hold them, and release them all at the scheduled minute.
 *
 * FunnelKit stays in charge of everything it already does. This class only calls
 * FunnelKit's own functions (all of them in CHTQ_FK):
 *   - content -> templates:  FunnelKit's get_or_create_multiple_templates
 *   - audience:              FunnelKit's own broadcast query arguments
 *   - each email:            FunnelKit's process_broadcast_contact_email
 *   - A/B variant:           forced per contact by position (pos % n), which is
 *                            exactly FunnelKit's round robin (processed % n)
 *   - Smart Sending winner:  FunnelKit's get_broadcast_tids_stats + declare_the_winner_email
 *   - start/complete:        FunnelKit's broadcast model
 *
 * Job states:
 *   preparing -> building -> ready -> releasing -> released -> done
 *   Smart Sending:            ... releasing -> smart_wait -> smart_building -> done
 *   any unreleased -> discarded -> discarded_clean (content edited, cancelled, FunnelKit started it)
 *   skipped / error           (left to FunnelKit)
 *
 * Anything uncertain falls back to FunnelKit sending the broadcast itself.
 */

defined( 'ABSPATH' ) || exit;

final class CHTQ_Prebuild {

	const CLAIM_CHUNK   = 20;    // contacts a worker claims at once
	const LATEST_START  = 180;   // seconds: never start a job closer than this to send time
	const RECONCILE_AT  = 240;   // seconds before send: re-check the audience once
	const STALE_CLAIM   = 600;   // seconds: a claim older than this is from a dead worker
	const STALE_STEP    = 120;   // seconds: a job stuck in preparing/releasing this long is recovered
	const BUILD_PER_SEC = 10;    // per worker, for "is there time to rebuild" estimates
	const FLIP_CHUNK    = 5000;  // held -> queued per statement at release

	const UNRELEASED = array( 'preparing', 'building', 'ready' );

	public static function init() {
		// FunnelKit asks this filter at the start of every broadcast tick. Answering it
		// is the moment to hand over a due pre-built broadcast, right before FunnelKit's
		// own scheduled-broadcast step runs. The filter's value is never changed.
		add_filter( 'bwfcrm_disable_broadcast_run_queue', array( __CLASS__, 'on_funnelkit_tick' ), 1 );
		// FunnelKit 3.9 clears a reset broadcast's unsent records in this action (priority 10).
		add_action( 'bwfan_delete_unscheduled_broadcast_data', array( 'CHTQ_Prebuild_V2', 'before_cleanup' ), 5, 1 );
	}

	public static function jobs_table() {
		global $wpdb;
		return $wpdb->prefix . 'chtq_jobs';
	}

	public static function build_table() {
		global $wpdb;
		return $wpdb->prefix . 'chtq_build';
	}

	public static function install_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$j = self::jobs_table();
		$b = self::build_table();
		dbDelta( "CREATE TABLE $j (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			broadcast_id bigint(20) unsigned NOT NULL,
			state varchar(20) NOT NULL,
			fingerprint char(32) NOT NULL,
			execution_time datetime NULL,
			content longtext NULL,
			variants smallint(5) unsigned NOT NULL DEFAULT 1,
			total int(10) unsigned NOT NULL DEFAULT 0,
			test_size int(10) unsigned NOT NULL DEFAULT 0,
			smart_hours int(10) unsigned NOT NULL DEFAULT 0,
			winner_tid bigint(20) unsigned NOT NULL DEFAULT 0,
			reconciled tinyint(1) NOT NULL DEFAULT 0,
			released_at datetime NULL,
			winner_at datetime NULL,
			message text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY broadcast_state (broadcast_id,state),
			KEY state (state)
		) $c;" );
		dbDelta( "CREATE TABLE $b (
			job_id bigint(20) unsigned NOT NULL,
			contact_id bigint(20) unsigned NOT NULL,
			pos int(10) unsigned NOT NULL,
			phase tinyint(1) NOT NULL DEFAULT 1,
			status tinyint(1) NOT NULL DEFAULT 0,
			claim varchar(40) NULL,
			claimed_at datetime NULL,
			conversation_id bigint(20) unsigned NULL,
			PRIMARY KEY  (job_id,contact_id),
			KEY pick (job_id,status,phase,pos),
			KEY claim (claim)
		) $c;" );
	}

	/* ------------------------------------------------------------------ helpers */

	private static function now() {
		return current_time( 'mysql', true );
	}

	/** Job row. Without $with_content the large frozen content is not read. */
	public static function job( $id, $with_content = false ) {
		global $wpdb;
		$cols = $with_content ? '*' : self::cols();
		return $wpdb->get_row( $wpdb->prepare( "SELECT $cols FROM " . self::jobs_table() . ' WHERE id = %d', $id ), ARRAY_A );
	}

	private static function cols() {
		return 'id, broadcast_id, state, fingerprint, execution_time, variants, total, test_size, smart_hours, winner_tid, reconciled, released_at, winner_at, message, created_at, updated_at';
	}

	private static function jobs_in( array $states, $extra = '', array $args = array() ) {
		global $wpdb;
		$ph  = implode( ',', array_fill( 0, count( $states ), '%s' ) );
		$sql = 'SELECT ' . self::cols() . ' FROM ' . self::jobs_table() . " WHERE state IN ($ph) $extra";
		return $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $states, $args ) ), ARRAY_A );
	}

	private static function active_job_for( $broadcast_id ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::jobs_table() . " WHERE broadcast_id = %d AND state IN ('preparing','building','ready','releasing','released','smart_wait','smart_building') LIMIT 1",
			$broadcast_id
		) );
	}

	private static function set_state( $job_id, $state, $message = null, $from = null ) {
		global $wpdb;
		$sql = 'UPDATE ' . self::jobs_table() . ' SET state = %s, updated_at = %s';
		$arg = array( $state, self::now() );
		if ( null !== $message ) {
			$sql  .= ', message = %s';
			$arg[] = substr( wp_strip_all_tags( $message ), 0, 1000 );
		}
		$sql  .= ' WHERE id = %d';
		$arg[] = $job_id;
		if ( $from ) {
			$from = (array) $from;
			$sql .= ' AND state IN (' . implode( ',', array_fill( 0, count( $from ), '%s' ) ) . ')';
			$arg  = array_merge( $arg, $from );
		}
		return (int) $wpdb->query( $wpdb->prepare( $sql, $arg ) );
	}

	private static function age( $gmt ) {
		return time() - strtotime( $gmt . ' UTC' );
	}

	/** Content fields that change what recipients get, plus the audience. Not the send time. */
	public static function fingerprint( $data ) {
		$keep = array();
		foreach ( (array) ( $data['content'] ?? array() ) as $c ) {
			$keep['content'][] = array(
				$c['type'] ?? '', $c['subject'] ?? '', $c['body'] ?? '', $c['editor']['body'] ?? '',
				$c['preheader'] ?? '', $c['utmEnabled'] ?? '', $c['utm'] ?? '',
			);
		}
		foreach ( array( 'senders_name', 'senders_email', 'replyto', 'filters', 'exclude', 'smart_send', 'is_promotional', 'includeSoftBounce', 'includeUnverified' ) as $k ) {
			$keep[ $k ] = $data[ $k ] ?? null;
		}
		return md5( wp_json_encode( $keep ) );
	}

	/** Why this broadcast should be left to FunnelKit, or '' if it can be pre-built. */
	private static function unsuitable( $b ) {
		$d = $b['data'];
		if ( ! empty( $b['parent'] ) ) {
			return 'sends to non-openers of another broadcast; the audience is only known at send time';
		}
		if ( empty( $d['content'] ) || ! is_array( $d['content'] ) ) {
			return 'no content';
		}
		foreach ( $d['content'] as $c ) {
			$text = ( $c['subject'] ?? '' ) . ( $c['body'] ?? '' ) . ( $c['editor']['body'] ?? '' );
			if ( false !== stripos( $text, 'create_coupon' ) ) {
				return 'creates a coupon per contact, which must happen at send time';
			}
		}
		return '';
	}

	public static function enabled() {
		$s = CHTQ::settings( true );
		return ! empty( $s['enabled'] ) && ! empty( $s['prebuild'] ) && ! CHTQ_FK::compat_problems();
	}

	/* ------------------------------------------------------------------ tick */

	/**
	 * Run every minute from cron (`wp chtq tick`, under flock): recover stuck steps,
	 * notice edits and cancellations, find newly scheduled broadcasts, re-check the
	 * audience before send, release due jobs if FunnelKit's own tick has not yet,
	 * run Smart Sending, sweep strays, tidy up.
	 */
	public static function tick( $log = null ) {
		$log = $log ?: function () {};
		$s   = CHTQ::settings( true );
		if ( empty( $s['enabled'] ) || empty( $s['prebuild'] ) ) {
			self::discard_all_unreleased( 'pre-building was switched off', $log );
		} elseif ( CHTQ_FK::is_v2_flow() ) {
			// FunnelKit 3.9+: 3.8-style jobs are handed back; CHTQ_Prebuild_V2 does the building.
			self::discard_all_unreleased( 'FunnelKit was updated to 3.9', $log );
			self::hand_back_running( 'FunnelKit was updated to 3.9', $log );
		} elseif ( $problems = CHTQ_FK::compat_problems() ) {
			self::discard_all_unreleased( 'FunnelKit changed: ' . implode( '; ', $problems ), $log );
			self::hand_back_running( 'FunnelKit changed: ' . implode( '; ', $problems ), $log );
			$log( 'FunnelKit compatibility problem, pre-building paused: ' . implode( '; ', $problems ) );
		} else {
			self::requeue_stale_claims();
			self::recover_stuck( $log );
			self::watch_jobs( $log );
			self::discover( $log );
			self::release_due( $log );
			self::smart_sending( $log );
		}
		if ( CHTQ_FK::is_v2_flow() ) {
			CHTQ_Prebuild_V2::tick( $log ); // also discards its jobs when pre-building is off
		}
		// These run even when pre-building is off, so released jobs always finish cleanly.
		self::sweep_strays( $log );
		self::finish( $log );
		self::cleanup_discarded( $log );
		// Measure finished large broadcasts for the status page, a few seconds a minute at most.
		$n = CHTQ_Stats::refresh( 15 );
		if ( $n ) {
			$log( "measured $n broadcast(s) for the status page" );
		}
	}

	/** New scheduled broadcasts become jobs. */
	private static function discover( $log ) {
		$min = (int) CHTQ::settings( true )['prebuild_min'];
		foreach ( CHTQ_FK::scheduled_email_broadcasts() as $bid ) {
			$bid = (int) $bid;
			if ( self::active_job_for( $bid ) ) {
				continue;
			}
			$b = CHTQ_FK::broadcast( $bid );
			if ( ! $b ) {
				continue;
			}
			$fp   = self::fingerprint( $b['data'] );
			$left = strtotime( $b['execution_time'] . ' UTC' ) - time();
			if ( $left < self::LATEST_START ) {
				continue; // too close to send time; FunnelKit sends it
			}
			if ( self::already_decided( $bid, $fp ) ) {
				continue;
			}
			if ( (int) $b['count'] < $min ) {
				self::record( $bid, $fp, $b, 'skipped', "fewer than $min recipients" );
				continue;
			}
			if ( $why = self::unsuitable( $b ) ) {
				self::record( $bid, $fp, $b, 'skipped', $why );
				$log( "broadcast $bid left to FunnelKit: $why" );
				continue;
			}
			$res = self::create_job( $b, $fp );
			$log( is_wp_error( $res ) ? "broadcast $bid: could not start pre-build: " . $res->get_error_message() : "broadcast $bid: pre-build job $res started" );
		}
	}

	/** A skip or error for this exact content was already recorded; do not retry it every minute. */
	private static function already_decided( $bid, $fp ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM ' . self::jobs_table() . " WHERE broadcast_id = %d AND fingerprint = %s AND state IN ('skipped','error') LIMIT 1",
			$bid, $fp
		) );
	}

	private static function record( $bid, $fp, $b, $state, $why ) {
		global $wpdb;
		$wpdb->insert( self::jobs_table(), array(
			'broadcast_id' => $bid, 'state' => $state, 'fingerprint' => $fp, 'execution_time' => $b['execution_time'],
			'message' => substr( wp_strip_all_tags( $why ), 0, 1000 ), 'created_at' => self::now(), 'updated_at' => self::now(),
		) );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Freeze the content into FunnelKit templates, list the audience in FunnelKit's
	 * order, and build one contact per A/B variant here (so FunnelKit's link-tracking
	 * rows exist before several workers race to create them). The job only becomes
	 * `building` once every contact row is in. Returns the job id or WP_Error.
	 */
	public static function create_job( $b, $fp = null ) {
		global $wpdb;
		$bid  = (int) $b['id'];
		$fp   = $fp ?: self::fingerprint( $b['data'] );
		$data = $b['data'];

		$content = CHTQ_FK::content_with_templates( $data['content'] );
		if ( ! is_array( $content ) || ! $content ) {
			return self::fail( $bid, $fp, $b, 'FunnelKit returned no templates' );
		}
		foreach ( $content as $c ) {
			if ( empty( $c['template_id'] ) ) {
				return self::fail( $bid, $fp, $b, 'FunnelKit did not return template ids' );
			}
		}

		$ids = CHTQ_FK::audience_ids( $b );
		if ( is_wp_error( $ids ) ) {
			return self::fail( $bid, $fp, $b, $ids->get_error_message() );
		}
		if ( ! $ids ) {
			return self::fail( $bid, $fp, $b, 'audience is empty' );
		}

		$total = count( $ids );
		$n     = count( $content );
		$smart = isset( $data['smart_send']['enable'] ) && true === $data['smart_send']['enable'] && $n > 1;
		$test  = $total;
		$hours = 0;
		if ( $smart ) {
			// FunnelKit holds once absint(processed / count * 100) reaches the percentage,
			// before sending that contact: the test group is positions 0 .. k-1.
			$pct  = max( 1, min( 100, absint( $data['smart_send']['percent'] ?? 0 ) ) );
			$test = 0;
			while ( $test < $total && absint( $test / $total * 100 ) < $pct ) {
				$test++;
			}
			$hours = absint( $data['smart_send']['hours'] ?? 0 );
		}

		$wpdb->insert( self::jobs_table(), array(
			'broadcast_id'   => $bid,
			'state'          => 'preparing',
			'fingerprint'    => $fp,
			'execution_time' => $b['execution_time'],
			'content'        => wp_json_encode( $content ),
			'variants'       => $n,
			'total'          => $total,
			'test_size'      => $test,
			'smart_hours'    => $hours,
			'created_at'     => self::now(),
			'updated_at'     => self::now(),
		) );
		$job_id = (int) $wpdb->insert_id;
		if ( ! $job_id ) {
			return new WP_Error( 'chtq_job', 'could not save job: ' . $wpdb->last_error );
		}

		$bt = self::build_table();
		foreach ( array_chunk( $ids, 1000, true ) as $chunk ) {
			$vals = array();
			foreach ( $chunk as $pos => $cid ) {
				$vals[] = $wpdb->prepare( '(%d,%d,%d,%d,0)', $job_id, $cid, $pos, ( $pos < $test ) ? 1 : 2 );
			}
			if ( false === $wpdb->query( "INSERT IGNORE INTO $bt (job_id,contact_id,pos,phase,status) VALUES " . implode( ',', $vals ) ) ) {
				self::set_state( $job_id, 'error', 'could not list the audience: ' . $wpdb->last_error );
				$wpdb->query( $wpdb->prepare( "DELETE FROM $bt WHERE job_id = %d", $job_id ) );
				return new WP_Error( 'chtq_job', 'could not list the audience' );
			}
		}
		$listed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $bt WHERE job_id = %d", $job_id ) );
		if ( $listed !== $total ) {
			self::set_state( $job_id, 'error', "listed $listed of $total contacts" );
			$wpdb->query( $wpdb->prepare( "DELETE FROM $bt WHERE job_id = %d", $job_id ) );
			return new WP_Error( 'chtq_job', "listed $listed of $total contacts" );
		}
		self::set_state( $job_id, 'building', null, 'preparing' );

		self::work( $job_id, min( $n, $test ) ); // warm FunnelKit's link-tracking rows, one per variant
		return $job_id;
	}

	private static function fail( $bid, $fp, $b, $msg ) {
		self::record( $bid, $fp, $b, 'error', $msg );
		return new WP_Error( 'chtq_job', $msg );
	}

	/** Steps a crashed process left half done. */
	private static function recover_stuck( $log ) {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STALE_STEP );
		foreach ( self::jobs_in( array( 'preparing' ), 'AND updated_at < %s', array( $cutoff ) ) as $job ) {
			self::set_state( $job['id'], 'error', 'listing the audience did not finish', 'preparing' );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::build_table() . ' WHERE job_id = %d', $job['id'] ) );
			$log( "job {$job['id']}: listing did not finish; left to FunnelKit" );
		}
		foreach ( self::jobs_in( array( 'releasing' ), 'AND updated_at < %s', array( $cutoff ) ) as $job ) {
			$b = CHTQ_FK::broadcast( $job['broadcast_id'] );
			if ( $b && CHTQ_FK::STATUS_SCHEDULED === (int) $b['status'] ) {
				// The hand-over never happened: try again.
				self::set_state( $job['id'], 'ready', 'release retried', 'releasing' );
				$log( "job {$job['id']}: release did not happen; retrying" );
			} elseif ( $b && CHTQ_FK::STATUS_RUNNING === (int) $b['status'] && (int) $b['offset'] <= 1 ) {
				// Handed over, but the held emails were not let go: finish the release.
				self::let_go( $job, $log );
			} else {
				self::discard( $job, 'release did not finish and the broadcast moved on (status ' . ( $b ? (int) $b['status'] : 'deleted' ) . ')', $log );
			}
		}
	}

	/** Notice edits, reschedules, cancellations and FunnelKit starting the send itself. */
	private static function watch_jobs( $log ) {
		global $wpdb;
		foreach ( self::jobs_in( array( 'building', 'ready' ) ) as $job ) {
			$b = CHTQ_FK::broadcast( $job['broadcast_id'] );
			if ( ! $b ) {
				self::discard( $job, 'broadcast was deleted', $log );
				continue;
			}
			$status = (int) $b['status'];
			if ( CHTQ_FK::STATUS_SCHEDULED !== $status ) {
				$why = CHTQ_FK::STATUS_HALTED === $status
					? 'FunnelKit halted the broadcast while building it (see its halt reason); it will not send until fixed'
					: "broadcast is no longer scheduled (status $status)";
				if ( CHTQ_FK::STATUS_HALTED === $status ) {
					error_log( "chtq: broadcast {$job['broadcast_id']} was halted by FunnelKit during pre-build" );
				}
				self::discard( $job, $why, $log );
				continue;
			}
			if ( self::fingerprint( $b['data'] ) !== $job['fingerprint'] ) {
				// discover() rebuilds it on a later tick if there is still time.
				self::discard( $job, 'content or audience changed after it was built; rebuilding', $log );
				continue;
			}
			if ( $b['execution_time'] !== $job['execution_time'] ) {
				$wpdb->update( self::jobs_table(), array( 'execution_time' => $b['execution_time'], 'reconciled' => 0, 'updated_at' => self::now() ), array( 'id' => $job['id'] ) );
				$job['execution_time'] = $b['execution_time'];
			}
			if ( 'building' === $job['state'] && ! self::pending( $job['id'], 1 ) ) {
				self::set_state( $job['id'], 'ready', null, 'building' );
				$log( "job {$job['id']}: built, waiting for {$job['execution_time']} UTC" );
				$job['state'] = 'ready';
			}
			$left = strtotime( $job['execution_time'] . ' UTC' ) - time();
			if ( ! $job['reconciled'] && $left <= self::RECONCILE_AT && $left > 30 ) {
				self::reconcile( $job, $b, $log );
			}
		}
	}

	/** Unbuilt (or claimed) contacts of a phase or lower. */
	private static function pending( $job_id, $max_phase ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::build_table() . ' WHERE job_id = %d AND status IN (0,1) AND phase <= %d',
			$job_id, $max_phase
		) );
	}

	/**
	 * Just before send time, re-run FunnelKit's audience query: drop people who left
	 * (unsubscribed, bounced, removed from the list) and build anyone who joined.
	 * Contacts a worker is building right now are left alone; the drain still skips
	 * anyone unsubscribed at send time.
	 */
	private static function reconcile( $job, $b, $log ) {
		global $wpdb;
		$ids = CHTQ_FK::audience_ids( $b );
		if ( is_wp_error( $ids ) ) {
			$log( "job {$job['id']}: audience re-check failed: " . $ids->get_error_message() );
			return;
		}
		$now_set = array_flip( $ids );
		$bt      = self::build_table();
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT contact_id, status, conversation_id FROM $bt WHERE job_id = %d", $job['id'] ), ARRAY_A );
		$have    = array();
		$gone    = array();
		$convs   = array();
		foreach ( $rows as $r ) {
			$cid          = (int) $r['contact_id'];
			$have[ $cid ] = true;
			if ( ! isset( $now_set[ $cid ] ) && in_array( (int) $r['status'], array( 0, 2 ), true ) ) {
				$gone[] = $cid;
				if ( $r['conversation_id'] ) {
					$convs[] = (int) $r['conversation_id'];
				}
			}
		}
		unset( $rows );
		foreach ( array_chunk( $gone, 1000 ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . CHTQ::table() . " WHERE broadcast_id = %d AND contact_id IN ($in) AND job_id = %d AND status = %d", $job['broadcast_id'], $job['id'], CHTQ::STATUS_HLD ) );
			$wpdb->query( $wpdb->prepare( "UPDATE $bt SET status = 4, conversation_id = NULL WHERE job_id = %d AND contact_id IN ($in) AND status IN (0,2)", $job['id'] ) );
		}
		foreach ( array_chunk( $convs, 1000 ) as $chunk ) {
			CHTQ_FK::delete_draft_conversations( $chunk );
		}

		$pos   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(pos) FROM $bt WHERE job_id = %d", $job['id'] ) );
		$phase = $job['test_size'] < $job['total'] ? 2 : 1;
		$new   = array();
		foreach ( $ids as $cid ) {
			if ( ! isset( $have[ $cid ] ) ) {
				$new[] = $wpdb->prepare( '(%d,%d,%d,%d,0)', $job['id'], $cid, ++$pos, $phase );
			}
		}
		foreach ( array_chunk( $new, 1000 ) as $chunk ) {
			$wpdb->query( "INSERT IGNORE INTO $bt (job_id,contact_id,pos,phase,status) VALUES " . implode( ',', $chunk ) );
		}
		$wpdb->update( self::jobs_table(), array( 'reconciled' => 1, 'updated_at' => self::now() ), array( 'id' => $job['id'] ) );
		if ( $new && 1 === $phase && 'ready' === $job['state'] ) {
			self::set_state( $job['id'], 'building', null, 'ready' );
		}
		$log( "job {$job['id']}: audience re-checked, " . count( $gone ) . ' left, ' . count( $new ) . ' joined' );
	}

	/* ------------------------------------------------------------------ release */

	/** FunnelKit's broadcast tick is starting. Hand over anything due. Never changes the filter value. */
	public static function on_funnelkit_tick( $disabled ) {
		if ( $disabled ) {
			return $disabled;
		}
		try {
			if ( self::enabled() ) {
				CHTQ_FK::is_v2_flow() ? CHTQ_Prebuild_V2::release_due() : self::release_due();
			}
		} catch ( Throwable $e ) {
			error_log( 'chtq release failed: ' . $e->getMessage() );
		}
		return $disabled;
	}

	public static function release_due( $log = null ) {
		$log = $log ?: function () {};
		foreach ( self::jobs_in( array( 'building', 'ready' ), 'AND execution_time <= %s', array( self::now() ) ) as $job ) {
			self::release( $job, $log );
		}
	}

	private static function release( $job, $log ) {
		global $wpdb;
		$b = CHTQ_FK::broadcast( $job['broadcast_id'] );
		if ( ! $b || CHTQ_FK::STATUS_SCHEDULED !== (int) $b['status'] ) {
			self::discard( $job, 'broadcast not scheduled at release time', $log );
			return;
		}
		if ( self::fingerprint( $b['data'] ) !== $job['fingerprint'] ) {
			// Edited in the last minute. FunnelKit sends it itself, in this same tick.
			self::discard( $job, 'content changed just before send; FunnelKit sent it', $log );
			return;
		}
		if ( ! self::set_state( $job['id'], 'releasing', null, array( 'building', 'ready' ) ) ) {
			return; // another process is releasing it
		}

		$full            = self::job( $job['id'], true );
		$data            = $b['data'];
		$data['content'] = json_decode( $full['content'], true );
		$built           = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::build_table() . ' WHERE job_id = %d AND status = 2', $job['id'] ) );
		$smart           = $job['test_size'] < $job['total'];

		// Park it so FunnelKit leaves it running (shown under Ongoing) while the queue
		// sends; finish() completes it when the last email is accepted. If this plugin
		// ever stops, FunnelKit completes it itself once the park time passes.
		// Smart Sending also waits for its winner first.
		$park = gmdate( 'Y-m-d H:i:s', time() + ( ( $smart ? (int) $job['smart_hours'] : 0 ) + 24 ) * HOUR_IN_SECONDS );
		if ( ! CHTQ_FK::start_with_nothing_left( $job['broadcast_id'], $data, (int) $job['total'], $built, $park ) ) {
			// FunnelKit started it in the same moment: its copy wins, ours goes.
			$job['state'] = 'releasing';
			self::discard( $job, 'FunnelKit started the broadcast at the same moment; FunnelKit sent it', $log );
			return;
		}
		self::let_go( $job, $log );
	}

	/** Held emails become sendable, in chunks so the drain can start at once. */
	private static function let_go( $job, $log ) {
		global $wpdb;
		$released = 0;
		do {
			$n = (int) $wpdb->query( $wpdb->prepare(
				'UPDATE ' . CHTQ::table() . ' SET status = %d WHERE job_id = %d AND status = %d ORDER BY id LIMIT %d',
				CHTQ::STATUS_Q, $job['id'], CHTQ::STATUS_HLD, self::FLIP_CHUNK
			) );
			$released += $n;
		} while ( $n > 0 );
		$smart = $job['test_size'] < $job['total'];
		$wpdb->update( self::jobs_table(), array(
			'state'       => $smart ? 'smart_wait' : 'released',
			'released_at' => self::now(),
			'winner_at'   => $smart ? gmdate( 'Y-m-d H:i:s', time() + (int) $job['smart_hours'] * HOUR_IN_SECONDS ) : null,
			'updated_at'  => self::now(),
		), array( 'id' => $job['id'], 'state' => 'releasing' ) );
		$log( "job {$job['id']}: released $released emails for broadcast {$job['broadcast_id']}" );
	}

	/* ------------------------------------------------------------------ Smart Sending */

	private static function smart_sending( $log ) {
		global $wpdb;
		foreach ( self::jobs_in( array( 'smart_wait' ), 'AND winner_at <= %s', array( self::now() ) ) as $job ) {
			if ( ! self::set_state( $job['id'], 'smart_building', null, 'smart_wait' ) ) {
				continue;
			}
			$tid = CHTQ_FK::decide_winner( (int) $job['broadcast_id'] );
			$wpdb->update( self::jobs_table(), array( 'winner_tid' => $tid, 'updated_at' => self::now() ), array( 'id' => $job['id'] ) );
			$log( "job {$job['id']}: Smart Sending " . ( $tid ? "winner is template $tid" : 'found no winner; the rest alternate' ) );
		}
	}

	/**
	 * Released jobs with nothing left to build or send are complete: mark the
	 * broadcast completed in FunnelKit at that moment, so its Ongoing tab is accurate.
	 * If FunnelKit's functions are unavailable, un-park it instead and FunnelKit
	 * completes it itself on its next tick (offset 1: nobody left to send to).
	 */
	private static function finish( $log ) {
		global $wpdb;
		foreach ( self::jobs_in( array( 'released', 'smart_building' ) ) as $job ) {
			if ( self::pending( $job['id'], 2 ) ) {
				continue;
			}
			$queued = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CHTQ::table() . ' WHERE job_id = %d AND status IN (0,1,6)', $job['id'] ) );
			if ( $queued ) {
				continue;
			}
			$b = CHTQ_FK::broadcast( $job['broadcast_id'] );
			$smart = 'smart_building' === $job['state'];
			if ( $b && CHTQ_FK::STATUS_RUNNING === (int) $b['status'] ) {
				$built = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::build_table() . ' WHERE job_id = %d AND status = 2', $job['id'] ) );
				try {
					// Put the original send time back: release parked it a day ahead.
					CHTQ_FK::park_until( (int) $job['broadcast_id'], $job['execution_time'] );
					CHTQ_FK::complete( (int) $job['broadcast_id'], $b['data'], $built, (int) $job['total'] );
				} catch ( Throwable $e ) {
					$log( "job {$job['id']}: could not complete in FunnelKit ({$e->getMessage()}); letting FunnelKit complete it" );
					CHTQ_FK::park_until( (int) $job['broadcast_id'], self::now() );
				}
			}
			self::set_state( $job['id'], 'done', null, array( 'released', 'smart_building' ) );
			$log( "job {$job['id']}: done" );
		}
	}

	/**
	 * FunnelKit changed while a released broadcast still had contacts to build (Smart
	 * Sending waiting for its winner, or late top-ups). Hand the rest back to FunnelKit
	 * so its own sender finishes the job: its bookmark (offset) goes to just past the
	 * lowest contact we already handled, since FunnelKit sends to ids below the offset,
	 * highest first, in the same order we built. Smart Sending then runs FunnelKit's own
	 * hold and winner logic (processed is set to what we sent). Contacts already built
	 * stay ours, so nobody gets two copies.
	 */
	private static function hand_back_running( $why, $log ) {
		global $wpdb;
		$bt = self::build_table();
		foreach ( self::jobs_in( array( 'released', 'smart_wait', 'smart_building' ) ) as $job ) {
			$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $bt WHERE job_id = %d AND status IN (0,1)", $job['id'] ) );
			if ( ! $left ) {
				continue; // fully built: finish() completes it (or un-parks it) once the queue drains
			}
			$done   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $bt WHERE job_id = %d AND status IN (2,3,4)", $job['id'] ) );
			$lowest = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(contact_id) FROM $bt WHERE job_id = %d AND status IN (2,3,4)", $job['id'] ) );
			if ( ! $lowest ) {
				$lowest = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(contact_id) FROM $bt WHERE job_id = %d", $job['id'] ) );
			}
			if ( ! self::set_state( $job['id'], 'handed_back', $why . "; FunnelKit sends the remaining $left", array( 'released', 'smart_wait', 'smart_building' ) ) ) {
				continue;
			}
			$wpdb->query( $wpdb->prepare( "UPDATE $bt SET status = 4, claim = NULL WHERE job_id = %d AND status IN (0,1)", $job['id'] ) );
			try {
				BWFAN_Model_Broadcast::update(
					array( 'offset' => $lowest, 'processed' => $done, 'status' => CHTQ_FK::STATUS_RUNNING, 'execution_time' => self::now(), 'last_modified' => gmdate( 'Y-m-d H:i:s', time() - 6 ) ),
					array( 'id' => (int) $job['broadcast_id'] )
				);
				$log( "job {$job['id']}: handed $left contacts back to FunnelKit (offset $lowest)" );
			} catch ( Throwable $e ) {
				error_log( "chtq: could not hand broadcast {$job['broadcast_id']} back to FunnelKit: " . $e->getMessage() );
				$log( "job {$job['id']}: HAND-BACK FAILED for broadcast {$job['broadcast_id']}; $left contacts not sent" );
			}
		}
	}

	/* ------------------------------------------------------------------ discard */

	/**
	 * Throw a pre-built copy away. Held emails are deleted at once (so FunnelKit can
	 * queue those contacts itself without tripping the duplicate check); their draft
	 * conversation records are removed by cleanup_discarded() on later ticks.
	 */
	public static function discard( $job, $why, $log = null ) {
		global $wpdb;
		if ( ! self::set_state( $job['id'], 'discarded', $why, array( 'preparing', 'building', 'ready', 'releasing' ) ) ) {
			return;
		}
		do {
			$n = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . CHTQ::table() . ' WHERE job_id = %d AND status = %d LIMIT %d', $job['id'], CHTQ::STATUS_HLD, self::FLIP_CHUNK ) );
		} while ( $n > 0 );
		if ( $log ) {
			$log( "job {$job['id']}: discarded ($why)" );
		}
	}

	private static function discard_all_unreleased( $why, $log ) {
		foreach ( self::jobs_in( self::UNRELEASED ) as $job ) {
			self::discard( $job, $why, $log );
		}
	}

	/**
	 * Held rows that slipped past a release or a discard (a worker was mid-build at
	 * that moment): send the ones whose job is released, delete the ones whose job
	 * was thrown away.
	 */
	private static function sweep_strays( $log ) {
		global $wpdb;
		$q  = CHTQ::table();
		$jt = self::jobs_table();
		$let = (int) $wpdb->query( $wpdb->prepare(
			"UPDATE $q q JOIN $jt j ON j.id = q.job_id SET q.status = %d WHERE q.status = %d AND j.state IN ('released','smart_wait','smart_building','handed_back','done','v2_catchup','v2_released','v2_done')",
			CHTQ::STATUS_Q, CHTQ::STATUS_HLD
		) );
		$del = (int) $wpdb->query( $wpdb->prepare(
			"DELETE q FROM $q q JOIN $jt j ON j.id = q.job_id WHERE q.status = %d AND j.state IN ('discarded','discarded_clean','error')",
			CHTQ::STATUS_HLD
		) );
		if ( $let || $del ) {
			$log( "swept stray held emails: $let released, $del deleted" );
		}
	}

	/** Remove the draft conversation records of discarded jobs, a chunk per tick. */
	private static function cleanup_discarded( $log ) {
		global $wpdb;
		$bt = self::build_table();
		foreach ( self::jobs_in( array( 'discarded' ) ) as $job ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT contact_id, conversation_id FROM $bt WHERE job_id = %d AND conversation_id IS NOT NULL LIMIT 2000", $job['id'] ), ARRAY_A );
			if ( ! $rows ) {
				self::set_state( $job['id'], 'discarded_clean', null, 'discarded' );
				continue;
			}
			CHTQ_FK::delete_draft_conversations( wp_list_pluck( $rows, 'conversation_id' ) );
			$in = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'contact_id' ) ) );
			$wpdb->query( $wpdb->prepare( "UPDATE $bt SET conversation_id = NULL WHERE job_id = %d AND contact_id IN ($in)", $job['id'] ) );
			$log( "job {$job['id']}: removed " . count( $rows ) . ' draft records' );
			return; // one chunk per tick
		}
	}

	private static function requeue_stale_claims() {
		global $wpdb;
		// Safe: the queue's unique (broadcast, contact) key stops a second copy.
		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::build_table() . ' SET status = 0, claim = NULL WHERE status = 1 AND claimed_at < %s',
			gmdate( 'Y-m-d H:i:s', time() - self::STALE_CLAIM )
		) );
	}

	/* ------------------------------------------------------------------ workers */

	/**
	 * Build contacts until there is nothing to do. Run several at once (`wp chtq work`
	 * from cron, one flock per slot). With $only_job and $limit, builds just that many
	 * contacts of that job, lowest positions first (used for the per-variant warm-up).
	 */
	public static function work( $only_job = 0, $limit = 0, $idle = 20, $log = null ) {
		global $wpdb;
		$bt      = self::build_table();
		$built   = 0;
		$idle_at = null;
		$cache   = array();

		while ( true ) {
			$jobs = $only_job
				? array_filter( array( self::job( $only_job ) ) )
				: self::jobs_in( array( 'building', 'ready', 'released', 'smart_wait', 'smart_building' ), 'ORDER BY execution_time' );
			$did = false;
			foreach ( $jobs as $job ) {
				$max_phase = ( 'smart_building' === $job['state'] ) ? 2 : 1;
				$take      = $limit ? min( self::CLAIM_CHUNK, $limit - $built ) : self::CLAIM_CHUNK;
				if ( $take <= 0 ) {
					return $built;
				}
				$claim = wp_generate_uuid4();
				$n     = 0;
				for ( $phase = 1; $phase <= $max_phase && ! $n; $phase++ ) {
					$n = (int) $wpdb->query( $wpdb->prepare(
						"UPDATE $bt SET status = 1, claim = %s, claimed_at = %s WHERE job_id = %d AND status = 0 AND phase = %d ORDER BY pos LIMIT %d",
						$claim, self::now(), $job['id'], $phase, $take
					) );
				}
				if ( ! $n ) {
					continue;
				}
				$did = true;
				if ( ! isset( $cache[ $job['id'] ] ) ) {
					$b    = CHTQ_FK::broadcast( $job['broadcast_id'] );
					$full = self::job( $job['id'], true );
					if ( ! $b || ! $full ) {
						$wpdb->query( $wpdb->prepare( "UPDATE $bt SET status = 0, claim = NULL WHERE claim = %s AND status = 1", $claim ) );
						continue;
					}
					$b['data']['content'] = json_decode( $full['content'], true );
					$cache[ $job['id'] ]  = $b;
				}
				// One state read per chunk; a release or discard meanwhile is caught by sweep_strays().
				$fresh = $wpdb->get_row( $wpdb->prepare( 'SELECT state, winner_tid FROM ' . self::jobs_table() . ' WHERE id = %d', $job['id'] ), ARRAY_A );
				if ( ! $fresh || in_array( $fresh['state'], array( 'discarded', 'discarded_clean', 'releasing', 'error' ), true ) ) {
					$wpdb->query( $wpdb->prepare( "UPDATE $bt SET status = 0, claim = NULL WHERE claim = %s AND status = 1", $claim ) );
					break;
				}
				$job = array_merge( $job, $fresh );
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT contact_id, pos FROM $bt WHERE claim = %s ORDER BY pos", $claim ), ARRAY_A );
				foreach ( $rows as $r ) {
					self::build_contact( $job, $cache[ $job['id'] ], (int) $r['contact_id'], (int) $r['pos'] );
					$built++;
				}
				break; // re-read jobs: earliest send time first
			}
			if ( $limit && $built >= $limit ) {
				return $built;
			}
			if ( ! $did ) {
				if ( $only_job ) {
					return $built;
				}
				$idle_at = $idle_at ?: time();
				if ( time() - $idle_at >= $idle ) {
					return $built;
				}
				sleep( 2 );
				continue;
			}
			$idle_at = null;
			if ( $log && $built % 200 < self::CLAIM_CHUNK ) {
				$log( gmdate( 'H:i:s' ) . " built $built" );
			}
		}
	}

	private static function build_contact( $job, $broadcast, $cid, $pos ) {
		global $wpdb;
		$content = $broadcast['data']['content'];
		$n       = max( 1, count( $content ) );
		$tid     = ( 'smart_building' === $job['state'] && (int) $job['winner_tid'] ) ? (int) $job['winner_tid'] : (int) $content[ $pos % $n ]['template_id'];
		$hold    = in_array( $job['state'], array( 'building', 'ready' ), true );

		CHTQ::$context      = array( 'job_id' => (int) $job['id'], 'broadcast_id' => (int) $job['broadcast_id'], 'contact_id' => $cid, 'hold' => $hold );
		CHTQ::$last_capture = null;
		$error              = '';
		try {
			CHTQ_FK::build_one( $broadcast, $cid, $tid );
		} catch ( Throwable $e ) {
			$error = $e->getMessage();
		}
		CHTQ::$context = null;
		$cap           = CHTQ::$last_capture;
		$conv          = $cap ? (int) $cap['conversation_id'] : 0;

		if ( $cap && $cap['ok'] ) {
			if ( $hold && $conv ) {
				CHTQ_FK::conversation_to_draft( $conv ); // FunnelKit marked it sent; it is only built
			}
			$status = 2;
		} elseif ( $cap && $cap['duplicate'] ) {
			// Already queued for this broadcast: drop the extra record FunnelKit just made.
			if ( $conv ) {
				CHTQ_FK::conversation_to_draft( $conv );
				CHTQ_FK::delete_draft_conversations( array( $conv ) );
			}
			$conv   = 0;
			$status = 2;
		} else {
			// FunnelKit produced no email (no address, deleted contact) and recorded that
			// itself, or saving it failed and capture() refused it.
			$status = 3;
			if ( $error ) {
				error_log( "chtq build: job {$job['id']} contact $cid: $error" );
			}
		}
		$wpdb->update(
			self::build_table(),
			array( 'status' => $status, 'claim' => null, 'conversation_id' => $conv ?: null ),
			array( 'job_id' => $job['id'], 'contact_id' => $cid )
		);
	}

	/* ------------------------------------------------------------------ retention */

	/**
	 * Delete old records. Queue rows of finished sends after $days, payloads of
	 * failed rows after 30 days, build rows of finished jobs after 14 days.
	 */
	public static function prune( $days = 90 ) {
		global $wpdb;
		$q    = CHTQ::table();
		$out  = array( 'queue' => 0, 'payloads' => 0, 'build' => 0 );
		$old  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$m30  = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$d14  = gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS );
		do {
			$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $q WHERE status IN (2,3,4,5) AND created_at < %s LIMIT 5000", $old ) );
			$out['queue'] += $n;
		} while ( $n > 0 );
		do {
			$n = (int) $wpdb->query( $wpdb->prepare( "UPDATE $q SET payload = NULL WHERE status IN (3,5) AND payload IS NOT NULL AND created_at < %s LIMIT 5000", $m30 ) );
			$out['payloads'] += $n;
		} while ( $n > 0 );
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::jobs_table() . " WHERE state IN ('done','discarded_clean','error','skipped') AND updated_at < %s", $d14 ) ) as $jid ) {
			do {
				$n = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::build_table() . ' WHERE job_id = %d LIMIT 5000', $jid ) );
				$out['build'] += $n;
			} while ( $n > 0 );
			$wpdb->update( self::jobs_table(), array( 'content' => null ), array( 'id' => $jid ) );
		}
		return $out;
	}
}
