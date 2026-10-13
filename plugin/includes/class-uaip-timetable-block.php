<?php
/**
 * The Timetable and Upcoming Classes blocks.
 *
 * @package UaipSimpleTimetable
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the blocks from plugin/blocks/ and renders them on the server.
 */
final class Uaip_Timetable_Block {

	/**
	 * Hooks. Blocks register after the assets they reference (init priority 10).
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'register' ), 20 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_data' ) );
	}

	/**
	 * Registers both blocks.
	 */
	public function register() {
		register_block_type(
			UAIP_TT_DIR . 'blocks/timetable',
			array( 'render_callback' => array( $this, 'render_timetable' ) )
		);
		register_block_type(
			UAIP_TT_DIR . 'blocks/upcoming',
			array( 'render_callback' => array( $this, 'render_upcoming' ) )
		);
	}

	/**
	 * Choices for the block settings (categories, locations, instructors…).
	 */
	public function editor_data() {
		$categories = array();
		$terms      = get_terms(
			array(
				'taxonomy'   => Uaip_Timetable_Post_Types::CATEGORY,
				'hide_empty' => false,
			)
		);
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$categories[] = array(
				'value' => $term->slug,
				'label' => $term->name,
			);
		}
		$options = static function ( $choices ) {
			$out = array();
			foreach ( $choices as $id => $title ) {
				$out[] = array(
					'value' => $id,
					'label' => $title,
				);
			}
			return $out;
		};
		$days  = array();
		$start = Uaip_Timetable_Settings::week_start();
		for ( $i = 0; $i < 7; $i++ ) {
			$day    = ( $start + $i ) % 7;
			$days[] = array(
				'value' => $day,
				'label' => Uaip_Timetable_Event::weekday_name( $day ),
			);
		}
		$events = get_posts(
			array(
				'post_type'      => Uaip_Timetable_Post_Types::EVENT,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$data   = array(
			'categories'  => $categories,
			'locations'   => $options( Uaip_Timetable_Post_Types::choices( Uaip_Timetable_Post_Types::LOCATION ) ),
			'instructors' => $options( Uaip_Timetable_Post_Types::choices( Uaip_Timetable_Post_Types::INSTRUCTOR ) ),
			'days'        => $days,
			'hasEvents'   => ! empty( $events ),
			'newEvent'    => admin_url( 'post-new.php?post_type=' . Uaip_Timetable_Post_Types::EVENT ),
			'events'      => admin_url( 'edit.php?post_type=' . Uaip_Timetable_Post_Types::EVENT ),
			'ics'         => (bool) Uaip_Timetable_Settings::get( 'ics' ),
			'defaults'    => array(
				'view'     => Uaip_Timetable_Settings::get( 'default_view' ),
				'slot'     => Uaip_Timetable_Settings::get( 'slot' ),
				'scheme'   => Uaip_Timetable_Settings::get( 'scheme' ),
				'listDays' => Uaip_Timetable_Settings::get( 'list_days' ),
			),
		);
		wp_add_inline_script( Uaip_Timetable_Assets::BLOCK, 'window.uaipTimetableBlock = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * Server render of the Timetable block.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_timetable( $attributes ) {
		$a     = is_array( $attributes ) ? $attributes : array();
		$raw   = array(
			'view'       => isset( $a['view'] ) ? (string) $a['view'] : '',
			'views'      => isset( $a['views'] ) ? (array) $a['views'] : array(),
			'category'   => isset( $a['category'] ) ? (array) $a['category'] : array(),
			'location'   => isset( $a['location'] ) ? (array) $a['location'] : array(),
			'instructor' => isset( $a['instructor'] ) ? (array) $a['instructor'] : array(),
			'days'       => isset( $a['days'] ) ? (array) $a['days'] : array(),
			'start'      => isset( $a['start'] ) ? (string) $a['start'] : '',
			'end'        => isset( $a['end'] ) ? (string) $a['end'] : '',
			'slot'       => isset( $a['slot'] ) ? (int) $a['slot'] : 0,
			'scheme'     => isset( $a['scheme'] ) ? (string) $a['scheme'] : '',
			'typical'    => ! empty( $a['typical'] ),
			'filters'    => ! isset( $a['filters'] ) || ! empty( $a['filters'] ),
			'list_days'  => isset( $a['listDays'] ) ? (int) $a['listDays'] : 0,
			'subscribe'  => ! isset( $a['subscribe'] ) || ! empty( $a['subscribe'] ),
		);
		$html  = Uaip_Timetable_Renderer::render_instance( Uaip_Timetable_Renderer::from_owner( $raw ) );
		return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
	}

	/**
	 * Server render of the Upcoming Classes block.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_upcoming( $attributes ) {
		$a = is_array( $attributes ) ? $attributes : array();
		Uaip_Timetable_Assets::enqueue_front( false );
		$html = Uaip_Timetable_Renderer::upcoming(
			array(
				'count'           => isset( $a['count'] ) ? (int) $a['count'] : 5,
				'days'            => isset( $a['days'] ) ? (int) $a['days'] : 14,
				'category'        => isset( $a['category'] ) ? (array) $a['category'] : array(),
				'location'        => isset( $a['location'] ) ? (array) $a['location'] : array(),
				'instructor'      => isset( $a['instructor'] ) ? (array) $a['instructor'] : array(),
				'show_location'   => ! isset( $a['showLocation'] ) || ! empty( $a['showLocation'] ),
				'show_instructor' => ! empty( $a['showInstructor'] ),
			)
		);
		return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
	}
}
