<?php
/**
 * Classic "Upcoming Classes" widget, for themes and sites that still use widget areas
 * without blocks. Shares its output with the Upcoming Classes block.
 *
 * @package UaipSimpleTimetable
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Upcoming classes widget.
 */
class Uaip_Timetable_Widget extends WP_Widget {

	/**
	 * Sets up the widget.
	 */
	public function __construct() {
		parent::__construct(
			'uaip_tt_upcoming',
			__( 'Upcoming Classes', 'uaip-simple-timetable' ),
			array(
				'description'           => __( 'The next classes or events from your timetable.', 'uaip-simple-timetable' ),
				'classname'             => 'uaip-tt-widget',
				'show_instance_in_rest' => true,
			)
		);
	}

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	private function defaults() {
		return array(
			'title'           => __( 'Upcoming classes', 'uaip-simple-timetable' ),
			'count'           => 5,
			'category'        => '',
			'show_location'   => true,
			'show_instructor' => false,
		);
	}

	/**
	 * Front-end output.
	 *
	 * @param array<string,mixed> $args     Widget area arguments.
	 * @param array<string,mixed> $instance Settings.
	 */
	public function widget( $args, $instance ) {
		$instance = wp_parse_args( (array) $instance, $this->defaults() );
		$title    = apply_filters( 'widget_title', (string) $instance['title'], $instance, $this->id_base );
		Uaip_Timetable_Assets::enqueue_front( false );

		echo wp_kses_post( $args['before_widget'] );
		if ( '' !== $title ) {
			echo wp_kses_post( $args['before_title'] ) . esc_html( $title ) . wp_kses_post( $args['after_title'] );
		}
		$html = Uaip_Timetable_Renderer::upcoming(
			array(
				'count'           => (int) $instance['count'],
				'days'            => 14,
				'category'        => (string) $instance['category'],
				'show_location'   => ! empty( $instance['show_location'] ),
				'show_instructor' => ! empty( $instance['show_instructor'] ),
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in Uaip_Timetable_Renderer::upcoming().
		echo wp_kses_post( $args['after_widget'] );
	}

	/**
	 * Settings form.
	 *
	 * @param array<string,mixed> $instance Settings.
	 * @return string
	 */
	public function form( $instance ) {
		$instance = wp_parse_args( (array) $instance, $this->defaults() );
		$terms    = get_terms(
			array(
				'taxonomy'   => Uaip_Timetable_Post_Types::CATEGORY,
				'hide_empty' => false,
			)
		);
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'uaip-simple-timetable' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( (string) $instance['title'] ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>"><?php esc_html_e( 'Number of classes:', 'uaip-simple-timetable' ); ?></label>
			<input class="tiny-text" id="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'count' ) ); ?>" type="number" min="1" max="20" value="<?php echo esc_attr( (string) (int) $instance['count'] ); ?>">
		</p>
		<?php if ( is_array( $terms ) && $terms ) : ?>
			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'category' ) ); ?>"><?php esc_html_e( 'Category:', 'uaip-simple-timetable' ); ?></label>
				<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'category' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'category' ) ); ?>">
					<option value=""><?php esc_html_e( 'All categories', 'uaip-simple-timetable' ); ?></option>
					<?php foreach ( $terms as $term ) : ?>
						<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( (string) $instance['category'], $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
		<?php endif; ?>
		<p>
			<input class="checkbox" type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'show_location' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'show_location' ) ); ?>" <?php checked( ! empty( $instance['show_location'] ) ); ?>>
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_location' ) ); ?>"><?php esc_html_e( 'Show location', 'uaip-simple-timetable' ); ?></label><br>
			<input class="checkbox" type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'show_instructor' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'show_instructor' ) ); ?>" <?php checked( ! empty( $instance['show_instructor'] ) ); ?>>
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_instructor' ) ); ?>"><?php esc_html_e( 'Show instructors', 'uaip-simple-timetable' ); ?></label>
		</p>
		<?php
		return '';
	}

	/**
	 * Sanitises settings on save.
	 *
	 * @param array<string,mixed> $new_instance New settings.
	 * @param array<string,mixed> $old_instance Old settings.
	 * @return array<string,mixed>
	 */
	public function update( $new_instance, $old_instance ) {
		return array(
			'title'           => sanitize_text_field( isset( $new_instance['title'] ) ? $new_instance['title'] : '' ),
			'count'           => min( 20, max( 1, (int) ( isset( $new_instance['count'] ) ? $new_instance['count'] : 5 ) ) ),
			'category'        => sanitize_title( isset( $new_instance['category'] ) ? $new_instance['category'] : '' ),
			'show_location'   => ! empty( $new_instance['show_location'] ),
			'show_instructor' => ! empty( $new_instance['show_instructor'] ),
		);
	}
}
