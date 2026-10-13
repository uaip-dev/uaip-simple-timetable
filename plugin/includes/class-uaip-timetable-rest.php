<?php
/**
 * Public read-only REST API: occurrences as JSON, and rendered timetable HTML for the
 * front-end script's navigation and filters. Only published data is ever returned.
 *
 * @package UaipSimpleTimetable
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes under uaip-simple-timetable/v1.
 */
final class Uaip_Timetable_Rest {

	const NAMESPACE = 'uaip-simple-timetable/v1';

	/** Longest range for /occurrences, in days. */
	const MAX_DAYS = 366;

	/**
	 * Hooks.
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes() {
		$text = array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		);

		register_rest_route(
			self::NAMESPACE,
			'/occurrences',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'occurrences' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'from'       => $text + array( 'required' => true ),
					'to'         => $text + array( 'required' => true ),
					'category'   => $text,
					'location'   => $text,
					'instructor' => $text,
				),
			)
		);

		$render_args = array();
		foreach ( array( 'view', 'views', 'date', 'category', 'location', 'instructor', 'days', 'start', 'end', 'slot', 'scheme', 'typical', 'filters', 'list_days', 'subscribe', 'fixed', 'instance', 'base' ) as $key ) {
			$render_args[ $key ] = $text;
		}
		$render_args['base']['sanitize_callback'] = 'esc_url_raw';

		register_rest_route(
			self::NAMESPACE,
			'/render',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'render' ),
				'permission_callback' => '__return_true',
				'args'                => $render_args,
			)
		);
	}

	/**
	 * GET /occurrences?from=Y-m-d&to=Y-m-d[&category=slug,slug][&location=id][&instructor=id]
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function occurrences( WP_REST_Request $request ) {
		$from = Uaip_Timetable_Event::sanitize_date( (string) $request['from'] );
		$to   = Uaip_Timetable_Event::sanitize_date( (string) $request['to'] );
		if ( '' === $from || '' === $to || $to < $from ) {
			return new WP_Error( 'uaip_tt_bad_range', __( 'Give "from" and "to" as YYYY-MM-DD, with "to" not before "from".', 'uaip-simple-timetable' ), array( 'status' => 400 ) );
		}
		if ( Uaip_Timetable_Occurrences::day_number( $to ) - Uaip_Timetable_Occurrences::day_number( $from ) >= self::MAX_DAYS ) {
			/* translators: %d: maximum number of days */
			return new WP_Error( 'uaip_tt_range_too_long', sprintf( __( 'The range may be at most %d days.', 'uaip-simple-timetable' ), self::MAX_DAYS ), array( 'status' => 400 ) );
		}
		$attrs = Uaip_Timetable_Renderer::normalize(
			array(
				'category'   => (string) $request['category'],
				'location'   => (string) $request['location'],
				'instructor' => (string) $request['instructor'],
			)
		);
		$items = Uaip_Timetable_Occurrences::between(
			$from,
			$to,
			array(
				'category'   => $attrs['category'],
				'location'   => $attrs['location'],
				'instructor' => $attrs['instructor'],
			)
		);
		return rest_ensure_response( $items );
	}

	/**
	 * GET /render: the timetable HTML for the given attributes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function render( WP_REST_Request $request ) {
		$raw = array();
		foreach ( array( 'view', 'views', 'date', 'category', 'location', 'instructor', 'days', 'start', 'end', 'slot', 'scheme', 'typical', 'filters', 'list_days', 'subscribe', 'fixed' ) as $key ) {
			if ( null !== $request[ $key ] ) {
				$raw[ $key ] = (string) $request[ $key ];
			}
		}
		$attrs = Uaip_Timetable_Renderer::normalize( $raw );

		// Links in the HTML point back at the page the timetable is on (same site only).
		$base = (string) $request['base'];
		$base = '' !== $base ? wp_validate_redirect( $base, '' ) : '';
		if ( '' !== $base ) {
			$base = remove_query_arg( Uaip_Timetable_Renderer::query_keys(), strtok( $base, '#' ) );
		}

		return rest_ensure_response(
			array( 'html' => Uaip_Timetable_Renderer::render( $attrs, (int) $request['instance'], $base ) )
		);
	}
}
