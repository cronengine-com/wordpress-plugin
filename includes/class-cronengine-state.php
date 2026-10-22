<?php
/**
 * What the plugin remembers about its connection.
 *
 * Two options. The site key on its own and never autoloaded, because nothing
 * but an API call needs it. Everything else in one small autoloaded array,
 * because the safety net reads it on every request.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CronEngine_State {

	const KEY_OPTION   = 'cronengine_key';
	const STATE_OPTION = 'cronengine_state';
	const STATUS_CACHE = 'cronengine_status';

	/** Last calls are written at most this often, not on every cron run. */
	const CALL_RESOLUTION = 30;

	/**
	 * `called` is false until CronEngine's first call arrives. Until then
	 * `last_call` holds the moment of connecting, so the safety net counts
	 * from there.
	 *
	 * @return array{connected_at:int, last_call:int, called:bool, interval:int, site_url:string}
	 */
	public static function get() {
		$state = get_option( self::STATE_OPTION, array() );

		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'connected_at' => 0,
				'last_call'    => 0,
				'called'       => false,
				'interval'     => 15,
				'site_url'     => '',
			)
		);
	}

	public static function key() {
		$key = get_option( self::KEY_OPTION, '' );

		return is_string( $key ) && 0 === strpos( $key, 'cew_' ) ? $key : '';
	}

	public static function is_connected() {
		return '' !== self::key();
	}

	/**
	 * Store a fresh connection.
	 *
	 * @param string     $key      The site key from CronEngine.
	 * @param string     $site_url The address that was connected.
	 * @param array|null $status   The status that came with the key, if any.
	 */
	public static function connect( $key, $site_url, $status = null ) {
		update_option( self::KEY_OPTION, $key, false );
		update_option(
			self::STATE_OPTION,
			array(
				'connected_at' => time(),
				// Counted from now, so the safety net gives the first call
				// the same grace as any other.
				'last_call'    => time(),
				'called'       => false,
				'interval'     => self::interval_from( $status, 15 ),
				'site_url'     => $site_url,
			),
			true
		);
		self::cache_status( $status );
	}

	/** Forget the connection. WP-Cron's visitor trigger comes back on the next request. */
	public static function forget() {
		delete_option( self::KEY_OPTION );
		delete_option( self::STATE_OPTION );
		delete_transient( self::STATUS_CACHE );
	}

	/** Note that CronEngine just called wp-cron.php. */
	public static function record_call() {
		$state = self::get();
		if ( $state['called'] && time() - (int) $state['last_call'] < self::CALL_RESOLUTION ) {
			return;
		}

		$state['last_call'] = time();
		$state['called']    = true;
		update_option( self::STATE_OPTION, $state, true );
	}

	/** Keep the interval in step with what CronEngine reports. */
	public static function remember_status( $status ) {
		self::cache_status( $status );

		$interval = self::interval_from( $status, 0 );
		$state    = self::get();
		if ( $interval > 0 && $interval !== (int) $state['interval'] ) {
			$state['interval'] = $interval;
			update_option( self::STATE_OPTION, $state, true );
		}
	}

	public static function cached_status() {
		$status = get_transient( self::STATUS_CACHE );

		return is_array( $status ) ? $status : null;
	}

	/**
	 * How long without a call before the safety net turns WP-Cron back on.
	 *
	 * Two missed calls plus half an hour, and never under an hour, so a slow
	 * interval or one late run never trips it.
	 */
	public static function grace_seconds() {
		$interval = max( 1, (int) self::get()['interval'] );

		return max( HOUR_IN_SECONDS, ( 2 * $interval + 30 ) * MINUTE_IN_SECONDS );
	}

	/** Whether CronEngine has been quiet for too long. */
	public static function is_overdue() {
		if ( ! self::is_connected() ) {
			return false;
		}

		return time() - (int) self::get()['last_call'] > self::grace_seconds();
	}

	private static function cache_status( $status ) {
		if ( is_array( $status ) ) {
			set_transient( self::STATUS_CACHE, $status, MINUTE_IN_SECONDS );
		}
	}

	private static function interval_from( $status, $fallback ) {
		$minutes = isset( $status['job']['interval_minutes'] ) ? (int) $status['job']['interval_minutes'] : 0;

		return $minutes > 0 ? $minutes : $fallback;
	}
}
