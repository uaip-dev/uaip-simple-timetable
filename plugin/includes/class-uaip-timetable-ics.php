<?php
/**
 * iCalendar (RFC 5545) feed of the timetable and single-event downloads.
 *
 * Feed:   ?feed=uaip-timetable (or /feed/uaip-timetable/) with optional uaip_tt_category,
 *         uaip_tt_location and uaip_tt_instructor filters.
 * Event:  the same URL with uaip_tt_event=<ID>.
 *
 * Weekly events are written as one VEVENT with RRULE (WKST=MO, matching the occurrence
 * engine) and EXDATEs, in the site timezone with a generated VTIMEZONE. Only published,
 * non-password-protected events are included.
 *
 * @package UaipSimpleTimetable
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and serves .ics output.
 */
final class Uaip_Timetable_Ics {

	const FEED = 'uaip-timetable';

	/** iCalendar weekday codes, indexed 0 (Sunday) to 6. */
	const BYDAY = array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' );

	/** How many years of time-zone transitions to include after the current year. */
	const TZ_YEARS_AHEAD = 5;

	/**
	 * Hooks.
	 */
	public function register_hooks() {
		add_action( 'init', array( __CLASS__, 'add_feed' ) );
		add_action( 'wp_head', array( $this, 'feed_link_tag' ) );
	}

	/**
	 * Registers the feed.
	 */
	public static function add_feed() {
		add_feed( self::FEED, array( __CLASS__, 'serve' ) );
	}

	/**
	 * Advertises the feed on pages that contain a timetable.
	 */
	public function feed_link_tag() {
		if ( ! Uaip_Timetable_Settings::get( 'ics' ) ) {
			return;
		}
		$post = get_post();
		if ( ! is_singular() || ! $post || ( ! has_shortcode( $post->post_content, Uaip_Timetable_Shortcode::TAG ) && ! has_block( 'uaip-simple-timetable/timetable', $post ) ) ) {
			return;
		}
		printf(
			'<link rel="alternate" type="text/calendar" title="%s" href="%s">' . "\n",
			esc_attr( self::calendar_name() ),
			esc_url( self::feed_url() )
		);
	}

	/**
	 * Feed URL with optional filters.
	 *
	 * @param array<string,mixed> $filters category (slugs), location / instructor (IDs), event (ID).
	 * @return string
	 */
	public static function feed_url( array $filters = array() ) {
		$args = array();
		foreach ( array( 'category', 'location', 'instructor', 'event' ) as $key ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$args[ 'uaip_tt_' . $key ] = implode( ',', (array) $filters[ $key ] );
			}
		}
		return add_query_arg( $args, get_feed_link( self::FEED ) );
	}

	/**
	 * Feed callback: prints the calendar and stops.
	 */
	public static function serve() {
		if ( ! Uaip_Timetable_Settings::get( 'ics' ) ) {
			status_header( 404 );
			nocache_headers();
			echo esc_html__( 'The calendar feed is turned off.', 'uaip-simple-timetable' );
			exit;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public read-only feed.
		$read = static function ( $key ) {
			return isset( $_GET[ 'uaip_tt_' . $key ] ) && is_string( $_GET[ 'uaip_tt_' . $key ] )
				? sanitize_text_field( wp_unslash( $_GET[ 'uaip_tt_' . $key ] ) )
				: '';
		};
		$event_id = absint( $read( 'event' ) );
		$attrs    = Uaip_Timetable_Renderer::normalize(
			array(
				'category'   => $read( 'category' ),
				'location'   => $read( 'location' ),
				'instructor' => $read( 'instructor' ),
			)
		);
		// phpcs:enable

		$snapshot = Uaip_Timetable_Occurrences::snapshot();
		$events   = self::filter_events( $snapshot, $attrs, $event_id );
		if ( $event_id && ! $events ) {
			status_header( 404 );
			nocache_headers();
			echo esc_html__( 'Event not found.', 'uaip-simple-timetable' );
			exit;
		}

		$name     = self::calendar_name();
		$filename = 'timetable.ics';
		if ( $event_id ) {
			$name     = $events[0]['title'];
			$filename = sanitize_file_name( sanitize_title( $events[0]['title'] ) . '.ics' );
		}
		$body = self::calendar( $events, $snapshot, $name, wp_timezone(), ! $event_id );

		status_header( 200 );
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: ' . ( $event_id ? 'attachment' : 'inline' ) . '; filename="' . $filename . '"' );
		header( 'Cache-Control: public, max-age=900' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/calendar, escaped per RFC 5545 in escape_text().
		exit;
	}

	/**
	 * Events of the snapshot matching the filters (or the single event).
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param array<string,mixed> $attrs    Normalised filters.
	 * @param int                 $event_id Single event, or 0.
	 * @return array<int,array<string,mixed>>
	 */
	private static function filter_events( array $snapshot, array $attrs, $event_id ) {
		$cats = null;
		if ( $attrs['category'] ) {
			$cats = array();
			foreach ( $snapshot['categories'] as $id => $category ) {
				if ( in_array( $category['slug'], $attrs['category'], true ) || ( $category['parent'] && isset( $snapshot['categories'][ $category['parent'] ] ) && in_array( $snapshot['categories'][ $category['parent'] ]['slug'], $attrs['category'], true ) ) ) {
					$cats[] = $id;
				}
			}
		}
		$out = array();
		foreach ( $snapshot['events'] as $event ) {
			if ( $event_id ) {
				if ( $event['id'] === $event_id ) {
					$out[] = $event;
				}
				continue;
			}
			if ( null !== $cats && ! array_intersect( $event['categories'], $cats ) ) {
				continue;
			}
			if ( $attrs['location'] && ! in_array( $event['location'], $attrs['location'], true ) ) {
				continue;
			}
			if ( $attrs['instructor'] && ! array_intersect( $event['instructors'], $attrs['instructor'] ) ) {
				continue;
			}
			$out[] = $event;
		}
		return $out;
	}

	/**
	 * Calendar name, e.g. "Harbour Fitness timetable".
	 *
	 * @return string
	 */
	private static function calendar_name() {
		/* translators: %s: site name */
		return sprintf( __( '%s timetable', 'uaip-simple-timetable' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
	}

	/**
	 * The whole VCALENDAR.
	 *
	 * @param array<int,array<string,mixed>> $events   Events from the snapshot.
	 * @param array<string,mixed>            $snapshot Snapshot (for locations, instructors, categories).
	 * @param string                         $name     Calendar name.
	 * @param DateTimeZone                   $tz       Site timezone.
	 * @param bool                           $is_feed  A subscription (adds refresh hints).
	 * @return string
	 */
	public static function calendar( array $events, array $snapshot, $name, DateTimeZone $tz, $is_feed ) {
		$tzid  = self::tzid( $tz );
		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//UAIP//Simple Timetable ' . UAIP_TT_VERSION . '//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . self::escape_text( $name ),
			'X-WR-TIMEZONE:' . $tzid,
		);
		if ( $is_feed ) {
			$lines[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT6H';
			$lines[] = 'X-PUBLISHED-TTL:PT6H';
		}

		$first_year = (int) gmdate( 'Y' ) - 1;
		foreach ( $events as $event ) {
			$first_year = min( $first_year, (int) substr( $event['rule']['date'], 0, 4 ) );
		}
		$lines = array_merge( $lines, self::vtimezone( $tz, $tzid, max( $first_year, (int) gmdate( 'Y' ) - 15 ), (int) gmdate( 'Y' ) + self::TZ_YEARS_AHEAD ) );

		foreach ( $events as $event ) {
			$lines = array_merge( $lines, self::vevent( $event, $snapshot, $tz, $tzid, $host ) );
		}
		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", array_map( array( __CLASS__, 'fold' ), $lines ) ) . "\r\n";
	}

	/**
	 * One VEVENT (with RRULE/EXDATE for weekly events). Empty when it never occurs.
	 *
	 * @param array<string,mixed> $event    Event from the snapshot.
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param DateTimeZone        $tz       Site timezone.
	 * @param string              $tzid     TZID.
	 * @param string              $host     Site host for UIDs.
	 * @return string[]
	 */
	public static function vevent( array $event, array $snapshot, DateTimeZone $tz, $tzid, $host ) {
		$rule  = $event['rule'];
		$first = self::first_date( $rule );
		if ( null === $first ) {
			return array();
		}

		$lines   = array( 'BEGIN:VEVENT' );
		$lines[] = 'UID:uaip-tt-' . $event['id'] . '@' . $host;
		$stamp   = ! empty( $event['modified'] ) ? strtotime( $event['modified'] . ' UTC' ) : time();
		$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z', (int) $stamp );
		$lines[] = 'LAST-MODIFIED:' . gmdate( 'Ymd\THis\Z', (int) $stamp );
		$lines[] = 'DTSTART;TZID=' . $tzid . ':' . self::local( $first, $rule['start'] );
		$lines[] = 'DTEND;TZID=' . $tzid . ':' . self::local( $first, $rule['end'] );

		$rrule = self::rrule( $rule, $tz );
		if ( '' !== $rrule ) {
			$lines[] = 'RRULE:' . $rrule;
			$exdates = array();
			foreach ( $rule['exdates'] as $date ) {
				if ( $date > $first ) {
					$exdates[] = self::local( $date, $rule['start'] );
				}
			}
			if ( $exdates ) {
				$lines[] = 'EXDATE;TZID=' . $tzid . ':' . implode( ',', $exdates );
			}
		}

		$lines[] = 'SUMMARY:' . self::escape_text( $event['title'] );
		$url     = '' !== $event['url'] ? $event['url'] : $event['permalink'];
		$text    = trim( $event['excerpt'] . "\n\n" . $url );
		$lines[] = 'DESCRIPTION:' . self::escape_text( $text );
		$lines[] = 'URL:' . $url;

		if ( $event['location'] && isset( $snapshot['locations'][ $event['location'] ] ) ) {
			$location = $snapshot['locations'][ $event['location'] ];
			$where    = $location['title'] . ( '' !== $location['address'] ? ', ' . preg_replace( '/\s*\n\s*/', ', ', trim( $location['address'] ) ) : '' );
			$lines[]  = 'LOCATION:' . self::escape_text( $where );
		}
		$names = array();
		foreach ( $event['categories'] as $id ) {
			if ( isset( $snapshot['categories'][ $id ] ) ) {
				$names[] = self::escape_text( $snapshot['categories'][ $id ]['name'] );
			}
		}
		if ( $names ) {
			$lines[] = 'CATEGORIES:' . implode( ',', $names );
		}
		foreach ( $event['instructors'] as $id ) {
			if ( isset( $snapshot['instructors'][ $id ] ) ) {
				$lines[] = 'X-UAIP-INSTRUCTOR:' . self::escape_text( $snapshot['instructors'][ $id ]['title'] );
			}
		}
		$lines[] = 'COLOR:' . self::color_name( $event['color'] );
		$lines[] = 'END:VEVENT';
		return $lines;
	}

	/**
	 * First date the rule really occurs on (DTSTART must be an occurrence), or null.
	 *
	 * @param array<string,mixed> $rule Rule.
	 * @return string|null
	 */
	public static function first_date( array $rule ) {
		// Cancelled dates are skipped here too, so clients that ignore an EXDATE on DTSTART
		// still start on a real occurrence.
		$to    = 'none' === $rule['repeat'] ? $rule['date'] : Uaip_Timetable_Occurrences::add_days( $rule['date'], Uaip_Timetable_Occurrences::MAX_RANGE_DAYS );
		$dates = Uaip_Timetable_Occurrences::expand( $rule, $rule['date'], $to );
		return $dates ? $dates[0] : null;
	}

	/**
	 * RRULE value for a weekly rule ('' for one-off events).
	 *
	 * @param array<string,mixed> $rule Rule.
	 * @param DateTimeZone        $tz   Site timezone (UNTIL is given in UTC).
	 * @return string
	 */
	public static function rrule( array $rule, DateTimeZone $tz ) {
		if ( 'none' === $rule['repeat'] ) {
			return '';
		}
		$days = $rule['days'] ? $rule['days'] : array( Uaip_Timetable_Occurrences::weekday( (int) Uaip_Timetable_Occurrences::day_number( $rule['date'] ) ) );
		// Monday-first order reads naturally and matches WKST=MO.
		usort(
			$days,
			static function ( $a, $b ) {
				return ( ( $a + 6 ) % 7 ) - ( ( $b + 6 ) % 7 );
			}
		);
		$parts = array( 'FREQ=WEEKLY' );
		if ( (int) $rule['interval'] > 1 ) {
			$parts[] = 'INTERVAL=' . (int) $rule['interval'];
		}
		$parts[] = 'BYDAY=' . implode(
			',',
			array_map(
				static function ( $d ) {
					return self::BYDAY[ (int) $d ];
				},
				$days
			)
		);
		$parts[] = 'WKST=MO';
		if ( '' !== $rule['until'] ) {
			// UNTIL is inclusive and must be UTC when DTSTART has a TZID: the start of the
			// last allowed occurrence.
			$until   = new DateTimeImmutable( $rule['until'] . ' ' . $rule['start'], $tz );
			$parts[] = 'UNTIL=' . $until->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
		}
		return implode( ';', $parts );
	}

	/**
	 * TZID for the site timezone: the IANA name, or e.g. "UTC+0530" for a fixed offset.
	 *
	 * @param DateTimeZone $tz Timezone.
	 * @return string
	 */
	public static function tzid( DateTimeZone $tz ) {
		$name = $tz->getName();
		if ( preg_match( '#^[A-Za-z_]+(/[A-Za-z0-9_+\-]+)*$#', $name ) && 'Z' !== $name ) {
			return $name;
		}
		$offset = ( new DateTimeImmutable( 'now', $tz ) )->getOffset();
		return 'UTC' . self::offset( $offset );
	}

	/**
	 * VTIMEZONE with the real transitions from $from_year to $to_year.
	 *
	 * @param DateTimeZone $tz        Timezone.
	 * @param string       $tzid      TZID used in the calendar.
	 * @param int          $from_year First year.
	 * @param int          $to_year   Last year.
	 * @return string[]
	 */
	public static function vtimezone( DateTimeZone $tz, $tzid, $from_year, $to_year ) {
		$begin       = gmmktime( 0, 0, 0, 1, 1, $from_year );
		$end         = gmmktime( 23, 59, 59, 12, 31, $to_year );
		$transitions = $tz->getTransitions( $begin, $end );
		$lines       = array( 'BEGIN:VTIMEZONE', 'TZID:' . $tzid );

		if ( ! is_array( $transitions ) || ! $transitions ) {
			$offset = ( new DateTimeImmutable( 'now', $tz ) )->getOffset();
			$transitions = array(
				array(
					'ts'     => $begin,
					'offset' => $offset,
					'isdst'  => false,
					'abbr'   => '',
				),
			);
		}

		// The first entry is the state at $begin; the rest are real changes.
		$previous = $transitions[0]['offset'];
		foreach ( $transitions as $i => $t ) {
			$from  = 0 === $i ? $t['offset'] : $previous;
			$local = 0 === $i ? '19700101T000000' : gmdate( 'Ymd\THis', $t['ts'] + $from );
			$kind  = $t['isdst'] ? 'DAYLIGHT' : 'STANDARD';
			$lines = array_merge(
				$lines,
				array(
					'BEGIN:' . $kind,
					'DTSTART:' . $local,
					'TZOFFSETFROM:' . self::offset( $from ),
					'TZOFFSETTO:' . self::offset( $t['offset'] ),
				),
				( '' !== (string) $t['abbr'] && ! preg_match( '/^[+-]/', (string) $t['abbr'] ) ) ? array( 'TZNAME:' . self::escape_text( (string) $t['abbr'] ) ) : array(),
				array( 'END:' . $kind )
			);
			$previous = $t['offset'];
		}
		$lines[] = 'END:VTIMEZONE';
		return $lines;
	}

	/**
	 * "+0530" style UTC offset.
	 *
	 * @param int $seconds Offset in seconds.
	 * @return string
	 */
	public static function offset( $seconds ) {
		$sign    = $seconds < 0 ? '-' : '+';
		$seconds = abs( (int) $seconds );
		return sprintf( '%s%02d%02d', $sign, intdiv( $seconds, 3600 ), intdiv( $seconds % 3600, 60 ) );
	}

	/**
	 * Local date-time value `YYYYMMDDTHHMMSS`.
	 *
	 * @param string $date `Y-m-d`.
	 * @param string $time `H:i`.
	 * @return string
	 */
	public static function local( $date, $time ) {
		return str_replace( '-', '', $date ) . 'T' . str_replace( ':', '', $time ) . '00';
	}

	/**
	 * Escapes a TEXT value (RFC 5545 §3.3.11).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function escape_text( $text ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		return str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\\;', '\\,', '\\n' ), $text );
	}

	/**
	 * Folds a content line at 75 octets without splitting UTF-8 characters (§3.1).
	 *
	 * @param string $line Line.
	 * @return string
	 */
	public static function fold( $line ) {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}
		$out   = '';
		$bytes = 0;
		$chars = preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $chars ) {
			$chars = str_split( $line );
		}
		foreach ( $chars as $char ) {
			$len = strlen( $char );
			if ( $bytes + $len > 75 ) {
				$out  .= "\r\n ";
				$bytes = 1;
			}
			$out   .= $char;
			$bytes += $len;
		}
		return $out;
	}

	/**
	 * Nearest CSS colour name for the RFC 7986 COLOR property (it only takes names).
	 *
	 * @param string $hex `#rrggbb`.
	 * @return string
	 */
	public static function color_name( $hex ) {
		$names = array(
			'royalblue'   => array( 65, 105, 225 ),
			'steelblue'   => array( 70, 130, 180 ),
			'teal'        => array( 0, 128, 128 ),
			'seagreen'    => array( 46, 139, 87 ),
			'forestgreen' => array( 34, 139, 34 ),
			'olivedrab'   => array( 107, 142, 35 ),
			'goldenrod'   => array( 218, 165, 32 ),
			'darkorange'  => array( 255, 140, 0 ),
			'chocolate'   => array( 210, 105, 30 ),
			'firebrick'   => array( 178, 34, 34 ),
			'crimson'     => array( 220, 20, 60 ),
			'mediumvioletred' => array( 199, 21, 133 ),
			'rebeccapurple'   => array( 102, 51, 153 ),
			'slateblue'   => array( 106, 90, 205 ),
			'slategray'   => array( 112, 128, 144 ),
			'dimgray'     => array( 105, 105, 105 ),
		);
		$hex   = ltrim( (string) $hex, '#' );
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return 'royalblue';
		}
		$rgb  = array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
		$best = 'royalblue';
		$min  = PHP_INT_MAX;
		foreach ( $names as $name => $c ) {
			$d = ( $c[0] - $rgb[0] ) ** 2 + ( $c[1] - $rgb[1] ) ** 2 + ( $c[2] - $rgb[2] ) ** 2;
			if ( $d < $min ) {
				$min  = $d;
				$best = $name;
			}
		}
		return $best;
	}
}
