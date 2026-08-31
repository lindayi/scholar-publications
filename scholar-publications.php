<?php
/**
 * Plugin Name: Scholar Publications
 * Plugin URI:  https://github.com/lindayi/scholar-publications
 * Description: An interactive, filterable publication list for WordPress, sourced from Google Scholar. Provides the [scholar_publications] shortcode.
 * Version:     1.0.1
 * Author:      Dayi Lin
 * Author URI:  https://lindayi.me
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: scholar-publications
 * Requires at least: 6.1
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCHPUB_VERSION', '1.0.1' );
define( 'SCHPUB_FILE', __FILE__ );
define( 'SCHPUB_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCHPUB_URL', plugin_dir_url( __FILE__ ) );

require_once SCHPUB_DIR . 'includes/class-store.php';
require_once SCHPUB_DIR . 'includes/class-serpapi.php';
require_once SCHPUB_DIR . 'includes/class-sync.php';
require_once SCHPUB_DIR . 'includes/class-admin.php';
require_once SCHPUB_DIR . 'includes/class-shortcode.php';

/**
 * Default settings, used until the options page is saved.
 *
 * @return array
 */
function schpub_default_settings() {
	return array(
		'scholar_id'      => '',
		'serpapi_key'     => '',
		'refresh'         => 'daily',
		'max_details_run' => 10,
		'quota_reserve'   => 40,
		'detail_ttl_days' => 0,
		'sort_default'    => 'year',
		'layout'          => 'sidebar',
		'group_by_year'   => 1,
		'show_stats'      => 1,
		'show_chart'      => 1,
		'highlight_name'  => '',
		'min_year'        => 0,
		'exclude_titles'  => '',
	);
}

/**
 * Read all settings merged over the defaults.
 *
 * @return array
 */
function schpub_settings() {
	$saved = get_option( 'schpub_settings', array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return array_merge( schpub_default_settings(), $saved );
}

/**
 * Read one setting.
 *
 * @param string $key     Setting key.
 * @param mixed  $default Value returned when the setting is unset or blank.
 * @return mixed
 */
function schpub_setting( $key, $default = '' ) {
	$settings = schpub_settings();
	if ( ! isset( $settings[ $key ] ) || '' === $settings[ $key ] ) {
		return $default;
	}
	return $settings[ $key ];
}

/**
 * Wire up the plugin components.
 */
function schpub_bootstrap() {
	SchPub_Sync::init();
	SchPub_Shortcode::init();
	if ( is_admin() ) {
		SchPub_Admin::init();
	}
}
add_action( 'plugins_loaded', 'schpub_bootstrap' );

/**
 * Keep WordPress from ever offering an update for this plugin.
 *
 * This plugin is bespoke and is not distributed through wordpress.org. If a
 * public plugin ever claims the same folder name, WordPress would match on the
 * slug and could overwrite these files with unrelated code. Dropping the entry
 * from the update transient makes that impossible.
 *
 * @param mixed $value The update_plugins site transient.
 * @return mixed
 */
function schpub_block_external_updates( $value ) {
	if ( is_object( $value ) && ! empty( $value->response ) && is_array( $value->response ) ) {
		unset( $value->response[ plugin_basename( SCHPUB_FILE ) ] );
	}
	return $value;
}
add_filter( 'site_transient_update_plugins', 'schpub_block_external_updates' );

/**
 * Schedule the recurring refresh on activation.
 */
function schpub_activate() {
	SchPub_Sync::reschedule();
}
register_activation_hook( __FILE__, 'schpub_activate' );

/**
 * Remove scheduled events on deactivation.
 */
function schpub_deactivate() {
	SchPub_Sync::unschedule();
}
register_deactivation_hook( __FILE__, 'schpub_deactivate' );
