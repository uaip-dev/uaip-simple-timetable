<?php
/**
 * Tests for the iCalendar helpers (no WordPress needed).
 * Run in the dev container: docker compose -f dev/docker-compose.yml exec wordpress php /uaip-tests/ics-test.php
 *
 * @package UaipSimpleTimetable
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

$uaip_tt_root = is_dir( '/var/www/html/wp-content/plugins/uaip-simple-timetable' )
	? '/var/www/html/wp-content/plugins/uaip-simple-timetable'
	: dirname( __DIR__ ) . '/plugin';
require $uaip_tt_root . '/includes/class-uaip-timetable-occurrences.php';
require $uaip_tt_root . '/includes/class-uaip-timetable-ics.php';

$failures = 0;
$count    = 0;

/**
 * Asserts equality and prints the result.
 *
 * @param string $name     Test name.
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 */
function check( $name, $expected, $actual ) {
	global $failures, $count;
	++$count;
	if ( $expected === $actual ) {
		echo "ok   $name\n";
		return;
	}
	++$failures;
	echo "FAIL $name\n  expected: " . json_encode( $expected ) . "\n  actual:   " . json_encode( $actual ) . "\n";
}

/**
 * Builds a rule.
 *
 * @param array $over Overrides.
 * @return array
 */
function rule( array $over = array() ) {
	return array_merge(
		array(
			'date'     => '2026-10-14',
			'start'    => '18:00',
			'end'      => '19:00',
			'repeat'   => 'weekly',
			'interval' => 1,
			'days'     => array(),
			'until'    => '',
			'exdates'  => array(),
		),
		$over
	);
}

$berlin = new DateTimeZone( 'Europe/Berlin' );
$kl     = new DateTimeZone( 'Asia/Kuala_Lumpur' );

// Text escaping and folding.
check( 'escape commas, semicolons, backslashes, newlines', 'a\\, b\\; c\\\\d\\ne', Uaip_Timetable_Ics::escape_text( "a, b; c\\d\r\ne" ) );
check( 'short line not folded', 'SUMMARY:Yoga', Uaip_Timetable_Ics::fold( 'SUMMARY:Yoga' ) );
$long   = 'DESCRIPTION:' . str_repeat( 'é', 60 );
$folded = Uaip_Timetable_Ics::fold( $long );
$max    = 0;
foreach ( explode( "\r\n", $folded ) as $line ) {
	$max = max( $max, strlen( $line ) );
}
check( 'folded lines are at most 75 octets', true, $max <= 75 );
check( 'folding keeps UTF-8 valid and unfolds to the original', $long, str_replace( "\r\n ", '', $folded ) );
check( 'folded output is valid UTF-8 per line', true, (bool) array_product( array_map( static function ( $l ) {
	return preg_match( '//u', $l );
}, explode( "\r\n", $folded ) ) ) );

// Values.
check( 'offset +0530', '+0530', Uaip_Timetable_Ics::offset( 19800 ) );
check( 'offset -0330', '-0330', Uaip_Timetable_Ics::offset( -12600 ) );
check( 'local date-time', '20261014T180000', Uaip_Timetable_Ics::local( '2026-10-14', '18:00' ) );
check( 'TZID of a named zone', 'Europe/Berlin', Uaip_Timetable_Ics::tzid( $berlin ) );
check( 'TZID of a fixed offset', 'UTC+0530', Uaip_Timetable_Ics::tzid( new DateTimeZone( '+05:30' ) ) );
check( 'TZID of UTC', 'UTC', Uaip_Timetable_Ics::tzid( new DateTimeZone( 'UTC' ) ) );

// RRULE.
check( 'one-off has no RRULE', '', Uaip_Timetable_Ics::rrule( rule( array( 'repeat' => 'none' ) ), $berlin ) );
check( 'weekly, weekday of first date', 'FREQ=WEEKLY;BYDAY=WE;WKST=MO', Uaip_Timetable_Ics::rrule( rule(), $berlin ) );
check(
	'every 2 weeks on Sun/Mon/Fri, Monday-first order',
	'FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,FR,SU;WKST=MO',
	Uaip_Timetable_Ics::rrule( rule( array( 'interval' => 2, 'days' => array( 0, 5, 1 ) ) ), $berlin )
);
check(
	'UNTIL in UTC at the start of the last occurrence (Berlin, winter time)',
	'FREQ=WEEKLY;BYDAY=WE;WKST=MO;UNTIL=20261209T170000Z',
	Uaip_Timetable_Ics::rrule( rule( array( 'until' => '2026-12-09' ) ), $berlin )
);
check(
	'UNTIL in UTC (Kuala Lumpur, crosses midnight backwards)',
	'FREQ=WEEKLY;BYDAY=WE;WKST=MO;UNTIL=20261209T010000Z',
	Uaip_Timetable_Ics::rrule( rule( array( 'until' => '2026-12-09', 'start' => '09:00', 'end' => '10:00' ) ), $kl )
);

// DTSTART must be a real occurrence.
check( 'first date: weekday of the first date', '2026-10-14', Uaip_Timetable_Ics::first_date( rule() ) );
check( 'first date: first ticked day after the first date', '2026-10-16', Uaip_Timetable_Ics::first_date( rule( array( 'days' => array( 1, 5 ) ) ) ) );
check( 'first date: skips a cancelled first occurrence', '2026-10-21', Uaip_Timetable_Ics::first_date( rule( array( 'exdates' => array( '2026-10-14' ) ) ) ) );
check( 'first date: cancelled one-off has none', null, Uaip_Timetable_Ics::first_date( rule( array( 'repeat' => 'none', 'exdates' => array( '2026-10-14' ) ) ) ) );
check( 'first date: until before the first occurrence', null, Uaip_Timetable_Ics::first_date( rule( array( 'days' => array( 5 ), 'until' => '2026-10-15' ) ) ) );

// VTIMEZONE.
$vtz = Uaip_Timetable_Ics::vtimezone( $berlin, 'Europe/Berlin', 2026, 2027 );
check( 'VTIMEZONE starts and ends properly', array( 'BEGIN:VTIMEZONE', 'TZID:Europe/Berlin' ), array_slice( $vtz, 0, 2 ) );
check( 'VTIMEZONE has the March 2026 change at 02:00 local', true, in_array( 'DTSTART:20260329T020000', $vtz, true ) );
check( 'VTIMEZONE has the October 2026 change at 03:00 local', true, in_array( 'DTSTART:20261025T030000', $vtz, true ) );
check( 'VTIMEZONE has 4 changes plus the initial state', 5, count( array_keys( $vtz, 'BEGIN:DAYLIGHT', true ) ) + count( array_keys( $vtz, 'BEGIN:STANDARD', true ) ) );
$fixed = Uaip_Timetable_Ics::vtimezone( new DateTimeZone( '+05:30' ), 'UTC+0530', 2026, 2027 );
check( 'fixed offset VTIMEZONE', array( 'BEGIN:VTIMEZONE', 'TZID:UTC+0530', 'BEGIN:STANDARD', 'DTSTART:19700101T000000', 'TZOFFSETFROM:+0530', 'TZOFFSETTO:+0530', 'END:STANDARD', 'END:VTIMEZONE' ), $fixed );

// Colour names (RFC 7986 COLOR).
check( 'red maps to a red name', true, in_array( Uaip_Timetable_Ics::color_name( '#c9302c' ), array( 'firebrick', 'crimson' ), true ) );
check( 'bad colour falls back', 'royalblue', Uaip_Timetable_Ics::color_name( 'nope' ) );

echo "\n" . ( $count - $failures ) . "/$count passed\n";
exit( $failures ? 1 : 0 );
