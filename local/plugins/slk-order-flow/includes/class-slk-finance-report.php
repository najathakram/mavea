<?php
/**
 * WooCommerce -> Finances: a period revenue report, a cash-on-delivery
 * position panel, a loyalty points liability panel, and a CSV export of the
 * same period's orders.
 *
 * Every figure here is either summed straight off wc_get_orders()/WC_Order
 * data WooCommerce already computed, or read from a value the system that
 * owns it already stored — SLK_Points's balances, and the send fee
 * SLK_Exchange stamps onto a request the moment it is dispatched. Nothing is
 * re-derived from scratch, and no figure is inferred from a setting's
 * CURRENT value: the exchange fee in particular changes hands as cash with
 * the courier, so the stamp taken at dispatch is the only thing that knows
 * what a given month was actually charged. A period with nothing stamped
 * prints an em dash rather than a zero — see render_exchange_fee_row().
 *
 * PAGE vs EXPORT — render_page() and export_csv() both start from
 * resolve_period() and compute(), so the numbers on screen and the rows in
 * the download can never disagree about what "this period" means.
 *
 * @package slk-order-flow
 */

defined( 'ABSPATH' ) || exit;

final class SLK_Finance_Report {

	public const PAGE_SLUG = 'slk-finances';

	public const PERIOD_THIS_MONTH = 'this_month';
	public const PERIOD_LAST_MONTH = 'last_month';
	public const PERIOD_LAST_30    = 'last_30';
	public const PERIOD_CUSTOM     = 'custom';

	/**
	 * Hidden order line item meta stamped by slk-checkout's customization
	 * package (SLK_Customization_Cart::checkout_create_order_line_item()).
	 * That class owns the payload and exposes no public reader for it, so
	 * the key is read directly here — it is stable, already-written data on
	 * live orders, the same way this class reads SLK_Payments' own
	 * '_slk_cod_fee' below rather than asking either class to grow a report
	 * accessor it otherwise has no use for.
	 */
	private const CUSTOMIZATION_META_KEY = '_slk_customization';

	/** Order meta stamped by SLK_Payments::record_cod_fee() on every COD order. */
	private const COD_FEE_META_KEY = '_slk_cod_fee';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_slk_finance_export_csv', array( __CLASS__, 'export_csv' ) );
	}

	/**
	 * WooCommerce -> Finances, gated at manage_woocommerce the same way every
	 * other submenu this build adds is (see SLK_Exchange_Admin::register_menu()).
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Finances', 'slk' ),
			__( 'Finances', 'slk' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Period resolution                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array<string,string> Period key => label, in menu order.
	 */
	private static function period_options(): array {
		return array(
			self::PERIOD_THIS_MONTH => __( 'This month', 'slk' ),
			self::PERIOD_LAST_MONTH => __( 'Last month', 'slk' ),
			self::PERIOD_LAST_30    => __( 'Last 30 days', 'slk' ),
			self::PERIOD_CUSTOM     => __( 'Custom range', 'slk' ),
		);
	}

	/**
	 * The period the current request asked for, read from the query string
	 * and sanitised against the fixed set of choices above — a custom range
	 * additionally against two real calendar dates. Anything unrecognised
	 * or malformed quietly falls back to this month: a report screen must
	 * always show something rather than error out on a hand-edited URL.
	 *
	 * @return array{key:string,label:string,start:DateTimeImmutable,end:DateTimeImmutable}
	 */
	public static function resolve_period(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a read-only report filter, not a state change; nothing here writes anything.
		$requested = isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : self::PERIOD_THIS_MONTH;
		// phpcs:enable

		$options = self::period_options();
		if ( ! array_key_exists( $requested, $options ) ) {
			$requested = self::PERIOD_THIS_MONTH;
		}

		$now = current_datetime();

		if ( self::PERIOD_CUSTOM === $requested ) {
			$custom = self::resolve_custom_range( $now );

			if ( null !== $custom ) {
				return array(
					'key'   => self::PERIOD_CUSTOM,
					'label' => $options[ self::PERIOD_CUSTOM ],
					'start' => $custom[0],
					'end'   => $custom[1],
				);
			}

			// No usable "from"/"to" — same silent fallback as an unrecognised period.
			$requested = self::PERIOD_THIS_MONTH;
		}

		switch ( $requested ) {
			case self::PERIOD_LAST_MONTH:
				$start = $now->modify( 'first day of last month' )->setTime( 0, 0, 0 );
				$end   = $now->modify( 'last day of last month' )->setTime( 23, 59, 59 );
				break;

			case self::PERIOD_LAST_30:
				$start = $now->modify( '-29 days' )->setTime( 0, 0, 0 );
				$end   = $now->setTime( 23, 59, 59 );
				break;

			case self::PERIOD_THIS_MONTH:
			default:
				$requested = self::PERIOD_THIS_MONTH;
				$start     = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );
				$end       = $now->modify( 'last day of this month' )->setTime( 23, 59, 59 );
				break;
		}

		return array(
			'key'   => $requested,
			'label' => $options[ $requested ],
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * @return array{0:DateTimeImmutable,1:DateTimeImmutable}|null Null when
	 *         "from" or "to" is missing, not a real calendar date, or "from"
	 *         sits after "to" — a custom range this broken has nothing
	 *         honest to report, so the caller falls back to this month
	 *         rather than run a query with a range that makes no sense.
	 */
	private static function resolve_custom_range( DateTimeImmutable $now ): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only report filter, see resolve_period().
		$from_raw = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to_raw   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		// phpcs:enable

		$from = self::parse_date( $from_raw, $now );
		$to   = self::parse_date( $to_raw, $now );

		if ( null === $from || null === $to || $from > $to ) {
			return null;
		}

		return array( $from->setTime( 0, 0, 0 ), $to->setTime( 23, 59, 59 ) );
	}

	/**
	 * A strict YYYY-MM-DD parse. createFromFormat()'s leading '!' resets
	 * every field DateTime would otherwise default from "now", so a
	 * malformed or partial string can never silently pass as valid with
	 * today's time smuggled onto it.
	 */
	private static function parse_date( string $raw, DateTimeImmutable $now ): ?DateTimeImmutable {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
			return null;
		}

		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $raw, $now->getTimezone() );

		return ( $date instanceof DateTimeImmutable ) ? $date : null;
	}

	/**
	 * The wc_get_orders() date_created/date_paid range string for a period —
	 * WooCommerce's own "YYYY-MM-DD...YYYY-MM-DD" syntax, resolved to a
	 * whole-day-inclusive date_query internally. See
	 * WC_Data_Store_WP::parse_date_for_wp_query().
	 *
	 * @param array{start:DateTimeImmutable,end:DateTimeImmutable} $period
	 */
	private static function date_range_arg( array $period ): string {
		return $period['start']->format( 'Y-m-d' ) . '...' . $period['end']->format( 'Y-m-d' );
	}

	/* ------------------------------------------------------------------ */
	/* The numbers                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Everything the page and the CSV export need for one period, built once
	 * so the two can never quote different figures for the same range.
	 *
	 * @param array{start:DateTimeImmutable,end:DateTimeImmutable} $period
	 * @return array{
	 *     orders: array<int,WC_Order>,
	 *     item_total: float,
	 *     shipping_total: float,
	 *     cod_fee_total: float,
	 *     customization_fee_total: float,
	 *     exchange: array{available:bool,total:float,count:int},
	 *     refund_total: float,
	 *     net: float,
	 *     cod: array{outstanding:float,collected:float},
	 *     points: array{available:bool,points:int,value:float,block_points:int,block_value:float},
	 * }
	 */
	private static function compute( array $period ): array {
		$orders = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'status'       => self::revenue_statuses(),
				'date_created' => self::date_range_arg( $period ),
				'limit'        => -1, // WC_Object_Query defaults to the site's "posts per page" option; a report must never truncate on that.
				'orderby'      => 'date',
				'order'        => 'ASC',
			)
		);
		$orders = array_values( array_filter( $orders, static fn( $order ) => $order instanceof WC_Order ) );

		$item_total = 0.0;
		$shipping   = 0.0;
		$cod_fee    = 0.0;
		$custom_fee = 0.0;

		foreach ( $orders as $order ) {
			foreach ( $order->get_items( 'line_item' ) as $line_item ) {
				$item_total += (float) $line_item->get_total();
				$custom_fee += self::line_customization_fee( $line_item );
			}

			$shipping += (float) $order->get_shipping_total();
			$cod_fee  += (float) $order->get_meta( self::COD_FEE_META_KEY, true );
		}

		$refund_total = self::refunds_total( $period );
		$exchange     = self::exchange_fees( $period );

		return array(
			'orders'                  => $orders,
			'item_total'              => SLK_Money::rupees( $item_total ),
			'shipping_total'          => SLK_Money::rupees( $shipping ),
			'cod_fee_total'           => SLK_Money::rupees( $cod_fee ),
			'customization_fee_total' => SLK_Money::rupees( $custom_fee ),
			'exchange'                => $exchange,
			'refund_total'            => SLK_Money::rupees( $refund_total ),
			'net'                     => SLK_Money::rupees( $item_total + $shipping + $cod_fee + $exchange['total'] - $refund_total ),
			'cod'                     => self::cod_position( $period ),
			'points'                  => self::points_liability(),
		);
	}

	/**
	 * The order statuses whose money this report treats as earned inside the
	 * period: WooCommerce's own paid set, plus `refunded`.
	 *
	 * `refunded` matters because WooCommerce moves an order there by itself
	 * the moment it is refunded in full, and refunds_total() below counts
	 * every refund raised in the period whatever its parent order now says.
	 * Leaving fully refunded orders out would subtract a refund whose gross
	 * was never added, so one full refund would read as a pure negative
	 * instead of netting to zero — and re-running an earlier period after a
	 * refund landed would retroactively shrink that period's gross. Counting
	 * the gross and then the refund keeps both periods honest.
	 *
	 * @return array<int,string>
	 */
	private static function revenue_statuses(): array {
		return array_values( array_unique( array_merge( wc_get_is_paid_statuses(), array( 'refunded' ) ) ) );
	}

	/**
	 * A single order line item's customization fee, read straight off the
	 * hidden meta slk-checkout stamped at checkout — never re-derived from
	 * the product's current customization configuration, which may have
	 * changed (or been removed) since the order was placed.
	 *
	 * The stamped 'fee' is a PER-UNIT amount: SLK_Customization_Cart::
	 * apply_line_fee() adds it to the product's unit price, so WooCommerce
	 * multiplies it by the line quantity when it computes the line total this
	 * figure is meant to break out. It is multiplied by the quantity here for
	 * the same reason, and NOT netted against refunded quantity — gross item
	 * revenue above is pre-refund too, and the Refunds row subtracts that
	 * money once, on its own line.
	 */
	private static function line_customization_fee( WC_Order_Item $line_item ): float {
		$custom = $line_item->get_meta( self::CUSTOMIZATION_META_KEY, true );

		if ( ! is_array( $custom ) || ! isset( $custom['fee'] ) || ! is_numeric( $custom['fee'] ) ) {
			return 0.0;
		}

		return max( 0.0, (float) $custom['fee'] ) * max( 0, (int) $line_item->get_quantity() );
	}

	/**
	 * Exchange send fees charged in the period: the amount stamped onto each
	 * slk-exchanges request at the moment it was dispatched
	 * (SLK_Exchange::stamp_send_fee()), summed by dispatch date rather than
	 * by the date of the order behind it — the fee is charged when the
	 * replacement goes out, which is routinely a different month from the
	 * purchase.
	 *
	 * The stamp is read rather than SLK_Fulfilment::exchange_send_fee(),
	 * which reports what the fee is TODAY: the cash changes hands with the
	 * courier and never touches a payment record, so a request dispatched
	 * before the setting last moved would otherwise be totalled at the wrong
	 * rate. Requests dispatched before the stamping existed carry no meta and
	 * cannot be counted at all — hence 'count', which lets the row print an
	 * em dash for those periods instead of an authoritative zero.
	 *
	 * @param array{start:DateTimeImmutable,end:DateTimeImmutable} $period
	 * @return array{available:bool,total:float,count:int}
	 */
	private static function exchange_fees( array $period ): array {
		if ( ! class_exists( 'SLK_Exchange' ) ) {
			return array(
				'available' => false,
				'total'     => 0.0,
				'count'     => 0,
			);
		}

		$ids = get_posts(
			array(
				'post_type'      => SLK_Exchange::POST_TYPE,
				'post_status'    => array_keys( SLK_Exchange::states() ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin-only report screen, run on demand; matches SLK_Exchange's own queries against this small internal post type.
					array(
						'key'     => SLK_Exchange::META_DISPATCHED_AT,
						'value'   => array(
							$period['start']->format( 'Y-m-d H:i:s' ),
							$period['end']->format( 'Y-m-d H:i:s' ),
						),
						'compare' => 'BETWEEN',
						'type'    => 'DATETIME',
					),
				),
			)
		);

		$total = 0.0;
		foreach ( $ids as $id ) {
			$total += max( 0.0, (float) get_post_meta( (int) $id, SLK_Exchange::META_FEE, true ) );
		}

		return array(
			'available' => true,
			'total'     => SLK_Money::rupees( $total ),
			'count'     => count( $ids ),
		);
	}

	/**
	 * Rupees refunded across every order, of any status, whose refund was
	 * itself created within the period — refund date, not order date, so a
	 * refund issued this month against an order placed last month still
	 * counts as this month's cost.
	 *
	 * @param array{start:DateTimeImmutable,end:DateTimeImmutable} $period
	 */
	private static function refunds_total( array $period ): float {
		$refunds = wc_get_orders(
			array(
				'type'         => 'shop_order_refund',
				'date_created' => self::date_range_arg( $period ),
				'limit'        => -1,
			)
		);

		$total = 0.0;
		foreach ( $refunds as $refund ) {
			if ( $refund instanceof WC_Order_Refund ) {
				$total += abs( (float) $refund->get_amount() );
			}
		}

		return $total;
	}

	/**
	 * Cash-on-delivery orders placed within the period, split by whether the
	 * courier has actually handed the money over yet. `completed` is the
	 * only status this store's fulfilment lifecycle treats as delivered and
	 * paid (see SLK_Exchange::eligible_for(), which measures its own
	 * exchange window from the same status); everything short of it is
	 * still outstanding. A cancelled, refunded, failed or trashed order
	 * carries no live money either way — refunded value is already counted
	 * in refunds_total() above — so neither bucket claims it.
	 *
	 * `pending` and `checkout-draft` are dead for the same reason even though
	 * they look live: WooCommerce writes the order row (payment method and
	 * all) BEFORE the gateway runs, and the COD gateway always moves a real
	 * placed order on to processing or on-hold. Anything still sitting at
	 * pending is therefore a checkout the customer walked away from — no
	 * parcel, no courier, no cash to collect — and counting it would inflate
	 * "outstanding" month after month with money that will never arrive.
	 *
	 * @param array{start:DateTimeImmutable,end:DateTimeImmutable} $period
	 * @return array{outstanding:float,collected:float}
	 */
	private static function cod_position( array $period ): array {
		$orders = wc_get_orders(
			array(
				'type'           => 'shop_order',
				'payment_method' => 'cod',
				'date_created'   => self::date_range_arg( $period ),
				'limit'          => -1,
			)
		);

		$dead_statuses = array( 'pending', 'checkout-draft', 'cancelled', 'refunded', 'failed', 'trash' );

		$outstanding = 0.0;
		$collected   = 0.0;

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$status = $order->get_status();
			if ( in_array( $status, $dead_statuses, true ) ) {
				continue;
			}

			if ( 'completed' === $status ) {
				$collected += (float) $order->get_total();
			} else {
				$outstanding += (float) $order->get_total();
			}
		}

		return array(
			'outstanding' => SLK_Money::rupees( $outstanding ),
			'collected'   => SLK_Money::rupees( $collected ),
		);
	}

	/**
	 * Outstanding loyalty points, valued at the currently configured block
	 * rate. Reads SLK_Points's own already-maintained balance meta rather
	 * than replaying the ledger or re-deriving anything: the deferred cost
	 * is what those stored balances say it is right now, not what a fresh
	 * recomputation might argue for.
	 *
	 * @return array{available:bool,points:int,value:float,block_points:int,block_value:float}
	 */
	private static function points_liability(): array {
		if ( ! class_exists( 'SLK_Points' ) ) {
			return array(
				'available'    => false,
				'points'       => 0,
				'value'        => 0.0,
				'block_points' => 0,
				'block_value'  => 0.0,
			);
		}

		$settings     = SLK_Points::settings();
		$block_points = max( 1, (int) $settings['block_points'] );
		$block_value  = max( 0.0, (float) $settings['block_value'] );

		// Only users who actually carry a positive balance — a store-wide
		// scan of every account for a meta key most will never have set.
		$user_ids = get_users(
			array(
				'fields'       => 'ID',
				'meta_key'     => SLK_Points::META_BALANCE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- admin-only report screen, run on demand, not per storefront request.
				'meta_value'   => 0,
				'meta_compare' => '>',
				'meta_type'    => 'NUMERIC',
			)
		);

		$total_points = 0;
		foreach ( $user_ids as $user_id ) {
			$total_points += SLK_Points::balance( (int) $user_id );
		}

		return array(
			'available'    => true,
			'points'       => $total_points,
			'value'        => SLK_Money::rupees( $total_points * ( $block_value / $block_points ) ),
			'block_points' => $block_points,
			'block_value'  => $block_value,
		);
	}

	/* ------------------------------------------------------------------ */
	/* The page                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * The screen: a period picker, a CSV export link, and the three panels —
	 * revenue, cash-on-delivery position, points liability. capability is
	 * re-checked here (the menu registration already gates it) as the same
	 * defence-in-depth every screen this build adds uses.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'slk' ), '', array( 'response' => 403 ) );
		}

		$period = self::resolve_period();
		$report = self::compute( $period );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Finances', 'slk' ) . '</h1>';

		self::render_period_form( $period );
		self::render_export_button( $period );
		self::render_revenue_panel( $report );
		self::render_cod_panel( $report['cod'] );
		self::render_points_panel( $report['points'] );

		echo '</div>';
	}

	/**
	 * The period-picker form: This month / Last month / Last 30 days /
	 * Custom range, plain GET so the period lives in the query string and
	 * the page (and the export link below it) can be bookmarked or shared.
	 *
	 * @param array{key:string,label:string,start:DateTimeImmutable,end:DateTimeImmutable} $period
	 */
	private static function render_period_form( array $period ): void {
		echo '<form method="get" style="margin:16px 0 8px;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';

		echo '<div><label for="slk-finance-period" style="display:block;font-weight:600;margin-bottom:4px;">' . esc_html__( 'Period', 'slk' ) . '</label>';
		echo '<select id="slk-finance-period" name="period">';
		foreach ( self::period_options() as $key => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $key ),
				selected( $period['key'], $key, false ),
				esc_html( $label )
			);
		}
		echo '</select></div>';

		// Always rendered, not just for a custom range: staff can switch the
		// dropdown to "Custom range" and fill these in without the form
		// reloading first to reveal them.
		$from_value = self::PERIOD_CUSTOM === $period['key'] ? $period['start']->format( 'Y-m-d' ) : '';
		$to_value   = self::PERIOD_CUSTOM === $period['key'] ? $period['end']->format( 'Y-m-d' ) : '';

		printf(
			'<div><label for="slk-finance-from" style="display:block;font-weight:600;margin-bottom:4px;">%1$s</label><input type="date" id="slk-finance-from" name="from" value="%2$s" /></div>',
			esc_html__( 'From', 'slk' ),
			esc_attr( $from_value )
		);
		printf(
			'<div><label for="slk-finance-to" style="display:block;font-weight:600;margin-bottom:4px;">%1$s</label><input type="date" id="slk-finance-to" name="to" value="%2$s" /></div>',
			esc_html__( 'To', 'slk' ),
			esc_attr( $to_value )
		);

		echo '<div><button type="submit" class="button button-primary">' . esc_html__( 'Apply', 'slk' ) . '</button></div>';
		echo '</form>';

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: period start date, 2: period end date, both formatted for the site's date setting. */
					__( 'Showing %1$s to %2$s.', 'slk' ),
					wp_date( get_option( 'date_format' ), $period['start']->getTimestamp() ),
					wp_date( get_option( 'date_format' ), $period['end']->getTimestamp() )
				)
			)
		);
	}

	/**
	 * A single link to admin-post.php, nonce-gated, carrying the same period
	 * the page is currently showing so the download always matches what is
	 * on screen.
	 */
	private static function render_export_button( array $period ): void {
		$args = array(
			'action' => 'slk_finance_export_csv',
			'period' => $period['key'],
		);

		if ( self::PERIOD_CUSTOM === $period['key'] ) {
			$args['from'] = $period['start']->format( 'Y-m-d' );
			$args['to']   = $period['end']->format( 'Y-m-d' );
		}

		$url = wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'slk_finance_export_csv' );

		printf(
			'<p><a class="button" href="%1$s">%2$s</a></p>',
			esc_url( $url ),
			esc_html__( 'Export CSV for this period', 'slk' )
		);
	}

	private static function render_revenue_panel( array $report ): void {
		echo '<h2>' . esc_html__( 'Revenue', 'slk' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:640px;">';
		echo '<tbody>';

		self::render_amount_row( __( 'Gross item revenue', 'slk' ), $report['item_total'] );
		self::render_amount_row( __( 'Delivery charged', 'slk' ), $report['shipping_total'] );
		self::render_amount_row( __( 'COD handling collected', 'slk' ), $report['cod_fee_total'] );
		self::render_amount_row(
			__( 'Of which, customization fees', 'slk' ),
			$report['customization_fee_total']
		);
		self::render_exchange_fee_row( $report['exchange'] );
		self::render_amount_row( __( 'Refunds', 'slk' ), -$report['refund_total'] );

		echo '<tr style="font-weight:600;border-top:2px solid #c3c4c7;">';
		echo '<th scope="row">' . esc_html__( 'Net', 'slk' ) . '</th>';
		echo '<td>' . wp_kses_post( wc_price( $report['net'] ) ) . '</td>';
		echo '</tr>';

		echo '</tbody></table>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( '"Of which, customization fees" is already included in gross item revenue above, not additional to it — it breaks out how much of that revenue was customization markup, and so is not counted again in Net. Exchange send fees are counted by the date the replacement was dispatched, which is often a different period from the order behind it.', 'slk' )
		);
	}

	/**
	 * @param string $label  Row label.
	 * @param float  $amount Rupees. A negative amount prints with a minus
	 *                       sign ahead of the formatted price rather than
	 *                       wc_price() on a negative number, which some
	 *                       currency formats render ambiguously.
	 */
	private static function render_amount_row( string $label, float $amount ): void {
		$formatted = $amount < 0
			? '&minus;' . wc_price( abs( $amount ) )
			: wc_price( $amount );

		echo '<tr>';
		echo '<th scope="row" style="font-weight:400;">' . esc_html( $label ) . '</th>';
		echo '<td>' . wp_kses_post( $formatted ) . '</td>';
		echo '</tr>';
	}

	/**
	 * The exchange send fees stamped on requests dispatched in the period.
	 *
	 * An em dash, never a zero, when there is nothing stamped: the fee is
	 * cash handed to a courier, so a period with no stamped request is one
	 * this screen knows nothing about — either exchanges are not running, or
	 * it predates the stamping — and printing "Rs. 0.00" would assert no fee
	 * was ever charged, which is exactly what it cannot tell.
	 *
	 * @param array{available:bool,total:float,count:int} $exchange
	 */
	private static function render_exchange_fee_row( array $exchange ): void {
		echo '<tr>';
		echo '<th scope="row" style="font-weight:400;">' . esc_html__( 'Exchange send fees charged', 'slk' ) . '</th>';

		if ( empty( $exchange['available'] ) || $exchange['count'] < 1 ) {
			echo '<td>&mdash; <span class="description">' . esc_html__( 'No exchange with a recorded fee was dispatched in this period. The fee is collected in cash by the courier, and is only on record for exchanges dispatched from now on.', 'slk' ) . '</span></td>';
		} else {
			echo '<td>' . wp_kses_post( wc_price( $exchange['total'] ) ) . '</td>';
		}

		echo '</tr>';
	}

	/**
	 * @param array{outstanding:float,collected:float} $cod
	 */
	private static function render_cod_panel( array $cod ): void {
		echo '<h2>' . esc_html__( 'Cash on delivery position', 'slk' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Cash on delivery money exists only once the courier hands it over. Outstanding is what is still owed for orders placed this period; collected is what has actually come in.', 'slk' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:640px;">';
		echo '<tbody>';
		self::render_amount_row( __( 'Outstanding (placed, not yet completed)', 'slk' ), $cod['outstanding'] );
		self::render_amount_row( __( 'Collected (completed)', 'slk' ), $cod['collected'] );
		echo '</tbody></table>';
	}

	/**
	 * @param array{available:bool,points:int,value:float,block_points:int,block_value:float} $points
	 */
	private static function render_points_panel( array $points ): void {
		echo '<h2>' . esc_html__( 'Loyalty points liability', 'slk' ) . '</h2>';

		if ( empty( $points['available'] ) ) {
			echo '<p>&mdash; ' . esc_html__( 'Loyalty points are not active on this store.', 'slk' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:640px;">';
		echo '<tbody>';
		echo '<tr><th scope="row" style="font-weight:400;">' . esc_html__( 'Outstanding points', 'slk' ) . '</th><td>' . esc_html( number_format_i18n( $points['points'] ) ) . '</td></tr>';
		self::render_amount_row( __( 'Value at the current block rate', 'slk' ), $points['value'] );
		echo '</tbody></table>';

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: points needed for one redemption block, 2: rupee value of a block, plain text. */
					__( 'Every %1$s points are worth %2$s of credit — what every point in circulation would cost the store if redeemed today.', 'slk' ),
					number_format_i18n( $points['block_points'] ),
					wp_strip_all_tags( wc_price( $points['block_value'] ) )
				)
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* CSV export                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * admin_post_slk_finance_export_csv. Streams one row per order in the
	 * currently selected period, matching the panels above.
	 */
	public static function export_csv(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'slk' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'slk_finance_export_csv' );

		$period = self::resolve_period();
		$report = self::compute( $period );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . self::export_filename( $period ) . '"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a generated download, not touching the filesystem.
		$out = fopen( 'php://output', 'w' );

		fputcsv(
			$out,
			array(
				__( 'Order', 'slk' ),
				__( 'Date', 'slk' ),
				__( 'Status', 'slk' ),
				__( 'Payment method', 'slk' ),
				__( 'Item total', 'slk' ),
				__( 'Delivery', 'slk' ),
				__( 'COD fee', 'slk' ),
				__( 'Customization fee', 'slk' ),
				__( 'Refunded to date', 'slk' ),
				__( 'Order total', 'slk' ),
			)
		);

		foreach ( $report['orders'] as $order ) {
			fputcsv( $out, self::csv_row( $order ) );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		exit;
	}

	/**
	 * One order as a CSV row, in the same column order as the header row in
	 * export_csv(). Amounts are plain numbers, not wc_price()'s HTML — a
	 * spreadsheet needs to sum this column, not render it.
	 *
	 * @return array<int,string>
	 */
	private static function csv_row( WC_Order $order ): array {
		$item_total = 0.0;
		$custom_fee = 0.0;

		foreach ( $order->get_items( 'line_item' ) as $line_item ) {
			$item_total += (float) $line_item->get_total();
			$custom_fee += self::line_customization_fee( $line_item );
		}

		$created = $order->get_date_created();

		return array(
			$order->get_order_number(),
			$created instanceof WC_DateTime ? $created->date( 'Y-m-d' ) : '',
			wc_get_order_status_name( $order->get_status() ),
			(string) $order->get_payment_method_title(),
			(string) SLK_Money::rupees( $item_total ),
			(string) SLK_Money::rupees( (float) $order->get_shipping_total() ),
			(string) SLK_Money::rupees( (float) $order->get_meta( self::COD_FEE_META_KEY, true ) ),
			(string) SLK_Money::rupees( $custom_fee ),
			(string) SLK_Money::rupees( (float) $order->get_total_refunded() ),
			(string) SLK_Money::rupees( (float) $order->get_total() ),
		);
	}

	private static function export_filename( array $period ): string {
		return sprintf(
			'finances-%s-%s.csv',
			$period['start']->format( 'Ymd' ),
			$period['end']->format( 'Ymd' )
		);
	}
}
