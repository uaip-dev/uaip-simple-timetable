<?php
/**
 * Schedule details on single event, location and instructor pages.
 *
 * @package UaipSimpleTimetable
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a schedule box above the content of our single pages.
 */
final class Uaip_Timetable_Single {

	/** How many upcoming dates to list. */
	const UPCOMING = 5;

	/** How far ahead to look for upcoming dates, in days. */
	const LOOKAHEAD = 180;

	/**
	 * Hooks.
	 */
	public function register_hooks() {
		add_filter( 'the_content', array( $this, 'filter_content' ) );
	}

	/**
	 * Prepends the schedule box in the main loop of our single pages.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function filter_content( $content ) {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post || post_password_required( $post ) ) {
			return $content;
		}
		switch ( $post->post_type ) {
			case Uaip_Timetable_Post_Types::EVENT:
				$box = $this->event_box( $post );
				break;
			case Uaip_Timetable_Post_Types::LOCATION:
				$box = $this->related_box( array( 'location' => array( $post->ID ) ), $this->location_facts( $post ) );
				break;
			case Uaip_Timetable_Post_Types::INSTRUCTOR:
				$box = $this->related_box( array( 'instructor' => array( $post->ID ) ), $this->instructor_facts( $post ) );
				break;
			default:
				return $content;
		}
		Uaip_Timetable_Assets::enqueue_front();
		return $box . $content;
	}

	/**
	 * Schedule, place, instructors and next dates of an event.
	 *
	 * @param WP_Post $post Event.
	 * @return string
	 */
	private function event_box( WP_Post $post ) {
		$rule = Uaip_Timetable_Event::rule( $post->ID );
		if ( '' !== Uaip_Timetable_Event::rule_problem( $rule ) ) {
			return '';
		}
		$today = wp_date( 'Y-m-d' );
		$dates = array_slice( Uaip_Timetable_Occurrences::expand( $rule, $today, Uaip_Timetable_Occurrences::add_days( $today, self::LOOKAHEAD ) ), 0, self::UPCOMING );

		$location_id = (int) get_post_meta( $post->ID, Uaip_Timetable_Event::META_LOCATION, true );
		$snapshot    = Uaip_Timetable_Occurrences::snapshot();
		$location    = isset( $snapshot['locations'][ $location_id ] ) ? $snapshot['locations'][ $location_id ] : null;
		$names       = array();
		$ids         = get_post_meta( $post->ID, Uaip_Timetable_Event::META_INSTRUCTORS, true );
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			if ( isset( $snapshot['instructors'][ $id ] ) ) {
				$names[] = $snapshot['instructors'][ $id ];
			}
		}

		ob_start();
		?>
		<div class="uaip-tt-single">
			<dl class="uaip-tt-single__facts">
				<dt><?php esc_html_e( 'When', 'uaip-simple-timetable' ); ?></dt>
				<dd><?php echo esc_html( Uaip_Timetable_Event::summary( $rule ) ); ?></dd>
				<?php if ( $location ) : ?>
					<dt><?php esc_html_e( 'Where', 'uaip-simple-timetable' ); ?></dt>
					<dd>
						<?php echo $location['url'] ? '<a href="' . esc_url( $location['url'] ) . '">' . esc_html( $location['title'] ) . '</a>' : esc_html( $location['title'] ); ?>
						<?php if ( '' !== $location['address'] ) : ?>
							<br><?php echo esc_html( $location['address'] ); ?>
						<?php endif; ?>
					</dd>
				<?php endif; ?>
				<?php if ( $names ) : ?>
					<dt><?php echo esc_html( _n( 'Instructor', 'Instructors', count( $names ), 'uaip-simple-timetable' ) ); ?></dt>
					<dd>
						<?php
						$links = array();
						foreach ( $names as $instructor ) {
							$links[] = $instructor['url'] ? '<a href="' . esc_url( $instructor['url'] ) . '">' . esc_html( $instructor['title'] ) . '</a>' : esc_html( $instructor['title'] );
						}
						echo wp_kses_post( implode( ', ', $links ) );
						?>
					</dd>
				<?php endif; ?>
				<?php if ( 'weekly' === $rule['repeat'] ) : ?>
					<dt><?php esc_html_e( 'Next dates', 'uaip-simple-timetable' ); ?></dt>
					<dd>
						<?php if ( $dates ) : ?>
							<ul class="uaip-tt-single__dates">
								<?php foreach ( $dates as $date ) : ?>
									<li><time datetime="<?php echo esc_attr( $date ); ?>"><?php echo esc_html( Uaip_Timetable_Renderer::format_date( $date, _x( 'l j F', 'timetable list date format', 'uaip-simple-timetable' ) ) ); ?></time></li>
								<?php endforeach; ?>
							</ul>
						<?php else : ?>
							<?php esc_html_e( 'No upcoming dates.', 'uaip-simple-timetable' ); ?>
						<?php endif; ?>
					</dd>
				<?php endif; ?>
			</dl>
			<?php if ( Uaip_Timetable_Settings::get( 'ics' ) && null !== Uaip_Timetable_Ics::first_date( $rule ) ) : ?>
				<p class="uaip-tt-single__ics">
					<a href="<?php echo esc_url( Uaip_Timetable_Ics::feed_url( array( 'event' => $post->ID ) ) ); ?>" download><?php esc_html_e( 'Add to calendar', 'uaip-simple-timetable' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Address and map link of a location.
	 *
	 * @param WP_Post $post Location.
	 * @return array<int,array{0:string,1:string}> [label, HTML] pairs.
	 */
	private function location_facts( WP_Post $post ) {
		$facts   = array();
		$address = (string) get_post_meta( $post->ID, Uaip_Timetable_Post_Types::META_ADDRESS, true );
		$map     = (string) get_post_meta( $post->ID, Uaip_Timetable_Post_Types::META_MAP_URL, true );
		if ( '' !== $address ) {
			$facts[] = array( __( 'Address', 'uaip-simple-timetable' ), nl2br( esc_html( $address ) ) );
		}
		if ( '' !== $map ) {
			$facts[] = array( __( 'Map', 'uaip-simple-timetable' ), '<a href="' . esc_url( $map ) . '" rel="noopener" target="_blank">' . esc_html__( 'Open map', 'uaip-simple-timetable' ) . '</a>' );
		}
		return $facts;
	}

	/**
	 * Contact details an instructor chose to publish.
	 *
	 * @param WP_Post $post Instructor.
	 * @return array<int,array{0:string,1:string}> [label, HTML] pairs.
	 */
	private function instructor_facts( WP_Post $post ) {
		$facts   = array();
		$email   = (string) get_post_meta( $post->ID, Uaip_Timetable_Post_Types::META_EMAIL, true );
		$phone   = (string) get_post_meta( $post->ID, Uaip_Timetable_Post_Types::META_PHONE, true );
		$website = (string) get_post_meta( $post->ID, Uaip_Timetable_Post_Types::META_WEBSITE, true );
		if ( '' !== $email ) {
			$facts[] = array( __( 'Email', 'uaip-simple-timetable' ), '<a href="' . esc_url( 'mailto:' . antispambot( $email ) ) . '">' . esc_html( antispambot( $email ) ) . '</a>' );
		}
		if ( '' !== $phone ) {
			$facts[] = array( __( 'Phone', 'uaip-simple-timetable' ), '<a href="' . esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $phone ) ) . '">' . esc_html( $phone ) . '</a>' );
		}
		if ( '' !== $website ) {
			$facts[] = array( __( 'Website', 'uaip-simple-timetable' ), '<a href="' . esc_url( $website ) . '" rel="noopener">' . esc_html( preg_replace( '#^https?://#', '', $website ) ) . '</a>' );
		}
		return $facts;
	}

	/**
	 * Facts plus a list of upcoming sessions for a location or instructor.
	 *
	 * @param array<string,int[]>                    $filter Occurrence filter.
	 * @param array<int,array{0:string,1:string}> $facts  [label, HTML] pairs (already escaped).
	 * @return string
	 */
	private function related_box( array $filter, array $facts ) {
		$today = wp_date( 'Y-m-d' );
		$items = array_slice( Uaip_Timetable_Occurrences::between( $today, Uaip_Timetable_Occurrences::add_days( $today, 13 ), $filter ), 0, 20 );
		ob_start();
		?>
		<div class="uaip-tt-single">
			<?php if ( $facts ) : ?>
				<dl class="uaip-tt-single__facts">
					<?php foreach ( $facts as $fact ) : ?>
						<dt><?php echo esc_html( $fact[0] ); ?></dt>
						<dd><?php echo wp_kses_post( $fact[1] ); ?></dd>
					<?php endforeach; ?>
				</dl>
			<?php endif; ?>
			<?php if ( $items ) : ?>
				<h2 class="uaip-tt-single__heading"><?php esc_html_e( 'Coming up', 'uaip-simple-timetable' ); ?></h2>
				<ul class="uaip-tt-single__upcoming">
					<?php foreach ( $items as $item ) : ?>
						<li>
							<time datetime="<?php echo esc_attr( $item['start_iso'] ); ?>"><?php echo esc_html( Uaip_Timetable_Renderer::format_date( $item['date'], _x( 'D j M', 'timetable short date format', 'uaip-simple-timetable' ) ) . ', ' . Uaip_Timetable_Event::time_range( $item['start'], $item['end'] ) ); ?></time>
							<a href="<?php echo esc_url( '' !== $item['url'] ? $item['url'] : $item['permalink'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
