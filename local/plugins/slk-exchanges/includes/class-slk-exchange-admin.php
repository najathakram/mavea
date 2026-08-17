<?php
/**
 * WooCommerce -> Exchanges: the staff-facing board, and the same controls
 * again as a meta box on the order screen.
 *
 * This file calls SLK_Exchange (class-slk-exchange.php) for every fact and
 * every write — the post type, its meta keys and its state machine belong to
 * that class alone. What lives here is entirely about the two screens: a
 * WP_List_Table of every request with a state filter and bulk approve, and
 * an order-scoped view of the same data plus a form for staff to log a
 * request a customer only ever raised on WhatsApp (the common path in Sri
 * Lanka, so it gets the same first-class controls as the board, not a
 * bare textarea).
 *
 * WRITE PATH — every mutation funnels through attempt_transition() or
 * SLK_Exchange::create(), and every entry point that can reach them (a row
 * action, the bulk "Approve" action, the manual-log form) checks
 * manage_woocommerce and a nonce first. Nothing here trusts a request id by
 * itself: SLK_Exchange::get()/set_state() re-derive the request's real
 * current state from the database before a transition is allowed to happen
 * (see attempt_transition()), and the meta box's own row actions carry a
 * "return" url validated with wp_validate_redirect() rather than trusting a
 * bare redirect target from the query string.
 *
 * MAIL — every successful transition this class makes goes through
 * SLK_Exchange_Emails::maybe_send(), and a request logged manually is
 * acknowledged off SLK_Exchange::create()'s own slk_exchange_created action.
 * There is no separate "send the email" step to forget. Which of those notes
 * actually go out is a dashboard setting (WooCommerce → Settings → Emails),
 * not a decision taken here.
 *
 * @package slk-exchanges
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class SLK_Exchange_Admin {

	public const PAGE_SLUG = 'slk-exchanges';

	/** Hook suffix add_submenu_page() returns for the board, set in register_menu(). */
	private static string $board_hook = '';

	/** Per-request memo of wc_get_order() lookups — several columns on one row want the same order. */
	private static array $order_cache = array();

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_box' ) );
		add_action( 'admin_footer', array( __CLASS__, 'render_log_request_form_element' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_notice' ) );
		add_action( 'admin_head', array( __CLASS__, 'print_admin_styles' ) );
		add_action( 'admin_post_slk_exchange_log_request', array( __CLASS__, 'handle_log_request' ) );
	}

	/* ------------------------------------------------------------------ */
	/* The board: menu, page, and the row/bulk actions it processes       */
	/* ------------------------------------------------------------------ */

	/**
	 * Registers the submenu page. The title carries a bell-style open-request
	 * count in the same markup core uses for the Comments menu's pending
	 * count ("awaiting-mod" / "pending-count"), so it reads as a native part
	 * of wp-admin rather than a plugin bolting a badge onto itself.
	 */
	public static function register_menu(): void {
		$count = ( class_exists( 'SLK_Exchange' ) && method_exists( 'SLK_Exchange', 'open_count' ) )
			? (int) SLK_Exchange::open_count()
			: 0;

		$menu_title = __( 'Exchanges', 'slk' );

		if ( $count > 0 ) {
			$menu_title .= sprintf(
				' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$s</span></span>',
				$count
			);
		}

		$hook = add_submenu_page(
			'woocommerce',
			__( 'Exchanges', 'slk' ),
			$menu_title,
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);

		if ( $hook ) {
			self::$board_hook = $hook;
			// load-{hook} runs before any output, so a row action or bulk
			// action can redirect afterwards without a "headers already
			// sent" error.
			add_action( "load-{$hook}", array( __CLASS__, 'process_actions' ) );
		}
	}

	/**
	 * Entry point for everything the board (and, via a "return" link, the
	 * order screen's meta box) can do: a single row's state transition, or
	 * the bulk "Approve" action. Both always end in a redirect.
	 */
	public static function process_actions(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_GET['action'], $_GET['id'] ) && 'transition' === $_GET['action'] ) {
			self::process_transition_request();
			return;
		}

		self::process_bulk_request();
	}

	/**
	 * A single row action, e.g. "Approve" on one request. The nonce action
	 * string is scoped to this exchange's id (see transition_nonce_action()),
	 * so a nonce minted for one row's link cannot be replayed against another
	 * by editing the id in the URL.
	 */
	private static function process_transition_request(): void {
		$id = absint( $_GET['id'] );
		check_admin_referer( self::transition_nonce_action( $id ) );

		$target      = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : '';
		$destination = self::resolve_return_url();

		$result = self::attempt_transition( $id, $target );

		self::redirect(
			$destination,
			is_wp_error( $result ) ? 'error' : 'transitioned',
			is_wp_error( $result ) ? $result->get_error_message() : ''
		);
	}

	/**
	 * The list table's own bulk-action dropdown ("Approve"). Requests that
	 * are not currently in a state approve can legally follow are silently
	 * skipped — attempt_transition() -> SLK_Exchange::set_state() is what
	 * decides that, not this method.
	 */
	private static function process_bulk_request(): void {
		$action = ( new SLK_Exchanges_List_Table() )->current_action();

		if ( 'approve' !== $action || empty( $_POST['exchange'] ) ) {
			return;
		}

		check_admin_referer( 'bulk-exchanges' );

		$ids  = array_map( 'absint', (array) wp_unslash( $_POST['exchange'] ) );
		$done = 0;

		foreach ( $ids as $id ) {
			if ( $id <= 0 ) {
				continue;
			}

			if ( ! is_wp_error( self::attempt_transition( $id, SLK_Exchange::STATE_APPROVED ) ) ) {
				++$done;
			}
		}

		self::redirect(
			add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ),
			$done > 0 ? 'bulk-approved' : 'error'
		);
	}

	/**
	 * The one place a state actually changes. Re-reads the exchange before
	 * and after so it can hand SLK_Exchange_Emails the real before/after
	 * states — never the state a link merely asked for, since
	 * SLK_Exchange::set_state() is the authority on whether that move was
	 * legal at all.
	 *
	 * @return true|WP_Error
	 */
	private static function attempt_transition( int $id, string $target_state ) {
		if ( $id <= 0 || '' === $target_state ) {
			return new WP_Error( 'slk_exchange_bad_request', __( 'Nothing to do.', 'slk' ) );
		}

		$before = SLK_Exchange::get( $id );
		$result = SLK_Exchange::set_state( $id, $target_state );

		if ( ! is_wp_error( $result ) && null !== $before ) {
			$after = SLK_Exchange::get( $id );
			SLK_Exchange_Emails::maybe_send( $after ?? $before, $target_state, (string) $before['state'] );
		}

		return $result;
	}

	/**
	 * Where a row-action link sends the browser back to once processed: the
	 * board itself by default, or the order screen when the link carried a
	 * "return" url (the meta box's own row actions set one). Validated with
	 * wp_validate_redirect() so a tampered "return" value can only ever land
	 * back on this site, never off it.
	 */
	private static function resolve_return_url(): string {
		$board = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );

		if ( empty( $_GET['return'] ) ) {
			return $board;
		}

		$candidate = esc_url_raw( wp_unslash( $_GET['return'] ) );

		return wp_validate_redirect( $candidate, $board );
	}

	/**
	 * The board. The notice left by the redirect that follows every action is
	 * deliberately NOT printed here: maybe_render_notice() is hooked to
	 * admin_notices, which wp-admin fires — above this .wrap — before it calls
	 * this page callback at all. Calling it again from here would print the
	 * same notice twice, the second copy generic, because the first pass has
	 * already consumed the transient carrying the specific reason.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'slk' ), '', array( 'response' => 403 ) );
		}

		$table = new SLK_Exchanges_List_Table();
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Exchanges', 'slk' ) . '</h1>';
		echo '<hr class="wp-header-end" />';

		echo '<form method="post">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::PAGE_SLUG ) );
		$table->views();
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Notices carried across the redirect that follows every action      */
	/* ------------------------------------------------------------------ */

	/**
	 * Redirects to $url with a whitelisted ?slk_notice= code. A WP_Error's
	 * specific message (e.g. why a transition or a manual create() was
	 * refused) rides along in a short-lived per-user transient instead of the
	 * query string, so the URL stays a small fixed set of known values.
	 */
	private static function redirect( string $url, string $notice, string $detail = '' ): void {
		if ( '' !== $detail ) {
			set_transient( self::notice_transient_key(), $detail, MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( add_query_arg( 'slk_notice', $notice, $url ) );
		exit;
	}

	private static function notice_transient_key(): string {
		return 'slk_exchange_notice_' . get_current_user_id();
	}

	/**
	 * Renders the notice left by redirect(), on the board page and the order
	 * screen only — the query arg it reads is only ever set by this class's
	 * own redirects to those two places.
	 */
	public static function maybe_render_notice(): void {
		if ( empty( $_GET['slk_notice'] ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen instanceof WP_Screen || ( self::$board_hook !== $screen->id && self::order_screen_id() !== $screen->id ) ) {
			return;
		}

		$notice = sanitize_key( wp_unslash( $_GET['slk_notice'] ) );

		$copy = array(
			'transitioned'  => array( 'success', __( 'The request was updated.', 'slk' ) ),
			'bulk-approved' => array( 'success', __( 'The selected requests were approved.', 'slk' ) ),
			'logged'        => array( 'success', __( 'The exchange request was logged.', 'slk' ) ),
			'error'         => array( 'error', __( 'That could not be done.', 'slk' ) ),
		);

		if ( ! isset( $copy[ $notice ] ) ) {
			return;
		}

		[$type, $message] = $copy[ $notice ];

		// A specific reason (an illegal transition, or why create() refused
		// the request) beats the generic copy above when one was left for us.
		$detail = get_transient( self::notice_transient_key() );
		if ( is_string( $detail ) && '' !== $detail ) {
			delete_transient( self::notice_transient_key() );
			$message = $detail;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Row actions — shared by the board's list table and the meta box    */
	/* ------------------------------------------------------------------ */

	/**
	 * Which action links to show for one exchange, from its current state.
	 * This mirrors SLK_Exchange's own (private) allowed_transitions() map —
	 * see the state-machine diagram in that file's header — purely to decide
	 * what to render; SLK_Exchange::set_state() remains the sole authority
	 * that enforces it, so a stale link here can never do more than fail.
	 *
	 * @param array  $exchange   A record shaped like SLK_Exchange::get()'s return value.
	 * @param string $return_url Where to send the browser back to once the action runs. Empty means "the board".
	 * @return array<string,string> Action key => ready-to-echo `<a>` HTML.
	 */
	public static function row_actions_for( array $exchange, string $return_url = '' ): array {
		$id    = (int) ( $exchange['id'] ?? 0 );
		$state = (string) ( $exchange['state'] ?? '' );

		switch ( $state ) {
			case SLK_Exchange::STATE_REQUESTED:
				return array(
					'approve' => self::action_link( $id, SLK_Exchange::STATE_APPROVED, __( 'Approve', 'slk' ), false, $return_url ),
					'decline' => self::action_link( $id, SLK_Exchange::STATE_DECLINED, __( 'Decline', 'slk' ), true, $return_url ),
				);

			case SLK_Exchange::STATE_APPROVED:
				return array(
					'collect' => self::action_link( $id, SLK_Exchange::STATE_COLLECTING, __( 'Mark collecting', 'slk' ), false, $return_url ),
					'decline' => self::action_link( $id, SLK_Exchange::STATE_DECLINED, __( 'Decline', 'slk' ), true, $return_url ),
					'back'    => self::action_link( $id, SLK_Exchange::STATE_REQUESTED, __( 'Move back to requested', 'slk' ), false, $return_url ),
				);

			case SLK_Exchange::STATE_COLLECTING:
				return array(
					'receive' => self::action_link( $id, SLK_Exchange::STATE_RECEIVED, __( 'Mark received', 'slk' ), false, $return_url ),
					'back'    => self::action_link( $id, SLK_Exchange::STATE_APPROVED, __( 'Move back to approved', 'slk' ), false, $return_url ),
				);

			case SLK_Exchange::STATE_RECEIVED:
				return array(
					'dispatch' => self::action_link( $id, SLK_Exchange::STATE_DISPATCHED, __( 'Mark dispatched', 'slk' ), false, $return_url ),
					'back'     => self::action_link( $id, SLK_Exchange::STATE_COLLECTING, __( 'Move back to collecting', 'slk' ), false, $return_url ),
				);

			case SLK_Exchange::STATE_DISPATCHED:
				return array(
					'close' => self::action_link( $id, SLK_Exchange::STATE_CLOSED, __( 'Close', 'slk' ), false, $return_url ),
					'back'  => self::action_link( $id, SLK_Exchange::STATE_RECEIVED, __( 'Move back to received', 'slk' ), false, $return_url ),
				);

			default:
				// closed, declined: terminal states — SLK_Exchange itself
				// allows no further transition out of either.
				return array();
		}
	}

	/**
	 * One nonce-protected `<a>` that asks process_actions() to move $id to
	 * $target_state. $confirm adds a plain JS confirmation for the one action
	 * ("Decline") that is not easily undone.
	 */
	private static function action_link( int $id, string $target_state, string $label, bool $confirm, string $return_url ): string {
		$args = array(
			'page'   => self::PAGE_SLUG,
			'action' => 'transition',
			'id'     => $id,
			'state'  => $target_state,
		);

		if ( '' !== $return_url ) {
			$args['return'] = rawurlencode( $return_url );
		}

		$url = wp_nonce_url( add_query_arg( $args, admin_url( 'admin.php' ) ), self::transition_nonce_action( $id ) );

		$attrs = $confirm
			? ' onclick="return confirm(\'' . esc_js( __( 'Decline this exchange request?', 'slk' ) ) . '\');"'
			: '';

		return sprintf( '<a href="%1$s"%2$s>%3$s</a>', esc_url( $url ), $attrs, esc_html( $label ) );
	}

	private static function transition_nonce_action( int $id ): string {
		return 'slk_exchange_transition_' . $id;
	}

	/* ------------------------------------------------------------------ */
	/* The order screen's meta box                                        */
	/* ------------------------------------------------------------------ */

	public static function register_meta_box(): void {
		add_meta_box(
			'slk-exchanges',
			__( 'Exchanges', 'slk' ),
			array( __CLASS__, 'render_meta_box' ),
			self::order_screen_id(),
			'normal',
			'default'
		);
	}

	/**
	 * The screen id for the order edit screen, HPOS-aware. WooCommerce's own
	 * helper resolves this whichever storage is active; the literal
	 * 'shop_order' fallback only matters on a WooCommerce old enough not to
	 * carry the helper at all.
	 */
	private static function order_screen_id(): string {
		return function_exists( 'wc_get_page_screen_id' ) ? (string) wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
	}

	/**
	 * @param WP_Post|WC_Order $post_or_order_object Whichever the order screen hands the callback — see the WooCommerce HPOS meta box guidance this follows.
	 */
	public static function render_meta_box( $post_or_order_object ): void {
		$order = $post_or_order_object instanceof WC_Order ? $post_or_order_object : wc_get_order( $post_or_order_object );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$exchanges = SLK_Exchange::for_order( $order->get_id() );

		echo '<div class="slk-exchange-meta-box">';

		if ( empty( $exchanges ) ) {
			echo '<p>' . esc_html__( 'No exchanges have been logged for this order yet.', 'slk' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="margin-bottom:1em;">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Piece', 'slk' ) . '</th>';
			echo '<th>' . esc_html__( 'Size wanted', 'slk' ) . '</th>';
			echo '<th>' . esc_html__( 'Reason', 'slk' ) . '</th>';
			echo '<th>' . esc_html__( 'State', 'slk' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $exchanges as $exchange ) {
				self::render_meta_box_row( $order, $exchange );
			}

			echo '</tbody></table>';
		}

		self::render_log_request_form( $order );

		echo '</div>';
	}

	/**
	 * @param array $exchange A record shaped like SLK_Exchange::get()'s return value.
	 */
	private static function render_meta_box_row( WC_Order $order, array $exchange ): void {
		$line  = $order->get_item( (int) ( $exchange['item_id'] ?? 0 ) );
		$piece = $line ? $line->get_name() : __( 'Item no longer on this order', 'slk' );

		$reason = SLK_Exchange::reasons()[ $exchange['reason'] ?? '' ] ?? (string) ( $exchange['reason'] ?? '' );
		$state  = SLK_Exchange::states()[ $exchange['state'] ?? '' ] ?? (string) ( $exchange['state'] ?? '' );

		$actions = self::row_actions_for( $exchange, $order->get_edit_order_url() );

		echo '<tr>';
		echo '<td>' . esc_html( $piece ) . '</td>';
		echo '<td>' . esc_html( (string) ( $exchange['wanted_size'] ?? '' ) ) . '</td>';
		echo '<td>' . esc_html( $reason ) . '</td>';
		echo '<td>' . esc_html( $state );

		$fee_note = self::send_fee_note( (string) ( $exchange['state'] ?? '' ) );
		if ( '' !== $fee_note ) {
			// send_fee_note() escapes its own copy and hands back wc_price()'s
			// own markup for the amount.
			echo '<br />' . $fee_note; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( ! empty( $actions ) ) {
			// Every link came from action_link(), which already esc_url()s
			// the href and esc_html()s the label.
			echo '<br /><span class="row-actions" style="visibility:visible;">' . implode( ' | ', $actions ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</td>';
		echo '</tr>';
	}

	/**
	 * The visible fields for "log a request manually". They cannot sit inside
	 * a <form> here — this markup renders inside the order screen's own
	 * #post form, and a <form> nested inside a <form> is invalid HTML that
	 * browsers silently drop along with everything it would have contained.
	 * Instead every field carries the HTML "form" attribute, pointing at the
	 * empty <form> render_log_request_form_element() prints later, in
	 * admin_footer, once the outer form has already closed. See that method.
	 */
	private static function render_log_request_form( WC_Order $order ): void {
		$items   = $order->get_items();
		$form_id = 'slk-exchange-log-request';

		echo '<h4>' . esc_html__( 'Log a request manually', 'slk' ) . '</h4>';
		echo '<p class="description">' . esc_html__( 'For a customer who asked on WhatsApp or by phone rather than through the exchanges page on the site. Only an order that is completed, and still inside the exchange window, can take a new request.', 'slk' ) . '</p>';

		if ( empty( $items ) ) {
			echo '<p>' . esc_html__( 'This order has no line items to exchange.', 'slk' ) . '</p>';
			return;
		}

		printf( '<input type="hidden" name="action" value="slk_exchange_log_request" form="%s" />', esc_attr( $form_id ) );
		printf( '<input type="hidden" name="order_id" value="%1$d" form="%2$s" />', (int) $order->get_id(), esc_attr( $form_id ) );
		printf(
			'<input type="hidden" name="_wpnonce" value="%1$s" form="%2$s" />',
			esc_attr( wp_create_nonce( 'slk_exchange_log_request_' . $order->get_id() ) ),
			esc_attr( $form_id )
		);

		echo '<p><label for="slk-exchange-item-id">' . esc_html__( 'Piece', 'slk' ) . '</label><br />';
		echo '<select id="slk-exchange-item-id" name="item_id" form="' . esc_attr( $form_id ) . '" class="widefat" required>';
		foreach ( $items as $item_id => $item ) {
			printf( '<option value="%1$d">%2$s</option>', (int) $item_id, esc_html( $item->get_name() ) );
		}
		echo '</select></p>';

		echo '<p><label for="slk-exchange-wanted-size">' . esc_html__( 'Size wanted', 'slk' ) . '</label><br />';
		echo '<input type="text" id="slk-exchange-wanted-size" name="wanted_size" form="' . esc_attr( $form_id ) . '" class="widefat" required /></p>';

		echo '<p><label for="slk-exchange-reason">' . esc_html__( 'Reason', 'slk' ) . '</label><br />';
		echo '<select id="slk-exchange-reason" name="reason" form="' . esc_attr( $form_id ) . '" class="widefat" required>';
		foreach ( SLK_Exchange::reasons() as $key => $label ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $key ), esc_html( $label ) );
		}
		echo '</select></p>';

		echo '<p><label for="slk-exchange-note">' . esc_html__( 'Note', 'slk' ) . '</label><br />';
		echo '<textarea id="slk-exchange-note" name="note" form="' . esc_attr( $form_id ) . '" class="widefat" rows="2"></textarea></p>';

		echo '<p><button type="submit" class="button button-primary" form="' . esc_attr( $form_id ) . '">' . esc_html__( 'Log request', 'slk' ) . '</button></p>';
	}

	/**
	 * The actual (empty) <form> the manual-log fields above point at by id —
	 * see the docblock on render_log_request_form() for why it cannot simply
	 * wrap them. Printed only on the order screen, safely after that
	 * screen's own #post form has closed.
	 */
	public static function render_log_request_form_element(): void {
		$screen = get_current_screen();

		if ( ! $screen instanceof WP_Screen || self::order_screen_id() !== $screen->id ) {
			return;
		}

		printf( '<form id="slk-exchange-log-request" method="post" action="%s"></form>', esc_url( admin_url( 'admin-post.php' ) ) );
	}

	/**
	 * Handles the manual-log form's submission. order_id and item_id both
	 * ride in on the request, but SLK_Exchange::create() re-derives the
	 * product from the order's own line rather than trusting anything else
	 * posted, and its eligibility check re-verifies item_id truly belongs to
	 * order_id before writing anything — see that method's own "never trust
	 * a request id" note. Nothing here needs to repeat that check.
	 */
	public static function handle_log_request(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'slk' ), '', array( 'response' => 403 ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'That order could not be found.', 'slk' ), '', array( 'response' => 404 ) );
		}

		check_admin_referer( 'slk_exchange_log_request_' . $order_id );

		$item_id     = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
		$wanted_size = isset( $_POST['wanted_size'] ) ? sanitize_text_field( wp_unslash( $_POST['wanted_size'] ) ) : '';
		$reason      = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : '';
		$note        = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

		$result = SLK_Exchange::create(
			array(
				'order_id'    => $order_id,
				'item_id'     => $item_id,
				'reason'      => $reason,
				'wanted_size' => $wanted_size,
				'note'        => $note,
			)
		);

		// The customer's acknowledgement is not sent here: SLK_Exchange::create()
		// fires slk_exchange_created, which SLK_Exchange_Emails answers, so a
		// request logged by staff is acknowledged exactly like one raised on
		// the site.
		self::redirect(
			$order->get_edit_order_url(),
			is_wp_error( $result ) ? 'error' : 'logged',
			is_wp_error( $result ) ? $result->get_error_message() : ''
		);
	}

	/* ------------------------------------------------------------------ */
	/* Small shared helpers                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * What the courier collects on delivery, stated on the request itself once
	 * it is dispatched — the plan's "on dispatched, if exchange_send_fee > 0,
	 * staff see the fee stated on the request". Since there is no online
	 * payment for it, this line and the customer's dispatch email are the only
	 * places the figure appears at all, so it is read live from SLK_Fulfilment
	 * exactly as that email reads it and the two can never quote different
	 * amounts. A fee of zero states nothing: there is nothing to collect.
	 *
	 * @param string $state The request's current state key.
	 * @return string Ready-to-echo HTML, empty when there is nothing to say.
	 */
	public static function send_fee_note( string $state ): string {
		if ( SLK_Exchange::STATE_DISPATCHED !== $state ) {
			return '';
		}

		$fee = SLK_Fulfilment::exchange_send_fee();

		if ( $fee <= 0 ) {
			return '';
		}

		return '<span class="description">' . sprintf(
			/* translators: %s: exchange send fee, e.g. "Rs. 350". */
			esc_html__( 'Collect %s on delivery.', 'slk' ),
			wp_kses_post( wc_price( $fee ) )
		) . '</span>';
	}

	/**
	 * Memoised wc_get_order(), because the list table's customer/order/piece
	 * columns each want the same order for one row.
	 */
	public static function get_order_cached( int $order_id ): ?WC_Order {
		if ( ! array_key_exists( $order_id, self::$order_cache ) ) {
			$order                          = $order_id > 0 ? wc_get_order( $order_id ) : false;
			self::$order_cache[ $order_id ] = $order instanceof WC_Order ? $order : null;
		}

		return self::$order_cache[ $order_id ];
	}

	/**
	 * A handful of rules for the state pill and the meta box table, scoped to
	 * the board and the order screen only. No separate stylesheet is enqueued
	 * for a handful of rules this small.
	 */
	public static function print_admin_styles(): void {
		$screen = get_current_screen();

		if ( ! $screen instanceof WP_Screen || ( self::$board_hook !== $screen->id && self::order_screen_id() !== $screen->id ) ) {
			return;
		}
		?>
		<style>
			.slk-exchange-state { display: inline-block; padding: 2px 8px; border-radius: 3px; background: #f0f0f1; font-size: 12px; line-height: 1.6; }
			.slk-exchange-state--approved,
			.slk-exchange-state--collecting { background: #e5f5fa; }
			.slk-exchange-state--received,
			.slk-exchange-state--dispatched { background: #edfaef; }
			.slk-exchange-state--closed { background: #f0f0f1; color: #646970; }
			.slk-exchange-state--declined { background: #fcf0f1; color: #8a2424; }
			.slk-exchange-meta-box table.widefat td { vertical-align: top; }
		</style>
		<?php
	}
}

/**
 * The board's WP_List_Table: columns request / customer / order / product /
 * size wanted / state / age, a state filter (WP_List_Table's own "views"),
 * and the bulk "Approve" action.
 *
 * Listing is built directly against SLK_Exchange's public POST_TYPE constant
 * and its own get() — SLK_Exchange exposes create/read/set_state for a
 * single request but no list query, because that is this screen's job, not
 * the data model's.
 */
final class SLK_Exchanges_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'exchange',
				'plural'   => 'exchanges',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		return array(
			'cb'          => '<input type="checkbox" />',
			'request'     => __( 'Request', 'slk' ),
			'customer'    => __( 'Customer', 'slk' ),
			'order'       => __( 'Order', 'slk' ),
			'product'     => __( 'Product', 'slk' ),
			'size_wanted' => __( 'Size wanted', 'slk' ),
			'state'       => __( 'State', 'slk' ),
			'age'         => __( 'Age', 'slk' ),
		);
	}

	protected function get_bulk_actions(): array {
		return array( 'approve' => __( 'Approve', 'slk' ) );
	}

	/**
	 * The state filter, as WP_List_Table's standard "All | Requested (3) | …"
	 * view links — the same pattern wp-admin's own post list tables use for
	 * status filtering.
	 */
	protected function get_views(): array {
		$counts  = wp_count_posts( SLK_Exchange::POST_TYPE );
		$current = isset( $_GET['exchange_state'] ) ? sanitize_key( wp_unslash( $_GET['exchange_state'] ) ) : '';
		$base    = remove_query_arg( array( 'exchange_state', 'paged', 'slk_notice', 'action', 'id', 'state', 'return', '_wpnonce' ) );

		$total = 0;
		foreach ( array_keys( SLK_Exchange::states() ) as $slug ) {
			$total += isset( $counts->$slug ) ? (int) $counts->$slug : 0;
		}

		$views = array(
			'all' => sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>',
				esc_url( $base ),
				'' === $current ? ' class="current"' : '',
				esc_html__( 'All', 'slk' ),
				$total
			),
		);

		foreach ( SLK_Exchange::states() as $slug => $label ) {
			$count = isset( $counts->$slug ) ? (int) $counts->$slug : 0;

			$views[ $slug ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>',
				esc_url( add_query_arg( 'exchange_state', $slug, $base ) ),
				$current === $slug ? ' class="current"' : '',
				esc_html( $label ),
				$count
			);
		}

		return $views;
	}

	public function prepare_items(): void {
		$per_page = 20;
		$paged    = $this->get_pagenum();
		$state    = isset( $_GET['exchange_state'] ) ? sanitize_key( wp_unslash( $_GET['exchange_state'] ) ) : '';

		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$result = self::query( $state, $paged, $per_page );

		$this->items = $result['items'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * @return array{items:array<int,array>,total:int}
	 */
	private static function query( string $state, int $paged, int $per_page ): array {
		// A state from the query string is only ever trusted once it matches
		// a real state key — otherwise every state is shown, same as "All".
		$post_status = ( '' !== $state && array_key_exists( $state, SLK_Exchange::states() ) )
			? $state
			: array_keys( SLK_Exchange::states() );

		$query = new WP_Query(
			array(
				'post_type'      => SLK_Exchange::POST_TYPE,
				'post_status'    => $post_status,
				'posts_per_page' => $per_page,
				'paged'          => max( 1, $paged ),
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);

		$items = array();
		foreach ( $query->posts as $post_id ) {
			$record = SLK_Exchange::get( (int) $post_id );
			if ( null !== $record ) {
				$items[] = $record;
			}
		}

		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * @param array $item A record shaped like SLK_Exchange::get()'s return value.
	 */
	public function column_cb( $item ): void {
		printf( '<input type="checkbox" name="exchange[]" value="%d" />', (int) $item['id'] );
	}

	public function column_request( $item ): void {
		$id     = (int) $item['id'];
		$reason = SLK_Exchange::reasons()[ $item['reason'] ?? '' ] ?? '';

		$out = '<strong>' . esc_html(
			sprintf(
				/* translators: %d: exchange request id. */
				__( 'Exchange #%d', 'slk' ),
				$id
			)
		) . '</strong>';

		if ( '' !== $reason ) {
			$out .= '<br /><span class="description">' . esc_html( $reason ) . '</span>';
		}

		if ( ! empty( $item['note'] ) ) {
			$out .= '<br /><span class="description">' . esc_html( wp_trim_words( (string) $item['note'], 14 ) ) . '</span>';
		}

		$out .= $this->row_actions( SLK_Exchange_Admin::row_actions_for( $item ) );

		// Every dynamic piece above was individually esc_html()'d before
		// concatenation; row_actions_for()'s links escape their own href/label.
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function column_customer( $item ): void {
		$order = SLK_Exchange_Admin::get_order_cached( (int) ( $item['order_id'] ?? 0 ) );

		if ( ! $order instanceof WC_Order ) {
			echo '&#8212;';
			return;
		}

		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		echo esc_html( '' !== $name ? $name : (string) $order->get_billing_email() );

		$phone = (string) $order->get_billing_phone();
		if ( '' !== $phone ) {
			echo '<br /><span class="description">' . esc_html( $phone ) . '</span>';
		}
	}

	public function column_order( $item ): void {
		$order = SLK_Exchange_Admin::get_order_cached( (int) ( $item['order_id'] ?? 0 ) );

		if ( ! $order instanceof WC_Order ) {
			echo '&#8212;';
			return;
		}

		printf(
			'<a href="%1$s">#%2$s</a>',
			esc_url( $order->get_edit_order_url() ),
			esc_html( $order->get_order_number() )
		);
	}

	public function column_product( $item ): void {
		$order = SLK_Exchange_Admin::get_order_cached( (int) ( $item['order_id'] ?? 0 ) );
		$line  = $order instanceof WC_Order ? $order->get_item( (int) ( $item['item_id'] ?? 0 ) ) : false;

		if ( $line ) {
			echo esc_html( $line->get_name() );
			return;
		}

		$product = ! empty( $item['product_id'] ) ? wc_get_product( (int) $item['product_id'] ) : false;
		echo esc_html( $product ? $product->get_name() : __( 'Item no longer on the order', 'slk' ) );
	}

	public function column_size_wanted( $item ): void {
		$size = trim( (string) ( $item['wanted_size'] ?? '' ) );
		echo '' !== $size ? esc_html( $size ) : '&#8212;';
	}

	public function column_state( $item ): void {
		$state = (string) ( $item['state'] ?? '' );
		$label = SLK_Exchange::states()[ $state ] ?? $state;

		printf(
			'<span class="slk-exchange-state slk-exchange-state--%1$s">%2$s</span>',
			esc_attr( $state ),
			esc_html( $label )
		);

		$fee_note = SLK_Exchange_Admin::send_fee_note( $state );
		if ( '' !== $fee_note ) {
			// send_fee_note() escapes its own copy and hands back wc_price()'s
			// own markup for the amount.
			echo '<br />' . $fee_note; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	public function column_age( $item ): void {
		$created   = isset( $item['created'] ) ? (string) $item['created'] : '';
		$timestamp = '' !== $created ? strtotime( $created . ' +00:00' ) : false;

		if ( false === $timestamp ) {
			echo '&#8212;';
			return;
		}

		$days = (int) floor( ( time() - $timestamp ) / DAY_IN_SECONDS );

		if ( $days <= 0 ) {
			esc_html_e( 'Today', 'slk' );
			return;
		}

		printf(
			/* translators: %d: number of days old the request is. */
			esc_html( _n( '%d day', '%d days', $days, 'slk' ) ),
			$days
		);
	}

	/**
	 * @param array  $item        A record shaped like SLK_Exchange::get()'s return value.
	 * @param string $column_name Column key.
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
	}

	public function no_items(): void {
		esc_html_e( 'No exchange requests yet.', 'slk' );
	}
}
