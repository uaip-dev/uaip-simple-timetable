<?php
/**
 * Sample gym timetable for the dev site. Run with: wp eval-file /uaip-dev/sample-data.php
 * Does nothing when events already exist.
 *
 * @package UaipSimpleTimetable
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( get_posts(
	array(
		'post_type'   => 'uaip_tt_event',
		'post_status' => 'any',
		'numberposts' => 1,
	)
) ) {
	WP_CLI::log( 'Sample data already present.' );
	return;
}

$uaip_dev_cats = array();
foreach ( array(
	'yoga'     => array( 'Yoga', '#2e8540' ),
	'hiit'     => array( 'HIIT', '#c9302c' ),
	'pilates'  => array( 'Pilates', '#7b4fbf' ),
	'spin'     => array( 'Spin', '#d9641c' ),
	'workshop' => array( 'Workshop', '#2271b1' ),
) as $uaip_dev_slug => $uaip_dev_cat ) {
	$uaip_dev_term = wp_insert_term( $uaip_dev_cat[0], 'uaip_tt_category', array( 'slug' => $uaip_dev_slug ) );
	if ( ! is_wp_error( $uaip_dev_term ) ) {
		update_term_meta( $uaip_dev_term['term_id'], 'uaip_tt_color', $uaip_dev_cat[1] );
		$uaip_dev_cats[ $uaip_dev_slug ] = (int) $uaip_dev_term['term_id'];
	}
}

$uaip_dev_post = static function ( $type, $title, $content, $meta = array() ) {
	$id = wp_insert_post(
		array(
			'post_type'    => $type,
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $content,
		)
	);
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $id;
};

$uaip_dev_studio_a = $uaip_dev_post(
	'uaip_tt_location',
	'Studio A',
	'Our main studio with sprung floor and mirrors.',
	array( '_uaip_tt_address' => "12 Harbour Road\nLevel 2" )
);
$uaip_dev_studio_b = $uaip_dev_post( 'uaip_tt_location', 'Studio B', 'Cycle studio with 20 bikes.', array( '_uaip_tt_address' => "12 Harbour Road\nLevel 3" ) );
$uaip_dev_maya     = $uaip_dev_post( 'uaip_tt_instructor', 'Maya Lee', 'Yoga and Pilates teacher.', array( '_uaip_tt_email' => 'maya@example.com' ) );
$uaip_dev_tom      = $uaip_dev_post( 'uaip_tt_instructor', 'Tom Okafor', 'Strength and conditioning coach.' );
$uaip_dev_sara     = $uaip_dev_post( 'uaip_tt_instructor', 'Sara Novak', 'Cycling coach.' );

// Monday of last week, so weekly events already repeat this week.
$uaip_dev_today  = new DateTimeImmutable( 'now', wp_timezone() );
$uaip_dev_monday = $uaip_dev_today->modify( 'monday this week' )->modify( '-7 days' );
$uaip_dev_date   = static function ( $days ) use ( $uaip_dev_monday ) {
	return $uaip_dev_monday->modify( "+{$days} days" )->format( 'Y-m-d' );
};

$uaip_dev_events = array(
	array( 'Morning Flow Yoga', 'yoga', array( 1, 3, 5 ), '07:00', '08:00', $uaip_dev_studio_a, array( $uaip_dev_maya ) ),
	array( 'HIIT Express', 'hiit', array( 1, 2, 4 ), '12:15', '12:45', $uaip_dev_studio_a, array( $uaip_dev_tom ) ),
	array( 'Spin 45', 'spin', array( 2, 4 ), '18:00', '18:45', $uaip_dev_studio_b, array( $uaip_dev_sara ) ),
	array( 'Pilates Core', 'pilates', array( 2, 4 ), '18:00', '19:00', $uaip_dev_studio_a, array( $uaip_dev_maya ) ),
	array( 'Evening HIIT', 'hiit', array( 1, 3 ), '18:30', '19:30', $uaip_dev_studio_a, array( $uaip_dev_tom, $uaip_dev_sara ) ),
	array( 'Weekend Spin', 'spin', array( 6 ), '09:00', '10:00', $uaip_dev_studio_b, array( $uaip_dev_sara ) ),
	array( 'Yin Yoga', 'yoga', array( 0 ), '17:00', '18:15', $uaip_dev_studio_a, array( $uaip_dev_maya ) ),
);
foreach ( $uaip_dev_events as $uaip_dev_e ) {
	$uaip_dev_id = $uaip_dev_post(
		'uaip_tt_event',
		$uaip_dev_e[0],
		'A friendly class for all levels. Bring water and a towel.',
		array(
			'_uaip_tt_date'        => $uaip_dev_date( 0 ),
			'_uaip_tt_start'       => $uaip_dev_e[3],
			'_uaip_tt_end'         => $uaip_dev_e[4],
			'_uaip_tt_repeat'      => 'weekly',
			'_uaip_tt_interval'    => 1,
			'_uaip_tt_days'        => $uaip_dev_e[2],
			'_uaip_tt_location'    => $uaip_dev_e[5],
			'_uaip_tt_instructors' => $uaip_dev_e[6],
		)
	);
	wp_set_object_terms( $uaip_dev_id, array( $uaip_dev_cats[ $uaip_dev_e[1] ] ), 'uaip_tt_category' );
}

// Every second week, with one cancelled date.
$uaip_dev_id = $uaip_dev_post(
	'uaip_tt_event',
	'Mobility Clinic',
	'Fortnightly mobility and stretching clinic.',
	array(
		'_uaip_tt_date'        => $uaip_dev_date( 3 ),
		'_uaip_tt_start'       => '10:00',
		'_uaip_tt_end'         => '11:30',
		'_uaip_tt_repeat'      => 'weekly',
		'_uaip_tt_interval'    => 2,
		'_uaip_tt_days'        => array( 3 ),
		'_uaip_tt_exdates'     => array( $uaip_dev_date( 31 ) ),
		'_uaip_tt_location'    => $uaip_dev_studio_a,
		'_uaip_tt_instructors' => array( $uaip_dev_maya ),
	)
);
wp_set_object_terms( $uaip_dev_id, array( $uaip_dev_cats['yoga'] ), 'uaip_tt_category' );

// One-off workshop this Saturday.
$uaip_dev_id = $uaip_dev_post(
	'uaip_tt_event',
	'Nutrition Workshop',
	'A one-off talk on eating for training.',
	array(
		'_uaip_tt_date'     => $uaip_dev_date( 12 ),
		'_uaip_tt_start'    => '11:00',
		'_uaip_tt_end'      => '12:30',
		'_uaip_tt_repeat'   => 'none',
		'_uaip_tt_location' => $uaip_dev_studio_a,
		'_uaip_tt_url'      => 'https://example.com/book',
	)
);
wp_set_object_terms( $uaip_dev_id, array( $uaip_dev_cats['workshop'] ), 'uaip_tt_category' );

foreach ( array(
	'timetable'    => array( 'Timetable', '[uaip_timetable views="week,day,month,list"]' ),
	'class-list'   => array( 'Class list', '[uaip_timetable view="list" list_days="7"]' ),
	'typical-week' => array( 'Typical week', '[uaip_timetable typical="yes" scheme="modern" filters="no"]' ),
	'yoga'         => array( 'Yoga classes', '<!-- wp:shortcode -->[uaip_timetable category="yoga" view="list"]<!-- /wp:shortcode -->' ),
	'blocks'       => array( 'Blocks', '<!-- wp:uaip-simple-timetable/timetable {"views":["week","month","list"],"align":"wide"} /--><!-- wp:heading --><h2 class="wp-block-heading">Next up</h2><!-- /wp:heading --><!-- wp:uaip-simple-timetable/upcoming {"count":4,"showInstructor":true} /-->' ),
) as $uaip_dev_slug => $uaip_dev_page ) {
	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => $uaip_dev_slug,
			'post_title'   => $uaip_dev_page[0],
			'post_content' => 0 === strpos( $uaip_dev_page[1], '<!--' ) ? $uaip_dev_page[1] : '<!-- wp:shortcode -->' . $uaip_dev_page[1] . '<!-- /wp:shortcode -->',
		)
	);
}

WP_CLI::success( 'Sample timetable created.' );
