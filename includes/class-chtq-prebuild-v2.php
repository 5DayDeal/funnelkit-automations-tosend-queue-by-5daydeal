<?php
/**
 * Pre-building on FunnelKit Automations 3.9+.
 *
 * FunnelKit 3.9 prepares a scheduled broadcast up to an hour ahead: it writes one
 * draft engagement record per recipient, then from the scheduled minute claims them
 * in batches and builds and sends each email one at a time. Our block-editor emails
 * do not qualify for its render cache, so that build is the slow part (about 7 a second).
 *
 * This runs FunnelKit's own send process early, in several workers at once, with the
 * queue holding every finished email instead of sending it. FunnelKit's claims keep the
 * workers from building the same recipient twice, and FunnelKit itself picks variants,
 * writes the records and builds the mail, so nothing of its logic is copied here. At the
 * scheduled minute the held emails are released and the drain sends them in batches.
 *
 * One draft record (the "sentinel") carries a claim of ours until release. FunnelKit's
 * send loop never completes a broadcast while a claim is open, so the early runs stop
 * when everything else is built instead of marking the broadcast completed. At release
 * the claim is dropped and FunnelKit's own run at the scheduled time sends that record,
 * plus anyone who joined the audience since, through the queue, and completes it.
 *
 * An edit before release is handled by what it changed (see watch()):
 *  - only the send time, within KEEP_HOURS: the held emails are kept. FunnelKit throws its
 *    prepared list away on a reschedule, but its cleanup deletes only unsent records, and
 *    ours are marked sent, so its new preparation skips those people (it never gives a
 *    contact a second record) and builds only the rest.
 *  - only the sender name, address or reply-to: patched into the held emails.
 *  - one small text change in a single-version email (body, preheader or subject):
 *    patched into the held emails when it can be matched exactly in every one of them.
 *  - anything else (audience, A/B versions, design changes, a cancel or pause, FunnelKit
 *    changing): the held emails are discarded and their records put back to draft, so
 *    FunnelKit sends those people itself at the scheduled time.
 * The queue's unique (broadcast, contact) key means nobody can get two copies.
 *
 * After release the workers keep going, now queueing instead of holding: FunnelKit
 * sends nothing until it has prepared every recipient, so a broadcast scheduled (or
 * moved) close to its send time would otherwise wait. While FunnelKit is still
 * preparing ("catch-up") the sentinel stays, so the workers can never complete a
 * broadcast whose list is unfinished; once preparation is done it is dropped and the
 * workers send alongside FunnelKit's own run until nobody is left.
 *
 * Smart Sending broadcasts are left to FunnelKit (its test window starts when the test
 * batch is sent, which must be the scheduled time); they still send through the queue.
 *
 * Written against FunnelKit Automations Pro 3.9.0.RC2.
 */

defined( 'ABSPATH' ) || exit;

final class CHTQ_Prebuild_V2 {

	const SENTINEL     = 987654321; // our claim_id on the sentinel record; FunnelKit's are small random numbers
	const MIN_LEAD     = 120;       // seconds: don't start a job closer than this to send time
	const STOP_BEFORE  = 5;         // seconds: workers stop starting runs this close to send time
	const RUN_SECONDS  = 25;        // length of one early FunnelKit run
	const FLIP_CHUNK   = 5000;
	const KEEP_HOURS   = 24;        // a reschedule further out than this rebuilds, so the audience is current
	const PATCH_CHUNK  = 500;

	const BUILDING = 'v2_building';
	const CATCHUP  = 'v2_catchup';  // released while FunnelKit is still preparing the list
	const RELEASED = 'v2_released';
	const DONE     = 'v2_done';

	/** What this needs from FunnelKit 3.9. Empty array = all present. */
	public static function compat_problems() {
		$need = array(
			'BWFCRM_Broadcast_V2_Email_Controller' => array( 'send_email_process', 'get_mail_handler_controller' ),
			'BWFCRM_Broadcast_Processing'          => array( 'get_instance', 'maybe_daily_limit_reached', 'get_per_second_limit', 'is_time_exceeded' ),
			'BWFAN_Model_Broadcast'                => array( 'get', 'update', 'get_formatted_campaign_data' ),
			'BWFCRM_Broadcast_Helper'              => array( 'check_if_smart_send_enabled', 'is_v2_broadcast' ),
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
		global $wpdb;
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}bwfan_engagement_tracking" );
		foreach ( array( 'claim_id', 'c_status', 'oid', 'updated_at' ) as $c ) {
			if ( ! in_array( $c, (array) $cols, true ) ) {
				$problems[] = "engagement table has no $c column";
			}
		}
		return $problems;
	}

	private static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	private static function et() {
		global $wpdb;
		return $wpdb->prefix . 'bwfan_engagement_tracking';
	}

	/** Formatted the way FunnelKit formats it before a send run. */
	private static function broadcast( $id ) {
		$raw = BWFAN_Model_Broadcast::get( absint( $id ) );
		if ( empty( $raw ) ) {
			return null;
		}
		$f = BWFAN_Model_Broadcast::get_formatted_campaign_data( array( $raw ) );
		return ( is_array( $f ) && ! empty( $f[0] ) && is_array( $f[0] ) ) ? $f[0] : null;
	}

	/** Changes when the content, the audience, the senders, the send time or FunnelKit's preparation changes. */
	private static function fingerprint( $b ) {
		return md5( wp_json_encode( self::parts( $b ) ) );
	}

	/**
	 * What the held emails depend on, kept with the job (in its content column) so an edit
	 * can be told apart: c = the email itself, a = who gets it, s = senders, t = when.
	 */
	private static function parts( $b ) {
		$d    = is_array( $b['data'] ) ? $b['data'] : array();
		$pick = function ( $keys ) use ( $d ) {
			$out = array();
			foreach ( $keys as $k ) {
				$out[ $k ] = $d[ $k ] ?? null;
			}
			return $out;
		};
		return array(
			'content' => $d['content'] ?? null,
			'c'       => md5( wp_json_encode( $pick( array( 'content', 'ab_type', 'smart_send' ) ) ) ),
			'a'       => md5( wp_json_encode( $pick( array( 'filters', 'exclude', 'includeSoftBounce', 'includeUnverified', 'is_promotional' ) ) ) ),
			's'       => md5( wp_json_encode( $pick( array( 'senders_name', 'senders_email', 'replyto' ) ) ) ),
			't'       => md5( wp_json_encode( array( $d['prepared_for'] ?? '', $b['execution_time'] ?? '' ) ) ),
		);
	}

	/** FunnelKit has a current preparation for it (none right after a reset, until its cleanup ends). */
	private static function prepared( $b ) {
		if ( empty( $b['data']['prepared_for'] ) ) {
			return false;
		}
		return ! ( method_exists( 'BWFAN_Model_Broadcast', 'has_engagement_cleanup_queued' ) && BWFAN_Model_Broadcast::has_engagement_cleanup_queued( (int) $b['id'] ) );
	}

	private static function jobs( $states ) {
		global $wpdb;
		$in = implode( ',', array_map( function ( $s ) use ( $wpdb ) {
			return $wpdb->prepare( '%s', $s );
		}, (array) $states ) );
		return $wpdb->get_results( 'SELECT id, broadcast_id, state, fingerprint, execution_time, total, content FROM ' . CHTQ_Prebuild::jobs_table() . " WHERE state IN ($in) ORDER BY execution_time, id", ARRAY_A );
	}

	private static function set_state( $job_id, $state, $from, $message = null ) {
		global $wpdb;
		$set = array( 'state' => $state, 'updated_at' => self::now() );
		if ( null !== $message ) {
			$set['message'] = substr( $message, 0, 1000 );
		}
		if ( self::BUILDING === $from && in_array( $state, array( self::RELEASED, self::CATCHUP ), true ) ) {
			$set['released_at'] = self::now();
		}
		return (bool) $wpdb->update( CHTQ_Prebuild::jobs_table(), $set, array( 'id' => (int) $job_id, 'state' => $from ) );
	}

	/* ------------------------------------------------------------------ tick */

	public static function tick( $log ) {
		$s = CHTQ::settings( true );
		if ( empty( $s['enabled'] ) || empty( $s['prebuild'] ) ) {
			self::discard_all( 'pre-building was switched off', $log );
			self::end_catchup( true, $log );
		} elseif ( $p = self::compat_problems() ) {
			self::discard_all( 'FunnelKit changed: ' . implode( '; ', $p ), $log );
			self::end_catchup( true, $log );
			$log( 'FunnelKit compatibility problem, pre-building paused: ' . implode( '; ', $p ) );
		} else {
			self::watch( $log );
			self::discover( (int) $s['prebuild_min'], $log );
			self::release_due( $log );
			self::end_catchup( false, $log );
		}
		self::finish( $log );
	}

	/** Keep, patch or throw away held emails of broadcasts that were edited, rescheduled, cancelled or paused. */
	private static function watch( $log, $only = 0 ) {
		foreach ( self::jobs( self::BUILDING ) as $job ) {
			if ( $only && (int) $job['broadcast_id'] !== $only ) {
				continue;
			}
			$b = self::broadcast( $job['broadcast_id'] );
			if ( ! $b || ! in_array( (int) $b['status'], array( CHTQ_FK::STATUS_SCHEDULED, CHTQ_FK::STATUS_RUNNING ), true ) ) {
				self::discard( $job, 'broadcast is no longer scheduled', $log );
			} elseif ( self::fingerprint( $b ) !== $job['fingerprint'] ) {
				self::edited( $job, $b, $log );
			} elseif ( self::prepared( $b ) ) {
				self::place_sentinel( $job );
			}
		}
	}

	/**
	 * Runs inside FunnelKit's cleanup of a reset broadcast, just before it (priority 5; its
	 * own is 10), so emails we discard are back to draft in time for that same pass to
	 * delete them, and kept ones are settled before FunnelKit prepares the list again.
	 */
	public static function before_cleanup( $broadcast_id ) {
		$s = CHTQ::settings( true );
		if ( ! empty( $s['enabled'] ) && ! empty( $s['prebuild'] ) && ! self::compat_problems() ) {
			self::watch( function ( $m ) {
				error_log( 'chtq: ' . $m );
			}, (int) $broadcast_id );
		}
	}

	/** The broadcast changed since the job started: decide what that change means for the held emails. */
	private static function edited( $job, $b, $log ) {
		global $wpdb;
		$old = json_decode( (string) $job['content'], true );
		$new = self::parts( $b );
		if ( ! is_array( $old ) || ! isset( $old['c'], $old['a'], $old['s'], $old['t'] ) ) {
			self::discard( $job, 'broadcast was edited; it is built again once FunnelKit prepares it', $log );
			return;
		}
		if ( $old['a'] !== $new['a'] ) {
			self::discard( $job, 'audience changed; it is built again once FunnelKit prepares it', $log );
			return;
		}
		if ( strtotime( $b['execution_time'] . ' UTC' ) - time() > self::KEEP_HOURS * HOUR_IN_SECONDS ) {
			self::discard( $job, 'moved more than ' . self::KEEP_HOURS . ' hours out; built again nearer the time so the audience is current', $log );
			return;
		}
		$did = array();
		if ( $old['c'] !== $new['c'] ) {
			$why = self::patch_content( $job, $old['content'] ?? null, $new['content'], $b );
			if ( true !== $why ) {
				self::discard( $job, "content changed ($why); it is built again once FunnelKit prepares it", $log );
				return;
			}
			$did[] = 'content edit patched in';
		}
		if ( $old['s'] !== $new['s'] ) {
			$why = self::patch_senders( $job, $b );
			if ( true !== $why ) {
				self::discard( $job, "sender changed ($why); it is built again once FunnelKit prepares it", $log );
				return;
			}
			$did[] = 'sender patched in';
		}
		if ( $old['t'] !== $new['t'] ) {
			$did[] = 'send time ' . $b['execution_time'] . ' UTC';
		}
		$held = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CHTQ::table() . ' WHERE job_id = %d AND status = %d', $job['id'], CHTQ::STATUS_HLD ) );
		$msg  = "kept $held held emails: " . implode( ', ', $did );
		$ok   = $wpdb->update( CHTQ_Prebuild::jobs_table(), array(
			'fingerprint'    => self::fingerprint( $b ),
			'content'        => wp_json_encode( $new ),
			'execution_time' => $b['execution_time'],
			'message'        => $msg,
			'updated_at'     => self::now(),
		), array( 'id' => (int) $job['id'], 'state' => self::BUILDING, 'fingerprint' => $job['fingerprint'] ) );
		if ( $ok ) {
			$log( "job {$job['id']}: broadcast {$job['broadcast_id']} edited, $msg" );
		}
	}

	/** New sender name, address and reply-to, straight into every held email. */
	private static function patch_senders( $job, $b ) {
		$d     = $b['data'];
		$email = trim( (string) ( $d['senders_email'] ?? '' ) );
		$name  = trim( (string) ( $d['senders_name'] ?? '' ) );
		$reply = trim( (string) ( $d['replyto'] ?? '' ) );
		if ( ! is_email( $email ) || '' === $name || ( '' !== $reply && ! is_email( $reply ) ) ) {
			return 'blank or invalid sender, FunnelKit would fill in its defaults';
		}
		self::each_held( $job, function ( &$e ) use ( $email, $name, $reply ) {
			$e['from'] = array( 'email' => $email, 'name' => $name );
			if ( '' === $reply ) {
				unset( $e['reply_to'] );
			} else {
				$e['reply_to'] = array( 'email' => $reply );
			}
			return true;
		} );
		return true;
	}

	/**
	 * One small text change in a single-version email, patched into the held copies.
	 *
	 * Only when it is safe to do blind: exactly one field of the one version changed (the
	 * body, the preheader or the subject), and the change, widened with the text around it
	 * until it is unique in the template, contains no merge tags and appears exactly once,
	 * unchanged, in every held email. Anything else returns the reason and is rebuilt.
	 *
	 * @return true|string
	 */
	private static function patch_content( $job, $old, $new, $b ) {
		if ( ! is_array( $old ) || ! is_array( $new ) || 1 !== count( $old ) || 1 !== count( $new ) ) {
			return 'A/B versions';
		}
		$o = reset( $old );
		$n = reset( $new );
		if ( ! is_array( $o ) || ! is_array( $n ) ) {
			return 'email settings';
		}
		// FunnelKit saves every content edit as a new template row (the old one stays), so the
		// id always moves with the text; the held records are pointed at the new one below.
		$old_tid = (int) ( $o['template_id'] ?? 0 );
		$new_tid = (int) ( $n['template_id'] ?? 0 );
		unset( $o['template_id'], $n['template_id'] );
		if ( array_keys( $o ) != array_keys( $n ) ) {
			return 'email settings';
		}
		$changed = array_keys( array_filter( $o, function ( $v, $k ) use ( $n ) {
			return $v !== $n[ $k ];
		}, ARRAY_FILTER_USE_BOTH ) );
		if ( ! $changed ) {
			// Only the template id moved: FunnelKit writes the new one when it prepares again
			// after an edit we already patched in.
			self::follow_template( $job, $old_tid, $new_tid );
			return true;
		}
		if ( 1 !== count( $changed ) || ! in_array( $changed[0], array( 'body', 'preheader', 'subject' ), true ) || ! is_string( $o[ $changed[0] ] ) || ! is_string( $n[ $changed[0] ] ) ) {
			return 'more than one part of the email';
		}
		$field = $changed[0];
		$pair  = self::change_in_context( $o[ $field ], $n[ $field ] );
		if ( ! is_array( $pair ) ) {
			return $pair;
		}
		list( $from, $to ) = $pair;
		$key = 'subject' === $field ? 'subject' : 'html';
		// Check every held email first; patch only if all of them match.
		$bad = 0;
		self::each_held( $job, function ( &$e ) use ( $from, $key, &$bad ) {
			if ( 1 !== substr_count( (string) ( $e[ $key ] ?? '' ), $from ) ) {
				$bad++;
			}
			return false;
		} );
		if ( $bad ) {
			return "the edit is not found exactly once in $bad built emails";
		}
		self::each_held( $job, function ( &$e ) use ( $from, $to, $key ) {
			$e[ $key ] = str_replace( $from, $to, $e[ $key ] );
			return true;
		} );
		self::follow_template( $job, $old_tid, $new_tid );
		return true;
	}

	/** Point the held emails' records at FunnelKit's new template row, so its reports keep them together. */
	private static function follow_template( $job, $old_tid, $new_tid ) {
		global $wpdb;
		if ( $old_tid && $new_tid && $old_tid !== $new_tid ) {
			$wpdb->query( $wpdb->prepare(
				'UPDATE ' . self::et() . ' SET tid = %d WHERE oid = %d AND type = 2 AND tid = %d AND ID IN (SELECT conversation_id FROM ' . CHTQ::table() . ' WHERE job_id = %d AND status = %d)',
				$new_tid, (int) $job['broadcast_id'], $old_tid, (int) $job['id'], CHTQ::STATUS_HLD
			) );
		}
	}

	/**
	 * The changed run of text between two versions, widened with unchanged text on both
	 * sides until the old version is unique in the old template.
	 *
	 * @return array|string [old, new] or the reason it cannot be patched blind
	 */
	private static function change_in_context( $a, $b ) {
		$la  = strlen( $a );
		$lb  = strlen( $b );
		$pre = 0;
		while ( $pre < $la && $pre < $lb && $a[ $pre ] === $b[ $pre ] ) {
			$pre++;
		}
		$suf = 0;
		while ( $suf < $la - $pre && $suf < $lb - $pre && $a[ $la - 1 - $suf ] === $b[ $lb - 1 - $suf ] ) {
			$suf++;
		}
		for ( $pad = 8; $pad <= 200; $pad += 8 ) {
			$start = max( 0, $pre - $pad );
			$end_a = min( $la, $la - $suf + $pad );
			$end_b = min( $lb, $lb - $suf + $pad );
			$from  = substr( $a, $start, $end_a - $start );
			$to    = substr( $b, $start, $end_b - $start );
			if ( false !== strpos( $from, '{{' ) || false !== strpos( $from, '}}' ) || false !== strpos( $to, '{{' ) || false !== strpos( $to, '}}' ) ) {
				return 'the edit is next to a merge tag';
			}
			if ( 1 === substr_count( $a, $from ) ) {
				return array( $from, $to );
			}
		}
		return 'the edit is not unique in the email';
	}

	/**
	 * Run $fn over every held email of the job; it edits the email in place and returns
	 * true to save it. Rows the drain has claimed meanwhile are left alone.
	 */
	private static function each_held( $job, $fn ) {
		global $wpdb;
		$q    = CHTQ::table();
		$last = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, payload FROM $q WHERE job_id = %d AND status = %d AND id > %d ORDER BY id LIMIT %d", $job['id'], CHTQ::STATUS_HLD, $last, self::PATCH_CHUNK ), ARRAY_A );
			foreach ( $rows as $r ) {
				$last = (int) $r['id'];
				$e    = json_decode( (string) @gzuncompress( $r['payload'] ), true );
				if ( is_array( $e ) && $fn( $e ) ) {
					$wpdb->query( $wpdb->prepare( "UPDATE $q SET payload = %s WHERE id = %d AND status = %d", gzcompress( wp_json_encode( $e ), 6 ), $r['id'], CHTQ::STATUS_HLD ) );
				}
			}
		} while ( $rows );
	}

	/** Prepared email broadcasts big enough, not Smart Sending, with time to build, become jobs. */
	private static function discover( $min, $log ) {
		global $wpdb;
		$jt   = CHTQ_Prebuild::jobs_table();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}bwfan_broadcast WHERE type = 1 AND status IN (%d,%d) AND count >= %d AND execution_time > %s AND (parent IS NULL OR parent = 0)",
			CHTQ_FK::STATUS_SCHEDULED, CHTQ_FK::STATUS_RUNNING, max( 1, $min ), gmdate( 'Y-m-d H:i:s', time() + self::MIN_LEAD )
		), ARRAY_A );
		foreach ( $rows as $r ) {
			$b = self::broadcast( $r['id'] );
			if ( ! $b || empty( $b['data']['prepared_for'] ) || ! BWFCRM_Broadcast_Helper::is_v2_broadcast( $b ) ) {
				continue; // FunnelKit has not prepared it yet
			}
			$fp = self::fingerprint( $b );
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $jt WHERE broadcast_id = %d AND fingerprint = %s LIMIT 1", $b['id'], $fp ) ) ) {
				continue; // already built, or already decided against
			}
			$smart = BWFCRM_Broadcast_Helper::check_if_smart_send_enabled( $b['data'] );
			$wpdb->insert( $jt, array(
				'broadcast_id'   => (int) $b['id'],
				'state'          => $smart ? 'skipped' : self::BUILDING,
				'fingerprint'    => $fp,
				'execution_time' => $b['execution_time'],
				'content'        => wp_json_encode( self::parts( $b ) ),
				'total'          => (int) $b['count'],
				'message'        => $smart ? 'Smart Sending: FunnelKit sends it at the scheduled time, through the queue' : null,
				'created_at'     => self::now(),
				'updated_at'     => self::now(),
			) );
			$log( $smart ? "broadcast {$b['id']}: Smart Sending, left to FunnelKit" : "broadcast {$b['id']}: job {$wpdb->insert_id} pre-building {$b['count']} for {$b['execution_time']} UTC" );
			if ( ! $smart ) {
				self::place_sentinel( array( 'id' => $wpdb->insert_id, 'broadcast_id' => $b['id'] ) );
			}
		}
	}

	/**
	 * Put our claim on one draft record, or refresh it. FunnelKit frees claims older
	 * than five minutes, so this runs every tick and before every early run.
	 *
	 * @return bool whether the broadcast has a sentinel now
	 */
	private static function place_sentinel( $job ) {
		global $wpdb;
		$et  = self::et();
		$bid = (int) $job['broadcast_id'];
		// Checked by SELECT: an UPDATE that changes nothing (same second) reports 0 rows.
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $et WHERE oid = %d AND type = 2 AND claim_id = %d LIMIT 1", $bid, self::SENTINEL ) ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE $et SET updated_at = %s WHERE oid = %d AND type = 2 AND claim_id = %d", self::now(), $bid, self::SENTINEL ) );
			return true;
		}
		// The newest draft: FunnelKit claims oldest first, so the early runs reach it last.
		return (bool) $wpdb->query( $wpdb->prepare(
			"UPDATE $et SET claim_id = %d, updated_at = %s WHERE oid = %d AND type = 2 AND c_status = %d AND claim_id = 0 ORDER BY ID DESC LIMIT 1",
			self::SENTINEL, self::now(), $bid, CHTQ_FK::CONV_DRAFT
		) );
	}

	private static function drop_sentinel( $broadcast_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::et() . ' SET claim_id = 0 WHERE oid = %d AND type = 2 AND claim_id = %d', (int) $broadcast_id, self::SENTINEL ) );
	}

	/* ------------------------------------------------------------------ release */

	/** Also called from FunnelKit's own tick (CHTQ_Prebuild::on_funnelkit_tick), so release is on the minute. */
	public static function release_due( $log = null ) {
		$log = $log ?: function () {};
		foreach ( self::jobs( self::BUILDING ) as $job ) {
			if ( strtotime( $job['execution_time'] . ' UTC' ) > time() ) {
				continue;
			}
			if ( ! self::set_state( $job['id'], self::RELEASED, self::BUILDING ) ) {
				continue; // another process released it
			}
			global $wpdb;
			$released = 0;
			do {
				$n = (int) $wpdb->query( $wpdb->prepare(
					'UPDATE ' . CHTQ::table() . ' SET status = %d WHERE job_id = %d AND status = %d ORDER BY id LIMIT %d',
					CHTQ::STATUS_Q, $job['id'], CHTQ::STATUS_HLD, self::FLIP_CHUNK
				) );
				$released += $n;
			} while ( $n > 0 );
			if ( self::still_preparing( $job['broadcast_id'] ) ) {
				// Keep the sentinel: the workers carry on building (and queueing) until FunnelKit has the whole list.
				self::set_state( $job['id'], self::CATCHUP, self::RELEASED );
				$log( "job {$job['id']}: released $released emails for broadcast {$job['broadcast_id']}; FunnelKit is still preparing, workers carry on" );
			} else {
				self::drop_sentinel( $job['broadcast_id'] );
				$log( "job {$job['id']}: released $released emails for broadcast {$job['broadcast_id']}; workers and FunnelKit send anyone left" );
			}
		}
	}

	/** FunnelKit has not finished writing the draft records (it sends nothing until it has). */
	private static function still_preparing( $broadcast_id ) {
		global $wpdb;
		$ec = $wpdb->get_var( $wpdb->prepare( "SELECT JSON_EXTRACT(data, '$.engagements_created') FROM {$wpdb->prefix}bwfan_broadcast WHERE id = %d", (int) $broadcast_id ) );
		return null !== $ec && 0 === (int) $ec;
	}

	/**
	 * Catch-up ends when FunnelKit has prepared everyone (or the broadcast stopped, or
	 * pre-building was switched off): the sentinel goes and FunnelKit can complete it.
	 * Until then the sentinel is refreshed so FunnelKit's five-minute sweep keeps it.
	 */
	private static function end_catchup( $force, $log ) {
		global $wpdb;
		foreach ( self::jobs( self::CATCHUP ) as $job ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . CHTQ::table() . ' SET status = %d WHERE job_id = %d AND status = %d', CHTQ::STATUS_Q, $job['id'], CHTQ::STATUS_HLD ) );
			$b       = CHTQ_FK::broadcast( $job['broadcast_id'] );
			$running = $b && CHTQ_FK::STATUS_RUNNING === (int) $b['status'];
			if ( ! $force && $running && self::still_preparing( $job['broadcast_id'] ) ) {
				self::place_sentinel( $job );
				continue;
			}
			if ( self::set_state( $job['id'], self::RELEASED, self::CATCHUP ) ) {
				self::drop_sentinel( $job['broadcast_id'] );
				$log( "job {$job['id']}: FunnelKit has the whole list" . ( $running ? '' : ' (or the broadcast stopped)' ) . '; sentinel dropped' );
			}
		}
	}

	/** Released jobs are done once the queue has sent everything. */
	private static function finish( $log ) {
		global $wpdb;
		foreach ( self::jobs( self::RELEASED ) as $job ) {
			// Held rows built by a run that was still going at release are let go here too.
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . CHTQ::table() . ' SET status = %d WHERE job_id = %d AND status = %d', CHTQ::STATUS_Q, $job['id'], CHTQ::STATUS_HLD ) );
			$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CHTQ::table() . ' WHERE broadcast_id = %d AND status IN (0,1,6)', $job['broadcast_id'] ) );
			$b    = CHTQ_FK::broadcast( $job['broadcast_id'] );
			if ( ! $left && $b && CHTQ_FK::STATUS_COMPLETED === (int) $b['status'] ) {
				self::set_state( $job['id'], self::DONE, self::RELEASED );
				$log( "job {$job['id']}: done" );
			}
		}
	}

	/* ------------------------------------------------------------------ discard */

	/**
	 * Delete the held emails and put their records back to draft, so FunnelKit sends
	 * those people itself. Its processed count goes back down by the same number.
	 */
	public static function discard( $job, $why, $log = null ) {
		global $wpdb;
		if ( ! self::set_state( $job['id'], 'discarded', self::BUILDING, $why ) ) {
			return;
		}
		$q        = CHTQ::table();
		$et       = self::et();
		$reverted = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, conversation_id FROM $q WHERE job_id = %d AND status = %d LIMIT %d", $job['id'], CHTQ::STATUS_HLD, 1000 ), ARRAY_A );
			if ( ! $rows ) {
				break;
			}
			$cvs = array_filter( array_map( 'intval', wp_list_pluck( $rows, 'conversation_id' ) ) );
			if ( $cvs ) {
				$reverted += (int) $wpdb->query( $wpdb->prepare(
					"UPDATE $et SET c_status = %d, claim_id = 0 WHERE oid = %d AND type = 2 AND c_status = %d AND ID IN (" . implode( ',', $cvs ) . ')',
					CHTQ_FK::CONV_DRAFT, (int) $job['broadcast_id'], CHTQ_FK::CONV_SENT
				) );
			}
			$wpdb->query( "DELETE FROM $q WHERE id IN (" . implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) ) . ')' );
		} while ( true );
		if ( $reverted ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}bwfan_broadcast SET processed = GREATEST(0, CAST(processed AS SIGNED) - %d) WHERE id = %d", $reverted, (int) $job['broadcast_id'] ) );
		}
		self::drop_sentinel( $job['broadcast_id'] );
		if ( $log ) {
			$log( "job {$job['id']}: discarded ($why); $reverted records back to draft for FunnelKit" );
		}
	}

	public static function discard_all( $why, $log ) {
		foreach ( self::jobs( self::BUILDING ) as $job ) {
			self::discard( $job, $why, $log );
		}
	}

	/* ------------------------------------------------------------------ workers */

	/**
	 * Run FunnelKit's send process early, holding what it builds, until nothing is left
	 * to build. Several run at once (`wp chtq work`, one cron slot each); FunnelKit's
	 * claims share the work out.
	 *
	 * @return int emails built
	 */
	public static function work( $idle = 20, $log = null ) {
		global $wpdb;
		$built   = 0;
		$idle_at = null;
		while ( true ) {
			$did = false;
			foreach ( self::jobs( array( self::BUILDING, self::CATCHUP, self::RELEASED ) ) as $job ) {
				$ahead = self::BUILDING === $job['state'];
				if ( $ahead && strtotime( $job['execution_time'] . ' UTC' ) - time() < self::STOP_BEFORE ) {
					continue; // release is seconds away; the tick takes it from here
				}
				$drafts = (int) $wpdb->get_var( $wpdb->prepare(
					'SELECT COUNT(*) FROM ' . self::et() . ' WHERE oid = %d AND type = 2 AND c_status = %d AND claim_id = 0',
					$job['broadcast_id'], CHTQ_FK::CONV_DRAFT
				) );
				if ( ! $drafts ) {
					continue; // nothing to build yet, or nobody left
				}
				if ( self::RELEASED !== $job['state'] && ! self::place_sentinel( $job ) ) {
					continue; // never run without a sentinel while FunnelKit may still be preparing
				}
				$n = self::run_once( $job );
				if ( $n > 0 ) {
					$built += $n;
					$did    = true;
					if ( $log ) {
						$log( gmdate( 'H:i:s' ) . " job {$job['id']}: built $n (worker total $built)" );
					}
				}
				break; // re-read jobs: earliest send time first
			}
			if ( $did ) {
				$idle_at = null;
				continue;
			}
			$idle_at = $idle_at ?: time();
			if ( time() - $idle_at >= $idle ) {
				return $built;
			}
			sleep( 2 );
		}
	}

	/** One FunnelKit send run for this job's broadcast: holding before release, queueing after. */
	private static function run_once( $job ) {
		global $wpdb;
		$ahead = self::BUILDING === $job['state'];
		$b     = self::broadcast( $job['broadcast_id'] );
		if ( ! $b ) {
			return 0;
		}
		if ( $ahead && ( self::fingerprint( $b ) !== $job['fingerprint'] || ! self::prepared( $b ) ) ) {
			return 0; // the tick (or FunnelKit's cleanup) settles it first
		}
		if ( ! $ahead && CHTQ_FK::STATUS_RUNNING !== (int) $b['status'] ) {
			return 0; // paused, cancelled or completed: FunnelKit's call
		}
		$p = BWFCRM_Broadcast_Processing::get_instance();
		// What FunnelKit's do_scheduler_work() sets up before a run.
		$p->default_email_settings       = BWFAN_Common::get_global_settings();
		$p->EMAIL_PER_SECOND_LIMIT       = apply_filters( 'bwfan_broadcast_email_per_second_limit', absint( $p->default_email_settings['bwfan_email_per_second_limit'] ?? 15 ) );
		$p->current_broadcast            = $b;
		$p->start_time                   = time();
		$p->AS_CALL_TIME                 = $ahead ? min( self::RUN_SECONDS, max( 1, strtotime( $job['execution_time'] . ' UTC' ) - time() - self::STOP_BEFORE ) ) : self::RUN_SECONDS;
		$p->started_send_mail_at         = microtime( true );
		$p->records_processed_in_one_sec = 0;
		$p->daily_achieved_limit         = 0;
		if ( $p->maybe_daily_limit_reached( 1 ) ) {
			return 0; // FunnelKit delays it at send time; nothing to build ahead
		}
		if ( class_exists( 'BWFCRM_Load_Filters' ) ) {
			BWFCRM_Load_Filters::load_filters();
		}

		$q      = CHTQ::table();
		$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $q WHERE job_id = %d", $job['id'] ) );
		CHTQ::$context = array( 'job_id' => (int) $job['id'], 'broadcast_id' => (int) $job['broadcast_id'], 'contact_id' => 0, 'hold' => $ahead );
		try {
			( new BWFCRM_Broadcast_V2_Email_Controller( $p ) )->send_email_process();
		} catch ( Throwable $e ) {
			error_log( "chtq: early run for broadcast {$job['broadcast_id']} failed: " . $e->getMessage() );
		} finally {
			CHTQ::$context = null;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $q WHERE job_id = %d", $job['id'] ) ) - $before;
	}
}
