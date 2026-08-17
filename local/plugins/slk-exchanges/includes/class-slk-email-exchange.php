<?php
/**
 * One WooCommerce email per exchange state — the plain, on-brand note a
 * customer gets as their request moves along.
 *
 * A real WC_Email, registered through the `woocommerce_email_classes` filter
 * (see SLK_Exchange_Emails::register_emails()) once per notified state — as a
 * one-line subclass each, at the foot of this file, for the reason set out
 * there — so every one of these notes appears in WooCommerce → Settings →
 * Emails with its own enable switch, subject, heading and additional content.
 * Staff can reword the declined note, or stop the "collecting" note going out,
 * from the dashboard — the rule this whole build is held to: anything
 * operational is editable there, never only in code.
 *
 * COPY IS PLACEHOLDER-BASED — {piece}, {size}, {order_number}, {greeting} and
 * {send_fee_note} — precisely so an edited subject or heading keeps working.
 * The values are filled in at trigger() time from the order and the exchange
 * record through WC_Email's own format_string(), the same mechanism core's
 * {site_title} uses, so a staff edit can never desync from the request it
 * describes.
 *
 * The body is wrapped with WC()->mailer()->wrap_message() rather than a
 * template file of our own, so every note inherits the store's header, footer
 * and branding exactly as an order confirmation does (see wordmark.php's note
 * on WC_Email::get_from_name() and the {site_title} placeholder) with nothing
 * to keep in sync here.
 *
 * This file is loaded lazily, from inside the `woocommerce_email_classes`
 * callback, because WC_Email itself is only defined once WooCommerce boots its
 * mailer — requiring it at plugin-load time would fatal on a storefront
 * request that never sends an email.
 *
 * @package slk-exchanges
 */

defined( 'ABSPATH' ) || exit;

class SLK_Email_Exchange extends WC_Email {

	/** The exchange state this instance is the note for. */
	private string $state;

	/**
	 * @param string $state One of the SLK_Exchange::STATE_* constants.
	 */
	public function __construct( string $state = SLK_Exchange::STATE_REQUESTED ) {
		$this->state = $state;
		$label       = SLK_Exchange::states()[ $state ] ?? $state;

		$this->id             = 'slk_exchange_' . $state;
		$this->customer_email = true;
		// Files these under "Order changes" on the settings screen, beside
		// WooCommerce's own refund and cancellation notes, rather than in a
		// group of their own at the bottom.
		$this->email_group    = 'order-changes';
		$this->title          = sprintf(
			/* translators: %s: exchange state label, e.g. "Approved". */
			__( 'Exchange — %s', 'slk' ),
			$label
		);
		$this->description    = SLK_Exchange::STATE_REQUESTED === $state
			? __( 'Sent as soon as an exchange request is opened, whether the customer raised it on the site or staff logged it from the order screen.', 'slk' )
			: sprintf(
				/* translators: %s: exchange state label, e.g. "Dispatched". */
				__( 'Sent when an exchange request moves to "%s".', 'slk' ),
				$label
			);

		// Declared with readable stand-ins rather than empty strings so the
		// settings screen's own preview of a note reads as a sentence even
		// though no real request is attached to it there.
		$this->placeholders = array(
			'{piece}'         => __( 'your piece', 'slk' ),
			'{size}'          => __( 'the size you asked for', 'slk' ),
			'{order_number}'  => '',
			'{greeting}'      => __( 'Hi,', 'slk' ),
			'{send_fee_note}' => '',
		);

		parent::__construct();
	}

	/* ------------------------------------------------------------------ */
	/* Sending                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Send this state's note for one exchange request.
	 *
	 * @param array $exchange A record shaped like SLK_Exchange::get()'s return value.
	 */
	public function trigger( array $exchange ): bool {
		$copy  = self::copy( $this->state );
		$order = wc_get_order( (int) ( $exchange['order_id'] ?? 0 ) );

		if ( null === $copy || ! $order instanceof WC_Order ) {
			return false;
		}

		$this->setup_locale();

		$this->object    = $order;
		$this->recipient = $order->get_billing_email();

		$item = $order->get_item( (int) ( $exchange['item_id'] ?? 0 ) );
		$size = trim( (string) ( $exchange['wanted_size'] ?? '' ) );
		$name = trim( (string) $order->get_billing_first_name() );

		// Every placeholder is reassigned, never left as it was: one instance
		// serves every request in a bulk "Approve", so a value carried over
		// from the previous one would name the wrong garment in this note.
		$this->placeholders['{piece}']         = $item ? $item->get_name() : __( 'your piece', 'slk' );
		$this->placeholders['{size}']          = '' !== $size ? $size : __( 'the size you asked for', 'slk' );
		$this->placeholders['{greeting}']      = '' !== $name
			/* translators: %s: customer's first name. */
			? sprintf( __( 'Hi %s,', 'slk' ), $name )
			: __( 'Hi,', 'slk' );
		$this->placeholders['{order_number}']  = (string) $order->get_order_number();
		$this->placeholders['{send_fee_note}'] = self::send_fee_note( SLK_Exchange::STATE_DISPATCHED === $this->state );

		$sent = false;

		// A note switched off in the dashboard is not meant to go out at all,
		// and a placeholder-email order (see SLK_Email_Policy) has no inbox to
		// send it to.
		if ( $this->is_enabled() && '' !== (string) $this->get_recipient() ) {
			$sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();

		return (bool) $sent;
	}

	/* ------------------------------------------------------------------ */
	/* What WooCommerce asks this email for                                */
	/* ------------------------------------------------------------------ */

	public function get_default_subject(): string {
		$copy = self::copy( $this->state );

		return null !== $copy ? $copy['subject'] : '';
	}

	public function get_default_heading(): string {
		$copy = self::copy( $this->state );

		return null !== $copy ? $copy['heading'] : '';
	}

	public function get_content_html(): string {
		$body = $this->body_paragraphs_html();

		return '' !== $body ? WC()->mailer()->wrap_message( $this->get_heading(), $body ) : '';
	}

	public function get_content_plain(): string {
		$copy = self::copy( $this->state );

		if ( null === $copy ) {
			return '';
		}

		$lines = array();

		foreach ( $copy['paragraphs'] as $paragraph ) {
			$paragraph = trim( wp_strip_all_tags( $this->format_string( $paragraph ) ) );

			if ( '' !== $paragraph ) {
				$lines[] = $paragraph;
			}
		}

		$extra = trim( wp_strip_all_tags( (string) $this->get_additional_content() ) );
		if ( '' !== $extra ) {
			$lines[] = $extra;
		}

		return implode( "\n\n", $lines );
	}

	/**
	 * The paragraphs, placeholders resolved, followed by whatever staff typed
	 * into this note's "Additional content" box in the dashboard.
	 */
	private function body_paragraphs_html(): string {
		$copy = self::copy( $this->state );

		if ( null === $copy ) {
			return '';
		}

		$body = '';

		foreach ( $copy['paragraphs'] as $paragraph ) {
			$paragraph = trim( $this->format_string( $paragraph ) );

			if ( '' === $paragraph ) {
				continue;
			}

			// wp_kses_post(), not esc_html(): a paragraph can carry a
			// wc_price() amount already stripped to plain text with HTML
			// entities in it (see send_fee_note()) — esc_html() would
			// double-encode those entities into literal "&amp;nbsp;" text.
			// wp_kses_post() leaves entities alone, and every tag here is our
			// own copy or a dashboard edit by a manage_woocommerce user, so
			// nothing unsafe passes through either way.
			$body .= '<p>' . wp_kses_post( $paragraph ) . '</p>';
		}

		$extra = trim( (string) $this->get_additional_content() );
		if ( '' !== $extra ) {
			$body .= wpautop( wptexturize( $extra ) );
		}

		return $body;
	}

	/* ------------------------------------------------------------------ */
	/* The copy itself                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Default subject, heading and body paragraphs for one state. Every branch
	 * states the piece, the size going out, and what happens next, as the work
	 * package requires — and does it in placeholders, so a subject reworded in
	 * the dashboard still names the right garment.
	 *
	 * @return array{subject:string,heading:string,paragraphs:string[]}|null Null for a state this class has no note for.
	 */
	private static function copy( string $state ): ?array {
		switch ( $state ) {
			case SLK_Exchange::STATE_REQUESTED:
				return array(
					'subject'    => __( 'We have your exchange request for {piece}', 'slk' ),
					'heading'    => __( 'Your exchange request is with us', 'slk' ),
					'paragraphs' => array(
						'{greeting}',
						__( 'Thank you — we have your request to exchange {piece} from order #{order_number}. The size you asked for is {size}.', 'slk' ),
						__( 'Next: we look at every request by hand. We will email you as soon as it is approved and the courier is arranged.', 'slk' ),
						'{send_fee_note}',
					),
				);

			case SLK_Exchange::STATE_APPROVED:
				return array(
					'subject'    => __( 'Your exchange for {piece} is approved', 'slk' ),
					'heading'    => __( 'Your exchange is approved', 'slk' ),
					'paragraphs' => array(
						'{greeting}',
						__( 'We have approved your exchange request for {piece}. The size going out to you is {size}.', 'slk' ),
						__( 'Next: our courier collects the original piece from your address. Once it reaches us, we prepare the new size and send it straight out.', 'slk' ),
						'{send_fee_note}',
					),
				);

			case SLK_Exchange::STATE_COLLECTING:
				return array(
					'subject'    => __( "We're on our way to collect your exchange for {piece}", 'slk' ),
					'heading'    => __( 'Your exchange is being collected', 'slk' ),
					'paragraphs' => array(
						'{greeting}',
						__( 'Our courier is arranged to collect {piece} from your address. The size going out to you once we receive it is {size}.', 'slk' ),
						__( 'Next: hand the piece to the courier unworn, with the tag on. We will email you again the moment the new size is on its way.', 'slk' ),
					),
				);

			case SLK_Exchange::STATE_RECEIVED:
				return array(
					'subject'    => __( 'We have {piece} back with us', 'slk' ),
					'heading'    => __( 'Your piece is back with us', 'slk' ),
					'paragraphs' => array(
						'{greeting}',
						__( '{piece} from order #{order_number} has reached us and has been checked over.', 'slk' ),
						__( 'Next: we prepare {size} and send it out to you. We will email you the moment it is on its way.', 'slk' ),
					),
				);

			case SLK_Exchange::STATE_DISPATCHED:
				return array(
					'subject'    => __( 'Your {piece} is on its way', 'slk' ),
					'heading'    => __( 'Your exchange is on its way', 'slk' ),
					'paragraphs' => array(
						'{greeting}',
						__( 'The size going out to you, {size} of {piece}, has been dispatched.', 'slk' ),
						'{send_fee_note}',
						__( 'Next: our courier delivers it to your address. That completes the exchange.', 'slk' ),
					),
				);

			case SLK_Exchange::STATE_CLOSED:
				return array(
					'subject'    => __( 'Your exchange for {piece} is complete', 'slk' ),
					'heading'    => __( 'Your exchange is complete', 'slk' ),
					'paragraphs' => array(
						'{greeting}',
						__( 'The exchange of {piece} from order #{order_number} is complete — {size} is with you, and the original piece is back with us.', 'slk' ),
						__( 'Next: nothing to do. If the new size is not right either, reply to this email and we will sort it out.', 'slk' ),
					),
				);

			case SLK_Exchange::STATE_DECLINED:
				return array(
					'subject'    => __( 'Update on your exchange request for {piece}', 'slk' ),
					'heading'    => __( 'We could not proceed with this exchange', 'slk' ),
					'paragraphs' => array(
						'{greeting}',
						__( 'We are not able to proceed with the exchange request for {piece}.', 'slk' ),
						__( 'Next: if you have questions about this, reply to this email or message us and we will explain.', 'slk' ),
					),
				);

			default:
				return null;
		}
	}

	/**
	 * One line about the send fee, read live from SLK_Fulfilment so it can
	 * never drift from what checkout charges (the same rule Phase 1 applied
	 * to every other rupee amount the storefront prints) — never a hardcoded
	 * figure, and always through wc_price(), never a typed "Rs." symbol.
	 *
	 * @param bool $dispatched True once the new size has actually gone out (past tense); false while it is still upcoming.
	 */
	private static function send_fee_note( bool $dispatched ): string {
		$fee = SLK_Fulfilment::exchange_send_fee();

		if ( $fee <= 0 ) {
			return $dispatched
				? __( 'There is nothing to pay for this exchange.', 'slk' )
				: __( 'There is nothing to pay for this exchange. The new size is sent free.', 'slk' );
		}

		// wp_strip_all_tags() drops wc_price()'s markup but keeps entities
		// such as &nbsp; as literal text; see the wp_kses_post() note in
		// body_paragraphs_html(), the paragraph this string is embedded into.
		$amount = wp_strip_all_tags( wc_price( $fee ) );

		return $dispatched
			? sprintf( /* translators: %s: exchange send fee, e.g. "Rs. 350". */ __( 'Please have %s ready for the courier on delivery.', 'slk' ), $amount )
			: sprintf( /* translators: %s: exchange send fee, e.g. "Rs. 350". */ __( 'A courier fee of %s is payable on delivery of the new size.', 'slk' ), $amount );
	}
}

/* -------------------------------------------------------------------------- */
/* One class per state                                                         */
/* -------------------------------------------------------------------------- */

/*
 * Each notified state gets a subclass that carries nothing but the state it is
 * the note for. Seven instances of the one class above would send perfectly
 * well — SLK_Exchange_Emails::send() looks an instance up by its own array key
 * — but WooCommerce identifies an email by its CLASS, not by that key: the
 * Settings → Emails screen publishes `get_class( $email )` as the preview's
 * type, and EmailPreview::set_email_type() resolves it through
 * `array_combine( array_map( 'get_class', $wc_emails ), $wc_emails )`, where
 * seven identical class names collapse to one and the last registered instance
 * wins. Shared, every exchange note previewed as the declined one — staff
 * rewording the approval copy would read "We could not proceed with this
 * exchange" underneath it. Distinct classes are how core keys its own emails.
 * All the copy and behaviour stays in the parent; these add only the state.
 */

final class SLK_Email_Exchange_Requested extends SLK_Email_Exchange {
	public function __construct() {
		parent::__construct( SLK_Exchange::STATE_REQUESTED );
	}
}

final class SLK_Email_Exchange_Approved extends SLK_Email_Exchange {
	public function __construct() {
		parent::__construct( SLK_Exchange::STATE_APPROVED );
	}
}

final class SLK_Email_Exchange_Collecting extends SLK_Email_Exchange {
	public function __construct() {
		parent::__construct( SLK_Exchange::STATE_COLLECTING );
	}
}

final class SLK_Email_Exchange_Received extends SLK_Email_Exchange {
	public function __construct() {
		parent::__construct( SLK_Exchange::STATE_RECEIVED );
	}
}

final class SLK_Email_Exchange_Dispatched extends SLK_Email_Exchange {
	public function __construct() {
		parent::__construct( SLK_Exchange::STATE_DISPATCHED );
	}
}

final class SLK_Email_Exchange_Closed extends SLK_Email_Exchange {
	public function __construct() {
		parent::__construct( SLK_Exchange::STATE_CLOSED );
	}
}

final class SLK_Email_Exchange_Declined extends SLK_Email_Exchange {
	public function __construct() {
		parent::__construct( SLK_Exchange::STATE_DECLINED );
	}
}
