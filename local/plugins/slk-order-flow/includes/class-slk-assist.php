<?php
/**
 * Order-help assistant — a floating glass pill, bottom-right, on every
 * front-end page except checkout.
 *
 * RULES-BASED, NOT AI. No external service, no vendor call, no invented
 * answer. Every row either reads a live value already computed elsewhere in
 * this codebase, or is a plain link — nothing here composes a sentence that
 * was not verified against the file that publishes it:
 *
 *   - "Where is my order?" calls SLK_Track::resolve() (class-slk-track.php,
 *     this same plugin) behind a class_exists guard, exactly like
 *     page-templates/track-order.php does. The row itself is omitted
 *     entirely when that class is absent, not shown broken.
 *   - "Delivery costs" reads SLK_Shipping::free_over() and
 *     SLK_Payments::cod_fee() — both slk-checkout classes, both guarded —
 *     the same live settings slk-child/inc/chrome.php's announcement line
 *     and inc/pages-help.php's FAQ answers already read. No number here is
 *     typed twice; it is asked for.
 *   - "Exchanges" prints the exact hero sentence
 *     page-templates/exchange.php prints on /exchanges/ — the identical
 *     __() call, copied rather than paraphrased, so the two can never say
 *     different things — plus a link. It does not restate the day count or
 *     the send fee: those are read once, correctly, on the exchanges page
 *     itself, and duplicating them here would be a second place for them to
 *     go stale.
 *   - "Size guide" is a bare link. No claim is made about what it contains.
 *   - The WhatsApp row exists only when slk_whatsapp_number() (theme,
 *     inc/cart.php) returns something. It returns '' today, by design, so
 *     the row does not render — no placeholder, no "coming soon".
 *
 * ACCESSIBILITY / OPEN-CLOSE MECHANICS. This deliberately reuses the generic
 * `data-slk-toggle` / `data-slk-close` / `[hidden]` contract that
 * slk-child/inc/chrome.php's inline script already implements and already
 * loads on every front-end page (the header pill, the mobile drawer and the
 * header search panel all run on it today). That existing handler does
 * exactly what the DIALOG needs: opening moves focus to the first focusable
 * element inside the target panel, Escape closes it, and closing returns
 * focus to the control that opened it. If chrome.php's script is ever
 * removed, this panel would stop opening; nothing here duplicates that
 * logic, since duplicating it would be the same behaviour maintained twice.
 *
 * The one exception is the "Where is my order?" row, which is a disclosure
 * NESTED inside that dialog. It uses its own `data-slk-assist-row-toggle`
 * attribute and the small handler in enqueue()'s script, because
 * chrome.php's handler tracks a single open panel id: opening an inner
 * panel through it would overwrite the dialog's id, and Escape would then
 * collapse only the row, leaving the dialog open with no keyboard way out
 * and focus never returned to the pill.
 *
 * @package slk-order-flow
 */

defined( 'ABSPATH' ) || exit;

class SLK_Assist {

	/** admin-ajax action name and nonce action for the order lookup. */
	public const AJAX_ACTION  = 'slk_assist_track';
	public const NONCE_ACTION = 'slk-assist-track';

	public static function init(): void {
		add_action( 'wp_footer', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 31 );

		// priv + nopriv: a phone-only guest is exactly who this exists for.
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_track' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_track' ) );
	}

	/**
	 * Whether this request should carry the widget at all. Excludes
	 * checkout outright — nothing competes with payment — and degrades to
	 * nothing rather than fataling if WooCommerce's conditional tags are
	 * somehow unavailable.
	 */
	private static function should_render(): bool {
		return function_exists( 'is_checkout' ) && ! is_checkout();
	}

	/* ------------------------------------------------------------------ */
	/* Live facts — every one guarded, every one droppable                */
	/* ------------------------------------------------------------------ */

	/**
	 * Delivery-cost summary sentence(s), or '' when nothing is resolvable.
	 * Reads the same two slk-checkout settings inc/chrome.php's header
	 * announcement and inc/pages-help.php's FAQ already read; a clause is
	 * dropped, not zeroed, when the merchant has switched it off.
	 */
	private static function delivery_costs_text(): string {
		$free_over = null;
		$cod_fee   = null;

		if ( class_exists( 'SLK_Shipping' ) && method_exists( 'SLK_Shipping', 'free_over' ) ) {
			$free_over = (float) SLK_Shipping::free_over();
		}

		if ( class_exists( 'SLK_Payments' ) && method_exists( 'SLK_Payments', 'cod_fee' ) ) {
			$cod_fee = (float) SLK_Payments::cod_fee();
		}

		if ( null === $free_over && null === $cod_fee ) {
			return '';
		}

		$parts = array();

		if ( $free_over > 0 ) {
			$parts[] = sprintf(
				/* translators: %s: free-delivery threshold, e.g. "Rs. 15,000". */
				__( 'Free delivery over %s.', 'slk' ),
				wp_strip_all_tags( html_entity_decode( wc_price( $free_over ) ) )
			);
		}

		if ( $cod_fee > 0 ) {
			$parts[] = sprintf(
				/* translators: %s: cash-on-delivery handling fee, e.g. "Rs. 150". */
				__( 'Cash on delivery adds a %s handling fee.', 'slk' ),
				wp_strip_all_tags( html_entity_decode( wc_price( $cod_fee ) ) )
			);
		}

		// Both settings can exist and both read zero (threshold switched off,
		// no handling fee) — there is nothing true left to say, so the row
		// drops out the same as if the classes were missing entirely.
		return implode( ' ', $parts );
	}

	/**
	 * The exchanges summary — the exact hero sentence
	 * page-templates/exchange.php prints, not a paraphrase of it. Same
	 * __() call, same string, so a translation of one is a translation of
	 * both and the two can never drift apart.
	 */
	private static function exchange_summary(): string {
		return __( 'We exchange, we do not refund. Every piece is made to order.', 'slk' );
	}

	/**
	 * A provisioned page's URL, or '' when the helper that resolves it is
	 * unavailable — never a dead "#" link.
	 */
	private static function page_url( string $slug ): string {
		return function_exists( 'slk_page_url' ) ? (string) slk_page_url( $slug ) : '';
	}

	/**
	 * The WhatsApp handoff URL, or '' — checked through
	 * slk_whatsapp_number() exactly as the brief requires, then built with
	 * the theme's own slk_whatsapp_url() rather than re-assembling a wa.me
	 * link by hand.
	 */
	private static function whatsapp_url(): string {
		if ( ! function_exists( 'slk_whatsapp_number' ) || '' === slk_whatsapp_number() ) {
			return '';
		}

		return function_exists( 'slk_whatsapp_url' )
			? (string) slk_whatsapp_url( __( 'Hi, I have a question about my order.', 'slk' ) )
			: '';
	}

	/* ------------------------------------------------------------------ */
	/* Order lookup — ajax                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * The neutral failure copy — identical to page-templates/track-order.php
	 * so a shopper never learns, from a difference in wording, whether the
	 * order number or the contact was the half that did not match, or
	 * whether they simply hit the rate limit.
	 */
	private static function neutral_failure_message(): string {
		return __( 'We could not find that order. Check the number and the email or mobile you used.', 'slk' );
	}

	/**
	 * pending/on-hold/anything-not-yet-processing -> "Order received";
	 * processing -> "Being packed"; completed -> "At your door" — the same
	 * three labels woocommerce/order/tracking.php's timeline uses. Terminal
	 * statuses (cancelled/refunded/failed) get no step label at all, same
	 * as that template's own handling.
	 */
	private static function step_label( string $status ): string {
		if ( in_array( $status, array( 'cancelled', 'refunded', 'failed' ), true ) ) {
			return '';
		}

		if ( 'completed' === $status ) {
			return __( 'At your door', 'slk' );
		}

		if ( 'processing' === $status ) {
			return __( 'Being packed', 'slk' );
		}

		return __( 'Order received', 'slk' );
	}

	/**
	 * The order-lookup endpoint. priv + nopriv, nonce-gated, rate-limited,
	 * and — like SLK_Track::resolve() itself — never distinguishes "wrong
	 * order number", "wrong contact" and "too many attempts" in what it
	 * sends back.
	 *
	 * The budget lives on SLK_Track, not here, so this endpoint and the
	 * /track-order/ form POST spend from the same per-IP counter; a limit
	 * that only covered this path would be sidestepped by posting the page
	 * form instead.
	 */
	public static function ajax_track(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! class_exists( 'SLK_Track' ) || SLK_Track::rate_limited() ) {
			wp_send_json_error( array( 'message' => self::neutral_failure_message() ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : '';
		$contact  = isset( $_POST['contact'] ) ? sanitize_text_field( wp_unslash( $_POST['contact'] ) ) : '';

		$order = SLK_Track::resolve( $order_id, $contact );

		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => self::neutral_failure_message() ) );
		}

		$status = $order->get_status();

		wp_send_json_success(
			array(
				'order_number' => $order->get_order_number(),
				'status_label' => wc_get_order_status_name( $status ),
				'step_label'   => self::step_label( $status ),
				'date'         => wc_format_datetime( $order->get_date_created() ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Icons — drawn, matching inc/chrome.php's stroke language            */
	/* ------------------------------------------------------------------ */

	/**
	 * The pill's chat glyph. Not registered in slk_chrome_icon() (that
	 * function has no "chat" entry and adding one is outside this
	 * package's two files), so it is drawn here, at the same viewBox and
	 * stroke weight as every other header/PDP icon.
	 */
	private static function icon_chat( int $size = 18 ): string {
		return sprintf(
			'<svg class="slk-icon" viewBox="0 0 24 24" width="%1$d" height="%1$d" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 4.25c-4.56 0-8.25 3.13-8.25 7s3.69 7 8.25 7c.66 0 1.3-.07 1.92-.2L19.75 20l-1.1-3.6c1.03-1.05 1.6-2.36 1.6-3.75 0-3.87-3.69-7-8.25-7Z"/></svg>',
			$size
		);
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                           */
	/* ------------------------------------------------------------------ */

	public static function render(): void {
		static $done = false;

		if ( $done || ! self::should_render() ) {
			return;
		}

		$done = true;

		$has_track       = class_exists( 'SLK_Track' );
		$delivery_text   = self::delivery_costs_text();
		$exchange_url    = self::page_url( 'exchanges' );
		$size_guide_url  = self::page_url( 'size-guide' );
		$delivery_url    = self::page_url( 'delivery' );
		$wa_url          = self::whatsapp_url();
		?>
		<div class="slk-assist">
			<button type="button"
				class="slk-assist__pill"
				data-slk-toggle="slk-assist-panel"
				aria-controls="slk-assist-panel"
				aria-expanded="false"
				aria-haspopup="dialog">
				<?php echo self::icon_chat( 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup, see helper. ?>
				<span><?php esc_html_e( 'Help', 'slk' ); ?></span>
			</button>

			<div class="slk-assist__panel"
				id="slk-assist-panel"
				role="dialog"
				aria-label="<?php esc_attr_e( 'Help', 'slk' ); ?>"
				hidden>

				<div class="slk-assist__head">
					<span class="slk-assist__title"><?php esc_html_e( 'Help', 'slk' ); ?></span>
					<button type="button" class="slk-icon-btn" data-slk-close="slk-assist-panel" aria-label="<?php esc_attr_e( 'Close help', 'slk' ); ?>">
						<?php
						echo function_exists( 'slk_chrome_icon' )
							? slk_chrome_icon( 'close', 16 ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
							: '&times;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static entity.
						?>
					</button>
				</div>

				<ul class="slk-assist__list">

					<?php if ( $has_track ) : ?>
						<li class="slk-assist__row">
							<button type="button"
								class="slk-assist__row-trigger"
								data-slk-assist-row-toggle="slk-assist-track-panel"
								aria-controls="slk-assist-track-panel"
								aria-expanded="false">
								<span class="slk-assist__row-label"><?php esc_html_e( 'Where is my order?', 'slk' ); ?></span>
								<span class="slk-assist__row-icon" aria-hidden="true">+</span>
							</button>
							<div class="slk-assist__row-panel" id="slk-assist-track-panel" hidden>
								<form class="slk-assist__track-form" data-slk-assist-track-form novalidate>
									<div class="slk-field">
										<label for="slk-assist-order-id"><?php esc_html_e( 'Order number', 'slk' ); ?></label>
										<input class="slk-input" type="text" inputmode="numeric" autocomplete="off"
											id="slk-assist-order-id" name="order_id"
											placeholder="<?php esc_attr_e( 'The number we sent you', 'slk' ); ?>" required>
									</div>
									<div class="slk-field">
										<label for="slk-assist-contact"><?php esc_html_e( 'Email or mobile number', 'slk' ); ?></label>
										<input class="slk-input" type="text"
											id="slk-assist-contact" name="contact"
											placeholder="<?php esc_attr_e( 'The email or number you used', 'slk' ); ?>" required>
									</div>
									<button type="submit" class="slk-btn slk-btn--primary slk-assist__track-submit"><?php esc_html_e( 'Track order', 'slk' ); ?></button>
								</form>
								<div class="slk-assist__track-result" data-slk-assist-track-result aria-live="polite" hidden></div>
							</div>
						</li>
					<?php endif; ?>

					<?php if ( '' !== $delivery_text ) : ?>
						<li class="slk-assist__row">
							<p class="slk-assist__row-label"><?php esc_html_e( 'Delivery costs', 'slk' ); ?></p>
							<p class="slk-assist__row-note"><?php echo esc_html( $delivery_text ); ?></p>
							<a class="slk-assist__row-more" href="<?php echo esc_url( $delivery_url ); ?>"><?php esc_html_e( 'See delivery details', 'slk' ); ?></a>
						</li>
					<?php endif; ?>

					<li class="slk-assist__row">
						<p class="slk-assist__row-label"><?php esc_html_e( 'Exchanges', 'slk' ); ?></p>
						<p class="slk-assist__row-note"><?php echo esc_html( self::exchange_summary() ); ?></p>
						<a class="slk-assist__row-more" href="<?php echo esc_url( $exchange_url ); ?>"><?php esc_html_e( 'Read the exchange policy', 'slk' ); ?></a>
					</li>

					<li class="slk-assist__row">
						<a class="slk-assist__row-link" href="<?php echo esc_url( $size_guide_url ); ?>">
							<span class="slk-assist__row-label"><?php esc_html_e( 'Size guide', 'slk' ); ?></span>
							<span class="slk-assist__row-icon" aria-hidden="true">&rarr;</span>
						</a>
					</li>

					<?php if ( $wa_url ) : ?>
						<li class="slk-assist__row">
							<a class="slk-assist__row-link" href="<?php echo esc_url( $wa_url ); ?>">
								<span class="slk-assist__row-label"><?php esc_html_e( 'WhatsApp us', 'slk' ); ?></span>
								<span class="slk-assist__row-icon" aria-hidden="true">&rarr;</span>
							</a>
						</li>
					<?php endif; ?>

				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * CSS + JS. Same wp_add_inline_style( 'slk-child', … ) idiom the rest
	 * of this plugin already uses (SLK_Points::enqueue_account_styles(),
	 * SLK_Saved::enqueue_account_styles()) to style theme-hosted markup from
	 * plugin code, and the same wp_register_script( '', false, … ) +
	 * wp_add_inline_script() + wp_localize_script() idiom
	 * slk-child/inc/shop.php uses for the saved-pieces toggle. Skipped
	 * outright on checkout, matching render() above, so no unused CSS/JS
	 * ships on the one page this widget must never touch.
	 */
	public static function enqueue(): void {
		if ( ! self::should_render() ) {
			return;
		}

		$css = <<<'CSS'
/* -- floating pill --------------------------------------------------------- */
.slk-assist{position:fixed;right:var(--slk-space-4);bottom:var(--slk-space-4);z-index:65}
.slk-assist__pill{
	display:inline-flex;align-items:center;gap:var(--slk-space-2);
	min-height:var(--slk-touch);padding:0 var(--slk-space-4) 0 14px;
	background:var(--slk-glass-solid);
	backdrop-filter:blur(var(--slk-blur)) saturate(1.4);
	-webkit-backdrop-filter:blur(var(--slk-blur)) saturate(1.4);
	border:1px solid var(--slk-glass-edge);
	border-radius:var(--slk-radius-pill);
	box-shadow:var(--slk-shadow-float);
	color:var(--slk-color-ink);
	font:500 13px/1 var(--slk-font-ui);
	cursor:pointer;
	transition:transform var(--slk-motion-base) var(--slk-ease), box-shadow var(--slk-motion-base) var(--slk-ease);
}
.slk-assist__pill:hover{transform:translateY(-2px);box-shadow:var(--slk-shadow-press)}
.slk-assist__pill:active{transform:scale(.96)}

/* -- panel ------------------------------------------------------------------ */
.slk-assist__panel{
	position:fixed;right:var(--slk-space-4);
	bottom:calc(var(--slk-touch) + var(--slk-space-4) + var(--slk-space-3));
	width:min(360px, calc(100vw - 32px));
	max-height:70vh;overflow-y:auto;
	background:var(--slk-glass);
	backdrop-filter:blur(var(--slk-blur)) saturate(1.4);
	-webkit-backdrop-filter:blur(var(--slk-blur)) saturate(1.4);
	border:1px solid var(--slk-glass-edge);
	border-radius:var(--slk-radius-card);
	box-shadow:var(--slk-shadow-float);
	padding:var(--slk-space-4);
}
.slk-assist__panel[hidden]{display:none}

.slk-assist__head{display:flex;align-items:center;justify-content:space-between;gap:var(--slk-space-3);margin-bottom:var(--slk-space-2)}
.slk-assist__title{font:400 var(--slk-text-xl)/1.2 var(--slk-font-display);color:var(--slk-color-ink)}

/* -- rows -------------------------------------------------------------------- */
.slk-assist__list{list-style:none;margin:0;padding:0;display:grid}
.slk-assist__row{border-top:1px solid var(--slk-hairline);padding:var(--slk-space-3) 0}
.slk-assist__row:first-child{border-top:0;padding-top:0}

.slk-assist__row-trigger{
	width:100%;border:0;background:none;padding:0;margin:0;
	display:flex;align-items:center;justify-content:space-between;gap:var(--slk-space-3);
	min-height:var(--slk-touch);cursor:pointer;text-align:left;
	font:500 var(--slk-text-base)/1.3 var(--slk-font-ui);color:var(--slk-color-ink);
}
.slk-assist__row-link{
	display:flex;align-items:center;justify-content:space-between;gap:var(--slk-space-3);
	min-height:var(--slk-touch);text-decoration:none;color:var(--slk-color-ink);
	font:500 var(--slk-text-base)/1.3 var(--slk-font-ui);
}
.slk-assist__row-icon{flex:none;font:300 18px/1 var(--slk-font-ui);color:var(--slk-color-faint);transition:transform var(--slk-motion-base) var(--slk-ease)}
.slk-assist__row-trigger[aria-expanded="true"] .slk-assist__row-icon{transform:rotate(45deg)}
.slk-assist__row-label{display:block;font:500 var(--slk-text-base)/1.3 var(--slk-font-ui);color:var(--slk-color-ink)}
.slk-assist__row-note{margin:6px 0 0;font:400 var(--slk-text-sm)/1.55 var(--slk-font-ui);color:var(--slk-color-muted)}
.slk-assist__row-more{display:inline-block;margin-top:6px;font:500 var(--slk-text-sm)/1 var(--slk-font-ui);color:var(--slk-color-ink);text-decoration:underline;text-underline-offset:3px}

/* -- the order-lookup mini-form ---------------------------------------------- */
.slk-assist__row-panel{padding-top:var(--slk-space-3)}
.slk-assist__row-panel[hidden]{display:none}
.slk-assist__track-form .slk-field:last-of-type{margin-bottom:var(--slk-space-3)}
.slk-assist__track-submit{width:100%}
.slk-assist__track-result{margin-top:var(--slk-space-3);font:400 var(--slk-text-sm)/1.55 var(--slk-font-ui)}
.slk-assist__track-result[hidden]{display:none}
.slk-assist__track-headline{margin:0;font:500 var(--slk-text-base)/1.3 var(--slk-font-ui);color:var(--slk-color-ink)}
.slk-assist__track-meta{margin:4px 0 0;color:var(--slk-color-muted)}
.slk-assist__track-fail{margin:0;color:var(--slk-color-muted)}

/* -- clears the mobile PDP buy dock (style.css §3.9), which shares the
   bottom-right corner below 1000px; the dock is display:none at and above
   it, so nothing to clear there. */
@media (max-width:999px){
	body.single-product .slk-assist{bottom:calc(var(--slk-space-4) + 74px)}
}
CSS;

		wp_add_inline_style( 'slk-child', $css );

		wp_register_script( 'slk-assist', false, array(), null, true );
		wp_enqueue_script( 'slk-assist' );

		$js = <<<'JS'
(function () {
	"use strict";

	// %1$s / %2$s substitution, matching wp sprintf() placeholders, so the
	// two sentences below stay translatable strings (word order and all)
	// rather than a fixed JS-side concatenation.
	function format( template, args ) {
		return template.replace( /%(\d+)\$s/g, function ( match, index ) {
			var value = args[ parseInt( index, 10 ) - 1 ];
			return typeof value === 'undefined' ? match : value;
		} );
	}

	function renderResult( el, data, failMessage ) {
		el.textContent = '';
		el.hidden = false;

		if ( ! data ) {
			var fail = document.createElement( 'p' );
			fail.className = 'slk-assist__track-fail';
			fail.textContent = failMessage;
			el.appendChild( fail );
			return;
		}

		var head = document.createElement( 'p' );
		head.className = 'slk-assist__track-headline';
		head.textContent = format( window.slkAssist.headlineFormat, [ data.order_number, data.status_label ] );
		el.appendChild( head );

		var meta = document.createElement( 'p' );
		meta.className = 'slk-assist__track-meta';
		meta.textContent = format( window.slkAssist.placedFormat, [ data.date ] );
		el.appendChild( meta );

		if ( data.step_label ) {
			var step = document.createElement( 'p' );
			step.className = 'slk-assist__track-meta';
			step.textContent = data.step_label;
			el.appendChild( step );
		}
	}

	// The "Where is my order?" row is a disclosure NESTED inside the dialog,
	// so it deliberately does not use chrome.php's `data-slk-toggle`
	// contract: that handler tracks exactly one open panel id, and expanding
	// an inner panel would overwrite the dialog's id, leaving Escape unable
	// to close the dialog or return focus to the pill. This local handler
	// toggles the row alone; `slk-assist-panel` stays the panel chrome.php
	// knows about. Same [hidden] + aria-expanded + focus moves it makes, so
	// the row still behaves like every other slk disclosure.
	document.addEventListener( 'click', function ( e ) {
		var trigger = e.target.closest( '[data-slk-assist-row-toggle]' );
		if ( ! trigger ) {
			return;
		}

		e.preventDefault();

		var panel = document.getElementById( trigger.getAttribute( 'data-slk-assist-row-toggle' ) );
		if ( ! panel ) {
			return;
		}

		var isOpen = panel.hasAttribute( 'hidden' );

		if ( isOpen ) {
			panel.removeAttribute( 'hidden' );
		} else {
			panel.setAttribute( 'hidden', '' );
		}

		trigger.setAttribute( 'aria-expanded', isOpen ? 'true' : 'false' );

		if ( isOpen ) {
			var first = panel.querySelector( 'input, button, a[href]' );
			if ( first ) { first.focus(); }
		} else {
			trigger.focus();
		}
	} );

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target.closest( '[data-slk-assist-track-form]' );
		if ( ! form || typeof window.slkAssist === 'undefined' ) {
			return;
		}

		e.preventDefault();

		var panel  = form.closest( '.slk-assist__row-panel' );
		var result = panel ? panel.querySelector( '[data-slk-assist-track-result]' ) : null;
		var submit = form.querySelector( '.slk-assist__track-submit' );
		var orderId = form.querySelector( '[name="order_id"]' );
		var contact = form.querySelector( '[name="contact"]' );

		if ( ! result || ! submit || ! orderId || ! contact ) {
			return;
		}

		submit.disabled = true;

		var body = new URLSearchParams();
		body.set( 'action', window.slkAssist.action );
		body.set( 'nonce', window.slkAssist.nonce );
		body.set( 'order_id', orderId.value );
		body.set( 'contact', contact.value );

		window.fetch( window.slkAssist.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} )
			.then( function ( response ) { return response.json(); } )
			.then( function ( json ) {
				if ( json && json.success && json.data ) {
					renderResult( result, json.data, window.slkAssist.failMessage );
				} else {
					var message = ( json && json.data && json.data.message ) ? json.data.message : window.slkAssist.failMessage;
					renderResult( result, null, message );
				}
			} )
			.catch( function () {
				renderResult( result, null, window.slkAssist.failMessage );
			} )
			.finally( function () {
				submit.disabled = false;
			} );
	} );
})();
JS;

		wp_add_inline_script( 'slk-assist', $js );

		wp_localize_script(
			'slk-assist',
			'slkAssist',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'action'         => self::AJAX_ACTION,
				'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
				'failMessage'    => self::neutral_failure_message(),
				// Same msgid class-slk-studio-today.php's order rows already use —
				// one translation covers both.
				'headlineFormat' => __( 'Order #%1$s — %2$s', 'slk' ),
				/* translators: %s: the date the order was placed. */
				'placedFormat'   => __( 'Placed %s', 'slk' ),
			)
		);
	}
}
