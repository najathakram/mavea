<?php
/**
 * Per-product customization: preset option groups plus an optional custom
 * length. This file owns the data model, the storage keys, and the ONE
 * resolver that turns a shopper's selection into a validated fee/day/label
 * answer.
 *
 * Every other package (storefront rendering, cart line pricing, packing
 * slip) must call SLK_Customization::resolve() rather than re-deriving a
 * fee or a day count from the raw meta — that keeps "is this selection
 * allowed" and "what does it cost" answered in exactly one place.
 *
 * @package slk-checkout
 */

defined( 'ABSPATH' ) || exit;

final class SLK_Customization {

	public const META_OPTIONS = '_slk_custom_options';
	public const META_LENGTH  = '_slk_custom_length';

	/* ------------------------------------------------------------------ */
	/* Reading the model                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * A variation carries no customization meta of its own: a group applies
	 * to every size/colour of a design alike, so it is configured once on
	 * the parent — the same resolution SLK_Fulfilment::making_days() uses.
	 */
	private static function config_id( WC_Product $product ): int {
		return $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
	}

	/**
	 * @return array<int,array{key:string,label:string,required:bool,choices:array<int,array{key:string,label:string,fee:float,extra_days:int}>}>
	 */
	public static function groups_for( WC_Product $product ): array {
		$raw = get_post_meta( self::config_id( $product ), self::META_OPTIONS, true );

		return self::normalize_groups( self::decode( $raw ) );
	}

	/**
	 * @return array{enabled:bool,fee:float,extra_days:int,min_cm:int,max_cm:int}
	 */
	public static function length_for( WC_Product $product ): array {
		$raw = get_post_meta( self::config_id( $product ), self::META_LENGTH, true );

		return self::normalize_length( self::decode( $raw ) );
	}

	/**
	 * Whether this product has anything to customize at all — the cheap
	 * check the storefront uses to decide whether to render the block.
	 */
	public static function has_any( WC_Product $product ): bool {
		if ( ! empty( self::groups_for( $product ) ) ) {
			return true;
		}

		return self::length_for( $product )['enabled'];
	}

	/**
	 * @return array{enabled:bool,fee:float,extra_days:int,min_cm:int,max_cm:int}
	 */
	public static function length_defaults(): array {
		return array(
			'enabled'    => false,
			'fee'        => 0.0,
			'extra_days' => 0,
			'min_cm'     => 0,
			'max_cm'     => 0,
		);
	}

	/* ------------------------------------------------------------------ */
	/* The resolver: the one place a selection is validated and priced.    */
	/* ------------------------------------------------------------------ */

	/**
	 * @param WC_Product $product   Simple/variable product, or a variation
	 *                              (resolved to its parent's configuration).
	 * @param array      $selection Raw, unsanitized shopper input:
	 *                              array{
	 *                                  groups?: array<string,string>,
	 *                                  length_cm?: mixed,
	 *                              }
	 *                              `groups` maps a group key to the chosen
	 *                              choice key; a group left out is "nothing
	 *                              chosen". `length_cm` is only read when the
	 *                              product's length block is enabled.
	 * @return array{valid:bool,errors:array<int,string>,fee:float,extra_days:int,labels:array<int,array{label:string,value:string}>}
	 *               `labels` is an ORDERED LIST of label/value pairs, ready to
	 *               drop into cart item data / order line meta. It is a list
	 *               rather than a label-keyed map because two groups are allowed
	 *               to share a display label: keyed by label, the second silently
	 *               overwrote the first and the shopper lost a choice from the
	 *               cart, the order and the slip while still being charged for
	 *               both. A list also preserves the order the groups were
	 *               configured in.
	 *               An invalid selection prices at zero: a caller that skips
	 *               the `valid` check adds nothing, never a guess.
	 */
	public static function resolve( WC_Product $product, array $selection ): array {
		$groups = self::groups_for( $product );
		$length = self::length_for( $product );
		$picked = isset( $selection['groups'] ) && is_array( $selection['groups'] ) ? $selection['groups'] : array();

		$errors     = array();
		$fee        = 0.0;
		$extra_days = 0;
		$labels     = array();

		foreach ( $groups as $group ) {
			$choice_key = isset( $picked[ $group['key'] ] ) && is_scalar( $picked[ $group['key'] ] )
				? sanitize_key( (string) $picked[ $group['key'] ] )
				: '';

			if ( '' === $choice_key ) {
				if ( $group['required'] ) {
					/* translators: %s: the option group's label, e.g. "Frame colour". */
					$errors[] = sprintf( __( '%s is required.', 'slk' ), $group['label'] );
				}
				continue;
			}

			$choice = self::find_choice( $group['choices'], $choice_key );

			if ( null === $choice ) {
				/* translators: %s: the option group's label, e.g. "Frame colour". */
				$errors[] = sprintf( __( 'Choose a valid option for %s.', 'slk' ), $group['label'] );
				continue;
			}

			$fee        += $choice['fee'];
			$extra_days += $choice['extra_days'];
			$labels[]    = array(
				'label' => $group['label'],
				'value' => $choice['label'],
			);
		}

		if ( $length['enabled'] ) {
			$raw_length = $selection['length_cm'] ?? null;
			$blank      = null === $raw_length || '' === trim( (string) $raw_length );

			/*
			 * Blank means "standard length", not "you forgot something". The
			 * field this reads renders with a "Leave blank for standard length"
			 * placeholder and no required attribute, so treating an empty value
			 * as an error made the form contradict itself: it invited her to
			 * leave it alone and then refused the add-to-cart when she did.
			 * Nothing is charged and no extra days accrue for a piece cut to the
			 * length it is already cut to.
			 */
			if ( ! $blank ) {
				if ( ! is_numeric( $raw_length ) ) {
					$errors[] = __( 'Enter the custom length in centimetres, or leave it blank.', 'slk' );
				} else {
					$length_cm = (int) round( (float) $raw_length );

					/*
					 * max_cm 0 is "no upper bound", which is what the admin field
					 * means when it is left empty and what the PDP input already
					 * assumed — it omits the `max` attribute in that case. Reading
					 * it as a literal ceiling made every length fail against
					 * "between 0 and 0 cm" and left the product unbuyable the
					 * moment the block was switched on without a maximum.
					 */
					$has_max = $length['max_cm'] > 0;

					if ( $length_cm < $length['min_cm'] || ( $has_max && $length_cm > $length['max_cm'] ) ) {
						if ( $has_max ) {
							/* translators: 1: minimum length in cm, 2: maximum length in cm. */
							$errors[] = sprintf( __( 'Custom length must be between %1$d and %2$d cm.', 'slk' ), $length['min_cm'], $length['max_cm'] );
						} else {
							/* translators: %d: minimum length in cm. */
							$errors[] = sprintf( __( 'Custom length must be at least %d cm.', 'slk' ), $length['min_cm'] );
						}
					} else {
						$fee        += $length['fee'];
						$extra_days += $length['extra_days'];
						$labels[]    = array(
							'label' => __( 'Custom length', 'slk' ),
							/* translators: %d: the chosen length in centimetres. */
							'value' => sprintf( __( '%d cm', 'slk' ), $length_cm ),
						);
					}
				}
			}
		}

		$valid = empty( $errors );

		return array(
			'valid'      => $valid,
			'errors'     => $errors,
			'fee'        => $valid ? max( 0.0, SLK_Money::rupees( $fee ) ) : 0.0,
			'extra_days' => $valid ? max( 0, $extra_days ) : 0,
			'labels'     => $valid ? $labels : array(),
		);
	}

	/**
	 * @param array<int,array{key:string,label:string,fee:float,extra_days:int}> $choices
	 */
	private static function find_choice( array $choices, string $key ): ?array {
		foreach ( $choices as $choice ) {
			if ( $choice['key'] === $key ) {
				return $choice;
			}
		}

		return null;
	}

	/* ------------------------------------------------------------------ */
	/* Decoding + normalizing: defensive on every field, because this runs */
	/* against whatever is in the database, not just what the admin tab   */
	/* writes. Anything that does not parse cleanly degrades to "no       */
	/* customization" rather than fataling the product page.              */
	/* ------------------------------------------------------------------ */

	/**
	 * @return mixed Decoded JSON value, or null when $raw is not a non-empty
	 *               string of valid JSON.
	 */
	private static function decode( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );

		return ( JSON_ERROR_NONE === json_last_error() ) ? $decoded : null;
	}

	/**
	 * A number from a source that might hand back a string, a bool, an
	 * array, or nothing at all — every value here ultimately came from
	 * json_decode() of hand-edited or legacy data, not from our own
	 * sanitizer, so it cannot be trusted to already be numeric.
	 */
	private static function numeric( $value, float $default = 0.0 ): float {
		return is_numeric( $value ) ? (float) $value : $default;
	}

	/**
	 * @return array<int,array{key:string,label:string,required:bool,choices:array}>
	 */
	private static function normalize_groups( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$groups    = array();
		$used_keys = array();

		foreach ( $raw as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$key   = isset( $group['key'] ) && is_scalar( $group['key'] ) ? sanitize_key( (string) $group['key'] ) : '';
			$label = isset( $group['label'] ) && is_scalar( $group['label'] ) ? sanitize_text_field( (string) $group['label'] ) : '';

			if ( '' === $key || '' === $label || isset( $used_keys[ $key ] ) ) {
				continue; // No identity, nothing to show, or a repeat of an earlier group's key.
			}

			$choices = self::normalize_choices( $group['choices'] ?? array() );

			if ( empty( $choices ) ) {
				continue; // A group with nothing to pick from cannot be resolved.
			}

			$used_keys[ $key ] = true;

			$groups[] = array(
				'key'      => $key,
				'label'    => $label,
				'required' => ! empty( $group['required'] ),
				'choices'  => $choices,
			);
		}

		return $groups;
	}

	/**
	 * @return array<int,array{key:string,label:string,fee:float,extra_days:int}>
	 */
	private static function normalize_choices( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$choices   = array();
		$used_keys = array();

		foreach ( $raw as $choice ) {
			if ( ! is_array( $choice ) ) {
				continue;
			}

			$key   = isset( $choice['key'] ) && is_scalar( $choice['key'] ) ? sanitize_key( (string) $choice['key'] ) : '';
			$label = isset( $choice['label'] ) && is_scalar( $choice['label'] ) ? sanitize_text_field( (string) $choice['label'] ) : '';

			if ( '' === $key || '' === $label || isset( $used_keys[ $key ] ) ) {
				continue; // A duplicate or empty key would make a selection ambiguous.
			}

			$used_keys[ $key ] = true;

			$choices[] = array(
				'key'        => $key,
				'label'      => $label,
				'fee'        => max( 0.0, SLK_Money::rupees( self::numeric( $choice['fee'] ?? null ) ) ),
				'extra_days' => max( 0, (int) self::numeric( $choice['extra_days'] ?? null ) ),
			);
		}

		return $choices;
	}

	/**
	 * @return array{enabled:bool,fee:float,extra_days:int,min_cm:int,max_cm:int}
	 */
	private static function normalize_length( $raw ): array {
		$defaults = self::length_defaults();

		if ( ! is_array( $raw ) ) {
			return $defaults;
		}

		$min_cm = max( 0, (int) self::numeric( $raw['min_cm'] ?? null, (float) $defaults['min_cm'] ) );
		$max_cm = max( 0, (int) self::numeric( $raw['max_cm'] ?? null, (float) $defaults['max_cm'] ) );

		/*
		 * 0 is "no upper bound", so it must survive normalisation. Clamping with
		 * max( $min_cm, … ) — which is what this did — turned an unset maximum
		 * into a copy of the minimum, and the range check downstream then read
		 * "between 140 and 140 cm": one single length accepted, everything else
		 * refused, on a field the admin was never told was mandatory. Only clamp
		 * a maximum that was actually given and sits below the minimum.
		 */
		if ( $max_cm > 0 && $max_cm < $min_cm ) {
			$max_cm = $min_cm;
		}

		return array(
			'enabled'    => ! empty( $raw['enabled'] ),
			'fee'        => max( 0.0, SLK_Money::rupees( self::numeric( $raw['fee'] ?? null, $defaults['fee'] ) ) ),
			'extra_days' => max( 0, (int) self::numeric( $raw['extra_days'] ?? null, (float) $defaults['extra_days'] ) ),
			'min_cm'     => $min_cm,
			'max_cm'     => $max_cm,
		);
	}
}
