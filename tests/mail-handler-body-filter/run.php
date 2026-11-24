<?php
/**
 * Runs CHTQ_Mail_Handler's body filtering for real, outside WordPress:
 *
 *     php tests/mail-handler-body-filter/run.php
 *
 * Loads the actual includes/class-chtq-mail-handler.php, not a copy. WordPress, FunnelKit and the
 * rest of this plugin are replaced by the small stand-ins below. Nothing here touches a database,
 * a network or a real send.
 *
 * What it checks:
 *   1. bwfan_before_send_email_body is applied, once, before the email is queued.
 *   2. The queue path and the wp_mail fallback send the same body, so a reader never gets a
 *      different email depending on whether the queue happened to be on.
 *   3. chtq_apply_before_send_email_body turns it off.
 *   4. A listener that throws does not take the send with it, and the original body goes.
 *   5. A listener that returns a non-string, or an empty one, does not blank the email.
 *   6. A body that is already empty or not a string is left alone.
 *   7. The per-mail array reaches listeners as the second argument.
 *   8. FunnelKit's own UTM listener is a no-op on this path rather than a double-application.
 *
 * Exit code 0 when every check passes, 1 otherwise. Added 2026-11-24 with the filter.
 */

error_reporting( E_ALL );

// error_log() is a built-in and cannot be redeclared, so it is pointed at a file of our own and
// read back. The plugin logs with error_log( 'chtq: ...' ), which is this repo's convention.
$log_file = sys_get_temp_dir() . '/chtq-test-' . getmypid() . '.log';
@unlink( $log_file );
ini_set( 'log_errors', '1' );
ini_set( 'error_log', $log_file );

const ABSPATH = '/';

$root = dirname( __DIR__, 2 );

// ── WordPress stand-ins ─────────────────────────────────────────────────────────────────────
$GLOBALS['T'] = array( 'filters' => array(), 'queued' => array(), 'mailed' => array(), 'logs' => array() );

function add_filter( $h, $cb, $p = 10, $n = 1 ) {
	$GLOBALS['T']['filters'][ $h ][ $p ][] = array( $cb, $n );
}
function apply_filters( $h, $v, ...$args ) {
	$f = $GLOBALS['T']['filters'][ $h ] ?? array();
	ksort( $f );
	foreach ( $f as $cbs ) {
		foreach ( $cbs as list( $cb, $n ) ) {
			$v = $cb( ...array_slice( array_merge( array( $v ), $args ), 0, max( 1, $n ) ) );
		}
	}
	return $v;
}
function do_action( $h, ...$args ) {
	$GLOBALS['T']['actions'][] = $h;
}
function wp_mail( $to, $subject, $body, $headers = array() ) {
	$GLOBALS['T']['mailed'][] = array( 'to' => $to, 'subject' => $subject, 'body' => $body );
	return true;
}

// ── FunnelKit and plugin stand-ins ──────────────────────────────────────────────────────────
class BWFAN_Mail_Handler_Controller {
	/** FunnelKit's own handler: headers only, then wp_mail. It does NOT apply the body filter. */
	public function send_mail( $mail_data ) {
		return wp_mail( $mail_data['to'], $mail_data['subject'], $mail_data['body'], array() );
	}
}
class BWFAN_PRO_Common {
	public static function build_reply_to_header( $replyto, $name, $from ) {
		return 'Reply-To: ' . $replyto;
	}
}
class BWFCRM_Common {
	public static $captured_email_failed_message = '';
}
class CHTQ {
	public static $context  = array();
	public static $settings = array( 'enabled' => 1 );
	public static function settings() {
		return self::$settings;
	}
	public static function enqueue( $to, $subject, $body, $headers, $broadcast_id, $contact_id, $engagement_id ) {
		$GLOBALS['T']['queued'][] = array( 'to' => $to, 'subject' => $subject, 'body' => $body );
		return true;
	}
}

require_once $root . '/includes/class-chtq-mail-handler.php';

// ── harness ─────────────────────────────────────────────────────────────────────────────────
$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

function ok( $condition, $label, $detail = '' ) {
	if ( $condition ) {
		$GLOBALS['pass']++;
		echo "  ok    $label\n";
		return true;
	}
	$GLOBALS['fail']++;
	echo "  FAIL  $label\n";
	if ( '' !== $detail ) {
		echo "        " . str_replace( "\n", "\n        ", $detail ) . "\n";
	}
	return false;
}
function group( $name ) {
	echo "\n$name\n";
}
function reset_state() {
	$GLOBALS['T']['filters'] = array();
	$GLOBALS['T']['queued']  = array();
	$GLOBALS['T']['mailed']  = array();
	CHTQ::$context  = array();
	CHTQ::$settings = array( 'enabled' => 1 );
	@unlink( $GLOBALS['log_file'] );
}

/** The chtq lines written since the last reset. */
function logs() {
	$raw = @file_get_contents( $GLOBALS['log_file'] );
	if ( false === $raw || '' === trim( (string) $raw ) ) {
		return array();
	}
	return array_values( array_filter( explode( "\n", trim( $raw ) ), static function ( $l ) {
		return false !== strpos( $l, 'chtq: ' );
	} ) );
}
function mail_data( $body = '<html><body>original</body></html>' ) {
	return array(
		'to'            => 'reader@example.com',
		'subject'       => 'Subject',
		'body'          => $body,
		'senders_name'  => '5DayDeal',
		'senders_email' => 'support@5daydeal.com',
		'replyto'       => 'support@5daydeal.com',
		'contact_id'    => 123,
		'engagement_id' => 0,
	);
}

// ── 1. the filter is applied, once, before queueing ─────────────────────────────────────────
group( '1. the filter is applied before the email is queued' );

reset_state();
$calls = 0;
add_filter( 'bwfan_before_send_email_body', function ( $body, $data ) use ( &$calls ) {
	$calls++;
	return str_replace( 'original', 'transformed', $body );
}, 10, 2 );

$h = new CHTQ_Mail_Handler();
ok( true === $h->send_mail( mail_data() ), 'send_mail returns true' );
ok( 1 === $calls, 'the filter ran exactly once', "ran $calls times" );
ok( 1 === count( $GLOBALS['T']['queued'] ), 'one email was queued' );
ok( false !== strpos( $GLOBALS['T']['queued'][0]['body'], 'transformed' ), 'the queued body is the filtered one', $GLOBALS['T']['queued'][0]['body'] );
ok( false === strpos( $GLOBALS['T']['queued'][0]['body'], 'original' ), 'the unfiltered body was not queued' );
ok( 0 === count( $GLOBALS['T']['mailed'] ), 'nothing went through wp_mail on the queue path' );

// ── 2. the fallback path sends the same body ────────────────────────────────────────────────
group( '2. the wp_mail fallback sends the same body as the queue' );

reset_state();
add_filter( 'bwfan_before_send_email_body', function ( $body ) {
	return str_replace( 'original', 'transformed', $body );
} );
CHTQ::$settings = array( 'enabled' => 0 ); // queue off: falls through to the parent handler
$h = new CHTQ_Mail_Handler();
$h->send_mail( mail_data() );
ok( 1 === count( $GLOBALS['T']['mailed'] ), 'the email went through wp_mail' );
ok( false !== strpos( $GLOBALS['T']['mailed'][0]['body'], 'transformed' ), 'the fallback body is filtered too', $GLOBALS['T']['mailed'][0]['body'] );

reset_state();
add_filter( 'bwfan_before_send_email_body', function ( $body ) {
	return str_replace( 'original', 'transformed', $body );
} );
$h = new CHTQ_Mail_Handler();
$h->send_mail( mail_data() );
$queued_body = $GLOBALS['T']['queued'][0]['body'];
reset_state();
add_filter( 'bwfan_before_send_email_body', function ( $body ) {
	return str_replace( 'original', 'transformed', $body );
} );
CHTQ::$settings = array( 'enabled' => 0 );
$h = new CHTQ_Mail_Handler();
$h->send_mail( mail_data() );
ok( $queued_body === $GLOBALS['T']['mailed'][0]['body'], 'both paths produce a byte-identical body' );

// ── 3. the off switch ───────────────────────────────────────────────────────────────────────
group( '3. chtq_apply_before_send_email_body turns it off' );

reset_state();
$ran = false;
add_filter( 'bwfan_before_send_email_body', function ( $body ) use ( &$ran ) {
	$ran = true;
	return 'SHOULD NOT HAPPEN';
} );
add_filter( 'chtq_apply_before_send_email_body', function () {
	return false;
} );
$h = new CHTQ_Mail_Handler();
$h->send_mail( mail_data() );
ok( ! $ran, 'the body filter never ran' );
ok( false !== strpos( $GLOBALS['T']['queued'][0]['body'], 'original' ), 'the original body was queued' );

// ── 4. a listener that throws ───────────────────────────────────────────────────────────────
group( '4. a listener that throws does not take the send with it' );

foreach ( array( 'Exception' => new Exception( 'listener blew up' ), 'Error' => new Error( 'fatal in listener' ) ) as $kind => $thrown ) {
	reset_state();
	add_filter( 'bwfan_before_send_email_body', function () use ( $thrown ) {
		throw $thrown;
	} );
	$h      = new CHTQ_Mail_Handler();
	$result = $h->send_mail( mail_data() );
	ok( true === $result, "$kind: the email was still sent" );
	ok( 1 === count( $GLOBALS['T']['queued'] ), "$kind: it was queued" );
	ok( false !== strpos( $GLOBALS['T']['queued'][0]['body'], 'original' ), "$kind: the original body went" );
	ok( 1 === count( logs() ) && false !== strpos( logs()[0], 'threw' ), "$kind: it was logged once", implode( ' | ', logs() ) );
}

// ── 5. a listener that returns rubbish ──────────────────────────────────────────────────────
group( '5. a listener that returns rubbish does not blank the email' );

foreach ( array( 'null' => null, 'false' => false, 'array' => array( 'x' ), 'empty string' => '', 'whitespace' => "  \n\t ", 'int' => 0 ) as $kind => $return ) {
	reset_state();
	add_filter( 'bwfan_before_send_email_body', function () use ( $return ) {
		return $return;
	} );
	$h = new CHTQ_Mail_Handler();
	$h->send_mail( mail_data() );
	ok(
		1 === count( $GLOBALS['T']['queued'] ) && false !== strpos( $GLOBALS['T']['queued'][0]['body'], 'original' ),
		"returning $kind leaves the original body",
		isset( $GLOBALS['T']['queued'][0] ) ? var_export( $GLOBALS['T']['queued'][0]['body'], true ) : 'nothing queued'
	);
	ok( 1 === count( logs() ), "returning $kind is logged once", implode( ' | ', logs() ) );
}

// A listener returning a perfectly good shorter body is honoured, so the guard is not too eager.
reset_state();
add_filter( 'bwfan_before_send_email_body', function () {
	return 'x';
} );
$h = new CHTQ_Mail_Handler();
$h->send_mail( mail_data() );
ok( 'x' === $GLOBALS['T']['queued'][0]['body'], 'a short but real body is honoured' );
ok( 0 === count( logs() ), 'and nothing is logged for it' );

// ── 6. a body that is empty or not a string ─────────────────────────────────────────────────
group( '6. an empty or non-string body is left alone' );

foreach ( array( 'empty' => '', 'null' => null, 'array' => array() ) as $kind => $body ) {
	reset_state();
	$ran = false;
	add_filter( 'bwfan_before_send_email_body', function ( $b ) use ( &$ran ) {
		$ran = true;
		return $b;
	} );
	$h = new CHTQ_Mail_Handler();
	$h->send_mail( mail_data( $body ) );
	ok( ! $ran, "a $kind body never reaches the filter" );
}

// ── 7. the per-mail array reaches listeners ─────────────────────────────────────────────────
group( '7. listeners get the per-mail array' );

reset_state();
$seen = null;
add_filter( 'bwfan_before_send_email_body', function ( $body, $data ) use ( &$seen ) {
	$seen = $data;
	return $body;
}, 10, 2 );
$h = new CHTQ_Mail_Handler();
$h->send_mail( mail_data() );
ok( is_array( $seen ), 'the second argument is an array' );
ok( 'reader@example.com' === ( $seen['to'] ?? '' ), 'it carries the recipient' );
ok( 123 === ( $seen['contact_id'] ?? 0 ), 'it carries the contact id' );
ok( ! isset( $seen['append_utm'] ), 'it has no append_utm, which is what makes the UTM listener a no-op here' );

// ── 8. FunnelKit's UTM listener is a no-op on this path ─────────────────────────────────────
group( "8. FunnelKit's own listener does not double-apply" );

reset_state();
// The relevant half of BWFAN_UTM_Tracking::maybe_add_utm_parameters(): it returns the body
// untouched unless append_utm is 1. Broadcast UTM parameters are added by the controller,
// through append_to_email_body_links(), long before the handler is reached.
add_filter( 'bwfan_before_send_email_body', function ( $body, $data ) {
	if ( ! isset( $data['append_utm'] ) || 1 !== absint( $data['append_utm'] ) ) {
		return $body;
	}
	return str_replace( 'href="https://example.com/"', 'href="https://example.com/?utm_source=x"', $body );
}, 10, 2 );

$with_link = '<html><body>original <a href="https://example.com/">go</a></body></html>';
$h         = new CHTQ_Mail_Handler();
$h->send_mail( mail_data( $with_link ) );
ok( $with_link === $GLOBALS['T']['queued'][0]['body'], 'the body is untouched, so UTM is not applied twice', $GLOBALS['T']['queued'][0]['body'] );
ok( 0 === substr_count( $GLOBALS['T']['queued'][0]['body'], 'utm_source' ), 'no utm_source was appended' );

// ── result ──────────────────────────────────────────────────────────────────────────────────
echo "\n";
echo $GLOBALS['pass'] . " passed, " . $GLOBALS['fail'] . " failed\n";
exit( $GLOBALS['fail'] > 0 ? 1 : 0 );
