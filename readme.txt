=== CronEngine: Real Cron Scheduler ===
Contributors: cronengine
Tags: cron, wp-cron, scheduled posts, scheduler, missed schedule
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Run WP-Cron on a real schedule. Scheduled posts, emails and backups run on time, even when nobody visits your site.

== Description ==

WordPress only checks for scheduled tasks when someone loads a page. On a quiet site that means scheduled posts go out late ("Missed schedule"), and emails, backups, imports and WooCommerce tasks wait. On a busy site it means visitors pay for cron with slower page loads.

CronEngine fixes both. It calls your site's `wp-cron.php` on a real schedule from the outside, and the plugin stops page views from starting cron.

* **One click to set up.** Click Connect, sign in or create a free account, and you are back in WordPress with cron running. No wp-config.php edits, no server crontab.
* **On time.** Every 15 minutes on the free plan, down to every minute on paid plans.
* **Alerts.** You get an email when your site stops answering, and one when it is back.
* **Safety net.** If CronEngine ever stops calling, WordPress goes back to running cron on visits by itself, so nothing is skipped.
* **See it working.** Last run, next run and recent results right in Tools, CronEngine, and a check in Site Health.
* **More when you need it.** Retries, Slack, Discord and webhook alerts, run history and custom schedules are in your CronEngine account.

Deactivating the plugin puts WordPress back exactly as it was.

== External services ==

This plugin connects your site to CronEngine (https://cronengine.com), a hosted cron and monitoring service run by Furora BV, Belgium. The plugin does nothing that involves CronEngine until an administrator clicks "Connect to CronEngine".

What is sent, and when:

* **When you click Connect:** your browser is sent to cronengine.com with this site's address, its name, the plugin version, and a random one-time value used to finish the connection securely. You sign in or create an account there.
* **When the connection finishes, when you open Tools, CronEngine, or change the interval:** the plugin asks CronEngine for this site's status or sends the new interval, using the site's own key.
* **When you disconnect or delete the plugin:** the plugin tells CronEngine to delete this site's cron job.
* **From CronEngine to your site:** CronEngine requests `wp-cron.php` on the interval you chose. It records the HTTP status and timing of each request, and the response only when a request fails.

No visitor data, content, or personal data about your users is sent.

* Terms of Service: https://cronengine.com/terms
* Privacy Policy: https://cronengine.com/privacy-policy

== Installation ==

1. Install and activate the plugin.
2. Go to Tools, CronEngine and click Connect to CronEngine.
3. Sign in or create a free account, choose how often cron should run, and click Connect site.

You are sent back to WordPress and cron runs on schedule from then on.

== Frequently Asked Questions ==

= Do I need a CronEngine account? =

Yes. The free plan is enough for one site every 15 minutes, and no card is needed. You can create the account during setup.

= Does it work on shared hosting? =

Yes. Nothing is installed on the server. CronEngine calls your site over HTTPS like a visitor would.

= What happens if CronEngine is down, or my account ends? =

The plugin notices when CronEngine has not called for a while and lets WordPress run cron on page views again, as it did before. When CronEngine calls again, it switches back.

= I already set DISABLE_WP_CRON in wp-config.php. =

That is fine, CronEngine still runs cron. The only difference is that the safety net cannot switch visitor cron back on while that line is there. The plugin page tells you when this applies.

= Does it work with WooCommerce and Action Scheduler? =

Yes. Action Scheduler runs from WP-Cron, so its queue is processed on CronEngine's schedule.

= Does it work on multisite? =

Yes. Connect each site from its own Tools, CronEngine page.

= My host blocks requests to wp-cron.php. =

Some security plugins and firewalls block it. Allow requests with the user agent "CronEngine-Dispatcher", or from 45.9.188.216.

== Screenshots ==

1. Connect your site in one click.
2. Pick how often cron runs. CronEngine suggests an interval that leaves room for your other jobs.
3. See the last run, the next run and recent results, and change the interval in one click.

== Changelog ==

= 1.0.0 =
* First release.
