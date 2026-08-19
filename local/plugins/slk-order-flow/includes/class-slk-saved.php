<?php
/**
 * My Account → "Saved pieces": a plain wishlist.
 *
 * Registers a real WooCommerce My Account endpoint (not a parallel page),
 * mirroring the sibling slk-exchanges plugin's
 * SLK_Exchange_Account exactly: the endpoint constant, the one-shot
 * rewrite-flush option, the woocommerce_get_query_vars filter, the
 * woocommerce_account_menu_items filter and the endpoint-renderer shape.
 *
 * Storage is a single user meta array of product IDs — no separate table,
 * no named "lists". The brand rules out the usual wishlist trimmings on
 * purpose: no saved-item counts, no price-drop nudges, no sharing. It is a
 * private list of pieces a customer wants to find again, nothing more.
 *
 * The toggle itself (a heart on a shop card, a text button on the PDP) is
 * drawn once, here, and reused by both theme call sites via
 * self::render_toggle() — one glyph, one meaning, guarded everywhere with
 * class_exists( 'SLK_Saved' ) so a deploy that ships the theme without this
 * plugin degrades to nothing rather than a fatal error.
 *
 * REWRITE FLUSH: same idiom SLK_Exchange_Account uses (see that file's own
 * header) — a one-shot option instead of an activation hook, since this
 * plugin has no bootstrap file in scope to hang one off.
 *
 * @package slk-order-flow
 */

defined( 'ABSPATH' ) || exit;

class SLK_Saved {

	/** The endpoint slug: /my-account/saved/. */
	public const ENDPOINT = 'saved';

	/** One-shot guard so rewrite rules are flushed once, not on every load. */
	private const FLUSH_OPTION = 'slk_saved_account_rewrite_flushed';

	/** User meta: array of saved product IDs. */
	private const META_KEY = '_slk_saved_products';

	/** admin-ajax action name and nonce action, shared with the theme's toggle scripts. */
	public const AJAX_ACTION  = 'slk_saved_toggle';
	public const NONCE_ACTION = 'slk-saved';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'add_query_var' ) );
		// Priority 24: after the theme's own menu filter (slk-child/inc/account.php,
		// priority 20) has already dropped dashboard/downloads/payment-methods
		// and relabelled the rest, and before SLK_Exchange_Account's own filter
		// (25) — so the account nav reads Orders, Addresses, Account details,
		// Saved pieces, Exchanges, Sign out.
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_menu_item' ), 24 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render' ) );

		// Logged-in only: deliberately no _nopriv counterpart. Nothing this
		// codebase draws ever posts here signed out — a guest's heart is a
		// plain <a> to the account page, rendered by the theme, not a button
		// wired to this action — so there is no legitimate signed-out caller.
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_toggle' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_account_styles' ), 40 );
	}

	/**
	 * Register the endpoint with WordPress, and flush once. See
	 * SLK_Exchange_Account::register_endpoint() for why EP_PAGES (never
	 * EP_ROOT) and why the flush is one-shot rather than per-request.
	 */
	public static function register_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_PAGES );

		if ( ! get_option( self::FLUSH_OPTION ) ) {
			flush_rewrite_rules();
			update_option( self::FLUSH_OPTION, 'yes', false );
		}
	}

	/**
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
	 * Insert "Saved pieces" into the account nav, just above "Sign out" —
	 * same fallback discipline as SLK_Exchange_Account::add_menu_item(): if
	 * no logout row is present, append at the end rather than dropping the
	 * item silently.
	 *
	 * @param array $items Endpoint => label.
	 * @return array
	 */
	public static function add_menu_item( $items ) {
		if ( ! is_array( $items ) || isset( $items[ self::ENDPOINT ] ) ) {
			return $items;
		}

		$label      = __( 'Saved pieces', 'slk' );
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
	 * The endpoint's public URL, for anything that wants to link to it
	 * without hard-coding the endpoint slug a second time.
	 */
	public static function url(): string {
		return function_exists( 'wc_get_account_endpoint_url' )
			? wc_get_account_endpoint_url( self::ENDPOINT )
			: wc_get_page_permalink( 'myaccount' );
	}

	/* ------------------------------------------------------------------ */
	/* Data                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * A user's saved product IDs, sanitised to a unique list of positive
	 * integers regardless of what is actually stored.
	 *
	 * @return array<int,int>
	 */
	public static function get_saved_ids( int $user_id ): array {
		if ( $user_id < 1 ) {
			return array();
		}

		$raw = get_user_meta( $user_id, self::META_KEY, true );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		return array_values( array_unique( array_map( 'absint', $raw ) ) );
	}

	public static function is_saved( int $user_id, int $product_id ): bool {
		return $product_id > 0 && in_array( $product_id, self::get_saved_ids( $user_id ), true );
	}

	/**
	 * Add or remove a product from a user's saved list.
	 *
	 * @return bool The new state: true if the product is saved after this call.
	 */
	public static function toggle( int $user_id, int $product_id ): bool {
		$ids = self::get_saved_ids( $user_id );

		if ( in_array( $product_id, $ids, true ) ) {
			update_user_meta( $user_id, self::META_KEY, array_values( array_diff( $ids, array( $product_id ) ) ) );
			return false;
		}

		$ids[] = $product_id;
		update_user_meta( $user_id, self::META_KEY, $ids );

		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Ajax                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Toggle one product for the signed-in user. Never trusts the posted id
	 * beyond confirming a real, loadable product sits behind it — there is
	 * no ownership concept to check beyond "is this a real product", unlike
	 * an order.
	 */
	public static function ajax_toggle(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error(); // Belt and braces: the wp_ajax_ hook already gates this.
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : false;

		if ( ! $product instanceof WC_Product ) {
			wp_send_json_error();
		}

		$saved = self::toggle( get_current_user_id(), $product_id );

		wp_send_json_success(
			array(
				'saved'      => $saved,
				'product_id' => $product_id,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Rendering — the account endpoint page                               */
	/* ------------------------------------------------------------------ */

	public static function render(): void {
		if ( ! is_user_logged_in() ) {
			return; // WooCommerce already gates the account area; belt and braces.
		}

		$products = array();

		foreach ( self::get_saved_ids( get_current_user_id() ) as $id ) {
			$product = wc_get_product( $id );

			if ( $product instanceof WC_Product ) {
				$products[] = $product;
			}
		}

		if ( empty( $products ) ) {
			?>
			<div class="slk-panel">
				<p><?php esc_html_e( 'Nothing saved yet. Pieces you save appear here.', 'slk' ); ?></p>
				<p><a class="slk-btn slk-btn--secondary" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'Go to the shop', 'slk' ); ?></a></p>
			</div>
			<?php
			return;
		}

		echo '<div class="slk-saved-grid">';

		foreach ( $products as $product ) {
			self::render_card( $product );
		}

		echo '</div>';
	}

	private static function render_card( WC_Product $product ): void {
		$id = $product->get_id();
		?>
		<div class="slk-saved-card">
			<a class="slk-card" href="<?php echo esc_url( get_permalink( $id ) ); ?>">
				<div class="slk-card__media">
					<?php
					if ( $product->get_image_id() ) {
						echo wp_kses_post( $product->get_image( 'slk_card' ) );
					} else {
						echo wp_kses_post( wc_placeholder_img( 'slk_card' ) );
					}
					?>
				</div>
				<h3 class="slk-card__name"><?php echo esc_html( $product->get_name() ); ?></h3>
				<div class="slk-card__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></div>
			</a>
			<?php self::render_toggle( $id, 'card' ); ?>
		</div>
		<?php
	}

	/**
	 * Small, scoped CSS for the grid above. Tokens only, matching the rest
	 * of the account area's styling in slk-child/inc/account.php, which
	 * this file cannot edit (out of this package's file list) — same
	 * reasoning and the same wp_add_inline_style( 'slk-child', … ) idiom as
	 * SLK_Points::enqueue_account_styles() in the sibling class-slk-points.php.
	 *
	 * The toggle button's own look (.slk-save-btn, .slk-save-btn--card) is
	 * defined once, globally, by slk-child/inc/shop.php — loaded on every
	 * front-end page including this one, so it is not repeated here. This
	 * block only supplies the grid layout and the positioning context
	 * (.slk-saved-card{position:relative}) the card variant is drawn
	 * against.
	 */
	public static function enqueue_account_styles(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		$css = <<<'CSS'
.slk-saved-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--slk-space-6) var(--slk-space-3)}
@media (min-width:1000px){.slk-saved-grid{grid-template-columns:repeat(4,minmax(0,1fr))}}
.slk-saved-card{position:relative}
CSS;

		wp_add_inline_style( 'slk-child', $css );
	}

	/* ------------------------------------------------------------------ */
	/* The toggle control — shared by both theme call sites                */
	/* ------------------------------------------------------------------ */

	/**
	 * The heart toggle. Drawn once so a shop card and the PDP always agree
	 * on the same glyph and the same meaning.
	 *
	 * Signed out, this is a real, working <a> to the account page — never a
	 * dead control that looks interactive and does nothing (brand law).
	 * Signed in, it is a <button> the click handler localised alongside it
	 * (slk-child/inc/shop.php) wires up to self::ajax_toggle() above.
	 *
	 * @param int    $product_id Product this control saves/unsaves.
	 * @param string $variant    'card' — icon only, absolutely positioned
	 *                           over a product card's media (the shop grid,
	 *                           the Saved pieces grid). 'text' — a ghost
	 *                           text button carrying its own visible label
	 *                           (the PDP, near "Add to bag").
	 */
	public static function render_toggle( int $product_id, string $variant = 'card' ): void {
		if ( $product_id < 1 ) {
			return;
		}

		$is_text = ( 'text' === $variant );
		$size    = $is_text ? 16 : 20;

		$classes = array( 'slk-save-btn', $is_text ? 'slk-save-btn--text' : 'slk-save-btn--card' );

		if ( $is_text ) {
			array_unshift( $classes, 'slk-btn', 'slk-btn--ghost' );
		}

		if ( ! is_user_logged_in() ) {
			$url = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : '';

			if ( '' === $url ) {
				return; // No account page to send her to — nothing to link.
			}

			printf(
				'<a class="%1$s" href="%2$s" aria-label="%3$s">%4$s%5$s</a>',
				esc_attr( implode( ' ', $classes ) ),
				esc_url( $url ),
				esc_attr__( 'Sign in to save', 'slk' ),
				self::heart_svg( $size ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup, see helper.
				$is_text ? '<span>' . esc_html__( 'Save this piece', 'slk' ) . '</span>' : ''
			);

			return;
		}

		$saved = self::is_saved( get_current_user_id(), $product_id );

		if ( $saved ) {
			$classes[] = 'is-saved';
		}

		$label = $saved
			? __( 'Remove from saved pieces', 'slk' )
			: __( 'Save this piece', 'slk' );

		printf(
			'<button type="button" class="%1$s" data-slk-save data-slk-save-id="%2$d" aria-pressed="%3$s" aria-label="%4$s">%5$s%6$s</button>',
			esc_attr( implode( ' ', $classes ) ),
			$product_id,
			$saved ? 'true' : 'false',
			esc_attr( $label ),
			self::heart_svg( $size ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup, see helper.
			$is_text ? '<span data-slk-save-label>' . esc_html( $saved ? __( 'Saved', 'slk' ) : __( 'Save this piece', 'slk' ) ) . '</span>' : ''
		);
	}

	/**
	 * The heart glyph. Outline only — fill is added purely by CSS on
	 * .is-saved (see slk-child/inc/shop.php), never drawn as a second,
	 * separate "filled" path here, so the two states can never drift out of
	 * alignment with each other. Ink by inheritance (stroke/fill both
	 * currentColor) — brand law: never red.
	 */
	private static function heart_svg( int $size ): string {
		return sprintf(
			'<svg class="slk-save-btn__icon" viewBox="0 0 24 24" width="%1$d" height="%1$d" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 20.1c-.28 0-.55-.1-.76-.28-2.02-1.73-3.9-3.35-5.4-4.96C4.02 12.9 2.9 11.02 2.9 9.02c0-2.98 2.28-5.17 5.06-5.17 1.7 0 3.32.86 4.29 2.28.06.08.18.08.24 0 .97-1.42 2.59-2.28 4.29-2.28 2.78 0 5.06 2.19 5.06 5.17 0 2-1.12 3.88-3.18 5.86-1.5 1.6-3.38 3.21-5.4 4.94-.21.18-.48.28-.76.28Z"/></svg>',
			$size
		);
	}
}
