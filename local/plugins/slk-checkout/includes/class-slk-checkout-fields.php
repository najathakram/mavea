<?php
/**
 * Checkout field definitions, ordering and validation.
 *
 * Field order follows design/sections/06-mobile.html:
 *   1 · You     mobile number → full name → email (optional)
 *   2 · Where   address → landmark → city / district → postal code
 *
 * @package slk
 */

defined( 'ABSPATH' ) || exit;

final class SLK_Checkout_Fields {

	public const LANDMARK_KEY  = 'billing_landmark';
	public const LANDMARK_META = '_billing_landmark';

	public static function init(): void {
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'fields' ), 20 );
		add_filter( 'woocommerce_checkout_posted_data', array( __CLASS__, 'normalise_posted_data' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_order_data' ), 10, 2 );

		// Hints must be readable by screen readers. WooCommerce renders field
		// descriptions inside <span class="description" aria-hidden="true">,
		// which hides them from exactly the people who most need them.
		foreach ( array( 'text', 'tel', 'email', 'select', 'state', 'textarea' ) as $type ) {
			add_filter( 'woocommerce_form_field_' . $type, array( __CLASS__, 'expose_description' ), 10, 4 );
		}
	}

	/**
	 * @param array $fields Checkout fields, grouped.
	 */
	public static function fields( $fields ) {
		if ( ! is_array( $fields ) || empty( $fields['billing'] ) ) {
			return $fields;
		}

		$billing = $fields['billing'];

		/* ---- 1 · You ------------------------------------------------- */

		// Phone first: it is the identity, not an afterthought at the bottom.
		if ( isset( $billing['billing_phone'] ) ) {
			$billing['billing_phone'] = array_merge(
				$billing['billing_phone'],
				array(
					'label'        => __( 'Mobile number', 'slk' ),
					'placeholder'  => '77 123 4567',
					'description'  => __( 'This is the number we call to confirm.', 'slk' ),
					'required'     => true,
					'type'         => 'tel',
					'autocomplete' => 'tel',
					'priority'     => 10,
					'class'        => array( 'form-row-wide', 'slk-field' ),
					'input_class'  => array( 'slk-input' ),
					// We own phone validation entirely (see self::validate).
					// WC_Validation::is_phone() only checks length >= 7 and
					// would emit a second, vaguer error alongside ours.
					'validate'     => array(),
				)
			);
		}

		if ( isset( $billing['billing_first_name'] ) ) {
			$billing['billing_first_name'] = array_merge(
				$billing['billing_first_name'],
				array(
					'label'        => __( 'Full name', 'slk' ),
					'required'     => true,
					'autocomplete' => 'name',
					'priority'     => 20,
					'class'        => array( 'form-row-wide', 'slk-field' ),
					'input_class'  => array( 'slk-input' ),
				)
			);
		}

		// One visible name box. billing_last_name stays in the field array —
		// it must remain posted so WC_Checkout maps it onto the order and
		// gateways that require a surname (PayHere) still get one — but it is
		// rendered hidden and refilled from the split in normalise_posted_data().
		if ( isset( $billing['billing_last_name'] ) ) {
			$billing['billing_last_name'] = array_merge(
				$billing['billing_last_name'],
				array(
					'type'     => 'hidden',
					'label'    => '',
					'required' => false,
					'priority' => 21,
					'class'    => array( 'form-row-wide' ),
				)
			);
		}

		// Email is optional at the field level; it becomes required only for
		// gateways that need it, enforced server-side in self::validate().
		if ( isset( $billing['billing_email'] ) ) {
			$billing['billing_email'] = array_merge(
				$billing['billing_email'],
				array(
					'label'        => __( 'Email', 'slk' ),
					'description'  => __( 'Optional, and needed only for card payment.', 'slk' ),
					'required'     => false,
					'autocomplete' => 'email',
					'priority'     => 30,
					'class'        => array( 'form-row-wide', 'slk-field' ),
					'input_class'  => array( 'slk-input' ),
				)
			);
		}

		/* ---- 2 · Where ----------------------------------------------- */

		if ( isset( $billing['billing_country'] ) ) {
			$billing['billing_country']['priority'] = 40;
		}

		if ( isset( $billing['billing_address_1'] ) ) {
			$billing['billing_address_1'] = array_merge(
				$billing['billing_address_1'],
				array(
					'label'       => __( 'Address', 'slk' ),
					'placeholder' => __( 'House no. and street', 'slk' ),
					'required'    => true,
					'priority'    => 50,
					'class'       => array( 'form-row-wide', 'slk-field' ),
					'input_class' => array( 'slk-input' ),
				)
			);
		}

		// The landmark. Sri Lankan couriers navigate by junctions and shops,
		// not by postcodes — this is the field that actually gets the parcel
		// delivered, so it sits directly under the street address.
		$billing[ self::LANDMARK_KEY ] = array(
			'type'         => 'text',
			'label'        => __( 'Nearest landmark or junction', 'slk' ),
			'description'  => __( 'Couriers navigate by this, not postcodes', 'slk' ),
			'placeholder'  => __( 'e.g. opposite the Dehiwala junction pharmacy', 'slk' ),
			'required'     => false,
			'autocomplete' => 'off',
			'priority'     => 55,
			'class'        => array( 'form-row-wide', 'slk-field' ),
			'input_class'  => array( 'slk-input' ),
		);

		if ( isset( $billing['billing_city'] ) ) {
			$billing['billing_city']['priority']    = 60;
			$billing['billing_city']['class']       = array( 'form-row-first', 'slk-field' );
			$billing['billing_city']['input_class'] = array( 'slk-input' );
		}
		if ( isset( $billing['billing_state'] ) ) {
			$billing['billing_state']['priority']    = 70;
			$billing['billing_state']['class']       = array( 'form-row-last', 'slk-field' );
			$billing['billing_state']['input_class'] = array( 'slk-select' );
		}
		if ( isset( $billing['billing_postcode'] ) ) {
			$billing['billing_postcode']['required']    = false;
			$billing['billing_postcode']['priority']    = 80;
			$billing['billing_postcode']['class']       = array( 'form-row-wide', 'slk-field' );
			$billing['billing_postcode']['input_class'] = array( 'slk-input' );
		}

		// address_2 and company are removed outright, in both groups. The
		// locale marks them hidden, but that is applied by WooCommerce's
		// address-i18n JS; unsetting them here means they are gone server-side
		// too, so they can never be validated, stored, or printed on a slip.
		foreach ( array( 'billing', 'shipping' ) as $group ) {
			foreach ( array( '_address_2', '_company' ) as $suffix ) {
				unset( $fields[ $group ][ $group . $suffix ] );
			}
		}
		unset( $billing['billing_address_2'], $billing['billing_company'] );

		$fields['billing'] = $billing;

		if ( isset( $fields['order']['order_comments'] ) ) {
			$fields['order']['order_comments'] = array_merge(
				$fields['order']['order_comments'],
				array(
					'label'          => __( 'Delivery notes', 'slk' ),
					'placeholder'    => __( 'Anything else the courier should know', 'slk' ),
					'required'       => false,
					'class'          => array( 'form-row-wide', 'slk-field' ),
					'input_class'    => array( 'slk-textarea' ),
				)
			);
		}

		return $fields;
	}

	/**
	 * Runs before validation and before the order is created, so every later
	 * consumer (validation, the order object, the gateway, the courier export)
	 * sees the same canonical values.
	 *
	 * @param array $data Posted checkout data.
	 */
	public static function normalise_posted_data( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		// Phone → +94XXXXXXXXX. If it does not parse we leave the raw value
		// alone so the shopper sees what they typed next to the error.
		foreach ( array( 'billing_phone', 'shipping_phone' ) as $key ) {
			if ( empty( $data[ $key ] ) ) {
				continue;
			}
			$normalised = SLK_Phone::normalise( $data[ $key ] );
			if ( '' !== $normalised ) {
				$data[ $key ] = $normalised;
			}
		}

		// Split "Nimali Perera" back into first/last so the order, the invoice
		// and gateways that demand a surname all behave. A single-word name
		// leaves the surname empty rather than duplicating the given name onto
		// the packing slip.
		if ( apply_filters( 'slk_use_single_name_field', true ) && ! empty( $data['billing_first_name'] ) ) {
			$parts = preg_split( '/\s+/u', trim( (string) $data['billing_first_name'] ) );
			if ( is_array( $parts ) && count( $parts ) > 1 ) {
				$last                        = array_pop( $parts );
				$data['billing_last_name']   = $last;
				$data['billing_first_name']  = implode( ' ', $parts );
			} elseif ( ! isset( $data['billing_last_name'] ) ) {
				$data['billing_last_name'] = '';
			}
		}

		if ( isset( $data[ self::LANDMARK_KEY ] ) ) {
			$data[ self::LANDMARK_KEY ] = sanitize_text_field( (string) $data[ self::LANDMARK_KEY ] );
		}

		return $data;
	}

	/**
	 * Server-side rules. Client-side hints are a courtesy; this is the gate.
	 *
	 * @param array    $data   Posted data.
	 * @param WP_Error $errors Error bag.
	 */
	public static function validate( $data, $errors ) {
		if ( ! is_array( $data ) || ! is_wp_error( $errors ) ) {
			return;
		}

		$phone = $data['billing_phone'] ?? '';
		$check = SLK_Phone::check( $phone );
		if ( ! $check['ok'] ) {
			$errors->add( 'billing_phone', $check['message'], array( 'id' => 'billing_phone' ) );
		}

		// Email is required only when the chosen gateway cannot work without
		// one. Everything else — cash on delivery, bank transfer — runs on the
		// phone number alone.
		$method = isset( $data['payment_method'] ) ? (string) $data['payment_method'] : '';
		$email  = isset( $data['billing_email'] ) ? trim( (string) $data['billing_email'] ) : '';

		if ( '' === $email && SLK_Email_Policy::gateway_requires_email( $method ) ) {
			$errors->add(
				'billing_email',
				__( 'Card payment needs an email for the receipt. Prefer not to? Choose cash on delivery instead.', 'slk' ),
				array( 'id' => 'billing_email' )
			);
		}

		// A district outside the 25 can only come from a tampered POST, but it
		// would silently produce an undeliverable order and a wrong rate.
		$district = $data['billing_state'] ?? '';
		if ( SLK_Districts::COUNTRY === ( $data['billing_country'] ?? '' ) && ! SLK_Districts::is_district( $district ) ) {
			$errors->add(
				'billing_state',
				__( 'Please choose your district from the list.', 'slk' ),
				array( 'id' => 'billing_state' )
			);
		}
	}

	/**
	 * Persist the landmark on the order.
	 *
	 * WC_Checkout::create_order() already copies unknown "billing_" and
	 * "shipping_" keys into order meta, but relying on that is relying on an
	 * implementation detail. Writing it explicitly (same meta key, so no
	 * duplicate) makes the contract ours. CRUD only — HPOS stores this in
	 * wc_orders_meta, and get_post_meta() would read nothing there.
	 *
	 * @param WC_Order $order Order being created.
	 * @param array    $data  Posted data.
	 */
	public static function save_order_data( $order, $data ) {
		if ( ! $order instanceof WC_Order || ! is_array( $data ) ) {
			return;
		}

		$landmark = isset( $data[ self::LANDMARK_KEY ] ) ? sanitize_text_field( (string) $data[ self::LANDMARK_KEY ] ) : '';
		if ( '' !== $landmark ) {
			$order->update_meta_data( self::LANDMARK_META, $landmark );
		}
	}

	public static function get_landmark( $order ): string {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		return (string) $order->get_meta( self::LANDMARK_META );
	}

	/**
	 * Make field hints visible to assistive technology and give them the
	 * design system's hint class.
	 *
	 * @param string $field Rendered field HTML.
	 * @param string $key   Field key.
	 * @param array  $args  Field args.
	 * @param mixed  $value Field value.
	 */
	public static function expose_description( $field, $key, $args, $value ) {
		if ( ! is_string( $field ) || empty( $args['description'] ) ) {
			return $field;
		}

		$field   = str_replace( 'class="description"', 'class="description slk-field__hint"', $field );
		$exposed = preg_replace( '/(<span class="description[^"]*"[^>]*?)\s+aria-hidden="true"/', '$1', $field );

		return is_string( $exposed ) ? $exposed : $field;
	}
}
