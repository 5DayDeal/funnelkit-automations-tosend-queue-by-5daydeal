<?php
/**
 * Plugin Name: FunnelKit Automations toSend Queue: light open pixel
 * Description: Installed and kept up to date by the FunnelKit Automations toSend Queue plugin; turn it off with `wp chtq pixel off`, not by editing this file. FunnelKit's email open pixel loads only FunnelKit's plugins.
 *
 * FunnelKit records an email open on plugins_loaded and exits (BWFAN_Email_Conversations::
 * handle_track_open), so the pixel never needs the site's other ~50 plugins, which are most of
 * its ~0.37 s. For a request carrying FunnelKit's open-tracking query (?bwfan-track-action=open&
 * bwfan-track-id=...) only FunnelKit's own plugins load: Automations (Lite, Pro, Connectors),
 * which records the open, sets the contact's last-open field and may re-subscribe a bounced
 * contact, and Funnel Builder (Lite, Pro), which carries the WooFunnels core they share.
 *
 * When FunnelKit doesn't record the request (a bot it skips, an unknown or unsent id), it used
 * to go on and build the whole home page; with most plugins left out that page mustn't be built,
 * so this answers with the same 1x1 GIF and stops.
 */

defined( 'ABSPATH' ) || exit;

if ( isset( $_GET['bwfan-track-action'], $_GET['bwfan-track-id'] ) && 'open' === $_GET['bwfan-track-action'] //phpcs:ignore WordPress.Security.NonceVerification.Recommended
	&& '' !== $_GET['bwfan-track-id'] && 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) //phpcs:ignore
	&& ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {

	header( 'X-CHTQ-Pixel: light' );      // says this path ran (FunnelKit sends the GIF itself)
	add_filter( 'option_active_plugins', function ( $plugins ) {
		return array_values( array_filter( (array) $plugins, function ( $p ) {
			return 0 === strpos( $p, 'wp-marketing-automations' ) || 0 === strpos( $p, 'funnel-builder' );
		} ) );
	}, 1 );

	// After FunnelKit's handler (priority 99): it exits once it records an open, so getting here
	// means it didn't. Answer as a pixel either way.
	add_action( 'plugins_loaded', function () {
		nocache_headers();
		header( 'Content-Type: image/gif' );
		header( 'X-CHTQ-Pixel: not-recorded' );
		echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ); //phpcs:ignore
		exit;
	}, PHP_INT_MAX );
}
