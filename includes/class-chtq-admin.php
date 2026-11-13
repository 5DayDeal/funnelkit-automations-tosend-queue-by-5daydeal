<?php
/**
 * Tools > toSend Queue: performance dashboard, settings, toSend account, recent sends
 * and pre-build jobs. Administrators only. Charts are drawn on the server as inline
 * SVG; no outside scripts are loaded into the admin.
 */

defined( 'ABSPATH' ) || exit;

final class CHTQ_Admin {

	const SLUG = 'chtq';

	/** Categorical colours, fixed order (validated set: blue, orange, aqua). */
	const COLORS = array(
		'funnelkit' => '#2a78d6',
		'queue'     => '#eb6834',
		'prebuilt'  => '#1baf7a',
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_chtq_save', array( __CLASS__, 'save' ) );
	}

	public static function menu() {
		add_management_page( 'toSend Queue', 'toSend Queue', 'manage_options', self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.' ), 403 );
		}
		check_admin_referer( 'chtq_save' );
		$s                  = CHTQ::settings();
		$s['enabled']       = ! empty( $_POST['enabled'] );
		$s['prebuild']      = ! empty( $_POST['prebuild'] );
		$s['prebuild_min']  = max( 1, absint( wp_unslash( $_POST['prebuild_min'] ?? 1000 ) ) );
		$s['build_workers'] = max( 1, min( 8, absint( wp_unslash( $_POST['build_workers'] ?? 4 ) ) ) );
		CHTQ::save( $s );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'saved' => 1 ), admin_url( 'tools.php' ) ) );
		exit;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$s        = CHTQ::settings();
		$problems = CHTQ_FK::compat_problems();
		$v2       = CHTQ_FK::is_v2_flow();
		$jt       = CHTQ_Prebuild::jobs_table();
		$bt       = CHTQ_Prebuild::build_table();
		$qt       = CHTQ::table();
		$jobs     = $wpdb->get_results( "SELECT j.id, j.broadcast_id, j.state, j.execution_time, j.total, j.message, IF(j.state LIKE 'v2\\_%', (SELECT COUNT(*) FROM $qt q WHERE q.job_id = j.id), (SELECT COUNT(*) FROM $bt b WHERE b.job_id = j.id AND b.status = 2)) built FROM $jt j ORDER BY j.id DESC LIMIT 15", ARRAY_A );
		$sends    = $wpdb->get_results( "SELECT broadcast_id, SUM(status = 2) sent, SUM(status IN (0,1)) waiting, SUM(status = 6) held, SUM(status IN (3,5)) problems,
			MIN(sent_at) first_sent, MAX(sent_at) last_sent FROM $qt GROUP BY broadcast_id ORDER BY broadcast_id DESC LIMIT 10", ARRAY_A );
		$names    = array();
		foreach ( array_unique( array_merge( wp_list_pluck( $jobs, 'broadcast_id' ), wp_list_pluck( $sends, 'broadcast_id' ) ) ) as $bid ) {
			$b             = CHTQ_FK::broadcast( (int) $bid );
			$names[ $bid ] = $b ? $b['title'] : '';
		}
		$tz    = function ( $gmt ) {
			return $gmt ? get_date_from_gmt( $gmt, 'M j, g:i:s a' ) : '';
		};
		$ts    = CHTQ::tosend_info();
		$stats = CHTQ_Stats::data();
		?>
		<div class="wrap chtq-wrap">
			<style>
				.chtq-wrap .chtq-cards { display: grid; grid-template-columns: repeat( auto-fit, minmax( 200px, 1fr ) ); gap: 12px; margin: 16px 0 8px; max-width: 1100px; }
				.chtq-wrap .chtq-card { background: #fff; border: 1px solid #dcdcde; border-radius: 6px; padding: 14px 16px; }
				.chtq-wrap .chtq-card .chtq-label { color: #50575e; font-size: 12px; margin: 0 0 6px; }
				.chtq-wrap .chtq-card .chtq-value { font-size: 26px; font-weight: 600; line-height: 1.1; color: #1d2327; margin: 0; font-variant-numeric: tabular-nums; }
				.chtq-wrap .chtq-card .chtq-sub { color: #50575e; font-size: 12px; margin: 6px 0 0; }
				.chtq-wrap .chtq-panel { background: #fff; border: 1px solid #dcdcde; border-radius: 6px; padding: 14px 16px 6px; margin: 12px 0; max-width: 1068px; }
				.chtq-wrap .chtq-panel h3 { margin: 0 0 2px; font-size: 14px; }
				.chtq-wrap .chtq-panel .chtq-note { color: #50575e; margin: 0 0 8px; }
				.chtq-wrap .chtq-legend { display: flex; flex-wrap: wrap; gap: 16px; margin: 4px 0 10px; color: #1d2327; }
				.chtq-wrap .chtq-legend span { display: inline-flex; align-items: center; gap: 6px; }
				.chtq-wrap .chtq-legend i { width: 12px; height: 12px; border-radius: 3px; display: inline-block; }
				.chtq-wrap svg.chtq-chart { width: 100%; height: auto; display: block; }
				.chtq-wrap svg.chtq-chart text { font: 12px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; fill: #1d2327; }
				.chtq-wrap svg.chtq-chart .chtq-muted { fill: #646970; }
				.chtq-wrap svg.chtq-chart rect.chtq-bar:hover { opacity: .85; }
				.chtq-wrap details { margin: 8px 0 14px; max-width: 1100px; }
			</style>

			<h1>toSend Queue</h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>Saved.</p></div>
			<?php endif; ?>
			<?php if ( $s['paused'] ) : ?>
				<div class="notice notice-error"><p><strong>Sending is paused:</strong> <?php echo esc_html( $s['paused'] ); ?></p></div>
			<?php endif; ?>
			<?php if ( $problems ) : ?>
				<div class="notice notice-warning"><p><strong>Pre-building is paused:</strong> FunnelKit changed (<?php echo esc_html( implode( '; ', $problems ) ); ?>). Broadcasts still send through the queue.</p></div>
			<?php endif; ?>

			<?php self::dashboard( $stats ); ?>

			<h2>Settings</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="chtq_save">
				<?php wp_nonce_field( 'chtq_save' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Queue broadcasts</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?>> Send FunnelKit broadcasts through the queue, in batches</label></td></tr>
					<tr><th scope="row">Pre-build</th><td>
						<?php if ( $v2 ) : ?>
							<p>On FunnelKit 3.9 this runs FunnelKit's own sending early, in several workers, and holds every email until the scheduled minute. It starts once FunnelKit has prepared the broadcast (up to an hour before). Smart Sending broadcasts are left to FunnelKit and still go out through the queue.</p>
						<?php endif; ?>
						<label><input type="checkbox" name="prebuild" value="1" <?php checked( $s['prebuild'] ); ?>> Build scheduled broadcasts ahead of time and send them all at the scheduled minute</label>
						<p class="description">Broadcasts that create coupons or send to non-openers are always left to FunnelKit. Editing a pre-built broadcast rebuilds it automatically.</p></td></tr>
					<tr><th scope="row"><label for="chtq-min">Only broadcasts with at least</label></th><td><input id="chtq-min" type="number" min="1" name="prebuild_min" value="<?php echo esc_attr( $s['prebuild_min'] ); ?>" class="small-text"> recipients</td></tr>
					<tr><th scope="row"><label for="chtq-w">Build workers</label></th><td><input id="chtq-w" type="number" min="1" max="8" name="build_workers" value="<?php echo esc_attr( $s['build_workers'] ); ?>" class="small-text">
						<p class="description">Must match the number of <code>wp chtq work</code> lines in the server's cron file.</p></td></tr>
				</table>
				<?php submit_button( 'Save' ); ?>
			</form>

			<h2>toSend account</h2>
			<?php if ( $ts ) : ?>
				<table class="widefat striped" style="max-width:720px">
					<tbody>
						<tr><td>Prepaid credits</td><td><?php echo esc_html( number_format_i18n( (float) ( $ts['account']['credit_balance'] ?? 0 ) ) ); ?></td></tr>
						<tr><td>Sending health</td><td><?php echo esc_html( (string) ( $ts['account']['ses_status'] ?? '' ) ); ?></td></tr>
						<tr><td>Speed limit</td><td><?php echo esc_html( sprintf( '%s a second per team; the queue sends %s', $ts['account']['limit_per_second'] ?? '?', ( $er = CHTQ::effective_rate( $s['rate'] ) ) ? $er . ' a second' . ( 'auto' === $s['rate'] ? ' (auto, 95% of the limit)' : ' (set by hand)' ) : 'with no limit' ) ); ?></td></tr>
						<?php foreach ( (array) ( $ts['domains'] ?? array() ) as $d ) : ?>
							<?php if ( empty( $d['total_sent'] ) ) { continue; } ?>
							<tr><td><?php echo esc_html( (string) $d['domain_name'] ); ?></td><td><?php echo esc_html( sprintf( '%s sent, %.2f%% bounced, %.3f%% spam complaints', number_format_i18n( (int) $d['total_sent'] ), 100 * (int) $d['total_bounced'] / max( 1, (int) $d['total_sent'] ), 100 * (int) $d['total_complained'] / max( 1, (int) $d['total_sent'] ) ) ); ?></td></tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">From toSend, refreshed every 10 minutes. toSend accepts batches within minutes and then hands each email to the recipient's mail server; its Logs page shows any still pending.</p>
			<?php else : ?>
				<p>toSend account details are unavailable right now.</p>
			<?php endif; ?>

			<h2>Recent sends through the queue</h2>
			<table class="widefat striped" style="max-width:1100px">
				<thead><tr><th>Broadcast</th><th>Accepted by toSend</th><th>Waiting</th><th>Held</th><th>Problems</th><th>First accepted</th><th>Last accepted</th><th>Time to send</th></tr></thead>
				<tbody>
				<?php if ( ! $sends ) : ?>
					<tr><td colspan="8">None yet.</td></tr>
				<?php endif; ?>
				<?php foreach ( $sends as $r ) : ?>
					<tr>
						<td>#<?php echo esc_html( $r['broadcast_id'] . ' ' . ( $names[ $r['broadcast_id'] ] ?? '' ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $r['sent'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $r['waiting'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $r['held'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $r['problems'] ) ); ?></td>
						<td><?php echo esc_html( $tz( $r['first_sent'] ) ); ?></td>
						<td><?php echo esc_html( $tz( $r['last_sent'] ) ); ?></td>
						<td><?php echo esc_html( ( $r['first_sent'] && $r['last_sent'] ) ? CHTQ_Stats::duration( strtotime( $r['last_sent'] . ' UTC' ) - strtotime( $r['first_sent'] . ' UTC' ) ) : '' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">Times are this site's timezone. "Problems" are addresses toSend refused or requests that may not have arrived; <code>wp chtq status</code> lists the reasons.</p>

			<details open>
				<summary><strong>Pre-build jobs</strong></summary>
				<table class="widefat striped" style="margin-top:8px">
					<thead><tr><th>Job</th><th>Broadcast</th><th>State</th><th>Send time</th><th>Built</th><th>Note</th></tr></thead>
					<tbody>
					<?php if ( ! $jobs ) : ?>
						<tr><td colspan="6">None yet.</td></tr>
					<?php endif; ?>
					<?php foreach ( $jobs as $j ) : ?>
						<tr>
							<td><?php echo esc_html( $j['id'] ); ?></td>
							<td>#<?php echo esc_html( $j['broadcast_id'] . ' ' . ( $names[ $j['broadcast_id'] ] ?? '' ) ); ?></td>
							<td><?php echo esc_html( $j['state'] ); ?></td>
							<td><?php echo esc_html( $tz( $j['execution_time'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $j['built'] ) . ' / ' . number_format_i18n( (int) $j['total'] ) ); ?></td>
							<td><?php echo esc_html( (string) $j['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		</div>
		<?php
	}

	/** Stat cards, two bar charts and the table behind them. */
	private static function dashboard( $stats ) {
		$c    = $stats['cards'];
		$rows = $stats['rows'];
		$q    = $stats['queue'];
		$x    = ( $c['after_secs'] > 0 && $c['before_secs'] > 0 ) ? $c['before_secs'] / $c['after_secs'] : 0;
		$fx   = ( $c['fastest'] > 0 && $c['before_secs'] > 0 ) ? $c['before_secs'] / $c['fastest'] : 0;
		$ok   = $q['sent'] + $q['problems'];
		?>
		<h2>Performance</h2>
		<div class="chtq-cards">
			<div class="chtq-card">
				<p class="chtq-label">Large send, before the queue</p>
				<p class="chtq-value"><?php echo esc_html( $c['before_n'] ? CHTQ_Stats::duration( $c['before_secs'] ) : 'n/a' ); ?></p>
				<p class="chtq-sub">Average of <?php echo esc_html( $c['before_n'] ); ?> broadcasts sent by FunnelKit alone, <?php echo esc_html( number_format_i18n( $c['before_rate'], 1 ) ); ?> emails a second</p>
			</div>
			<div class="chtq-card">
				<p class="chtq-label">Large send, with the queue</p>
				<p class="chtq-value"><?php echo esc_html( $c['after_n'] ? CHTQ_Stats::duration( $c['after_secs'] ) : 'n/a' ); ?></p>
				<p class="chtq-sub">Average of <?php echo esc_html( $c['after_n'] ); ?> broadcasts, <?php echo esc_html( number_format_i18n( $c['after_rate'], 1 ) ); ?> emails a second</p>
			</div>
			<div class="chtq-card">
				<p class="chtq-label">Fastest large send</p>
				<p class="chtq-value"><?php echo esc_html( $c['fastest'] ? CHTQ_Stats::duration( $c['fastest'] ) : 'n/a' ); ?></p>
				<p class="chtq-sub"><?php echo $fx ? esc_html( sprintf( '%s times faster than the average before', number_format_i18n( $fx, 0 ) ) ) : '&nbsp;'; ?></p>
			</div>
			<div class="chtq-card">
				<p class="chtq-label">Sent through the queue</p>
				<p class="chtq-value"><?php echo esc_html( number_format_i18n( $q['sent'] ) ); ?></p>
				<p class="chtq-sub"><?php echo esc_html( sprintf( '%s broadcasts since %s, %s%% accepted by toSend', number_format_i18n( $q['broadcasts'] ), $q['since'] ? get_date_from_gmt( $q['since'], 'M j' ) : '-', $ok ? number_format_i18n( 100 * $q['sent'] / $ok, 2 ) : '100' ) ); ?></p>
			</div>
		</div>
		<?php if ( $x ) : ?>
			<p class="description" style="max-width:1100px">On average, large broadcasts now send <?php echo esc_html( number_format_i18n( $x, 1 ) ); ?> times faster than before the queue. Send time runs from the first to the last email sent, as FunnelKit records it.</p>
		<?php endif; ?>

		<?php if ( ! empty( $stats['pending'] ) ) : ?>
			<p class="description"><?php echo esc_html( sprintf( '%d more recent large broadcasts are still being measured; they appear here within a few minutes.', (int) $stats['pending'] ) ); ?></p>
		<?php endif; ?>
		<?php if ( ! $rows ) : ?>
			<p>No measured broadcasts of <?php echo esc_html( number_format_i18n( CHTQ_Stats::LARGE ) ); ?> or more recipients yet.</p>
			<?php return; ?>
		<?php endif; ?>

		<div class="chtq-panel">
			<h3>Time to send each large broadcast</h3>
			<p class="chtq-note">Broadcasts of <?php echo esc_html( number_format_i18n( CHTQ_Stats::LARGE ) ); ?> or more recipients, oldest first. Shorter is better.</p>
			<?php self::legend( $rows ); ?>
			<?php self::bars( $rows, 'seconds', function ( $v ) {
				return CHTQ_Stats::duration( $v );
			} ); ?>
		</div>
		<div class="chtq-panel">
			<h3>Emails sent per second</h3>
			<p class="chtq-note">Average over each whole send. Longer is better.</p>
			<?php self::legend( $rows ); ?>
			<?php self::bars( $rows, 'rate', function ( $v ) {
				return number_format_i18n( $v, $v < 10 ? 1 : 0 ) . ' a second';
			} ); ?>
		</div>
		<details>
			<summary>Show the numbers as a table</summary>
			<table class="widefat striped" style="margin-top:8px">
				<thead><tr><th>Broadcast</th><th>Sent on</th><th>How it was sent</th><th>Emails</th><th>Time to send</th><th>Emails a second</th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td>#<?php echo esc_html( $r['id'] . ' ' . $r['title'] ); ?></td>
						<td><?php echo esc_html( get_date_from_gmt( $r['date'], 'M j, Y g:i a' ) ); ?></td>
						<td><?php echo esc_html( CHTQ_Stats::METHODS[ $r['method'] ] ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $r['sent'] ) ); ?></td>
						<td><?php echo esc_html( CHTQ_Stats::duration( $r['seconds'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $r['rate'], 1 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">Each broadcast is measured once, in the background, after it finishes sending.</p>
		</details>
		<?php
	}

	private static function legend( $rows ) {
		$used = array_unique( array_column( $rows, 'method' ) );
		echo '<div class="chtq-legend">';
		foreach ( CHTQ_Stats::METHODS as $key => $label ) {
			if ( in_array( $key, $used, true ) ) {
				printf( '<span><i style="background:%s"></i>%s</span>', esc_attr( self::COLORS[ $key ] ), esc_html( $label ) );
			}
		}
		echo '</div>';
	}

	/** Axis label for a round number of seconds: "0", "30 m", "1 h", "1 h 30 m". */
	private static function axis_time( $secs ) {
		if ( ! $secs ) {
			return '0';
		}
		$h = intdiv( $secs, 3600 );
		$m = intdiv( $secs % 3600, 60 );
		return trim( ( $h ? $h . ' h ' : '' ) . ( $m ? $m . ' m' : '' ) );
	}

	/** Horizontal bar chart, one bar per broadcast, coloured by how it was sent, value at the bar end. */
	private static function bars( $rows, $key, $format ) {
		$w      = 1040;
		$label  = 300;
		$right  = 130;
		$row_h  = 30;
		$top    = 6;
		$h      = $top + count( $rows ) * $row_h + 22;
		$plot   = $w - $label - $right;
		// Round axis: the smallest "nice" step that fits the biggest bar in at most 5 steps.
		$top_v  = max( array_column( $rows, $key ) ) ?: 1;
		$nice   = 'seconds' === $key
			? array( 60, 300, 600, 900, 1800, 3600, 7200, 10800 )
			: array( 1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500 );
		$stepv  = end( $nice );
		foreach ( $nice as $n ) {
			if ( $top_v / $n <= 5 ) {
				$stepv = $n;
				break;
			}
		}
		$steps  = (int) ceil( $top_v / $stepv );
		$max    = $steps * $stepv;
		ob_start();
		printf( '<svg class="chtq-chart" viewBox="0 0 %d %d" role="img" aria-label="%s">', $w, $h, esc_attr( 'seconds' === $key ? 'Time to send each large broadcast' : 'Emails sent per second for each large broadcast' ) );
		for ( $i = 0; $i <= $steps; $i++ ) { // recessive grid with axis values
			$gx = $label + $plot * $i / $steps;
			printf( '<line x1="%.1f" y1="%d" x2="%.1f" y2="%d" stroke="#e0e0e0" stroke-width="1"/>', $gx, $top, $gx, $h - 20 );
			printf( '<text class="chtq-muted" x="%.1f" y="%d" text-anchor="middle">%s</text>', $gx, $h - 6, esc_html( 'seconds' === $key ? self::axis_time( $stepv * $i ) : number_format_i18n( $stepv * $i ) ) );
		}
		foreach ( array_values( $rows ) as $i => $r ) {
			$y     = $top + $i * $row_h;
			$v     = (float) $r[ $key ];
			$bw    = max( 2, $plot * $v / $max );
			$title = mb_strimwidth( $r['title'], 0, 34, '...' );
			$date  = get_date_from_gmt( $r['date'], 'M j' );
			printf( '<text x="%d" y="%d" text-anchor="end">%s</text>', $label - 10, $y + 14, esc_html( $title ) );
			printf( '<text class="chtq-muted" x="%d" y="%d" text-anchor="end">%s</text>', $label - 10, $y + 26, esc_html( $date ) );
			printf(
				'<rect class="chtq-bar" x="%d" y="%d" width="%.1f" height="18" rx="4" fill="%s"><title>%s</title></rect>',
				$label, $y + 4, $bw, esc_attr( self::COLORS[ $r['method'] ] ),
				esc_html( sprintf( '#%d %s, %s: %s emails in %s (%s a second), %s', $r['id'], $r['title'], $date, number_format_i18n( $r['sent'] ), CHTQ_Stats::duration( $r['seconds'] ), number_format_i18n( $r['rate'], 1 ), CHTQ_Stats::METHODS[ $r['method'] ] ) )
			);
			printf( '<text x="%.1f" y="%d">%s</text>', $label + $bw + 8, $y + 17, esc_html( $format( $v ) ) );
		}
		echo '</svg>';
		echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value above is escaped
	}
}
