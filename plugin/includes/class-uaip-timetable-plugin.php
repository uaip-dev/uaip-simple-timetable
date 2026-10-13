<?php
/**
 * Plugin orchestrator.
 *
 * @package UaipSimpleTimetable
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots the plugin's components once.
 */
final class Uaip_Timetable_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Uaip_Timetable_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Returns the shared instance.
	 *
	 * @return Uaip_Timetable_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers all hooks.
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		( new Uaip_Timetable_Post_Types() )->register_hooks();
		( new Uaip_Timetable_Occurrences() )->register_hooks();
		( new Uaip_Timetable_Assets() )->register_hooks();
		( new Uaip_Timetable_Shortcode() )->register_hooks();
		( new Uaip_Timetable_Rest() )->register_hooks();
		( new Uaip_Timetable_Single() )->register_hooks();

		if ( is_admin() ) {
			( new Uaip_Timetable_Admin() )->register_hooks();
		}
	}

	/**
	 * Activation: register the post types so their permalinks exist, then flush.
	 */
	public static function activate() {
		Uaip_Timetable_Post_Types::register_types();
		flush_rewrite_rules();
	}
}
