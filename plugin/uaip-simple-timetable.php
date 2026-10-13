<?php
/**
 * Plugin Name:       UAIP Simple Timetable
 * Plugin URI:        https://github.com/uaip-dev/uaip-simple-timetable
 * Description:       Class schedules and timetables with weekly, daily, monthly and list views, repeating events, locations and instructors. Free, no premium version.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            UAIP
 * Author URI:        https://uaip.dev/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       uaip-simple-timetable
 *
 * @package UaipSimpleTimetable
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UAIP_TT_VERSION', '0.1.0' );
define( 'UAIP_TT_FILE', __FILE__ );
define( 'UAIP_TT_DIR', plugin_dir_path( __FILE__ ) );
define( 'UAIP_TT_URL', plugin_dir_url( __FILE__ ) );
define( 'UAIP_TT_BASENAME', plugin_basename( __FILE__ ) );

spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'Uaip_Timetable_' ) ) {
			return;
		}
		$file = 'class-' . str_replace( '_', '-', strtolower( $class_name ) ) . '.php';
		foreach ( array( 'includes/', 'includes/admin/' ) as $dir ) {
			$path = UAIP_TT_DIR . $dir . $file;
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

register_activation_hook( __FILE__, array( 'Uaip_Timetable_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

add_action(
	'plugins_loaded',
	static function () {
		Uaip_Timetable_Plugin::instance()->boot();
	}
);
