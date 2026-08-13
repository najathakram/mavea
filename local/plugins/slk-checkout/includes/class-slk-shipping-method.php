<?php
/**
 * The Sri Lanka delivery shipping method.
 *
 * Three district bands plus a free-delivery threshold, all editable in the
 * shipping zone UI so the rates can move without a deploy.
 *
 * Loaded on woocommerce_shipping_init, after WC_Shipping_Method exists.
 *
 * @package slk
 */

defined( 'ABSPATH' ) || exit;

class SLK_Shipping_Method extends WC_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = SLK_Shipping::METHOD_ID;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Sri Lanka delivery', 'slk' );
		$this->method_description = __( 'Courier rates by district, with free delivery over a set order value.', 'slk' );
		$this->supports           = array(
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		);

		$this->init();
	}

	public function init() {
		$this->init_form_fields();
		$this->init_settings();

		$this->title = $this->get_option( 'title', __( 'Delivery', 'slk' ) );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields() {
		$this->instance_form_fields = array(
			'title'         => array(
				'title'       => __( 'Name shown at checkout', 'slk' ),
				'type'        => 'text',
				'default'     => __( 'Delivery', 'slk' ),
				'desc_tip'    => true,
				'description' => __( 'The district and the expected days are appended automatically.', 'slk' ),
			),
			'fee_metro'     => array(
				'title'             => __( 'Colombo & Gampaha (Rs.)', 'slk' ),
				'type'              => 'number',
				'default'           => (string) SLK_Shipping::FEE_METRO,
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			),
			'fee_regional'  => array(
				'title'             => __( 'Kandy, Galle, Kalutara, Kurunegala (Rs.)', 'slk' ),
				'type'              => 'number',
				'default'           => (string) SLK_Shipping::FEE_REGIONAL,
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			),
			'fee_island'    => array(
				'title'             => __( 'All other districts (Rs.)', 'slk' ),
				'type'              => 'number',
				'default'           => (string) SLK_Shipping::FEE_ISLAND,
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			),
			'free_over'     => array(
				'title'             => __( 'Free delivery over (Rs.)', 'slk' ),
				'type'              => 'number',
				'default'           => (string) SLK_Shipping::FREE_OVER,
				'description'       => __( 'Compared against the value of the goods, before delivery. Set to 0 to switch free delivery off.', 'slk' ),
				'desc_tip'          => true,
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			),
		);
	}

	/**
	 * @param array $package Shipping package.
	 */
	public function calculate_shipping( $package = array() ) {
		$district = isset( $package['destination']['state'] ) ? (string) $package['destination']['state'] : '';
		$country  = isset( $package['destination']['country'] ) ? (string) $package['destination']['country'] : '';

		if ( '' !== $country && SLK_Districts::COUNTRY !== $country ) {
			return; // This method only rates Sri Lankan addresses.
		}

		// The per-shipment pricing in SLK_Shipments has to charge what this
		// instance charges, so hand it the instance before asking it for a
		// number. Otherwise the rates edited in the zone UI would move the
		// checkout total and leave the cart's own delivery lines behind.
		SLK_Shipping::set_rating_method( $this );

		$shipments = SLK_Shipments::build();

		if ( empty( $shipments ) ) {
			// An empty or unusual cart must never price as free by accident:
			// fall back to the single-fee behaviour this method always had.
			$contents_cost = isset( $package['contents_cost'] ) ? (float) $package['contents_cost'] : 0.0;

			$free_over = SLK_Shipping::free_over();
			$is_free   = $free_over > 0 && $contents_cost >= $free_over;

			if ( $is_free ) {
				$cost  = 0.0;
				$label = __( 'Free delivery', 'slk' );
			} else {
				$cost  = SLK_Shipping::fee_for_district( $district );
				$label = $this->rate_label( $district );
			}
		} else {
			// One rate for the whole cart, priced as the sum of every
			// shipment: the cart keeps one shipping line and WooCommerce
			// keeps one package.
			$cost    = SLK_Shipments::total_fee( $district );
			$is_free = $cost <= 0.0;

			if ( $is_free ) {
				$label = __( 'Free delivery', 'slk' );
			} else {
				$shipment_count = count( $shipments );
				$detail         = $shipment_count > 1
					? sprintf(
						/* translators: %s: number of shipments */
						_n( '%s shipment', '%s shipments', $shipment_count, 'slk' ),
						number_format_i18n( $shipment_count )
					)
					: null;

				$label = $this->rate_label( $district, $detail );
			}
		}

		$this->add_rate(
			array(
				'id'        => $this->get_rate_id(),
				'label'     => apply_filters( 'slk_shipping_rate_label', $label, $district, $is_free ),
				'cost'      => $cost,
				'package'   => $package,
				'meta_data' => array(
					__( 'District', 'slk' ) => '' !== $district ? $district : __( 'Not chosen yet', 'slk' ),
				),
			)
		);
	}

	/**
	 * @param string      $district District name.
	 * @param string|null $detail   Overrides the trailing detail, e.g. a
	 *                              shipment count, instead of the expected
	 *                              working-day range for a single delivery.
	 */
	private function rate_label( $district, ?string $detail = null ): string {
		$base = $this->title ? $this->title : __( 'Delivery', 'slk' );

		if ( SLK_Districts::is_district( $district ) ) {
			return sprintf(
				/* translators: 1: method name, 2: district, 3: expected days, or the number of shipments when the order is split */
				__( '%1$s to %2$s · %3$s', 'slk' ),
				$base,
				$district,
				null !== $detail ? $detail : SLK_Shipping::tier_label( $district )
			);
		}

		return $base;
	}
}
