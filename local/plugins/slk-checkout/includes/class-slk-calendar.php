<?php
/**
 * Working-day arithmetic for delivery promises.
 *
 * @package slk-checkout
 */

defined( 'ABSPATH' ) || exit;

class SLK_Calendar {

	/**
	 * The moment counting starts. Orders placed after the cut-off hour are
	 * counted from the following day, because nothing else leaves today.
	 */
	public static function start( array $settings ): DateTimeImmutable {
		$now = new DateTimeImmutable( 'now', wp_timezone() );

		$cutoff = (int) $settings['cutoff_hour'];
		if ( $cutoff > 0 && (int) $now->format( 'G' ) >= $cutoff ) {
			$now = $now->modify( '+1 day' );
		}

		return $now->setTime( 0, 0 );
	}

	public static function is_working_day( DateTimeImmutable $day, array $settings ): bool {
		if ( in_array( $day->format( 'Y-m-d' ), (array) $settings['holidays'], true ) ) {
			return false;
		}

		// 'w' is 0 for Sunday through 6 for Saturday.
		return in_array( (int) $day->format( 'w' ), array_map( 'intval', (array) $settings['working_days'] ), true );
	}

	/**
	 * $days working days after $from. Zero days still rolls forward to the next
	 * working day, because a piece ready on a holiday is not ready that day.
	 */
	public static function add_working_days( DateTimeImmutable $from, int $days, array $settings ): DateTimeImmutable {
		$day   = $from;
		$guard = 0;

		while ( $days > 0 && $guard < 400 ) {
			$day = $day->modify( '+1 day' );
			$guard++;
			if ( self::is_working_day( $day, $settings ) ) {
				$days--;
			}
		}

		while ( ! self::is_working_day( $day, $settings ) && $guard < 400 ) {
			$day = $day->modify( '+1 day' );
			$guard++;
		}

		return $day;
	}

	/**
	 * Whole days between the day the shopper is reading and $date.
	 *
	 * Measured from the real day, not from start(), which has already rolled
	 * past the cut-off hour. Measuring from start() would call the day after the
	 * cut-off "today" and promise every date one day earlier than the store can
	 * deliver.
	 */
	private static function days_away( DateTimeImmutable $date ): int {
		$today = ( new DateTimeImmutable( 'now', wp_timezone() ) )->setTime( 0, 0 );

		return (int) $today->diff( $date )->format( '%r%a' );
	}

	/**
	 * "Tomorrow" and "Today" read better than a date the shopper has to decode.
	 *
	 * @param array $settings Kept for a stable signature; the words need no settings.
	 */
	public static function label( DateTimeImmutable $date, array $settings ): string {
		$diff = self::days_away( $date );

		if ( 0 === $diff ) {
			return __( 'today', 'slk' );
		}
		if ( 1 === $diff ) {
			return __( 'tomorrow', 'slk' );
		}

		return wp_date( _x( 'j F', 'promise date format', 'slk' ), $date->getTimestamp(), wp_timezone() );
	}

	/**
	 * The same moment as label(), carrying its own preposition so it can follow
	 * a verb: "ships tomorrow", "ships on 5 August". A sentence that writes the
	 * "on" itself reads as "ships on tomorrow" whenever the date is a word,
	 * which is the everyday case for a piece on the shelf.
	 *
	 * @param array $settings Kept for a stable signature; the words need no settings.
	 */
	public static function when( DateTimeImmutable $date, array $settings ): string {
		$label = self::label( $date, $settings );
		$diff  = self::days_away( $date );

		if ( 0 === $diff || 1 === $diff ) {
			return $label;
		}

		/* translators: %s: a date such as 5 August. */
		return sprintf( __( 'on %s', 'slk' ), $label );
	}
}
