<?php
/**
 * Adds the "Add-ons" meta box to the sc_service edit screen: optional or
 * required extras with an optional quantity counter and quantity-discount
 * tiers. ("Add-on" is the admin-facing name; the plan and the code both
 * call these "components" — same thing, friendlier label.)
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per the plan, components belong to one service ("Service → sub-services
 * (components) with required flag") rather than being a shared catalog —
 * that's why this is a second meta box on sc_service, not its own post
 * type or admin submenu. Deliberately a separate class from
 * Service_Crew_Services (which owns the CPT itself and the base
 * price/duration/crew-needed meta box): each feature is its own class.
 *
 * Total price/time for a component is unit_price/unit_duration × quantity;
 * these are additive to the service's own base price/duration
 * (Service_Crew_Services::META_PRICE / META_DURATION_MINUTES), which this
 * class never touches.
 */
class Service_Crew_Components {

	/**
	 * Meta key for the components array.
	 *
	 * @var string
	 */
	const META_COMPONENTS = '_sc_components';

	/**
	 * Nonce action/field name for the meta box save.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'sc_components_save_meta';
	const NONCE_NAME   = 'sc_components_meta_nonce';

	/**
	 * Quantity-discount tiers are a fixed 3 slots per component rather than
	 * an open-ended repeater: the plan's example ("3 or more → 10% off")
	 * suggests a handful of tiers, and a second level of dynamic add/remove
	 * nested inside the components repeater would add real UI complexity
	 * for a case the plan doesn't ask for. Revisit if a service ever needs
	 * more than 3 tiers.
	 *
	 * @var int
	 */
	const TIER_SLOTS = 3;

	/**
	 * Registers every WordPress hook this class needs. Called once from the
	 * plugin bootstrap (service_crew_init() in service-crew.php).
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post_sc_service', array( $this, 'save_meta_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers the Components meta box on the sc_service edit screen.
	 *
	 * @return void
	 */
	public function register_meta_box() {
		add_meta_box(
			'sc_service_components',
			__( 'Add-ons', 'service-crew' ),
			array( $this, 'render_meta_box' ),
			'sc_service',
			'normal',
			'default'
		);
	}

	/**
	 * Renders the repeatable add-on blocks.
	 *
	 * @param WP_Post $post Current post object.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$components = get_post_meta( $post->ID, self::META_COMPONENTS, true );
		if ( ! is_array( $components ) ) {
			$components = array();
		}
		?>
		<p class="sc-field-help">
			<?php esc_html_e( 'Extras a customer can add to this service for more money — e.g. "Extra Bathroom" or "Inside Oven Cleaning". Leave empty if this service has none.', 'service-crew' ); ?>
		</p>
		<div id="sc_components_list">
			<?php foreach ( $components as $index => $component ) : ?>
				<?php $this->render_component_block( $index, $component ); ?>
			<?php endforeach; ?>
		</div>
		<p>
			<button type="button" id="sc_component_add" class="button button-primary"><?php esc_html_e( '+ Add an add-on', 'service-crew' ); ?></button>
		</p>
		<div style="display:none;">
			<?php $this->render_component_block( '__INDEX__', array() ); ?>
		</div>
		<?php
	}

	/**
	 * Renders one component block, shared by the persisted blocks and the
	 * hidden template (index '__INDEX__') the JS clones.
	 *
	 * @param int|string           $index     Component index, or '__INDEX__' for the template.
	 * @param array<string,mixed> $component Component data, empty for the template.
	 * @return void
	 */
	private function render_component_block( $index, $component ) {
		$name                  = isset( $component['name'] ) ? $component['name'] : '';
		$required              = ! empty( $component['required'] );
		$has_quantity          = ! empty( $component['has_quantity'] );
		$qty_min               = isset( $component['qty_min'] ) ? $component['qty_min'] : 1;
		$qty_max               = isset( $component['qty_max'] ) ? $component['qty_max'] : 1;
		$qty_default           = isset( $component['qty_default'] ) ? $component['qty_default'] : 1;
		$unit_price            = isset( $component['unit_price'] ) ? $component['unit_price'] : '';
		$unit_duration_minutes = isset( $component['unit_duration_minutes'] ) ? $component['unit_duration_minutes'] : '';
		$tiers                 = isset( $component['tiers'] ) && is_array( $component['tiers'] ) ? $component['tiers'] : array();
		$field                 = 'sc_components[' . $index . ']';
		$row_id                = '__INDEX__' === $index ? 'sc_component_template' : '';
		$quantity_hidden_class = $has_quantity ? '' : ' sc-addon-hidden';
		?>
		<div class="sc-admin-card sc-component-block" id="<?php echo esc_attr( $row_id ); ?>">
			<div class="sc-component-block__header">
				<input
					type="text"
					name="<?php echo esc_attr( $field ); ?>[name]"
					value="<?php echo esc_attr( $name ); ?>"
					class="sc-component-block__name"
					placeholder="<?php esc_attr_e( 'Add-on name, e.g. Extra Bathroom Cleaning', 'service-crew' ); ?>"
				/>
				<button type="button" class="button-link-delete sc-component-remove"><?php esc_html_e( 'Remove', 'service-crew' ); ?></button>
			</div>

			<p class="sc-toggle-row">
				<label class="sc-toggle">
					<input type="checkbox" name="<?php echo esc_attr( $field ); ?>[required]" value="1" <?php checked( $required ); ?> />
					<?php esc_html_e( 'Customer must add this', 'service-crew' ); ?>
				</label>
				<label class="sc-toggle sc-addon-has-quantity-toggle">
					<input type="checkbox" class="sc-addon-has-quantity" name="<?php echo esc_attr( $field ); ?>[has_quantity]" value="1" <?php checked( $has_quantity ); ?> />
					<?php esc_html_e( 'Let customer choose a quantity', 'service-crew' ); ?>
				</label>
			</p>

			<div class="sc-field-row">
				<div class="sc-field-group">
					<label><?php esc_html_e( 'Price ($)', 'service-crew' ); ?></label>
					<input type="number" step="0.01" min="0" name="<?php echo esc_attr( $field ); ?>[unit_price]" value="<?php echo esc_attr( $unit_price ); ?>" class="regular-text" />
				</div>
				<div class="sc-field-group">
					<label><?php esc_html_e( 'Extra time (minutes)', 'service-crew' ); ?></label>
					<input type="number" step="1" min="0" name="<?php echo esc_attr( $field ); ?>[unit_duration_minutes]" value="<?php echo esc_attr( $unit_duration_minutes ); ?>" class="regular-text" />
				</div>
			</div>

			<div class="sc-addon-quantity-fields<?php echo esc_attr( $quantity_hidden_class ); ?>">
				<p class="sc-field-help"><?php esc_html_e( 'How many of this add-on can a customer choose?', 'service-crew' ); ?></p>
				<div class="sc-field-row">
					<div class="sc-field-group">
						<label><?php esc_html_e( 'Min', 'service-crew' ); ?></label>
						<input type="number" step="1" min="1" name="<?php echo esc_attr( $field ); ?>[qty_min]" value="<?php echo esc_attr( $qty_min ); ?>" class="small-text" />
					</div>
					<div class="sc-field-group">
						<label><?php esc_html_e( 'Max', 'service-crew' ); ?></label>
						<input type="number" step="1" min="1" name="<?php echo esc_attr( $field ); ?>[qty_max]" value="<?php echo esc_attr( $qty_max ); ?>" class="small-text" />
					</div>
					<div class="sc-field-group">
						<label><?php esc_html_e( 'Starts at', 'service-crew' ); ?></label>
						<input type="number" step="1" min="1" name="<?php echo esc_attr( $field ); ?>[qty_default]" value="<?php echo esc_attr( $qty_default ); ?>" class="small-text" />
					</div>
				</div>

				<p class="sc-field-help"><?php esc_html_e( 'Optional: give a discount when a customer picks more than one (leave "Buy at least" empty to skip a row).', 'service-crew' ); ?></p>
				<table class="widefat striped sc-tiers-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Buy at least', 'service-crew' ); ?></th>
							<th><?php esc_html_e( 'Discount type', 'service-crew' ); ?></th>
							<th><?php esc_html_e( 'Amount off', 'service-crew' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php for ( $tier_index = 0; $tier_index < self::TIER_SLOTS; $tier_index++ ) : ?>
							<?php
							$tier         = isset( $tiers[ $tier_index ] ) ? $tiers[ $tier_index ] : array();
							$tier_min_qty = isset( $tier['min_qty'] ) ? $tier['min_qty'] : '';
							$tier_type    = isset( $tier['discount_type'] ) ? $tier['discount_type'] : 'percent';
							$tier_value   = isset( $tier['discount_value'] ) ? $tier['discount_value'] : '';
							$tier_field   = $field . '[tiers][' . $tier_index . ']';
							?>
							<tr>
								<td><input type="number" step="1" min="1" name="<?php echo esc_attr( $tier_field ); ?>[min_qty]" value="<?php echo esc_attr( $tier_min_qty ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'e.g. 3', 'service-crew' ); ?>" /></td>
								<td>
									<select name="<?php echo esc_attr( $tier_field ); ?>[discount_type]">
										<option value="percent" <?php selected( $tier_type, 'percent' ); ?>><?php esc_html_e( 'Percent off', 'service-crew' ); ?></option>
										<option value="fixed" <?php selected( $tier_type, 'fixed' ); ?>><?php esc_html_e( 'Fixed amount off', 'service-crew' ); ?></option>
									</select>
								</td>
								<td><input type="number" step="0.01" min="0" name="<?php echo esc_attr( $tier_field ); ?>[discount_value]" value="<?php echo esc_attr( $tier_value ); ?>" class="small-text" /></td>
							</tr>
						<?php endfor; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueues the component repeater script on the sc_service add/edit
	 * screen only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'sc_service' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'sc-admin',
			SERVICE_CREW_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			SERVICE_CREW_VERSION
		);

		wp_enqueue_script(
			'sc-service-components',
			SERVICE_CREW_PLUGIN_URL . 'admin/js/service-components.js',
			array(),
			SERVICE_CREW_VERSION,
			true
		);
	}

	/**
	 * Saves the Components meta field, with nonce + capability check and
	 * sanitize-on-save for every value. Hooked on save_post_sc_service
	 * alongside Service_Crew_Services::save_meta_box() — the two run
	 * independently since each only touches its own meta key(s).
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

		if ( ! isset( $_POST['sc_components'] ) || ! is_array( $_POST['sc_components'] ) ) {
			delete_post_meta( $post_id, self::META_COMPONENTS );
			return;
		}

		$posted     = wp_unslash( $_POST['sc_components'] );
		$components = array();

		foreach ( $posted as $row ) {
			if ( ! is_array( $row ) || '' === trim( (string) ( $row['name'] ?? '' ) ) ) {
				continue;
			}

			$qty_min     = max( 1, absint( $row['qty_min'] ?? 1 ) );
			$qty_max     = max( $qty_min, absint( $row['qty_max'] ?? $qty_min ) );
			$qty_default = min( $qty_max, max( $qty_min, absint( $row['qty_default'] ?? $qty_min ) ) );

			$components[] = array(
				'name'                  => sanitize_text_field( $row['name'] ),
				'required'              => ! empty( $row['required'] ),
				'has_quantity'          => ! empty( $row['has_quantity'] ),
				'qty_min'               => $qty_min,
				'qty_max'               => $qty_max,
				'qty_default'           => $qty_default,
				'unit_price'            => max( 0, (float) ( $row['unit_price'] ?? 0 ) ),
				'unit_duration_minutes' => max( 0, absint( $row['unit_duration_minutes'] ?? 0 ) ),
				'tiers'                 => $this->sanitize_tiers( $row['tiers'] ?? array() ),
			);
		}

		if ( empty( $components ) ) {
			delete_post_meta( $post_id, self::META_COMPONENTS );
		} else {
			update_post_meta( $post_id, self::META_COMPONENTS, $components );
		}
	}

	/**
	 * Sanitizes a component's quantity-discount tiers, dropping any tier
	 * slot with no minimum quantity set.
	 *
	 * @param array $tiers Raw posted tiers, keyed 0..TIER_SLOTS-1.
	 * @return array<int,array<string,mixed>> Sanitized tiers, reindexed.
	 */
	private function sanitize_tiers( $tiers ) {
		if ( ! is_array( $tiers ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $tiers as $tier ) {
			if ( ! is_array( $tier ) || empty( $tier['min_qty'] ) ) {
				continue;
			}

			$discount_type = isset( $tier['discount_type'] ) && 'fixed' === $tier['discount_type'] ? 'fixed' : 'percent';

			$sanitized[] = array(
				'min_qty'        => absint( $tier['min_qty'] ),
				'discount_type'  => $discount_type,
				'discount_value' => max( 0, (float) ( $tier['discount_value'] ?? 0 ) ),
			);
		}

		return $sanitized;
	}
}
