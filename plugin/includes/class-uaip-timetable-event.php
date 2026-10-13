<?php
/**
 * Event schedule fields: meta keys, sanitising and reading an event's rule.
 *
 * @package UaipSimpleTimetable
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helpers for the `uaip_tt_event` post type's schedule meta.
 */
final class Uaip_Timetable_Event {

	const META_DATE        = '_uaip_tt_date';
	const META_START       = '_uaip_tt_start';
	const META_END         = '_uaip_tt_end';
	const META_REPEAT      = '_uaip_tt_repeat';
	const META_INTERVAL    = '_uaip_tt_interval';
	const META_DAYS        = '_uaip_tt_days';
	const META_UNTIL       = '_uaip_tt_until';
	const META_EXDATES     = '_uaip_tt_exdates';
	const META_LOCATION    = '_uaip_tt_location';
	const META_INSTRUCTORS = '_uaip_tt_instructors';
	const META_COLOR       = '_uaip_tt_color';
	const META_URL         = '_uaip_tt_url';

	const MAX_INTERVAL = 8;
	const MAX_EXDATES  = 366;

	/**
	 * Meta definitions for register_post_meta(): key => [type, sanitize callback, default, item type].
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function meta_fields() {
		return array(
			self::META_DATE        => array(
				'type'     => 'string',
				'sanitize' => array( __CLASS__, 'sanitize_date' ),
				'default'  => '',
			),
			self::META_START       => array(
				'type'     => 'string',
				'sanitize' => array( __CLASS__, 'sanitize_time' ),
				'default'  => '',
			),
			self::META_END         => array(
				'type'     => 'string',
				'sanitize' => array( __CLASS__, 'sanitize_time' ),
				'default'  => '',
			),
			self::META_REPEAT      => array(
				'type'     => 'string',
				'sanitize' => array( __CLASS__, 'sanitize_repeat' ),
				'default'  => 'weekly',
			),
			self::META_INTERVAL    => array(
				'type'     => 'integer',
				'sanitize' => array( __CLASS__, 'sanitize_interval' ),
				'default'  => 1,
			),
			self::META_DAYS        => array(
				'type'     => 'array',
				'items'    => 'integer',
				'sanitize' => array( __CLASS__, 'sanitize_days' ),
				'default'  => array(),
			),
			self::META_UNTIL       => array(
				'type'     => 'string',
				'sanitize' => array( __CLASS__, 'sanitize_date' ),
				'default'  => '',
			),
			self::META_EXDATES     => array(
				'type'     => 'array',
				'items'    => 'string',
				'sanitize' => array( __CLASS__, 'sanitize_dates' ),
				'default'  => array(),
			),
			self::META_LOCATION    => array(
				'type'     => 'integer',
				'sanitize' => array( __CLASS__, 'sanitize_location' ),
				'default'  => 0,
			),
			self::META_INSTRUCTORS => array(
				'type'     => 'array',
				'items'    => 'integer',
				'sanitize' => array( __CLASS__, 'sanitize_instructors' ),
				'default'  => array(),
			),
			self::META_COLOR       => array(
				'type'     => 'string',
				'sanitize' => array( __CLASS__, 'sanitize_color' ),
				'default'  => '',
			),
			self::META_URL         => array(
				'type'     => 'string',
				'sanitize' => array( __CLASS__, 'sanitize_url' ),
				'default'  => '',
			),
		);
	}

	/**
	 * A `Y-m-d` date, or '' when invalid.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_date( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return '';
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $value : '';
	}

	/**
	 * A `H:i` time (00:00–23:59), or '' when invalid. Accepts `H:i:s` and drops the seconds.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_time( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $value, $m ) ) {
			return '';
		}
		return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
	}

	/**
	 * `none` or `weekly`.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_repeat( $value ) {
		return 'none' === $value ? 'none' : 'weekly';
	}

	/**
	 * Every N weeks, 1 to MAX_INTERVAL.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_interval( $value ) {
		return min( self::MAX_INTERVAL, max( 1, (int) $value ) );
	}

	/**
	 * Weekdays 0 (Sunday) to 6, unique and sorted.
	 *
	 * @param mixed $value Raw value (array or comma-separated string).
	 * @return int[]
	 */
	public static function sanitize_days( $value ) {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}
		$days = array();
		foreach ( (array) $value as $day ) {
			if ( is_numeric( $day ) && (int) $day >= 0 && (int) $day <= 6 ) {
				$days[] = (int) $day;
			}
		}
		$days = array_values( array_unique( $days ) );
		sort( $days );
		return $days;
	}

	/**
	 * A list of `Y-m-d` dates, unique and sorted.
	 *
	 * @param mixed $value Raw value (array or comma-separated string).
	 * @return string[]
	 */
	public static function sanitize_dates( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value );
		}
		$dates = array();
		foreach ( (array) $value as $date ) {
			$date = self::sanitize_date( $date );
			if ( '' !== $date ) {
				$dates[] = $date;
			}
		}
		$dates = array_values( array_unique( $dates ) );
		sort( $dates );
		return array_slice( $dates, 0, self::MAX_EXDATES );
	}

	/**
	 * A location post ID, or 0.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_location( $value ) {
		$id = absint( $value );
		return $id && Uaip_Timetable_Post_Types::LOCATION === get_post_type( $id ) ? $id : 0;
	}

	/**
	 * Instructor post IDs.
	 *
	 * @param mixed $value Raw value (array or comma-separated string).
	 * @return int[]
	 */
	public static function sanitize_instructors( $value ) {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}
		$ids = array();
		foreach ( (array) $value as $id ) {
			$id = absint( $id );
			if ( $id && Uaip_Timetable_Post_Types::INSTRUCTOR === get_post_type( $id ) ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * An http(s) URL, or ''. (Wraps esc_url_raw(): sanitize_meta() passes the meta key as a
	 * second argument, which esc_url_raw() would take as the allowed protocols.)
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_url( $value ) {
		return is_string( $value ) ? esc_url_raw( trim( $value ), array( 'http', 'https' ) ) : '';
	}

	/**
	 * A `#rrggbb` colour, or ''.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_color( $value ) {
		$color = is_string( $value ) ? sanitize_hex_color( trim( $value ) ) : '';
		return $color ? strtolower( $color ) : '';
	}

	/**
	 * The schedule rule of an event, ready for Uaip_Timetable_Occurrences::expand().
	 *
	 * @param int $post_id Event ID.
	 * @return array{date:string,start:string,end:string,repeat:string,interval:int,days:int[],until:string,exdates:string[]}
	 */
	public static function rule( $post_id ) {
		$days = get_post_meta( $post_id, self::META_DAYS, true );
		$ex   = get_post_meta( $post_id, self::META_EXDATES, true );
		return array(
			'date'     => self::sanitize_date( get_post_meta( $post_id, self::META_DATE, true ) ),
			'start'    => self::sanitize_time( get_post_meta( $post_id, self::META_START, true ) ),
			'end'      => self::sanitize_time( get_post_meta( $post_id, self::META_END, true ) ),
			'repeat'   => self::sanitize_repeat( get_post_meta( $post_id, self::META_REPEAT, true ) ),
			'interval' => self::sanitize_interval( get_post_meta( $post_id, self::META_INTERVAL, true ) ),
			'days'     => self::sanitize_days( is_array( $days ) ? $days : array() ),
			'until'    => self::sanitize_date( get_post_meta( $post_id, self::META_UNTIL, true ) ),
			'exdates'  => self::sanitize_dates( is_array( $ex ) ? $ex : array() ),
		);
	}

	/**
	 * Why a rule cannot produce occurrences, or '' when it is complete.
	 *
	 * @param array<string,mixed> $rule Rule from rule().
	 * @return string Translated problem description.
	 */
	public static function rule_problem( $rule ) {
		if ( '' === $rule['date'] ) {
			return __( 'Set a date.', 'uaip-simple-timetable' );
		}
		if ( '' === $rule['start'] || '' === $rule['end'] ) {
			return __( 'Set a start and end time.', 'uaip-simple-timetable' );
		}
		if ( $rule['end'] <= $rule['start'] ) {
			return __( 'The end time must be after the start time.', 'uaip-simple-timetable' );
		}
		if ( 'weekly' === $rule['repeat'] && '' !== $rule['until'] && $rule['until'] < $rule['date'] ) {
			return __( 'The "repeat until" date is before the first date.', 'uaip-simple-timetable' );
		}
		return '';
	}

	/**
	 * Human-readable schedule, e.g. "Every Monday and Wednesday, 18:00–19:00".
	 *
	 * @param array<string,mixed> $rule Rule from rule().
	 * @return string
	 */
	public static function summary( $rule ) {
		$problem = self::rule_problem( $rule );
		if ( '' !== $problem ) {
			return $problem;
		}
		$time = self::time_range( $rule['start'], $rule['end'] );
		$tz   = wp_timezone();

		if ( 'none' === $rule['repeat'] ) {
			$date = wp_date( get_option( 'date_format' ), ( new DateTimeImmutable( $rule['date'] . ' 12:00', $tz ) )->getTimestamp() );
			/* translators: 1: date or day (e.g. "Tuesday 13 October" or "Today"), 2: time range */
			return sprintf( __( '%1$s, %2$s', 'uaip-simple-timetable' ), $date, $time );
		}

		$days = $rule['days'] ? $rule['days'] : array( (int) gmdate( 'w', strtotime( $rule['date'] . ' 12:00 UTC' ) ) );
		$days = wp_sprintf( '%l', array_map( array( __CLASS__, 'weekday_name' ), $days ) );

		if ( 1 === $rule['interval'] ) {
			/* translators: 1: list of weekdays, e.g. "Monday and Wednesday", 2: time range */
			$text = sprintf( __( 'Every %1$s, %2$s', 'uaip-simple-timetable' ), $days, $time );
		} else {
			$text = sprintf(
				/* translators: 1: number of weeks, 2: list of weekdays, 3: time range */
				_n( 'Every %1$d week on %2$s, %3$s', 'Every %1$d weeks on %2$s, %3$s', $rule['interval'], 'uaip-simple-timetable' ),
				$rule['interval'],
				$days,
				$time
			);
		}
		$today = wp_date( 'Y-m-d' );
		if ( $rule['date'] > $today ) {
			$from = wp_date( get_option( 'date_format' ), ( new DateTimeImmutable( $rule['date'] . ' 12:00', $tz ) )->getTimestamp() );
			/* translators: 1: schedule, 2: date */
			$text = sprintf( __( '%1$s, from %2$s', 'uaip-simple-timetable' ), $text, $from );
		}
		if ( '' !== $rule['until'] ) {
			$until = wp_date( get_option( 'date_format' ), ( new DateTimeImmutable( $rule['until'] . ' 12:00', $tz ) )->getTimestamp() );
			/* translators: 1: schedule, 2: date */
			$text = sprintf( __( '%1$s, until %2$s', 'uaip-simple-timetable' ), $text, $until );
		}
		return $text;
	}

	/**
	 * "18:00–19:00" in the configured time format.
	 *
	 * @param string $start `H:i`.
	 * @param string $end   `H:i`.
	 * @return string
	 */
	public static function time_range( $start, $end ) {
		/* translators: 1: start time, 2: end time */
		return sprintf( __( '%1$s–%2$s', 'uaip-simple-timetable' ), self::format_time( $start ), self::format_time( $end ) );
	}

	/**
	 * Formats a `H:i` time with the configured time format.
	 *
	 * @param string $time `H:i`.
	 * @return string
	 */
	public static function format_time( $time ) {
		$dt = DateTimeImmutable::createFromFormat( '!H:i', $time, new DateTimeZone( 'UTC' ) );
		return $dt ? wp_date( Uaip_Timetable_Settings::time_format(), $dt->getTimestamp(), new DateTimeZone( 'UTC' ) ) : $time;
	}

	/**
	 * Localised weekday name.
	 *
	 * @param int $day 0 (Sunday) to 6.
	 * @return string
	 */
	public static function weekday_name( $day ) {
		global $wp_locale;
		return $wp_locale ? $wp_locale->get_weekday( (int) $day ) : gmdate( 'l', strtotime( "Sunday +{$day} days" ) );
	}
}
