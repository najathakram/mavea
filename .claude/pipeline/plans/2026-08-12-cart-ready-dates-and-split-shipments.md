# Plan: cart ready dates and split shipments

> Authored 2026-08-12. Status: APPROVED
> This file is the ONLY context the implementation and review agents receive.
> It must stand alone.

## Objective

Every cart line tells the shopper two dates: when that piece is **ready**, and
when it **ships**. By default the whole order travels together, so every line
ships on the latest ready date. A shopper in a hurry can switch to "send each
piece as soon as it is ready", which splits the order into shipments grouped by
ready date, in the manner of Amazon's "ship as available".

Delivery is charged per shipment. A shipment at or above the free-delivery
threshold is free, so splitting can cost more, and the shopper can remove that
cost by adding items to the same shipment.

Stock decides everything automatically. A piece on the shelf is ready after the
configured dispatch time. A piece not on the shelf that we still make is ready
after that product's making time. A piece we no longer make, with no stock
left, cannot be bought at all.

## Constraints & conventions

Stack: WordPress + WooCommerce 11.0.1 with HPOS enabled, PHP 8.3. Theme is
`slk-child` (child of Blocksy). Repo root contains `local/` which holds
`plugins/slk-checkout` and `themes/slk-child`, bind-mounted into the container.

Hard rules, all of which have bitten this codebase before:

- **Classic shortcode checkout only.** Converting cart or checkout to
  WooCommerce blocks is a documented forbidden action. Do not touch the
  cart/checkout page content.
- **Money.** Sri Lankan rupees, 0 decimals. Never write a currency symbol in
  code; always render through `wc_price()`. `SLK_Money::rupees()` normalises a
  settings value into a float.
- **Copy rules, enforced in review.** No em dashes and no en dashes anywhere a
  shopper can read, including alt text and placeholders. Use a full stop, a
  comma, or a colon. No sentence fragments: every customer-facing sentence has
  a subject and a verb. Plain words, short sentences, written for readers whose
  first language is not English. Never use the phrase "made to order" or
  "backorder" in customer-facing text. The shopper is told a date, not how the
  workshop is organised.
- **No sale theatre.** No discounts, no strikethrough pricing.
- **CSS** uses only the `--slk-*` design tokens already defined in
  `themes/slk-child/style.css`. No raw hex. One breakpoint, `min-width:1000px`.
- **Text domain** is `slk` throughout.
- Do not modify: `themes/slk-child/inc/moments.php`,
  `themes/slk-child/inc/pdp.php`, `themes/slk-child/inc/select.php`,
  `themes/slk-child/assets/js/select.js`.

Existing API these packages build on, all in `local/plugins/slk-checkout`:

```php
SLK_Districts::COUNTRY            // 'LK'
SLK_Districts::tier( $district )  // 'metro' | 'regional' | 'island'
SLK_Shipping::FREE_OVER           // 15000
SLK_Shipping::FEE_METRO / FEE_REGIONAL / FEE_ISLAND   // 350 / 400 / 450
SLK_Shipping::fee_for_district( $district ) : float
SLK_Money::rupees( $value ) : float
```

`local/plugins/slk-checkout/slk-checkout.php` loads classes with explicit
`require_once` calls around line 72. New classes must be added there.

## Work packages

### WP1 — Working-day calendar

- **files:** `local/plugins/slk-checkout/includes/class-slk-calendar.php`
- **brief:** Date arithmetic that skips non-working days and holidays, in the
  site timezone, honouring a daily cut-off after which counting starts the next
  day. Pure functions, no WordPress hooks. This is the only place date maths
  lives.
- **exact code:**

```php
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
	 * "Tomorrow" and "Today" read better than a date the shopper has to decode.
	 */
	public static function label( DateTimeImmutable $date, array $settings ): string {
		$today = self::start( $settings );
		$diff  = (int) $today->diff( $date )->format( '%r%a' );

		if ( 0 === $diff ) {
			return __( 'today', 'slk' );
		}
		if ( 1 === $diff ) {
			return __( 'tomorrow', 'slk' );
		}

		return wp_date( _x( 'j F', 'promise date format', 'slk' ), $date->getTimestamp(), wp_timezone() );
	}
}
```

### WP2 — Fulfilment settings and per-item ready time

- **files:** `local/plugins/slk-checkout/includes/class-slk-fulfilment.php`
- **brief:** Owns the settings array, the per-product meta, and the single
  function that answers "how many working days until this line is ready".
  Registers the product meta box fields' save handler only; the field markup is
  WP4's job and calls into here.
- **exact code:**

```php
<?php
/**
 * How long until a line is ready, and the settings behind that answer.
 *
 * Three cases, decided by stock alone:
 *   in stock             -> dispatch_days
 *   not in stock, active -> making_days + tolerance_days
 *   not in stock, retired -> null, meaning it cannot be bought
 *
 * @package slk-checkout
 */

defined( 'ABSPATH' ) || exit;

class SLK_Fulfilment {

	public const OPTION      = 'slk_fulfilment_settings';
	public const META_MAKING = '_slk_making_days';
	public const META_RETIRED = '_slk_retired';

	public static function defaults(): array {
		return array(
			'dispatch_days'       => 1,
			'default_making_days' => 7,
			'tolerance_days'      => 0,
			'cutoff_hour'         => 15,
			'working_days'        => array( 1, 2, 3, 4, 5, 6 ), // Monday to Saturday.
			'holidays'            => array(),
			'extra_shipment_fee'  => 0, // 0 means charge the normal district fee.
			'split_enabled'       => true,
		);
	}

	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function is_retired( WC_Product $product ): bool {
		$id  = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		return 'yes' === get_post_meta( $id, self::META_RETIRED, true );
	}

	public static function making_days( WC_Product $product ): int {
		$id  = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$own = get_post_meta( $id, self::META_MAKING, true );

		if ( '' !== $own && is_numeric( $own ) ) {
			return max( 0, (int) $own );
		}

		return max( 0, (int) self::settings()['default_making_days'] );
	}

	/**
	 * Working days until this line is ready to leave, or null when the piece
	 * cannot be supplied at all.
	 *
	 * is_on_backorder( $qty ) is the accurate test for "we do not have this
	 * many on the shelf": it is true only when stock is managed, backorders are
	 * allowed and the held quantity is short. A product with stock management
	 * off and status instock reads as in stock, which is correct.
	 */
	public static function ready_days( WC_Product $product, int $qty ): ?int {
		$settings = self::settings();

		if ( ! $product->is_in_stock() ) {
			return null; // outofstock: retired and gone.
		}

		if ( ! $product->is_on_backorder( $qty ) ) {
			return max( 0, (int) $settings['dispatch_days'] );
		}

		if ( self::is_retired( $product ) ) {
			return null;
		}

		return self::making_days( $product ) + max( 0, (int) $settings['tolerance_days'] );
	}
}
```

### WP3 — Shipment grouping and per-shipment fees

- **files:** `local/plugins/slk-checkout/includes/class-slk-shipments.php`
- **brief:** Turns the cart into one or more shipments and prices each. This is
  the only place the split rule and the fee rule live. Reads the shopper's mode
  from the session.
- **exact code:**

```php
<?php
/**
 * Grouping the cart into shipments, and pricing each one.
 *
 * Mode "together" is one shipment leaving on the latest ready date.
 * Mode "split" is one shipment per distinct ready date.
 *
 * Fees follow the order value rule the store already advertises, applied to
 * each shipment: a shipment worth the free-delivery threshold or more travels
 * free, anything less pays. That is what makes the extra cost of splitting
 * avoidable, by putting more into the same shipment.
 *
 * @package slk-checkout
 */

defined( 'ABSPATH' ) || exit;

class SLK_Shipments {

	public const MODE_TOGETHER = 'together';
	public const MODE_SPLIT    = 'split';
	public const SESSION_KEY   = 'slk_ship_mode';

	public static function init(): void {
		add_action( 'wp_ajax_slk_set_ship_mode', array( __CLASS__, 'ajax_set_mode' ) );
		add_action( 'wp_ajax_nopriv_slk_set_ship_mode', array( __CLASS__, 'ajax_set_mode' ) );
	}

	public static function mode(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return self::MODE_TOGETHER;
		}

		$mode = WC()->session->get( self::SESSION_KEY );

		return self::MODE_SPLIT === $mode ? self::MODE_SPLIT : self::MODE_TOGETHER;
	}

	public static function set_mode( string $mode ): void {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set(
				self::SESSION_KEY,
				self::MODE_SPLIT === $mode ? self::MODE_SPLIT : self::MODE_TOGETHER
			);
		}
	}

	public static function ajax_set_mode(): void {
		check_ajax_referer( 'slk-ship-mode', 'nonce' );
		self::set_mode( isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : '' );
		wp_send_json_success();
	}

	/**
	 * @return array<int,array{ready_days:int,ready_date:DateTimeImmutable,keys:array<int,string>,subtotal:float}>
	 */
	public static function build( $cart = null, ?string $mode = null ): array {
		$cart = $cart ?: ( function_exists( 'WC' ) ? WC()->cart : null );
		if ( ! $cart ) {
			return array();
		}

		$settings = SLK_Fulfilment::settings();
		$mode     = $mode ?: self::mode();
		$start    = SLK_Calendar::start( $settings );

		$by_days = array();
		$slowest = 0;

		foreach ( $cart->get_cart() as $key => $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$qty  = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
			$days = SLK_Fulfilment::ready_days( $product, $qty );

			if ( null === $days ) {
				continue; // Cannot be supplied; WP4's validator keeps it out of the cart.
			}

			$line_total = isset( $item['line_subtotal'] ) ? (float) $item['line_subtotal'] : 0.0;
			$slowest    = max( $slowest, $days );

			if ( ! isset( $by_days[ $days ] ) ) {
				$by_days[ $days ] = array( 'keys' => array(), 'subtotal' => 0.0 );
			}
			$by_days[ $days ]['keys'][]   = $key;
			$by_days[ $days ]['subtotal'] += $line_total;
		}

		if ( empty( $by_days ) ) {
			return array();
		}

		if ( self::MODE_TOGETHER === $mode ) {
			$keys     = array();
			$subtotal = 0.0;
			foreach ( $by_days as $group ) {
				$keys      = array_merge( $keys, $group['keys'] );
				$subtotal += $group['subtotal'];
			}

			return array(
				array(
					'ready_days' => $slowest,
					'ready_date' => SLK_Calendar::add_working_days( $start, $slowest, $settings ),
					'keys'       => $keys,
					'subtotal'   => $subtotal,
				),
			);
		}

		ksort( $by_days, SORT_NUMERIC );

		$shipments = array();
		foreach ( $by_days as $days => $group ) {
			$shipments[] = array(
				'ready_days' => (int) $days,
				'ready_date' => SLK_Calendar::add_working_days( $start, (int) $days, $settings ),
				'keys'       => $group['keys'],
				'subtotal'   => $group['subtotal'],
			);
		}

		return $shipments;
	}

	/**
	 * The date a single cart line is ready, regardless of when it travels.
	 */
	public static function line_ready_date( WC_Product $product, int $qty ): ?DateTimeImmutable {
		$settings = SLK_Fulfilment::settings();
		$days     = SLK_Fulfilment::ready_days( $product, $qty );

		if ( null === $days ) {
			return null;
		}

		return SLK_Calendar::add_working_days( SLK_Calendar::start( $settings ), $days, $settings );
	}

	public static function fee_for_shipment( float $subtotal, string $district, bool $is_first ): float {
		if ( $subtotal >= SLK_Shipping::FREE_OVER ) {
			return 0.0;
		}

		$district_fee = SLK_Shipping::fee_for_district( $district );

		if ( $is_first ) {
			return $district_fee;
		}

		$extra = SLK_Money::rupees( SLK_Fulfilment::settings()['extra_shipment_fee'] );

		return $extra > 0 ? $extra : $district_fee;
	}

	/**
	 * Total delivery cost across every shipment.
	 */
	public static function total_fee( string $district, $cart = null ): float {
		$total = 0.0;
		foreach ( self::build( $cart ) as $i => $shipment ) {
			$total += self::fee_for_shipment( (float) $shipment['subtotal'], $district, 0 === $i );
		}

		return $total;
	}

	/**
	 * How much more this shipment needs to travel free, or 0 when it already does.
	 */
	public static function shortfall( float $subtotal ): float {
		return max( 0.0, SLK_Shipping::FREE_OVER - $subtotal );
	}
}
```

### WP4 — Admin: settings screen, product fields, cart guard

- **files:**
  `local/plugins/slk-checkout/includes/class-slk-fulfilment-admin.php`,
  `local/plugins/slk-checkout/slk-checkout.php`
- **brief:**
  1. A WooCommerce settings section (`WooCommerce → Settings → Shipping →
     Delivery promise`) writing to `SLK_Fulfilment::OPTION`, with one field per
     key in `SLK_Fulfilment::defaults()`. Holidays is a textarea of `Y-m-d`
     lines. Working days is a multi-select of weekday numbers.
  2. Product data fields on the Inventory tab: a number input "Making time
     (working days)" saving `_slk_making_days` (blank means use the default),
     and a checkbox "Retired, sell remaining stock only" saving
     `_slk_retired`. Beside the making-time field print the count of open
     orders containing this product, using an HPOS-safe `wc_get_orders()` call
     with `status` limited to processing plus any custom pending statuses, so
     the operator sets the number knowing the backlog.
  3. Register the new classes in `slk-checkout.php` beside the existing
     `require_once` block, in this order: calendar, fulfilment, shipments,
     fulfilment-admin. Call `SLK_Shipments::init()` where the other `init()`
     calls are made.
  4. A cart guard on `woocommerce_add_to_cart_validation` that refuses a
     product whose `SLK_Fulfilment::ready_days()` is null, with the message
     "Sorry, this size is not available." Also filter
     `woocommerce_check_cart_items` to drop such lines if they were already in
     the cart when the last one sold.
- **effort:** low for the settings scaffolding; the order-count query needs care.

### WP5 — Shipping method charges per shipment

- **files:** `local/plugins/slk-checkout/includes/class-slk-shipping-method.php`
- **brief:** `calculate_shipping()` currently prices one delivery. It must now
  price every shipment and add a single rate whose cost is the sum, so the cart
  keeps one shipping line and WooCommerce keeps one package.
  - Replace the free/not-free branch with `SLK_Shipments::total_fee( $district )`.
  - When `count( SLK_Shipments::build() ) > 1`, the label states the number of
    shipments, for example "Delivery to Galle · 2 shipments". When the total is
    0 the label stays "Free delivery".
  - Keep the existing `slk_shipping_rate_label` filter, the rate id, and the
    District meta exactly as they are.
  - Guard for an empty shipments array by falling back to the current
    single-fee behaviour, so an empty or unusual cart cannot produce a free
    rate by accident.

### WP6 — The cart: two dates per line, and the choice

- **files:**
  `local/themes/slk-child/woocommerce/cart/cart.php`,
  `local/themes/slk-child/inc/cart.php`
- **brief:** The visible half of the feature.
  - Above the cart table, render the choice as two radio inputs when
    `split_enabled` is true and the cart has more than one distinct ready date:
    "Send everything together" (default) and "Send each piece as soon as it is
    ready". Changing it posts to `slk_set_ship_mode` with the
    `slk-ship-mode` nonce and reloads the cart.
  - Each cart row gains a line under the product name: "Ready
    <SLK_Calendar::label(...)>." Use `SLK_Shipments::line_ready_date()`.
  - In together mode, one line under the table reads "Everything ships on
    <date>."
  - In split mode, group the rows under headings: "Shipment 1 of 2, ships on
    <date>." Under each heading, if that shipment pays a fee, add "Delivery for
    this shipment is <fee>. Add <shortfall> more to this shipment and it
    travels free."
  - Copy must obey the rules above. Do not write "made to order" or
    "backorder". Render every amount with `wc_price()`.
  - Styles go in `inc/cart.php` beside the existing cart CSS, tokens only.
- **exact code:** the row date line, so the null case is handled the same way
  everywhere:

```php
<?php
$slk_ready = SLK_Shipments::line_ready_date( $_product, (int) $cart_item['quantity'] );
if ( $slk_ready ) :
	?>
	<span class="slk-cart__ready">
		<?php
		printf(
			/* translators: %s: a date, or the words today or tomorrow. */
			esc_html__( 'Ready %s.', 'slk' ),
			esc_html( SLK_Calendar::label( $slk_ready, SLK_Fulfilment::settings() ) )
		);
		?>
	</span>
<?php endif; ?>
```

### WP7 — Seed the catalogue so the states are real

- **files:** `local/seed-catalog.sh`
- **effort:** low
- **brief:** The nine products are currently `_manage_stock no`, so every line
  looks in stock and the feature has nothing to demonstrate. Turn stock
  management on, give each product a quantity, allow backorders, and set a
  making time, so all three states exist in a fresh install:
  - Most products: `_manage_stock yes`, `_stock` 3, `_backorders notify`.
  - Give `mizna` `_stock` 0 so it exercises the making-time path.
  - Give `dahlia` `_stock` 0, `_backorders no` and `_slk_retired yes` so it
    exercises the not-available path.
  - Set `_slk_making_days` per product: 4 for the simpler cuts (`amara`,
    `noor`, `liana`), 10 for `inaya` and `mira`, leave the rest blank to use
    the default.
  - Keep every existing meta write and the image import untouched.

## Acceptance criteria

1. A cart holding only an in-stock product shows "Ready tomorrow." on the line
   and "Everything ships on <that same date>." below the table.
2. A cart holding only an out-of-stock, non-retired product with a making time
   of 10 shows a ready date 10 working days out, and ships on that date.
3. A cart holding both shows two different ready dates on the two lines, and in
   the default mode both ship on the later date.
4. Switching to "send each piece as soon as it is ready" splits that cart into
   two labelled shipments, the earlier one first.
5. Delivery cost equals the sum of the per-shipment fees. A shipment worth
   `FREE_OVER` or more contributes nothing. Together mode is charged once.
6. Where a shipment pays a fee, the cart states how much more would make it
   free, and adding that much removes the fee.
7. A retired product with no stock cannot be added to the cart, and is removed
   from the cart if it is already there.
8. Making time and the retired flag are editable per product in the admin, and
   the making-time field shows the count of open orders for that product.
9. All settings in `SLK_Fulfilment::defaults()` are editable in the admin and
   take effect without code changes.
10. Working-day maths skips the configured non-working weekdays and holidays,
    and orders placed after the cut-off hour count from the next day.
11. No customer-facing string contains an em dash, an en dash, a sentence
    fragment, or the words "made to order" or "backorder".
12. Cart and checkout remain classic shortcode pages. No block conversion.

## Verification commands

From the repo root. The container is already running.

```
cd local && docker compose exec -T wordpress php -l /var/www/html/wp-content/plugins/slk-checkout/includes/class-slk-calendar.php
cd local && docker compose exec -T wordpress php -l /var/www/html/wp-content/plugins/slk-checkout/includes/class-slk-fulfilment.php
cd local && docker compose exec -T wordpress php -l /var/www/html/wp-content/plugins/slk-checkout/includes/class-slk-shipments.php
cd local && docker compose exec -T wordpress php -l /var/www/html/wp-content/plugins/slk-checkout/includes/class-slk-fulfilment-admin.php
cd local && docker compose exec -T wordpress php -l /var/www/html/wp-content/plugins/slk-checkout/includes/class-slk-shipping-method.php
cd local && docker compose exec -T wordpress php -l /var/www/html/wp-content/themes/slk-child/woocommerce/cart/cart.php
cd local && docker compose exec -T wordpress php -l /var/www/html/wp-content/themes/slk-child/inc/cart.php
cd local && bash -n seed-catalog.sh
```

`MSYS_NO_PATHCONV=1` must be exported before any `docker compose exec` call on
this machine, or Git Bash rewrites the container paths.

The store is at http://localhost:8088 and the cart at
http://localhost:8088/cart/.

## Risks & rollback

- **The shipping rate is cached.** WooCommerce caches shipping rates per
  package hash. Changing the ship mode must invalidate them, or the cost will
  not move. Call `WC()->cart->calculate_shipping()` after setting the mode, and
  include the mode in the package hash via
  `woocommerce_cart_shipping_packages` if the cost still sticks.
- **Quantity partly in stock.** Wanting 2 when 1 is held makes the whole line
  wait for the making time. That is deliberate and never promises earlier than
  we can deliver. Do not attempt to split a line by quantity.
- **`is_on_backorder()` needs managed stock.** With stock management off it is
  always false, so every line reads as in stock. WP7 is what makes the feature
  visible; without it the cart will look unchanged and that is not a bug.
- **Do not let a null ready time silently vanish from pricing.** A line that
  cannot be supplied is skipped by `build()`; WP4's guard is what keeps it out
  of the cart in the first place. Both must exist.
- Rollback is `git revert` of the feature commit. No data migration is
  destructive: WP7 only writes product meta that can be rewritten by re-running
  the seed.
