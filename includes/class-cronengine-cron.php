<?php
/**
 * Who runs WP-Cron: visitors, or CronEngine.
 *
 * WordPress runs due cron events by starting a background request from page
 * views, through `add_action( 'init', 'wp_cron' )`. While the site is
 * connected, this removes that one hook, so cron runs only when CronEngine
 * calls wp-cron.php. Nothing is written to wp-config.php, and deactivating the
 * plugin puts everything back as it was.
 *
 * The safety net: if CronEngine has not called for longer than
 * CronEngine_State::grace_seconds(), the hook is left alone and visitors run
 * cron again until CronEngine is back. A site never ends up with no cron.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CronEngine_Cron {

	public static function boot() {
		if ( self::is_cronengine_request() ) {
			CronEngine_State::record_call();
		}

		if ( self::is_ours() ) {
			remove_action( 'init', 'wp_cron' );
		}
	}

	/** Whether CronEngine is running cron right now (connected, and calling). */
	public static function is_ours() {
		return CronEngine_State::is_connected() && ! CronEngine_State::is_overdue();
	}

	/**
	 * Whether wp-config.php turns WP-Cron off by itself.
	 *
	 * Harmless while CronEngine calls, but it means the safety net has nothing
	 * to fall back to, which is worth telling the admin.
	 */
	public static function disabled_in_config() {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	/**
	 * Whether this request is CronEngine calling wp-cron.php.
	 *
	 * Recognised by the headers the CronEngine dispatcher sends. They can be
	 * imitated, but all that would do is run cron, which is what the request
	 * does anyway.
	 */
	private static function is_cronengine_request() {
		if ( ! defined( 'DOING_CRON' ) || ! DOING_CRON ) {
			return false;
		}

		if ( ! empty( $_SERVER['HTTP_X_CRONENGINE_EXECUTION_ID'] ) ) {
			return true;
		}

		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		return 0 === strpos( $agent, 'CronEngine-Dispatcher/' );
	}
}
