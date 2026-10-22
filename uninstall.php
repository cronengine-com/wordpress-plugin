<?php
/**
 * Removing the plugin: end the connection and leave nothing behind.
 *
 * Deactivating keeps the connection, so turning the plugin off and on again
 * loses nothing. Deleting it tells CronEngine to delete the site's job, so it
 * does not keep calling a site that no longer expects it, and removes every
 * option, transient and user setting the plugin wrote.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$cronengine_key = get_option( 'cronengine_key', '' );

if ( is_string( $cronengine_key ) && 0 === strpos( $cronengine_key, 'cew_' ) ) {
	$cronengine_url = defined( 'CRONENGINE_APP_URL' ) ? CRONENGINE_APP_URL : 'https://cronengine.com';

	// Best effort and short: an uninstall must not hang on a slow network.
	wp_remote_post(
		$cronengine_url . '/api/v1/wordpress/disconnect',
		array(
			'timeout' => 5,
			'headers' => array(
				'Authorization' => 'Bearer ' . $cronengine_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'    => '{}',
		)
	);
}

delete_option( 'cronengine_key' );
delete_option( 'cronengine_state' );
delete_transient( 'cronengine_status' );
delete_metadata( 'user', 0, 'cronengine_notice_dismissed', '', true );

// Per-admin transients from an unfinished connect or a pending message.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup of this plugin's own transients.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_cronengine_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_cronengine_' ) . '%',
		$wpdb->esc_like( '_site_transient_cronengine_' ) . '%',
		$wpdb->esc_like( '_site_transient_timeout_cronengine_' ) . '%'
	)
);
