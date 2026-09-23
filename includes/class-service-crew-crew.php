<?php
/**
 * Registers the sc_crew custom post type and its meta boxes (type, contact,
 * address/ZIP, coverage radius, weekly availability, time off, photo).
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * sc_crew holds both employees and vendors in one record shape (a `type`
 * meta field distinguishes them), per the plan's "one record shape" rule —
 * there is no separate vendor post type. Not publicly queryable, same as
 * sc_service: crew records are read by the admin UI and, later, by an
 * admin-gated REST controller (a separate Phase 1a task), never by a public
 * archive/single template.
 *
 * Metadata-only in this task: login provisioning (the "App access" box,
 * wp_users account, Application Passwords) arrives in Phase 1d and attaches
 * to this same CPT rather than replacing it.
 *
 * Geocoding (lat/lng auto-populated from address+ZIP) is a separate,
 * not-yet-built task (class-service-crew-geocoding.php). Lat/lng are plain
 * editable meta fields here so the record shape round-trips end to end;
 * once the geocoding wrapper exists it hooks into save_post_sc_crew to fill
 * them in rather than replacing this meta box.
 */
class Service_Crew_Crew {

	/**
	 * Meta key for crew type: 'employee' or 'vendor'.
	 *
	 * @var string
	 */
	const META_TYPE = '_sc_crew_type';

	/**
	 * Meta key for phone number.
	 *
	 * @var string
	 */
	const META_PHONE = '_sc_crew_phone';

	/**
	 * Meta key for email address.
	 *
	 * @var string
	 */
	const META_EMAIL = '_sc_crew_email';

	/**
	 * Meta key for street address.
	 *
	 * @var string
	 */
	const META_ADDRESS = '_sc_crew_address';

	/**
	 * Meta key for ZIP/postal code.
	 *
	 * @var string
	 */
	const META_ZIP = '_sc_crew_zip';

	/**
	 * Meta key for geocoded latitude.
	 *
	 * @var string
	 */
	const META_LAT = '_sc_crew_lat';

	/**
	 * Meta key for geocoded longitude.
	 *
	 * @var string
	 */
	const META_LNG = '_sc_crew_lng';

	/**
	 * Meta key for coverage radius in miles. Empty/unset means unlimited —
	 * the plan's default for employees; vendors are meant to always have one
	 * set (flagged, not hard-blocked, on save — see save_meta_box()).
	 *
	 * @var string
	 */
	const META_RADIUS = '_sc_crew_radius';

	/**
	 * Meta key for the weekly availability template (array keyed by weekday,
	 * each value an array of up to two start/end shifts — "split shifts").
	 *
	 * @var string
	 */
	const META_AVAILABILITY = '_sc_crew_availability';

	/**
	 * Meta key for time off (array of start/end date + optional reason).
	 *
	 * @var string
	 */
	const META_TIME_OFF = '_sc_crew_time_off';

	/**
	 * Nonce action/field name for the meta box save.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'sc_crew_save_meta';
	const NONCE_NAME   = 'sc_crew_meta_nonce';

	/**
	 * Transient key prefix used to pass a "radius required for vendors"
	 * warning across the redirect after save (see save_meta_box() /
	 * show_admin_notices()).
	 *
	 * @var string
	 */
	const RADIUS_NOTICE_TRANSIENT_PREFIX = 'sc_crew_radius_notice_';

	/**
	 * Weekdays the availability meta box renders a row for, in display order.
	 *
	 * @var string[]
	 */
	const WEEKDAYS = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );

	/**
	 * Registers every WordPress hook this class needs. Called once from the
	 * plugin bootstrap (service_crew_init() in service-crew.php); everything
	 * past construction — post type args, meta box markup, save handling —
	 * lives in this class, not the bootstrap.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_sc_crew', array( $this, 'save_meta_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );

		/*
		 * The featured-image meta box (used here as the crew member's photo)
		 * only renders if the active theme declares post-thumbnails support.
		 * Declared unconditionally so the photo field works regardless of
		 * which theme is active, matching the plan's "photo" field.
		 */
		add_action( 'after_setup_theme', array( $this, 'add_thumbnail_support' ) );
	}

	/**
	 * Declares theme support for post thumbnails so the Photo meta box shows
	 * up on the sc_crew edit screen no matter the active theme.
	 *
	 * @return void
	 */
	public function add_thumbnail_support() {
		add_theme_support( 'post-thumbnails' );
	}

	/**
	 * Registers the sc_crew custom post type.
	 *
	 * @return void
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => _x( 'Crew', 'post type general name', 'service-crew' ),
			'singular_name'      => _x( 'Crew Member', 'post type singular name', 'service-crew' ),
			'menu_name'          => _x( 'Crew', 'admin menu', 'service-crew' ),
			'name_admin_bar'     => _x( 'Crew Member', 'add new on admin bar', 'service-crew' ),
			'add_new'            => _x( 'Add New', 'crew member', 'service-crew' ),
			'add_new_item'       => __( 'Add New Crew Member', 'service-crew' ),
			'new_item'           => __( 'New Crew Member', 'service-crew' ),
			'edit_item'          => __( 'Edit Crew Member', 'service-crew' ),
			'view_item'          => __( 'View Crew Member', 'service-crew' ),
			'all_items'          => __( 'Crew', 'service-crew' ),
			'search_items'       => __( 'Search Crew', 'service-crew' ),
			'not_found'          => __( 'No crew members found.', 'service-crew' ),
			'not_found_in_trash' => __( 'No crew members found in Trash.', 'service-crew' ),
		);

		/*
		 * edit_post/read_post/delete_post use unique capability names, not
		 * 'manage_options' directly — see the identical note and postmortem
		 * in Service_Crew_Services::register_post_type(). Reusing a core
		 * capability string there hijacks it site-wide via WordPress's
		 * $post_type_meta_caps map. The administrator role is granted these
		 * three custom caps in Service_Crew_Activator::register_roles().
		 */
		$capabilities = array(
			'edit_post'              => 'edit_sc_crew',
			'read_post'              => 'read_sc_crew',
			'delete_post'            => 'delete_sc_crew',
			'edit_posts'             => 'manage_options',
			'edit_others_posts'      => 'manage_options',
			'publish_posts'          => 'manage_options',
			'read_private_posts'     => 'manage_options',
			'delete_posts'           => 'manage_options',
			'delete_private_posts'   => 'manage_options',
			'delete_published_posts' => 'manage_options',
			'delete_others_posts'    => 'manage_options',
			'edit_private_posts'     => 'manage_options',
			'edit_published_posts'   => 'manage_options',
			'create_posts'           => 'manage_options',
		);

		$args = array(
			'labels'              => $labels,
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => 'service-crew',
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,
			'query_var'           => false,
			'rewrite'             => false,
			'capability_type'     => 'post',
			'capabilities'        => $capabilities,
			'map_meta_cap'        => true,
			'has_archive'         => false,
			'hierarchical'        => false,
			'supports'            => array( 'title', 'thumbnail' ),
		);

		register_post_type( 'sc_crew', $args );
	}

	/**
	 * Registers the Details, Availability and Time Off meta boxes on the
	 * sc_crew edit screen. Photo uses the built-in featured-image box (see
	 * add_thumbnail_support()), so it doesn't need one of its own.
	 *
	 * @return void
	 */
	public function register_meta_boxes() {
		add_meta_box(
			'sc_crew_details',
			__( 'Details', 'service-crew' ),
			array( $this, 'render_details_meta_box' ),
			'sc_crew',
			'normal',
			'high'
		);

		add_meta_box(
			'sc_crew_availability',
			__( 'Weekly Availability', 'service-crew' ),
			array( $this, 'render_availability_meta_box' ),
			'sc_crew',
			'normal',
			'default'
		);

		add_meta_box(
			'sc_crew_time_off',
			__( 'Time Off', 'service-crew' ),
			array( $this, 'render_time_off_meta_box' ),
			'sc_crew',
			'normal',
			'default'
		);
	}

	/**
	 * Renders type, contact, address/ZIP, lat/lng and coverage radius.
	 *
	 * @param WP_Post $post Current post object.
	 * @return void
	 */
	public function render_details_meta_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$type    = get_post_meta( $post->ID, self::META_TYPE, true );
		$phone   = get_post_meta( $post->ID, self::META_PHONE, true );
		$email   = get_post_meta( $post->ID, self::META_EMAIL, true );
		$address = get_post_meta( $post->ID, self::META_ADDRESS, true );
		$zip     = get_post_meta( $post->ID, self::META_ZIP, true );
		$lat     = get_post_meta( $post->ID, self::META_LAT, true );
		$lng     = get_post_meta( $post->ID, self::META_LNG, true );
		$radius  = get_post_meta( $post->ID, self::META_RADIUS, true );

		if ( '' === $type ) {
			$type = 'employee';
		}
		?>
		<p>
			<label for="sc_crew_type"><?php esc_html_e( 'Type', 'service-crew' ); ?></label><br />
			<select id="sc_crew_type" name="sc_crew_type">
				<option value="employee" <?php selected( $type, 'employee' ); ?>><?php esc_html_e( 'Employee', 'service-crew' ); ?></option>
				<option value="vendor" <?php selected( $type, 'vendor' ); ?>><?php esc_html_e( 'Vendor', 'service-crew' ); ?></option>
			</select>
		</p>
		<p>
			<label for="sc_crew_phone"><?php esc_html_e( 'Phone', 'service-crew' ); ?></label><br />
			<input type="tel" id="sc_crew_phone" name="sc_crew_phone" value="<?php echo esc_attr( $phone ); ?>" class="regular-text" />
		</p>
		<p>
			<label for="sc_crew_email"><?php esc_html_e( 'Email', 'service-crew' ); ?></label><br />
			<input type="email" id="sc_crew_email" name="sc_crew_email" value="<?php echo esc_attr( $email ); ?>" class="regular-text" />
		</p>
		<p>
			<label for="sc_crew_address"><?php esc_html_e( 'Address', 'service-crew' ); ?></label><br />
			<input type="text" id="sc_crew_address" name="sc_crew_address" value="<?php echo esc_attr( $address ); ?>" class="regular-text" />
		</p>
		<p>
			<label for="sc_crew_zip"><?php esc_html_e( 'ZIP / postal code', 'service-crew' ); ?></label><br />
			<input type="text" id="sc_crew_zip" name="sc_crew_zip" value="<?php echo esc_attr( $zip ); ?>" class="regular-text" />
		</p>
		<p>
			<label for="sc_crew_lat"><?php esc_html_e( 'Latitude', 'service-crew' ); ?></label><br />
			<input type="number" step="0.0000001" id="sc_crew_lat" name="sc_crew_lat" value="<?php echo esc_attr( $lat ); ?>" class="regular-text" />
		</p>
		<p>
			<label for="sc_crew_lng"><?php esc_html_e( 'Longitude', 'service-crew' ); ?></label><br />
			<input type="number" step="0.0000001" id="sc_crew_lng" name="sc_crew_lng" value="<?php echo esc_attr( $lng ); ?>" class="regular-text" />
		</p>
		<p>
			<label for="sc_crew_radius"><?php esc_html_e( 'Coverage radius (miles)', 'service-crew' ); ?></label><br />
			<input type="number" step="1" min="0" id="sc_crew_radius" name="sc_crew_radius" value="<?php echo esc_attr( $radius ); ?>" class="regular-text" />
			<br />
			<span class="description">
				<?php esc_html_e( 'Leave blank for unlimited (default for employees). Required for vendors.', 'service-crew' ); ?>
			</span>
		</p>
		<?php
	}

	/**
	 * Renders two shifts (start/end) per weekday, letting a day be left
	 * blank (unavailable) or filled in once (single shift) or twice
	 * (split shift).
	 *
	 * @param WP_Post $post Current post object.
	 * @return void
	 */
	public function render_availability_meta_box( $post ) {
		$availability = get_post_meta( $post->ID, self::META_AVAILABILITY, true );
		if ( ! is_array( $availability ) ) {
			$availability = array();
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Day', 'service-crew' ); ?></th>
					<th><?php esc_html_e( 'Shift 1 start', 'service-crew' ); ?></th>
					<th><?php esc_html_e( 'Shift 1 end', 'service-crew' ); ?></th>
					<th><?php esc_html_e( 'Shift 2 start', 'service-crew' ); ?></th>
					<th><?php esc_html_e( 'Shift 2 end', 'service-crew' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( self::WEEKDAYS as $day ) : ?>
					<?php
					$day_shifts     = isset( $availability[ $day ] ) && is_array( $availability[ $day ] ) ? $availability[ $day ] : array();
					$shift1_start   = isset( $day_shifts['shift1_start'] ) ? $day_shifts['shift1_start'] : '';
					$shift1_end     = isset( $day_shifts['shift1_end'] ) ? $day_shifts['shift1_end'] : '';
					$shift2_start   = isset( $day_shifts['shift2_start'] ) ? $day_shifts['shift2_start'] : '';
					$shift2_end     = isset( $day_shifts['shift2_end'] ) ? $day_shifts['shift2_end'] : '';
					?>
					<tr>
						<td><?php echo esc_html( ucfirst( $day ) ); ?></td>
						<td><input type="time" name="sc_crew_availability[<?php echo esc_attr( $day ); ?>][shift1_start]" value="<?php echo esc_attr( $shift1_start ); ?>" /></td>
						<td><input type="time" name="sc_crew_availability[<?php echo esc_attr( $day ); ?>][shift1_end]" value="<?php echo esc_attr( $shift1_end ); ?>" /></td>
						<td><input type="time" name="sc_crew_availability[<?php echo esc_attr( $day ); ?>][shift2_start]" value="<?php echo esc_attr( $shift2_start ); ?>" /></td>
						<td><input type="time" name="sc_crew_availability[<?php echo esc_attr( $day ); ?>][shift2_end]" value="<?php echo esc_attr( $shift2_end ); ?>" /></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders a repeatable start/end/reason row per time-off entry. Row
	 * add/remove is handled by admin/js/crew-meta-boxes.js (enqueued in
	 * enqueue_assets()) by cloning the hidden #sc_crew_time_off_template row.
	 *
	 * @param WP_Post $post Current post object.
	 * @return void
	 */
	public function render_time_off_meta_box( $post ) {
		$time_off = get_post_meta( $post->ID, self::META_TIME_OFF, true );
		if ( ! is_array( $time_off ) ) {
			$time_off = array();
		}
		?>
		<table class="widefat striped" id="sc_crew_time_off_table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Start date', 'service-crew' ); ?></th>
					<th><?php esc_html_e( 'End date', 'service-crew' ); ?></th>
					<th><?php esc_html_e( 'Reason', 'service-crew' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $time_off as $index => $entry ) : ?>
					<?php $this->render_time_off_row( $index, $entry ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<button type="button" id="sc_crew_time_off_add" class="button"><?php esc_html_e( 'Add time off', 'service-crew' ); ?></button>
		</p>
		<table style="display:none;">
			<tbody>
				<?php $this->render_time_off_row( '__INDEX__', array() ); ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders one time-off row, shared by the persisted rows and the hidden
	 * template row the JS clones (index '__INDEX__', replaced client-side).
	 *
	 * @param int|string           $index Row index, or '__INDEX__' for the template row.
	 * @param array<string,string> $entry Row data (start/end/reason), empty for the template.
	 * @return void
	 */
	private function render_time_off_row( $index, $entry ) {
		$start  = isset( $entry['start'] ) ? $entry['start'] : '';
		$end    = isset( $entry['end'] ) ? $entry['end'] : '';
		$reason = isset( $entry['reason'] ) ? $entry['reason'] : '';
		$row_id = '__INDEX__' === $index ? 'sc_crew_time_off_template' : '';
		?>
		<tr id="<?php echo esc_attr( $row_id ); ?>">
			<td><input type="date" name="sc_crew_time_off[<?php echo esc_attr( $index ); ?>][start]" value="<?php echo esc_attr( $start ); ?>" /></td>
			<td><input type="date" name="sc_crew_time_off[<?php echo esc_attr( $index ); ?>][end]" value="<?php echo esc_attr( $end ); ?>" /></td>
			<td><input type="text" name="sc_crew_time_off[<?php echo esc_attr( $index ); ?>][reason]" value="<?php echo esc_attr( $reason ); ?>" class="regular-text" /></td>
			<td><button type="button" class="button sc-crew-time-off-remove"><?php esc_html_e( 'Remove', 'service-crew' ); ?></button></td>
		</tr>
		<?php
	}

	/**
	 * Enqueues the time-off repeater script on the sc_crew add/edit screen
	 * only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'sc_crew' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script(
			'sc-crew-meta-boxes',
			SERVICE_CREW_PLUGIN_URL . 'admin/js/crew-meta-boxes.js',
			array(),
			SERVICE_CREW_VERSION,
			true
		);
	}

	/**
	 * Saves the meta box fields, with nonce + capability check and
	 * sanitize-on-save for every field. Hooked on save_post_sc_crew, so this
	 * only ever runs for the sc_crew post type.
	 *
	 * @param int     $post_id Post ID being saved.
	 * @param WP_Post $post    Post object being saved.
	 * @return void
	 */
	public function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$type = 'employee';
		if ( isset( $_POST['sc_crew_type'] ) && 'vendor' === sanitize_text_field( wp_unslash( $_POST['sc_crew_type'] ) ) ) {
			$type = 'vendor';
		}
		update_post_meta( $post_id, self::META_TYPE, $type );

		if ( isset( $_POST['sc_crew_phone'] ) ) {
			update_post_meta( $post_id, self::META_PHONE, sanitize_text_field( wp_unslash( $_POST['sc_crew_phone'] ) ) );
		}

		if ( isset( $_POST['sc_crew_email'] ) ) {
			update_post_meta( $post_id, self::META_EMAIL, sanitize_email( wp_unslash( $_POST['sc_crew_email'] ) ) );
		}

		if ( isset( $_POST['sc_crew_address'] ) ) {
			update_post_meta( $post_id, self::META_ADDRESS, sanitize_text_field( wp_unslash( $_POST['sc_crew_address'] ) ) );
		}

		if ( isset( $_POST['sc_crew_zip'] ) ) {
			update_post_meta( $post_id, self::META_ZIP, sanitize_text_field( wp_unslash( $_POST['sc_crew_zip'] ) ) );
		}

		if ( isset( $_POST['sc_crew_lat'] ) && '' !== $_POST['sc_crew_lat'] ) {
			update_post_meta( $post_id, self::META_LAT, (float) wp_unslash( $_POST['sc_crew_lat'] ) );
		} else {
			delete_post_meta( $post_id, self::META_LAT );
		}

		if ( isset( $_POST['sc_crew_lng'] ) && '' !== $_POST['sc_crew_lng'] ) {
			update_post_meta( $post_id, self::META_LNG, (float) wp_unslash( $_POST['sc_crew_lng'] ) );
		} else {
			delete_post_meta( $post_id, self::META_LNG );
		}

		$radius = isset( $_POST['sc_crew_radius'] ) ? sanitize_text_field( wp_unslash( $_POST['sc_crew_radius'] ) ) : '';
		if ( '' !== $radius ) {
			update_post_meta( $post_id, self::META_RADIUS, max( 0, absint( $radius ) ) );
		} else {
			delete_post_meta( $post_id, self::META_RADIUS );
		}

		/*
		 * Radius is required for vendors but not hard-blocked here: this
		 * meta box has no reliable way to stop WordPress's post save and
		 * redirect back with the form still filled in. The admin gets a
		 * same-request-cycle notice instead; strict enforcement (rejecting
		 * the write outright) belongs to the admin-gated Crew REST
		 * controller, a separate not-yet-built Phase 1a task.
		 */
		if ( 'vendor' === $type && '' === $radius ) {
			set_transient( self::RADIUS_NOTICE_TRANSIENT_PREFIX . get_current_user_id(), $post_id, 45 );
		}

		$this->save_availability( $post_id );
		$this->save_time_off( $post_id );
	}

	/**
	 * Sanitizes and saves the weekly availability meta field.
	 *
	 * @param int $post_id Post ID being saved.
	 * @return void
	 */
	private function save_availability( $post_id ) {
		if ( ! isset( $_POST['sc_crew_availability'] ) || ! is_array( $_POST['sc_crew_availability'] ) ) {
			delete_post_meta( $post_id, self::META_AVAILABILITY );
			return;
		}

		$posted       = wp_unslash( $_POST['sc_crew_availability'] );
		$availability = array();

		foreach ( self::WEEKDAYS as $day ) {
			if ( ! isset( $posted[ $day ] ) || ! is_array( $posted[ $day ] ) ) {
				continue;
			}

			$shifts = array(
				'shift1_start' => $this->sanitize_time( $posted[ $day ]['shift1_start'] ?? '' ),
				'shift1_end'   => $this->sanitize_time( $posted[ $day ]['shift1_end'] ?? '' ),
				'shift2_start' => $this->sanitize_time( $posted[ $day ]['shift2_start'] ?? '' ),
				'shift2_end'   => $this->sanitize_time( $posted[ $day ]['shift2_end'] ?? '' ),
			);

			if ( '' === $shifts['shift1_start'] && '' === $shifts['shift1_end'] && '' === $shifts['shift2_start'] && '' === $shifts['shift2_end'] ) {
				continue;
			}

			$availability[ $day ] = $shifts;
		}

		if ( empty( $availability ) ) {
			delete_post_meta( $post_id, self::META_AVAILABILITY );
		} else {
			update_post_meta( $post_id, self::META_AVAILABILITY, $availability );
		}
	}

	/**
	 * Sanitizes a posted HH:MM time value, discarding anything that doesn't
	 * match the expected shape rather than trying to coerce it.
	 *
	 * @param string $value Raw posted value.
	 * @return string Sanitized HH:MM value, or '' if invalid/empty.
	 */
	private function sanitize_time( $value ) {
		$value = sanitize_text_field( $value );

		if ( ! preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Sanitizes and saves the time-off meta field. Rows with no start date
	 * are dropped (the template row posts as index '__INDEX__' if the JS
	 * template row was left in the DOM without ever being cloned/filled in,
	 * which also has no start date and is dropped the same way).
	 *
	 * @param int $post_id Post ID being saved.
	 * @return void
	 */
	private function save_time_off( $post_id ) {
		if ( ! isset( $_POST['sc_crew_time_off'] ) || ! is_array( $_POST['sc_crew_time_off'] ) ) {
			delete_post_meta( $post_id, self::META_TIME_OFF );
			return;
		}

		$posted   = wp_unslash( $_POST['sc_crew_time_off'] );
		$time_off = array();

		foreach ( $posted as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$start = $this->sanitize_date( $row['start'] ?? '' );
			if ( '' === $start ) {
				continue;
			}

			$end = $this->sanitize_date( $row['end'] ?? '' );
			if ( '' === $end || $end < $start ) {
				$end = $start;
			}

			$time_off[] = array(
				'start'  => $start,
				'end'    => $end,
				'reason' => sanitize_text_field( $row['reason'] ?? '' ),
			);
		}

		if ( empty( $time_off ) ) {
			delete_post_meta( $post_id, self::META_TIME_OFF );
		} else {
			update_post_meta( $post_id, self::META_TIME_OFF, $time_off );
		}
	}

	/**
	 * Sanitizes a posted YYYY-MM-DD date value, discarding anything that
	 * doesn't match the expected shape rather than trying to coerce it.
	 *
	 * @param string $value Raw posted value.
	 * @return string Sanitized YYYY-MM-DD value, or '' if invalid/empty.
	 */
	private function sanitize_date( $value ) {
		$value = sanitize_text_field( $value );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Shows the "radius required for vendors" notice queued by
	 * save_meta_box() for the current user/post, once.
	 *
	 * @return void
	 */
	public function show_admin_notices() {
		$transient_key = self::RADIUS_NOTICE_TRANSIENT_PREFIX . get_current_user_id();
		$post_id       = get_transient( $transient_key );

		if ( ! $post_id ) {
			return;
		}

		delete_transient( $transient_key );

		$screen = get_current_screen();
		if ( ! $screen || 'sc_crew' !== $screen->post_type || ! isset( $_GET['post'] ) || absint( wp_unslash( $_GET['post'] ) ) !== (int) $post_id ) {
			return;
		}
		?>
		<div class="notice notice-warning is-dismissible">
			<p><?php esc_html_e( 'This vendor has no coverage radius set. Vendors should always have a radius — employees default to unlimited, vendors do not.', 'service-crew' ); ?></p>
		</div>
		<?php
	}
}
