<?php
/**
 * Sri Lankan mobile number parsing.
 *
 * The phone number is the primary identity on this store — it is what the
 * confirmation call goes to, what WhatsApp updates go to, and (when no email is
 * given) what the synthesised placeholder address is derived from. So it is
 * normalised to one canonical form, +94XXXXXXXXX, before the order is created.
 *
 * Accepted input, with any spaces, dashes, dots or brackets:
 *   0771234567     national trunk form
 *   771234567      bare national form
 *   +94771234567   E.164
 *   94771234567    E.164 without the plus
 *   0094771234567  international access prefix
 *
 * @package slk
 */

defined( 'ABSPATH' ) || exit;

final class SLK_Phone {

	public const CC              = '+94';
	public const NATIONAL_LENGTH = 9;

	/**
	 * Reduce any accepted form to the 9 national digits, or '' if it cannot be
	 * read as a Sri Lankan number at all.
	 */
	public static function national_digits( $raw ): string {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}

		// Keep digits only; a leading '+' carries no information once we have
		// stripped the country code below.
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( ! is_string( $digits ) || '' === $digits ) {
			return '';
		}

		// 0094… → 94…
		if ( str_starts_with( $digits, '0094' ) ) {
			$digits = substr( $digits, 2 );
		}

		// 94… (country code) — only strip it when what remains is plausibly a
		// national number, so 0941234567 (a Kurunegala landline) is not eaten.
		if ( str_starts_with( $digits, '94' ) && strlen( $digits ) === self::NATIONAL_LENGTH + 2 ) {
			$digits = substr( $digits, 2 );
		}

		// 0771234567 → 771234567
		if ( str_starts_with( $digits, '0' ) && strlen( $digits ) === self::NATIONAL_LENGTH + 1 ) {
			$digits = substr( $digits, 1 );
		}

		return $digits;
	}

	/**
	 * Canonical stored form: +94XXXXXXXXX. Returns '' when the input is not a
	 * valid Sri Lankan mobile number.
	 */
	public static function normalise( $raw ): string {
		$digits = self::national_digits( $raw );

		if ( self::NATIONAL_LENGTH !== strlen( $digits ) ) {
			return '';
		}

		return self::CC . $digits;
	}

	/**
	 * Validate, with the checkout's own wording.
	 *
	 * @return array{ok:bool,message:string,normalised:string}
	 */
	public static function check( $raw ): array {
		$digits = self::national_digits( $raw );
		$count  = strlen( $digits );

		if ( 0 === $count ) {
			return array(
				'ok'         => false,
				'message'    => __( 'We need a mobile number, because it is how we confirm your order.', 'slk' ),
				'normalised' => '',
			);
		}

		if ( self::NATIONAL_LENGTH !== $count ) {
			return array(
				'ok'      => false,
				/* translators: %d: number of digits the shopper actually typed. */
				'message' => sprintf(
					_n(
						'That is %d digit. Sri Lankan mobile numbers have 9 after +94.',
						'That is %d digits. Sri Lankan mobile numbers have 9 after +94.',
						$count,
						'slk'
					),
					$count
				),
				'normalised' => '',
			);
		}

		// 9 digits, but a mobile must start with 7 (070–078 ranges). Landlines
		// are 9 digits too, and a landline cannot be WhatsApped — the whole
		// post-order flow assumes a mobile, so this is a real rejection, not
		// pedantry.
		if ( ! str_starts_with( $digits, '7' ) ) {
			return array(
				'ok'         => false,
				'message'    => __( 'Sri Lankan mobile numbers start with 7 after +94. A landline will not reach you on WhatsApp.', 'slk' ),
				'normalised' => '',
			);
		}

		return array(
			'ok'         => true,
			'message'    => '',
			'normalised' => self::CC . $digits,
		);
	}

	public static function is_valid( $raw ): bool {
		$check = self::check( $raw );

		return (bool) $check['ok'];
	}
}
