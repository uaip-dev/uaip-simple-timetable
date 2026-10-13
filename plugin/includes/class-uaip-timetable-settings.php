<?php
/**
 * Site-wide settings (one option array). The settings screen comes later; this class owns
 * the defaults and sanitising so every component reads settings the same way.
 *
 * @package UaipSimpleTimetable
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and sanitises the `uaip_tt_settings` option.
 */
final class Uaip_Timetable_Settings {

	const OPTION = 'uaip_tt_settings';

	const VIEWS   = array( 'week', 'day', 'month', 'list' );
	const SLOTS   = array( 15, 30, 60 );
	const SCHEMES = array( 'default', 'modern', 'minimal', 'dark', 'warm' );

	/**
	 * Default values. Empty time format / week start mean "use the WordPress setting".
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'time_format'        => '',
			'week_start'         => '',
			'default_view'       => 'week',
			'slot'               => 60,
			'scheme'             => 'default',
			'list_days'          => 14,
			'public_locations'   => false,
			'public_instructors' => false,
			'ics'                => true,
			'delete_data'        => false,
		);
	}

	/**
	 * All settings merged over the defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return self::sanitize( is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * PHP time format to use for display.
	 *
	 * @return string
	 */
	public static function time_format() {
		$format = self::get( 'time_format' );
		if ( '12' === $format ) {
			return 'g:i a';
		}
		if ( '24' === $format ) {
			return 'H:i';
		}
		$wp = get_option( 'time_format' );
		return is_string( $wp ) && '' !== $wp ? $wp : 'H:i';
	}

	/**
	 * First day of the week, 0 (Sunday) to 6.
	 *
	 * @return int
	 */
	public static function week_start() {
		$value = self::get( 'week_start' );
		if ( '' === $value || null === $value ) {
			$value = get_option( 'start_of_week', 1 );
		}
		return min( 6, max( 0, (int) $value ) );
	}

	/**
	 * Sanitises a settings array (also used as the Settings API callback).
	 *
	 * @param array<string,mixed> $input Raw values.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ) {
		$d   = self::defaults();
		$in  = is_array( $input ) ? $input : array();
		$out = $d;

		$time_format        = isset( $in['time_format'] ) ? (string) $in['time_format'] : '';
		$out['time_format'] = in_array( $time_format, array( '', '12', '24' ), true ) ? $time_format : '';

		$week_start        = isset( $in['week_start'] ) ? (string) $in['week_start'] : '';
		$out['week_start'] = ( '' === $week_start || ! ctype_digit( $week_start ) || (int) $week_start > 6 ) ? '' : $week_start;

		$view                = isset( $in['default_view'] ) ? (string) $in['default_view'] : '';
		$out['default_view'] = in_array( $view, self::VIEWS, true ) ? $view : $d['default_view'];

		$slot        = isset( $in['slot'] ) ? (int) $in['slot'] : 0;
		$out['slot'] = in_array( $slot, self::SLOTS, true ) ? $slot : $d['slot'];

		$scheme        = isset( $in['scheme'] ) ? (string) $in['scheme'] : '';
		$out['scheme'] = in_array( $scheme, self::SCHEMES, true ) ? $scheme : $d['scheme'];

		$days             = isset( $in['list_days'] ) ? (int) $in['list_days'] : 0;
		$out['list_days'] = $days >= 1 && $days <= 90 ? $days : $d['list_days'];

		foreach ( array( 'public_locations', 'public_instructors', 'ics', 'delete_data' ) as $key ) {
			if ( array_key_exists( $key, $in ) ) {
				$out[ $key ] = (bool) $in[ $key ];
			}
		}
		return $out;
	}
}
