<?php
/**
 * Timetable → Settings screen.
 *
 * @package UaipSimpleTimetable
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings API screen for the `uaip_tt_settings` option.
 */
final class Uaip_Timetable_Settings_Page {

	const PAGE  = 'uaip-tt-settings';
	const GROUP = 'uaip_tt_settings_group';

	/** Set when public pages change, so the next request flushes rewrite rules. */
	const FLUSH_OPTION = 'uaip_tt_flush_rewrites';

	/**
	 * Hooks.
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_filter( 'plugin_action_links_' . UAIP_TT_BASENAME, array( $this, 'action_links' ) );
		add_action( 'update_option_' . Uaip_Timetable_Settings::OPTION, array( $this, 'maybe_schedule_flush' ), 10, 2 );
		add_action( 'add_option_' . Uaip_Timetable_Settings::OPTION, array( $this, 'schedule_flush' ) );
	}

	/**
	 * Flushes rewrite rules once after public pages were switched on or off. Runs late on
	 * init, after the post types registered with the new setting.
	 */
	public static function maybe_flush() {
		if ( get_option( self::FLUSH_OPTION ) ) {
			delete_option( self::FLUSH_OPTION );
			flush_rewrite_rules();
		}
	}

	/**
	 * Schedules a flush when the public-page settings changed.
	 *
	 * @param mixed $old_value Old value.
	 * @param mixed $value     New value.
	 */
	public function maybe_schedule_flush( $old_value, $value ) {
		$old = Uaip_Timetable_Settings::sanitize( is_array( $old_value ) ? $old_value : array() );
		$new = Uaip_Timetable_Settings::sanitize( is_array( $value ) ? $value : array() );
		if ( $old['public_locations'] !== $new['public_locations'] || $old['public_instructors'] !== $new['public_instructors'] ) {
			$this->schedule_flush();
		}
	}

	/**
	 * Marks rewrite rules for flushing on the next request.
	 */
	public function schedule_flush() {
		update_option( self::FLUSH_OPTION, 1, true );
	}

	/**
	 * Adds the submenu.
	 */
	public function menu() {
		add_submenu_page(
			'edit.php?post_type=' . Uaip_Timetable_Post_Types::EVENT,
			__( 'Timetable Settings', 'uaip-simple-timetable' ),
			__( 'Settings', 'uaip-simple-timetable' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$url = admin_url( 'edit.php?post_type=' . Uaip_Timetable_Post_Types::EVENT . '&page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'uaip-simple-timetable' ) . '</a>' );
		return $links;
	}

	/**
	 * Registers the setting, sections and fields.
	 */
	public function register() {
		register_setting(
			self::GROUP,
			Uaip_Timetable_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Uaip_Timetable_Settings', 'sanitize' ),
				'default'           => Uaip_Timetable_Settings::defaults(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section( 'uaip_tt_time', __( 'Dates and times', 'uaip-simple-timetable' ), '__return_false', self::PAGE );
		add_settings_field( 'time_format', __( 'Time format', 'uaip-simple-timetable' ), array( $this, 'field_time_format' ), self::PAGE, 'uaip_tt_time', array( 'label_for' => 'uaip-tt-time-format' ) );
		add_settings_field( 'week_start', __( 'Week starts on', 'uaip-simple-timetable' ), array( $this, 'field_week_start' ), self::PAGE, 'uaip_tt_time', array( 'label_for' => 'uaip-tt-week-start' ) );

		add_settings_section( 'uaip_tt_display', __( 'Timetable defaults', 'uaip-simple-timetable' ), array( $this, 'section_display' ), self::PAGE );
		add_settings_field( 'default_view', __( 'View', 'uaip-simple-timetable' ), array( $this, 'field_view' ), self::PAGE, 'uaip_tt_display', array( 'label_for' => 'uaip-tt-view' ) );
		add_settings_field( 'slot', __( 'Grid lines every', 'uaip-simple-timetable' ), array( $this, 'field_slot' ), self::PAGE, 'uaip_tt_display', array( 'label_for' => 'uaip-tt-slot' ) );
		add_settings_field( 'scheme', __( 'Colour scheme', 'uaip-simple-timetable' ), array( $this, 'field_scheme' ), self::PAGE, 'uaip_tt_display', array( 'label_for' => 'uaip-tt-scheme' ) );
		add_settings_field( 'list_days', __( 'List view shows', 'uaip-simple-timetable' ), array( $this, 'field_list_days' ), self::PAGE, 'uaip_tt_display', array( 'label_for' => 'uaip-tt-list-days' ) );

		add_settings_section( 'uaip_tt_pages', __( 'Pages and calendar feed', 'uaip-simple-timetable' ), '__return_false', self::PAGE );
		add_settings_field( 'public_pages', __( 'Public pages', 'uaip-simple-timetable' ), array( $this, 'field_public_pages' ), self::PAGE, 'uaip_tt_pages' );
		add_settings_field( 'ics', __( 'Calendar feed', 'uaip-simple-timetable' ), array( $this, 'field_ics' ), self::PAGE, 'uaip_tt_pages' );

		add_settings_section( 'uaip_tt_data', __( 'Data', 'uaip-simple-timetable' ), '__return_false', self::PAGE );
		add_settings_field( 'delete_data', __( 'When deleting the plugin', 'uaip-simple-timetable' ), array( $this, 'field_delete_data' ), self::PAGE, 'uaip_tt_data' );
	}

	/**
	 * Current settings.
	 *
	 * @return array<string,mixed>
	 */
	private function values() {
		return Uaip_Timetable_Settings::all();
	}

	/**
	 * Input name for a setting key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private function name( $key ) {
		return Uaip_Timetable_Settings::OPTION . '[' . $key . ']';
	}

	/**
	 * Prints a select.
	 *
	 * @param string               $id      Element ID.
	 * @param string               $key     Setting key.
	 * @param array<string,string> $options value => label.
	 * @param string               $help    Help text.
	 */
	private function select( $id, $key, array $options, $help = '' ) {
		$value = (string) $this->values()[ $key ];
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $this->name( $key ) ) . '"' . ( '' !== $help ? ' aria-describedby="' . esc_attr( $id . '-help' ) . '"' : '' ) . '>';
		foreach ( $options as $option => $label ) {
			echo '<option value="' . esc_attr( (string) $option ) . '"' . selected( $value, (string) $option, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		if ( '' !== $help ) {
			echo '<p class="description" id="' . esc_attr( $id . '-help' ) . '">' . esc_html( $help ) . '</p>';
		}
	}

	/**
	 * Prints a checkbox (with a hidden "0" so unticking is saved).
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 */
	private function checkbox( $key, $label ) {
		$id = 'uaip-tt-' . str_replace( '_', '-', $key );
		echo '<input type="hidden" name="' . esc_attr( $this->name( $key ) ) . '" value="0">';
		echo '<label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $this->name( $key ) ) . '" value="1"' . checked( ! empty( $this->values()[ $key ] ), true, false ) . '> ' . esc_html( $label ) . '</label>';
	}

	/**
	 * Time format field.
	 */
	public function field_time_format() {
		$wp = (string) get_option( 'time_format' );
		$this->select(
			'uaip-tt-time-format',
			'time_format',
			array(
				/* translators: %s: the current WordPress setting, e.g. a time in its format or a weekday */
				''   => sprintf( __( 'Same as WordPress (%s)', 'uaip-simple-timetable' ), wp_date( '' !== $wp ? $wp : 'H:i', gmmktime( 14, 30, 0, 1, 1, 2026 ), new DateTimeZone( 'UTC' ) ) ),
				'24' => __( '24-hour (14:30)', 'uaip-simple-timetable' ),
				'12' => __( '12-hour (2:30 pm)', 'uaip-simple-timetable' ),
			)
		);
	}

	/**
	 * Week start field.
	 */
	public function field_week_start() {
		/* translators: %s: the current WordPress setting, e.g. a time in its format or a weekday */
		$options = array( '' => sprintf( __( 'Same as WordPress (%s)', 'uaip-simple-timetable' ), Uaip_Timetable_Event::weekday_name( (int) get_option( 'start_of_week', 1 ) ) ) );
		for ( $day = 0; $day < 7; $day++ ) {
			$options[ (string) $day ] = Uaip_Timetable_Event::weekday_name( $day );
		}
		$this->select( 'uaip-tt-week-start', 'week_start', $options );
	}

	/**
	 * Intro of the display section.
	 */
	public function section_display() {
		echo '<p>' . esc_html__( 'Used when a timetable block or shortcode does not set its own.', 'uaip-simple-timetable' ) . '</p>';
	}

	/**
	 * Default view field.
	 */
	public function field_view() {
		$options = array();
		foreach ( Uaip_Timetable_Settings::VIEWS as $view ) {
			$options[ $view ] = Uaip_Timetable_Renderer::view_label( $view );
		}
		$this->select( 'uaip-tt-view', 'default_view', $options );
	}

	/**
	 * Grid line interval field.
	 */
	public function field_slot() {
		$this->select(
			'uaip-tt-slot',
			'slot',
			array(
				'15' => __( '15 minutes', 'uaip-simple-timetable' ),
				'30' => __( '30 minutes', 'uaip-simple-timetable' ),
				'60' => __( '1 hour', 'uaip-simple-timetable' ),
			),
			__( 'Horizontal lines in the week and day grid.', 'uaip-simple-timetable' )
		);
	}

	/**
	 * Colour scheme field.
	 */
	public function field_scheme() {
		$this->select(
			'uaip-tt-scheme',
			'scheme',
			array(
				'default' => __( 'Default', 'uaip-simple-timetable' ),
				'modern'  => __( 'Modern', 'uaip-simple-timetable' ),
				'minimal' => __( 'Minimal', 'uaip-simple-timetable' ),
				'dark'    => __( 'Dark', 'uaip-simple-timetable' ),
				'warm'    => __( 'Warm', 'uaip-simple-timetable' ),
			)
		);
	}

	/**
	 * List length field.
	 */
	public function field_list_days() {
		$value = (int) $this->values()['list_days'];
		printf(
			'<input type="number" id="uaip-tt-list-days" name="%1$s" value="%2$d" min="1" max="90" class="small-text"> %3$s',
			esc_attr( $this->name( 'list_days' ) ),
			(int) $value,
			esc_html__( 'days', 'uaip-simple-timetable' )
		);
	}

	/**
	 * Public location/instructor pages.
	 */
	public function field_public_pages() {
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Public pages', 'uaip-simple-timetable' ) . '</legend>';
		$this->checkbox( 'public_locations', __( 'Give each location its own page', 'uaip-simple-timetable' ) );
		echo '<br>';
		$this->checkbox( 'public_instructors', __( 'Give each instructor their own page', 'uaip-simple-timetable' ) );
		echo '<p class="description">' . esc_html__( 'Their pages show the description, contact details you entered and the classes coming up. Names in timetables then link to them. Events always have their own page.', 'uaip-simple-timetable' ) . '</p>';
		echo '</fieldset>';
	}

	/**
	 * iCal feed toggle.
	 */
	public function field_ics() {
		$this->checkbox( 'ics', __( 'Let visitors subscribe to the timetable in their calendar app', 'uaip-simple-timetable' ) );
		if ( $this->values()['ics'] ) {
			echo '<p class="description">' . esc_html__( 'Feed address:', 'uaip-simple-timetable' ) . ' <code>' . esc_html( Uaip_Timetable_Ics::feed_url() ) . '</code></p>';
		}
	}

	/**
	 * Uninstall cleanup toggle.
	 */
	public function field_delete_data() {
		$this->checkbox( 'delete_data', __( 'Also delete all events, locations, instructors, categories and these settings', 'uaip-simple-timetable' ) );
		echo '<p class="description">' . esc_html__( 'Leave this off if you might install the plugin again: your timetable is then kept.', 'uaip-simple-timetable' ) . '</p>';
	}

	/**
	 * The screen.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$events = admin_url( 'post-new.php?post_type=' . Uaip_Timetable_Post_Types::EVENT );
		?>
		<div class="wrap uaip-tt-settings">
			<h1><?php esc_html_e( 'Timetable Settings', 'uaip-simple-timetable' ); ?></h1>
			<?php settings_errors(); ?>
			<div class="uaip-tt-settings__layout">
				<form method="post" action="options.php" class="uaip-tt-settings__form">
					<?php
					settings_fields( self::GROUP );
					do_settings_sections( self::PAGE );
					submit_button();
					?>
				</form>
				<aside class="uaip-tt-settings__help" aria-labelledby="uaip-tt-help-title">
					<h2 id="uaip-tt-help-title"><?php esc_html_e( 'Getting started', 'uaip-simple-timetable' ); ?></h2>
					<ol>
						<li><?php esc_html_e( 'Add categories (with colours), locations and instructors under Timetable. All optional.', 'uaip-simple-timetable' ); ?></li>
						<li>
							<?php
							printf(
								/* translators: %s: "Add Event" link */
								esc_html__( '%s: set the first date, the times and the days it repeats on.', 'uaip-simple-timetable' ),
								'<a href="' . esc_url( $events ) . '">' . esc_html__( 'Add an event', 'uaip-simple-timetable' ) . '</a>'
							);
							?>
						</li>
						<li><?php esc_html_e( 'Add the Timetable block to a page, or the shortcode below.', 'uaip-simple-timetable' ); ?></li>
					</ol>
					<p><code>[uaip_timetable]</code></p>
					<p><code>[uaip_timetable views="week,month,list" category="yoga"]</code></p>
					<p><?php esc_html_e( 'The Upcoming Classes block (or widget) lists the next few classes, e.g. on your home page.', 'uaip-simple-timetable' ); ?></p>
				</aside>
			</div>
		</div>
		<?php
	}
}
