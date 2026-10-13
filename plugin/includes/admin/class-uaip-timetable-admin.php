<?php
/**
 * Admin screens: event schedule fields (block editor panel + classic meta box fallback),
 * location and instructor details, category colours and list columns.
 *
 * @package UaipSimpleTimetable
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin hooks.
 */
final class Uaip_Timetable_Admin {

	const NONCE = 'uaip_tt_meta';

	/**
	 * Hooks.
	 */
	public function register_hooks() {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_panel' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . Uaip_Timetable_Post_Types::EVENT, array( $this, 'save_event' ) );
		add_action( 'save_post_' . Uaip_Timetable_Post_Types::LOCATION, array( $this, 'save_location' ) );
		add_action( 'save_post_' . Uaip_Timetable_Post_Types::INSTRUCTOR, array( $this, 'save_instructor' ) );

		add_filter( 'manage_' . Uaip_Timetable_Post_Types::EVENT . '_posts_columns', array( $this, 'event_columns' ) );
		add_action( 'manage_' . Uaip_Timetable_Post_Types::EVENT . '_posts_custom_column', array( $this, 'event_column' ), 10, 2 );

		$tax = Uaip_Timetable_Post_Types::CATEGORY;
		add_action( $tax . '_add_form_fields', array( $this, 'category_add_field' ) );
		add_action( $tax . '_edit_form_fields', array( $this, 'category_edit_field' ) );
		add_action( 'created_' . $tax, array( $this, 'save_category' ) );
		add_action( 'edited_' . $tax, array( $this, 'save_category' ) );
		add_filter( 'manage_edit-' . $tax . '_columns', array( $this, 'category_columns' ) );
		add_filter( 'manage_' . $tax . '_custom_column', array( $this, 'category_column' ), 10, 3 );
	}

	/**
	 * The schedule panel in the block editor (events only).
	 */
	public function enqueue_editor_panel() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || Uaip_Timetable_Post_Types::EVENT !== $screen->post_type ) {
			return;
		}
		wp_enqueue_script( Uaip_Timetable_Assets::EDITOR );
		wp_enqueue_style( Uaip_Timetable_Assets::ADMIN );
		wp_add_inline_script(
			Uaip_Timetable_Assets::EDITOR,
			'window.uaipTimetableEditor = ' . wp_json_encode( $this->editor_data() ) . ';',
			'before'
		);
	}

	/**
	 * Data for the editor panel and the classic meta box script.
	 *
	 * @return array<string,mixed>
	 */
	private function editor_data() {
		$options = static function ( $choices ) {
			$out = array();
			foreach ( $choices as $id => $title ) {
				$out[] = array(
					'value' => $id,
					'label' => $title,
				);
			}
			return $out;
		};
		$days = array();
		$week = Uaip_Timetable_Settings::week_start();
		for ( $i = 0; $i < 7; $i++ ) {
			$day    = ( $week + $i ) % 7;
			$days[] = array(
				'value' => $day,
				'label' => Uaip_Timetable_Event::weekday_name( $day ),
			);
		}
		return array(
			'locations'      => $options( Uaip_Timetable_Post_Types::choices( Uaip_Timetable_Post_Types::LOCATION ) ),
			'instructors'    => $options( Uaip_Timetable_Post_Types::choices( Uaip_Timetable_Post_Types::INSTRUCTOR ) ),
			'days'           => $days,
			'maxInterval'    => Uaip_Timetable_Event::MAX_INTERVAL,
			'today'          => wp_date( 'Y-m-d' ),
			'newLocation'    => admin_url( 'post-new.php?post_type=' . Uaip_Timetable_Post_Types::LOCATION ),
			'newInstructor'  => admin_url( 'post-new.php?post_type=' . Uaip_Timetable_Post_Types::INSTRUCTOR ),
			'categoriesPage' => admin_url( 'edit-tags.php?taxonomy=' . Uaip_Timetable_Post_Types::CATEGORY . '&post_type=' . Uaip_Timetable_Post_Types::EVENT ),
		);
	}

	/**
	 * Colour pickers and meta box helpers on our classic screens.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function enqueue_admin( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		$ours = in_array( $screen->post_type, array( Uaip_Timetable_Post_Types::EVENT, Uaip_Timetable_Post_Types::LOCATION, Uaip_Timetable_Post_Types::INSTRUCTOR ), true );
		if ( ! $ours && Uaip_Timetable_Post_Types::CATEGORY !== $screen->taxonomy ) {
			return;
		}
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php', 'edit-tags.php', 'term.php', 'edit.php' ), true ) ) {
			return;
		}
		wp_enqueue_style( Uaip_Timetable_Assets::ADMIN );
		wp_enqueue_script( Uaip_Timetable_Assets::ADMIN );
	}

	/**
	 * Meta boxes. The event schedule box is a fallback for the classic editor only; the block
	 * editor uses the sidebar panel instead.
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'uaip-tt-schedule',
			__( 'Schedule', 'uaip-simple-timetable' ),
			array( $this, 'event_box' ),
			Uaip_Timetable_Post_Types::EVENT,
			'normal',
			'high',
			array( '__back_compat_meta_box' => true )
		);
		add_meta_box(
			'uaip-tt-location',
			__( 'Location details', 'uaip-simple-timetable' ),
			array( $this, 'location_box' ),
			Uaip_Timetable_Post_Types::LOCATION,
			'normal',
			'high'
		);
		add_meta_box(
			'uaip-tt-instructor',
			__( 'Contact details', 'uaip-simple-timetable' ),
			array( $this, 'instructor_box' ),
			Uaip_Timetable_Post_Types::INSTRUCTOR,
			'normal',
			'high'
		);
	}

	/**
	 * Classic editor schedule fields.
	 *
	 * @param WP_Post $post Event.
	 */
	public function event_box( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		$is_new = 'auto-draft' === $post->post_status;
		$rule   = Uaip_Timetable_Event::rule( $post->ID );
		if ( $is_new && '' === $rule['date'] ) {
			$rule['date'] = wp_date( 'Y-m-d' );
		}
		$location    = (int) get_post_meta( $post->ID, Uaip_Timetable_Event::META_LOCATION, true );
		$instructors = get_post_meta( $post->ID, Uaip_Timetable_Event::META_INSTRUCTORS, true );
		$instructors = is_array( $instructors ) ? array_map( 'intval', $instructors ) : array();
		$color       = (string) get_post_meta( $post->ID, Uaip_Timetable_Event::META_COLOR, true );
		$url         = (string) get_post_meta( $post->ID, Uaip_Timetable_Event::META_URL, true );
		$data        = $this->editor_data();
		$problem     = $is_new ? '' : Uaip_Timetable_Event::rule_problem( $rule );
		?>
		<div class="uaip-tt-box">
			<?php if ( '' !== $problem ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( $problem ); ?> <?php esc_html_e( 'The event is not shown in timetables until this is fixed.', 'uaip-simple-timetable' ); ?></p></div>
			<?php endif; ?>
			<p class="uaip-tt-row">
				<label><?php esc_html_e( 'Date', 'uaip-simple-timetable' ); ?><br>
					<input type="date" name="uaip_tt[date]" value="<?php echo esc_attr( $rule['date'] ); ?>" required></label>
				<label><?php esc_html_e( 'Start', 'uaip-simple-timetable' ); ?><br>
					<input type="time" name="uaip_tt[start]" value="<?php echo esc_attr( $rule['start'] ); ?>" required></label>
				<label><?php esc_html_e( 'End', 'uaip-simple-timetable' ); ?><br>
					<input type="time" name="uaip_tt[end]" value="<?php echo esc_attr( $rule['end'] ); ?>" required></label>
			</p>
			<fieldset class="uaip-tt-row">
				<legend class="screen-reader-text"><?php esc_html_e( 'Repeat', 'uaip-simple-timetable' ); ?></legend>
				<label><input type="radio" name="uaip_tt[repeat]" value="weekly" <?php checked( $rule['repeat'], 'weekly' ); ?>> <?php esc_html_e( 'Repeats weekly', 'uaip-simple-timetable' ); ?></label>
				<label><input type="radio" name="uaip_tt[repeat]" value="none" <?php checked( $rule['repeat'], 'none' ); ?>> <?php esc_html_e( 'Happens once', 'uaip-simple-timetable' ); ?></label>
			</fieldset>
			<div class="uaip-tt-weekly" data-uaip-tt-weekly>
				<fieldset class="uaip-tt-row uaip-tt-days">
					<legend><?php esc_html_e( 'On', 'uaip-simple-timetable' ); ?></legend>
					<?php foreach ( $data['days'] as $day ) : ?>
						<label><input type="checkbox" name="uaip_tt[days][]" value="<?php echo esc_attr( (string) $day['value'] ); ?>" <?php checked( in_array( $day['value'], $rule['days'], true ) ); ?>> <?php echo esc_html( $day['label'] ); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<p class="description"><?php esc_html_e( 'Leave all unticked to repeat on the weekday of the first date.', 'uaip-simple-timetable' ); ?></p>
				<p class="uaip-tt-row">
					<label><?php esc_html_e( 'Every', 'uaip-simple-timetable' ); ?>
						<input type="number" name="uaip_tt[interval]" value="<?php echo esc_attr( (string) $rule['interval'] ); ?>" min="1" max="<?php echo esc_attr( (string) Uaip_Timetable_Event::MAX_INTERVAL ); ?>" class="small-text">
						<?php esc_html_e( 'week(s)', 'uaip-simple-timetable' ); ?></label>
					<label><?php esc_html_e( 'Until (optional)', 'uaip-simple-timetable' ); ?>
						<input type="date" name="uaip_tt[until]" value="<?php echo esc_attr( $rule['until'] ); ?>"></label>
				</p>
				<p>
					<label for="uaip-tt-exdates"><?php esc_html_e( 'Cancelled dates', 'uaip-simple-timetable' ); ?></label><br>
					<textarea id="uaip-tt-exdates" name="uaip_tt[exdates]" rows="2" class="large-text" placeholder="2026-12-25, 2027-01-01"><?php echo esc_textarea( implode( ', ', $rule['exdates'] ) ); ?></textarea>
					<span class="description"><?php esc_html_e( 'Dates (YYYY-MM-DD) when this event does not take place, separated by commas.', 'uaip-simple-timetable' ); ?></span>
				</p>
			</div>
			<hr>
			<p class="uaip-tt-row">
				<label><?php esc_html_e( 'Location', 'uaip-simple-timetable' ); ?><br>
					<select name="uaip_tt[location]">
						<option value="0"><?php esc_html_e( '— None —', 'uaip-simple-timetable' ); ?></option>
						<?php foreach ( $data['locations'] as $option ) : ?>
							<option value="<?php echo esc_attr( (string) $option['value'] ); ?>" <?php selected( $location, $option['value'] ); ?>><?php echo esc_html( $option['label'] ); ?></option>
						<?php endforeach; ?>
					</select></label>
				<a href="<?php echo esc_url( $data['newLocation'] ); ?>"><?php esc_html_e( 'Add location', 'uaip-simple-timetable' ); ?></a>
			</p>
			<fieldset class="uaip-tt-row">
				<legend><?php esc_html_e( 'Instructors', 'uaip-simple-timetable' ); ?></legend>
				<?php if ( ! $data['instructors'] ) : ?>
					<span class="description"><?php esc_html_e( 'No instructors yet.', 'uaip-simple-timetable' ); ?></span>
				<?php endif; ?>
				<?php foreach ( $data['instructors'] as $option ) : ?>
					<label><input type="checkbox" name="uaip_tt[instructors][]" value="<?php echo esc_attr( (string) $option['value'] ); ?>" <?php checked( in_array( $option['value'], $instructors, true ) ); ?>> <?php echo esc_html( $option['label'] ); ?></label>
				<?php endforeach; ?>
				<a href="<?php echo esc_url( $data['newInstructor'] ); ?>"><?php esc_html_e( 'Add instructor', 'uaip-simple-timetable' ); ?></a>
			</fieldset>
			<p class="uaip-tt-row">
				<label><?php esc_html_e( 'Colour', 'uaip-simple-timetable' ); ?><br>
					<input type="text" name="uaip_tt[color]" value="<?php echo esc_attr( $color ); ?>" class="uaip-tt-color" data-default-color=""></label>
				<span class="description"><?php esc_html_e( 'Leave empty to use the category colour.', 'uaip-simple-timetable' ); ?></span>
			</p>
			<p>
				<label for="uaip-tt-url"><?php esc_html_e( 'Link (optional)', 'uaip-simple-timetable' ); ?></label><br>
				<input type="url" id="uaip-tt-url" name="uaip_tt[url]" value="<?php echo esc_attr( $url ); ?>" class="large-text" placeholder="https://">
				<span class="description"><?php esc_html_e( 'Where "More details" goes, e.g. a booking page. Leave empty to use this event\'s own page.', 'uaip-simple-timetable' ); ?></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Saves the classic schedule fields. The block editor saves through the REST API instead
	 * (our nonce is not present there).
	 *
	 * @param int $post_id Event ID.
	 */
	public function save_event( $post_id ) {
		$input = $this->verified_input( $post_id );
		if ( null === $input ) {
			return;
		}
		$map = array(
			'date'        => Uaip_Timetable_Event::META_DATE,
			'start'       => Uaip_Timetable_Event::META_START,
			'end'         => Uaip_Timetable_Event::META_END,
			'repeat'      => Uaip_Timetable_Event::META_REPEAT,
			'interval'    => Uaip_Timetable_Event::META_INTERVAL,
			'days'        => Uaip_Timetable_Event::META_DAYS,
			'until'       => Uaip_Timetable_Event::META_UNTIL,
			'exdates'     => Uaip_Timetable_Event::META_EXDATES,
			'location'    => Uaip_Timetable_Event::META_LOCATION,
			'instructors' => Uaip_Timetable_Event::META_INSTRUCTORS,
			'color'       => Uaip_Timetable_Event::META_COLOR,
			'url'         => Uaip_Timetable_Event::META_URL,
		);
		foreach ( $map as $field => $key ) {
			$value = isset( $input[ $field ] ) ? $input[ $field ] : '';
			if ( in_array( $field, array( 'days', 'instructors' ), true ) && '' === $value ) {
				$value = array();
			}
			// update_post_meta() runs the registered sanitize callback.
			update_post_meta( $post_id, $key, is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : sanitize_text_field( $value ) );
		}
	}

	/**
	 * Location fields.
	 *
	 * @param WP_Post $post Location.
	 */
	public function location_box( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		$address = (string) get_post_meta( $post->ID, Uaip_Timetable_Post_Types::META_ADDRESS, true );
		$map     = (string) get_post_meta( $post->ID, Uaip_Timetable_Post_Types::META_MAP_URL, true );
		?>
		<p>
			<label for="uaip-tt-address"><?php esc_html_e( 'Address', 'uaip-simple-timetable' ); ?></label><br>
			<textarea id="uaip-tt-address" name="uaip_tt[address]" rows="3" class="large-text"><?php echo esc_textarea( $address ); ?></textarea>
		</p>
		<p>
			<label for="uaip-tt-map"><?php esc_html_e( 'Map link', 'uaip-simple-timetable' ); ?></label><br>
			<input type="url" id="uaip-tt-map" name="uaip_tt[map_url]" value="<?php echo esc_attr( $map ); ?>" class="large-text" placeholder="https://">
			<span class="description"><?php esc_html_e( 'Optional link to a map of this place. Nothing is loaded from map services unless a visitor clicks it.', 'uaip-simple-timetable' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Saves location fields.
	 *
	 * @param int $post_id Location ID.
	 */
	public function save_location( $post_id ) {
		$input = $this->verified_input( $post_id );
		if ( null === $input ) {
			return;
		}
		update_post_meta( $post_id, Uaip_Timetable_Post_Types::META_ADDRESS, sanitize_textarea_field( isset( $input['address'] ) ? $input['address'] : '' ) );
		update_post_meta( $post_id, Uaip_Timetable_Post_Types::META_MAP_URL, esc_url_raw( isset( $input['map_url'] ) ? $input['map_url'] : '' ) );
	}

	/**
	 * Instructor fields.
	 *
	 * @param WP_Post $post Instructor.
	 */
	public function instructor_box( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		$fields = array(
			'email'   => array( Uaip_Timetable_Post_Types::META_EMAIL, __( 'Email', 'uaip-simple-timetable' ), 'email' ),
			'phone'   => array( Uaip_Timetable_Post_Types::META_PHONE, __( 'Phone', 'uaip-simple-timetable' ), 'tel' ),
			'website' => array( Uaip_Timetable_Post_Types::META_WEBSITE, __( 'Website', 'uaip-simple-timetable' ), 'url' ),
		);
		?>
		<p class="description"><?php esc_html_e( 'Shown on the instructor\'s page when instructor pages are public. Leave a field empty to keep it private.', 'uaip-simple-timetable' ); ?></p>
		<?php foreach ( $fields as $name => $field ) : ?>
			<p>
				<label for="<?php echo esc_attr( 'uaip-tt-' . $name ); ?>"><?php echo esc_html( $field[1] ); ?></label><br>
				<input type="<?php echo esc_attr( $field[2] ); ?>" id="<?php echo esc_attr( 'uaip-tt-' . $name ); ?>" name="<?php echo esc_attr( 'uaip_tt[' . $name . ']' ); ?>"
					value="<?php echo esc_attr( (string) get_post_meta( $post->ID, $field[0], true ) ); ?>" class="regular-text">
			</p>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Saves instructor fields.
	 *
	 * @param int $post_id Instructor ID.
	 */
	public function save_instructor( $post_id ) {
		$input = $this->verified_input( $post_id );
		if ( null === $input ) {
			return;
		}
		update_post_meta( $post_id, Uaip_Timetable_Post_Types::META_EMAIL, sanitize_email( isset( $input['email'] ) ? $input['email'] : '' ) );
		update_post_meta( $post_id, Uaip_Timetable_Post_Types::META_PHONE, sanitize_text_field( isset( $input['phone'] ) ? $input['phone'] : '' ) );
		update_post_meta( $post_id, Uaip_Timetable_Post_Types::META_WEBSITE, esc_url_raw( isset( $input['website'] ) ? $input['website'] : '' ) );
	}

	/**
	 * Our posted fields when the nonce and capability check pass, otherwise null.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>|null Unslashed input (sanitised by the caller).
	 */
	private function verified_input( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE . '_nonce' ], $_POST['uaip_tt'] ) || ! is_array( $_POST['uaip_tt'] ) ) {
			return null;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ), self::NONCE ) ) {
			return null;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitised by its caller.
		return wp_unslash( $_POST['uaip_tt'] );
	}

	/**
	 * Event list columns.
	 *
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public function event_columns( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['uaip_tt_schedule']    = __( 'Schedule', 'uaip-simple-timetable' );
				$out['uaip_tt_location']    = __( 'Location', 'uaip-simple-timetable' );
				$out['uaip_tt_instructors'] = __( 'Instructors', 'uaip-simple-timetable' );
			}
		}
		return $out;
	}

	/**
	 * Event list column values.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Event ID.
	 */
	public function event_column( $column, $post_id ) {
		switch ( $column ) {
			case 'uaip_tt_schedule':
				$rule    = Uaip_Timetable_Event::rule( $post_id );
				$problem = Uaip_Timetable_Event::rule_problem( $rule );
				if ( '' !== $problem ) {
					echo '<span class="uaip-tt-problem">' . esc_html( $problem ) . '</span>';
					return;
				}
				$color = Uaip_Timetable_Event::sanitize_color( get_post_meta( $post_id, Uaip_Timetable_Event::META_COLOR, true ) );
				if ( '' !== $color ) {
					echo '<span class="uaip-tt-swatch" style="' . esc_attr( 'background:' . $color ) . '"></span> ';
				}
				echo esc_html( Uaip_Timetable_Event::summary( $rule ) );
				if ( $rule['exdates'] ) {
					echo '<br><span class="description">';
					/* translators: %d: number of cancelled dates */
					echo esc_html( sprintf( _n( '%d cancelled date', '%d cancelled dates', count( $rule['exdates'] ), 'uaip-simple-timetable' ), count( $rule['exdates'] ) ) );
					echo '</span>';
				}
				break;
			case 'uaip_tt_location':
				$id = (int) get_post_meta( $post_id, Uaip_Timetable_Event::META_LOCATION, true );
				echo $id ? esc_html( get_the_title( $id ) ) : '<span aria-hidden="true">—</span>';
				break;
			case 'uaip_tt_instructors':
				$ids   = get_post_meta( $post_id, Uaip_Timetable_Event::META_INSTRUCTORS, true );
				$names = array();
				foreach ( is_array( $ids ) ? $ids : array() as $id ) {
					$names[] = get_the_title( (int) $id );
				}
				echo $names ? esc_html( implode( ', ', $names ) ) : '<span aria-hidden="true">—</span>';
				break;
		}
	}

	/**
	 * Colour field on the "Add category" form.
	 */
	public function category_add_field() {
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		?>
		<div class="form-field">
			<label for="uaip-tt-term-color"><?php esc_html_e( 'Colour', 'uaip-simple-timetable' ); ?></label>
			<input type="text" id="uaip-tt-term-color" name="uaip_tt_term_color" value="" class="uaip-tt-color">
			<p><?php esc_html_e( 'Events in this category use this colour unless they set their own.', 'uaip-simple-timetable' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Colour field on the "Edit category" form.
	 *
	 * @param WP_Term $term Term.
	 */
	public function category_edit_field( $term ) {
		$color = (string) get_term_meta( $term->term_id, Uaip_Timetable_Post_Types::TERM_COLOR, true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="uaip-tt-term-color"><?php esc_html_e( 'Colour', 'uaip-simple-timetable' ); ?></label></th>
			<td>
				<?php wp_nonce_field( self::NONCE, self::NONCE . '_nonce' ); ?>
				<input type="text" id="uaip-tt-term-color" name="uaip_tt_term_color" value="<?php echo esc_attr( $color ); ?>" class="uaip-tt-color">
				<p class="description"><?php esc_html_e( 'Events in this category use this colour unless they set their own.', 'uaip-simple-timetable' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Saves the category colour.
	 *
	 * @param int $term_id Term ID.
	 */
	public function save_category( $term_id ) {
		if ( ! isset( $_POST[ self::NONCE . '_nonce' ], $_POST['uaip_tt_term_color'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ), self::NONCE ) || ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}
		$color = Uaip_Timetable_Event::sanitize_color( sanitize_text_field( wp_unslash( $_POST['uaip_tt_term_color'] ) ) );
		if ( '' === $color ) {
			delete_term_meta( $term_id, Uaip_Timetable_Post_Types::TERM_COLOR );
		} else {
			update_term_meta( $term_id, Uaip_Timetable_Post_Types::TERM_COLOR, $color );
		}
		Uaip_Timetable_Occurrences::invalidate();
	}

	/**
	 * Category list columns.
	 *
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public function category_columns( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			if ( 'name' === $key ) {
				$out['uaip_tt_color'] = '<span class="screen-reader-text">' . esc_html__( 'Colour', 'uaip-simple-timetable' ) . '</span>';
			}
			$out[ $key ] = $label;
		}
		return $out;
	}

	/**
	 * Category colour column.
	 *
	 * @param string $content Column content.
	 * @param string $column  Column key.
	 * @param int    $term_id Term ID.
	 * @return string
	 */
	public function category_column( $content, $column, $term_id ) {
		if ( 'uaip_tt_color' !== $column ) {
			return $content;
		}
		$color = Uaip_Timetable_Event::sanitize_color( get_term_meta( $term_id, Uaip_Timetable_Post_Types::TERM_COLOR, true ) );
		return '' !== $color ? '<span class="uaip-tt-swatch" style="' . esc_attr( 'background:' . $color ) . '"></span>' : '';
	}
}
