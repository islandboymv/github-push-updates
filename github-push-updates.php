<?php
/**
 * Plugin Name: GitHub Push Updates
 * Plugin URI:  https://github.com/islandboymv/github-push-updates
 * Description: Installs updates for plugins that self-update from GitHub Releases (Plugin Update Checker) as soon as a release is published. A GitHub webhook triggers the update within a minute; an hourly check catches anything a webhook missed.
 * Author:      Islandboy
 * Version:     0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License:     GPL-2.0-or-later
 * Text Domain: github-push-updates
 */

defined( 'ABSPATH' ) || exit;

define( 'GPU_VERSION', '0.1.0' );
define( 'GPU_FILE', __FILE__ );
define( 'GPU_LOG_OPTION', 'gpu_log' );

/*
 * Self-update from GitHub Releases, like the plugins it manages.
 */
if ( file_exists( __DIR__ . '/lib/plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once __DIR__ . '/lib/plugin-update-checker/plugin-update-checker.php';
	\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/islandboymv/github-push-updates/',
		__FILE__,
		'github-push-updates'
	);
}

/**
 * Slugs of installed plugins that self-update from GitHub with Plugin Update
 * Checker. PUC schedules a "puc_cron_check_updates-{slug}" event for each one,
 * which is how they are found without a hard-coded list.
 *
 * @return string[]
 */
function gpu_managed_slugs() {
	$slugs = array();
	foreach ( (array) _get_cron_array() as $events ) {
		foreach ( array_keys( (array) $events ) as $hook ) {
			if ( 0 === strpos( $hook, 'puc_cron_check_updates-' ) ) {
				$slugs[] = substr( $hook, strlen( 'puc_cron_check_updates-' ) );
			}
		}
	}
	$slugs = array_values( array_unique( $slugs ) );
	sort( $slugs );
	return apply_filters( 'gpu_managed_slugs', $slugs );
}

/**
 * Plugin file ("dir/main.php") for a slug, or null.
 */
function gpu_plugin_file( $slug ) {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	foreach ( array_keys( get_plugins() ) as $file ) {
		if ( dirname( $file ) === $slug ) {
			return $file;
		}
	}
	return null;
}

/**
 * Check GitHub now and install any available updates.
 *
 * @param string[] $only Limit to these slugs (empty = all managed plugins).
 * @param string   $why  Short reason, for the log.
 * @return array slug => result string
 */
function gpu_run_updates( $only = array(), $why = 'manual' ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	$targets = array();
	foreach ( gpu_managed_slugs() as $slug ) {
		if ( $only && ! in_array( $slug, $only, true ) ) {
			continue;
		}
		$file = gpu_plugin_file( $slug );
		if ( ! $file ) {
			continue;
		}
		// Forget when PUC last looked, so its scheduled check runs now.
		delete_option( 'external_updates-' . $slug );
		do_action( 'puc_cron_check_updates-' . $slug );
		$targets[ $slug ] = $file;
	}

	// PUC adds its findings to this transient whenever it is read.
	$available = get_site_transient( 'update_plugins' );
	$results   = array();
	$files     = array();
	foreach ( $targets as $slug => $file ) {
		if ( is_object( $available ) && isset( $available->response[ $file ] ) ) {
			$files[ $slug ] = $file;
		} else {
			$results[ $slug ] = 'up to date';
		}
	}

	if ( $files ) {
		$versions_before = array();
		foreach ( $files as $slug => $file ) {
			$data                      = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
			$versions_before[ $slug ] = $data['Version'];
		}
		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$outcome  = $upgrader->bulk_upgrade( array_values( $files ) );
		wp_clean_plugins_cache();
		foreach ( $files as $slug => $file ) {
			$ok   = isset( $outcome[ $file ] ) && $outcome[ $file ] && ! is_wp_error( $outcome[ $file ] );
			$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
			$results[ $slug ] = $ok
				? sprintf( 'updated %s -> %s', $versions_before[ $slug ], $data['Version'] )
				: 'update FAILED' . ( is_wp_error( $outcome[ $file ] ?? null ) ? ': ' . $outcome[ $file ]->get_error_message() : '' );
		}
	}

	gpu_log( $why, $results );
	return $results;
}

function gpu_log( $why, $results ) {
	$log = (array) get_option( GPU_LOG_OPTION, array() );
	array_unshift( $log, array( 'time' => time(), 'why' => $why, 'results' => $results ) );
	update_option( GPU_LOG_OPTION, array_slice( $log, 0, 30 ), false );
}

/*
 * Background jobs: an hourly safety net, and the one-off job a webhook schedules.
 */
add_action( 'gpu_hourly', function () {
	gpu_run_updates( array(), 'hourly check' );
} );
add_action( 'gpu_update_now', function ( $slug = '' ) {
	gpu_run_updates( $slug ? array( $slug ) : array(), 'GitHub release' . ( $slug ? " ($slug)" : '' ) );
} );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'gpu_hourly' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'gpu_hourly' );
	}
} );
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'gpu_hourly' );
} );

// Let WordPress's own auto-updater install these too (belt and braces).
add_filter( 'auto_update_plugin', function ( $update, $item ) {
	return ( isset( $item->slug ) && in_array( $item->slug, gpu_managed_slugs(), true ) ) ? true : $update;
}, 10, 2 );

/*
 * Webhook: POST /wp-json/github-push-updates/v1/webhook
 * GitHub repository webhook, content type application/json, events: Releases.
 * The secret must match the GPU_WEBHOOK_SECRET constant in wp-config.php.
 */
add_action( 'rest_api_init', function () {
	register_rest_route( 'github-push-updates/v1', '/webhook', array(
		'methods'             => 'POST',
		'callback'            => 'gpu_webhook',
		'permission_callback' => '__return_true', // authenticated by the HMAC signature below
	) );
} );

function gpu_webhook( WP_REST_Request $request ) {
	$secret = defined( 'GPU_WEBHOOK_SECRET' ) ? (string) GPU_WEBHOOK_SECRET : '';
	if ( '' === $secret ) {
		return new WP_REST_Response( array( 'error' => 'not configured' ), 503 );
	}
	$expected = 'sha256=' . hash_hmac( 'sha256', $request->get_body(), $secret );
	$given    = (string) $request->get_header( 'x_hub_signature_256' );
	if ( ! hash_equals( $expected, $given ) ) {
		return new WP_REST_Response( array( 'error' => 'bad signature' ), 401 );
	}

	$event   = (string) $request->get_header( 'x_github_event' );
	$payload = json_decode( $request->get_body(), true );
	if ( 'ping' === $event ) {
		return new WP_REST_Response( array( 'ok' => true, 'pong' => true ), 200 );
	}
	if ( 'release' !== $event || ! in_array( $payload['action'] ?? '', array( 'published', 'released' ), true ) ) {
		return new WP_REST_Response( array( 'ok' => true, 'ignored' => $event ), 200 );
	}

	$slug = sanitize_key( $payload['repository']['name'] ?? '' );
	if ( ! in_array( $slug, gpu_managed_slugs(), true ) ) {
		return new WP_REST_Response( array( 'ok' => true, 'ignored' => 'not installed here' ), 200 );
	}
	// Install in the background (GitHub gives webhooks 10 seconds).
	if ( ! wp_next_scheduled( 'gpu_update_now', array( $slug ) ) ) {
		wp_schedule_single_event( time(), 'gpu_update_now', array( $slug ) );
	}
	return new WP_REST_Response( array( 'ok' => true, 'scheduled' => $slug ), 202 );
}

/*
 * Tools > GitHub Push Updates: what is managed, recent runs, "check now".
 */
add_action( 'admin_menu', function () {
	add_management_page( 'GitHub Push Updates', 'GitHub Push Updates', 'update_plugins', 'github-push-updates', 'gpu_admin_page' );
} );

function gpu_admin_page() {
	if ( ! current_user_can( 'update_plugins' ) ) {
		return;
	}
	$ran = null;
	if ( isset( $_POST['gpu_check'] ) && check_admin_referer( 'gpu_check' ) ) {
		$ran = gpu_run_updates( array(), 'manual (Tools page)' );
	}
	echo '<div class="wrap"><h1>GitHub Push Updates</h1>';
	if ( null !== $ran ) {
		echo '<div class="notice notice-success"><p>' . esc_html( implode( '; ', array_map( function ( $s, $r ) { return "$s: $r"; }, array_keys( $ran ), $ran ) ) ?: 'Nothing to check.' ) . '</p></div>';
	}
	echo '<p>Webhook URL: <code>' . esc_html( rest_url( 'github-push-updates/v1/webhook' ) ) . '</code> &middot; secret ' . ( defined( 'GPU_WEBHOOK_SECRET' ) && GPU_WEBHOOK_SECRET ? 'set in wp-config.php' : '<strong>missing</strong> (define GPU_WEBHOOK_SECRET in wp-config.php)' ) . '</p>';
	echo '<h2>Managed plugins</h2><ul>';
	foreach ( gpu_managed_slugs() as $slug ) {
		$file = gpu_plugin_file( $slug );
		$ver  = $file ? get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false )['Version'] : '?';
		echo '<li><code>' . esc_html( $slug ) . '</code> ' . esc_html( $ver ) . '</li>';
	}
	echo '</ul><form method="post">';
	wp_nonce_field( 'gpu_check' );
	submit_button( 'Check GitHub now', 'secondary', 'gpu_check', false );
	echo '</form><h2>Recent runs</h2><table class="widefat striped"><thead><tr><th>When</th><th>Why</th><th>Result</th></tr></thead><tbody>';
	foreach ( (array) get_option( GPU_LOG_OPTION, array() ) as $row ) {
		$res = array();
		foreach ( (array) $row['results'] as $s => $r ) {
			$res[] = "$s: $r";
		}
		echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i', $row['time'] ) ) . '</td><td>' . esc_html( $row['why'] ) . '</td><td>' . esc_html( implode( ' · ', $res ) ?: '-' ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
}
