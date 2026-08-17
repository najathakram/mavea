<?php
/**
 * Registers the customer notes for exchanges, and is the one door the rest of
 * this plugin sends them through.
 *
 * The notes themselves are real WooCommerce emails — one SLK_Email_Exchange
 * subclass per state, registered below on `woocommerce_email_classes`, so each
 * lands in WooCommerce → Settings → Emails with its own enable switch, subject,
 * heading and additional content. This class holds no copy of its own; it
 * decides only WHEN a note goes out, and hands the work to the registered
 * email.
 *
 * A customer hears from us at every step, as Phase 3 of the plan requires:
 * requested (the acknowledgement, fired off the create path so the portal form
 * and the staff "log a WhatsApp request" form both acknowledge without either
 * remembering to), then approved, collecting, received, dispatched, closed —
 * and declined, the one off-ramp.
 *
 * Every state change in this plugin currently originates from
 * SLK_Exchange_Admin — a row action, the bulk "Approve" action, or a step taken
 * from the order screen's meta box — so that class calls maybe_send() itself,
 * right after a transition succeeds, rather than this class listening for an
 * action nobody fires. The one exception is creation, which has no transition
 * to hang off at all: SLK_Exchange::create() fires `slk_exchange_created`, and
 * on_created() below answers it.
 *
 * @package slk-exchanges
 */

defined( 'ABSPATH' ) || exit;

final class SLK_Exchange_Emails {

	/**
	 * States the customer is told about — one registered WC_Email each. Every
	 * state is on the list today; the constant stays because it is what drives
	 * registration, so a state added later mails only once it is named here.
	 */
	private const NOTIFIED_STATES = array(
		SLK_Exchange::STATE_REQUESTED,
		SLK_Exchange::STATE_APPROVED,
		SLK_Exchange::STATE_COLLECTING,
		SLK_Exchange::STATE_RECEIVED,
		SLK_Exchange::STATE_DISPATCHED,
		SLK_Exchange::STATE_CLOSED,
		SLK_Exchange::STATE_DECLINED,
	);

	public static function init(): void {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register_emails' ) );
		add_action( 'slk_exchange_created', array( __CLASS__, 'on_created' ) );
	}

	/**
	 * Add one email per notified state to WooCommerce's own mailer, which is
	 * what puts them on the Settings → Emails screen and gives each its own
	 * stored settings (option `woocommerce_slk_exchange_{state}_settings`).
	 *
	 * The class file is required here rather than at plugin load because
	 * WC_Email, the parent they extend, only exists once WooCommerce boots its
	 * mailer — which is exactly when this filter runs.
	 *
	 * @param array $emails Key => WC_Email instance.
	 * @return array
	 */
	public static function register_emails( $emails ) {
		if ( ! is_array( $emails ) ) {
			return $emails;
		}

		require_once SLK_EXCHANGES_PATH . 'includes/class-slk-email-exchange.php';

		foreach ( self::NOTIFIED_STATES as $state ) {
			$class = self::email_class( $state );

			$emails[ $class ] = new $class();
		}

		return $emails;
	}

	/**
	 * The class registered for one state — and, deliberately, the array key it
	 * is registered under too. Keying by class name is what core does, and what
	 * WooCommerce's Settings → Emails preview needs: it identifies an email by
	 * get_class(), so the classes must be distinct (see the note at the foot of
	 * class-slk-email-exchange.php). The settings screen lowercases the key for
	 * its section slug, so this reads as `slk_email_exchange_approved` there
	 * either way, and each note's stored settings are keyed by the email's own
	 * id — `woocommerce_slk_exchange_{state}_settings` — untouched by any of it.
	 */
	private static function email_class( string $state ): string {
		return 'SLK_Email_Exchange_' . ucfirst( $state );
	}

	/**
	 * The acknowledgement for a brand-new request, on `slk_exchange_created`.
	 * Creation is not a transition, so maybe_send() never sees it — without
	 * this the customer would hear nothing at all until staff acted.
	 */
	public static function on_created( $id ): void {
		$exchange = SLK_Exchange::get( (int) $id );

		if ( null !== $exchange ) {
			self::send( $exchange, SLK_Exchange::STATE_REQUESTED );
		}
	}

	/**
	 * Sends a note only when $new_state is one customers are told about, and
	 * only on an actual change — a no-op transition (SLK_Exchange::set_state()
	 * returning true for "already there") must never re-mail the same state.
	 *
	 * @param array $exchange A record shaped like SLK_Exchange::get()'s return value, fetched AFTER the transition.
	 */
	public static function maybe_send( array $exchange, string $new_state, string $old_state ): void {
		if ( $new_state === $old_state || ! in_array( $new_state, self::NOTIFIED_STATES, true ) ) {
			return;
		}

		self::send( $exchange, $new_state );
	}

	/**
	 * Hands one exchange to the WC_Email registered for one state. Public (not
	 * just called via maybe_send()) so a later package — a resend action, a
	 * queued retry — can call it directly without re-deriving the before/after
	 * states first.
	 *
	 * @param array $exchange A record shaped like SLK_Exchange::get()'s return value.
	 */
	public static function send( array $exchange, string $state ): bool {
		if ( ! in_array( $state, self::NOTIFIED_STATES, true ) || ! function_exists( 'WC' ) ) {
			return false;
		}

		$mailer = WC()->mailer();
		if ( ! $mailer instanceof WC_Emails ) {
			return false;
		}

		$email = $mailer->get_emails()[ self::email_class( $state ) ] ?? null;

		return $email instanceof SLK_Email_Exchange ? $email->trigger( $exchange ) : false;
	}
}
