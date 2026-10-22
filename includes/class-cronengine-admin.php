<?php
/**
 * Tools, CronEngine: connect, see how cron is running, change the interval,
 * disconnect. Plus one dismissible reminder on the Dashboard and Plugins
 * screens until the site is connected.
 *
 * Connecting works like "sign in with" on other sites. The admin is sent to
 * CronEngine with a random `state` and a PKCE challenge, signs in or signs up
 * there, and comes back here with a one-time code. The code and the PKCE
 * verifier (which never leaves this server) are traded for the site key.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CronEngine_Admin {

	const PAGE        = 'cronengine';
	const CAPABILITY  = 'manage_options';
	const PENDING     = 'cronengine_connect_';
	const MESSAGE     = 'cronengine_message_';
	const DISMISSED   = 'cronengine_notice_dismissed';

	/** @var string */
	private static $hook = '';

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'reminder' ) );

		add_action( 'admin_post_cronengine_connect', array( __CLASS__, 'start_connect' ) );
		add_action( 'admin_post_cronengine_interval', array( __CLASS__, 'change_interval' ) );
		add_action( 'admin_post_cronengine_disconnect', array( __CLASS__, 'disconnect' ) );
		add_action( 'admin_post_cronengine_forget', array( __CLASS__, 'forget' ) );
		add_action( 'admin_post_cronengine_dismiss', array( __CLASS__, 'dismiss' ) );

		add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'allow_cronengine_redirect' ) );
		// WordPress strips these from the address bar after the page loads, so
		// a notice shows once and not again on refresh.
		add_filter( 'removable_query_args', array( __CLASS__, 'removable_args' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CRONENGINE_FILE ), array( __CLASS__, 'action_links' ) );
	}

	// ---------------------------------------------------------------------
	// Wiring
	// ---------------------------------------------------------------------

	public static function menu() {
		self::$hook = (string) add_management_page(
			__( 'CronEngine', 'cronengine' ),
			__( 'CronEngine', 'cronengine' ),
			self::CAPABILITY,
			self::PAGE,
			array( __CLASS__, 'render' )
		);

		add_action( 'load-' . self::$hook, array( __CLASS__, 'handle_return' ) );
	}

	public static function assets( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}

		wp_enqueue_style( 'cronengine-admin', plugins_url( 'assets/admin.css', CRONENGINE_FILE ), array(), CRONENGINE_VERSION );
		wp_enqueue_script( 'cronengine-admin', plugins_url( 'assets/admin.js', CRONENGINE_FILE ), array(), CRONENGINE_VERSION, true );
	}

	public static function allow_cronengine_redirect( $hosts ) {
		$hosts[] = (string) wp_parse_url( CRONENGINE_APP_URL, PHP_URL_HOST );

		return $hosts;
	}

	public static function removable_args( $args ) {
		$args[] = 'cronengine_notice';

		return $args;
	}

	public static function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::page_url() ), esc_html__( 'Settings', 'cronengine' ) )
		);

		return $links;
	}

	public static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'tools.php' ) );
	}

	/**
	 * The address CronEngine calls, with the same scheme as the admin.
	 *
	 * CronEngine only sends the code back to an admin page on the same scheme,
	 * host and port as the site. With FORCE_SSL_ADMIN on an http site, the
	 * admin is https while site_url() says http, so the site is sent as https:
	 * it has to answer there for its own admin to work at all.
	 */
	private static function site_url() {
		return set_url_scheme( untrailingslashit( site_url() ), (string) wp_parse_url( admin_url(), PHP_URL_SCHEME ) );
	}

	// ---------------------------------------------------------------------
	// Actions
	// ---------------------------------------------------------------------

	/** Connect: remember a state and a verifier, and send the admin to CronEngine. */
	public static function start_connect() {
		self::guard( 'cronengine_connect' );

		$site_url = self::site_url();
		$state    = wp_generate_password( 40, false );
		$verifier = wp_generate_password( 64, false );

		set_transient(
			self::PENDING . get_current_user_id(),
			array(
				'state'    => $state,
				'verifier' => $verifier,
				'site_url' => $site_url,
			),
			30 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect(
			CronEngine_Api::connect_url(
				array(
					'site_url'              => $site_url,
					'return_url'            => self::page_url(),
					'state'                 => $state,
					'code_challenge'        => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ),
					'code_challenge_method' => 'S256',
					'site_name'             => get_bloginfo( 'name' ),
					'plugin_version'        => CRONENGINE_VERSION,
				)
			)
		);
		exit;
	}

	/**
	 * Back from CronEngine, with a code or a cancellation.
	 *
	 * Runs before the page renders, and always ends in a redirect to the bare
	 * page, so the code never stays in the address bar, the history, or a
	 * Referer header.
	 */
	public static function handle_return() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- `state` is the check here: it was generated for this admin and only CronEngine has it.
		if ( ! isset( $_GET['cronengine_code'] ) && ! isset( $_GET['cronengine_error'] ) ) {
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to connect this site.', 'cronengine' ) );
		}

		$pending = get_transient( self::PENDING . get_current_user_id() );
		$state   = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code    = isset( $_GET['cronengine_code'] ) ? sanitize_text_field( wp_unslash( $_GET['cronengine_code'] ) ) : '';
		$error   = isset( $_GET['cronengine_error'] ) ? sanitize_key( wp_unslash( $_GET['cronengine_error'] ) ) : '';
		// phpcs:enable

		if ( ! is_array( $pending ) || '' === $state || ! hash_equals( (string) $pending['state'], $state ) ) {
			self::back( 'expired' );
		}

		delete_transient( self::PENDING . get_current_user_id() );

		if ( '' !== $error ) {
			self::back( 'cancelled' );
		}

		$result = CronEngine_Api::exchange( $code, (string) $pending['verifier'], (string) $pending['site_url'] );
		if ( ! $result['ok'] || empty( $result['data']['key'] ) ) {
			self::back( 'error', $result['message'] );
		}

		CronEngine_State::connect(
			sanitize_text_field( (string) $result['data']['key'] ),
			(string) $pending['site_url'],
			isset( $result['data']['site'] ) && is_array( $result['data']['site'] ) ? $result['data']['site'] : null
		);

		self::back( 'connected' );
	}

	public static function change_interval() {
		self::guard( 'cronengine_interval' );

		$minutes = isset( $_POST['minutes'] ) ? absint( wp_unslash( $_POST['minutes'] ) ) : 0;
		$result  = CronEngine_Api::set_interval( $minutes );

		if ( $result['ok'] ) {
			if ( isset( $result['data']['site'] ) && is_array( $result['data']['site'] ) ) {
				CronEngine_State::remember_status( $result['data']['site'] );
			} else {
				delete_transient( CronEngine_State::STATUS_CACHE );
			}
			self::back( 'saved' );
		}

		self::handle_failure( $result );
	}

	public static function disconnect() {
		self::guard( 'cronengine_disconnect' );

		$result = CronEngine_Api::disconnect();

		// Already gone on CronEngine's side is as good as disconnected.
		if ( $result['ok'] || 401 === $result['status'] ) {
			CronEngine_State::forget();
			self::back( 'disconnected' );
		}

		self::back( 'disconnect_failed', $result['message'] );
	}

	/** Forget the connection on this site only, for when CronEngine cannot be reached. */
	public static function forget() {
		self::guard( 'cronengine_forget' );

		CronEngine_State::forget();
		self::back( 'forgotten' );
	}

	public static function dismiss() {
		self::guard( 'cronengine_dismiss' );

		update_user_meta( get_current_user_id(), self::DISMISSED, 1 );

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	/** Capability and nonce, for every form on the page. */
	private static function guard( $action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage CronEngine.', 'cronengine' ) );
		}
		check_admin_referer( $action );
	}

	/** A failed call. A 401 means the key is gone, so the plugin lets go too. */
	private static function handle_failure( array $result ) {
		if ( 401 === $result['status'] ) {
			CronEngine_State::forget();
			self::back( 'revoked' );
		}

		self::back( 'error', $result['message'] );
	}

	/** Back to the page with a notice, and an optional message from CronEngine. */
	private static function back( $notice, $message = '' ) {
		if ( '' !== $message ) {
			set_transient( self::MESSAGE . get_current_user_id(), $message, MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( self::page_url( array( 'cronengine_notice' => $notice ) ) );
		exit;
	}

	// ---------------------------------------------------------------------
	// Reminder
	// ---------------------------------------------------------------------

	/** One reminder to connect, on the Dashboard and Plugins screens only. */
	public static function reminder() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) || CronEngine_State::is_connected() ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), self::DISMISSED, true ) ) {
			return;
		}

		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=cronengine_dismiss' ), 'cronengine_dismiss' );
		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'CronEngine is installed.', 'cronengine' ); ?></strong>
				<?php esc_html_e( 'Connect it to run WP-Cron on a real schedule instead of waiting for visitors.', 'cronengine' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( 'Set up CronEngine', 'cronengine' ); ?></a>
				<a class="button-link" style="margin-left: 10px;" href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'Dismiss', 'cronengine' ); ?></a>
			</p>
		</div>
		<?php
	}

	// ---------------------------------------------------------------------
	// Page
	// ---------------------------------------------------------------------

	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$status  = null;
		$error   = '';
		$revoked = false;

		if ( CronEngine_State::is_connected() ) {
			$status = CronEngine_State::cached_status();

			if ( null === $status ) {
				$result = CronEngine_Api::site();

				if ( $result['ok'] && isset( $result['data']['site'] ) && is_array( $result['data']['site'] ) ) {
					$status = $result['data']['site'];
					CronEngine_State::remember_status( $status );
				} elseif ( 401 === $result['status'] ) {
					// Disconnected from CronEngine's side, or the account is gone.
					CronEngine_State::forget();
					$revoked = true;
				} else {
					$error = $result['message'];
				}
			}
		}

		echo '<div class="wrap cronengine">';
		echo '<h1 class="cronengine-title">' . self::logo() . esc_html__( 'CronEngine', 'cronengine' ) . '</h1>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- logo() is a fixed SVG.

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only, a fixed list of notices.
		$notice = isset( $_GET['cronengine_notice'] ) ? sanitize_key( wp_unslash( $_GET['cronengine_notice'] ) ) : '';
		if ( $revoked ) {
			self::notice( 'revoked' );
		} elseif ( '' !== $notice ) {
			self::notice( $notice );
		}

		if ( CronEngine_State::is_connected() ) {
			self::render_connected( $status, $error );
		} else {
			self::render_intro();
		}

		echo '</div>';
	}

	private static function render_intro() {
		?>
		<div class="cronengine-card cronengine-intro">
			<h2><?php esc_html_e( 'Run WP-Cron on a real schedule.', 'cronengine' ); ?></h2>
			<p class="cronengine-lead">
				<?php esc_html_e( 'CronEngine calls your site every few minutes, so scheduled posts, emails and backups run on time, even when nobody visits.', 'cronengine' ); ?>
			</p>

			<div class="cronengine-features">
				<div>
					<h3><?php esc_html_e( 'On time', 'cronengine' ); ?></h3>
					<p><?php esc_html_e( 'Cron runs on a fixed schedule, not on page views.', 'cronengine' ); ?></p>
				</div>
				<div>
					<h3><?php esc_html_e( 'Alerts', 'cronengine' ); ?></h3>
					<p><?php esc_html_e( 'An email when your site stops answering.', 'cronengine' ); ?></p>
				</div>
				<div>
					<h3><?php esc_html_e( 'Safety net', 'cronengine' ); ?></h3>
					<p><?php esc_html_e( 'WordPress takes over again if calls ever stop.', 'cronengine' ); ?></p>
				</div>
			</div>

			<div class="cronengine-connect">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="cronengine_connect">
					<?php wp_nonce_field( 'cronengine_connect' ); ?>
					<button type="submit" class="cronengine-button"><?php esc_html_e( 'Connect to CronEngine', 'cronengine' ); ?></button>
				</form>
				<p class="cronengine-muted"><?php esc_html_e( 'Free plan, no card needed. Takes about a minute.', 'cronengine' ); ?></p>
			</div>
		</div>

		<p class="cronengine-footnote">
			<?php
			printf(
				/* translators: 1: privacy policy link, 2: terms link */
				esc_html__( 'Connecting sends this site\'s address and name to CronEngine. See the %1$s and %2$s.', 'cronengine' ),
				'<a href="' . esc_url( CRONENGINE_APP_URL . '/privacy-policy' ) . '" target="_blank" rel="noopener">' . esc_html__( 'privacy policy', 'cronengine' ) . '</a>',
				'<a href="' . esc_url( CRONENGINE_APP_URL . '/terms' ) . '" target="_blank" rel="noopener">' . esc_html__( 'terms', 'cronengine' ) . '</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * @param array|null $status What CronEngine reported, or null if it could not be asked.
	 * @param string     $error  Why it could not be asked.
	 */
	private static function render_connected( $status, $error ) {
		$job      = is_array( $status ) && isset( $status['job'] ) && is_array( $status['job'] ) ? $status['job'] : null;
		$state    = CronEngine_State::get();
		$overdue  = CronEngine_State::is_overdue();
		$label    = isset( $status['site_url'] ) ? preg_replace( '#^https?://#', '', (string) $status['site_url'] ) : preg_replace( '#^https?://#', '', (string) $state['site_url'] );

		if ( $overdue ) {
			$badge = array( 'warning', __( 'Safety net on', 'cronengine' ) );
		} elseif ( null === $status ) {
			$badge = array( 'neutral', __( 'Connected', 'cronengine' ) );
		} elseif ( null === $job ) {
			$badge = array( 'error', __( 'No job in CronEngine', 'cronengine' ) );
		} elseif ( 'active' !== $job['status'] ) {
			$badge = array( 'neutral', __( 'Paused in CronEngine', 'cronengine' ) );
		} else {
			$badge = array( 'success', __( 'Running', 'cronengine' ) );
		}
		?>
		<div class="cronengine-card">
			<div class="cronengine-head">
				<div>
					<p class="cronengine-eyebrow"><?php esc_html_e( 'Connected site', 'cronengine' ); ?></p>
					<h2><?php echo esc_html( $label ); ?></h2>
				</div>
				<span class="cronengine-badge cronengine-badge-<?php echo esc_attr( $badge[0] ); ?>"><?php echo esc_html( $badge[1] ); ?></span>
			</div>

			<?php if ( '' !== $error ) : ?>
				<p class="cronengine-alert"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>

			<?php if ( $overdue ) : ?>
				<p class="cronengine-alert cronengine-alert-warning">
					<?php
					printf(
						/* translators: %s: how long ago, for example "2 hours" */
						esc_html__( 'CronEngine has not called this site for %s, so WordPress is running cron on visits again for now. It switches back as soon as CronEngine calls.', 'cronengine' ),
						esc_html( human_time_diff( (int) $state['last_call'] ) )
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( null !== $job ) : ?>
				<dl class="cronengine-facts">
					<div>
						<dt><?php esc_html_e( 'Last run', 'cronengine' ); ?></dt>
						<dd><?php echo esc_html( self::when( $job['last_run_at'], __( 'Not yet', 'cronengine' ) ) ); ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Next run', 'cronengine' ); ?></dt>
						<dd><?php echo esc_html( self::when( $job['next_run_at'], '-' ) ); ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Plan', 'cronengine' ); ?></dt>
						<dd><?php echo esc_html( isset( $status['plan']['name'] ) ? (string) $status['plan']['name'] : '-' ); ?></dd>
					</div>
				</dl>

				<div class="cronengine-section">
					<h3><?php esc_html_e( 'How often', 'cronengine' ); ?></h3>
					<?php if ( null !== $job['interval_minutes'] && ! empty( $status['intervals'] ) ) : ?>
						<form class="cronengine-segments" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="cronengine_interval">
							<?php wp_nonce_field( 'cronengine_interval' ); ?>
							<?php foreach ( $status['intervals'] as $option ) : ?>
								<?php $current = (int) $option['minutes'] === (int) $job['interval_minutes']; ?>
								<button type="submit" name="minutes" value="<?php echo esc_attr( (string) (int) $option['minutes'] ); ?>"
									class="<?php echo $current ? 'is-current' : ''; ?>" aria-pressed="<?php echo $current ? 'true' : 'false'; ?>" <?php disabled( $current ); ?>>
									<?php echo esc_html( self::interval_label( (int) $option['minutes'] ) ); ?>
								</button>
							<?php endforeach; ?>
						</form>
						<?php if ( ! empty( $status['plan']['upgrade_url'] ) && 1 !== (int) $status['intervals'][0]['minutes'] ) : ?>
							<p class="cronengine-muted">
								<?php esc_html_e( 'Faster intervals are on bigger plans.', 'cronengine' ); ?>
								<a href="<?php echo esc_url( (string) $status['plan']['upgrade_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See plans', 'cronengine' ); ?></a>
							</p>
						<?php endif; ?>
					<?php else : ?>
						<p class="cronengine-muted"><?php esc_html_e( 'This job\'s schedule was changed in CronEngine, so it is managed there.', 'cronengine' ); ?></p>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $job['recent_runs'] ) ) : ?>
					<div class="cronengine-section">
						<h3><?php esc_html_e( 'Recent runs', 'cronengine' ); ?></h3>
						<ul class="cronengine-runs">
							<?php foreach ( $job['recent_runs'] as $run ) : ?>
								<li class="<?php echo ! empty( $run['ok'] ) ? 'is-ok' : 'is-failed'; ?>">
									<span class="cronengine-dot" aria-hidden="true"></span>
									<span class="cronengine-run-when"><?php echo esc_html( self::when( $run['at'], '-' ) ); ?></span>
									<span class="cronengine-run-meta">
										<?php
										echo esc_html(
											trim(
												( null !== $run['http_status'] ? 'HTTP ' . (int) $run['http_status'] : __( 'No answer', 'cronengine' ) )
												. ( null !== $run['duration_ms'] ? ' · ' . (int) $run['duration_ms'] . ' ms' : '' )
											)
										);
										?>
									</span>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			<?php elseif ( is_array( $status ) ) : ?>
				<p class="cronengine-alert"><?php esc_html_e( 'The cron job for this site was deleted in CronEngine. Disconnect and connect again to create a new one.', 'cronengine' ); ?></p>
			<?php endif; ?>

			<p class="cronengine-safety">
				<?php
				if ( CronEngine_Cron::is_ours() && empty( $state['called'] ) ) {
					$minutes = max( 1, (int) $state['interval'] );
					printf(
						/* translators: %d: number of minutes */
						esc_html( _n( 'Page views no longer start cron. CronEngine\'s first call comes within %d minute.', 'Page views no longer start cron. CronEngine\'s first call comes within %d minutes.', $minutes, 'cronengine' ) ),
						(int) $minutes
					);
				} elseif ( CronEngine_Cron::is_ours() ) {
					printf(
						/* translators: %s: how long ago, for example "3 mins" */
						esc_html__( 'Page views no longer start cron. CronEngine last called this site %s ago.', 'cronengine' ),
						esc_html( human_time_diff( (int) $state['last_call'] ) )
					);
				}
				if ( CronEngine_Cron::disabled_in_config() ) {
					echo ' ' . esc_html__( 'Note: wp-config.php sets DISABLE_WP_CRON, so the safety net cannot switch visitor cron back on while that line is there.', 'cronengine' );
				}
				?>
			</p>
		</div>

		<?php if ( null !== $job && ! empty( $job['manage_url'] ) ) : ?>
			<div class="cronengine-card cronengine-more">
				<div>
					<h3><?php esc_html_e( 'Want more control?', 'cronengine' ); ?></h3>
					<p><?php esc_html_e( 'Retries, Slack, Discord or webhook alerts, run history and your own schedule are all in CronEngine. Changes you make there show up here.', 'cronengine' ); ?></p>
				</div>
				<a class="button" href="<?php echo esc_url( (string) $job['manage_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open in CronEngine', 'cronengine' ); ?></a>
			</div>
		<?php endif; ?>

		<form class="cronengine-disconnect" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			data-cronengine-confirm="<?php esc_attr_e( 'Disconnect this site? Its cron job in CronEngine is deleted, and WordPress goes back to running cron on visits.', 'cronengine' ); ?>">
			<input type="hidden" name="action" value="cronengine_disconnect">
			<?php wp_nonce_field( 'cronengine_disconnect' ); ?>
			<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Disconnect this site', 'cronengine' ); ?></button>
		</form>
		<?php
	}

	private static function notice( $key ) {
		$message = get_transient( self::MESSAGE . get_current_user_id() );
		delete_transient( self::MESSAGE . get_current_user_id() );
		$detail = is_string( $message ) ? $message : '';

		$notices = array(
			'connected'         => array( 'success', __( 'Connected. CronEngine now runs WP-Cron for this site. Want retries or Slack alerts? Use Open in CronEngine below.', 'cronengine' ) ),
			'saved'             => array( 'success', __( 'Interval saved.', 'cronengine' ) ),
			'disconnected'      => array( 'success', __( 'Disconnected. The cron job was deleted in CronEngine and WordPress runs cron on visits again.', 'cronengine' ) ),
			'forgotten'         => array( 'warning', __( 'The connection was removed from this site. If the cron job still exists in CronEngine, delete it under Settings, Connected sites.', 'cronengine' ) ),
			'cancelled'         => array( 'info', __( 'Nothing was connected.', 'cronengine' ) ),
			'expired'           => array( 'error', __( 'That connection attempt expired or did not start here. Click Connect to CronEngine to try again.', 'cronengine' ) ),
			'revoked'           => array( 'warning', __( 'This site was disconnected in CronEngine, so WordPress runs cron on visits again. Connect again to switch back.', 'cronengine' ) ),
			'error'             => array( 'error', '' !== $detail ? $detail : __( 'Something went wrong. Please try again.', 'cronengine' ) ),
			'disconnect_failed' => array( 'error', '' !== $detail ? $detail : __( 'Could not disconnect. Please try again.', 'cronengine' ) ),
		);

		if ( ! isset( $notices[ $key ] ) ) {
			return;
		}

		list( $type, $text ) = $notices[ $key ];
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p>';

		if ( 'disconnect_failed' === $key ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><p>';
			echo '<input type="hidden" name="action" value="cronengine_forget">';
			wp_nonce_field( 'cronengine_forget' );
			echo '<button type="submit" class="button">' . esc_html__( 'Remove the connection from this site only', 'cronengine' ) . '</button>';
			echo '</p></form>';
		}

		echo '</div>';
	}

	private static function interval_label( $minutes ) {
		if ( 1 === $minutes ) {
			return __( 'Every minute', 'cronengine' );
		}
		if ( 60 === $minutes ) {
			return __( 'Every hour', 'cronengine' );
		}

		/* translators: %d: number of minutes */
		return sprintf( _n( 'Every %d minute', 'Every %d minutes', $minutes, 'cronengine' ), $minutes );
	}

	/** A UTC ISO time from CronEngine, in this site's timezone and format. */
	private static function when( $iso, $empty ) {
		$timestamp = is_string( $iso ) ? strtotime( $iso ) : false;
		if ( ! $timestamp ) {
			return $empty;
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	private static function logo() {
		return '<svg class="cronengine-logo" viewBox="0 0 64 64" fill="none" aria-hidden="true" focusable="false">'
			. '<defs>'
			. '<linearGradient id="cronengine-ring" x1="16" y1="8" x2="48" y2="56" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#FB923C"/><stop offset="0.58" stop-color="#F97316"/><stop offset="1" stop-color="#EA580C"/></linearGradient>'
			. '<linearGradient id="cronengine-face" x1="22" y1="18" x2="44" y2="48" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#1F2937"/><stop offset="1" stop-color="#0F172A"/></linearGradient>'
			. '</defs>'
			. '<path d="M 47.6 14.4 A 23.5 23.5 0 1 1 16.4 14.4" stroke="url(#cronengine-ring)" stroke-width="5.8" stroke-linecap="round"/>'
			. '<circle cx="32" cy="32" r="16.6" fill="url(#cronengine-face)" stroke="#FDBA74" stroke-width="2"/>'
			. '<line x1="32" y1="32" x2="25" y2="27.8" stroke="#FFF7ED" stroke-width="3.8" stroke-linecap="round"/>'
			. '<line x1="32" y1="32" x2="41.2" y2="26.4" stroke="#FFF7ED" stroke-width="3.3" stroke-linecap="round"/>'
			. '<circle cx="32" cy="32" r="3" fill="#FFF7ED"/>'
			. '</svg>';
	}
}
