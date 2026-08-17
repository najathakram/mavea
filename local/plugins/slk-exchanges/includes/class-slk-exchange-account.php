<?php
/**
 * My Account → "Exchanges": the customer-facing request form and status list.
 *
 * Registers a real WooCommerce My Account endpoint (not a parallel page) so a
 * signed-in customer can see which of their delivered lines can still be
 * exchanged, ask for one, and see plain-language progress on requests already
 * made — without ever touching wp-admin or WhatsApp.
 *
 * DEPENDENCY CONTRACT (this file calls it, it does not define it — SLK_Exchange
 * is built by a sibling work package against the data model in the
 * 2026-08-17-dashboard-operations.md plan, Phase 3):
 *
 *   SLK_Exchange::eligible_for( WC_Order $order, int $item_id ): array
 *       array{ok:bool,reason:string} — 'ok' is true when this exact order line
 *       may still be exchanged (completed order, inside the configured window,
 *       no open request on the line already), and 'reason' carries the
 *       shopper-facing explanation when it is not. Test ['ok'], never the array
 *       itself: a non-empty array is always truthy. This is the single source
 *       of truth for eligibility — this file never re-derives the window or
 *       "already has one open" itself, so it cannot drift from whatever
 *       SLK_Exchange actually enforces.
 *
 *   SLK_Exchange::create( array $args ): int|WP_Error
 *       Args: order_id, item_id, customer_id, reason, wanted_size, note.
 *       Returns the new request's ID, or a WP_Error on failure.
 *
 *   SLK_Exchange::for_customer( int $customer_id ): array
 *       Returns this customer's own requests as arrays shaped
 *       { id, order_id, order_number, item_name, reason, wanted_size, note,
 *       status, date }, status being one of the plan's post-status slugs
 *       (requested/approved/collecting/received/dispatched/closed/declined).
 *       Still guarded with is_callable() below, so an older copy of
 *       SLK_Exchange left behind by a half-finished deploy shows the request
 *       form with an empty history instead of fataling the account page.
 *
 * Reason keys and status slugs are the exact vocabulary the plan names for
 * Phase 3, so they are safe to hard-code here (shared contract, not a guess
 * about SLK_Exchange's internals) — exposed as public constants so an admin
 * screen built in a different package can print the same labels rather than
 * keeping a second copy that can drift.
 *
 * REWRITE FLUSH: this plugin has no bootstrap file in this package's scope to
 * hang a register_activation_hook() off, so the endpoint is registered and
 * the rewrite rules are flushed once via a one-shot option instead — the same
 * idiom the theme already uses for one-time setup (see
 * slk_checkout_account_options_bound_v2 in slk-child/inc/account.php). The
 * option guard means the (comparatively expensive) flush runs once, the
 * first time this code is live, not on every page load.
 *
 * NOTICES: success/error state travels back from the POST handler as query
 * args on the redirect target rather than through wc_add_notice(), matching
 * the pattern this codebase already uses for its own POST-redirect-GET forms
 * (slk-child/inc/pages-support.php's contact form) — self-contained, and not
 * dependent on whether this theme's My Account page prints the WooCommerce
 * session notice queue.
 *
 * @package slk-exchanges
 */

defined( 'ABSPATH' ) || exit;

class SLK_Exchange_Account {

	/** The endpoint slug: /my-account/exchanges/. */
	public const ENDPOINT = 'exchanges';

	/** One-shot guard so rewrite rules are flushed once, not on every load. */
	private const FLUSH_OPTION = 'slk_exchange_account_rewrite_flushed';

	private const NONCE_ACTION = 'slk_exchange_request';
	private const NONCE_FIELD  = 'slk_exchange_nonce';

	/** How many of the customer's most recent completed orders to scan for eligible lines. */
	private const ORDER_SCAN_LIMIT = 30;

	/**
	 * Reason enum for the request form — exactly the plan's Phase 3 wording.
	 *
	 * @var array<string,string>
	 */
	public const REASONS = array(
		'fits_small' => 'It fits small',
		'fits_large' => 'It fits large',
		'flaw'       => 'There is a flaw',
		'other'      => 'Something else',
	);

	/**
	 * Plain-language labels for the plan's post-status workflow.
	 *
	 * @var array<string,string>
	 */
	public const STATUS_LABELS = array(
		'requested'  => 'Received — we are reviewing it',
		'approved'   => 'Approved — the courier will collect soon',
		'collecting' => 'The courier is on the way to collect it',
		'received'   => 'We have the piece back with us',
		'dispatched' => 'On its way back to you',
		'closed'     => 'Complete',
		'declined'   => 'Declined',
	);

	/** Terminal statuses read as "resolved" rather than "in progress" for pill tone. */
	private const CLOSED_STATUSES = array( 'closed', 'declined' );

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'add_query_var' ) );
		// Priority 25: after slk-child/inc/account.php's own menu filter (20)
		// has already dropped dashboard/downloads/payment-methods and
		// relabelled the rest, so this inserts against the final shape of the
		// menu rather than a shape that filter is about to rewrite.
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_menu_item' ), 25 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render' ) );
		// template_redirect, not the endpoint render callback: a redirect has
		// to happen before any output starts, and running the handler here
		// means a straight GET of the endpoint (including the redirect this
		// handler itself sends back) never re-enters it — the whole point of
		// POST-redirect-GET is that refreshing the result page cannot re-fire
		// the form submit.
		add_action( 'template_redirect', array( __CLASS__, 'handle_submit' ) );
	}

	/**
	 * Register the endpoint with WordPress, and flush once.
	 *
	 * Flushing on every request would be needless work on every single page
	 * load forever; flushing only the first time this code runs is enough,
	 * because WordPress persists rewrite rules in an option until something
	 * changes them again.
	 *
	 * EP_PAGES only, never EP_ROOT: the endpoint lives under the My Account
	 * page, and EP_ROOT would additionally claim the top-level /exchanges/
	 * path — which the theme's own Exchanges page already owns, so that rule
	 * matched first and 404'd the page. WooCommerce masks its own account
	 * endpoints the same way (WC_Query::get_endpoints_mask() adds EP_ROOT
	 * only when My Account *is* the front page).
	 */
	public static function register_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_PAGES );

		if ( ! get_option( self::FLUSH_OPTION ) ) {
			flush_rewrite_rules();
			update_option( self::FLUSH_OPTION, 'yes', false );
		}
	}

	/**
	 * Tell WooCommerce's own query/endpoint machinery about the new
	 * endpoint (is_wc_endpoint_url(), wc_get_account_endpoint_url(), nav
	 * "current" state) — separate from WordPress's own rewrite system, which
	 * add_rewrite_endpoint() above already satisfies on its own.
	 *
	 * @param array $vars Endpoint slug => query var name.
	 * @return array
	 */
	public static function add_query_var( $vars ) {
		if ( ! is_array( $vars ) ) {
			return $vars;
		}

		$vars[ self::ENDPOINT ] = self::ENDPOINT;

		return $vars;
	}

	/**
	 * Insert "Exchanges" into the account nav, just above "Sign out" so it
	 * reads as part of the account's own business rather than trailing after
	 * it. Falls back to appending at the end if no logout row is present
	 * (a custom menu with that row removed, say) rather than silently
	 * dropping the item.
	 *
	 * @param array $items Endpoint => label.
	 * @return array
	 */
	public static function add_menu_item( $items ) {
		if ( ! is_array( $items ) || isset( $items[ self::ENDPOINT ] ) ) {
			return $items;
		}

		$label      = __( 'Exchanges', 'slk' );
		$positioned = array();
		$placed     = false;

		foreach ( $items as $key => $value ) {
			if ( 'customer-logout' === $key ) {
				$positioned[ self::ENDPOINT ] = $label;
				$placed                       = true;
			}
			$positioned[ $key ] = $value;
		}

		if ( ! $placed ) {
			$positioned[ self::ENDPOINT ] = $label;
		}

		return $positioned;
	}

	/**
	 * The endpoint's public URL, for other packages (the theme's Exchange
	 * policy page, an order-confirmation email, and so on) to link to
	 * without hard-coding the endpoint slug a second time.
	 */
	public static function url(): string {
		return function_exists( 'wc_get_account_endpoint_url' )
			? wc_get_account_endpoint_url( self::ENDPOINT )
			: wc_get_page_permalink( 'myaccount' );
	}

	/**
	 * Whether this customer has anything they could exchange right now.
	 *
	 * The question another package asks before offering a link here (the
	 * theme's Exchange policy page does), so a signed-in visitor who has
	 * never ordered — or whose orders have all aged out of the window — is
	 * never sent to the endpoint just to be told nothing qualifies. It runs
	 * the same scan the request form does, against the same authority
	 * (SLK_Exchange::eligible_for()), so the link and the form can never
	 * disagree; it stops at the first eligible line because the caller only
	 * needs the yes/no.
	 */
	public static function has_eligible_lines( int $customer_id ): bool {
		if ( $customer_id < 1 || ! class_exists( 'SLK_Exchange' ) ) {
			return false;
		}

		return array() !== self::eligible_lines( $customer_id, true );
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Endpoint content: notice, request form, request history.
	 */
	public static function render(): void {
		if ( ! is_user_logged_in() ) {
			return; // WooCommerce already gates the account area; belt and braces.
		}

		if ( ! class_exists( 'SLK_Exchange' ) ) {
			// The sibling package that owns the exchange lifecycle is not
			// active yet — degrade quietly rather than fatal, the same rule
			// the theme already follows when a plugin it extends is off.
			?>
			<div class="slk-panel">
				<p><?php esc_html_e( 'Exchanges are not available right now. Please message us on WhatsApp and we will sort it out by hand.', 'slk' ); ?></p>
			</div>
			<?php
			return;
		}

		$customer_id = get_current_user_id();

		// Read-only display flags carried on the redirect this page's own POST
		// handler sends back to itself — no state changes here, so no nonce.
		$sent = isset( $_GET['slk_exchange'] ) ? sanitize_key( wp_unslash( $_GET['slk_exchange'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$why  = isset( $_GET['slk_exchange_why'] ) ? sanitize_key( wp_unslash( $_GET['slk_exchange_why'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'sent' === $sent ) {
			?>
			<div class="slk-toast"><?php esc_html_e( 'Request received. We will email you once we have reviewed it.', 'slk' ); ?></div>
			<?php
		} elseif ( 'error' === $sent ) {
			?>
			<div class="slk-toast slk-toast--error"><?php echo esc_html( self::error_message( $why ) ); ?></div>
			<?php
		}

		self::render_form( $customer_id );
		self::render_history( $customer_id );
	}

	/**
	 * The "Request an exchange" form, or an explanation when nothing on the
	 * account qualifies right now.
	 *
	 * @param int $customer_id Current user id.
	 */
	private static function render_form( int $customer_id ): void {
		$lines = self::eligible_lines( $customer_id );
		?>
		<div class="slk-panel">
			<h2><?php esc_html_e( 'Request an exchange', 'slk' ); ?></h2>
			<?php if ( empty( $lines ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: %d: number of days an order stays eligible for exchange. */
						esc_html__( 'Nothing is eligible for an exchange right now. A piece stays eligible for %d days after it reaches you.', 'slk' ),
						class_exists( 'SLK_Fulfilment' ) ? (int) SLK_Fulfilment::exchange_window_days() : 7
					);
					?>
				</p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( self::url() ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>

					<div class="slk-field">
						<label for="slk-exchange-line"><?php esc_html_e( 'Which piece?', 'slk' ); ?></label>
						<select class="slk-select" id="slk-exchange-line" name="slk_exchange_line" required>
							<?php foreach ( $lines as $line ) : ?>
								<option value="<?php echo esc_attr( $line['value'] ); ?>"><?php echo esc_html( $line['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="slk-field">
						<label for="slk-exchange-reason"><?php esc_html_e( 'Why does it need to change?', 'slk' ); ?></label>
						<select class="slk-select" id="slk-exchange-reason" name="slk_exchange_reason" required>
							<?php foreach ( self::REASONS as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="slk-field">
						<label for="slk-exchange-size"><?php esc_html_e( 'Size wanted', 'slk' ); ?></label>
						<input class="slk-input" id="slk-exchange-size" type="text" name="slk_exchange_size" maxlength="60" placeholder="<?php echo esc_attr__( 'e.g. M, or a length in cm', 'slk' ); ?>" required>
					</div>

					<div class="slk-field">
						<label for="slk-exchange-note"><?php esc_html_e( 'Anything else we should know? (optional)', 'slk' ); ?></label>
						<textarea class="slk-textarea" id="slk-exchange-note" name="slk_exchange_note" rows="3"></textarea>
					</div>

					<button class="slk-btn slk-btn--primary" type="submit"><?php esc_html_e( 'Send request', 'slk' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The customer's own requests, oldest state → plain language, newest
	 * data SLK_Exchange has for them.
	 *
	 * @param int $customer_id Current user id.
	 */
	private static function render_history( int $customer_id ): void {
		$requests = self::existing_requests( $customer_id );
		?>
		<div class="slk-panel">
			<h2><?php esc_html_e( 'Your requests', 'slk' ); ?></h2>
			<?php if ( empty( $requests ) ) : ?>
				<p><?php esc_html_e( "You haven't requested an exchange yet.", 'slk' ); ?></p>
			<?php else : ?>
				<?php foreach ( $requests as $request ) : ?>
					<?php
					$status = (string) $request['status'];
					$tone   = in_array( $status, self::CLOSED_STATUSES, true ) ? 'quiet' : 'solid';
					?>
					<div class="slk-exchange-row">
						<div class="slk-exchange-row__text">
							<strong><?php echo esc_html( (string) $request['item_name'] ); ?></strong><br>
							<small>
								<?php
								printf(
									/* translators: 1: order number, 2: size the customer asked for. */
									esc_html__( 'Order #%1$s · wanted size %2$s', 'slk' ),
									esc_html( (string) $request['order_number'] ),
									esc_html( (string) $request['wanted_size'] )
								);
								?>
							</small>
						</div>
						<span class="slk-status-pill slk-status-pill--<?php echo esc_attr( $tone ); ?>">
							<?php echo esc_html( self::status_label( $status ) ); ?>
						</span>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Submission                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Validate and act on a posted request, then always redirect — the
	 * endpoint is only ever reached again by GET after this, so refreshing
	 * the result page cannot resubmit the form.
	 */
	public static function handle_submit(): void {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( self::ENDPOINT ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return; // A plain GET of the endpoint (including our own redirect back to it) — nothing to handle.
		}

		if ( ! is_user_logged_in() ) {
			return; // No account to own an exchange under; WooCommerce already gates this page anyway.
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			self::redirect( 'error', 'nonce' );
		}

		if ( ! class_exists( 'SLK_Exchange' ) ) {
			self::redirect( 'error', 'unavailable' );
		}

		$line  = isset( $_POST['slk_exchange_line'] ) ? sanitize_text_field( wp_unslash( $_POST['slk_exchange_line'] ) ) : '';
		$parts = explode( ':', $line, 2 );

		$order_id = isset( $parts[0] ) ? absint( $parts[0] ) : 0;
		$item_id  = isset( $parts[1] ) ? absint( $parts[1] ) : 0;

		$reason      = isset( $_POST['slk_exchange_reason'] ) ? sanitize_key( wp_unslash( $_POST['slk_exchange_reason'] ) ) : '';
		$wanted_size = isset( $_POST['slk_exchange_size'] ) ? sanitize_text_field( wp_unslash( $_POST['slk_exchange_size'] ) ) : '';
		$note        = isset( $_POST['slk_exchange_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['slk_exchange_note'] ) ) : '';

		if ( ! $order_id || ! $item_id || ! isset( self::REASONS[ $reason ] ) || '' === $wanted_size ) {
			self::redirect( 'error', 'invalid' );
		}

		// Never trust a posted order id: re-derive the order from it and
		// confirm it is actually this signed-in customer's order before
		// doing anything else with it. Ordinary customers carry no WordPress
		// capability for this beyond being logged in, so ownership of the
		// order IS the capability check here.
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || (int) $order->get_customer_id() !== get_current_user_id() ) {
			self::redirect( 'error', 'ownership' );
		}

		if ( ! $order->get_item( $item_id ) ) {
			self::redirect( 'error', 'ownership' );
		}

		$eligibility = SLK_Exchange::eligible_for( $order, $item_id );

		if ( empty( $eligibility['ok'] ) ) {
			self::redirect( 'error', 'ineligible' );
		}

		$result = SLK_Exchange::create(
			array(
				'order_id'    => $order_id,
				'item_id'     => $item_id,
				'customer_id' => get_current_user_id(),
				'reason'      => $reason,
				'wanted_size' => $wanted_size,
				'note'        => $note,
			)
		);

		if ( is_wp_error( $result ) ) {
			self::redirect( 'error', 'failed' );
		}

		self::redirect( 'sent' );
	}

	/**
	 * Redirect back to the endpoint carrying the outcome, then stop
	 * execution — every call site treats this as the end of the request,
	 * which the `never` return type makes a language-level guarantee rather
	 * than just a comment.
	 */
	private static function redirect( string $status, string $reason = '' ): never {
		$url = add_query_arg( 'slk_exchange', $status, self::url() );

		if ( '' !== $reason ) {
			$url = add_query_arg( 'slk_exchange_why', $reason, $url );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Data                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Every line, across the customer's most recent completed orders, that
	 * SLK_Exchange currently accepts a request for.
	 *
	 * SLK_Exchange::eligible_for() is the only authority on eligibility (the
	 * window, "already has one open") — this only supplies it candidates to
	 * check, so there is nothing here to keep in sync with that logic.
	 *
	 * @param int  $customer_id   Customer to scan.
	 * @param bool $stop_at_first Return as soon as one line qualifies — for
	 *                            has_eligible_lines(), which only needs to
	 *                            know whether the list would be empty and
	 *                            should not pay for the rest of the scan.
	 * @return array<int,array{value:string,label:string}>
	 */
	private static function eligible_lines( int $customer_id, bool $stop_at_first = false ): array {
		$lines = array();

		$orders = wc_get_orders(
			array(
				'customer_id' => $customer_id,
				'status'      => 'completed',
				'limit'       => self::ORDER_SCAN_LIMIT,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'return'      => 'objects',
			)
		);

		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			foreach ( $order->get_items() as $item_id => $item ) {
				$eligibility = SLK_Exchange::eligible_for( $order, $item_id );

				if ( empty( $eligibility['ok'] ) ) {
					continue;
				}

				$lines[] = array(
					'value' => $order->get_id() . ':' . $item_id,
					'label' => sprintf(
						/* translators: 1: item name, 2: order number. */
						__( '%1$s — order #%2$s', 'slk' ),
						$item->get_name(),
						$order->get_order_number()
					),
				);

				if ( $stop_at_first ) {
					return $lines;
				}
			}
		}

		return $lines;
	}

	/**
	 * This customer's own exchange requests, normalised to a display-ready
	 * shape so a missing field never trips a PHP notice.
	 *
	 * @return array<int,array{id:int,order_id:int,order_number:string,item_name:string,reason:string,wanted_size:string,note:string,status:string,date:string}>
	 */
	private static function existing_requests( int $customer_id ): array {
		if ( ! is_callable( array( 'SLK_Exchange', 'for_customer' ) ) ) {
			return array();
		}

		$raw = (array) SLK_Exchange::for_customer( $customer_id );
		$out = array();

		foreach ( $raw as $request ) {
			$out[] = wp_parse_args(
				(array) $request,
				array(
					'id'           => 0,
					'order_id'     => 0,
					'order_number' => '',
					'item_name'    => '',
					'reason'       => '',
					'wanted_size'  => '',
					'note'         => '',
					'status'       => '',
					'date'         => '',
				)
			);
		}

		return $out;
	}

	/**
	 * Plain-language label for a request status, falling back to the raw
	 * slug so an unrecognised status still prints something rather than
	 * nothing.
	 */
	private static function status_label( string $status ): string {
		return self::STATUS_LABELS[ $status ] ?? $status;
	}

	/**
	 * Copy for each way handle_submit() can redirect with an error.
	 */
	private static function error_message( string $reason ): string {
		$messages = array(
			'nonce'       => __( "That didn't go through. Please try again.", 'slk' ),
			'unavailable' => __( 'Exchanges are not available right now. Please message us on WhatsApp instead.', 'slk' ),
			'invalid'     => __( 'Please choose a piece, a reason and the size you want, then try again.', 'slk' ),
			'ownership'   => __( "We couldn't match that to one of your orders. Please try again.", 'slk' ),
			'ineligible'  => __( 'That piece is no longer eligible for an exchange.', 'slk' ),
			'failed'      => __( "That didn't go through. Please try again or message us on WhatsApp.", 'slk' ),
		);

		return $messages[ $reason ] ?? __( "That didn't go through. Please try again.", 'slk' );
	}
}
