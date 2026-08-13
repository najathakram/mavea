<?php
/**
 * Loyalty points: earning, redemption credit, balance and ledger.
 *
 * Earning: 1 point per rupee of an order's item total (delivery excluded),
 * granted once an order reaches a paid or completed state, reversed if that
 * order is later refunded, cancelled or returned. Guests earn nothing.
 *
 * Redemption: coupons are disabled store-wide (see
 * themes/slk-child/inc/cart.php), so credit is applied as a negative
 * WC_Cart fee, never a coupon, and is worded as the shopper spending their
 * own credit, never as a discount or an offer. A signed-in shopper's whole
 * redeemable blocks are applied automatically, clamped to the cart's item
 * total, and the points actually used are deducted from their balance the
 * moment the order is placed, not left to happen again on every visit. Those
 * spent points are returned in full if the order is later cancelled or
 * refunded in full, so an order that never completes costs nothing, and are
 * taken again if that order is put back to a paid state, so the credit an
 * order carries is always paid for exactly once.
 *
 * Balance and ledger live in user meta so a balance can always be explained,
 * and are shown on the My Account dashboard without touching any theme file.
 *
 * @package slk-order-flow
 */

defined( 'ABSPATH' ) || exit;

final class SLK_Points {

	public const OPTION = 'slk_points_settings';

	public const META_BALANCE = '_slk_points_balance';
	public const META_LEDGER  = '_slk_points_ledger';

	/** Order meta. Idempotency guard for earning: '', 'yes' or 'reversed'. */
	public const META_AWARDED        = '_slk_points_awarded';
	public const META_AWARDED_POINTS = '_slk_points_awarded_points';

	/** Order meta. Points of the award taken back so far, so part refunds add up. */
	public const META_REVERSED_POINTS = '_slk_points_reversed_points';

	/** Order meta. Idempotency guard for spending: '', 'yes' or 'returned'. */
	public const META_REDEEMED        = '_slk_points_redeemed';
	public const META_REDEEMED_POINTS = '_slk_points_redeemed_points';

	public static function init(): void {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'handle_status_change' ), 10, 4 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'handle_refund' ), 10, 2 );

		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'apply_credit_fee' ) );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'handle_order_processed' ), 10, 3 );

		add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'render_account_panel' ) );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'render_guest_notice' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_account_styles' ), 40 );
	}

	public static function defaults(): array {
		return array(
			'rate'         => 1,    // Points earned per rupee of item total.
			'block_points' => 5000, // Points needed for one redemption block.
			'block_value'  => 50,   // Rupees of credit one redemption block is worth.
		);
	}

	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/* ------------------------------------------------------------------ */
	/* Earning                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * @param int      $order_id   Order id.
	 * @param string   $old_status Bare status slug before the change.
	 * @param string   $new_status Bare status slug after the change.
	 * @param WC_Order $order      Order.
	 */
	public static function handle_status_change( $order_id, $old_status, $new_status, $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( in_array( $new_status, wc_get_is_paid_statuses(), true ) ) {
			self::award( $order );
			return;
		}

		if ( self::is_reversal_status( (string) $new_status ) ) {
			// The whole order has fallen away. A cancellation carries no refund
			// record to measure, so this path always reverses in full.
			self::reverse( $order, true );
		}
	}

	/**
	 * A partial refund can leave an order's status unchanged (a part refund
	 * on a still-processing order, for example), so the status-change hook
	 * above never fires for it. This is the only reliable signal for that
	 * case; a full refund fires both, and reverse() is idempotent either way.
	 *
	 * @param int $order_id  Order id.
	 * @param int $refund_id Refund id.
	 */
	public static function handle_refund( $order_id, $refund_id ) {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			self::reverse( $order, false );
		}
	}

	/**
	 * A status slug that should give back an order's points. Matched by name
	 * as well as the two built-in slugs, so a courier-return status the
	 * order-flow lifecycle has not registered yet still reverses correctly
	 * once it exists, whatever exact slug it lands under.
	 */
	private static function is_reversal_status( string $status ): bool {
		if ( in_array( $status, array( 'cancelled', 'refunded' ), true ) ) {
			return true;
		}

		return 'rto' === $status || false !== strpos( $status, 'return' );
	}

	private static function award( WC_Order $order ): void {
		// Runs before the guard below, and outside it, so an order that is put
		// back to a paid state pays for its credit again whether or not it
		// ever earned anything.
		self::recommit_returned_spend( $order );

		$flag = $order->get_meta( self::META_AWARDED );
		if ( 'yes' === $flag || 'reversed' === $flag ) {
			return; // Already decided, one way or the other.
		}

		$user_id = (int) $order->get_customer_id();

		if ( $user_id <= 0 ) {
			// Guests earn nothing. The flag is still stamped so a guest order
			// is never re-evaluated on a later status change.
			$order->update_meta_data( self::META_AWARDED, 'yes' );
			$order->update_meta_data( self::META_AWARDED_POINTS, '0' );
			$order->save();
			return;
		}

		$points = self::points_for_order( $order );

		if ( $points > 0 ) {
			self::adjust_balance( $user_id, $points, $order->get_id(), __( 'Points earned', 'slk' ) );
		}

		$order->update_meta_data( self::META_AWARDED, 'yes' );
		$order->update_meta_data( self::META_AWARDED_POINTS, (string) $points );
		$order->save();
	}

	/**
	 * Takes the spend again on an order that has come back to life.
	 *
	 * reverse() hands the spent points back and marks the spend 'returned',
	 * but a cancelled order can be put back to a paid state by an admin, and
	 * it still carries the points credit line it was placed with. Without this
	 * the shopper would keep both the points and the credit those points
	 * bought. The spend therefore follows the order in both directions, as
	 * many times as it turns around, and the flag stays in step with it.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function recommit_returned_spend( WC_Order $order ): void {
		if ( 'returned' !== $order->get_meta( self::META_REDEEMED ) ) {
			return;
		}

		$user_id = (int) $order->get_customer_id();
		$spent   = max( 0, (int) $order->get_meta( self::META_REDEEMED_POINTS ) );

		if ( $user_id <= 0 || $spent <= 0 ) {
			$order->update_meta_data( self::META_REDEEMED, 'yes' );
			$order->save();
			return;
		}

		/*
		 * Take the whole spend back or none of it. The points went back to the
		 * shopper when this order was cancelled, and they are free to have
		 * spent them on another order since. If they have, the balance can no
		 * longer fund this order's credit, and a partial deduction would leave
		 * the store paying part of a credit out of nothing while the ledger
		 * showed points that were never there. In that case the credit line
		 * comes off the reinstated order instead, so one block of points can
		 * never fund two credits.
		 */
		if ( self::balance( $user_id ) >= $spent ) {
			self::adjust_balance( $user_id, -$spent, $order->get_id(), __( 'Points redeemed', 'slk' ) );
			$order->update_meta_data( self::META_REDEEMED, 'yes' );
			$order->save();
			return;
		}

		$removed = self::strip_credit_fee( $order );

		$order->update_meta_data( self::META_REDEEMED, 'unfunded' );
		$order->add_order_note(
			sprintf(
				/* translators: %s: number of points. */
				__( 'The %s points this order used were returned when it was cancelled and have since been spent elsewhere. The points credit has been removed and the total recalculated.', 'slk' ),
				number_format_i18n( $spent )
			)
		);
		$order->save();

		if ( $removed ) {
			$order->calculate_totals( false );
		}
	}

	/**
	 * Remove the points credit line from an order that can no longer fund it.
	 *
	 * @return bool Whether a credit line was found and removed.
	 */
	private static function strip_credit_fee( WC_Order $order ): bool {
		$label   = __( 'Points credit', 'slk' );
		$removed = false;

		foreach ( $order->get_items( 'fee' ) as $item_id => $item ) {
			if ( $item->get_name() === $label ) {
				$order->remove_item( $item_id );
				$removed = true;
			}
		}

		return $removed;
	}

	/**
	 * Gives back what an order took and takes back what it gave.
	 *
	 * The points spent on the order are returned whenever the whole order
	 * falls away, so a shopper whose order is cancelled or refunded in full
	 * never loses the credit they put into it. That matters most for cash on
	 * delivery, where the spend leaves the balance at checkout but the order
	 * may never be confirmed. A part refund leaves the spend alone, because
	 * the shopper keeps the goods that credit paid for.
	 *
	 * The points the order earned are taken back in proportion to how much of
	 * its item total has been refunded, so refunding one damaged piece costs
	 * only that piece's points instead of the whole order's.
	 *
	 * Each half is guarded by its own meta, so both hooks that reach here can
	 * fire, more than once, without doubling up.
	 *
	 * @param WC_Order $order Order.
	 * @param bool     $full  True when the whole order has fallen away.
	 */
	private static function reverse( WC_Order $order, bool $full ): void {
		$user_id = (int) $order->get_customer_id();
		$share   = $full ? 1.0 : self::refunded_share( $order );
		$changed = false;

		if ( $share >= 1.0 && 'yes' === $order->get_meta( self::META_REDEEMED ) ) {
			$spent = (int) $order->get_meta( self::META_REDEEMED_POINTS );

			if ( $spent > 0 ) {
				self::adjust_balance( $user_id, $spent, $order->get_id(), __( 'Points returned', 'slk' ) );
			}

			$order->update_meta_data( self::META_REDEEMED, 'returned' );
			$changed = true;
		}

		if ( 'yes' === $order->get_meta( self::META_AWARDED ) ) {
			$awarded = max( 0, (int) $order->get_meta( self::META_AWARDED_POINTS ) );
			$taken   = max( 0, (int) $order->get_meta( self::META_REVERSED_POINTS ) );
			$owed    = min( $awarded, (int) floor( $awarded * $share ) );

			if ( $owed > $taken ) {
				self::adjust_balance( $user_id, -( $owed - $taken ), $order->get_id(), __( 'Points reversed', 'slk' ) );
				$order->update_meta_data( self::META_REVERSED_POINTS, (string) $owed );
				$taken   = $owed;
				$changed = true;
			}

			if ( $taken >= $awarded ) {
				// Nothing of the award is left, so the order is settled and a
				// later status change must never award it again.
				$order->update_meta_data( self::META_AWARDED, 'reversed' );
				$changed = true;
			}
		}

		if ( $changed ) {
			$order->save();
		}
	}

	/**
	 * How much of an order's item total has been refunded, between 0 and 1.
	 * Only line items are counted, the same basis points_for_order() earns on,
	 * so refunding delivery alone takes no points back. A rounding wobble on
	 * the last rupee must not leave a full refund looking partial, so a share
	 * that close to whole counts as whole.
	 */
	private static function refunded_share( WC_Order $order ): float {
		$total = self::order_item_total( $order );

		if ( $total <= 0 ) {
			return 1.0;
		}

		$refunded = 0.0;

		foreach ( array_keys( $order->get_items( 'line_item' ) ) as $item_id ) {
			$refunded += (float) $order->get_total_refunded_for_item( (int) $item_id );
		}

		$share = max( 0.0, $refunded ) / $total;

		return $share >= 0.999 ? 1.0 : $share;
	}

	/**
	 * Points earned for an order: the rate applied to the item total,
	 * delivery excluded. Summing 'line_item' totals only already excludes
	 * shipping and every fee line (the cash-on-delivery handling charge, a
	 * points credit fee) without any extra subtraction.
	 */
	public static function points_for_order( WC_Order $order ): int {
		$rate = max( 0, (float) self::settings()['rate'] );

		return (int) floor( self::order_item_total( $order ) * $rate );
	}

	private static function order_item_total( WC_Order $order ): float {
		$total = 0.0;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$total += (float) $item->get_total();
		}

		return max( 0.0, SLK_Money::round( $total ) );
	}

	/* ------------------------------------------------------------------ */
	/* Balance + ledger (user meta)                                        */
	/* ------------------------------------------------------------------ */

	public static function balance( int $user_id ): int {
		return max( 0, (int) get_user_meta( $user_id, self::META_BALANCE, true ) );
	}

	/**
	 * @return array<int,array{order:int,delta:int,reason:string,date:string}>
	 */
	public static function ledger( int $user_id ): array {
		$ledger = get_user_meta( $user_id, self::META_LEDGER, true );

		return is_array( $ledger ) ? $ledger : array();
	}

	/**
	 * Append a ledger line and move the balance by $delta. The balance is
	 * never allowed below zero: a reversal larger than what remains (a
	 * shopper who has since spent points from an order that is only now
	 * refunded) clamps at zero rather than going negative.
	 */
	private static function adjust_balance( int $user_id, int $delta, int $order_id, string $reason ): int {
		if ( 0 === $delta || $user_id <= 0 ) {
			return 0;
		}

		$before = self::balance( $user_id );
		$after  = max( 0, $before + $delta );

		/*
		 * The ledger records what actually moved, not what was asked for. When
		 * the zero clamp bites, writing the requested delta would leave a
		 * ledger that cannot be added up to the balance it is supposed to
		 * explain.
		 */
		$applied = $after - $before;

		if ( 0 === $applied ) {
			return 0;
		}

		update_user_meta( $user_id, self::META_BALANCE, $after );

		$ledger   = self::ledger( $user_id );
		$ledger[] = array(
			'order'  => $order_id,
			'delta'  => $applied,
			'reason' => $reason,
			'date'   => gmdate( 'c' ),
		);
		update_user_meta( $user_id, self::META_LEDGER, $ledger );

		return $applied;
	}

	/* ------------------------------------------------------------------ */
	/* Redemption: negative fee, clamped, whole blocks only                */
	/* ------------------------------------------------------------------ */

	/**
	 * The points a balance can redeem right now and the rupee credit that is
	 * worth, scaled back to the largest whole block that still fits inside
	 * $ceiling. Redemption never exceeds the ceiling and never spends more
	 * points than the credit it actually produced.
	 *
	 * @return array{points:int,credit:float}
	 */
	private static function redemption_for( int $balance, float $ceiling ): array {
		$settings = self::settings();
		$block    = max( 1, (int) $settings['block_points'] );
		$value    = max( 0.0, (float) $settings['block_value'] );

		$points = max( 0, $balance - ( $balance % $block ) );

		if ( $points <= 0 || $ceiling <= 0 || $value <= 0 ) {
			return array(
				'points' => 0,
				'credit' => 0.0,
			);
		}

		$credit = ( $points / $block ) * $value;

		if ( $credit > $ceiling ) {
			$max_blocks = (int) floor( $ceiling / $value );
			$points     = min( $points, $max_blocks * $block );
			$credit     = ( $points / $block ) * $value;
		}

		return array(
			'points' => max( 0, $points ),
			'credit' => SLK_Money::rupees( max( 0.0, $credit ) ),
		);
	}

	/**
	 * Rupees of credit a signed-in shopper's balance is worth right now,
	 * clamped to the cart's item total so credit can never reach or exceed
	 * the order total on its own, and delivery is never paid with points.
	 */
	public static function pending_credit(): float {
		if ( ! is_user_logged_in() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0.0; // Guests hold no points to spend.
		}

		$ceiling = max( 0.0, (float) WC()->cart->get_cart_contents_total() );

		return self::redemption_for( self::balance( get_current_user_id() ), $ceiling )['credit'];
	}

	/**
	 * Coupons are disabled store-wide, so credit is applied as a negative fee.
	 * Fees are recalculated on every cart change, so this hook is the only
	 * place the credit amount is decided.
	 *
	 * @param WC_Cart $cart Cart being totalled.
	 */
	public static function apply_credit_fee( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		$credit = self::pending_credit(); // rupees, already clamped.

		if ( $credit > 0 ) {
			$cart->add_fee( __( 'Points credit', 'slk' ), -$credit, false );
		}
	}

	/**
	 * Commits the redemption the moment an order is placed: the points that
	 * produced the credit already reflected in the order's totals are
	 * deducted from the shopper's balance here, so a cash-on-delivery order
	 * that sits unpaid for days cannot be used to spend the same points
	 * again on a second order in the meantime.
	 *
	 * @param int      $order_id    Order id.
	 * @param array    $posted_data Posted checkout data.
	 * @param WC_Order $order       Order.
	 */
	public static function handle_order_processed( $order_id, $posted_data, $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( '' !== (string) $order->get_meta( self::META_REDEEMED ) ) {
			return; // Idempotent: never deduct the same order's spend twice.
		}

		$user_id = (int) $order->get_customer_id();

		if ( $user_id > 0 ) {
			$ceiling    = self::order_item_total( $order );
			$redemption = self::redemption_for( self::balance( $user_id ), $ceiling );

			if ( $redemption['points'] > 0 ) {
				self::adjust_balance( $user_id, -$redemption['points'], $order->get_id(), __( 'Points redeemed', 'slk' ) );
				$order->update_meta_data( self::META_REDEEMED_POINTS, (string) $redemption['points'] );
			}
		}

		$order->update_meta_data( self::META_REDEEMED, 'yes' );
		$order->save();
	}

	/* ------------------------------------------------------------------ */
	/* My Account: balance + ledger, and the guest reason-to-join line.    */
	/*                                                                      */
	/* Rendered through woocommerce_account_dashboard (the hook the        */
	/* theme's own dashboard.php calls at the bottom, see                  */
	/* themes/slk-child/woocommerce/myaccount/dashboard.php) and           */
	/* woocommerce_before_checkout_form (the hook core itself uses for the */
	/* checkout login prompt), so neither file needs to change.            */
	/* ------------------------------------------------------------------ */

	public static function render_account_panel(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id  = get_current_user_id();
		$balance  = self::balance( $user_id );
		$ledger   = array_reverse( self::ledger( $user_id ) );
		$settings = self::settings();

		echo '<div class="slk-panel slk-acct-points">';

		printf( '<div class="slk-eyebrow">%s</div>', esc_html__( 'Your points', 'slk' ) );

		printf(
			'<p class="slk-acct-points__balance">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: points balance, formatted with thousands separators */
					__( '%s points', 'slk' ),
					number_format_i18n( $balance )
				)
			)
		);

		/* translators: 1: points per redemption block, 2: formatted rupee value of a block, HTML */
		$rule_format = esc_html__( 'Every %1$s points are worth %2$s of credit towards a later order. Points never expire.', 'slk' );
		printf(
			'<p class="slk-acct-points__rule">%s</p>',
			sprintf(
				$rule_format, // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- already escaped and translated above.
				esc_html( number_format_i18n( (int) $settings['block_points'] ) ),
				wp_kses_post( wc_price( SLK_Money::rupees( $settings['block_value'] ) ) )
			)
		);

		if ( empty( $ledger ) ) {
			printf( '<p class="slk-acct-points__empty">%s</p>', esc_html__( 'Points from your paid orders will show here.', 'slk' ) );
		} else {
			echo '<ul class="slk-acct-points__ledger">';
			foreach ( array_slice( $ledger, 0, 20 ) as $line ) {
				$delta = (int) ( $line['delta'] ?? 0 );
				$order = (int) ( $line['order'] ?? 0 );
				$date  = isset( $line['date'] ) ? mysql2date( get_option( 'date_format' ), $line['date'] ) : '';
				$label = $order > 0
					/* translators: %d: order number */
					? sprintf( __( 'Order #%d', 'slk' ), $order )
					: __( 'Adjustment', 'slk' );

				printf(
					'<li><span>%1$s · %2$s</span><span>%3$s%4$s</span></li>',
					esc_html( $date ),
					esc_html( $label ),
					esc_html( $delta > 0 ? '+' : '' ),
					esc_html( number_format_i18n( $delta ) )
				);
			}
			echo '</ul>';
		}

		echo '</div>';
	}

	public static function render_guest_notice(): void {
		if ( is_user_logged_in() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		wc_print_notice(
			esc_html__( 'Make an account and every paid order earns points that turn into real credit later and never expire.', 'slk' ),
			'notice'
		);
	}

	/**
	 * Small, scoped CSS for the account panel above. Tokens only, matching
	 * the rest of the account area's styling in themes/slk-child/inc/account.php,
	 * which this file cannot edit (out of this package's file list) so the
	 * panel carries its own inline styling instead.
	 */
	public static function enqueue_account_styles(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		$css = <<<'CSS'
.slk-acct-points{margin-bottom:var(--slk-space-4)}
.slk-acct-points__balance{font-family:var(--slk-font-display);font-weight:300;font-size:var(--slk-display-s);margin:4px 0 8px}
.slk-acct-points__rule{margin:0 0 var(--slk-space-4);font:400 12px/1.55 var(--slk-font-ui);color:var(--slk-color-faint)}
.slk-acct-points__empty{margin:0;font:400 12.5px/1.6 var(--slk-font-ui);color:var(--slk-color-faint)}
.slk-acct-points__ledger{list-style:none;margin:0;padding:0}
.slk-acct-points__ledger li{display:flex;align-items:center;justify-content:space-between;gap:var(--slk-space-3);min-height:40px;padding:6px 4px;border-bottom:1px solid var(--slk-hairline);font:400 12.5px/1.4 var(--slk-font-ui)}
.slk-acct-points__ledger li:last-child{border-bottom:0}
CSS;

		wp_add_inline_style( 'slk-child', $css );
	}
}
