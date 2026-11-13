<?php
/**
 * Deleting the plugin removes its tables and settings. Deactivating keeps them.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The light open pixel's must-use copy (includes/class-chtq-pixel.php).
foreach ( array( 'funnelkit-automations-tosend-queue-open-pixel.php', 'ch-tosend-queue-open-pixel.php' ) as $f ) {
	if ( is_file( WPMU_PLUGIN_DIR . '/' . $f ) ) {
		unlink( WPMU_PLUGIN_DIR . '/' . $f );
	}
}

global $wpdb;
foreach ( array( 'chtq_queue', 'chtq_jobs', 'chtq_build' ) as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}$t" );
}
foreach ( array( 'chtq_settings', 'chtq_db_version', 'chtq_upgrading' ) as $o ) {
	delete_option( $o );
}
