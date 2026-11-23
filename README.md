# GitHub Push Updates

Installs updates for WordPress plugins that self-update from GitHub Releases with
[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker), as soon as a
release is published, instead of waiting up to 12 hours for the next check and a manual click.

## How it works

1. Each plugin repository has a `Release` GitHub Actions workflow: a push to `main` that changes
   the `Version:` header publishes a release for that version.
2. A repository webhook (event: *Releases*) calls this plugin on the site. The signature is
   checked, and the update is installed in the background within a minute.
3. An hourly check installs anything a webhook missed, and WordPress's own auto-updater is
   enabled for the same plugins.

Managed plugins are found automatically: any plugin that has registered a Plugin Update Checker
cron event (`puc_cron_check_updates-{slug}`). The webhook's repository name must match the
plugin's folder slug.

## Setup

1. Install and activate the plugin.
2. Add a random secret to `wp-config.php`:

   ```php
   define( 'GPU_WEBHOOK_SECRET', 'a-long-random-string' );
   ```

3. In each plugin repository on GitHub: *Settings → Webhooks → Add webhook*
   - Payload URL: `https://your-site.example/wp-json/github-push-updates/v1/webhook`
   - Content type: `application/json`
   - Secret: the same string
   - Events: *Let me select individual events → Releases*

*Tools → GitHub Push Updates* lists the managed plugins, recent runs, and has a
"Check GitHub now" button.

## Notes

- Updates use the standard WordPress plugin upgrader, so the site shows the usual
  "briefly unavailable for scheduled maintenance" page for a few seconds while files are replaced.
- With `DISABLE_WP_CRON`, make sure a system cron runs `wp cron event run --due-now` regularly;
  the webhook only schedules the update.
- Filter `gpu_managed_slugs` to limit or extend the list.

License: GPL-2.0-or-later
