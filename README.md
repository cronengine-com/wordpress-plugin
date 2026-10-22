# CronEngine: Real Cron Scheduler

Runs WP-Cron on a real schedule. [CronEngine](https://cronengine.com/wordpress-cron?utm_source=github&utm_medium=readme) calls your site's `wp-cron.php` on time, and the plugin stops page views from starting cron.

The plugin's page for users is [readme.txt](readme.txt), which is what wordpress.org shows.

## How it works

1. **Connect.** Tools, CronEngine, Connect. The admin is sent to CronEngine with the site URL, a random `state` and a PKCE challenge (S256), signs in or signs up, and picks an interval.
2. **Come back.** CronEngine creates a cron job for `https://site/wp-cron.php?doing_wp_cron` and redirects to the plugin page with a one-time code. The plugin trades the code and its PKCE verifier for a site key (`cew_...`) and stores it in `wp_options`.
3. **Run.** While connected, the plugin removes `add_action( 'init', 'wp_cron' )`, so page views no longer start cron. Nothing is written to `wp-config.php`.
4. **Safety net.** Every call from CronEngine (recognised by its `X-CronEngine-Execution-ID` header) is noted. If none arrives for two intervals plus 30 minutes, and at least an hour, the hook is left alone and visitors run cron again until CronEngine is back.

The site key can only read and change this site's job in CronEngine. Disconnecting, from either side, deletes the job and ends the key.

## Files

| File | What it does |
|---|---|
| `cronengine.php` | Plugin header and bootstrap |
| `includes/class-cronengine-state.php` | The stored key and connection state |
| `includes/class-cronengine-api.php` | Calls to `https://cronengine.com/api/v1/wordpress/` |
| `includes/class-cronengine-cron.php` | Turns off the visitor trigger, notes CronEngine's calls, the safety net |
| `includes/class-cronengine-admin.php` | Tools, CronEngine: connect, status, interval, disconnect |
| `includes/class-cronengine-site-health.php` | Site Health check |
| `uninstall.php` | Deletes the job in CronEngine and every option the plugin wrote |
| `.wordpress-org/` | Icon, banners and screenshots for the wordpress.org page (not in the zip) |

## Build

```
php -d extension=zip bin/build.php
```

Writes `dist/cronengine.zip`, ready for Plugins, Add New, Upload.

## Testing against another CronEngine

Define the address in `wp-config.php`:

```php
define( 'CRONENGINE_APP_URL', 'https://staging.example.com' );
```

CronEngine refuses to call private addresses, so the site itself has to be reachable from the internet.

## License

GPLv2 or later. See [LICENSE](LICENSE).
