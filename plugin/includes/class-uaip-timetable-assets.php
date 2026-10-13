<?php
/**
 * Script and style registration. Front-end assets load only on pages with a timetable.
 *
 * @package UaipSimpleTimetable
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the built assets in plugin/build/.
 */
final class Uaip_Timetable_Assets {

	const FRONT_STYLE  = 'uaip-tt-front';
	const FRONT_MODULE = 'uaip-tt-front';
	const EDITOR       = 'uaip-tt-editor';
	const ADMIN        = 'uaip-tt-admin';

	/**
	 * Hooks.
	 */
	public function register_hooks() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_if_needed' ) );
	}

	/**
	 * Registers styles and scripts.
	 */
	public static function register() {
		$version = self::version();
		wp_register_style( self::FRONT_STYLE, UAIP_TT_URL . 'build/front/style.css', array(), $version );
		if ( function_exists( 'wp_register_script_module' ) ) {
			wp_register_script_module( self::FRONT_MODULE, UAIP_TT_URL . 'build/front/index.js', array(), $version );
		}
		wp_register_script(
			self::EDITOR,
			UAIP_TT_URL . 'build/editor/index.js',
			array( 'wp-plugins', 'wp-editor', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n', 'wp-core-data' ),
			$version,
			true
		);
		wp_set_script_translations( self::EDITOR, 'uaip-simple-timetable' );
		wp_register_script( self::ADMIN, UAIP_TT_URL . 'build/admin/index.js', array( 'wp-color-picker' ), $version, true );
		wp_register_style( self::ADMIN, UAIP_TT_URL . 'build/admin/style.css', array( 'wp-color-picker' ), $version );
	}

	/**
	 * Enqueues the front-end assets early (in the head) when the main post contains a
	 * timetable; otherwise rendering enqueues them late.
	 */
	public function enqueue_if_needed() {
		$post = get_post();
		if ( is_singular() && $post && has_shortcode( $post->post_content, Uaip_Timetable_Shortcode::TAG ) ) {
			self::enqueue_front();
		}
	}

	/**
	 * Enqueues the front-end style and script module.
	 */
	public static function enqueue_front() {
		wp_enqueue_style( self::FRONT_STYLE );
		if ( function_exists( 'wp_enqueue_script_module' ) ) {
			wp_enqueue_script_module( self::FRONT_MODULE );
		}
	}

	/**
	 * Asset version: the plugin version, or the build time while developing.
	 *
	 * @return string
	 */
	private static function version() {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			$mtime = @filemtime( UAIP_TT_DIR . 'build/front/index.js' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- missing build is fine.
			return $mtime ? UAIP_TT_VERSION . '.' . $mtime : UAIP_TT_VERSION;
		}
		return UAIP_TT_VERSION;
	}
}
