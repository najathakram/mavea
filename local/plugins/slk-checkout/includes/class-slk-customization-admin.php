<?php
/**
 * The "Customization" product data tab: a repeatable builder for preset
 * option groups, plus the fixed custom-length block. Both write the JSON
 * SLK_Customization reads.
 *
 * No admin does the JSON authoring themselves — a group's storage `key` is
 * derived from its label (and de-duplicated) on save, so the tab only ever
 * asks for words a shop owner would type anyway: a label, a fee, a day
 * count.
 *
 * The repeater has no build step and this plugin ships no bundled assets,
 * so its "add row" / "remove row" behaviour is a small inline script
 * printed in the admin footer, built from the same row-renderer used for
 * the initial markup — one template, not two copies to keep in sync.
 *
 * @package slk-checkout
 */

defined( 'ABSPATH' ) || exit;

final class SLK_Customization_Admin {

	public static function init(): void {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_panel' ) );
		add_action( 'admin_footer', array( __CLASS__, 'print_repeater_script' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Tab registration                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * @param array $tabs Registered product data tabs.
	 */
	public static function add_tab( $tabs ) {
		if ( ! is_array( $tabs ) ) {
			return $tabs;
		}

		$tabs['slk_customization'] = array(
			'label'    => __( 'Customization', 'slk' ),
			'target'   => 'slk_customization_data',
			// Same product types SLK_Fulfilment_Admin's fields apply to: a
			// grouped or external/affiliate listing has no buy dock of its
			// own to attach a selection to.
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 65, // Between the core "Variations" (60) and "Advanced" (70) tabs.
		);

		return $tabs;
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                           */
	/* ------------------------------------------------------------------ */

	public static function render_panel(): void {
		global $product_object;

		if ( ! $product_object instanceof WC_Product ) {
			return;
		}

		$product_id = $product_object->get_id();
		$groups     = self::raw_groups( $product_id );
		$length     = self::raw_length( $product_id );

		echo '<div id="slk_customization_data" class="panel woocommerce_options_panel">';

		echo '<div class="options_group">';
		echo '<p class="form-field"><em>' . esc_html__( 'Preset choices a shopper picks before adding this piece to the bag — colour, engraving, style, and so on. Each choice can carry its own fee and add to the making time. Variations inherit these groups from this parent product.', 'slk' ) . '</em></p>';

		echo '<div id="slk-custom-groups">';

		$gi = 0;
		foreach ( $groups as $group ) {
			echo self::group_row_html( $gi, is_array( $group ) ? $group : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped field-by-field inside group_row_html().
			++$gi;
		}

		echo '</div>';

		echo '<p><button type="button" class="button button-primary" id="slk-add-group">' . esc_html__( '+ Add option group', 'slk' ) . '</button></p>';
		echo '</div>';

		echo '<div class="options_group slk-custom-length">';
		echo '<p class="form-field"><em>' . esc_html__( 'Optional length-in-cm field shown above the buy button, on top of any option groups above.', 'slk' ) . '</em></p>';

		woocommerce_wp_checkbox(
			array(
				'id'          => 'slk_custom_length_enabled',
				'name'        => '_slk_custom_length[enabled]',
				'label'       => __( 'Custom length', 'slk' ),
				'description' => __( 'Ask the shopper for a length in cm before this piece can be added to the bag.', 'slk' ),
				'value'       => $length['enabled'] ? 'yes' : 'no',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => 'slk_custom_length_fee',
				'name'              => '_slk_custom_length[fee]',
				'label'             => __( 'Length fee (Rs.)', 'slk' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'value'             => (string) SLK_Money::rupees( $length['fee'] ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => 'slk_custom_length_extra_days',
				'name'              => '_slk_custom_length[extra_days]',
				'label'             => __( 'Extra making days', 'slk' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'value'             => (string) (int) $length['extra_days'],
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => 'slk_custom_length_min_cm',
				'name'              => '_slk_custom_length[min_cm]',
				'label'             => __( 'Minimum length (cm)', 'slk' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'value'             => (string) (int) $length['min_cm'],
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => 'slk_custom_length_max_cm',
				'name'              => '_slk_custom_length[max_cm]',
				'label'             => __( 'Maximum length (cm)', 'slk' ),
				'desc_tip'          => true,
				'description'       => __( 'Kept at or above the minimum automatically on save.', 'slk' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'value'             => (string) (int) $length['max_cm'],
			)
		);

		echo '</div>';

		echo '</div>';
	}

	/**
	 * Markup for one option group row: its label/required controls, its
	 * choice table, and an "add choice" button. Reused three ways — real
	 * rows on page load, the "new group" JS template (empty group, index
	 * token in place of a real one), and, indirectly, every choice row it
	 * contains via choice_row_html().
	 *
	 * @param int|string $gi    Row index for the `slk_custom_options[$gi]`
	 *                          name, or the `__GI__` placeholder token.
	 * @param array      $group Raw stored group (label/required/choices),
	 *                          empty for a fresh template row.
	 */
	private static function group_row_html( $gi, array $group = array() ): string {
		$label    = isset( $group['label'] ) && is_scalar( $group['label'] ) ? (string) $group['label'] : '';
		$required = ! empty( $group['required'] );
		$choices  = isset( $group['choices'] ) && is_array( $group['choices'] ) ? $group['choices'] : array();

		ob_start();
		?>
		<div class="slk-custom-group" data-gi="<?php echo esc_attr( $gi ); ?>" style="border:1px solid #dcdcde;padding:12px;margin:0 12px 12px;">
			<table class="widefat">
				<tbody>
					<tr>
						<td style="border:none;width:55%;">
							<input type="text" class="regular-text" placeholder="<?php echo esc_attr__( 'e.g. Frame colour', 'slk' ); ?>" name="slk_custom_options[<?php echo esc_attr( $gi ); ?>][label]" value="<?php echo esc_attr( $label ); ?>" />
						</td>
						<td style="border:none;">
							<label><input type="checkbox" name="slk_custom_options[<?php echo esc_attr( $gi ); ?>][required]" value="1" <?php checked( $required ); ?> /> <?php esc_html_e( 'Required', 'slk' ); ?></label>
						</td>
						<td style="border:none;text-align:right;">
							<button type="button" class="button slk-remove-group"><?php esc_html_e( 'Remove group', 'slk' ); ?></button>
						</td>
					</tr>
				</tbody>
			</table>
			<table class="widefat">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Choice', 'slk' ); ?></th>
						<th style="width:15%;"><?php esc_html_e( 'Fee (Rs.)', 'slk' ); ?></th>
						<th style="width:20%;"><?php esc_html_e( 'Extra making days', 'slk' ); ?></th>
						<th style="width:10%;"></th>
					</tr>
				</thead>
				<tbody class="slk-choice-rows">
					<?php
					if ( empty( $choices ) ) {
						echo self::choice_row_html( $gi, 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped field-by-field inside choice_row_html().
					} else {
						$ci = 0;
						foreach ( $choices as $choice ) {
							echo self::choice_row_html( $gi, $ci, is_array( $choice ) ? $choice : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							++$ci;
						}
					}
					?>
				</tbody>
			</table>
			<p><button type="button" class="button slk-add-choice"><?php esc_html_e( '+ Add choice', 'slk' ); ?></button></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param int|string $gi     Owning group's index/token, see group_row_html().
	 * @param int|string $ci     Row index for `choices[$ci]`, or the `__CI__` token.
	 * @param array      $choice Raw stored choice (label/fee/extra_days).
	 */
	private static function choice_row_html( $gi, $ci, array $choice = array() ): string {
		$label      = isset( $choice['label'] ) && is_scalar( $choice['label'] ) ? (string) $choice['label'] : '';
		$fee        = isset( $choice['fee'] ) && is_numeric( $choice['fee'] ) ? (string) SLK_Money::rupees( $choice['fee'] ) : '0';
		$extra_days = isset( $choice['extra_days'] ) && is_numeric( $choice['extra_days'] ) ? (string) (int) $choice['extra_days'] : '0';

		ob_start();
		?>
		<tr>
			<td><input type="text" class="regular-text" name="slk_custom_options[<?php echo esc_attr( $gi ); ?>][choices][<?php echo esc_attr( $ci ); ?>][label]" value="<?php echo esc_attr( $label ); ?>" /></td>
			<td><input type="number" min="0" step="1" name="slk_custom_options[<?php echo esc_attr( $gi ); ?>][choices][<?php echo esc_attr( $ci ); ?>][fee]" value="<?php echo esc_attr( $fee ); ?>" /></td>
			<td><input type="number" min="0" step="1" name="slk_custom_options[<?php echo esc_attr( $gi ); ?>][choices][<?php echo esc_attr( $ci ); ?>][extra_days]" value="<?php echo esc_attr( $extra_days ); ?>" /></td>
			<td><button type="button" class="button-link-delete slk-remove-choice"><?php esc_html_e( 'Remove', 'slk' ); ?></button></td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Deliberately lenient: this feeds the edit screen, not the storefront.
	 * SLK_Customization::groups_for() drops an incomplete row silently (the
	 * right behaviour for a shopper-facing render); doing the same here
	 * would make a half-finished row vanish out from under the admin
	 * mid-edit. Anything not recognisably an array of arrays reads as "no
	 * groups yet" rather than fataling the product screen.
	 *
	 * @return array<int,array>
	 */
	private static function raw_groups( int $product_id ): array {
		$raw = get_post_meta( $product_id, SLK_Customization::META_OPTIONS, true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) ? $decoded : array();
	}

	/**
	 * @return array{enabled:bool,fee:float,extra_days:int,min_cm:int,max_cm:int}
	 */
	private static function raw_length( int $product_id ): array {
		$defaults = SLK_Customization::length_defaults();
		$raw      = get_post_meta( $product_id, SLK_Customization::META_LENGTH, true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return $defaults;
		}

		$decoded = json_decode( $raw, true );

		return ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) )
			? wp_parse_args( $decoded, $defaults )
			: $defaults;
	}

	/* ------------------------------------------------------------------ */
	/* Saving: the hard sanitizer. Everything the admin typed is untrusted */
	/* until it passes through here.                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * @param WC_Product $product Product being saved.
	 */
	public static function save_panel( $product ): void {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$posted_groups = ( isset( $_POST['slk_custom_options'] ) && is_array( $_POST['slk_custom_options'] ) )
			? wp_unslash( $_POST['slk_custom_options'] )
			: array();

		$posted_length = ( isset( $_POST['_slk_custom_length'] ) && is_array( $_POST['_slk_custom_length'] ) )
			? wp_unslash( $_POST['_slk_custom_length'] )
			: array();

		$product->update_meta_data( SLK_Customization::META_OPTIONS, wp_json_encode( self::sanitize_groups( $posted_groups ) ) );
		$product->update_meta_data( SLK_Customization::META_LENGTH, wp_json_encode( self::sanitize_length( $posted_length ) ) );
	}

	/**
	 * @param mixed $raw Unslashed $_POST['slk_custom_options'], or absent.
	 * @return array<int,array{key:string,label:string,required:bool,choices:array}>
	 */
	private static function sanitize_groups( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$groups    = array();
		$used_keys = array();

		foreach ( $raw as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$label = isset( $group['label'] ) && is_scalar( $group['label'] ) ? sanitize_text_field( (string) $group['label'] ) : '';

			if ( '' === $label ) {
				continue; // Nothing to show a shopper: drop the row rather than save a blank one.
			}

			$choices = self::sanitize_choices( $group['choices'] ?? array() );

			if ( empty( $choices ) ) {
				continue; // A group with nothing to pick from can never be resolved on the storefront.
			}

			$groups[] = array(
				'key'      => self::unique_key( $label, $used_keys ),
				'label'    => $label,
				'required' => ! empty( $group['required'] ),
				'choices'  => $choices,
			);
		}

		return $groups;
	}

	/**
	 * @param mixed $raw Unslashed choices sub-array for one group.
	 * @return array<int,array{key:string,label:string,fee:float,extra_days:int}>
	 */
	private static function sanitize_choices( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$choices   = array();
		$used_keys = array();

		foreach ( $raw as $choice ) {
			if ( ! is_array( $choice ) ) {
				continue;
			}

			$label = isset( $choice['label'] ) && is_scalar( $choice['label'] ) ? sanitize_text_field( (string) $choice['label'] ) : '';

			if ( '' === $label ) {
				continue; // An unlabelled choice is not something a shopper could pick.
			}

			$choices[] = array(
				'key'        => self::unique_key( $label, $used_keys ),
				'label'      => $label,
				'fee'        => max( 0.0, SLK_Money::rupees( self::posted_number( $choice['fee'] ?? null ) ) ),
				'extra_days' => max( 0, (int) self::posted_number( $choice['extra_days'] ?? null ) ),
			);
		}

		return $choices;
	}

	/**
	 * @param mixed $raw Unslashed $_POST['_slk_custom_length'], or absent.
	 * @return array{enabled:bool,fee:float,extra_days:int,min_cm:int,max_cm:int}
	 */
	private static function sanitize_length( $raw ): array {
		$defaults = SLK_Customization::length_defaults();

		if ( ! is_array( $raw ) ) {
			return $defaults;
		}

		$min_cm = max( 0, (int) self::posted_number( $raw['min_cm'] ?? null ) );
		// Never let a mistyped maximum sit below the minimum: the length
		// input on the storefront would have no valid value to accept.
		$max_cm = max( $min_cm, (int) self::posted_number( $raw['max_cm'] ?? null ) );

		return array(
			'enabled'    => ! empty( $raw['enabled'] ),
			'fee'        => max( 0.0, SLK_Money::rupees( self::posted_number( $raw['fee'] ?? null ) ) ),
			'extra_days' => max( 0, (int) self::posted_number( $raw['extra_days'] ?? null ) ),
			'min_cm'     => $min_cm,
			'max_cm'     => $max_cm,
		);
	}

	/**
	 * Storage key for a group or a choice, built from its label because the
	 * form never asks the admin to type one. Keys only need to be unique
	 * among siblings in the same list (a group's choices, or the groups
	 * themselves) — resolve() looks a key up by walking that same list.
	 *
	 * @param array<string,bool> $used_keys Keys already claimed among this
	 *                                       row's siblings; updated in place.
	 */
	private static function unique_key( string $label, array &$used_keys ): string {
		$base = sanitize_key( $label );
		$base = ( '' !== $base ) ? $base : 'option';
		$key  = $base;
		$n    = 2;

		while ( isset( $used_keys[ $key ] ) ) {
			$key = $base . '-' . $n;
			++$n;
		}

		$used_keys[ $key ] = true;

		return $key;
	}

	/**
	 * A number out of $_POST is always a string (or absent) before this —
	 * never trust it to cast cleanly on its own.
	 */
	private static function posted_number( $value ): float {
		return is_numeric( $value ) ? (float) $value : 0.0;
	}

	/* ------------------------------------------------------------------ */
	/* The repeater script.                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Row-add/remove behaviour for the group and choice repeaters, printed
	 * only on the product edit screen. Both templates are rendered by the
	 * same functions that draw the real rows, with `__GI__`/`__CI__` in
	 * place of a real index, so the JS and PHP markup can never drift apart.
	 *
	 * Indices do not need to be unique across the product's whole save
	 * history, only among the rows currently in the DOM at submit time: a
	 * fresh row always takes the next index after however many siblings are
	 * presently visible, and removing a row simply removes its inputs, so
	 * two rows never end up sharing a `name` at once.
	 */
	public static function print_repeater_script(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen instanceof WP_Screen || 'product' !== $screen->id ) {
			return;
		}

		$group_template  = self::group_row_html( '__GI__' );
		$choice_template = self::choice_row_html( '__GI__', '__CI__' );
		?>
		<script type="text/javascript">
		( function ( $ ) {
			'use strict';

			var GROUP_TEMPLATE  = <?php echo wp_json_encode( $group_template ); ?>;
			var CHOICE_TEMPLATE = <?php echo wp_json_encode( $choice_template ); ?>;

			function groupHtml( gi ) {
				return GROUP_TEMPLATE.split( '__GI__' ).join( gi );
			}

			function choiceHtml( gi, ci ) {
				return CHOICE_TEMPLATE.split( '__GI__' ).join( gi ).split( '__CI__' ).join( ci );
			}

			$( document ).on( 'click', '#slk-add-group', function ( e ) {
				e.preventDefault();
				var $list = $( '#slk-custom-groups' );
				var gi = $list.children( '.slk-custom-group' ).length;
				$list.append( groupHtml( gi ) );
			} );

			$( document ).on( 'click', '.slk-remove-group', function ( e ) {
				e.preventDefault();
				$( this ).closest( '.slk-custom-group' ).remove();
			} );

			$( document ).on( 'click', '.slk-add-choice', function ( e ) {
				e.preventDefault();
				var $group = $( this ).closest( '.slk-custom-group' );
				var gi = $group.data( 'gi' );
				var $rows = $group.find( '.slk-choice-rows' );
				var ci = $rows.children( 'tr' ).length;
				$rows.append( choiceHtml( gi, ci ) );
			} );

			$( document ).on( 'click', '.slk-remove-choice', function ( e ) {
				e.preventDefault();
				$( this ).closest( 'tr' ).remove();
			} );
		} )( jQuery );
		</script>
		<?php
	}
}
