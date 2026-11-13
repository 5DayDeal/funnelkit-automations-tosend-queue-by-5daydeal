<?php
/**
 * The light open pixel (includes/mu-open-pixel.php): FunnelKit's email open pixel loads only
 * FunnelKit's plugins. It has to cut the plugin list before plugins load, which only a must-use
 * plugin can do, so this plugin copies the file into mu-plugins and keeps it current.
 *
 * Synced from admin pages, cron and WP-CLI (never on a visitor's request): copied when missing or
 * different, removed when the setting is off. Deactivating or deleting this plugin removes it.
 * `wp chtq pixel on|off|status`.
 */

defined( 'ABSPATH' ) || exit;

final class CHTQ_Pixel {
	const FILE = 'funnelkit-automations-tosend-queue-open-pixel.php';

	public static function init() {
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			add_action( 'init', array( __CLASS__, 'sync' ) );
		}
	}

	public static function source() {
		return __DIR__ . '/mu-open-pixel.php';
	}

	public static function target() {
		return WPMU_PLUGIN_DIR . '/' . self::FILE;
	}

	public static function enabled() {
		$s = CHTQ::settings();
		return ! empty( $s['light_pixel'] );
	}

	/** @return string what it did: installed, updated, removed, or '' */
	public static function sync() {
		$target = self::target();
		if ( ! self::enabled() ) {
			return self::remove() ? 'removed' : '';
		}
		$want = file_get_contents( self::source() );
		if ( false === $want ) {
			return '';
		}
		$had = is_file( $target );
		if ( $had && md5_file( $target ) === md5( $want ) ) {
			return '';
		}
		if ( ! is_dir( WPMU_PLUGIN_DIR ) && ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
			return '';
		}
		// Written beside it and renamed, so no request ever loads half a file.
		$tmp = $target . '.tmp-' . getmypid();
		if ( false === file_put_contents( $tmp, $want ) || ! rename( $tmp, $target ) ) {
			@unlink( $tmp ); //phpcs:ignore
			return '';
		}
		return $had ? 'updated' : 'installed';
	}

	public static function remove() {
		$target = self::target();
		return is_file( $target ) && unlink( $target );
	}
}
