<?php
/**
 * Server-side rendering of the timetable views (week, day, month, list).
 *
 * The HTML works without JavaScript: navigation and filters are plain links and a GET form
 * scoped to one timetable on the page. The front-end script upgrades them to in-place
 * updates through the REST `render` endpoint and adds the event details popover.
 *
 * @package UaipSimpleTimetable
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a timetable from normalised attributes.
 */
final class Uaip_Timetable_Renderer {

	/** Query parameter prefix for the no-JS navigation of one timetable. */
	const QUERY = 'uaip_tt';

	/** Weekday keys accepted in the `days` attribute. */
	const DAY_KEYS = array(
		'sun'       => 0,
		'sunday'    => 0,
		'mon'       => 1,
		'monday'    => 1,
		'tue'       => 2,
		'tuesday'   => 2,
		'wed'       => 3,
		'wednesday' => 3,
		'thu'       => 4,
		'thursday'  => 4,
		'fri'       => 5,
		'friday'    => 5,
		'sat'       => 6,
		'saturday'  => 6,
	);

	/** Most events shown in one month-view day before "+N more". */
	const MONTH_MAX_PER_DAY = 3;

	/**
	 * Number of timetables rendered in this request (for unique IDs and query scoping).
	 *
	 * @var int
	 */
	private static $count = 0;

	/**
	 * Number of event details blocks rendered (for unique IDs).
	 *
	 * @var int
	 */
	private static $details = 0;

	/**
	 * Normalises raw attributes (shortcode, block or REST) into a safe, complete set.
	 *
	 * @param array<string,mixed> $raw Raw attributes.
	 * @return array<string,mixed>
	 */
	public static function normalize( array $raw ) {
		$settings = Uaip_Timetable_Settings::all();
		$a        = array();

		$view      = isset( $raw['view'] ) ? strtolower( (string) $raw['view'] ) : '';
		$a['view'] = in_array( $view, Uaip_Timetable_Settings::VIEWS, true ) ? $view : $settings['default_view'];

		$views = array();
		foreach ( self::csv( isset( $raw['views'] ) ? $raw['views'] : '' ) as $v ) {
			if ( in_array( $v, Uaip_Timetable_Settings::VIEWS, true ) ) {
				$views[] = $v;
			}
		}
		if ( $views && ! in_array( $a['view'], $views, true ) ) {
			$a['view'] = $views[0];
		}
		$a['views'] = array_values( array_unique( $views ) );

		$date      = isset( $raw['date'] ) ? Uaip_Timetable_Event::sanitize_date( (string) $raw['date'] ) : '';
		$a['date'] = '' !== $date ? $date : wp_date( 'Y-m-d' );

		$a['category']   = array_values( array_filter( array_map( 'sanitize_title', self::csv( isset( $raw['category'] ) ? $raw['category'] : '' ) ) ) );
		$a['location']   = array_values( array_filter( array_map( 'absint', self::csv( isset( $raw['location'] ) ? $raw['location'] : '' ) ) ) );
		$a['instructor'] = array_values( array_filter( array_map( 'absint', self::csv( isset( $raw['instructor'] ) ? $raw['instructor'] : '' ) ) ) );

		$days = array();
		foreach ( self::csv( isset( $raw['days'] ) ? $raw['days'] : '' ) as $day ) {
			if ( isset( self::DAY_KEYS[ $day ] ) ) {
				$days[] = self::DAY_KEYS[ $day ];
			} elseif ( ctype_digit( $day ) && (int) $day <= 6 ) {
				$days[] = (int) $day;
			}
		}
		$a['days'] = array_values( array_unique( $days ) );

		$a['start'] = isset( $raw['start'] ) ? Uaip_Timetable_Event::sanitize_time( (string) $raw['start'] ) : '';
		$a['end']   = isset( $raw['end'] ) ? Uaip_Timetable_Event::sanitize_time( (string) $raw['end'] ) : '';
		if ( '' !== $a['start'] && '' !== $a['end'] && $a['end'] <= $a['start'] ) {
			$a['end'] = '';
		}

		$slot      = isset( $raw['slot'] ) ? (int) $raw['slot'] : 0;
		$a['slot'] = in_array( $slot, Uaip_Timetable_Settings::SLOTS, true ) ? $slot : $settings['slot'];

		$scheme      = isset( $raw['scheme'] ) ? (string) $raw['scheme'] : '';
		$a['scheme'] = in_array( $scheme, Uaip_Timetable_Settings::SCHEMES, true ) ? $scheme : $settings['scheme'];

		$list_days      = isset( $raw['list_days'] ) ? (int) $raw['list_days'] : 0;
		$a['list_days'] = $list_days >= 1 && $list_days <= 90 ? $list_days : $settings['list_days'];

		$a['typical'] = self::flag( isset( $raw['typical'] ) ? $raw['typical'] : false, false );
		$a['filters']   = self::flag( isset( $raw['filters'] ) ? $raw['filters'] : true, true );
		$a['subscribe'] = self::flag( isset( $raw['subscribe'] ) ? $raw['subscribe'] : true, true );

		// Dimensions fixed by the site owner (not changeable by the visitor's filters).
		$fixed = array();
		foreach ( self::csv( isset( $raw['fixed'] ) ? $raw['fixed'] : '' ) as $key ) {
			if ( in_array( $key, array( 'category', 'location', 'instructor' ), true ) ) {
				$fixed[] = $key;
			}
		}
		$a['fixed'] = array_values( array_unique( $fixed ) );

		// "Typical week" has no dates, so only the week and day views make sense.
		if ( $a['typical'] && ! in_array( $a['view'], array( 'week', 'day' ), true ) ) {
			$a['view'] = 'week';
		}
		return $a;
	}

	/**
	 * Attributes as given by the site owner: the filters they set become fixed.
	 *
	 * @param array<string,mixed> $raw Raw shortcode/block attributes.
	 * @return array<string,mixed>
	 */
	public static function from_owner( array $raw ) {
		$fixed = array();
		foreach ( array( 'category', 'location', 'instructor' ) as $key ) {
			if ( ! empty( $raw[ $key ] ) ) {
				$fixed[] = $key;
			}
		}
		$raw['fixed'] = implode( ',', $fixed );
		return self::normalize( $raw );
	}

	/**
	 * Applies the visitor's no-JS navigation (query string) to the timetable it targets.
	 *
	 * @param array<string,mixed> $attrs    Normalised attributes.
	 * @param int                 $instance Instance number on the page.
	 * @return array<string,mixed>
	 */
	private static function apply_query( array $attrs, $instance ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view navigation.
		if ( ! isset( $_GET[ self::QUERY ] ) || (int) $_GET[ self::QUERY ] !== $instance ) {
			return $attrs;
		}
		$raw = $attrs;
		foreach ( array( 'view', 'date', 'category', 'location', 'instructor' ) as $key ) {
			$param = self::QUERY . '_' . $key;
			if ( isset( $_GET[ $param ] ) && is_string( $_GET[ $param ] ) ) {
				if ( in_array( $key, $attrs['fixed'], true ) ) {
					continue;
				}
				$raw[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $param ] ) );
			}
		}
		// phpcs:enable
		return self::normalize( self::to_raw( $raw ) );
	}

	/**
	 * Converts normalised attributes back to the raw (string) form, e.g. for the REST API.
	 *
	 * @param array<string,mixed> $attrs Normalised attributes.
	 * @return array<string,string>
	 */
	public static function to_raw( array $attrs ) {
		$out = array();
		foreach ( $attrs as $key => $value ) {
			if ( is_bool( $value ) ) {
				$out[ $key ] = $value ? '1' : '0';
			} elseif ( is_array( $value ) ) {
				$out[ $key ] = implode( ',', $value );
			} else {
				$out[ $key ] = (string) $value;
			}
		}
		return $out;
	}

	/**
	 * Renders a timetable placed by a shortcode or block (counts instances, reads the query).
	 *
	 * @param array<string,mixed> $attrs Normalised attributes.
	 * @return string HTML.
	 */
	public static function render_instance( array $attrs ) {
		++self::$count;
		$attrs = self::apply_query( $attrs, self::$count );
		Uaip_Timetable_Assets::enqueue_front();
		$base = remove_query_arg( self::query_keys() );
		return self::render( $attrs, self::$count, is_string( $base ) ? $base : '' );
	}

	/**
	 * Query keys used by the no-JS navigation.
	 *
	 * @return string[]
	 */
	public static function query_keys() {
		$keys = array( self::QUERY );
		foreach ( array( 'view', 'date', 'category', 'location', 'instructor' ) as $key ) {
			$keys[] = self::QUERY . '_' . $key;
		}
		return $keys;
	}

	/**
	 * Renders the whole timetable element.
	 *
	 * @param array<string,mixed> $attrs    Normalised attributes.
	 * @param int                 $instance Instance number on the page.
	 * @param string              $base     Page URL (without our query keys) for no-JS links.
	 * @return string HTML.
	 */
	public static function render( array $attrs, $instance, $base ) {
		$instance = max( 1, (int) $instance );
		list( $from, $to ) = self::range( $attrs );

		$filters = array(
			'category'       => $attrs['category'],
			'location'       => $attrs['location'],
			'instructor'     => $attrs['instructor'],
			'ignore_exdates' => $attrs['typical'],
		);
		$items   = Uaip_Timetable_Occurrences::between( $from, $to, $filters );

		$state = array(
			'attrs'    => self::to_raw( $attrs ),
			'instance' => $instance,
		);

		$classes = array(
			'uaip-tt',
			'uaip-tt--view-' . $attrs['view'],
			'uaip-tt--scheme-' . $attrs['scheme'],
		);
		if ( $attrs['typical'] ) {
			$classes[] = 'uaip-tt--typical';
		}

		ob_start();
		?>
		<div id="<?php echo esc_attr( 'uaip-tt-' . $instance ); ?>" class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			data-uaip-tt="<?php echo esc_attr( (string) wp_json_encode( $state ) ); ?>"
			data-rest="<?php echo esc_url( rest_url( Uaip_Timetable_Rest::NAMESPACE . '/render' ) ); ?>"
			data-close-label="<?php esc_attr_e( 'Close', 'uaip-simple-timetable' ); ?>">
			<?php self::toolbar( $attrs, $instance, $base, $from, $to ); ?>
			<div class="uaip-tt__body">
				<?php
				switch ( $attrs['view'] ) {
					case 'month':
						self::month_view( $attrs, $items, $from, $to, $instance, $base );
						break;
					case 'list':
						self::list_view( $items, $from, $to );
						break;
					default:
						self::grid_view( $attrs, $items, $from, $to );
				}
				?>
			</div>
			<?php self::footer( $attrs ); ?>
			<div class="uaip-tt__status screen-reader-text" role="status" aria-live="polite"></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * "Subscribe in your calendar app" link for the timetable's filters.
	 *
	 * @param array<string,mixed> $attrs Normalised attributes.
	 */
	private static function footer( array $attrs ) {
		if ( ! $attrs['subscribe'] || ! Uaip_Timetable_Settings::get( 'ics' ) ) {
			return;
		}
		$feed = Uaip_Timetable_Ics::feed_url(
			array(
				'category'   => $attrs['category'],
				'location'   => $attrs['location'],
				'instructor' => $attrs['instructor'],
			)
		);
		$webcal = preg_replace( '#^https?://#', 'webcal://', $feed );
		?>
		<div class="uaip-tt__footer">
			<a class="uaip-tt__subscribe" href="<?php echo esc_url( (string) $webcal, array( 'webcal' ) ); ?>">
				<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M19 4h-2V2h-2v2H9V2H7v2H5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2Zm0 15H5V9h14v10Zm-8-8h2v3h3v2h-3v3h-2v-3H8v-2h3v-3Z" fill="currentColor"/></svg>
				<?php esc_html_e( 'Subscribe in your calendar app', 'uaip-simple-timetable' ); ?>
			</a>
			<a class="uaip-tt__ics" href="<?php echo esc_url( $feed ); ?>" download><?php esc_html_e( 'Download .ics', 'uaip-simple-timetable' ); ?></a>
		</div>
		<?php
	}

	/**
	 * The next few occurrences as a compact list (Upcoming Classes block and widget).
	 *
	 * @param array<string,mixed> $args {
	 *     @type int      $count           How many (1–20).
	 *     @type int      $days            How far ahead to look (1–90).
	 *     @type string[] $category        Category slugs.
	 *     @type int[]    $location        Location IDs.
	 *     @type int[]    $instructor      Instructor IDs.
	 *     @type bool     $show_location   Show the location.
	 *     @type bool     $show_instructor Show the instructors.
	 * }
	 * @return string HTML.
	 */
	public static function upcoming( array $args ) {
		$count = min( 20, max( 1, isset( $args['count'] ) ? (int) $args['count'] : 5 ) );
		$days  = min( 90, max( 1, isset( $args['days'] ) ? (int) $args['days'] : 14 ) );
		$attrs = self::normalize(
			array(
				'category'   => isset( $args['category'] ) ? $args['category'] : '',
				'location'   => isset( $args['location'] ) ? $args['location'] : '',
				'instructor' => isset( $args['instructor'] ) ? $args['instructor'] : '',
			)
		);

		$now   = new DateTimeImmutable( 'now', wp_timezone() );
		$today = $now->format( 'Y-m-d' );
		$items = Uaip_Timetable_Occurrences::between(
			$today,
			Uaip_Timetable_Occurrences::add_days( $today, $days - 1 ),
			array(
				'category'   => $attrs['category'],
				'location'   => $attrs['location'],
				'instructor' => $attrs['instructor'],
			)
		);
		$upcoming = array();
		foreach ( $items as $item ) {
			// Skip what has already finished today.
			if ( new DateTimeImmutable( $item['end_iso'] ) > $now ) {
				$upcoming[] = $item;
			}
			if ( count( $upcoming ) >= $count ) {
				break;
			}
		}

		if ( ! $upcoming ) {
			return '<p class="uaip-tt-upcoming__empty">' . esc_html__( 'Nothing coming up.', 'uaip-simple-timetable' ) . '</p>';
		}
		$tomorrow = Uaip_Timetable_Occurrences::add_days( $today, 1 );

		ob_start();
		?>
		<ul class="uaip-tt-upcoming">
			<?php foreach ( $upcoming as $item ) : ?>
				<?php
				if ( $item['date'] === $today ) {
					$day = __( 'Today', 'uaip-simple-timetable' );
				} elseif ( $item['date'] === $tomorrow ) {
					$day = __( 'Tomorrow', 'uaip-simple-timetable' );
				} else {
					$day = self::format_date( $item['date'], _x( 'D j M', 'timetable short date format', 'uaip-simple-timetable' ) );
				}
				$meta = array();
				if ( ! empty( $args['show_location'] ) && $item['location'] ) {
					$meta[] = $item['location']['title'];
				}
				if ( ! empty( $args['show_instructor'] ) && $item['instructors'] ) {
					$meta[] = wp_sprintf( '%l', wp_list_pluck( $item['instructors'], 'title' ) );
				}
				?>
				<li class="uaip-tt-upcoming__item" style="<?php echo esc_attr( '--uaip-tt-bg:' . $item['color'] ); ?>">
					<a class="uaip-tt-upcoming__title" href="<?php echo esc_url( '' !== $item['url'] ? $item['url'] : $item['permalink'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
					<time class="uaip-tt-upcoming__when" datetime="<?php echo esc_attr( $item['start_iso'] ); ?>">
						<?php
						/* translators: 1: day (e.g. "Today" or "Tue 13 Oct"), 2: time range */
						echo esc_html( sprintf( __( '%1$s, %2$s', 'uaip-simple-timetable' ), $day, Uaip_Timetable_Event::time_range( $item['start'], $item['end'] ) ) );
						?>
					</time>
					<?php if ( $meta ) : ?>
						<span class="uaip-tt-upcoming__meta"><?php echo esc_html( implode( ' · ', $meta ) ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * First and last date shown by the view.
	 *
	 * @param array<string,mixed> $attrs Normalised attributes.
	 * @return string[] [from, to] as `Y-m-d`.
	 */
	public static function range( array $attrs ) {
		$date = $attrs['date'];
		$day  = Uaip_Timetable_Occurrences::day_number( $date );
		switch ( $attrs['view'] ) {
			case 'day':
				return array( $date, $date );
			case 'list':
				return array( $date, Uaip_Timetable_Occurrences::add_days( $date, $attrs['list_days'] - 1 ) );
			case 'month':
				$first = substr( $date, 0, 8 ) . '01';
				$last  = gmdate( 'Y-m-t', (int) strtotime( $first . ' 12:00 UTC' ) );
				// Pad to whole weeks.
				$lead  = ( Uaip_Timetable_Occurrences::weekday( (int) Uaip_Timetable_Occurrences::day_number( $first ) ) - Uaip_Timetable_Settings::week_start() + 7 ) % 7;
				$trail = ( Uaip_Timetable_Settings::week_start() + 6 - Uaip_Timetable_Occurrences::weekday( (int) Uaip_Timetable_Occurrences::day_number( $last ) ) + 7 ) % 7;
				return array( Uaip_Timetable_Occurrences::add_days( $first, -$lead ), Uaip_Timetable_Occurrences::add_days( $last, $trail ) );
			default:
				$offset = ( Uaip_Timetable_Occurrences::weekday( (int) $day ) - Uaip_Timetable_Settings::week_start() + 7 ) % 7;
				$start  = Uaip_Timetable_Occurrences::add_days( $date, -$offset );
				return array( $start, Uaip_Timetable_Occurrences::add_days( $start, 6 ) );
		}
	}

	/**
	 * The date the "previous" or "next" button goes to.
	 *
	 * @param array<string,mixed> $attrs     Normalised attributes.
	 * @param int                 $direction -1 or 1.
	 * @return string `Y-m-d`.
	 */
	private static function step( array $attrs, $direction ) {
		$date = $attrs['date'];
		switch ( $attrs['view'] ) {
			case 'day':
				return Uaip_Timetable_Occurrences::add_days( $date, $direction );
			case 'list':
				return Uaip_Timetable_Occurrences::add_days( $date, $direction * $attrs['list_days'] );
			case 'month':
				$month = new DateTimeImmutable( substr( $date, 0, 8 ) . '01 12:00', new DateTimeZone( 'UTC' ) );
				return $month->modify( ( $direction > 0 ? '+1' : '-1' ) . ' month' )->format( 'Y-m-d' );
			default:
				return Uaip_Timetable_Occurrences::add_days( $date, 7 * $direction );
		}
	}

	/**
	 * Heading, navigation, view switcher and filters.
	 *
	 * @param array<string,mixed> $attrs    Normalised attributes.
	 * @param int                 $instance Instance number.
	 * @param string              $base     Page URL.
	 * @param string              $from     First date shown.
	 * @param string              $to       Last date shown.
	 */
	private static function toolbar( array $attrs, $instance, $base, $from, $to ) {
		$today = wp_date( 'Y-m-d' );
		?>
		<div class="uaip-tt__toolbar">
			<?php if ( ! $attrs['typical'] ) : ?>
				<div class="uaip-tt__nav">
					<?php
					self::nav_link( $attrs, $instance, $base, array( 'date' => self::step( $attrs, -1 ) ), 'prev', self::nav_label( $attrs['view'], -1 ) );
					self::nav_link( $attrs, $instance, $base, array( 'date' => $today ), 'today', __( 'Today', 'uaip-simple-timetable' ), $today >= $from && $today <= $to );
					self::nav_link( $attrs, $instance, $base, array( 'date' => self::step( $attrs, 1 ) ), 'next', self::nav_label( $attrs['view'], 1 ) );
					?>
					<h2 class="uaip-tt__heading" tabindex="-1"><?php echo esc_html( self::heading( $attrs, $from, $to ) ); ?></h2>
				</div>
			<?php endif; ?>

			<?php if ( count( $attrs['views'] ) > 1 ) : ?>
				<nav class="uaip-tt__views" aria-label="<?php esc_attr_e( 'Timetable view', 'uaip-simple-timetable' ); ?>">
					<?php foreach ( $attrs['views'] as $view ) : ?>
						<a class="uaip-tt__view" href="<?php echo esc_url( self::nav_url( $attrs, $instance, $base, array( 'view' => $view ) ) ); ?>"
							data-nav="<?php echo esc_attr( (string) wp_json_encode( array( 'view' => $view ) ) ); ?>"
							<?php echo $view === $attrs['view'] ? 'aria-current="page"' : ''; ?>><?php echo esc_html( self::view_label( $view ) ); ?></a>
					<?php endforeach; ?>
				</nav>
			<?php endif; ?>

			<?php self::filters( $attrs, $instance, $base ); ?>
		</div>
		<?php
	}

	/**
	 * One navigation link.
	 *
	 * @param array<string,mixed> $attrs    Normalised attributes.
	 * @param int                 $instance Instance number.
	 * @param string              $base     Page URL.
	 * @param array<string,mixed> $change   Attributes to change.
	 * @param string              $kind     prev|today|next.
	 * @param string              $label    Accessible label.
	 * @param bool                $current  Whether the link points at what is shown.
	 */
	private static function nav_link( array $attrs, $instance, $base, array $change, $kind, $label, $current = false ) {
		$text = 'today' === $kind ? $label : '';
		$icon = array(
			'prev' => '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M14.6 6 9 12l5.6 6 1.4-1.4L11.8 12 16 7.4z" fill="currentColor"/></svg>',
			'next' => '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M9.4 18 15 12 9.4 6 8 7.4l4.2 4.6L8 16.6z" fill="currentColor"/></svg>',
		);
		?>
		<a class="<?php echo esc_attr( 'uaip-tt__btn uaip-tt__btn--' . $kind ); ?>"
			href="<?php echo esc_url( self::nav_url( $attrs, $instance, $base, $change ) ); ?>"
			data-nav="<?php echo esc_attr( (string) wp_json_encode( $change ) ); ?>"
			<?php echo $current ? 'aria-current="date"' : ''; ?>
			<?php echo '' === $text ? 'aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '"' : ''; ?>>
			<?php
			if ( isset( $icon[ $kind ] ) ) {
				echo $icon[ $kind ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
			}
			echo esc_html( $text );
			?>
		</a>
		<?php
	}

	/**
	 * No-JS URL that shows this timetable with some attributes changed.
	 *
	 * @param array<string,mixed> $attrs    Normalised attributes.
	 * @param int                 $instance Instance number.
	 * @param string              $base     Page URL.
	 * @param array<string,mixed> $change   Attributes to change.
	 * @return string
	 */
	private static function nav_url( array $attrs, $instance, $base, array $change ) {
		$merged = array_merge( $attrs, $change );
		$args   = array(
			self::QUERY           => $instance,
			self::QUERY . '_view' => $merged['view'],
			self::QUERY . '_date' => $merged['date'],
		);
		foreach ( array( 'category', 'location', 'instructor' ) as $key ) {
			if ( ! in_array( $key, $attrs['fixed'], true ) && ! empty( $merged[ $key ] ) ) {
				$args[ self::QUERY . '_' . $key ] = implode( ',', (array) $merged[ $key ] );
			}
		}
		return add_query_arg( $args, '' !== $base ? $base : home_url( '/' ) ) . '#uaip-tt-' . $instance;
	}

	/**
	 * Filter dropdowns (a GET form, upgraded to live filtering by the script).
	 *
	 * @param array<string,mixed> $attrs    Normalised attributes.
	 * @param int                 $instance Instance number.
	 * @param string              $base     Page URL.
	 */
	private static function filters( array $attrs, $instance, $base ) {
		if ( ! $attrs['filters'] ) {
			return;
		}
		$choices = self::filter_choices( $attrs );
		if ( ! $choices ) {
			return;
		}
		$labels = array(
			'category'   => array( __( 'Category', 'uaip-simple-timetable' ), __( 'All categories', 'uaip-simple-timetable' ) ),
			'location'   => array( __( 'Location', 'uaip-simple-timetable' ), __( 'All locations', 'uaip-simple-timetable' ) ),
			'instructor' => array( __( 'Instructor', 'uaip-simple-timetable' ), __( 'All instructors', 'uaip-simple-timetable' ) ),
		);
		$action = '' !== $base ? $base : home_url( '/' );
		$query  = (string) wp_parse_url( $action, PHP_URL_QUERY );
		parse_str( $query, $keep );
		?>
		<form class="uaip-tt__filters" method="get" action="<?php echo esc_url( strtok( $action, '?' ) . '#uaip-tt-' . $instance ); ?>">
			<?php foreach ( $keep as $name => $value ) : ?>
				<?php if ( is_string( $value ) ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
				<?php endif; ?>
			<?php endforeach; ?>
			<input type="hidden" name="<?php echo esc_attr( self::QUERY ); ?>" value="<?php echo esc_attr( (string) $instance ); ?>">
			<input type="hidden" name="<?php echo esc_attr( self::QUERY . '_view' ); ?>" value="<?php echo esc_attr( $attrs['view'] ); ?>">
			<input type="hidden" name="<?php echo esc_attr( self::QUERY . '_date' ); ?>" value="<?php echo esc_attr( $attrs['date'] ); ?>">
			<?php foreach ( $choices as $key => $options ) : ?>
				<?php
				$id       = 'uaip-tt-' . $instance . '-' . $key;
				$selected = (string) ( $attrs[ $key ] ? $attrs[ $key ][0] : '' );
				?>
				<span class="uaip-tt__filter">
					<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $labels[ $key ][0] ); ?></label>
					<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( self::QUERY . '_' . $key ); ?>" data-filter="<?php echo esc_attr( $key ); ?>">
						<option value=""><?php echo esc_html( $labels[ $key ][1] ); ?></option>
						<?php foreach ( $options as $value => $label ) : ?>
							<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $selected, (string) $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</span>
			<?php endforeach; ?>
			<button type="submit" class="uaip-tt__btn uaip-tt__apply"><?php esc_html_e( 'Filter', 'uaip-simple-timetable' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Options for each filter that the visitor may change: only values used by events, and
	 * only when there is more than one.
	 *
	 * @param array<string,mixed> $attrs Normalised attributes.
	 * @return array<string,array<string,string>>
	 */
	private static function filter_choices( array $attrs ) {
		$snapshot = Uaip_Timetable_Occurrences::snapshot();
		$used     = array(
			'category'   => array(),
			'location'   => array(),
			'instructor' => array(),
		);
		foreach ( $snapshot['events'] as $event ) {
			foreach ( $event['categories'] as $id ) {
				if ( isset( $snapshot['categories'][ $id ] ) ) {
					$used['category'][ $snapshot['categories'][ $id ]['slug'] ] = $snapshot['categories'][ $id ]['name'];
				}
			}
			if ( $event['location'] && isset( $snapshot['locations'][ $event['location'] ] ) ) {
				$used['location'][ $event['location'] ] = $snapshot['locations'][ $event['location'] ]['title'];
			}
			foreach ( $event['instructors'] as $id ) {
				if ( isset( $snapshot['instructors'][ $id ] ) ) {
					$used['instructor'][ $id ] = $snapshot['instructors'][ $id ]['title'];
				}
			}
		}
		$out = array();
		foreach ( $used as $key => $options ) {
			if ( in_array( $key, $attrs['fixed'], true ) || count( $options ) < 2 ) {
				continue;
			}
			natcasesort( $options );
			$out[ $key ] = $options;
		}
		return $out;
	}

	/**
	 * Week or day grid: one column per day, events placed by minutes, overlapping events
	 * side by side. Narrow containers turn it into a list per day (CSS container query).
	 *
	 * @param array<string,mixed> $attrs Normalised attributes.
	 * @param array               $items Occurrences.
	 * @param string              $from  First date.
	 * @param string              $to    Last date.
	 */
	private static function grid_view( array $attrs, array $items, $from, $to ) {
		$dates = array();
		for ( $d = $from; $d <= $to; $d = Uaip_Timetable_Occurrences::add_days( $d, 1 ) ) {
			$weekday = Uaip_Timetable_Occurrences::weekday( (int) Uaip_Timetable_Occurrences::day_number( $d ) );
			if ( 'week' === $attrs['view'] && $attrs['days'] && ! in_array( $weekday, $attrs['days'], true ) ) {
				continue;
			}
			$dates[ $d ] = $weekday;
		}
		$by_date = array_fill_keys( array_keys( $dates ), array() );
		foreach ( $items as $item ) {
			if ( isset( $by_date[ $item['date'] ] ) ) {
				$by_date[ $item['date'] ][] = $item;
			}
		}
		list( $start_min, $end_min ) = self::time_window( $attrs, $by_date );
		$total                       = $end_min - $start_min;
		$today                       = wp_date( 'Y-m-d' );
		$typical                     = $attrs['typical'];

		$style = sprintf( '--uaip-tt-minutes:%d;--uaip-tt-slot:%d;--uaip-tt-days:%d', $total, $attrs['slot'], max( 1, count( $dates ) ) );
		?>
		<div class="uaip-tt-grid" style="<?php echo esc_attr( $style ); ?>">
			<div class="uaip-tt-grid__head" aria-hidden="true">
				<span class="uaip-tt-grid__corner"></span>
				<?php foreach ( $dates as $date => $weekday ) : ?>
					<span class="<?php echo esc_attr( 'uaip-tt-grid__dayname' . ( ! $typical && $date === $today ? ' is-today' : '' ) ); ?>">
						<?php self::day_heading( $date, $weekday, $typical ); ?>
					</span>
				<?php endforeach; ?>
			</div>
			<div class="uaip-tt-grid__body">
				<div class="uaip-tt-grid__times" aria-hidden="true">
					<?php for ( $m = (int) ( ceil( $start_min / 60 ) * 60 ); $m < $end_min; $m += 60 ) : ?>
						<span style="<?php echo esc_attr( '--uaip-tt-at:' . ( $m - $start_min ) ); ?>"><?php echo esc_html( Uaip_Timetable_Event::format_time( sprintf( '%02d:00', $m / 60 ) ) ); ?></span>
					<?php endfor; ?>
				</div>
				<?php foreach ( $dates as $date => $weekday ) : ?>
					<?php $day_items = self::lanes( $by_date[ $date ] ); ?>
					<section class="<?php echo esc_attr( 'uaip-tt-grid__day' . ( ! $typical && $date === $today ? ' is-today' : '' ) ); ?>">
						<h3 class="uaip-tt-grid__dayhead"><?php self::day_heading( $date, $weekday, $typical ); ?></h3>
						<?php if ( $day_items ) : ?>
							<ul class="uaip-tt-grid__events">
								<?php
								foreach ( $day_items as $item ) {
									$top   = max( 0, self::minutes( $item['start'] ) - $start_min );
									$len   = min( $total - $top, self::minutes( $item['end'] ) - self::minutes( $item['start'] ) );
									$place = sprintf( '--uaip-tt-top:%d;--uaip-tt-len:%d;--uaip-tt-lane:%d;--uaip-tt-lanes:%d', $top, max( 1, $len ), $item['lane'], $item['lanes'] );
									self::event( $item, $place, ! $typical );
								}
								?>
							</ul>
						<?php else : ?>
							<p class="uaip-tt-grid__empty"><?php esc_html_e( 'No events', 'uaip-simple-timetable' ); ?></p>
						<?php endif; ?>
					</section>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Day name (and date) for a column heading.
	 *
	 * @param string $date    `Y-m-d`.
	 * @param int    $weekday 0–6.
	 * @param bool   $typical Hide the date.
	 */
	private static function day_heading( $date, $weekday, $typical ) {
		global $wp_locale;
		$name  = Uaip_Timetable_Event::weekday_name( $weekday );
		$short = $wp_locale ? $wp_locale->get_weekday_abbrev( $name ) : substr( $name, 0, 3 );
		?>
		<span class="uaip-tt-day__name"><span class="uaip-tt-day__long"><?php echo esc_html( $name ); ?></span><span class="uaip-tt-day__short" aria-hidden="true"><?php echo esc_html( $short ); ?></span></span>
		<?php if ( ! $typical ) : ?>
			<time class="uaip-tt-day__date" datetime="<?php echo esc_attr( $date ); ?>"><?php echo esc_html( self::format_date( $date, _x( 'j M', 'timetable column date format', 'uaip-simple-timetable' ) ) ); ?></time>
		<?php endif; ?>
		<?php
	}

	/**
	 * Visible time window in minutes: the attributes, or fitted to the events (whole hours),
	 * or 08:00–18:00 when there are none.
	 *
	 * @param array<string,mixed> $attrs   Normalised attributes.
	 * @param array               $by_date Occurrences per date.
	 * @return int[] [start, end] minutes after midnight.
	 */
	private static function time_window( array $attrs, array $by_date ) {
		$min = null;
		$max = null;
		foreach ( $by_date as $items ) {
			foreach ( $items as $item ) {
				$min = null === $min ? self::minutes( $item['start'] ) : min( $min, self::minutes( $item['start'] ) );
				$max = null === $max ? self::minutes( $item['end'] ) : max( $max, self::minutes( $item['end'] ) );
			}
		}
		$start = '' !== $attrs['start'] ? self::minutes( $attrs['start'] ) : ( null === $min ? 8 * 60 : (int) floor( $min / 60 ) * 60 );
		$end   = '' !== $attrs['end'] ? self::minutes( $attrs['end'] ) : ( null === $max ? 18 * 60 : (int) min( 24 * 60, ceil( $max / 60 ) * 60 ) );
		if ( $end - $start < 60 ) {
			$end = min( 24 * 60, $start + 60 );
		}
		return array( $start, $end );
	}

	/**
	 * Assigns side-by-side lanes to overlapping events of one day.
	 *
	 * @param array $items Occurrences of one day, sorted by start.
	 * @return array Occurrences with `lane` and `lanes`.
	 */
	public static function lanes( array $items ) {
		$out       = array();
		$cluster   = array();
		$lane_ends = array();
		$max_end   = -1;

		$close = static function () use ( &$out, &$cluster, &$lane_ends ) {
			$count = count( $lane_ends );
			foreach ( $cluster as $item ) {
				$item['lanes'] = $count;
				$out[]         = $item;
			}
			$cluster   = array();
			$lane_ends = array();
		};

		foreach ( $items as $item ) {
			$start = self::minutes( $item['start'] );
			$end   = self::minutes( $item['end'] );
			if ( $cluster && $start >= $max_end ) {
				$close();
				$max_end = -1;
			}
			$lane = null;
			foreach ( $lane_ends as $i => $lane_end ) {
				if ( $lane_end <= $start ) {
					$lane = $i;
					break;
				}
			}
			if ( null === $lane ) {
				$lane = count( $lane_ends );
			}
			$lane_ends[ $lane ] = $end;
			$item['lane']       = $lane;
			$cluster[]          = $item;
			$max_end            = max( $max_end, $end );
		}
		if ( $cluster ) {
			$close();
		}
		return $out;
	}

	/**
	 * Month grid. Narrow containers show only the days with events, as a list.
	 *
	 * @param array<string,mixed> $attrs    Normalised attributes.
	 * @param array               $items    Occurrences.
	 * @param string              $from     First date (start of the first week).
	 * @param string              $to       Last date (end of the last week).
	 * @param int                 $instance Instance number.
	 * @param string              $base     Page URL.
	 */
	private static function month_view( array $attrs, array $items, $from, $to, $instance, $base ) {
		$by_date = array();
		foreach ( $items as $item ) {
			$by_date[ $item['date'] ][] = $item;
		}
		$month = substr( $attrs['date'], 0, 7 );
		$today = wp_date( 'Y-m-d' );
		$start = Uaip_Timetable_Settings::week_start();
		global $wp_locale;
		?>
		<table class="uaip-tt-month">
			<thead>
				<tr>
					<?php for ( $i = 0; $i < 7; $i++ ) : ?>
						<?php $name = Uaip_Timetable_Event::weekday_name( ( $start + $i ) % 7 ); ?>
						<th scope="col" abbr="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $wp_locale ? $wp_locale->get_weekday_abbrev( $name ) : $name ); ?></th>
					<?php endfor; ?>
				</tr>
			</thead>
			<tbody>
				<?php for ( $week = $from; $week <= $to; $week = Uaip_Timetable_Occurrences::add_days( $week, 7 ) ) : ?>
					<tr>
						<?php
						for ( $i = 0; $i < 7; $i++ ) :
							$date    = Uaip_Timetable_Occurrences::add_days( $week, $i );
							$day     = isset( $by_date[ $date ] ) ? $by_date[ $date ] : array();
							$classes = array( 'uaip-tt-month__day' );
							if ( substr( $date, 0, 7 ) !== $month ) {
								$classes[] = 'is-outside';
							}
							if ( $date === $today ) {
								$classes[] = 'is-today';
							}
							if ( ! $day ) {
								$classes[] = 'is-empty';
							}
							?>
							<td class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
								<a class="uaip-tt-month__date" href="<?php echo esc_url( self::nav_url( $attrs, $instance, $base, array( 'view' => 'day', 'date' => $date ) ) ); ?>"
									data-nav="<?php echo esc_attr( (string) wp_json_encode( array( 'view' => 'day', 'date' => $date ) ) ); ?>"
									<?php echo $date === $today ? 'aria-current="date"' : ''; ?>>
									<span class="uaip-tt-month__num"><?php echo esc_html( self::format_date( $date, 'j' ) ); ?></span>
									<span class="uaip-tt-month__full"><?php echo esc_html( self::format_date( $date, _x( 'l j F', 'timetable list date format', 'uaip-simple-timetable' ) ) ); ?></span>
								</a>
								<?php if ( $day ) : ?>
									<ul class="uaip-tt-month__events">
										<?php
										foreach ( array_slice( $day, 0, self::MONTH_MAX_PER_DAY ) as $item ) {
											self::event( $item, '', true );
										}
										?>
									</ul>
									<?php if ( count( $day ) > self::MONTH_MAX_PER_DAY ) : ?>
										<?php $more = count( $day ) - self::MONTH_MAX_PER_DAY; ?>
										<a class="uaip-tt-month__more" href="<?php echo esc_url( self::nav_url( $attrs, $instance, $base, array( 'view' => 'day', 'date' => $date ) ) ); ?>"
											data-nav="<?php echo esc_attr( (string) wp_json_encode( array( 'view' => 'day', 'date' => $date ) ) ); ?>">
											<?php
											/* translators: %d: number of further events on that day */
											echo esc_html( sprintf( _n( '+%d more', '+%d more', $more, 'uaip-simple-timetable' ), $more ) );
											?>
										</a>
									<?php endif; ?>
								<?php endif; ?>
							</td>
						<?php endfor; ?>
					</tr>
				<?php endfor; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * List (agenda): days with events, in order.
	 *
	 * @param array  $items Occurrences.
	 * @param string $from  First date.
	 * @param string $to    Last date.
	 */
	private static function list_view( array $items, $from, $to ) {
		$by_date = array();
		foreach ( $items as $item ) {
			$by_date[ $item['date'] ][] = $item;
		}
		$today = wp_date( 'Y-m-d' );
		if ( ! $by_date ) {
			echo '<p class="uaip-tt-list__empty">' . esc_html__( 'No events in this period.', 'uaip-simple-timetable' ) . '</p>';
			return;
		}
		?>
		<div class="uaip-tt-list">
			<?php foreach ( $by_date as $date => $day ) : ?>
				<section class="<?php echo esc_attr( 'uaip-tt-list__day' . ( $date === $today ? ' is-today' : '' ) ); ?>">
					<h3 class="uaip-tt-list__date"><time datetime="<?php echo esc_attr( $date ); ?>"><?php echo esc_html( self::format_date( $date, _x( 'l j F', 'timetable list date format', 'uaip-simple-timetable' ) ) ); ?></time></h3>
					<ul class="uaip-tt-list__events">
						<?php
						foreach ( $day as $item ) {
							self::event( $item, '', true );
						}
						?>
					</ul>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * One event: a link to the event (works without JS) plus hidden details for the popover.
	 *
	 * @param array<string,mixed> $item      Occurrence.
	 * @param string              $placement Extra inline custom properties (grid placement).
	 * @param bool                $dated     Whether to show the date in the details.
	 */
	private static function event( array $item, $placement, $dated ) {
		$style   = sprintf( '--uaip-tt-bg:%s;--uaip-tt-fg:%s;', $item['color'], $item['text_color'] ) . $placement;
		$href    = '' !== $item['url'] ? $item['url'] : $item['permalink'];
		$details = 'uaip-tt-d-' . ( ++self::$details );
		$meta    = array();
		if ( $item['location'] ) {
			$meta[] = $item['location']['title'];
		}
		if ( $item['instructors'] ) {
			$meta[] = wp_sprintf( '%l', wp_list_pluck( $item['instructors'], 'title' ) );
		}
		?>
		<li class="uaip-tt-event" style="<?php echo esc_attr( $style ); ?>">
			<a class="uaip-tt-event__link" href="<?php echo esc_url( $href ); ?>" data-details="<?php echo esc_attr( $details ); ?>">
				<span class="uaip-tt-event__time">
					<time datetime="<?php echo esc_attr( $item['start_iso'] ); ?>"><?php echo esc_html( Uaip_Timetable_Event::format_time( $item['start'] ) ); ?></time><span aria-hidden="true">–</span><span class="screen-reader-text"><?php esc_html_e( 'to', 'uaip-simple-timetable' ); ?></span><time datetime="<?php echo esc_attr( $item['end_iso'] ); ?>"><?php echo esc_html( Uaip_Timetable_Event::format_time( $item['end'] ) ); ?></time>
				</span>
				<span class="uaip-tt-event__title"><?php echo esc_html( $item['title'] ); ?></span>
				<?php if ( $meta ) : ?>
					<span class="uaip-tt-event__meta"><?php echo esc_html( implode( ' · ', $meta ) ); ?></span>
				<?php endif; ?>
			</a>
			<div class="uaip-tt-event__details" id="<?php echo esc_attr( $details ); ?>" hidden>
				<?php self::details( $item, $dated ); ?>
			</div>
		</li>
		<?php
	}

	/**
	 * Event details shown in the popover.
	 *
	 * @param array<string,mixed> $item  Occurrence.
	 * @param bool                $dated Show the date.
	 */
	private static function details( array $item, $dated ) {
		$href = '' !== $item['url'] ? $item['url'] : $item['permalink'];
		$when = Uaip_Timetable_Event::time_range( $item['start'], $item['end'] );
		if ( $dated ) {
			/* translators: 1: date, 2: time range */
			$when = sprintf( __( '%1$s, %2$s', 'uaip-simple-timetable' ), self::format_date( $item['date'], _x( 'l j F', 'timetable list date format', 'uaip-simple-timetable' ) ), $when );
		}
		?>
		<p class="uaip-tt-details__title"><?php echo esc_html( $item['title'] ); ?></p>
		<dl class="uaip-tt-details__facts">
			<dt><?php esc_html_e( 'When', 'uaip-simple-timetable' ); ?></dt>
			<dd><?php echo esc_html( $when ); ?></dd>
			<?php if ( $item['location'] ) : ?>
				<dt><?php esc_html_e( 'Where', 'uaip-simple-timetable' ); ?></dt>
				<dd>
					<?php self::maybe_link( $item['location']['title'], $item['location']['url'] ); ?>
					<?php if ( '' !== $item['location']['address'] ) : ?>
						<br><span class="uaip-tt-details__address"><?php echo esc_html( $item['location']['address'] ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== $item['location']['map_url'] ) : ?>
						<br><a href="<?php echo esc_url( $item['location']['map_url'] ); ?>" rel="noopener" target="_blank"><?php esc_html_e( 'Map', 'uaip-simple-timetable' ); ?></a>
					<?php endif; ?>
				</dd>
			<?php endif; ?>
			<?php if ( $item['instructors'] ) : ?>
				<dt><?php echo esc_html( _n( 'Instructor', 'Instructors', count( $item['instructors'] ), 'uaip-simple-timetable' ) ); ?></dt>
				<dd>
					<?php
					foreach ( $item['instructors'] as $i => $instructor ) {
						if ( $i > 0 ) {
							echo ', ';
						}
						self::maybe_link( $instructor['title'], $instructor['url'] );
					}
					?>
				</dd>
			<?php endif; ?>
			<?php if ( $item['categories'] ) : ?>
				<dt><?php esc_html_e( 'Category', 'uaip-simple-timetable' ); ?></dt>
				<dd><?php echo esc_html( implode( ', ', wp_list_pluck( $item['categories'], 'name' ) ) ); ?></dd>
			<?php endif; ?>
		</dl>
		<?php if ( '' !== $item['excerpt'] ) : ?>
			<p class="uaip-tt-details__excerpt"><?php echo esc_html( $item['excerpt'] ); ?></p>
		<?php endif; ?>
		<p class="uaip-tt-details__more">
			<a href="<?php echo esc_url( $href ); ?>"><?php esc_html_e( 'More details', 'uaip-simple-timetable' ); ?></a>
			<?php if ( Uaip_Timetable_Settings::get( 'ics' ) ) : ?>
				<a class="uaip-tt-details__ics" href="<?php echo esc_url( Uaip_Timetable_Ics::feed_url( array( 'event' => $item['event_id'] ) ) ); ?>" download><?php esc_html_e( 'Add to calendar', 'uaip-simple-timetable' ); ?></a>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Prints a link when there is a URL, otherwise plain text.
	 *
	 * @param string $text Text.
	 * @param string $url  URL or ''.
	 */
	private static function maybe_link( $text, $url ) {
		if ( '' !== $url ) {
			echo '<a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>';
		} else {
			echo esc_html( $text );
		}
	}

	/**
	 * Heading text for the shown period.
	 *
	 * @param array<string,mixed> $attrs Normalised attributes.
	 * @param string              $from  First date.
	 * @param string              $to    Last date.
	 * @return string
	 */
	private static function heading( array $attrs, $from, $to ) {
		switch ( $attrs['view'] ) {
			case 'day':
				return self::format_date( $from, _x( 'l j F Y', 'timetable day heading format', 'uaip-simple-timetable' ) );
			case 'month':
				return self::format_date( $attrs['date'], _x( 'F Y', 'timetable month heading format', 'uaip-simple-timetable' ) );
			default:
				$same_year = substr( $from, 0, 4 ) === substr( $to, 0, 4 );
				return sprintf(
					/* translators: 1: first date, 2: last date */
					__( '%1$s – %2$s', 'uaip-simple-timetable' ),
					self::format_date( $from, $same_year ? _x( 'j M', 'timetable range start format', 'uaip-simple-timetable' ) : _x( 'j M Y', 'timetable range end format', 'uaip-simple-timetable' ) ),
					self::format_date( $to, _x( 'j M Y', 'timetable range end format', 'uaip-simple-timetable' ) )
				);
		}
	}

	/**
	 * Accessible label for the previous/next buttons.
	 *
	 * @param string $view      View.
	 * @param int    $direction -1 or 1.
	 * @return string
	 */
	private static function nav_label( $view, $direction ) {
		$labels = array(
			'day'   => array( __( 'Previous day', 'uaip-simple-timetable' ), __( 'Next day', 'uaip-simple-timetable' ) ),
			'week'  => array( __( 'Previous week', 'uaip-simple-timetable' ), __( 'Next week', 'uaip-simple-timetable' ) ),
			'month' => array( __( 'Previous month', 'uaip-simple-timetable' ), __( 'Next month', 'uaip-simple-timetable' ) ),
			'list'  => array( __( 'Earlier', 'uaip-simple-timetable' ), __( 'Later', 'uaip-simple-timetable' ) ),
		);
		$pair   = isset( $labels[ $view ] ) ? $labels[ $view ] : $labels['week'];
		return $direction < 0 ? $pair[0] : $pair[1];
	}

	/**
	 * Name of a view for the switcher.
	 *
	 * @param string $view View.
	 * @return string
	 */
	public static function view_label( $view ) {
		$labels = array(
			'week'  => __( 'Week', 'uaip-simple-timetable' ),
			'day'   => __( 'Day', 'uaip-simple-timetable' ),
			'month' => __( 'Month', 'uaip-simple-timetable' ),
			'list'  => __( 'List', 'uaip-simple-timetable' ),
		);
		return isset( $labels[ $view ] ) ? $labels[ $view ] : $view;
	}

	/**
	 * Formats a `Y-m-d` date (noon UTC, so the timezone can never shift the day).
	 *
	 * @param string $date   Date.
	 * @param string $format PHP date format.
	 * @return string
	 */
	public static function format_date( $date, $format ) {
		$ts = strtotime( $date . ' 12:00:00 UTC' );
		return false === $ts ? $date : wp_date( $format, $ts, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Minutes after midnight of a `H:i` time.
	 *
	 * @param string $time Time.
	 * @return int
	 */
	private static function minutes( $time ) {
		$parts = explode( ':', (string) $time );
		return (int) $parts[0] * 60 + (int) ( isset( $parts[1] ) ? $parts[1] : 0 );
	}

	/**
	 * Splits a comma-separated value (or array) into trimmed lowercase strings.
	 *
	 * @param mixed $value Value.
	 * @return string[]
	 */
	private static function csv( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( ',', array_map( 'strval', $value ) );
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', strtolower( (string) $value ) ) ), 'strlen' ) );
	}

	/**
	 * Reads a yes/no style flag.
	 *
	 * @param mixed $value    Value.
	 * @param bool  $fallback Value when empty.
	 * @return bool
	 */
	private static function flag( $value, $fallback ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value ) {
			return $fallback;
		}
		return in_array( $value, array( '1', 'yes', 'true', 'on' ), true );
	}
}
