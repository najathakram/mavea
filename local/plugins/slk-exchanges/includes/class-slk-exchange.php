<?php
/**
 * The slk_exchange post type, its state machine, and exchange eligibility.
 *
 * One post per exchange request, linked to an order and a single order line
 * item. The post type is deliberately private (public=false, show_ui=false):
 * the customer portal and admin board built in later packages of this build
 * drive their own screens against the methods below, so WordPress must not
 * add a "Exchanges" edit screen or archive of its own on top of them.
 *
 * STATE MACHINE — the state IS the post_status, not a meta field, so every
 * WordPress API that already understands post statuses (wp_count_posts(),
 * status-change hooks, WP_Query's post_status arg) works on it for free.
 * Seven states, one linear pipeline plus a single off-ramp near its start:
 *
 *   requested -> approved -> collecting -> received -> dispatched -> closed
 *       \           /
 *        \-> declined <-/
 *
 * Each arrow is legal in both directions except into/out of the two terminal
 * states (closed, declined) — a row action can walk a request one step
 * forward or back along the pipeline. declined is reachable only from
 * requested or approved: once a courier is physically collecting the piece,
 * "decline" no longer describes anything that can happen to it. See
 * allowed_transitions() for the explicit map set_state() checks every call
 * against; there is no way to skip a step from outside this class.
 *
 * ELIGIBILITY — eligible_for() is the one place that decides whether a given
 * order line may open a request at all, and create() enforces it itself
 * rather than trusting a caller to have checked first: order id and item id
 * arrive from requests, and an order line id is a global primary key, not
 * scoped to one order, so it is verified against the order it claims to
 * belong to before anything is written (see the "never trust a request id"
 * comment on eligible_for() below).
 *
 * Nonce and capability checks are deliberately NOT this class's job — it has
 * no notion of the current HTTP request. Every write here trusts its caller
 * to have already verified the nonce and the current user's capability; that
 * verification belongs to the portal form handler and the admin board actions
 * built in later packages, the same division WooCommerce itself draws between
 * WC_Order (data) and the screens that edit one.
 *
 * @package slk-exchanges
 */

defined( 'ABSPATH' ) || exit;

final class SLK_Exchange {

	public const POST_TYPE = 'slk_exchange';

	/** Post meta keys. */
	public const META_ORDER_ID    = '_slk_exchange_order_id';
	public const META_ITEM_ID     = '_slk_exchange_item_id';
	public const META_PRODUCT_ID  = '_slk_exchange_product_id';
	public const META_CUSTOMER_ID = '_slk_exchange_customer_id';
	public const META_WANTED      = '_slk_exchange_wanted_size';
	public const META_REASON      = '_slk_exchange_reason';
	public const META_NOTE        = '_slk_exchange_note';
	public const META_LOG         = '_slk_exchange_log';

	/** States (post_status values). */
	public const STATE_REQUESTED  = 'requested';
	public const STATE_APPROVED   = 'approved';
	public const STATE_COLLECTING = 'collecting';
	public const STATE_RECEIVED   = 'received';
	public const STATE_DISPATCHED = 'dispatched';
	public const STATE_CLOSED     = 'closed';
	public const STATE_DECLINED   = 'declined';

	/** Reason enum. */
	public const REASON_FITS_SMALL = 'fits_small';
	public const REASON_FITS_LARGE = 'fits_large';
	public const REASON_FLAW       = 'flaw';
	public const REASON_OTHER      = 'other';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'register_post_statuses' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Registration                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * The post type. Private on purpose — see the file header. 'title' is
	 * the only supported field; it holds a human label ("Exchange — order
	 * #1234") for anywhere WordPress prints a post's title internally
	 * (search, the REST API if it were ever enabled), while every fact this
	 * class actually reads and writes lives in meta.
	 */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( 'Exchange requests', 'slk' ),
				'labels'              => array(
					'singular_name' => __( 'Exchange request', 'slk' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_admin_bar'   => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'hierarchical'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => true,
				'delete_with_user'    => false,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Custom post statuses for every state, registered so wp_insert_post()
	 * and wp_update_post() accept them as first-class statuses (rather than
	 * silently falling back to 'draft') and so wp_count_posts() reports them
	 * by name. All internal: show_ui is false on the post type itself, so
	 * none of these ever reach a WordPress-drawn screen.
	 */
	public static function register_post_statuses(): void {
		foreach ( self::states() as $slug => $label ) {
			register_post_status(
				$slug,
				array(
					'label'                     => $label,
					'public'                    => false,
					'internal'                  => true,
					'private'                   => true,
					'exclude_from_search'       => true,
					'show_in_admin_all_list'    => false,
					'show_in_admin_status_list' => false,
				)
			);
		}
	}

	/**
	 * @return array<string,string> State slug => label.
	 */
	public static function states(): array {
		return array(
			self::STATE_REQUESTED  => __( 'Requested', 'slk' ),
			self::STATE_APPROVED   => __( 'Approved', 'slk' ),
			self::STATE_COLLECTING => __( 'Collecting', 'slk' ),
			self::STATE_RECEIVED   => __( 'Received', 'slk' ),
			self::STATE_DISPATCHED => __( 'Dispatched', 'slk' ),
			self::STATE_CLOSED     => __( 'Closed', 'slk' ),
			self::STATE_DECLINED   => __( 'Declined', 'slk' ),
		);
	}

	/**
	 * @return array<string,string> Reason slug => label.
	 */
	public static function reasons(): array {
		return array(
			self::REASON_FITS_SMALL => __( 'Fits small', 'slk' ),
			self::REASON_FITS_LARGE => __( 'Fits large', 'slk' ),
			self::REASON_FLAW       => __( 'Flaw', 'slk' ),
			self::REASON_OTHER      => __( 'Other', 'slk' ),
		);
	}

	/**
	 * States that count as "in flight" — everything before the pipeline's
	 * two terminal states. Used both for open_count() and, added to
	 * STATE_CLOSED, for the duplicate-request guard in eligible_for().
	 *
	 * @return array<int,string>
	 */
	public static function open_states(): array {
		return array(
			self::STATE_REQUESTED,
			self::STATE_APPROVED,
			self::STATE_COLLECTING,
			self::STATE_RECEIVED,
			self::STATE_DISPATCHED,
		);
	}

	/**
	 * The explicit allowed-transitions map set_state() validates every call
	 * against. See the state-machine diagram in the file header for the
	 * reasoning behind each edge. Both terminal states map to an empty list:
	 * nothing leaves closed or declined through this class.
	 *
	 * @return array<string,array<int,string>> Current state => states it may move to.
	 */
	private static function allowed_transitions(): array {
		return array(
			self::STATE_REQUESTED  => array( self::STATE_APPROVED, self::STATE_DECLINED ),
			self::STATE_APPROVED   => array( self::STATE_COLLECTING, self::STATE_REQUESTED, self::STATE_DECLINED ),
			self::STATE_COLLECTING => array( self::STATE_RECEIVED, self::STATE_APPROVED ),
			self::STATE_RECEIVED   => array( self::STATE_DISPATCHED, self::STATE_COLLECTING ),
			self::STATE_DISPATCHED => array( self::STATE_CLOSED, self::STATE_RECEIVED ),
			self::STATE_CLOSED     => array(),
			self::STATE_DECLINED   => array(),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Create / read                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Open a new exchange request.
	 *
	 * product_id is deliberately never taken from $args: it is always read
	 * back off the order's own line item, so a caller cannot make a request
	 * point at a different product than the line it actually names.
	 *
	 * @param array{order_id:int,item_id:int,customer_id?:int,reason:string,wanted_size?:string,note?:string} $args {
	 *     @type int    $order_id    Order id.
	 *     @type int    $item_id     Order line item id (a WC_Order_Item_Product).
	 *     @type int    $customer_id Account the request belongs to, for
	 *                               for_customer(). Optional — omitted by the
	 *                               staff "log a WhatsApp request" path, which
	 *                               falls back to the order's own customer so
	 *                               that request still reaches their portal.
	 *     @type string $reason      One of the reasons() keys.
	 *     @type string $wanted_size Requested size/variation, free text. Optional.
	 *     @type string $note        Free text elaborating the reason. Optional.
	 * }
	 * @return int|WP_Error New exchange post id, or the reason it was refused.
	 */
	public static function create( array $args ) {
		$order_id = isset( $args['order_id'] ) ? absint( $args['order_id'] ) : 0;
		$item_id  = isset( $args['item_id'] ) ? absint( $args['item_id'] ) : 0;

		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'slk_exchange_invalid_order', __( 'That order could not be found.', 'slk' ) );
		}

		$eligibility = self::eligible_for( $order, $item_id );
		if ( ! $eligibility['ok'] ) {
			return new WP_Error( 'slk_exchange_ineligible', $eligibility['reason'] );
		}

		$reason = isset( $args['reason'] ) ? sanitize_key( (string) $args['reason'] ) : '';
		if ( ! array_key_exists( $reason, self::reasons() ) ) {
			return new WP_Error( 'slk_exchange_invalid_reason', __( 'Choose a reason for the exchange.', 'slk' ) );
		}

		// Eligibility already proved this item belongs to this order and is a
		// real product line, so the product id is read from it directly.
		$item       = $order->get_item( $item_id );
		$product_id = $item->get_product_id();

		$wanted_size = isset( $args['wanted_size'] ) ? sanitize_text_field( (string) $args['wanted_size'] ) : '';
		$note        = isset( $args['note'] ) ? sanitize_textarea_field( (string) $args['note'] ) : '';

		// Whose account this request shows up under. A guest order has no
		// customer id at all, in which case it simply never appears in a
		// portal — there is no account for it to appear in.
		$customer_id = isset( $args['customer_id'] ) ? absint( $args['customer_id'] ) : 0;
		if ( $customer_id <= 0 ) {
			$customer_id = (int) $order->get_customer_id();
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => self::STATE_REQUESTED,
				'post_title'  => sprintf(
					/* translators: 1: order number, 2: product name */
					__( 'Exchange — order #%1$s — %2$s', 'slk' ),
					$order->get_order_number(),
					$item->get_name()
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, self::META_ORDER_ID, $order_id );
		update_post_meta( $post_id, self::META_ITEM_ID, $item_id );
		update_post_meta( $post_id, self::META_PRODUCT_ID, $product_id );
		update_post_meta( $post_id, self::META_CUSTOMER_ID, $customer_id );
		update_post_meta( $post_id, self::META_WANTED, $wanted_size );
		update_post_meta( $post_id, self::META_REASON, $reason );
		update_post_meta( $post_id, self::META_NOTE, $note );

		self::log( $post_id, __( 'Requested.', 'slk' ) );

		/**
		 * A request has just been opened. The customer's acknowledgement hangs
		 * off this rather than being sent here, because a request can be opened
		 * from two places — the My Account portal and the staff "log a WhatsApp
		 * request" form on the order screen — and neither should have to
		 * remember to acknowledge it. Creation is also the one step with no
		 * transition for SLK_Exchange_Emails::maybe_send() to see.
		 *
		 * @param int $post_id New exchange request id.
		 */
		do_action( 'slk_exchange_created', $post_id );

		return $post_id;
	}

	/**
	 * @return array{id:int,order_id:int,item_id:int,product_id:int,wanted_size:string,reason:string,note:string,state:string,created:string,log:array}|null
	 */
	public static function get( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return array(
			'id'          => $post->ID,
			'order_id'    => (int) get_post_meta( $post->ID, self::META_ORDER_ID, true ),
			'item_id'     => (int) get_post_meta( $post->ID, self::META_ITEM_ID, true ),
			'product_id'  => (int) get_post_meta( $post->ID, self::META_PRODUCT_ID, true ),
			'wanted_size' => (string) get_post_meta( $post->ID, self::META_WANTED, true ),
			'reason'      => (string) get_post_meta( $post->ID, self::META_REASON, true ),
			'note'        => (string) get_post_meta( $post->ID, self::META_NOTE, true ),
			'state'       => $post->post_status,
			'created'     => $post->post_date_gmt,
			'log'         => self::log_entries( $post->ID ),
		);
	}

	/**
	 * Every exchange request ever opened against an order, newest first,
	 * whatever state it is in — including declined ones, so the order
	 * screen's meta box (a later package) can show the full history.
	 *
	 * @return array<int,array> Records shaped like get()'s return value.
	 */
	public static function for_order( int $order_id ): array {
		if ( $order_id <= 0 ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array_keys( self::states() ),
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, internal table; no index to add for a single meta key.
					array(
						'key'     => self::META_ORDER_ID,
						'value'   => $order_id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		$records = array();
		foreach ( $ids as $id ) {
			$record = self::get( (int) $id );
			if ( null !== $record ) {
				$records[] = $record;
			}
		}

		return $records;
	}

	/**
	 * Every exchange request one customer has opened, newest first, whatever
	 * state it is in — the My Account portal's "Your requests" list.
	 *
	 * The shape is deliberately not get()'s: the portal prints an order number
	 * and a garment name, not ids, and reads the state under the key 'status',
	 * so the order and its line are resolved here rather than leaving a
	 * customer-facing template to reach into WooCommerce for them.
	 *
	 * @return array<int,array{id:int,order_id:int,order_number:string,item_name:string,reason:string,wanted_size:string,note:string,status:string,date:string}>
	 */
	public static function for_customer( int $customer_id ): array {
		if ( $customer_id <= 0 ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array_keys( self::states() ),
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, internal table; no index to add for a single meta key.
					array(
						'key'     => self::META_CUSTOMER_ID,
						'value'   => $customer_id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		$records = array();

		foreach ( $ids as $id ) {
			$record = self::get( (int) $id );
			if ( null === $record ) {
				continue;
			}

			$order = wc_get_order( $record['order_id'] );
			$line  = $order instanceof WC_Order ? $order->get_item( $record['item_id'] ) : false;

			$records[] = array(
				'id'           => $record['id'],
				'order_id'     => $record['order_id'],
				'order_number' => $order instanceof WC_Order ? (string) $order->get_order_number() : '',
				'item_name'    => $line ? (string) $line->get_name() : '',
				'reason'       => $record['reason'],
				'wanted_size'  => $record['wanted_size'],
				'note'         => $record['note'],
				'status'       => $record['state'],
				'date'         => $record['created'],
			);
		}

		return $records;
	}

	/**
	 * Count of requests currently in flight (not yet closed or declined),
	 * for the admin board's badge and the "studio today" widget (a later
	 * package). wp_count_posts() groups by post_status in one query and is
	 * core-cached, so this stays cheap wherever it is called from.
	 */
	public static function open_count(): int {
		$counts = wp_count_posts( self::POST_TYPE );
		$total  = 0;

		foreach ( self::open_states() as $state ) {
			$total += isset( $counts->$state ) ? (int) $counts->$state : 0;
		}

		return $total;
	}

	/* ------------------------------------------------------------------ */
	/* State machine                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Move an exchange to a new state, refusing any jump that is not an
	 * explicit edge in allowed_transitions(). Every successful move — and
	 * only a successful move — appends an entry to the audit trail.
	 *
	 * @param int    $id    Exchange post id.
	 * @param string $state Target state, one of the states() keys.
	 * @param string $note  Optional free text explaining the move (e.g. why
	 *                      a request was declined), kept with the log entry.
	 * @return true|WP_Error
	 */
	public static function set_state( int $id, string $state, string $note = '' ) {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'slk_exchange_not_found', __( 'That exchange request could not be found.', 'slk' ) );
		}

		if ( ! array_key_exists( $state, self::states() ) ) {
			return new WP_Error( 'slk_exchange_unknown_state', __( 'That is not a recognised exchange state.', 'slk' ) );
		}

		$current = $post->post_status;

		if ( $state === $current ) {
			return true; // Already there — a no-op, not an error.
		}

		$allowed = self::allowed_transitions();
		if ( ! in_array( $state, $allowed[ $current ] ?? array(), true ) ) {
			return new WP_Error(
				'slk_exchange_illegal_transition',
				sprintf(
					/* translators: 1: current state label, 2: requested state label */
					__( 'An exchange request cannot move from "%1$s" to "%2$s".', 'slk' ),
					self::states()[ $current ] ?? $current,
					self::states()[ $state ] ?? $state
				)
			);
		}

		$updated = wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => $state,
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		self::log(
			$id,
			sprintf(
				/* translators: 1: previous state label, 2: new state label */
				__( '%1$s → %2$s', 'slk' ),
				self::states()[ $current ] ?? $current,
				self::states()[ $state ] ?? $state
			),
			$note
		);

		return true;
	}

	/**
	 * Append a timestamped entry to an exchange's audit trail, stored as
	 * post meta so the full history of a request — including who acted and
	 * when — survives independently of the post's current state, and a
	 * dispute can be reconstructed after the fact.
	 */
	private static function log( int $id, string $message, string $note = '' ): void {
		$entries   = self::log_entries( $id );
		$entries[] = array(
			'date'    => gmdate( 'c' ),
			'message' => $message,
			'note'    => $note,
			'user_id' => get_current_user_id(),
		);

		update_post_meta( $id, self::META_LOG, $entries );
	}

	/**
	 * @return array<int,array{date:string,message:string,note:string,user_id:int}>
	 */
	public static function log_entries( int $id ): array {
		$entries = get_post_meta( $id, self::META_LOG, true );

		return is_array( $entries ) ? $entries : array();
	}

	/* ------------------------------------------------------------------ */
	/* Eligibility                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Whether a shopper may open an exchange request against one line of an
	 * order right now. The single gate create() and the customer portal (a
	 * later package) both check before doing anything else.
	 *
	 * "Never trust a request id": $item_id is a WooCommerce order-item id, a
	 * primary key global across every order in the store, not scoped to
	 * $order. $order->get_item() already refuses to return an item that
	 * belongs to a different order (it checks the item's own order id
	 * internally), and the explicit check below re-asserts the same thing
	 * one level up, so a mismatched or forged item id can never be read as
	 * belonging to the order a caller claims it does.
	 *
	 * @param WC_Order $order   Order the request would be filed against.
	 * @param int      $item_id Order line item id, claimed to belong to $order.
	 * @return array{ok:bool,reason:string}
	 */
	public static function eligible_for( WC_Order $order, int $item_id ): array {
		// Completed only, as the plan has it: the window below is measured from
		// the completion date, which WooCommerce stamps when an order reaches
		// completed and never before — so a processing order could only ever be
		// refused a step later, with a far less obvious reason.
		if ( 'completed' !== $order->get_status() ) {
			return array(
				'ok'     => false,
				'reason' => __( 'Only a completed order can request an exchange.', 'slk' ),
			);
		}

		$item = $item_id > 0 ? $order->get_item( $item_id ) : false;

		if ( ! $item instanceof WC_Order_Item_Product || (int) $item->get_order_id() !== $order->get_id() ) {
			return array(
				'ok'     => false,
				'reason' => __( 'That item could not be found on this order.', 'slk' ),
			);
		}

		if ( self::has_blocking_exchange( $order->get_id(), $item_id ) ) {
			return array(
				'ok'     => false,
				'reason' => __( 'An exchange has already been requested for this item.', 'slk' ),
			);
		}

		$completed = $order->get_date_completed();
		if ( ! $completed instanceof WC_DateTime ) {
			return array(
				'ok'     => false,
				'reason' => __( 'This order has no completion date yet, so no exchange window has opened for it.', 'slk' ),
			);
		}

		// Never hardcode the window — read the live fulfilment setting every
		// time, so editing it in the dashboard changes this answer at once.
		$window_days = SLK_Fulfilment::exchange_window_days();
		$deadline    = $completed->getTimestamp() + ( $window_days * DAY_IN_SECONDS );

		if ( time() > $deadline ) {
			return array(
				'ok'     => false,
				'reason' => sprintf(
					/* translators: %d: number of days */
					__( 'The %d-day exchange window for this order has closed.', 'slk' ),
					$window_days
				),
			);
		}

		return array(
			'ok'     => true,
			'reason' => '',
		);
	}

	/**
	 * Whether an order line already carries a request that should block a
	 * new one. A declined request does not block: a shopper turned down
	 * once is free to try again (a different reason, a corrected size). Any
	 * request still open, or already closed out successfully, does block —
	 * one exchange per line at a time, ever.
	 */
	private static function has_blocking_exchange( int $order_id, int $item_id ): bool {
		$blocking = array_merge( self::open_states(), array( self::STATE_CLOSED ) );

		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => $blocking,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, internal table; no index to add for two meta keys.
					'relation' => 'AND',
					array(
						'key'     => self::META_ORDER_ID,
						'value'   => $order_id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => self::META_ITEM_ID,
						'value'   => $item_id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		return ! empty( $ids );
	}
}
