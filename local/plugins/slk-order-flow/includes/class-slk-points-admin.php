<?php
/**
 * WooCommerce Settings -> Accounts & Privacy -> Loyalty points. One field per
 * key in SLK_Points::defaults(), all stored in the single SLK_Points::OPTION
 * array — the same one-array-one-screen shape
 * class-slk-fulfilment-admin.php (in slk-checkout) uses for the delivery
 * promise.
 *
 * @package slk-order-flow
 */

defined( 'ABSPATH' ) || exit;

final class SLK_Points_Admin {

	public static function init(): void {
		add_filter( 'woocommerce_get_sections_account', array( __CLASS__, 'add_section' ) );
		add_filter( 'woocommerce_get_settings_account', array( __CLASS__, 'add_settings' ), 10, 2 );
		add_filter( 'woocommerce_admin_settings_sanitize_option', array( __CLASS__, 'sanitize_option' ), 10, 3 );
	}

	/**
	 * @param array $sections Accounts & Privacy tab sections.
	 */
	public static function add_section( $sections ) {
		if ( ! is_array( $sections ) ) {
			return $sections;
		}

		$sections['slk_points'] = __( 'Loyalty points', 'slk' );

		return $sections;
	}

	/**
	 * @param array  $settings        Settings for the current account section.
	 * @param string $current_section Section being rendered.
	 */
	public static function add_settings( $settings, $current_section ) {
		if ( 'slk_points' !== $current_section ) {
			return $settings;
		}

		return self::settings_fields();
	}

	/**
	 * Bracket-notation id for one key of SLK_Points::OPTION, the form
	 * WooCommerce's settings API reads and writes the whole array through.
	 */
	private static function field_id( string $key ): string {
		return SLK_Points::OPTION . '[' . $key . ']';
	}

	private static function settings_fields(): array {
		$settings = SLK_Points::settings();

		return array(

			array(
				'title' => __( 'Loyalty points', 'slk' ),
				'type'  => 'title',
				'desc'  => __( 'How points are earned on a paid order and what they are worth as credit towards a later one. Points never expire and a guest checkout earns nothing.', 'slk' ),
				'id'    => 'slk_points_options',
			),

			array(
				'title'             => __( 'Points earned per rupee', 'slk' ),
				'desc'              => __( 'Points credited for every rupee of an order item total, delivery excluded.', 'slk' ),
				'id'                => self::field_id( 'rate' ),
				'type'              => 'number',
				'value'             => (string) (float) $settings['rate'],
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'desc_tip'          => true,
			),

			array(
				'title'             => __( 'Redemption block', 'slk' ),
				'desc'              => __( 'Points needed for one block of credit. Points are only ever spent in whole blocks.', 'slk' ),
				'id'                => self::field_id( 'block_points' ),
				'type'              => 'number',
				'value'             => (string) (int) $settings['block_points'],
				'custom_attributes' => array(
					'min'  => '1',
					'step' => '1',
				),
				'desc_tip'          => true,
			),

			array(
				'title'             => __( 'Block value', 'slk' ),
				'desc'              => __( 'Rupees of credit one redemption block is worth.', 'slk' ),
				'id'                => self::field_id( 'block_value' ),
				'type'              => 'number',
				'value'             => (string) SLK_Money::rupees( $settings['block_value'] ),
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'desc_tip'          => true,
			),

			array(
				'type' => 'sectionend',
				'id'   => 'slk_points_options',
			),
		);
	}

	/**
	 * A redemption block of zero would divide by zero everywhere points are
	 * spent, so it is floored at one; the other two amounts simply cannot go
	 * negative.
	 *
	 * @param mixed $value     Sanitised value produced by the field's type.
	 * @param array $option    The field definition.
	 * @param mixed $raw_value Raw posted value before type handling.
	 */
	public static function sanitize_option( $value, $option, $raw_value ) {
		if ( ! is_array( $option ) || ! isset( $option['id'] ) ) {
			return $value;
		}

		if ( self::field_id( 'block_points' ) === $option['id'] ) {
			$points = is_numeric( $value ) ? (int) $value : 0;

			return $points > 0 ? $points : SLK_Points::defaults()['block_points'];
		}

		if ( self::field_id( 'rate' ) === $option['id'] || self::field_id( 'block_value' ) === $option['id'] ) {
			return is_numeric( $value ) ? max( 0, (float) $value ) : 0;
		}

		return $value;
	}
}
