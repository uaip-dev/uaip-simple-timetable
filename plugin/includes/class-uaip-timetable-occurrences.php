<?php
/**
 * Occurrence engine: turns events (a first date plus an optional weekly repeat) into dated
 * occurrences for a date range. Every view, the REST API and (later) the iCal feed use it.
 *
 * All published events are loaded once into a snapshot that is cached until any event,
 * location, instructor or category changes. Expanding and filtering per request is cheap,
 * so nothing is cached per date range (visitors cannot grow the cache).
 *
 * @package UaipSimpleTimetable
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expands event rules into occurrences.
 */
final class Uaip_Timetable_Occurrences {

	const SALT_OPTION = 'uaip_tt_cache_salt';
	const CACHE_TTL   = DAY_IN_SECONDS;

	/** Longest range one call may expand, in days. */
	const MAX_RANGE_DAYS = 400;

	/** Bump when the snapshot's shape changes, so cached copies are rebuilt. */
	const SCHEMA = 2;

	/** Most events loaded into the snapshot. */
	const MAX_EVENTS = 2000;

	/**
	 * Whether this request changed timetable data (skip the cache, bump the salt at shutdown).
	 *
	 * @var bool
	 */
	private static $dirty = false;

	/**
	 * Snapshot loaded in this request.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $snapshot = null;

	/**
	 * Cache invalidation hooks.
	 */
	public function register_hooks() {
		add_action( 'transition_post_status', array( $this, 'on_post_change' ), 10, 3 );
		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 2 );
		add_action( 'deleted_post', array( $this, 'on_deleted_post' ), 10, 2 );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'on_meta_change' ), 10, 3 );
		}
		foreach ( array( 'created_', 'edited_', 'delete_' ) as $prefix ) {
			add_action( $prefix . Uaip_Timetable_Post_Types::CATEGORY, array( __CLASS__, 'invalidate' ) );
		}
		add_action( 'set_object_terms', array( $this, 'on_terms_change' ), 10, 4 );
		add_action( 'update_option_' . Uaip_Timetable_Settings::OPTION, array( __CLASS__, 'invalidate' ) );
		add_action( 'update_option_permalink_structure', array( __CLASS__, 'invalidate' ) );
		add_action( 'shutdown', array( __CLASS__, 'flush_dirty' ) );
	}

	/**
	 * Post status changes of our post types.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public function on_post_change( $new_status, $old_status, $post ) {
		if ( $new_status !== $old_status && self::is_ours( $post ) ) {
			self::invalidate();
		}
	}

	/**
	 * Saves of our post types.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public function on_save_post( $post_id, $post ) {
		if ( self::is_ours( $post ) ) {
			self::invalidate();
		}
	}

	/**
	 * Deleted posts of our post types.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post.
	 */
	public function on_deleted_post( $post_id, $post = null ) {
		if ( $post && self::is_ours( $post ) ) {
			self::invalidate();
		}
	}

	/**
	 * Meta changes of our keys (the block editor saves meta after save_post).
	 *
	 * @param int|int[] $meta_ids  Meta ID(s).
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 */
	public function on_meta_change( $meta_ids, $object_id, $meta_key ) {
		if ( is_string( $meta_key ) && 0 === strpos( $meta_key, '_uaip_tt_' ) ) {
			self::invalidate();
		}
	}

	/**
	 * Category assignment changes on events.
	 *
	 * @param int    $object_id Object ID.
	 * @param array  $terms     Terms.
	 * @param array  $tt_ids    Term taxonomy IDs.
	 * @param string $taxonomy  Taxonomy.
	 */
	public function on_terms_change( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( Uaip_Timetable_Post_Types::CATEGORY === $taxonomy ) {
			self::invalidate();
		}
	}

	/**
	 * Whether a post belongs to one of our post types.
	 *
	 * @param mixed $post Post.
	 * @return bool
	 */
	private static function is_ours( $post ) {
		return $post instanceof WP_Post && in_array(
			$post->post_type,
			array( Uaip_Timetable_Post_Types::EVENT, Uaip_Timetable_Post_Types::LOCATION, Uaip_Timetable_Post_Types::INSTRUCTOR ),
			true
		);
	}

	/**
	 * Marks the cached snapshot stale. The salt is bumped once, at shutdown.
	 */
	public static function invalidate() {
		self::$dirty    = true;
		self::$snapshot = null;
	}

	/**
	 * Bumps the cache salt if this request changed anything.
	 */
	public static function flush_dirty() {
		if ( self::$dirty ) {
			self::$dirty = false;
			update_option( self::SALT_OPTION, wp_generate_password( 8, false ), false );
		}
	}

	/**
	 * Expands one rule into dates. Pure function (no WordPress calls), so it is unit-tested
	 * directly.
	 *
	 * Weekly repeats count weeks from the Monday of the first date's week, the same as
	 * iCalendar's RRULE with WKST=MO, so "every 2 weeks" lines up with calendar apps.
	 *
	 * @param array<string,mixed> $rule           Rule from Uaip_Timetable_Event::rule().
	 * @param string              $from           First date `Y-m-d` (inclusive).
	 * @param string              $to             Last date `Y-m-d` (inclusive).
	 * @param bool                $ignore_exdates Include cancelled dates (for "typical week").
	 * @return string[] Dates `Y-m-d`, ascending.
	 */
	public static function expand( array $rule, $from, $to, $ignore_exdates = false ) {
		$first = isset( $rule['date'] ) ? (string) $rule['date'] : '';
		$start = isset( $rule['start'] ) ? (string) $rule['start'] : '';
		$end   = isset( $rule['end'] ) ? (string) $rule['end'] : '';
		if ( '' === $first || '' === $start || '' === $end || $end <= $start || $from > $to ) {
			return array();
		}
		$exdates = ( ! $ignore_exdates && ! empty( $rule['exdates'] ) ) ? array_flip( (array) $rule['exdates'] ) : array();

		if ( isset( $rule['repeat'] ) && 'none' === $rule['repeat'] ) {
			return ( $first >= $from && $first <= $to && ! isset( $exdates[ $first ] ) ) ? array( $first ) : array();
		}

		$last = $to;
		if ( ! empty( $rule['until'] ) && $rule['until'] < $last ) {
			$last = (string) $rule['until'];
		}
		$begin = $first > $from ? $first : $from;
		if ( $begin > $last ) {
			return array();
		}

		$first_day = self::day_number( $first );
		$begin_day = self::day_number( $begin );
		$last_day  = min( self::day_number( $last ), $begin_day + self::MAX_RANGE_DAYS );
		if ( null === $first_day || null === $begin_day || null === $last_day ) {
			return array();
		}

		$interval = max( 1, (int) ( isset( $rule['interval'] ) ? $rule['interval'] : 1 ) );
		$days     = ! empty( $rule['days'] ) ? array_map( 'intval', (array) $rule['days'] ) : array( self::weekday( $first_day ) );
		$days     = array_flip( $days );
		// Day number of the Monday that starts the first date's week.
		$anchor = $first_day - ( ( self::weekday( $first_day ) + 6 ) % 7 );

		$dates = array();
		for ( $day = $begin_day; $day <= $last_day; $day++ ) {
			if ( ! isset( $days[ self::weekday( $day ) ] ) ) {
				continue;
			}
			if ( 0 !== intdiv( $day - $anchor, 7 ) % $interval ) {
				continue;
			}
			$date = gmdate( 'Y-m-d', $day * DAY_IN_SECONDS );
			if ( ! isset( $exdates[ $date ] ) ) {
				$dates[] = $date;
			}
		}
		return $dates;
	}

	/**
	 * Days since 1970-01-01 for a `Y-m-d` date.
	 *
	 * @param string $date Date.
	 * @return int|null
	 */
	public static function day_number( $date ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		return (int) floor( gmmktime( 0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1] ) / DAY_IN_SECONDS );
	}

	/**
	 * Weekday of a day number, 0 (Sunday) to 6. 1970-01-01 was a Thursday.
	 *
	 * @param int $day Day number.
	 * @return int
	 */
	public static function weekday( $day ) {
		return ( ( $day % 7 ) + 11 ) % 7;
	}

	/**
	 * Adds days to a `Y-m-d` date.
	 *
	 * @param string $date Date.
	 * @param int    $days Days to add (may be negative).
	 * @return string
	 */
	public static function add_days( $date, $days ) {
		$day = self::day_number( $date );
		return null === $day ? $date : gmdate( 'Y-m-d', ( $day + (int) $days ) * DAY_IN_SECONDS );
	}

	/**
	 * Occurrences between two dates, with display data, sorted by date and start time.
	 *
	 * @param string $from    First date `Y-m-d` (inclusive).
	 * @param string $to      Last date `Y-m-d` (inclusive).
	 * @param array  $filters {
	 *     Optional filters.
	 *     @type string[] $category       Category slugs (children included).
	 *     @type int[]    $location       Location IDs.
	 *     @type int[]    $instructor     Instructor IDs.
	 *     @type bool     $ignore_exdates Include cancelled dates.
	 * }
	 * @return array<int,array<string,mixed>>
	 */
	public static function between( $from, $to, array $filters = array() ) {
		$snapshot = self::snapshot();
		$tz       = wp_timezone();

		$categories  = self::category_filter( $snapshot, isset( $filters['category'] ) ? (array) $filters['category'] : array() );
		$locations   = array_flip( array_map( 'intval', isset( $filters['location'] ) ? (array) $filters['location'] : array() ) );
		$instructors = array_flip( array_map( 'intval', isset( $filters['instructor'] ) ? (array) $filters['instructor'] : array() ) );
		$ignore_ex   = ! empty( $filters['ignore_exdates'] );

		$out = array();
		foreach ( $snapshot['events'] as $event ) {
			if ( null !== $categories && ! array_intersect( $event['categories'], $categories ) ) {
				continue;
			}
			if ( $locations && ! isset( $locations[ $event['location'] ] ) ) {
				continue;
			}
			if ( $instructors && ! array_intersect_key( array_flip( $event['instructors'] ), $instructors ) ) {
				continue;
			}
			foreach ( self::expand( $event['rule'], $from, $to, $ignore_ex ) as $date ) {
				$out[] = self::occurrence( $snapshot, $event, $date, $tz );
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return array( $a['date'], $a['start'], $a['end'], $a['title'] ) <=> array( $b['date'], $b['start'], $b['end'], $b['title'] );
			}
		);
		return $out;
	}

	/**
	 * Builds the public data of one occurrence.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param array<string,mixed> $event    Event from the snapshot.
	 * @param string              $date     Date `Y-m-d`.
	 * @param DateTimeZone        $tz       Site timezone.
	 * @return array<string,mixed>
	 */
	private static function occurrence( $snapshot, $event, $date, $tz ) {
		$start = new DateTimeImmutable( $date . ' ' . $event['rule']['start'], $tz );
		$end   = new DateTimeImmutable( $date . ' ' . $event['rule']['end'], $tz );

		$location = null;
		if ( $event['location'] && isset( $snapshot['locations'][ $event['location'] ] ) ) {
			$location = $snapshot['locations'][ $event['location'] ];
		}
		$instructors = array();
		foreach ( $event['instructors'] as $id ) {
			if ( isset( $snapshot['instructors'][ $id ] ) ) {
				$instructors[] = $snapshot['instructors'][ $id ];
			}
		}
		$categories = array();
		foreach ( $event['categories'] as $id ) {
			if ( isset( $snapshot['categories'][ $id ] ) ) {
				$categories[] = $snapshot['categories'][ $id ];
			}
		}
		return array(
			'id'          => $event['id'] . '-' . $date,
			'event_id'    => $event['id'],
			'date'        => $date,
			'start'       => $event['rule']['start'],
			'end'         => $event['rule']['end'],
			'start_iso'   => $start->format( DATE_ATOM ),
			'end_iso'     => $end->format( DATE_ATOM ),
			'title'       => $event['title'],
			'excerpt'     => $event['excerpt'],
			'url'         => $event['url'],
			'permalink'   => $event['permalink'],
			'color'       => $event['color'],
			'text_color'  => self::text_color( $event['color'] ),
			'repeats'     => 'weekly' === $event['rule']['repeat'],
			'location'    => $location,
			'instructors' => $instructors,
			'categories'  => $categories,
		);
	}

	/**
	 * Category IDs matching the given slugs, including child categories. Null = no filter.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param string[]            $slugs    Slugs.
	 * @return int[]|null
	 */
	private static function category_filter( $snapshot, $slugs ) {
		$slugs = array_filter( array_map( 'sanitize_title', $slugs ) );
		if ( ! $slugs ) {
			return null;
		}
		$ids = array();
		foreach ( $snapshot['categories'] as $id => $category ) {
			if ( in_array( $category['slug'], $slugs, true ) ) {
				$ids[] = $id;
			}
		}
		// Add descendants (parents are listed in the snapshot).
		$added = true;
		while ( $added ) {
			$added = false;
			foreach ( $snapshot['categories'] as $id => $category ) {
				if ( $category['parent'] && in_array( $category['parent'], $ids, true ) && ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
					$added = true;
				}
			}
		}
		return $ids;
	}

	/**
	 * All published events with their rules and display data, plus lookup tables for
	 * locations, instructors and categories. Cached in a transient keyed by the salt.
	 *
	 * @return array{events:array,locations:array,instructors:array,categories:array}
	 */
	public static function snapshot() {
		if ( null !== self::$snapshot ) {
			return self::$snapshot;
		}
		$key = 'uaip_tt_snapshot_' . get_option( self::SALT_OPTION, '0' );
		if ( ! self::$dirty ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) && isset( $cached['events'], $cached['schema'], $cached['version'] ) && self::SCHEMA === $cached['schema'] && UAIP_TT_VERSION === $cached['version'] ) {
				self::$snapshot = $cached;
				return $cached;
			}
		}
		$snapshot = self::build_snapshot();
		if ( ! self::$dirty ) {
			set_transient( $key, $snapshot, self::CACHE_TTL );
		}
		self::$snapshot = $snapshot;
		return $snapshot;
	}

	/**
	 * Loads the snapshot from the database.
	 *
	 * @return array<string,mixed>
	 */
	private static function build_snapshot() {
		$categories = array();
		$terms      = get_terms(
			array(
				'taxonomy'   => Uaip_Timetable_Post_Types::CATEGORY,
				'hide_empty' => false,
			)
		);
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[ $term->term_id ] = array(
					'id'     => $term->term_id,
					'slug'   => $term->slug,
					'name'   => $term->name,
					'parent' => (int) $term->parent,
					'color'  => Uaip_Timetable_Event::sanitize_color( get_term_meta( $term->term_id, Uaip_Timetable_Post_Types::TERM_COLOR, true ) ),
				);
			}
		}

		$locations = array();
		$public    = (bool) Uaip_Timetable_Settings::get( 'public_locations' );
		foreach ( self::published( Uaip_Timetable_Post_Types::LOCATION ) as $post ) {
			$locations[ $post->ID ] = array(
				'id'      => $post->ID,
				'title'   => get_the_title( $post ),
				'url'     => $public ? get_permalink( $post ) : '',
				'address' => (string) get_post_meta( $post->ID, Uaip_Timetable_Post_Types::META_ADDRESS, true ),
				'map_url' => (string) get_post_meta( $post->ID, Uaip_Timetable_Post_Types::META_MAP_URL, true ),
			);
		}

		$instructors = array();
		$public      = (bool) Uaip_Timetable_Settings::get( 'public_instructors' );
		foreach ( self::published( Uaip_Timetable_Post_Types::INSTRUCTOR ) as $post ) {
			$instructors[ $post->ID ] = array(
				'id'    => $post->ID,
				'title' => get_the_title( $post ),
				'url'   => $public ? get_permalink( $post ) : '',
			);
		}

		$events = array();
		foreach ( self::published( Uaip_Timetable_Post_Types::EVENT ) as $post ) {
			$rule = Uaip_Timetable_Event::rule( $post->ID );
			if ( '' !== Uaip_Timetable_Event::rule_problem( $rule ) ) {
				continue;
			}
			$terms   = get_the_terms( $post, Uaip_Timetable_Post_Types::CATEGORY );
			$cat_ids = is_array( $terms ) ? array_map( 'intval', wp_list_pluck( $terms, 'term_id' ) ) : array();

			$color = Uaip_Timetable_Event::sanitize_color( get_post_meta( $post->ID, Uaip_Timetable_Event::META_COLOR, true ) );
			foreach ( $cat_ids as $id ) {
				if ( '' === $color && isset( $categories[ $id ] ) && '' !== $categories[ $id ]['color'] ) {
					$color = $categories[ $id ]['color'];
				}
			}
			$instructor_ids = get_post_meta( $post->ID, Uaip_Timetable_Event::META_INSTRUCTORS, true );
			$events[]       = array(
				'id'          => $post->ID,
				'rule'        => $rule,
				'title'       => get_the_title( $post ),
				'excerpt'     => post_password_required( $post ) ? '' : wp_strip_all_tags( get_the_excerpt( $post ) ),
				'url'         => (string) get_post_meta( $post->ID, Uaip_Timetable_Event::META_URL, true ),
				'permalink'   => get_permalink( $post ),
				'modified'    => $post->post_modified_gmt,
				'color'       => '' !== $color ? $color : Uaip_Timetable_Post_Types::DEFAULT_COLOR,
				'location'    => (int) get_post_meta( $post->ID, Uaip_Timetable_Event::META_LOCATION, true ),
				'instructors' => is_array( $instructor_ids ) ? array_map( 'intval', $instructor_ids ) : array(),
				'categories'  => $cat_ids,
			);
		}

		return array(
			'version'     => UAIP_TT_VERSION,
			'schema'      => self::SCHEMA,
			'events'      => $events,
			'locations'   => $locations,
			'instructors' => $instructors,
			'categories'  => $categories,
		);
	}

	/**
	 * Published posts of a type, with meta and terms primed.
	 *
	 * @param string $post_type Post type.
	 * @return WP_Post[]
	 */
	private static function published( $post_type ) {
		return get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => 'publish',
				'posts_per_page'   => self::MAX_EVENTS,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
	}

	/**
	 * Black or white text, whichever has the higher WCAG contrast on the background.
	 *
	 * @param string $hex Background `#rrggbb`.
	 * @return string
	 */
	public static function text_color( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return '#ffffff';
		}
		$lum = 0.0;
		foreach ( array( 0 => 0.2126, 2 => 0.7152, 4 => 0.0722 ) as $offset => $weight ) {
			$c    = hexdec( substr( $hex, $offset, 2 ) ) / 255;
			$c    = $c <= 0.04045 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
			$lum += $weight * $c;
		}
		// Contrast with black is (L + 0.05) / 0.05, with white 1.05 / (L + 0.05); equal at L ≈ 0.179.
		return $lum > 0.179 ? '#1d2327' : '#ffffff';
	}
}
