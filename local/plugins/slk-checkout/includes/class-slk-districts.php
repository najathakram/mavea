<?php
/**
 * The 25 Sri Lankan districts, and the LK address locale.
 *
 * Measured on WooCommerce 11.0.1: WC()->countries->get_states('LK') returns
 * ZERO entries — core ships no provinces or districts for Sri Lanka, so the
 * address field renders as a free-text box. Everything here is therefore purely
 * additive: nothing of core's is overridden and a future core update that adds
 * provinces would merge, not collide.
 *
 * KEY CHOICE — the state key IS the district name ('Galle' => 'Galle') rather
 * than a short code. The courier handoff (Koombiyo) is keyed on the district
 * name string, and $order->get_billing_state() returns the *key*, so keying by
 * name means the value we store is already the value the courier needs, with no
 * lookup table to drift. Reviewer: confirm these 25 spellings against the
 * Koombiyo merchant portal before the first live dispatch.
 *
 * @package slk
 */

defined( 'ABSPATH' ) || exit;

final class SLK_Districts {

	public const COUNTRY = 'LK';

	/** Delivery tiers, from design/assets/sri-lanka-commerce.json. */
	public const TIER_METRO   = 'metro';
	public const TIER_REGIONAL = 'regional';
	public const TIER_ISLAND  = 'island';

	public static function init(): void {
		add_filter( 'woocommerce_states', array( __CLASS__, 'register_states' ) );
		add_filter( 'woocommerce_get_country_locale', array( __CLASS__, 'register_locale' ) );
		add_filter( 'default_checkout_billing_country', array( __CLASS__, 'default_country' ) );
		add_filter( 'default_checkout_shipping_country', array( __CLASS__, 'default_country' ) );
	}

	/**
	 * District => province. Source of truth: sri-lanka-commerce.json.
	 *
	 * @return array<string,string>
	 */
	public static function all(): array {
		return array(
			'Ampara'       => 'Eastern',
			'Anuradhapura' => 'North Central',
			'Badulla'      => 'Uva',
			'Batticaloa'   => 'Eastern',
			'Colombo'      => 'Western',
			'Galle'        => 'Southern',
			'Gampaha'      => 'Western',
			'Hambantota'   => 'Southern',
			'Jaffna'       => 'Northern',
			'Kalutara'     => 'Western',
			'Kandy'        => 'Central',
			'Kegalle'      => 'Sabaragamuwa',
			'Kilinochchi'  => 'Northern',
			'Kurunegala'   => 'North Western',
			'Mannar'       => 'Northern',
			'Matale'       => 'Central',
			'Matara'       => 'Southern',
			'Monaragala'   => 'Uva',
			'Mullaitivu'   => 'Northern',
			'Nuwara Eliya' => 'Central',
			'Polonnaruwa'  => 'North Central',
			'Puttalam'     => 'North Western',
			'Ratnapura'    => 'Sabaragamuwa',
			'Trincomalee'  => 'Eastern',
			'Vavuniya'     => 'Northern',
		);
	}

	/**
	 * Add the districts as LK "states". Additive only.
	 *
	 * @param array $states Existing states, keyed by country code.
	 */
	public static function register_states( $states ) {
		if ( ! is_array( $states ) ) {
			return $states;
		}

		$districts = array();
		foreach ( array_keys( self::all() ) as $district ) {
			// Key and label are the same string on purpose — see file header.
			$districts[ $district ] = $district;
		}

		$existing                 = isset( $states[ self::COUNTRY ] ) && is_array( $states[ self::COUNTRY ] ) ? $states[ self::COUNTRY ] : array();
		$states[ self::COUNTRY ]  = array_merge( $districts, $existing );

		return $states;
	}

	public static function is_district( $value ): bool {
		return '' !== (string) $value && array_key_exists( (string) $value, self::all() );
	}

	/**
	 * Which delivery tier a district falls in.
	 *
	 * Note for the reviewer: reference/sri-lanka-districts.md sketches metro as
	 * Colombo/Gampaha/Kalutara, but sri-lanka-commerce.json — the authoritative
	 * data file — puts Kalutara in the Rs. 400 band. The JSON wins here.
	 */
	public static function tier( $district ): string {
		$district = (string) $district;

		if ( in_array( $district, array( 'Colombo', 'Gampaha' ), true ) ) {
			return self::TIER_METRO;
		}
		if ( in_array( $district, array( 'Kandy', 'Galle', 'Kalutara', 'Kurunegala' ), true ) ) {
			return self::TIER_REGIONAL;
		}

		return self::TIER_ISLAND;
	}

	/**
	 * LK address locale.
	 *
	 * - state    → labelled "District", required (we now have a real list).
	 * - postcode → NOT required; Sri Lankan shoppers frequently do not know it.
	 * - address_2→ hidden; couriers use the landmark field instead.
	 * - priority → the field order the design lays out. WooCommerce honours
	 *              'priority' inside a locale entry, so ordering the address
	 *              block does not need a template override.
	 *
	 * Priorities are also re-asserted on the checkout fields (see
	 * SLK_Checkout_Fields) because the checkout adds phone/email/landmark,
	 * which are not part of the address-field locale.
	 *
	 * @param array $locale Country locale array.
	 */
	public static function register_locale( $locale ) {
		if ( ! is_array( $locale ) ) {
			return $locale;
		}

		$locale[ self::COUNTRY ] = array(
			'first_name' => array(
				'label'    => __( 'Full name', 'slk' ),
				'required' => true,
				'class'    => array( 'form-row-wide' ),
				'priority' => 20,
			),
			'last_name'  => array(
				// Collapsed into "Full name" and split again on submit.
				'required' => false,
				'hidden'   => true,
				'priority' => 21,
			),
			'company'    => array(
				'required' => false,
				'hidden'   => true,
			),
			'address_1'  => array(
				'label'       => __( 'Address', 'slk' ),
				'placeholder' => __( 'House no. and street', 'slk' ),
				'required'    => true,
				'priority'    => 50,
			),
			'address_2'  => array(
				'required' => false,
				'hidden'   => true,
			),
			'city'       => array(
				'label'    => __( 'City / town', 'slk' ),
				'required' => true,
				'class'    => array( 'form-row-first' ),
				'priority' => 60,
			),
			'state'      => array(
				'label'    => __( 'District', 'slk' ),
				'required' => true,
				'hidden'   => false,
				'class'    => array( 'form-row-last' ),
				'priority' => 70,
			),
			'postcode'   => array(
				'label'    => __( 'Postal code', 'slk' ),
				// Not required: WooCommerce appends "(optional)" to the label
				// for us, which is what the design shows.
				'required' => false,
				'hidden'   => false,
				'class'    => array( 'form-row-wide' ),
				'priority' => 80,
			),
		);

		return $locale;
	}

	public static function default_country( $country ) {
		return $country ? $country : self::COUNTRY;
	}
}
