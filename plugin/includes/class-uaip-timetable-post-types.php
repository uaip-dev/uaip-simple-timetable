<?php
/**
 * Post types, taxonomy and meta registration.
 *
 * @package UaipSimpleTimetable
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers events, locations, instructors and event categories.
 */
final class Uaip_Timetable_Post_Types {

	const EVENT      = 'uaip_tt_event';
	const LOCATION   = 'uaip_tt_location';
	const INSTRUCTOR = 'uaip_tt_instructor';
	const CATEGORY   = 'uaip_tt_category';

	/** Category colour (term meta). */
	const TERM_COLOR = 'uaip_tt_color';

	/** Location meta. */
	const META_ADDRESS = '_uaip_tt_address';
	const META_MAP_URL = '_uaip_tt_map_url';

	/** Instructor meta. */
	const META_EMAIL   = '_uaip_tt_email';
	const META_PHONE   = '_uaip_tt_phone';
	const META_WEBSITE = '_uaip_tt_website';

	/** Fallback event colour when neither the event nor its category sets one. */
	const DEFAULT_COLOR = '#2271b1';

	/**
	 * Hooks registration into init.
	 */
	public function register_hooks() {
		add_action( 'init', array( __CLASS__, 'register_types' ) );
	}

	/**
	 * Registers everything. Safe to call more than once per request.
	 */
	public static function register_types() {
		if ( post_type_exists( self::EVENT ) ) {
			return;
		}
		self::register_event();
		self::register_location();
		self::register_instructor();
		self::register_category();
		self::register_meta();
	}

	/**
	 * The event post type.
	 */
	private static function register_event() {
		register_post_type(
			self::EVENT,
			array(
				'labels'        => array(
					'name'                  => _x( 'Timetable events', 'post type general name', 'uaip-simple-timetable' ),
					'singular_name'         => _x( 'Timetable event', 'post type singular name', 'uaip-simple-timetable' ),
					'menu_name'             => _x( 'Timetable', 'admin menu', 'uaip-simple-timetable' ),
					'name_admin_bar'        => _x( 'Timetable event', 'add new on admin bar', 'uaip-simple-timetable' ),
					'add_new'               => __( 'Add Event', 'uaip-simple-timetable' ),
					'add_new_item'          => __( 'Add Event', 'uaip-simple-timetable' ),
					'new_item'              => __( 'New Event', 'uaip-simple-timetable' ),
					'edit_item'             => __( 'Edit Event', 'uaip-simple-timetable' ),
					'view_item'             => __( 'View Event', 'uaip-simple-timetable' ),
					'view_items'            => __( 'View Events', 'uaip-simple-timetable' ),
					'all_items'             => __( 'Events', 'uaip-simple-timetable' ),
					'search_items'          => __( 'Search Events', 'uaip-simple-timetable' ),
					'not_found'             => __( 'No events found.', 'uaip-simple-timetable' ),
					'not_found_in_trash'    => __( 'No events found in Trash.', 'uaip-simple-timetable' ),
					'item_published'        => __( 'Event published.', 'uaip-simple-timetable' ),
					'item_updated'          => __( 'Event updated.', 'uaip-simple-timetable' ),
					'featured_image'        => __( 'Event image', 'uaip-simple-timetable' ),
					'set_featured_image'    => __( 'Set event image', 'uaip-simple-timetable' ),
					'remove_featured_image' => __( 'Remove event image', 'uaip-simple-timetable' ),
					'use_featured_image'    => __( 'Use as event image', 'uaip-simple-timetable' ),
				),
				'public'        => true,
				'has_archive'   => false,
				'show_in_rest'  => true,
				'menu_position' => 26,
				'menu_icon'     => 'dashicons-calendar-alt',
				'rewrite'       => array(
					'slug'       => 'timetable-event',
					'with_front' => false,
				),
				// custom-fields: required for the schedule meta to appear in the REST API / block editor.
				'supports'      => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions' ),
			)
		);
	}

	/**
	 * The location post type. Public pages only when enabled in the settings.
	 */
	private static function register_location() {
		$public = (bool) Uaip_Timetable_Settings::get( 'public_locations' );
		register_post_type(
			self::LOCATION,
			array(
				'labels'              => array(
					'name'               => _x( 'Locations', 'post type general name', 'uaip-simple-timetable' ),
					'singular_name'      => _x( 'Location', 'post type singular name', 'uaip-simple-timetable' ),
					'add_new'            => __( 'Add Location', 'uaip-simple-timetable' ),
					'add_new_item'       => __( 'Add Location', 'uaip-simple-timetable' ),
					'edit_item'          => __( 'Edit Location', 'uaip-simple-timetable' ),
					'new_item'           => __( 'New Location', 'uaip-simple-timetable' ),
					'view_item'          => __( 'View Location', 'uaip-simple-timetable' ),
					'all_items'          => __( 'Locations', 'uaip-simple-timetable' ),
					'search_items'       => __( 'Search Locations', 'uaip-simple-timetable' ),
					'not_found'          => __( 'No locations found.', 'uaip-simple-timetable' ),
					'not_found_in_trash' => __( 'No locations found in Trash.', 'uaip-simple-timetable' ),
				),
				'public'              => $public,
				'publicly_queryable'  => $public,
				'exclude_from_search' => ! $public,
				'show_ui'             => true,
				'show_in_menu'        => 'edit.php?post_type=' . self::EVENT,
				'show_in_nav_menus'   => $public,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => $public ? array(
					'slug'       => 'timetable-location',
					'with_front' => false,
				) : false,
				'supports'            => array( 'title', 'editor', 'thumbnail' ),
			)
		);
	}

	/**
	 * The instructor post type. Public pages only when enabled in the settings.
	 */
	private static function register_instructor() {
		$public = (bool) Uaip_Timetable_Settings::get( 'public_instructors' );
		register_post_type(
			self::INSTRUCTOR,
			array(
				'labels'              => array(
					'name'                  => _x( 'Instructors', 'post type general name', 'uaip-simple-timetable' ),
					'singular_name'         => _x( 'Instructor', 'post type singular name', 'uaip-simple-timetable' ),
					'add_new'               => __( 'Add Instructor', 'uaip-simple-timetable' ),
					'add_new_item'          => __( 'Add Instructor', 'uaip-simple-timetable' ),
					'edit_item'             => __( 'Edit Instructor', 'uaip-simple-timetable' ),
					'new_item'              => __( 'New Instructor', 'uaip-simple-timetable' ),
					'view_item'             => __( 'View Instructor', 'uaip-simple-timetable' ),
					'all_items'             => __( 'Instructors', 'uaip-simple-timetable' ),
					'search_items'          => __( 'Search Instructors', 'uaip-simple-timetable' ),
					'not_found'             => __( 'No instructors found.', 'uaip-simple-timetable' ),
					'not_found_in_trash'    => __( 'No instructors found in Trash.', 'uaip-simple-timetable' ),
					'featured_image'        => __( 'Photo', 'uaip-simple-timetable' ),
					'set_featured_image'    => __( 'Set photo', 'uaip-simple-timetable' ),
					'remove_featured_image' => __( 'Remove photo', 'uaip-simple-timetable' ),
					'use_featured_image'    => __( 'Use as photo', 'uaip-simple-timetable' ),
				),
				'public'              => $public,
				'publicly_queryable'  => $public,
				'exclude_from_search' => ! $public,
				'show_ui'             => true,
				'show_in_menu'        => 'edit.php?post_type=' . self::EVENT,
				'show_in_nav_menus'   => $public,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => $public ? array(
					'slug'       => 'timetable-instructor',
					'with_front' => false,
				) : false,
				'supports'            => array( 'title', 'editor', 'thumbnail' ),
			)
		);
	}

	/**
	 * Event categories (Yoga, HIIT, Maths…), each with an optional colour.
	 */
	private static function register_category() {
		register_taxonomy(
			self::CATEGORY,
			array( self::EVENT ),
			array(
				'labels'            => array(
					'name'          => _x( 'Event categories', 'taxonomy general name', 'uaip-simple-timetable' ),
					'singular_name' => _x( 'Event category', 'taxonomy singular name', 'uaip-simple-timetable' ),
					'menu_name'     => __( 'Categories', 'uaip-simple-timetable' ),
					'all_items'     => __( 'All Categories', 'uaip-simple-timetable' ),
					'edit_item'     => __( 'Edit Category', 'uaip-simple-timetable' ),
					'view_item'     => __( 'View Category', 'uaip-simple-timetable' ),
					'update_item'   => __( 'Update Category', 'uaip-simple-timetable' ),
					'add_new_item'  => __( 'Add Category', 'uaip-simple-timetable' ),
					'new_item_name' => __( 'New Category Name', 'uaip-simple-timetable' ),
					'search_items'  => __( 'Search Categories', 'uaip-simple-timetable' ),
					'not_found'     => __( 'No categories found.', 'uaip-simple-timetable' ),
				),
				'hierarchical'      => true,
				'public'            => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array(
					'slug'       => 'timetable-category',
					'with_front' => false,
				),
			)
		);
	}

	/**
	 * Event schedule meta (exposed to the block editor), location/instructor meta and the
	 * category colour.
	 */
	private static function register_meta() {
		$auth = static function ( $allowed, $meta_key, $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		};

		foreach ( Uaip_Timetable_Event::meta_fields() as $key => $field ) {
			$schema = array( 'type' => $field['type'] );
			if ( 'array' === $field['type'] ) {
				$schema['items'] = array( 'type' => $field['items'] );
			}
			register_post_meta(
				self::EVENT,
				$key,
				array(
					'type'              => $field['type'],
					'single'            => true,
					'default'           => $field['default'],
					'sanitize_callback' => $field['sanitize'],
					'auth_callback'     => $auth,
					'show_in_rest'      => array( 'schema' => $schema ),
				)
			);
		}

		$text = array(
			self::LOCATION   => array(
				self::META_ADDRESS => 'sanitize_textarea_field',
				self::META_MAP_URL => array( 'Uaip_Timetable_Event', 'sanitize_url' ),
			),
			self::INSTRUCTOR => array(
				self::META_EMAIL   => 'sanitize_email',
				self::META_PHONE   => 'sanitize_text_field',
				self::META_WEBSITE => array( 'Uaip_Timetable_Event', 'sanitize_url' ),
			),
		);
		foreach ( $text as $post_type => $fields ) {
			foreach ( $fields as $key => $sanitize ) {
				register_post_meta(
					$post_type,
					$key,
					array(
						'type'              => 'string',
						'single'            => true,
						'default'           => '',
						'sanitize_callback' => $sanitize,
						'auth_callback'     => $auth,
						'show_in_rest'      => false,
					)
				);
			}
		}
		register_post_meta(
			self::LOCATION,
			Uaip_Timetable_Event::META_COLOR,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => array( 'Uaip_Timetable_Event', 'sanitize_color' ),
				'auth_callback'     => $auth,
				'show_in_rest'      => false,
			)
		);

		register_term_meta(
			self::CATEGORY,
			self::TERM_COLOR,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => array( 'Uaip_Timetable_Event', 'sanitize_color' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Published (and readable private) locations or instructors as id => title, for selects.
	 *
	 * @param string $post_type self::LOCATION or self::INSTRUCTOR.
	 * @return array<int,string>
	 */
	public static function choices( $post_type ) {
		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		$out   = array();
		foreach ( $posts as $post ) {
			// Private entries are listed only for users allowed to read them.
			if ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}
			$out[ $post->ID ] = get_the_title( $post );
		}
		return $out;
	}
}
