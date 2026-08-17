<?php
/**
 * "In the studio today" — a wp_dashboard_setup widget for manage_woocommerce
 * users only. Four sections, each a short list capped at eight rows with a
 * link to see the rest, and each row linking straight to the thing itself
 * (the order, the exchange's order, the product editor):
 *
 *   1. Order lines due to leave today or already overdue.
 *   2. Cash-on-delivery orders still waiting on the confirmation call.
 *   3. Open exchange requests (slk-exchanges may be deactivated; guarded).
 *   4. Products at or below their low-stock threshold.
 *
 * SECTION 1 AND THE CART CAN NEVER DISAGREE. It reads the same settings out
 * of the same two classes the cart's own delivery promise uses —
 * SLK_Fulfilment::placed_line_days() and SLK_Calendar::add_working_days() —
 * which are the placed-order twins of the pair SLK_Shipments::
 * line_ready_date() calls for the cart page (see class-slk-shipments.php and
 * themes/slk-child/woocommerce/cart/cart.php). Twins, not the same calls,
 * because a placed order asks a question the cart never does.
 * SLK_Fulfilment::ready_days() is prospective: it refuses a piece that
 * cannot be SOLD today and measures the wanted quantity against stock still
 * on the shelf, and an order has already taken that quantity off the shelf
 * while still owing the studio the work — so placed_line_days() takes the
 * same branch retrospectively and never returns null (see
 * class-slk-fulfilment.php). SLK_Calendar::start() likewise always counts
 * from "now", which is right for a live cart but wrong for a placed order,
 * so order_start() below re-derives the same cutoff-hour rule from the
 * order's own creation timestamp instead.
 *
 * One known gap: a placed order's line is walked with no cart-item array (see
 * build_ready_section()), so a customization's own extra making days —
 * which SLK_Fulfilment only learns about through the slk_line_making_days
 * filter keyed on a cart item — are not replayed for an already-placed
 * order. This is the same simplification SLK_Fulfilment_Admin already makes
 * wherever it calls ready_days() off a plain product/quantity pair with no
 * cart line to hand over.
 *
 * "Awaiting the confirmation call" is every processing/on-hold COD order:
 * the COD confirmation lifecycle described in slk-order-flow.php's own file
 * header is not built yet, so there is no separate "confirmed" flag to
 * exclude with. Once that lifecycle exists, filtering it out here is a
 * one-line change to build_confirm_section().
 *
 * REGISTRATION: this class defines init() but does not call it. A sibling
 * package wires SLK_Studio_Today::init() into slk-order-flow.php's own boot
 * sequence, the same way SLK_Points::init() and SLK_Points_Admin::init()
 * are already called there — this file is not that bootstrap and does not
 * touch it.
 *
 * @package slk-order-flow
 */

defined( 'ABSPATH' ) || exit;

final class SLK_Studio_Today {

	/** wp_add_dashboard_widget() id. */
	private const WIDGET_ID = 'slk_studio_today';

	/**
	 * Transient key for the whole widget's data. One shared cache, not one
	 * per user id: the widget itself is only ever shown to a user who holds
	 * manage_woocommerce (register_widget() below never adds it otherwise),
	 * and the four sections read store-wide facts that do not vary from one
	 * such user to the next — so "keyed per user capability" means keyed by
	 * the capability the whole widget is gated on, not duplicated per person.
	 */
	private const CACHE_KEY = 'slk_studio_today_manage_woocommerce';

	private const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/** Rows shown per section before the rest folds into "and N more". */
	private const ROW_CAP = 8;

	/**
	 * Order statuses that still owe something to a shipment: placed and
	 * paid (or COD-placed), not yet dispatched or settled. Deliberately just
	 * these two — wc-pending is not yet a confirmed order (the same
	 * reasoning SLK_Fulfilment_Admin::open_order_statuses() documents for
	 * its own "still owes a place in a shipment" count), and completed /
	 * cancelled / refunded / failed have nothing left for the studio to do.
	 */
	private const OPEN_STATUSES = array( 'wc-processing', 'wc-on-hold' );

	public static function init(): void {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_widget' ) );

		// Whichever of the four sections an order belongs to can change the
		// moment its status does (a line moves out of "ready today", a COD
		// order stops "awaiting confirmation"), so the cache is dropped on
		// the spot rather than left to show a stale picture for up to five
		// minutes after every single order move.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'bust_cache' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Registration                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * wp_dashboard_setup fires for every user who can see wp-admin at all —
	 * core adds no capability check of its own to a dashboard widget, so
	 * this is the one place that actually keeps the widget off a screen
	 * belonging to someone without manage_woocommerce.
	 */
	public static function register_widget(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'In the studio today', 'slk' ),
			array( __CLASS__, 'render_widget' )
		);
	}

	public static function bust_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/* ------------------------------------------------------------------ */
	/* Data: cached walk, five minutes, busted on order status change      */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array{
	 *   ready:array{total:int,rows:array},
	 *   confirm:array{total:int,rows:array},
	 *   exchanges:array{active:bool,total:int,rows:array},
	 *   low_stock:array{total:int,rows:array}
	 * }
	 */
	private static function get_data(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$settings     = SLK_Fulfilment::settings();
		$open_orders  = self::open_orders();

		$data = array(
			'ready'     => self::build_ready_section( $open_orders, $settings ),
			'confirm'   => self::build_confirm_section( $open_orders ),
			'exchanges' => self::build_exchange_section(),
			'low_stock' => self::build_low_stock_section(),
		);

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );

		return $data;
	}

	/**
	 * Every open order, fetched once and shared by build_ready_section() and
	 * build_confirm_section() rather than queried twice for the same status
	 * set.
	 *
	 * @return array<int,WC_Order>
	 */
	private static function open_orders(): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'status' => self::OPEN_STATUSES,
				'type'   => 'shop_order',
				'limit'  => -1,
				'return' => 'objects',
			)
		);

		return array_values( array_filter( (array) $orders, static fn( $order ) => $order instanceof WC_Order ) );
	}

	/**
	 * Section 1: every line, across every open order, whose ready date has
	 * already arrived — see the file header for exactly which functions this
	 * reuses and the one gap (a placed order's own customization extra days)
	 * it knowingly leaves out.
	 *
	 * @param array<int,WC_Order> $open_orders Every order in OPEN_STATUSES, from open_orders().
	 * @param array               $settings    SLK_Fulfilment::settings().
	 */
	private static function build_ready_section( array $open_orders, array $settings ): array {
		$today = ( new DateTimeImmutable( 'now', wp_timezone() ) )->setTime( 0, 0 );
		$rows  = array();

		foreach ( $open_orders as $order ) {
			$start = self::order_start( $order, $settings );

			foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				// A fully (or partially) refunded line has less — or nothing
				// — left to make and ship. get_qty_refunded_for_item()
				// returns zero or a negative count, so this only ever
				// shrinks the quantity being scheduled.
				$qty = (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item( (int) $item_id );
				if ( $qty <= 0 ) {
					continue;
				}

				$product = $item->get_product();
				if ( ! $product instanceof WC_Product ) {
					continue; // Deleted since the order was placed; nothing left to schedule.
				}

				// No $cart_item — see the file header. Not ready_days():
				// this line's quantity is already off the shelf, and a piece
				// that has since sold out is exactly the row the studio most
				// needs to see rather than one to drop.
				$days = SLK_Fulfilment::placed_line_days( $product );

				$ready_date = SLK_Calendar::add_working_days( $start, $days, $settings );
				if ( $ready_date > $today ) {
					continue; // Not due yet.
				}

				$rows[] = array(
					'order_id'   => $order->get_id(),
					'item_name'  => $item->get_name(),
					'qty'        => $qty,
					'days_late'  => (int) $today->diff( $ready_date )->format( '%a' ),
				);
			}
		}

		// Most overdue first — that is what the studio needs to see before
		// anything merely due today.
		usort( $rows, static fn( $a, $b ) => $b['days_late'] <=> $a['days_late'] );

		return array(
			'total' => count( $rows ),
			'rows'  => array_slice( $rows, 0, self::ROW_CAP ),
		);
	}

	/**
	 * The moment counting starts for an ALREADY-PLACED order, mirroring
	 * SLK_Calendar::start()'s own cutoff-hour rule exactly — orders placed
	 * after the cutoff hour count from the following day — but keyed on the
	 * order's own creation timestamp instead of "now". SLK_Calendar::start()
	 * cannot be called for this directly: it hardcodes "now", which is
	 * correct for a live cart and wrong for an order placed hours or days
	 * ago, and this file only ever reads that class's public API rather than
	 * changing it.
	 */
	private static function order_start( WC_Order $order, array $settings ): DateTimeImmutable {
		$created = $order->get_date_created();

		$moment = $created instanceof WC_DateTime
			? ( new DateTimeImmutable( '@' . $created->getTimestamp() ) )->setTimezone( wp_timezone() )
			: new DateTimeImmutable( 'now', wp_timezone() );

		$cutoff = (int) $settings['cutoff_hour'];
		if ( $cutoff > 0 && (int) $moment->format( 'G' ) >= $cutoff ) {
			$moment = $moment->modify( '+1 day' );
		}

		return $moment->setTime( 0, 0 );
	}

	/**
	 * Section 2: processing/on-hold COD orders — see the file header for why
	 * that alone is today's definition of "awaiting the confirmation call".
	 *
	 * @param array<int,WC_Order> $open_orders Every order in OPEN_STATUSES, from open_orders().
	 */
	private static function build_confirm_section( array $open_orders ): array {
		$rows = array();

		foreach ( $open_orders as $order ) {
			if ( 'cod' !== $order->get_payment_method() ) {
				continue;
			}

			$created = $order->get_date_created();

			$rows[] = array(
				'order_id' => $order->get_id(),
				'customer' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'total'    => (float) $order->get_total(),
				'status'   => $order->get_status(),
				'placed'   => $created instanceof WC_DateTime ? $created->getTimestamp() : 0,
			);
		}

		// Oldest first: the order that has waited longest for its call.
		usort( $rows, static fn( $a, $b ) => $a['placed'] <=> $b['placed'] );

		return array(
			'total' => count( $rows ),
			'rows'  => array_slice( $rows, 0, self::ROW_CAP ),
		);
	}

	/**
	 * Section 3: open exchange requests. Guarded with class_exists() because
	 * slk-exchanges is a separate plugin and may be deactivated — this
	 * section then reports itself inactive rather than pretending there is
	 * nothing open.
	 */
	private static function build_exchange_section(): array {
		if ( ! class_exists( 'SLK_Exchange' ) ) {
			return array(
				'active' => false,
				'total'  => 0,
				'rows'   => array(),
			);
		}

		$total = (int) SLK_Exchange::open_count();

		$ids = get_posts(
			array(
				'post_type'      => SLK_Exchange::POST_TYPE,
				'post_status'    => SLK_Exchange::open_states(),
				'posts_per_page' => self::ROW_CAP,
				'orderby'        => 'date',
				'order'          => 'ASC', // Oldest waiting longest, first.
				'fields'         => 'ids',
			)
		);

		$rows = array();

		foreach ( $ids as $id ) {
			$record = SLK_Exchange::get( (int) $id );
			if ( null === $record ) {
				continue;
			}

			$order = wc_get_order( $record['order_id'] );
			$line  = $order instanceof WC_Order ? $order->get_item( $record['item_id'] ) : false;

			$rows[] = array(
				'order_id' => $record['order_id'],
				'piece'    => $line ? $line->get_name() : __( 'Item no longer on the order', 'slk' ),
				'reason'   => SLK_Exchange::reasons()[ $record['reason'] ] ?? $record['reason'],
				'state'    => SLK_Exchange::states()[ $record['state'] ] ?? $record['state'],
			);
		}

		return array(
			'active' => true,
			'total'  => $total,
			'rows'   => $rows,
		);
	}

	/**
	 * Section 4: products (and, individually, variations) at or below their
	 * low-stock threshold.
	 *
	 * wc_get_low_stock_amount() is core WooCommerce (wc-product-functions.php,
	 * since 3.5): the product's own _low_stock_amount meta first, falling
	 * back to the store-wide woocommerce_notify_low_stock_amount option —
	 * exactly the fallback the plan calls for, already built, so it is used
	 * rather than re-implemented here.
	 */
	private static function build_low_stock_section(): array {
		if ( ! function_exists( 'wc_get_low_stock_amount' ) ) {
			return array(
				'total' => 0,
				'rows'  => array(),
			);
		}

		$ids = get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small store catalogue, and the result is cached for five minutes regardless.
					array(
						'key'   => '_manage_stock',
						'value' => 'yes',
					),
				),
			)
		);

		$rows = array();

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$stock = $product->get_stock_quantity();
			if ( null === $stock ) {
				continue; // Manages stock but has no quantity recorded; nothing to compare.
			}

			$threshold = wc_get_low_stock_amount( $product );
			if ( '' === $threshold || null === $threshold ) {
				continue; // No threshold configured anywhere for this product.
			}

			$threshold = (int) $threshold;
			if ( (int) $stock > $threshold ) {
				continue;
			}

			$rows[] = array(
				'product_id' => $product->get_id(),
				'edit_id'    => $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(),
				'name'       => self::product_display_name( $product ),
				'stock'      => (int) $stock,
				'threshold'  => $threshold,
			);
		}

		// Lowest stock first — the most urgent row leads the list.
		usort( $rows, static fn( $a, $b ) => $a['stock'] <=> $b['stock'] );

		return array(
			'total' => count( $rows ),
			'rows'  => array_slice( $rows, 0, self::ROW_CAP ),
		);
	}

	/**
	 * A variation's own get_name() is not reliably the "Parent — Size,
	 * Colour" form staff recognise, so it is built explicitly here from the
	 * parent's title plus wc_get_formatted_variation()'s flat attribute
	 * summary (core, same function the order screen and emails use for a
	 * variation line).
	 */
	private static function product_display_name( WC_Product $product ): string {
		if ( ! $product->is_type( 'variation' ) ) {
			return $product->get_name();
		}

		$parent = wc_get_product( $product->get_parent_id() );
		$name   = $parent instanceof WC_Product ? $parent->get_name() : $product->get_name();

		$attributes = function_exists( 'wc_get_formatted_variation' )
			? wc_get_formatted_variation( $product, true, false, false )
			: '';

		return '' !== $attributes ? $name . ' — ' . $attributes : $name;
	}

	/* ------------------------------------------------------------------ */
	/* Render                                                              */
	/* ------------------------------------------------------------------ */

	public static function render_widget(): void {
		// Belt and suspenders: register_widget() already keeps this callback
		// from ever being wired up for anyone without the capability, but the
		// callback itself is a public hook target core can call directly.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$data = self::get_data();

		echo '<div class="slk-studio-today">';

		self::render_ready_section( $data['ready'] );
		self::render_confirm_section( $data['confirm'] );
		self::render_exchange_section( $data['exchanges'] );
		self::render_low_stock_section( $data['low_stock'] );

		echo '</div>';

		self::render_styles();
	}

	private static function render_ready_section( array $section ): void {
		self::render_section(
			__( 'Ready today or overdue', 'slk' ),
			__( 'Nothing is due or overdue right now.', 'slk' ),
			$section['rows'],
			static function ( array $row ): void {
				$order = wc_get_order( $row['order_id'] );
				if ( ! $order instanceof WC_Order ) {
					return;
				}

				$meta = 0 === $row['days_late']
					? __( 'Due today', 'slk' )
					: sprintf(
						/* translators: %d: number of days overdue. */
						_n( 'Overdue by %d day', 'Overdue by %d days', $row['days_late'], 'slk' ),
						$row['days_late']
					);

				printf(
					'<li><a href="%1$s">%2$s</a><span class="slk-studio-today__meta">%3$s</span></li>',
					esc_url( $order->get_edit_order_url() ),
					esc_html(
						sprintf(
							/* translators: 1: order number, 2: line item name, 3: quantity. */
							__( 'Order #%1$s — %2$s ×%3$d', 'slk' ),
							$order->get_order_number(),
							$row['item_name'],
							$row['qty']
						)
					),
					esc_html( $meta )
				);
			},
			self::orders_list_url( 'wc-processing' ),
			$section['total']
		);
	}

	private static function render_confirm_section( array $section ): void {
		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();

		self::render_section(
			__( 'Awaiting the confirmation call', 'slk' ),
			__( 'No cash-on-delivery orders are waiting on a call right now.', 'slk' ),
			$section['rows'],
			static function ( array $row ) use ( $statuses ): void {
				$order = wc_get_order( $row['order_id'] );
				if ( ! $order instanceof WC_Order ) {
					return;
				}

				$status_label = $statuses[ 'wc-' . $row['status'] ] ?? $row['status'];

				printf(
					'<li><a href="%1$s">%2$s</a><span class="slk-studio-today__meta">%3$s · %4$s</span></li>',
					esc_url( $order->get_edit_order_url() ),
					esc_html(
						sprintf(
							/* translators: 1: order number, 2: customer name. */
							__( 'Order #%1$s — %2$s', 'slk' ),
							$order->get_order_number(),
							'' !== $row['customer'] ? $row['customer'] : __( 'Guest', 'slk' )
						)
					),
					wp_kses_post( wc_price( $row['total'] ) ),
					esc_html( $status_label )
				); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every placeholder above is individually escaped or wc_price()/wp_kses_post()'d before this call.
			},
			self::orders_list_url( 'wc-processing' ),
			$section['total']
		);
	}

	private static function render_exchange_section( array $section ): void {
		if ( ! $section['active'] ) {
			echo '<div class="slk-studio-today__section">';
			echo '<h4>' . esc_html__( 'Open exchanges', 'slk' ) . '</h4>';
			echo '<p class="slk-studio-today__empty">' . esc_html__( 'The exchanges module is not active.', 'slk' ) . '</p>';
			echo '</div>';
			return;
		}

		self::render_section(
			__( 'Open exchanges', 'slk' ),
			__( 'No exchange requests are open.', 'slk' ),
			$section['rows'],
			static function ( array $row ): void {
				$order = wc_get_order( $row['order_id'] );
				$url   = $order instanceof WC_Order ? $order->get_edit_order_url() : self::exchanges_board_url();

				printf(
					'<li><a href="%1$s">%2$s</a><span class="slk-studio-today__meta">%3$s</span></li>',
					esc_url( $url ),
					esc_html( $row['piece'] . ' — ' . $row['reason'] ),
					esc_html( $row['state'] )
				);
			},
			self::exchanges_board_url(),
			$section['total']
		);
	}

	private static function render_low_stock_section( array $section ): void {
		self::render_section(
			__( 'Low stock', 'slk' ),
			__( 'Nothing is at or below its low-stock threshold.', 'slk' ),
			$section['rows'],
			static function ( array $row ): void {
				$url = get_edit_post_link( $row['edit_id'], 'raw' );
				if ( empty( $url ) ) {
					$url = admin_url( 'edit.php?post_type=product' );
				}

				printf(
					'<li><a href="%1$s">%2$s</a><span class="slk-studio-today__meta">%3$s</span></li>',
					esc_url( $url ),
					esc_html( $row['name'] ),
					esc_html(
						sprintf(
							/* translators: 1: units left in stock, 2: the configured low-stock threshold. */
							__( '%1$d left · threshold %2$d', 'slk' ),
							$row['stock'],
							$row['threshold']
						)
					)
				);
			},
			self::low_stock_report_url(),
			$section['total']
		);
	}

	/**
	 * Shared shell for one section: heading, an empty-state sentence when
	 * there is nothing to show (never a bare empty box), a capped list of
	 * rows, and — when more rows exist than were shown — an "and N more"
	 * line into the filtered screen the rows themselves came from.
	 *
	 * @param string   $title      Section heading.
	 * @param string   $empty_text Sentence shown when $rows is empty.
	 * @param array    $rows       Already capped to self::ROW_CAP.
	 * @param callable $row_renderer function( array $row ): void, echoes one <li>.
	 * @param string   $more_url   Where "and N more" points.
	 * @param int      $total      Total matching rows before the cap.
	 */
	private static function render_section( string $title, string $empty_text, array $rows, callable $row_renderer, string $more_url, int $total ): void {
		echo '<div class="slk-studio-today__section">';
		echo '<h4>' . esc_html( $title ) . '</h4>';

		if ( empty( $rows ) ) {
			echo '<p class="slk-studio-today__empty">' . esc_html( $empty_text ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<ul>';
		foreach ( $rows as $row ) {
			$row_renderer( $row );
		}
		echo '</ul>';

		$remaining = $total - count( $rows );
		if ( $remaining > 0 && '' !== $more_url ) {
			printf(
				'<p class="slk-studio-today__more"><a href="%1$s">%2$s</a></p>',
				esc_url( $more_url ),
				esc_html(
					sprintf(
						/* translators: %d: number of further rows not shown here. */
						_n( 'and %d more', 'and %d more', $remaining, 'slk' ),
						$remaining
					)
				)
			);
		}

		echo '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Link targets                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * WooCommerce's own order list, filtered to one status. HPOS-aware: the
	 * screen this points at moves from edit.php?post_type=shop_order to
	 * admin.php?page=wc-orders once high-performance order storage is the
	 * active data store, and both still read the status filter off the same
	 * post_status query arg.
	 */
	private static function orders_list_url( string $status ): string {
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		$args = array( 'post_status' => $status );

		if ( $hpos ) {
			$args['page'] = 'wc-orders';
			return add_query_arg( $args, admin_url( 'admin.php' ) );
		}

		$args['post_type'] = 'shop_order';
		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	/**
	 * The exchanges board. Written as a literal admin page slug rather than
	 * a reference to SLK_Exchange_Admin::PAGE_SLUG: that class is only
	 * required when is_admin() (see slk-exchanges.php), which is always true
	 * wherever this dashboard widget renders, but the slug itself is the
	 * stable, documented part of that class's public surface — reaching for
	 * the literal avoids a hard compile-time coupling to a sibling plugin's
	 * class for one string.
	 */
	private static function exchanges_board_url(): string {
		return add_query_arg( 'page', 'slk-exchanges', admin_url( 'admin.php' ) );
	}

	/**
	 * WooCommerce's Analytics -> Stock report, filtered to the low-stock
	 * tab — the built-in screen for "which products need reordering", so
	 * "and N more" lands somewhere that already answers the question rather
	 * than a plain, unfiltered product list.
	 */
	private static function low_stock_report_url(): string {
		return add_query_arg(
			array(
				'page' => 'wc-admin',
				'path' => '/analytics/stock',
				'type' => 'lowstock',
			),
			admin_url( 'admin.php' )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Styling                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * A handful of rules for the widget's four sections, printed inline
	 * rather than through a separate enqueued stylesheet — the same choice
	 * SLK_Exchange_Admin::print_admin_styles() makes for a similarly small,
	 * screen-scoped set of rules, and this one is scoped to the dashboard
	 * widget's own wrapper class so it cannot leak onto anything else on
	 * the Dashboard screen.
	 */
	private static function render_styles(): void {
		?>
		<style>
			.slk-studio-today__section { margin-bottom: 16px; }
			.slk-studio-today__section:last-child { margin-bottom: 0; }
			.slk-studio-today__section h4 { margin: 0 0 6px; }
			.slk-studio-today__section ul { margin: 0; padding: 0; list-style: none; }
			.slk-studio-today__section li { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 4px 12px; padding: 4px 0; border-bottom: 1px solid #f0f0f1; }
			.slk-studio-today__section li:last-child { border-bottom: 0; }
			.slk-studio-today__meta { color: #646970; font-size: 12px; white-space: nowrap; }
			.slk-studio-today__empty { color: #646970; margin: 0; }
			.slk-studio-today__more { margin: 6px 0 0; }
		</style>
		<?php
	}
}
