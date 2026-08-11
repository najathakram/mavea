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

		// Goods value for this package, excluding tax and any existing fees.
		$contents_cost = isset( $package['contents_cost'] ) ? (float) $package['contents_cost'] : 0.0;

		$free_over = SLK_Money::rupees( $this->get_option( 'free_over', SLK_Shipping::FREE_OVER ) );
		$is_free   = $free_over > 0 && $contents_cost >= $free_over;

		if ( $is_free ) {
			$cost  = 0.0;
			$label = __( 'Free delivery', 'slk' );
		} else {
			$cost  = $this->district_cost( $district );
			$label = $this->rate_label( $district );
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
	 * An unknown or not-yet-chosen district rates at the island band. Quoting
	 * the cheapest band before the shopper picks a district would show a number
	 * that then goes UP, which reads as a bait — and quoting it after the fact
	 * would mean shipping the far districts at a loss.
	 */
	private function district_cost( $district ): float {
		switch ( SLK_Districts::tier( $district ) ) {
			case SLK_Districts::TIER_METRO:
				$option = $this->get_option( 'fee_metro', SLK_Shipping::FEE_METRO );
				break;
			case SLK_Districts::TIER_REGIONAL:
				$option = $this->get_option( 'fee_regional', SLK_Shipping::FEE_REGIONAL );
				break;
			default:
				$option = $this->get_option( 'fee_island', SLK_Shipping::FEE_ISLAND );
		}

		return max( 0.0, SLK_Money::rupees( $option ) );
	}

	private function rate_label( $district ): string {
		$base = $this->title ? $this->title : __( 'Delivery', 'slk' );

		if ( SLK_Districts::is_district( $district ) ) {
			return sprintf(
				/* translators: 1: method name, 2: district, 3: expected days */
				__( '%1$s — %2$s · %3$s', 'slk' ),
				$base,
				$district,
				SLK_Shipping::tier_label( $district )
			);
		}

		return $base;
	}
}
