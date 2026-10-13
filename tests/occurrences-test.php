<?php
/**
 * Tests for the occurrence engine's pure functions (no WordPress needed).
 * Run in the dev container: docker compose -f dev/docker-compose.yml exec wordpress php /uaip-tests/occurrences-test.php
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
require $uaip_tt_root . '/includes/class-uaip-timetable-renderer.php';

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

$e = array( 'Uaip_Timetable_Occurrences', 'expand' );

// Day arithmetic.
check( 'weekday of 2026-10-13 is Tuesday', 2, Uaip_Timetable_Occurrences::weekday( Uaip_Timetable_Occurrences::day_number( '2026-10-13' ) ) );
check( 'weekday of 1970-01-01 is Thursday', 4, Uaip_Timetable_Occurrences::weekday( 0 ) );
check( 'weekday before 1970 (1969-12-31 Wednesday)', 3, Uaip_Timetable_Occurrences::weekday( Uaip_Timetable_Occurrences::day_number( '1969-12-31' ) ) );
check( 'invalid date has no day number', null, Uaip_Timetable_Occurrences::day_number( '2026-02-30' ) );
check( 'add_days across a year', '2027-01-03', Uaip_Timetable_Occurrences::add_days( '2026-12-30', 4 ) );

// One-off events.
check( 'once: inside range', array( '2026-10-14' ), $e( rule( array( 'repeat' => 'none' ) ), '2026-10-01', '2026-10-31' ) );
check( 'once: outside range', array(), $e( rule( array( 'repeat' => 'none' ) ), '2026-11-01', '2026-11-30' ) );
check( 'once: cancelled', array(), $e( rule( array( 'repeat' => 'none', 'exdates' => array( '2026-10-14' ) ) ), '2026-10-01', '2026-10-31' ) );

// Weekly.
check(
	'weekly: no days = weekday of first date',
	array( '2026-10-14', '2026-10-21', '2026-10-28' ),
	$e( rule(), '2026-10-01', '2026-10-31' )
);
check(
	'weekly: several days, nothing before the first date',
	array( '2026-10-14', '2026-10-16', '2026-10-19', '2026-10-21', '2026-10-23' ),
	$e( rule( array( 'days' => array( 1, 3, 5 ) ) ), '2026-10-12', '2026-10-23' )
);
check(
	'weekly: until is inclusive',
	array( '2026-10-14', '2026-10-21' ),
	$e( rule( array( 'until' => '2026-10-21' ) ), '2026-10-01', '2026-12-31' )
);
check(
	'weekly: cancelled dates skipped',
	array( '2026-10-14', '2026-10-28' ),
	$e( rule( array( 'exdates' => array( '2026-10-21' ) ) ), '2026-10-01', '2026-10-31' )
);
check(
	'weekly: ignore_exdates (typical week)',
	array( '2026-10-21' ),
	$e( rule( array( 'exdates' => array( '2026-10-21' ) ) ), '2026-10-19', '2026-10-25', true )
);
check(
	'every 2 weeks, one day',
	array( '2026-10-14', '2026-10-28', '2026-11-11' ),
	$e( rule( array( 'interval' => 2 ) ), '2026-10-01', '2026-11-20' )
);
check(
	'every 2 weeks, two days, weeks counted from the Monday (WKST=MO)',
	array( '2026-10-15', '2026-10-26', '2026-10-29', '2026-11-09', '2026-11-12' ),
	$e( rule( array( 'date' => '2026-10-15', 'interval' => 2, 'days' => array( 1, 4 ) ) ), '2026-10-01', '2026-11-15' )
);
check(
	'every 2 weeks, Sunday in a Monday-based week',
	array( '2026-10-18', '2026-11-01' ),
	$e( rule( array( 'date' => '2026-10-18', 'interval' => 2, 'days' => array( 0 ) ) ), '2026-10-01', '2026-11-07' )
);
check(
	'every 2 weeks, range starting later keeps the rhythm',
	array( '2026-11-11', '2026-11-25' ),
	$e( rule( array( 'interval' => 2 ) ), '2026-11-05', '2026-11-30' )
);
check(
	'weekly across a DST change (EU, 2026-03-29)',
	array( '2026-03-22', '2026-03-29', '2026-04-05' ),
	$e( rule( array( 'date' => '2026-03-22', 'days' => array( 0 ) ) ), '2026-03-01', '2026-04-10' )
);
check(
	'every 2 weeks across a year boundary',
	array( '2026-12-28', '2027-01-11', '2027-01-25' ),
	$e( rule( array( 'date' => '2026-12-28', 'interval' => 2, 'days' => array( 1 ) ) ), '2026-12-01', '2027-01-31' )
);
check( 'empty range', array(), $e( rule(), '2026-10-31', '2026-10-01' ) );
check( 'end before start = no occurrences', array(), $e( rule( array( 'end' => '17:00' ) ), '2026-10-01', '2026-10-31' ) );
check( 'missing date = no occurrences', array(), $e( rule( array( 'date' => '' ) ), '2026-10-01', '2026-10-31' ) );
check( 'until before range', array(), $e( rule( array( 'until' => '2026-10-20' ) ), '2026-11-01', '2026-11-30' ) );
check( 'range is capped', true, count( $e( rule( array( 'days' => array( 0, 1, 2, 3, 4, 5, 6 ) ) ), '2026-01-01', '2036-01-01' ) ) <= 401 );

// Lanes for overlapping events.
$lanes = Uaip_Timetable_Renderer::lanes(
	array(
		array( 'start' => '09:00', 'end' => '10:00', 'n' => 'A' ),
		array( 'start' => '09:30', 'end' => '10:30', 'n' => 'B' ),
		array( 'start' => '10:00', 'end' => '11:00', 'n' => 'C' ),
		array( 'start' => '12:00', 'end' => '13:00', 'n' => 'D' ),
	)
);
check(
	'lanes: overlapping events side by side, separate cluster on its own',
	array( 'A0/2', 'B1/2', 'C0/2', 'D0/1' ),
	array_map(
		static function ( $i ) {
			return $i['n'] . $i['lane'] . '/' . $i['lanes'];
		},
		$lanes
	)
);

// Text colour.
check( 'text on white is dark', '#1d2327', Uaip_Timetable_Occurrences::text_color( '#ffffff' ) );
check( 'text on black is white', '#ffffff', Uaip_Timetable_Occurrences::text_color( '#000000' ) );
check( 'text on WP blue is white', '#ffffff', Uaip_Timetable_Occurrences::text_color( '#2271b1' ) );
check( 'text on yellow is dark', '#1d2327', Uaip_Timetable_Occurrences::text_color( '#ffd84d' ) );

echo "\n" . ( $count - $failures ) . "/$count passed\n";
exit( $failures ? 1 : 0 );
