<?php
/**
 * Plugin Name:       CronEngine: Real Cron Scheduler
 * Plugin URI:        https://cronengine.com/wordpress-cron
 * Description:       Runs WP-Cron on a real schedule. CronEngine calls your site on time, so scheduled posts, emails and backups no longer wait for a visitor.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            CronEngine
 * Author URI:        https://cronengine.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cronengine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CRONENGINE_VERSION', '1.0.0' );
define( 'CRONENGINE_FILE', __FILE__ );
define( 'CRONENGINE_DIR', plugin_dir_path( __FILE__ ) );

// The CronEngine address. Only ever changed for testing against another copy.
if ( ! defined( 'CRONENGINE_APP_URL' ) ) {
	define( 'CRONENGINE_APP_URL', 'https://cronengine.com' );
}

require_once CRONENGINE_DIR . 'includes/class-cronengine-state.php';
require_once CRONENGINE_DIR . 'includes/class-cronengine-api.php';
require_once CRONENGINE_DIR . 'includes/class-cronengine-cron.php';

// Runs on every request, as early as a plugin can, because the visitor
// trigger it removes is hooked to `init`.
CronEngine_Cron::boot();

if ( is_admin() ) {
	require_once CRONENGINE_DIR . 'includes/class-cronengine-admin.php';
	CronEngine_Admin::boot();
}

require_once CRONENGINE_DIR . 'includes/class-cronengine-site-health.php';
CronEngine_Site_Health::boot();
