<?php
/**
 * Gestión de campos de usuario para WooCommerce.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dolisync_WooCommerce_Users {
	private const META_KEY = 'dolisync_document_id';
	private const FIELD_ID = 'dolisync/document-id';
	private $document_validator;

	public static function init() {
		static $initialized = false;

		if ( $initialized ) {
			return;
		}

		$initialized = true;
		new self();
	}

	private function __construct() {
		require_once DOLISYNC_PLUGIN_DIR . 'includes/utils/class-dolisync-spanish-document-validator.php';
		$this->document_validator = new Dolisync_Spanish_Document_Validator();

		add_action( 'woocommerce_init', array( $this, 'register_checkout_field_for_blocks' ) );
		add_action( 'woocommerce_register_form', array( $this, 'render_registration_field' ) );
		add_filter( 'woocommerce_registration_errors', array( $this, 'validate_woocommerce_registration' ), 10, 3 );
		add_action( 'woocommerce_created_customer', array( $this, 'save_user_profile_field' ) );
		add_action( 'woocommerce_edit_account_form', array( $this, 'render_account_field' ) );
		add_filter( 'woocommerce_save_account_details_errors', array( $this, 'validate_account_details' ), 10, 2 );
		add_action( 'woocommerce_save_account_details', array( $this, 'save_account_details' ) );
		add_action( 'woocommerce_after_checkout_billing_form', array( $this, 'render_classic_checkout_field' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_classic_checkout_field' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_classic_checkout_order_meta' ), 10, 2 );
		add_action( 'woocommerce_created_customer', array( $this, 'save_classic_checkout_customer_meta' ), 10, 3 );
		add_action( 'woocommerce_set_additional_field_value', array( $this, 'mirror_blocks_field_to_user_meta' ), 10, 4 );

		add_action( 'show_user_profile', array( $this, 'render_user_profile_field' ) );
		add_action( 'edit_user_profile', array( $this, 'render_user_profile_field' ) );
		add_action( 'user_new_form', array( $this, 'render_new_user_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_user_profile_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_user_profile_field' ) );
		add_action( 'user_register', array( $this, 'save_user_profile_field' ) );
		add_action( 'user_profile_update_errors', array( $this, 'validate_user_profile_field' ), 10, 3 );

		add_filter( 'registration_errors', array( $this, 'validate_wordpress_registration' ), 10, 3 );
	}

	public function render_registration_field() {
		woocommerce_form_field(
			self::META_KEY,
			array(
				'type'              => 'text',
				'label'             => __( 'DNI / CIF / Pasaporte / NIE', 'dolisync' ),
				'required'          => true,
				'class'             => array( 'form-row-wide' ),
				'priority'          => 30,
				'custom_attributes' => array(
					'autocomplete' => 'off',
					'maxlength' => 50,
				),
			),
			$this->get_submitted_value()
		);
	}

	public function register_checkout_field_for_blocks() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		woocommerce_register_additional_checkout_field(
			array(
				'id'                 => self::FIELD_ID,
				'label'              => __( 'DNI / CIF / Pasaporte / NIE', 'dolisync' ),
				'location'           => 'contact',
				'required'           => true,
				'type'               => 'text',
				'attributes'         => array(
					'autocomplete' => 'off',
					'maxLength'    => 50,
				),
				'sanitize_callback'  => array( $this, 'sanitize_document_id' ),
				'validate_callback'  => array( $this, 'validate_blocks_document_id' ),
			)
		);
	}

	public function render_classic_checkout_field( $checkout ) {
		woocommerce_form_field(
			self::META_KEY,
			array(
				'type'              => 'text',
				'label'             => __( 'DNI / CIF / Pasaporte / NIE', 'dolisync' ),
				'required'          => true,
				'class'             => array( 'form-row-wide' ),
				'priority'          => 35,
				'custom_attributes' => array(
					'autocomplete' => 'off',
					'maxlength'    => 50,
				),
			),
			$this->get_checkout_field_value()
		);
	}

	public function validate_classic_checkout_field() {
		$document_id = $this->get_checkout_field_value();

		if ( '' === $document_id ) {
			wc_add_notice( __( 'Debes indicar el DNI/CIF/Pasaporte/NIE para continuar.', 'dolisync' ), 'error' );
			return;
		}

		$validation = $this->document_validator->validate( $document_id );

		if ( empty( $validation['valid'] ) ) {
			wc_add_notice( __( 'El documento indicado no es valido. Revisa formato y letra de control.', 'dolisync' ), 'error' );
		}
	}

	public function save_classic_checkout_customer_meta( $customer_id, $data, $password_generated ) {
		$document_id = $this->get_checkout_field_value();

		if ( '' === $document_id ) {
			return;
		}

		$validation = $this->document_validator->validate( $document_id );

		if ( empty( $validation['valid'] ) ) {
			return;
		}

		update_user_meta( (int) $customer_id, self::META_KEY, (string) $validation['normalized'] );
	}

	public function save_classic_checkout_order_meta( $order, $data ) {
		$document_id = $this->get_checkout_field_value();

		if ( '' === $document_id || ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		$validation = $this->document_validator->validate( $document_id );

		if ( empty( $validation['valid'] ) ) {
			return;
		}

		$order->update_meta_data( self::META_KEY, (string) $validation['normalized'] );
	}

	public function mirror_blocks_field_to_user_meta( $field_key, $value, $group, $wc_object ) {
		if ( self::FIELD_ID !== $field_key || '' === trim( (string) $value ) ) {
			return;
		}

		$validation = $this->document_validator->validate( $value );

		if ( empty( $validation['valid'] ) ) {
			return;
		}

		$document_id = (string) $validation['normalized'];

		if ( $wc_object instanceof WC_Order ) {
			$wc_object->update_meta_data( self::META_KEY, $document_id );
			$wc_object->save();
			return;
		}

		if ( is_object( $wc_object ) && method_exists( $wc_object, 'get_id' ) ) {
			$user_id = (int) $wc_object->get_id();

			if ( $user_id > 0 ) {
				update_user_meta( $user_id, self::META_KEY, $document_id );
			}
		}
	}

	public function validate_woocommerce_registration( $errors, $username, $email ) {
		if ( $this->is_checkout_registration_context() ) {
			return $errors;
		}

		$document_id = $this->get_submitted_value();

		if ( '' === $document_id ) {
			$errors->add(
				'dolisync_document_id_required',
				__( 'Debes indicar el DNI/CIF/Pasaporte/NIE para crear la cuenta.', 'dolisync' )
			);
			return $errors;
		}

		$validation = $this->document_validator->validate( $document_id );

		if ( empty( $validation['valid'] ) ) {
			$errors->add(
				'dolisync_document_id_invalid',
				__( 'El documento indicado no es valido. Revisa formato y letra de control.', 'dolisync' )
			);
		}

		return $errors;
	}

	public function validate_wordpress_registration( $errors, $sanitized_user_login, $user_email ) {
		if ( $this->is_checkout_registration_context() ) {
			return $errors;
		}

		$document_id = $this->get_submitted_value();

		if ( '' === $document_id ) {
			$errors->add(
				'dolisync_document_id_required',
				__( 'Debes indicar el DNI/CIF/Pasaporte/NIE para crear la cuenta.', 'dolisync' )
			);
			return $errors;
		}

		$validation = $this->document_validator->validate( $document_id );

		if ( empty( $validation['valid'] ) ) {
			$errors->add(
				'dolisync_document_id_invalid',
				__( 'El documento indicado no es valido. Revisa formato y letra de control.', 'dolisync' )
			);
		}

		return $errors;
	}

	public function render_user_profile_field( $user ) {
		$this->render_profile_input( $user instanceof WP_User ? $user->ID : 0 );
	}

	public function render_new_user_field( $operation ) {
		if ( 'add-new-user' !== $operation ) {
			return;
		}

		$this->render_profile_input( 0 );
	}

	public function validate_user_profile_field( $errors, $update, $user ) {
		$document_id = $this->get_submitted_value();

		if ( '' === $document_id ) {
			$errors->add(
				'dolisync_document_id_required',
				__( 'Debes indicar el DNI/CIF/Pasaporte/NIE.', 'dolisync' )
			);
			return;
		}

		$validation = $this->document_validator->validate( $document_id );

		if ( empty( $validation['valid'] ) ) {
			$errors->add(
				'dolisync_document_id_invalid',
				__( 'El documento indicado no es valido. Revisa formato y letra de control.', 'dolisync' )
			);
		}
	}

	public function render_account_field() {
		$this->render_profile_input( get_current_user_id() );
	}

	public function validate_account_details( $errors, $user ) {
		$document_id = $this->get_submitted_value();

		if ( '' === $document_id ) {
			$errors->add(
				'dolisync_document_id_required',
				__( 'Debes indicar el DNI/CIF/Pasaporte/NIE.', 'dolisync' )
			);
			return $errors;
		}

		$validation = $this->document_validator->validate( $document_id );

		if ( empty( $validation['valid'] ) ) {
			$errors->add(
				'dolisync_document_id_invalid',
				__( 'El documento indicado no es valido. Revisa formato y letra de control.', 'dolisync' )
			);
		}

		return $errors;
	}

	public function save_account_details( $user_id ) {
		$this->save_user_profile_field( $user_id );
	}

	public function save_user_profile_field( $user_id ) {
		$document_id = $this->get_submitted_value();

		if ( '' === $document_id ) {
			return;
		}

		$validation = $this->document_validator->validate( $document_id );

		if ( empty( $validation['valid'] ) ) {
			return;
		}

		update_user_meta( (int) $user_id, self::META_KEY, (string) $validation['normalized'] );
	}

	public function sanitize_document_id( $field_value ) {
		return $this->document_validator->normalize( wp_unslash( (string) $field_value ) );
	}

	public function validate_blocks_document_id( $field_value ) {
		$validation = $this->document_validator->validate( $field_value );
		if ( empty( $validation['valid'] ) ) {
			return new WP_Error( 'dolisync_invalid_document_id', __( 'El documento indicado no es válido. Revisa el formato y la letra de control.', 'dolisync' ) );
		}
		return true;
	}

	private function render_profile_input( $user_id ) {
		$value = $this->get_stored_value( $user_id );
		?>
		<h2><?php echo esc_html__( 'Datos de sincronización DoliSync', 'dolisync' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="<?php echo esc_attr( self::META_KEY ); ?>"><?php echo esc_html__( 'DNI / CIF / Pasaporte / NIE', 'dolisync' ); ?></label>
				</th>
				<td>
					<input type="text" name="<?php echo esc_attr( self::META_KEY ); ?>" id="<?php echo esc_attr( self::META_KEY ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" maxlength="50" autocomplete="off" required />
					<p class="description"><?php echo esc_html__( 'Campo obligatorio para crear y sincronizar usuarios.', 'dolisync' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	private function get_stored_value( $user_id ) {
		if ( $user_id > 0 ) {
			$stored_value = get_user_meta( $user_id, self::META_KEY, true );

			if ( is_string( $stored_value ) && '' !== trim( $stored_value ) ) {
				return $this->document_validator->normalize( $stored_value );
			}

			global $wpdb;
			$table = $wpdb->prefix . 'dolisync_contact_relations';
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			if ( $exists === $table ) {
				$relation_value = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT dni FROM {$table} WHERE wp_user_id = %d ORDER BY synced_at DESC LIMIT 1",
						$user_id
					)
				); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

				if ( is_string( $relation_value ) && '' !== trim( $relation_value ) ) {
					return $this->document_validator->normalize( $relation_value );
				}
			}
		}

		return $this->get_submitted_value();
	}

	private function get_submitted_value() {
		return $this->get_request_document_value();
	}

	private function get_checkout_field_value() {
		return $this->get_request_document_value();
	}

	private function get_request_document_value() {
		$keys = array(
			self::META_KEY,
			self::FIELD_ID,
			str_replace( '/', '_', self::FIELD_ID ),
			'billing_' . self::META_KEY,
		);

		foreach ( $keys as $key ) {
			if ( isset( $_POST[ $key ] ) && '' !== trim( (string) wp_unslash( $_POST[ $key ] ) ) ) {
				return $this->sanitize_document_id( $_POST[ $key ] );
			}
		}

		if ( isset( $_POST['additional_fields'] ) && is_array( $_POST['additional_fields'] ) ) {
			$additional_fields = wp_unslash( $_POST['additional_fields'] );

			foreach ( $keys as $key ) {
				if ( isset( $additional_fields[ $key ] ) && '' !== trim( (string) $additional_fields[ $key ] ) ) {
					return $this->sanitize_document_id( $additional_fields[ $key ] );
				}
			}
		}

		return '';
	}

	/**
	 * Detecta si la validacion de registro se dispara durante checkout.
	 */
	private function is_checkout_registration_context() {
		if ( isset( $_POST['woocommerce-process-checkout-nonce'] ) || isset( $_POST['woocommerce_checkout_place_order'] ) ) {
			return true;
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

			if ( false !== strpos( $request_uri, '/wc/store/' ) && false !== strpos( $request_uri, '/checkout' ) ) {
				return true;
			}
		}

		return false;
	}
}
