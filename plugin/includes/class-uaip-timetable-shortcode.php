<?php
/**
 * The [uaip_timetable] shortcode.
 *
 * @package UaipSimpleTimetable
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a timetable from shortcode attributes.
 */
final class Uaip_Timetable_Shortcode {

	const TAG = 'uaip_timetable';

	/**
	 * Hooks.
	 */
	public function register_hooks() {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Shortcode callback.
	 *
	 * Attributes: view (week|day|month|list), views (switcher, e.g. "week,list"), date (Y-m-d),
	 * category (slugs), location (IDs), instructor (IDs), days (e.g. "mon,tue"), start/end
	 * (H:i), slot (15|30|60), scheme, typical (yes|no), filters (yes|no), list_days.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'view'       => '',
				'views'      => '',
				'date'       => '',
				'category'   => '',
				'location'   => '',
				'instructor' => '',
				'days'       => '',
				'start'      => '',
				'end'        => '',
				'slot'       => '',
				'scheme'     => '',
				'typical'    => '',
				'filters'    => '',
				'list_days'  => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);
		return Uaip_Timetable_Renderer::render_instance( Uaip_Timetable_Renderer::from_owner( $atts ) );
	}
}
