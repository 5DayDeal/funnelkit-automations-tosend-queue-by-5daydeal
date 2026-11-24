<?php
/**
 * Plugin Name: FunnelKit Automations toSend Queue Plugin by 5DayDeal.com
 * Plugin URI: https://github.com/5DayDeal/funnelkit-automations-tosend-queue-by-5daydeal
 * Description: Queues FunnelKit Automations broadcast emails and sends them through toSend's batch API at your team's full speed, and can pre-build scheduled broadcasts so they all go out at the scheduled minute. Order emails and automations are untouched.
 * Version: 2.9.0
 * Requires PHP: 7.4
 * Author: 5DayDeal.com (Griffin Stewart)
 * Author URI: https://5daydeal.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * How it works
 * ------------
 * FunnelKit still builds every broadcast email (merge tags, tracking, unsubscribe
 * link). On FunnelKit 3.9+ the bwfcrm_override_broadcast_mail_handler filter swaps in
 * CHTQ_Mail_Handler, which stores the finished email in {prefix}chtq_queue instead of
 * sending it. On older versions a pre_wp_mail filter catches only the calls from
 * BWFCRM_Broadcast_Processing::process_email() and does the same. FunnelKit marks the
 * email sent and moves on in about a millisecond. `wp chtq drain` (run from cron under
 * flock) then sends the queue through toSend's batch API, 100 emails a request, paced to
 * the team's per-second limit, with an Idempotency-Key on every batch.
 *
 * CHTQ_Mail_Handler also applies bwfan_before_send_email_body, which FunnelKit stopped
 * applying to broadcasts in 3.9. Up to 3.8 a broadcast went through the v1 sender, which
 * applies it; 3.9 moved broadcasts to a controller and mail handler that do not. The
 * filter still runs on the automation path, so it narrowed rather than disappeared, and
 * nothing errors when it stops reaching a broadcast. Since this plugin already stands in
 * that exact spot, it applies the filter itself. Turn that off with
 * chtq_apply_before_send_email_body if it is ever in the way.
 *
 * FunnelKit runs remove_all_filters('pre_wp_mail') while it prepares each email,
 * so the filter is re-attached from the `wp_mail` filter, which FunnelKit leaves
 * alone and which runs just before pre_wp_mail in the same call.
 *
 * Undo: `wp chtq disable` (new broadcast emails go through FluentSMTP again).
 * Anything already queued still drains.
 *
 * Light open pixel: FunnelKit's email open pixel loads only FunnelKit's plugins, through a
 * must-use file this plugin installs and keeps current (includes/class-chtq-pixel.php).
 * `wp chtq pixel off` removes it.
 *
 * Internal names (CHTQ classes, `wp chtq`, chtq_* tables and options) are kept from the
 * plugin's first name so existing installs keep their data.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-chtq-funnelkit.php';
require_once __DIR__ . '/includes/class-chtq-prebuild.php';
require_once __DIR__ . '/includes/class-chtq-prebuild-v2.php';
require_once __DIR__ . '/includes/class-chtq-admin.php';
require_once __DIR__ . '/includes/class-chtq-stats.php';
require_once __DIR__ . '/includes/class-chtq-pixel.php';

final class CHTQ {
	const OPT        = 'chtq_settings';
	const STATUS_Q   = 0; // queued
	const STATUS_C   = 1; // claimed by a drain, request in flight
	const STATUS_OK  = 2; // accepted by toSend
	const STATUS_ERR = 3; // rejected by toSend, or gave up after retries
	const STATUS_UNS = 4; // skipped: unsubscribed after it was queued
	const STATUS_UNK = 5; // no clear answer after 4 same-key tries: toSend may or may not have it, never retried
	const STATUS_HLD = 6; // pre-built, held until its broadcast's scheduled time
	const STATUS_DIS = 7; // pre-built but thrown away (content edited, broadcast cancelled, left audience)
	const API        = 'https://api.tosend.com/v2/emails/batch';
	const DB_VERSION = 3;

	/**
	 * Set by the pre-builder around each FunnelKit build call, so capture() knows
	 * the email belongs to a pre-build job and must be held, not sent.
	 *
	 * @var array|null { job_id, broadcast_id, contact_id, hold }
	 */
	public static $context = null;

	/** Filled by capture() for the pre-builder to read back. */
	public static $last_capture = null;

	public static function init() {
		add_filter( 'wp_mail', array( __CLASS__, 'reattach' ), 1 );
		add_filter( 'pre_wp_mail', array( __CLASS__, 'capture' ), 1, 2 ); // FunnelKit 3.8 broadcasts
		add_filter( 'bwfcrm_override_broadcast_mail_handler', array( __CLASS__, 'mail_handler' ), 20 ); // FunnelKit 3.9+
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
		CHTQ_Prebuild::init();
		CHTQ_Pixel::init();
		if ( is_admin() ) {
			CHTQ_Admin::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'chtq', 'CHTQ_CLI' );
		}
	}

	public static function maybe_upgrade() {
		if ( (int) get_option( 'chtq_db_version', 0 ) >= self::DB_VERSION ) {
			return;
		}
		// One request runs the schema change; add_option is atomic on the unique option_name.
		if ( ! add_option( 'chtq_upgrading', time(), '', false ) ) {
			if ( time() - (int) get_option( 'chtq_upgrading' ) < 300 ) {
				return;
			}
			update_option( 'chtq_upgrading', time(), false );
		}
		self::install();
		delete_option( 'chtq_upgrading' );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'chtq_queue';
	}

	/**
	 * @param bool $fresh Read straight from the database (long-running CLI loops),
	 *                    without touching the shared object cache.
	 */
	public static function settings( $fresh = false ) {
		global $wpdb;
		$s = $fresh
			? maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPT ) ) )
			: get_option( self::OPT, array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), array(
			'enabled'    => false,
			'hold_until' => 0,      // unix time; drain sends nothing before this
			'workers'    => 6,      // parallel requests per drain
			'rate'       => 'auto', // emails a second at most: 'auto' = 95% of toSend's limit_per_second, a number to override, 0 = no limit
			'paused'     => '',     // set when toSend refuses a whole batch (credit, auth)
			'prebuild'   => false,  // build scheduled broadcasts ahead of their send time
			'prebuild_min'  => 1000, // only broadcasts with at least this many recipients
			'build_workers' => 4,    // parallel FunnelKit builders (one cron line each)
			'light_pixel'   => true, // FunnelKit's open pixel loads only FunnelKit's plugins
		) );
	}

	public static function save( $s ) {
		update_option( self::OPT, $s, false );
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			broadcast_id bigint(20) unsigned NOT NULL DEFAULT 0,
			contact_id bigint(20) unsigned NULL,
			conversation_id bigint(20) unsigned NULL,
			job_id bigint(20) unsigned NULL,
			to_email varchar(255) NOT NULL,
			payload longblob NULL,
			status tinyint(1) NOT NULL DEFAULT 0,
			claim varchar(40) NULL,
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			message_id varchar(100) NULL,
			error text NULL,
			created_at datetime NOT NULL,
			sent_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY broadcast_contact (broadcast_id,contact_id),
			KEY status_id (status,id),
			KEY claim (claim),
			KEY bstat (broadcast_id,status,sent_at),
			KEY job_status (job_id,status)
		) {$wpdb->get_charset_collate()};" );
		CHTQ_Prebuild::install_tables();
		update_option( 'chtq_db_version', self::DB_VERSION, false );
	}

	/** Keep the capture filter attached even after FunnelKit wipes pre_wp_mail. */
	public static function reattach( $atts ) {
		if ( false === has_filter( 'pre_wp_mail', array( __CLASS__, 'capture' ) ) ) {
			add_filter( 'pre_wp_mail', array( __CLASS__, 'capture' ), 1, 2 );
		}
		return $atts;
	}

	private static function is_broadcast_send() {
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 ) as $f ) {
			if ( isset( $f['class'], $f['function'] ) && 'process_email' === $f['function'] && 'BWFCRM_Broadcast_Processing' === $f['class'] ) {
				return true;
			}
		}
		return false;
	}

	public static function capture( $short, $atts ) {
		if ( null !== $short ) {
			return $short;
		}
		$ctx = self::$context;
		$s   = self::settings();
		if ( ! $ctx && ( empty( $s['enabled'] ) || ! self::is_broadcast_send() ) ) {
			return $short;
		}
		if ( $ctx && ! self::is_broadcast_send() ) {
			return $short; // something else mailed during a build; not ours
		}
		// From here on, a pre-built email ($ctx) is held or refused, never sent early:
		// returning false makes FunnelKit record a failure for that contact.
		$bail = $ctx ? false : $short;

		$to = $atts['to'] ?? '';
		if ( is_array( $to ) ) {
			if ( 1 !== count( $to ) ) {
				return $bail;
			}
			$to = reset( $to );
		}
		$to = trim( (string) $to );
		if ( ! is_email( $to ) || ! empty( $atts['attachments'] ) ) {
			return $bail; // live sends: let FluentSMTP handle anything unusual
		}

		$email = self::build_email( $to, (string) ( $atts['subject'] ?? '' ), (string) ( $atts['message'] ?? '' ), $atts['headers'] ?? '' );
		$json  = $email ? wp_json_encode( $email ) : false;
		if ( ! $json || '' === $email['subject'] ) {
			return $bail;
		}

		global $wpdb;
		$broadcast_id = $ctx ? (int) $ctx['broadcast_id'] : 0;
		$contact_id   = $ctx ? (int) $ctx['contact_id'] : 0;
		if ( ! $ctx && class_exists( 'BWFAN_Merge_Tag_Loader' ) ) {
			$broadcast_id = absint( BWFAN_Merge_Tag_Loader::get_data( 'broadcast_id' ) );
			$contact_id   = absint( BWFAN_Merge_Tag_Loader::get_data( 'contact_id' ) );
		}

		// FunnelKit's tracking hash in the body identifies the conversation record,
		// so the drain can stamp the real send time (or a failure) on it later.
		// Subscriber text (merge tags) comes before FunnelKit's own links, so a hash is
		// only trusted when every copy in the email agrees and it belongs to this contact.
		$conversation_id = 0;
		if ( class_exists( 'CHTQ_FK' ) && preg_match_all( '/bwfan-track-id=([A-Za-z0-9]{32})/', (string) $atts['message'], $m ) ) {
			$hashes = array_unique( $m[1] );
			if ( 1 === count( $hashes ) ) {
				$conversation_id = CHTQ_FK::conversation_id_for_hash( reset( $hashes ), $contact_id, $broadcast_id );
			}
		}

		$row = array(
			'broadcast_id'    => $broadcast_id,
			'contact_id'      => $contact_id ?: null,
			'conversation_id' => $conversation_id ?: null,
			'job_id'          => $ctx ? (int) $ctx['job_id'] : null,
			'to_email'        => $to,
			'payload'         => gzcompress( $json, 6 ),
			'status'          => ( $ctx && ! empty( $ctx['hold'] ) ) ? self::STATUS_HLD : self::STATUS_Q,
			'created_at'      => current_time( 'mysql', true ),
		);
		$fmt = array( '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s' );
		$ok  = $wpdb->insert( self::table(), $row, $fmt );

		self::$last_capture = array( 'ok' => (bool) $ok, 'conversation_id' => $conversation_id, 'duplicate' => false );
		if ( ! $ok && $contact_id && false !== stripos( (string) $wpdb->last_error, 'Duplicate' ) ) {
			// A held (never released) copy from a different job, or under a live send
			// (FunnelKit started the broadcast itself, e.g. "send now"), is replaced, so
			// the contact still gets exactly one. A copy from this same job, or one that
			// is queued or already sent, is a true duplicate.
			$job_cond = $ctx ? $wpdb->prepare( ' AND (job_id IS NULL OR job_id <> %d)', (int) $ctx['job_id'] ) : '';
			$wpdb->query( $wpdb->prepare(
				'DELETE FROM ' . self::table() . ' WHERE broadcast_id = %d AND contact_id = %d AND status IN (%d,%d)' . $job_cond,
				$broadcast_id, $contact_id, self::STATUS_HLD, self::STATUS_DIS
			) );
			if ( $wpdb->rows_affected && $wpdb->insert( self::table(), $row, $fmt ) ) {
				self::$last_capture['ok'] = true;
				return true;
			}
			// Already queued or sent for this broadcast: never send it twice.
			self::$last_capture['duplicate'] = true;
			return true;
		}

		// Any other save failure: live sends fall through to FluentSMTP; pre-builds fail.
		return $ok ? true : $bail;
	}

	/**
	 * Queue one finished broadcast email handed over by FunnelKit 3.9's mail handler
	 * (see CHTQ_Mail_Handler). FunnelKit already knows the engagement record, so no
	 * tracking-hash parsing is needed.
	 *
	 * @return true|string|false true queued; 'duplicate' already queued or sent for this
	 *                           broadcast (never send twice); false could not queue
	 */
	public static function enqueue( $to, $subject, $html, $headers, $broadcast_id, $contact_id, $conversation_id ) {
		global $wpdb;
		$to = trim( (string) $to );
		if ( ! is_email( $to ) ) {
			return false;
		}
		$email = self::build_email( $to, (string) $subject, (string) $html, $headers );
		$json  = $email ? wp_json_encode( $email ) : false;
		if ( ! $json || '' === $email['subject'] ) {
			return false;
		}
		// Set by CHTQ_Prebuild_V2 around an early FunnelKit run: hold it under that job.
		$ctx = ( self::$context && (int) self::$context['broadcast_id'] === (int) $broadcast_id ) ? self::$context : null;
		$row = array(
			'broadcast_id'    => (int) $broadcast_id,
			'contact_id'      => (int) $contact_id ?: null,
			'conversation_id' => (int) $conversation_id ?: null,
			'job_id'          => $ctx ? (int) $ctx['job_id'] : null,
			'to_email'        => $to,
			'payload'         => gzcompress( $json, 6 ),
			'status'          => ( $ctx && ! empty( $ctx['hold'] ) ) ? self::STATUS_HLD : self::STATUS_Q,
			'created_at'      => current_time( 'mysql', true ),
		);
		$fmt = array( '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s' );
		if ( $wpdb->insert( self::table(), $row, $fmt ) ) {
			return true;
		}
		if ( $contact_id && false !== stripos( (string) $wpdb->last_error, 'Duplicate' ) ) {
			// A never-released pre-built copy from another job is replaced; anything queued
			// or sent, or held under this same job, stays the only copy.
			$job_cond = $ctx ? $wpdb->prepare( ' AND (job_id IS NULL OR job_id <> %d)', (int) $ctx['job_id'] ) : '';
			$wpdb->query( $wpdb->prepare(
				'DELETE FROM ' . self::table() . ' WHERE broadcast_id = %d AND contact_id = %d AND status IN (%d,%d)' . $job_cond,
				$broadcast_id, $contact_id, self::STATUS_HLD, self::STATUS_DIS
			) );
			if ( $wpdb->rows_affected && $wpdb->insert( self::table(), $row, $fmt ) ) {
				return true;
			}
			return 'duplicate';
		}
		return false;
	}

	/**
	 * FunnelKit 3.9+ lets a plugin supply the mail handler for broadcasts through the
	 * bwfcrm_override_broadcast_mail_handler filter; ours queues instead of sending one
	 * by one. Loaded only here, once FunnelKit's base class is known to exist.
	 */
	public static function mail_handler( $handler ) {
		if ( ! class_exists( 'BWFAN_Mail_Handler_Controller' ) ) {
			return $handler;
		}
		require_once __DIR__ . '/includes/class-chtq-mail-handler.php';
		return new CHTQ_Mail_Handler();
	}

	/** wp_mail arguments -> one toSend email object. */
	public static function build_email( $to, $subject, $html, $headers ) {
		if ( ! is_array( $headers ) ) {
			$headers = preg_split( "/\r\n|\n/", (string) $headers );
		}
		$from = $reply = null;
		$extra = array();
		foreach ( $headers as $line ) {
			if ( false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $name, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$lname = strtolower( $name );
			if ( 'from' === $lname ) {
				$from = self::parse_address( $value );
			} elseif ( 'reply-to' === $lname ) {
				$reply = self::parse_address( $value );
			} elseif ( in_array( $lname, array( 'mime-version', 'content-type', 'cc', 'bcc' ), true ) ) {
				if ( 'content-type' === $lname && false === stripos( $value, 'text/html' ) ) {
					return null; // only HTML broadcasts are queued
				}
				continue;
			} elseif ( '' !== $value ) {
				if ( ! preg_match( '/^[A-Za-z0-9-]+$/', $name ) || ! preg_match( '/^[\x20-\x7E]*$/', $value ) ) {
					return null; // toSend wants printable ASCII header values; let FluentSMTP send it
				}
				$extra[ $name ] = $value;
			}
		}
		if ( ! $from || ! is_email( $from['email'] ) ) {
			return null;
		}
		$email = array(
			'from'    => $from,
			'to'      => array( array( 'email' => $to ) ),
			'subject' => $subject,
			'html'    => $html,
		);
		if ( $reply && is_email( $reply['email'] ) ) {
			$email['reply_to'] = $reply;
		}
		if ( $extra ) {
			$email['headers'] = $extra;
		}
		return $email;
	}

	private static function parse_address( $value ) {
		if ( preg_match( '/^\s*"?(.*?)"?\s*<([^>]+)>\s*$/', $value, $m ) ) {
			return array_filter( array( 'name' => trim( $m[1] ), 'email' => trim( $m[2] ) ), 'strlen' );
		}
		return array( 'email' => trim( $value ) );
	}

	/** The toSend connection FluentSMTP uses for this sender, decrypted. */
	public static function connection_for( $from_email ) {
		if ( ! function_exists( 'fluentMailGetSettings' ) ) {
			return null;
		}
		$settings = fluentMailGetSettings();
		$key      = isset( $settings['mappings'][ $from_email ] ) ? $settings['mappings'][ $from_email ] : ( $settings['misc']['default_connection'] ?? '' );
		$conn     = $settings['connections'][ $key ]['provider_settings'] ?? null;
		if ( ! $conn || 'tosend' !== ( $conn['provider'] ?? '' ) ) {
			return null;
		}
		if ( 'wp_config' === ( $conn['key_store'] ?? '' ) ) {
			$conn['api_key'] = defined( 'FLUENTMAIL_TOSEND_API_KEY' ) ? FLUENTMAIL_TOSEND_API_KEY : '';
		}
		return empty( $conn['api_key'] ) ? null : $conn;
	}

	/**
	 * toSend's account info (GET /v2/info): plan, per-second limit, credits, SES health
	 * and per-domain totals. Cached for 10 minutes. The API key is only sent in the
	 * request header; it is never stored or returned.
	 *
	 * @return array|null decoded JSON, or null if unavailable
	 */
	public static function tosend_info( $refresh = false ) {
		$cached = get_transient( 'chtq_tosend_info' );
		if ( ! $refresh && is_array( $cached ) ) {
			return $cached;
		}
		$settings = function_exists( 'fluentMailGetSettings' ) ? fluentMailGetSettings() : array();
		$key      = $settings['misc']['default_connection'] ?? '';
		$from     = $settings['connections'][ $key ]['provider_settings']['sender_email'] ?? '';
		$conn     = $from ? self::connection_for( $from ) : null;
		if ( ! $conn ) {
			return null;
		}
		$r = wp_remote_get( 'https://api.tosend.com/v2/info', array(
			'headers' => array( 'Authorization' => 'Bearer ' . $conn['api_key'], 'Accept' => 'application/json' ),
			'timeout' => 8,
		) );
		if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
			return null;
		}
		$info = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( ! is_array( $info ) ) {
			return null;
		}
		set_transient( 'chtq_tosend_info', $info, 10 * MINUTE_IN_SECONDS );
		return $info;
	}

	/** Used when 'auto' can't read toSend's limit: just under the old 30-a-second cap. */
	const RATE_FALLBACK = 28;

	/**
	 * Emails a second the drain may send. 'auto' follows toSend's per-team limit
	 * (/v2/info account.limit_per_second, cached 10 minutes) with 5% headroom, so a
	 * raised limit is picked up without a settings change.
	 *
	 * @return int 0 means no limit
	 */
	public static function effective_rate( $setting = null ) {
		$setting = null === $setting ? ( self::settings()['rate'] ?? 'auto' ) : $setting;
		if ( 'auto' !== $setting ) {
			return max( 0, (int) $setting );
		}
		$info  = self::tosend_info();
		$limit = (int) ( $info['account']['limit_per_second'] ?? 0 );
		return $limit > 0 ? max( 1, (int) floor( $limit * 0.95 ) ) : self::RATE_FALLBACK;
	}


	/*
	 * Keep FunnelKit's conversation record in step with what actually happened.
	 * Never let a FunnelKit error stop the drain: the email outcome is already
	 * recorded in the queue table either way.
	 */

	/** toSend accepted it: FunnelKit shows this as the sent time. */
	public static function funnelkit_sent( $conversation_id, $gmt ) {
		if ( ! $conversation_id || ! class_exists( 'CHTQ_FK' ) ) {
			return;
		}
		try {
			CHTQ_FK::conversations_sent_at( (array) $conversation_id, $gmt );
		} catch ( Throwable $e ) {
			error_log( 'chtq: could not stamp send time on conversation ' . $conversation_id . ': ' . $e->getMessage() );
		}
	}

	public static function funnelkit_failed( $conversation_id, $message ) {
		if ( ! $conversation_id || ! class_exists( 'CHTQ_FK' ) ) {
			return;
		}
		try {
			CHTQ_FK::conversation_failed( $conversation_id, substr( wp_strip_all_tags( $message ), 0, 500 ) );
		} catch ( Throwable $e ) {
			error_log( 'chtq: could not mark conversation ' . $conversation_id . ' failed: ' . $e->getMessage() );
		}
	}

	/**
	 * A queued email that will not be sent. A pre-built one only ever had a draft
	 * record, which is removed so reports match a normal send; a live one was already
	 * marked sent by FunnelKit, so it is marked failed with the reason instead.
	 */
	public static function funnelkit_skipped( $row, $reason ) {
		$cv = (int) ( $row['conversation_id'] ?? 0 );
		if ( ! $cv || ! class_exists( 'CHTQ_FK' ) ) {
			return;
		}
		try {
			if ( ! empty( $row['job_id'] ) ) {
				CHTQ_FK::delete_draft_conversations( array( $cv ) );
			} else {
				CHTQ_FK::conversation_failed( $cv, $reason );
			}
		} catch ( Throwable $e ) {
			error_log( 'chtq: could not update skipped conversation ' . $cv . ': ' . $e->getMessage() );
		}
	}
}

/**
 * WP-CLI: wp chtq <status|enable|disable|hold|release|resume|drain|install|pixel>
 */
class CHTQ_CLI {

	/**
	 * The light open pixel: FunnelKit's email open pixel loads only FunnelKit's plugins.
	 *
	 * ## OPTIONS
	 *
	 * <on|off|status>
	 */
	public function pixel( $args ) {
		$s = CHTQ::settings( true );
		switch ( $args[0] ?? 'status' ) {
			case 'on':
				$s['light_pixel'] = true;
				CHTQ::save( $s );
				break;
			case 'off':
				$s['light_pixel'] = false;
				CHTQ::save( $s );
				break;
			case 'status':
				break;
			default:
				WP_CLI::error( 'Use: wp chtq pixel on|off|status' );
		}
		wp_cache_delete( CHTQ::OPT, 'options' );
		$did = CHTQ_Pixel::sync();
		WP_CLI::log( sprintf( 'light_pixel=%s, %s%s', CHTQ_Pixel::enabled() ? 'on' : 'off',
			is_file( CHTQ_Pixel::target() ) ? 'installed at ' . CHTQ_Pixel::target() : 'not installed', $did ? " ($did now)" : '' ) );
	}

	/** Show settings and queue counts. */
	public function status( $args, $assoc ) {
		global $wpdb;
		$s = CHTQ::settings();
		WP_CLI::log( sprintf( 'enabled=%s workers=%d rate=%s hold_until=%s paused=%s',
			$s['enabled'] ? 'yes' : 'no', $s['workers'], ( $er = CHTQ::effective_rate( $s['rate'] ) ) ? $er . '/s' . ( 'auto' === $s['rate'] ? ' (auto)' : '' ) : 'unlimited',
			$s['hold_until'] ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $s['hold_until'] ), 'Y-m-d H:i:s T' ) : 'none',
			$s['paused'] ?: 'no' ) );
		WP_CLI::log( sprintf( 'prebuild=%s prebuild_min=%d build_workers=%d funnelkit=%s', $s['prebuild'] ? 'on' : 'off', $s['prebuild_min'], $s['build_workers'], ( $p = CHTQ_FK::compat_problems() ) ? implode( '; ', $p ) : 'compatible' ) );
		WP_CLI::log( sprintf( 'light_pixel=%s (%s)', $s['light_pixel'] ? 'on' : 'off', is_file( CHTQ_Pixel::target() ) ? 'installed' : 'not installed' ) );
		$ts = CHTQ::tosend_info();
		if ( $ts ) {
			WP_CLI::log( sprintf( 'tosend: credits=%s health=%s limit=%s/s', $ts['account']['credit_balance'] ?? '?', $ts['account']['ses_status'] ?? '?', $ts['account']['limit_per_second'] ?? '?' ) );
		}
		$names = array( 'queued', 'in-flight', 'sent', 'failed', 'unsubscribed', 'timed-out', 'held', 'discarded' );
		$where = isset( $assoc['broadcast'] ) ? $wpdb->prepare( 'WHERE broadcast_id = %d', $assoc['broadcast'] ) : '';
		$rows  = $wpdb->get_results( 'SELECT broadcast_id, status, COUNT(*) n, MIN(created_at) first_q, MAX(sent_at) last_sent FROM ' . CHTQ::table() . " $where GROUP BY broadcast_id, status ORDER BY broadcast_id, status", ARRAY_A );
		foreach ( $rows as &$r ) {
			$r['status'] = $names[ $r['status'] ] ?? $r['status'];
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'broadcast_id', 'status', 'n', 'first_q', 'last_sent' ) );
		$errs = $wpdb->get_results( 'SELECT error, COUNT(*) n FROM ' . CHTQ::table() . " WHERE status IN (3,5) GROUP BY error ORDER BY n DESC LIMIT 10", ARRAY_A );
		if ( $errs ) {
			WP_CLI\Utils\format_items( 'table', $errs, array( 'n', 'error' ) );
		}
	}

	/** Create or update the queue table. */
	public function install() {
		CHTQ::install();
		WP_CLI::success( 'Table ready: ' . CHTQ::table() );
	}

	/** Start queueing broadcast emails. [--workers=<n>] */
	public function enable( $args, $assoc ) {
		CHTQ::install();
		$s            = CHTQ::settings();
		$s['enabled'] = true;
		if ( isset( $assoc['workers'] ) ) {
			$s['workers'] = max( 1, min( 16, (int) $assoc['workers'] ) );
		}
		CHTQ::save( $s );
		WP_CLI::success( 'Broadcast emails will be queued.' );
	}

	/** Stop queueing; broadcasts send through FluentSMTP again. Queued mail still drains. */
	public function disable() {
		$s            = CHTQ::settings();
		$s['enabled'] = false;
		CHTQ::save( $s );
		WP_CLI::success( 'Queueing off.' );
	}

	/**
	 * Hold the queue until a time (site timezone), e.g. `wp chtq hold "2026-10-12 12:00"`.
	 *
	 * ## OPTIONS
	 *
	 * <when>
	 */
	public function hold( $args ) {
		$ts = strtotime( get_gmt_from_date( $args[0] ) . ' UTC' );
		if ( ! $ts ) {
			WP_CLI::error( 'Could not read that time.' );
		}
		$s               = CHTQ::settings();
		$s['hold_until'] = $ts;
		CHTQ::save( $s );
		WP_CLI::success( 'Holding until ' . get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ), 'Y-m-d H:i:s T' ) );
	}

	/** Release a hold now. */
	public function release() {
		$s               = CHTQ::settings();
		$s['hold_until'] = 0;
		CHTQ::save( $s );
		WP_CLI::success( 'Released.' );
	}

	/**
	 * Put failed (3) or unknown (5) rows back in the queue. Check toSend's log first:
	 * unknown rows may already have been delivered.
	 *
	 * ## OPTIONS
	 *
	 * --status=<status>
	 * [--broadcast=<id>]
	 */
	public function requeue( $args, $assoc ) {
		global $wpdb;
		$st = (int) $assoc['status'];
		if ( ! in_array( $st, array( 3, 5 ), true ) ) {
			WP_CLI::error( '--status must be 3 or 5.' );
		}
		$sql = $wpdb->prepare( 'UPDATE ' . CHTQ::table() . ' SET status = 0, claim = NULL WHERE status = %d AND payload IS NOT NULL', $st );
		if ( isset( $assoc['broadcast'] ) ) {
			$sql .= $wpdb->prepare( ' AND broadcast_id = %d', $assoc['broadcast'] );
		}
		WP_CLI::success( (int) $wpdb->query( $sql ) . ' rows requeued.' );
	}

	/**
	 * Change a setting.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : One of prebuild (on or off), prebuild_min (a number), build_workers (1 to 8), workers (drain, 1 to 16), rate (emails a second, 0 for no limit).
	 *
	 * <value>
	 * : The new value.
	 */
	public function set( $args ) {
		list( $key, $val ) = $args;
		$s = CHTQ::settings();
		switch ( $key ) {
			case 'prebuild':
				$s['prebuild'] = in_array( strtolower( $val ), array( 'on', '1', 'yes', 'true' ), true );
				break;
			case 'prebuild_min':
				$s['prebuild_min'] = max( 1, (int) $val );
				break;
			case 'build_workers':
				$s['build_workers'] = max( 1, min( 8, (int) $val ) );
				break;
			case 'workers':
				$s['workers'] = max( 1, min( 16, (int) $val ) );
				break;
			case 'rate':
				$s['rate'] = 'auto' === strtolower( $val ) ? 'auto' : max( 0, (int) $val );
				break;
			default:
				WP_CLI::error( "Unknown setting $key." );
		}
		CHTQ::save( $s );
		WP_CLI::success( "$key = " . wp_json_encode( $s[ $key ] ) );
	}

	/** Pre-build housekeeping; run every minute from cron. */
	public function tick() {
		CHTQ_Prebuild::tick( function ( $m ) {
			WP_CLI::log( gmdate( 'H:i:s' ) . ' ' . $m );
		} );
	}

	/**
	 * Build pre-build contacts until idle. Run one per slot from cron.
	 *
	 * ## OPTIONS
	 *
	 * [--idle=<seconds>]
	 */
	public function work( $args, $assoc ) {
		if ( ! CHTQ_Prebuild::enabled() ) {
			return;
		}
		$log = function ( $m ) {
			WP_CLI::log( $m );
		};
		$idle = (int) ( $assoc['idle'] ?? 20 );
		$n    = CHTQ_FK::is_v2_flow() ? CHTQ_Prebuild_V2::work( $idle, $log ) : CHTQ_Prebuild::work( 0, 0, $idle, $log );
		if ( $n ) {
			WP_CLI::log( gmdate( 'H:i:s' ) . " worker done, built $n" );
		}
	}

	/** List pre-build jobs. */
	public function jobs() {
		global $wpdb;
		$jt   = CHTQ_Prebuild::jobs_table();
		$bt   = CHTQ_Prebuild::build_table();
		$rows = $wpdb->get_results( "SELECT j.id, j.broadcast_id, j.state, j.execution_time, j.total, j.test_size, j.winner_tid,
			(SELECT COUNT(*) FROM $bt b WHERE b.job_id = j.id AND b.status = 2) built,
			(SELECT COUNT(*) FROM $bt b WHERE b.job_id = j.id AND b.status = 3) not_built,
			LEFT(j.message, 80) message FROM $jt j ORDER BY j.id DESC LIMIT 20", ARRAY_A );
		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'broadcast_id', 'state', 'execution_time', 'total', 'built', 'not_built', 'test_size', 'winner_tid', 'message' ) );
	}

	/**
	 * Start pre-building one scheduled broadcast now, ignoring the size minimum.
	 *
	 * ## OPTIONS
	 *
	 * <broadcast_id>
	 */
	public function prebuild( $args ) {
		if ( ! CHTQ_Prebuild::enabled() ) {
			WP_CLI::error( 'Turn on the queue and pre-building first (wp chtq enable; wp chtq set prebuild on).' );
		}
		$b = CHTQ_FK::broadcast( (int) $args[0] );
		if ( ! $b || CHTQ_FK::STATUS_SCHEDULED !== (int) $b['status'] ) {
			WP_CLI::error( 'That broadcast is not scheduled.' );
		}
		$r = CHTQ_Prebuild::create_job( $b );
		is_wp_error( $r ) ? WP_CLI::error( $r->get_error_message() ) : WP_CLI::success( "Job $r started." );
	}

	/**
	 * Throw away a job's pre-built emails; FunnelKit then sends the broadcast itself.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 */
	public function discard( $args ) {
		$job = CHTQ_Prebuild::job( (int) $args[0] );
		if ( ! $job ) {
			WP_CLI::error( 'No such job.' );
		}
		CHTQ_Prebuild::discard( $job, 'discarded by hand' );
		WP_CLI::success( 'Discarded.' );
	}

	/**
	 * Find and replace text in a broadcast's queued or held emails, without rebuilding.
	 * For fixing a typo, link or price after the emails were built.
	 *
	 * ## OPTIONS
	 *
	 * <broadcast_id>
	 *
	 * --find=<text>
	 *
	 * --replace=<text>
	 *
	 * [--subject]
	 * : Also replace in the subject line.
	 *
	 * [--accept-current-content]
	 * : The same edit was also made in FunnelKit; keep the pre-built copy instead of rebuilding.
	 *
	 * [--dry-run]
	 */
	public function replace( $args, $assoc ) {
		global $wpdb;
		$bid  = (int) $args[0];
		$find = (string) $assoc['find'];
		if ( '' === $find ) {
			WP_CLI::error( '--find cannot be empty.' );
		}
		$rep     = (string) $assoc['replace'];
		$dry     = isset( $assoc['dry-run'] );
		$t       = CHTQ::table();
		$changed = 0;
		$last    = 0;
		while ( true ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, payload FROM $t WHERE broadcast_id = %d AND status IN (0,6) AND id > %d AND payload IS NOT NULL ORDER BY id LIMIT 500", $bid, $last ), ARRAY_A );
			if ( ! $rows ) {
				break;
			}
			foreach ( $rows as $r ) {
				$last  = (int) $r['id'];
				$email = json_decode( (string) @gzuncompress( $r['payload'] ), true );
				if ( ! is_array( $email ) ) {
					continue;
				}
				$before = $email['html'] . "\0" . $email['subject'];
				$email['html'] = str_replace( $find, $rep, $email['html'] );
				if ( isset( $assoc['subject'] ) ) {
					$email['subject'] = str_replace( $find, $rep, $email['subject'] );
				}
				if ( $before === $email['html'] . "\0" . $email['subject'] ) {
					continue;
				}
				$changed++;
				if ( ! $dry ) {
					// Only rows still waiting; a row the drain claimed meanwhile is left alone.
					$wpdb->query( $wpdb->prepare( "UPDATE $t SET payload = %s WHERE id = %d AND status IN (0,6)", gzcompress( wp_json_encode( $email ), 6 ), $r['id'] ) );
				}
			}
		}
		if ( isset( $assoc['accept-current-content'] ) && ! $dry ) {
			$b = CHTQ_FK::broadcast( $bid );
			if ( $b ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . CHTQ_Prebuild::jobs_table() . " SET fingerprint = %s WHERE broadcast_id = %d AND state IN ('building','ready')", CHTQ_Prebuild::fingerprint( $b['data'] ), $bid ) );
			}
		}
		WP_CLI::success( ( $dry ? 'Would change ' : 'Changed ' ) . "$changed emails." );
	}

	/**
	 * Delete old records: finished queue rows after --days (default 90), payloads of
	 * failed rows after 30 days, build lists of finished jobs after 14 days.
	 * Runs daily from cron.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 *
	 * [--rotate-logs=<dir>]
	 * : Also trim each *.log file in this folder that is over 5 MB to its last 20,000 lines.
	 */
	public function prune( $args, $assoc ) {
		$out = CHTQ_Prebuild::prune( max( 7, (int) ( $assoc['days'] ?? 90 ) ) );
		WP_CLI::success( sprintf( 'Deleted %d queue rows, cleared %d failed payloads, deleted %d build rows.', $out['queue'], $out['payloads'], $out['build'] ) );
		if ( ! empty( $assoc['rotate-logs'] ) ) {
			foreach ( (array) glob( rtrim( $assoc['rotate-logs'], '/' ) . '/*.log' ) as $file ) {
				if ( ! is_file( $file ) || ! is_writable( $file ) || filesize( $file ) <= 5 * MB_IN_BYTES ) {
					continue;
				}
				$keep = new SplFileObject( $file, 'r' );
				$keep->seek( PHP_INT_MAX );
				$total = $keep->key();
				$keep->seek( max( 0, $total - 20000 ) );
				$tail = '';
				while ( ! $keep->eof() ) {
					$tail .= $keep->fgets();
				}
				$keep = null;
				file_put_contents( $file, $tail, LOCK_EX );
				WP_CLI::log( 'Trimmed ' . basename( $file ) . ' to its last 20,000 lines.' );
			}
		}
	}

	/** Clear a pause set after toSend refused a whole batch. */
	public function resume() {
		$s           = CHTQ::settings();
		$s['paused'] = '';
		CHTQ::save( $s );
		WP_CLI::success( 'Resumed.' );
	}

	/**
	 * Send queued emails until the queue stays empty for --idle seconds.
	 *
	 * ## OPTIONS
	 *
	 * [--workers=<n>]
	 * [--rate=<emails a second>]
	 * [--idle=<seconds>]
	 * [--max=<emails>]
	 *
	 * With a rate, batches go one at a time, spaced so the average stays under it
	 * (toSend's guidance: one 100-email batch about every 3.5 seconds for 30/s).
	 */
	public function drain( $args, $assoc ) {
		global $wpdb;
		$t       = CHTQ::table();
		$s       = CHTQ::settings();
		$workers = max( 1, min( 16, (int) ( $assoc['workers'] ?? $s['workers'] ) ) );
		$idle    = (int) ( $assoc['idle'] ?? 45 );
		$max     = (int) ( $assoc['max'] ?? 0 );
		$sent    = 0;
		$idle_at = null;
		$backoff = 0;
		$conns   = array();
		$next_at = 0.0; // microtime before which the next round may not start (rate limit)
		// Batches whose outcome was unclear (timeout, 5xx). They are resent with the
		// same body and Idempotency-Key, so toSend either replays its first answer
		// or processes them as new: never a second copy.
		$replay  = array();

		// Only one drain runs at a time (flock), so anything still in flight is
		// from a drain that died mid-request. It may have been sent: never resend
		// automatically. `wp chtq requeue --status=5` after checking toSend.
		$wpdb->query( "UPDATE $t SET status = 5, claim = NULL, error = 'drain interrupted mid-request' WHERE status = 1" );

		// Refuse to start if the toSend connection cannot be read, rather than
		// marking the whole queue failed.
		$fs = function_exists( 'fluentMailGetSettings' ) ? fluentMailGetSettings() : array();
		$default_from = '';
		$dkey = $fs['misc']['default_connection'] ?? '';
		if ( $dkey && isset( $fs['connections'][ $dkey ]['provider_settings']['sender_email'] ) ) {
			$default_from = $fs['connections'][ $dkey ]['provider_settings']['sender_email'];
		}
		if ( ! $default_from || ! CHTQ::connection_for( $default_from ) ) {
			WP_CLI::error( 'No usable toSend connection in FluentSMTP. Nothing claimed.' );
		}

		while ( true ) {
			$s = CHTQ::settings( true );
			if ( $s['paused'] ) {
				WP_CLI::warning( 'Paused: ' . $s['paused'] );
				return;
			}
			if ( $s['hold_until'] && time() < $s['hold_until'] ) {
				return;
			}
			if ( $max && $sent >= $max && ! $replay ) {
				break;
			}
			$rate   = CHTQ::effective_rate( $assoc['rate'] ?? $s['rate'] );
			$rounds = $rate ? max( 1, min( $workers, intdiv( $rate, 100 ) ) ) : $workers;

			// Claim up to $rounds batches of 100, less any batches being resent.
			$batches = array();
			for ( $i = 0; $i < $rounds - count( $replay ); $i++ ) {
				$claim = wp_generate_uuid4();
				$limit = $max ? min( 100, $max - $sent - 100 * $i ) : 100;
				if ( $limit <= 0 ) {
					break;
				}
				$n = $wpdb->query( $wpdb->prepare( "UPDATE $t SET status = 1, claim = %s WHERE status = 0 ORDER BY id LIMIT %d", $claim, $limit ) );
				if ( ! $n ) {
					break;
				}
				$batches[ $claim ] = $wpdb->get_results( $wpdb->prepare( "SELECT id, to_email, payload, attempts, conversation_id, job_id, created_at FROM $t WHERE claim = %s ORDER BY id", $claim ), ARRAY_A );
			}

			if ( ! $batches && ! $replay ) {
				$idle_at = $idle_at ?: time();
				if ( time() - $idle_at >= $idle ) {
					break;
				}
				sleep( 2 );
				continue;
			}
			$idle_at = null;

			// Drop anyone who unsubscribed since their email was built.
			$all = array();
			foreach ( $batches as $rows ) {
				foreach ( $rows as $r ) {
					$all[] = $r['to_email'];
				}
			}
			$all   = array_values( array_unique( array_merge( $all, array_map( 'strtolower', $all ) ) ) );
			// Only unsubscribes made AFTER the email was built count: FunnelKit already
			// chose the audience (a non-promotional broadcast deliberately includes people
			// who unsubscribed from marketing), so earlier ones are FunnelKit's decision.
			$unsub = array();
			if ( $all ) {
				$ph = implode( ',', array_fill( 0, count( $all ), '%s' ) );
				foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT LOWER(recipient) r, MAX(c_date) d FROM {$wpdb->prefix}bwfan_message_unsubscribe WHERE mode = 1 AND recipient IN ($ph) GROUP BY LOWER(recipient)", $all ), ARRAY_A ) as $u ) {
					$unsub[ $u['r'] ] = $u['d'];
				}
			}

			$wait = $next_at - microtime( true );
			if ( $wait > 0 ) {
				usleep( (int) ( $wait * 1e6 ) );
			}
			$round_at = microtime( true );
			$in_round = 0;
			$mh   = curl_multi_init();
			$reqs = array();
			$retry_after = array(); // Retry-After seconds by index in $reqs
			$replayed    = array(); // index in $reqs => true when toSend says Idempotent-Replayed
			$add = function ( $req ) use ( $mh, &$reqs, &$retry_after, &$replayed ) {
				$ch = curl_init( CHTQ::API );
				curl_setopt_array( $ch, array(
					CURLOPT_POST           => true,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_CONNECTTIMEOUT => 10,
					CURLOPT_TIMEOUT        => 120,
					CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
					CURLOPT_CAINFO         => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
					CURLOPT_SSL_VERIFYPEER => true,
					CURLOPT_SSL_VERIFYHOST => 2,
					CURLOPT_FOLLOWLOCATION => false,
					CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
					CURLOPT_HTTPHEADER     => array( 'Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $req['api'], 'Idempotency-Key: ' . $req['key'] ),
					CURLOPT_POSTFIELDS     => $req['post'],
				) );
				$key = count( $reqs );
				curl_setopt( $ch, CURLOPT_HEADERFUNCTION, function ( $c, $h ) use ( &$retry_after, &$replayed, $key ) {
					if ( preg_match( '/^retry-after:\s*(\d+)/i', $h, $m ) ) {
						$retry_after[ $key ] = (int) $m[1];
					} elseif ( preg_match( '/^idempotent-replayed:\s*true/i', $h ) ) {
						$replayed[ $key ] = true;
					}
					return strlen( $h );
				} );
				curl_multi_add_handle( $mh, $ch );
				$req['ch'] = $ch;
				$reqs[]    = $req;
			};
			foreach ( $replay as $req ) {
				$in_round += count( $req['ids'] );
				$add( $req );
			}
			$replay = array();
			foreach ( $batches as $claim => $rows ) {
				$emails = $ids = $convs = array();
				$api    = null;
				foreach ( $rows as $r ) {
					$u_at = $unsub[ strtolower( $r['to_email'] ) ] ?? null;
					if ( $u_at && $u_at >= get_date_from_gmt( $r['created_at'] ) ) {
						$wpdb->update( $t, array( 'status' => CHTQ::STATUS_UNS, 'payload' => null ), array( 'id' => $r['id'] ) );
						CHTQ::funnelkit_skipped( $r, 'Unsubscribed before send' );
						continue;
					}
					$raw   = $r['payload'] ? @gzuncompress( $r['payload'] ) : false;
					$email = $raw ? json_decode( $raw, true ) : null;
					if ( ! is_array( $email ) || empty( $email['from']['email'] ) ) {
						$wpdb->update( $t, array( 'status' => CHTQ::STATUS_ERR, 'error' => 'bad payload' ), array( 'id' => $r['id'] ) );
						continue;
					}
					$from  = $email['from']['email'];
					if ( ! isset( $conns[ $from ] ) ) {
						$conns[ $from ] = CHTQ::connection_for( $from );
					}
					$conn = $conns[ $from ];
					if ( ! $conn ) {
						$wpdb->update( $t, array( 'status' => CHTQ::STATUS_ERR, 'error' => 'No toSend connection for ' . $from ), array( 'id' => $r['id'] ) );
						continue;
					}
					// Same From rules as FluentSMTP's toSend handler, so a queued email
					// goes out exactly as it would have without the queue.
					if ( 'yes' === ( $conn['force_from_email'] ?? '' ) && function_exists( 'fluentMailIsListedSenderEmail' ) && ! fluentMailIsListedSenderEmail( $from ) ) {
						$email['from']['email'] = $conn['sender_email'];
					}
					if ( 'yes' === ( $conn['force_from_name'] ?? '' ) && ! empty( $conn['sender_name'] ) ) {
						$email['from']['name'] = $conn['sender_name'];
					}
					$api      = $conn['api_key']; // one connection per site today
					$emails[] = $email;
					$ids[]    = (int) $r['id'];
					$convs[]  = (int) $r['conversation_id'];
				}
				if ( ! $emails ) {
					continue;
				}
				$in_round += count( $emails );
				$post = wp_json_encode( array( 'emails' => $emails ) );
				unset( $emails );
				// The claim is unique per batch; toSend keeps keys for 24 hours.
				$add( array( 'ids' => $ids, 'convs' => $convs, 'len' => strlen( $post ), 'post' => $post, 'api' => $api, 'key' => 'chtq-' . $claim, 'tries' => 1 ) );
				unset( $post );
			}
			unset( $batches );

			do {
				$st = curl_multi_exec( $mh, $running );
				if ( $running ) {
					curl_multi_select( $mh, 1.0 );
				}
			} while ( $running && CURLM_OK === $st );

			if ( $rate ) {
				$next_at = $round_at + $in_round / $rate;
			}
			$retry = false;
			$limited = 0; // seconds toSend asked us to wait (429 Retry-After)
			foreach ( $reqs as $k => $req ) {
				$ch   = $req['ch'];
				$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
				$errn = curl_errno( $ch );
				$body = json_decode( (string) curl_multi_getcontent( $ch ), true );
				$up   = (int) curl_getinfo( $ch, CURLINFO_SIZE_UPLOAD );
				curl_multi_remove_handle( $mh, $ch );
				curl_close( $ch );
				$in   = implode( ',', $req['ids'] );
				$now  = current_time( 'mysql', true );

				// No clear answer (curl error or 5xx): resend the same body with the same
				// Idempotency-Key. If toSend stored the first try it replays that answer,
				// otherwise it processes it as new, so this never sends a second copy.
				// After 4 tries, a batch that ever fully reached toSend is marked unknown
				// (status 5, check toSend before requeueing); one that never did is put back.
				$req['uploaded'] = ! empty( $req['uploaded'] ) || ( $errn ? $up >= $req['len'] : $code > 0 );
				$unclear         = $errn || $code >= 500;
				if ( isset( $replayed[ $k ] ) ) {
					WP_CLI::log( gmdate( 'H:i:s' ) . ' toSend replayed its first answer for ' . $req['key'] );
				}

				if ( $unclear && $req['tries'] < 4 ) {
					unset( $req['ch'] );
					$req['tries']++;
					$replay[] = $req;
					$retry    = true;
					WP_CLI::log( gmdate( 'H:i:s' ) . ' ' . ( $errn ? 'curl ' . $errn : 'http ' . $code ) . ', resending ' . $req['key'] . ' (try ' . $req['tries'] . ')' );
				} elseif ( 429 === $code && $req['tries'] > 1 ) {
					// A resend that was rate limited keeps its key and body.
					unset( $req['ch'] );
					$replay[] = $req;
					$limited  = max( $limited, min( 60, $retry_after[ $k ] ?? 5 ) );
				} elseif ( $code >= 200 && $code < 300 && ! ( isset( $body['results'] ) && is_array( $body['results'] ) ) ) {
					$wpdb->query( $wpdb->prepare( "UPDATE $t SET status = 2, sent_at = %s, payload = NULL, error = %s WHERE id IN ($in)", $now, 'http ' . $code . ' without results' ) );
					$sent += count( $req['ids'] );
					CHTQ::funnelkit_sent( $req['convs'], $now );
				} elseif ( ( 200 === $code || 207 === $code ) ) {
					$ok_case = $ok_ids = $ok_convs = array();
					$wpdb->query( 'START TRANSACTION' );
					foreach ( $req['ids'] as $i => $id ) {
						$res = $body['results'][ $i ] ?? null;
						if ( $res && 'success' === ( $res['status'] ?? '' ) ) {
							$ok_case[]  = 'WHEN ' . (int) $id . " THEN '" . esc_sql( substr( (string) ( $res['message_id'] ?? '' ), 0, 100 ) ) . "'";
							$ok_ids[]   = (int) $id;
							$ok_convs[] = $req['convs'][ $i ];
							$sent++;
						} else {
							$msg = $res ? ( ( $res['status'] ?? '' ) . ': ' . ( $res['message'] ?? wp_json_encode( $res['errors'] ?? '' ) ) ) : 'missing result';
							$wpdb->query( $wpdb->prepare( "UPDATE $t SET status = 3, error = %s, sent_at = %s WHERE id = %d", substr( $msg, 0, 500 ), $now, $id ) );
							CHTQ::funnelkit_failed( $req['convs'][ $i ], 'toSend: ' . $msg );
						}
					}
					if ( $ok_ids ) {
						// Every part is already escaped (prepare per CASE arm, ints, esc_sql), so no
						// outer prepare(): a % inside a message id must not be re-read as a placeholder.
						$wpdb->query( "UPDATE $t SET status = 2, sent_at = '" . esc_sql( $now ) . "', payload = NULL, error = NULL, message_id = CASE id " . implode( ' ', $ok_case ) . ' END WHERE id IN (' . implode( ',', $ok_ids ) . ')' );
						CHTQ::funnelkit_sent( $ok_convs, $now );
					}
					$wpdb->query( 'COMMIT' );
				} elseif ( $unclear && $req['uploaded'] ) {
					$why = ( $errn ? 'curl ' . $errn : 'http ' . $code ) . ' after ' . $req['tries'] . ' tries with key ' . $req['key'];
					$wpdb->query( $wpdb->prepare( "UPDATE $t SET status = 5, claim = NULL, error = %s WHERE id IN ($in)", $why . ': may have been sent, not retried' ) );
				} elseif ( 429 === $code ) {
					// Rate limited: nothing in the batch was accepted. Put it back without
					// using up an attempt, and wait as long as toSend asks.
					$wpdb->query( $wpdb->prepare( "UPDATE $t SET status = 0, claim = NULL, error = %s WHERE id IN ($in)", 'http 429, retried' ) );
					$limited = max( $limited, min( 60, $retry_after[ $k ] ?? 5 ) );
				} elseif ( $unclear ) {
					// Never reached toSend in 4 tries: safe to put back as new batches.
					$why = 'curl ' . $errn . ', never reached toSend';
					$wpdb->query( $wpdb->prepare( "UPDATE $t SET status = IF(attempts >= 4, 3, 0), attempts = attempts + 1, claim = NULL, error = %s WHERE id IN ($in)", $why ) );
					$retry = true;
				} else {
					// 401/403/422 for the whole batch: put it back and stop everything.
					$msg = 'http ' . $code . ': ' . substr( wp_json_encode( $body ), 0, 300 );
					$wpdb->query( $wpdb->prepare( "UPDATE $t SET status = 0, claim = NULL, error = %s WHERE id IN ($in)", $msg ) );
					$s           = CHTQ::settings();
					$s['paused'] = $msg;
					CHTQ::save( $s );
				}
			}
			curl_multi_close( $mh );

			if ( $limited ) {
				WP_CLI::log( gmdate( 'H:i:s' ) . " rate limited by toSend, waiting {$limited}s" );
				$next_at = max( $next_at, microtime( true ) + $limited );
			}
			if ( $retry ) {
				$backoff = min( 30, $backoff ? $backoff * 2 : 2 );
				sleep( $backoff );
			} else {
				$backoff = 0;
			}
			WP_CLI::log( gmdate( 'H:i:s' ) . " sent so far: $sent" );
		}
		if ( $sent ) {
			WP_CLI::log( gmdate( 'H:i:s' ) . " done, sent $sent" ); // idle runs stay silent so the log only records sends
		}
	}
}

CHTQ::init();
register_activation_hook( __FILE__, array( 'CHTQ', 'install' ) );
register_deactivation_hook( __FILE__, array( 'CHTQ_Pixel', 'remove' ) );
