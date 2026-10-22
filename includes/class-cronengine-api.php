<?php
/**
 * Talks to CronEngine's WordPress API.
 *
 * Nothing here runs until an admin clicks Connect. After that, only when the
 * CronEngine page is opened, a setting is changed, or the plugin is removed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CronEngine_Api {

	/**
	 * The result of a call.
	 *
	 * ok      whether CronEngine said yes
	 * status  the HTTP status, 0 when CronEngine could not be reached
	 * code    CronEngine's error code, or 'network' / 'bad_response'
	 * message a sentence that can be shown as it is
	 * data    the decoded answer
	 */
	private static function result( $ok, $status, $code, $message, $data = array() ) {
		return array(
			'ok'      => $ok,
			'status'  => $status,
			'code'    => $code,
			'message' => $message,
			'data'    => $data,
		);
	}

	public static function exchange( $code, $verifier, $site_url ) {
		return self::request(
			'POST',
			'exchange',
			array(
				'code'          => $code,
				'code_verifier' => $verifier,
				'site_url'      => $site_url,
			),
			''
		);
	}

	public static function site() {
		return self::request( 'GET', 'site', null, CronEngine_State::key() );
	}

	public static function set_interval( $minutes ) {
		return self::request( 'POST', 'interval', array( 'minutes' => (int) $minutes ), CronEngine_State::key() );
	}

	public static function disconnect() {
		return self::request( 'POST', 'disconnect', array(), CronEngine_State::key() );
	}

	/** Where an admin goes to connect. */
	public static function connect_url( array $params ) {
		return CRONENGINE_APP_URL . '/connect/wordpress?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	private static function request( $method, $endpoint, $body, $key ) {
		$headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
		);
		if ( '' !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}

		$response = wp_remote_request(
			CRONENGINE_APP_URL . '/api/v1/wordpress/' . $endpoint,
			array(
				'method'     => $method,
				'timeout'    => 15,
				'headers'    => $headers,
				'body'       => null === $body ? null : wp_json_encode( $body ),
				'user-agent' => 'CronEngine-WordPress/' . CRONENGINE_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::result(
				false,
				0,
				'network',
				sprintf(
					/* translators: %s: the error WordPress reported */
					__( 'Could not reach CronEngine: %s', 'cronengine' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return self::result(
				false,
				$status,
				'bad_response',
				__( 'CronEngine sent an answer the plugin could not read. Please try again in a minute.', 'cronengine' )
			);
		}

		if ( empty( $data['ok'] ) ) {
			return self::result(
				false,
				$status,
				isset( $data['code'] ) ? (string) $data['code'] : 'error',
				isset( $data['error'] ) ? (string) $data['error'] : __( 'CronEngine refused the request.', 'cronengine' ),
				$data
			);
		}

		return self::result( true, $status, '', '', $data );
	}
}
