<?php
/**
 * A Site Health check (Tools, Site Health) for how WP-Cron is run.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CronEngine_Site_Health {

	public static function boot() {
		add_filter( 'site_status_tests', array( __CLASS__, 'register' ) );
	}

	public static function register( $tests ) {
		$tests['direct']['cronengine'] = array(
			'label' => __( 'How WP-Cron is run', 'cronengine' ),
			'test'  => array( __CLASS__, 'test' ),
		);

		return $tests;
	}

	public static function test() {
		$result = array(
			'test'    => 'cronengine',
			'badge'   => array(
				'label' => __( 'Performance', 'cronengine' ),
				'color' => 'blue',
			),
			'actions' => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( add_query_arg( 'page', 'cronengine', admin_url( 'tools.php' ) ) ),
				esc_html__( 'Open CronEngine', 'cronengine' )
			),
		);

		if ( CronEngine_Cron::is_ours() ) {
			return $result + array(
				'status'      => 'good',
				'label'       => __( 'WP-Cron is run on a schedule by CronEngine', 'cronengine' ),
				'description' => '<p>' . esc_html__( 'Scheduled tasks run on time, whether or not anyone visits the site.', 'cronengine' ) . '</p>',
			);
		}

		if ( CronEngine_State::is_connected() ) {
			$result['badge']['color'] = 'orange';

			return $result + array(
				'status'      => 'recommended',
				'label'       => __( 'CronEngine has stopped calling this site', 'cronengine' ),
				'description' => '<p>' . esc_html__( 'WordPress is running cron on visits again for now, so nothing is skipped. Check the job in CronEngine, or that this site answers requests to wp-cron.php.', 'cronengine' ) . '</p>',
			);
		}

		return $result + array(
			'status'      => 'recommended',
			'label'       => __( 'WP-Cron only runs when someone visits', 'cronengine' ),
			'description' => '<p>' . esc_html__( 'Scheduled posts, emails and backups can run late on a quiet site. Connect CronEngine to run them on a real schedule.', 'cronengine' ) . '</p>',
		);
	}
}
