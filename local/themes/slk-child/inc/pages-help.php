<?php
/**
 * Delivery & COD / Exchange policy / FAQ.
 *
 * Provisions the three editorial pages this file owns and holds the data
 * each template renders (delivery zones/fees, FAQ copy) so the templates in
 * page-templates/ stay pure presentation. Numbers mirror
 * design/assets/sri-lanka-commerce.json (the source of truth for fees and
 * timings) — kept as a plain PHP array here rather than a runtime file read,
 * because that JSON lives in design/ and is not shipped with the theme.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * 1. Provision the pages.
 * ---------------------------------------------------------------------- */

add_action(
	'init',
	static function () {
		slk_ensure_page( 'delivery', __( 'Delivery & COD', 'slk' ), 'page-templates/delivery.php' );
		slk_ensure_page( 'exchanges', __( 'Exchanges', 'slk' ), 'page-templates/exchange.php' );
		slk_ensure_page( 'faq', __( 'FAQ', 'slk' ), 'page-templates/faq.php' );
	},
	15
);

/* -------------------------------------------------------------------------
 * 2. Delivery zones, free-delivery threshold, COD handling fee, exchange
 * window/fee.
 *
 * Thin proxies over the plugin, which is the checkout's source of truth for
 * every one of these numbers (SLK_Shipping, SLK_Payments, SLK_Fulfilment —
 * dashboard-editable). Each falls back to the literal the store shows today
 * when the plugin is off or a given accessor has not shipped yet, so the
 * theme never fatals and a fresh install renders unchanged.
 * ---------------------------------------------------------------------- */

/**
 * @return array<int,array{label:string,days:string,fee:int}>
 */
function slk_delivery_zones() {
	if ( class_exists( 'SLK_Shipping' ) && method_exists( 'SLK_Shipping', 'zones_public' ) ) {
		$zones = SLK_Shipping::zones_public();

		if ( is_array( $zones ) && ! empty( $zones ) ) {
			return $zones;
		}
	}

	return array(
		array(
			'label' => __( 'Colombo & Gampaha', 'slk' ),
			'days'  => __( '1 to 2 working days', 'slk' ),
			'fee'   => 350,
		),
		array(
			'label' => __( 'Kandy · Galle · Kalutara · Kurunegala', 'slk' ),
			'days'  => __( '2 to 3 working days', 'slk' ),
			'fee'   => 400,
		),
		array(
			'label' => __( 'All other districts', 'slk' ),
			'days'  => __( '3 to 5 working days', 'slk' ),
			'fee'   => 450,
		),
	);
}

/**
 * One zone row's live day range, for the sentences that name the districts
 * themselves instead of printing the zone table. Tier 0 is Colombo &
 * Gampaha, 1 the regional towns, 2 the rest of the island — the order
 * slk_delivery_zones() returns.
 *
 * @param int $tier Row index.
 * @return string Empty when that row is absent.
 */
function slk_delivery_days( $tier ) {
	$zones = array_values( (array) slk_delivery_zones() );

	return isset( $zones[ $tier ]['days'] ) ? (string) $zones[ $tier ]['days'] : '';
}

/**
 * The same row's fee, formatted for prose ("Rs. 350").
 *
 * @param int $tier Row index.
 * @return string Empty when that row is absent.
 */
function slk_delivery_fee_text( $tier ) {
	$zones = array_values( (array) slk_delivery_zones() );

	return isset( $zones[ $tier ]['fee'] ) ? wp_strip_all_tags( wc_price( $zones[ $tier ]['fee'] ) ) : '';
}

/** @return int Rupees. */
function slk_delivery_free_over() {
	if ( class_exists( 'SLK_Shipping' ) && method_exists( 'SLK_Shipping', 'free_over' ) ) {
		return (int) SLK_Shipping::free_over();
	}

	return 15000;
}

/** @return int Rupees. */
function slk_delivery_cod_fee() {
	if ( class_exists( 'SLK_Payments' ) && method_exists( 'SLK_Payments', 'cod_fee' ) ) {
		return (int) SLK_Payments::cod_fee();
	}

	return 150;
}

/** @return int Rupees. Exchange courier's contribution toward the new size going out. */
function slk_exchange_send_fee() {
	if ( class_exists( 'SLK_Fulfilment' ) && method_exists( 'SLK_Fulfilment', 'exchange_send_fee' ) ) {
		return (int) SLK_Fulfilment::exchange_send_fee();
	}

	return 350;
}

/** @return int Working days a shopper has, from delivery, to start an exchange. */
function slk_exchange_window_days() {
	if ( class_exists( 'SLK_Fulfilment' ) && method_exists( 'SLK_Fulfilment', 'exchange_window_days' ) ) {
		return (int) SLK_Fulfilment::exchange_window_days();
	}

	return 7;
}

/* -------------------------------------------------------------------------
 * 3. FAQ content, grouped by the design's category chips.
 *
 * Only the "Paying" group and its first answer are drawn verbatim from
 * design/sections/04-pages.html; the design left the remaining answers (and
 * the other three categories) unwritten. Those are filled in here in the
 * same voice, from facts already established elsewhere in this file, in
 * sri-lanka-commerce.json and in design/docs/brand-guidelines.md — nothing
 * invented beyond what the store already states.
 * ---------------------------------------------------------------------- */

/**
 * @return array<string,array{label:string,items:array<int,array{q:string,a:string}>}>
 */
function slk_faq_groups() {
	$cod_fee   = slk_delivery_cod_fee();
	$free_over = slk_delivery_free_over();

	$size_guide_url = slk_page_id( 'size-guide' ) ? slk_page_url( 'size-guide' ) : '';
	$exchanges_url  = slk_page_url( 'exchanges' );

	// Composed from the live settings, never restated: a zero handling fee is
	// not charged at checkout, so the sentence that names one is dropped
	// rather than printed as "Rs. 0".
	$paying_answer = $cod_fee > 0
		? sprintf(
			/* translators: %s: COD handling fee, e.g. "Rs. 150". */
			__( 'You pay nothing when you order. With cash on delivery you pay the courier at your door, in cash. The %s handling fee is added to your total, and you see it before you order.', 'slk' ),
			wp_strip_all_tags( wc_price( $cod_fee ) )
		)
		: __( 'You pay nothing when you order. With cash on delivery you pay the courier at your door, in cash. There is no handling fee to add.', 'slk' );

	$delivery_cost = sprintf(
		/* translators: 1: Colombo & Gampaha fee. 2: regional-town fee. 3: rest-of-island fee. */
		__( 'Delivery costs %1$s to Colombo and Gampaha, %2$s to Kandy, Galle, Kalutara and Kurunegala, and %3$s everywhere else.', 'slk' ),
		slk_delivery_fee_text( 0 ),
		slk_delivery_fee_text( 1 ),
		slk_delivery_fee_text( 2 )
	);

	// Free delivery can be switched off from the dashboard (threshold 0), and
	// then there is no promise to make.
	if ( $free_over > 0 ) {
		$delivery_cost .= ' ' . sprintf(
			/* translators: %s: free-delivery threshold, e.g. "Rs. 15,000". */
			__( 'Orders over %s ship free.', 'slk' ),
			wp_strip_all_tags( wc_price( $free_over ) )
		);
	}

	$delivery_time = sprintf(
		/* translators: 1: Colombo & Gampaha day range. 2: regional-town day range. 3: rest-of-island day range. */
		__( 'Colombo and Gampaha take %1$s. Kandy, Galle, Kalutara and Kurunegala take %2$s, and every other district takes %3$s. We count from the confirmation call rather than from checkout.', 'slk' ),
		slk_delivery_days( 0 ),
		slk_delivery_days( 1 ),
		slk_delivery_days( 2 )
	);

	return array(
		'paying'   => array(
			'label' => __( 'Paying', 'slk' ),
			'items' => array(
				array(
					'q' => __( 'Do I pay anything before it arrives?', 'slk' ),
					'a' => $paying_answer,
				),
				array(
					'q' => __( 'Why do you call before shipping?', 'slk' ),
					'a' => __( 'We want the piece, the size and the address to be right before we dispatch anything. A real person calls you, or sends a WhatsApp message if that suits you better, and nothing ships until you say yes.', 'slk' ),
				),
				array(
					'q' => __( 'Can I pay by card or eZ Cash?', 'slk' ),
					'a' => __( 'Card, eZ Cash, helaPay and LankaQR all run through one secure PayHere screen at checkout. Paying this way means there is no handling fee to add.', 'slk' ),
				),
				array(
					'q' => __( 'Can I pay in instalments?', 'slk' ),
					'a' => __( 'Koko lets you pay in three instalments. It appears as a payment option at checkout once your cart passes Rs. 10,000.', 'slk' ),
				),
				array(
					'q' => __( 'What if I am not home when it arrives?', 'slk' ),
					'a' => __( 'The courier calls before arriving, and tries again the next working day if you miss them. If a time still does not work, message us on WhatsApp and we will arrange one that does.', 'slk' ),
				),
			),
		),
		'delivery' => array(
			'label' => __( 'Delivery', 'slk' ),
			'items' => array(
				array(
					'q' => __( 'How much does delivery cost?', 'slk' ),
					'a' => $delivery_cost,
				),
				array(
					'q' => __( 'Which areas do you deliver to?', 'slk' ),
					'a' => __( 'We deliver to all 25 districts through one courier partner. There is nowhere in Sri Lanka we cannot reach.', 'slk' ),
				),
				array(
					'q' => __( 'How long will my order take?', 'slk' ),
					'a' => $delivery_time,
				),
				array(
					'q' => __( 'Can I change my delivery address after ordering?', 'slk' ),
					'a' => __( 'Nothing ships until the confirmation call, so message us on WhatsApp any time before then and we will update it.', 'slk' ),
				),
			),
		),
		'sizing'   => array(
			'label' => __( 'Sizing', 'slk' ),
			'items' => array(
				array(
					'q' => __( 'How do I know my size?', 'slk' ),
					'a' => __( 'Send us your bust and height on WhatsApp before you order and we will measure the actual piece against you, not a chart.', 'slk' ),
				),
				array(
					'q' => __( 'What if it doesn\'t fit?', 'slk' ),
					/* translators: 1: number of days to start an exchange, 2: URL of the exchanges page. */
					'a' => sprintf(
						__( 'You can exchange it within %1$d days, and the courier collects from your door. The full policy is on our <a href="%2$s">exchanges page</a>.', 'slk' ),
						slk_exchange_window_days(),
						esc_url( $exchanges_url )
					),
				),
				array(
					'q' => __( 'Do you have a size guide?', 'slk' ),
					'a' => $size_guide_url
						/* translators: %s: URL of the size guide page. */
						? sprintf( __( 'Our <a href="%s">size guide</a> lists the measurements for every cut. Send your bust and height on WhatsApp first if you would rather we checked for you.', 'slk' ), esc_url( $size_guide_url ) )
						: __( 'Send us your bust and height on WhatsApp and we will check your size against the actual piece before you order.', 'slk' ),
				),
			),
		),
		'clothes'  => array(
			'label' => __( 'The clothes', 'slk' ),
			'items' => array(
				array(
					'q' => __( 'What are the dresses made from?', 'slk' ),
					'a' => __( 'Most of them are linen and cotton blends. We cut them loose, keep them opaque, and leave them unlined for the Colombo heat. Each product page lists its exact fabric.', 'slk' ),
				),
				array(
					// The question used to ask for the batch size and the answer
					// gave it. Both are now framed as rarity: the fact a buyer
					// actually cares about is whether she will meet herself at a
					// wedding, not what the run length is.
					'q' => __( 'Will I see someone else in the same piece?', 'slk' ),
					'a' => __( 'It is unlikely. We make a limited run of each piece and then retire it, so once a size sells out in a print it does not come back.', 'slk' ),
				),
				array(
					'q' => __( 'Are the abayas lined?', 'slk' ),
					'a' => __( 'Most are unlined by design, because of the climate. Where a piece is lined, its product page says so.', 'slk' ),
				),
			),
		),
	);
}

/* -------------------------------------------------------------------------
 * 4. Shared styling for delivery.php / exchange.php / faq.php.
 *
 * Built from the tokens and the §2 component contract only — .slk-panel,
 * .slk-btn, .slk-eyebrow are reused as-is; everything below is the small
 * amount of layout components.css does not already provide (the reading
 * column, the numbered/step cards, the accordion, the category chips).
 * ---------------------------------------------------------------------- */

add_action(
	'wp_enqueue_scripts',
	static function () {
		$css = <<<'CSS'
.slk-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.slk-help-main{max-width:var(--slk-container);margin:0 auto;padding:var(--slk-space-6) var(--slk-space-4) var(--slk-space-16)}
.slk-help-col{max-width:880px;margin:0 auto}
.slk-help-hero h1{font-size:var(--slk-display-s);margin:0 0 var(--slk-space-2)}
.slk-help-hero p{font:400 var(--slk-text-base)/1.7 var(--slk-font-ui);color:var(--slk-color-muted);margin:0 0 var(--slk-space-6);max-width:62ch}
.slk-help-section{margin-bottom:var(--slk-space-8)}
.slk-help-section h2{font-size:var(--slk-display-s);margin:0 0 var(--slk-space-2)}
.slk-help-section > p{font:400 var(--slk-text-base)/1.7 var(--slk-font-ui);color:var(--slk-color-muted);margin:0 0 var(--slk-space-4);max-width:62ch}

/* -- zone table ----------------------------------------------------------- */
.slk-zones{width:100%;border-collapse:collapse;overflow:hidden}
.slk-zones th,.slk-zones td{padding:var(--slk-space-3) var(--slk-space-4);text-align:left;font:400 var(--slk-text-base)/1.4 var(--slk-font-ui);border-bottom:1px solid var(--slk-hairline)}
.slk-zones tr:last-child td{border-bottom:0}
.slk-zones td:last-child,.slk-zones th:last-child{text-align:right;color:var(--slk-color-muted);white-space:nowrap}

/* -- numbered step cards --------------------------------------------------- */
.slk-help-steps{list-style:none;margin:0;padding:0;display:grid;gap:var(--slk-space-3)}
.slk-help-step{display:flex;gap:var(--slk-space-4);padding:var(--slk-space-4)}
.slk-help-step__num{flex:none;width:28px;height:28px;border-radius:50%;background:var(--slk-color-ink);color:var(--slk-color-on-ink);display:grid;place-items:center;font:500 12px/1 var(--slk-font-ui)}
.slk-help-step p{margin:0;font:400 var(--slk-text-base)/1.6 var(--slk-font-ui)}

/* -- generic info cards (payment methods, exchange rules) ----------------- */
.slk-help-cards{display:grid;gap:var(--slk-space-3);grid-template-columns:1fr}
.slk-help-card{padding:var(--slk-space-4)}
.slk-help-card h3{font-family:var(--slk-font-ui);font-weight:500;font-size:var(--slk-text-base);margin:0 0 4px}
.slk-help-card p{margin:0;font:400 var(--slk-text-sm)/1.6 var(--slk-font-ui);color:var(--slk-color-muted)}

/* -- dark CTA card ---------------------------------------------------------- */
.slk-help-cta{background:var(--slk-color-ink);color:var(--slk-color-on-ink);border-radius:var(--slk-radius-card);padding:var(--slk-space-6)}
.slk-help-cta h3{font-family:var(--slk-font-ui);font-weight:500;font-size:var(--slk-text-base);margin:0 0 6px;color:var(--slk-color-on-ink)} /* Blocksy paints all h3s --theme-headings-color (ink) — invisible on this ink card; measured on /exchanges/ */
.slk-help-cta p{margin:0 0 var(--slk-space-4);font:300 var(--slk-text-sm)/1.6 var(--slk-font-ui);opacity:.75}
.slk-help-cta .slk-btn--primary{background:var(--slk-color-on-ink);color:var(--slk-color-ink)}

/* -- WhatsApp prompt -------------------------------------------------------- */
.slk-help-wa{display:flex;align-items:center;gap:var(--slk-space-3);min-height:56px;padding:var(--slk-space-2) 10px var(--slk-space-2) var(--slk-space-4);text-decoration:none;color:inherit;box-shadow:var(--slk-shadow-lift)}
.slk-help-wa__text{flex:1;font:500 var(--slk-text-sm)/1.35 var(--slk-font-ui)}
.slk-help-wa__text small{display:block;font-weight:400;color:var(--slk-color-muted);font-size:var(--slk-text-xs)}
.slk-help-wa__mark{flex:none;width:38px;height:38px;border-radius:50%;background:var(--slk-color-ink);color:var(--slk-color-on-ink);display:grid;place-items:center;font:600 12px var(--slk-font-ui)}

/* -- FAQ category chips ----------------------------------------------------- */
.slk-faq-tabs{display:flex;gap:var(--slk-space-2);flex-wrap:wrap;margin:0 0 var(--slk-space-4);padding:0;list-style:none}
.slk-faq-tab{min-height:var(--slk-touch);border:1px solid rgba(35,34,32,.14);padding:0 var(--slk-space-4);background:var(--slk-glass-solid);border-radius:var(--slk-radius-pill);font:400 12px/1 var(--slk-font-ui);cursor:pointer;color:var(--slk-color-ink)}
.slk-faq-tab[aria-pressed="true"]{background:var(--slk-color-ink);color:var(--slk-color-on-ink);border-color:transparent;font-weight:500}

/* -- accordion --------------------------------------------------------------- */
.slk-faq-group{padding:0 var(--slk-space-4)}
.slk-faq-group + .slk-faq-group{margin-top:var(--slk-space-4)}
.slk-faq-item{border-bottom:1px solid var(--slk-hairline)}
.slk-faq-item:last-child{border-bottom:0}
.slk-faq-item[hidden]{display:none}
.slk-faq-item__trigger{width:100%;border:0;background:none;padding:var(--slk-space-4) 0;display:flex;justify-content:space-between;gap:var(--slk-space-3);align-items:center;cursor:pointer;color:var(--slk-color-ink);text-align:left;font:500 var(--slk-text-base)/1.4 var(--slk-font-ui)}
.slk-faq-item__icon{flex:none;font-size:16px;font-weight:300;color:var(--slk-color-faint)}
.slk-faq-item__panel{margin:0;font:400 var(--slk-text-sm)/1.65 var(--slk-font-ui);color:var(--slk-color-muted);padding:0 0 var(--slk-space-4)}
.slk-faq-item__panel[hidden]{display:none}
.slk-faq-item__panel a{color:var(--slk-color-ink)}

@media (min-width:1000px){
	.slk-help-hero h1{font-size:var(--slk-display-l)}
	.slk-help-section h2{font-size:var(--slk-display-m)}
	.slk-help-steps{grid-template-columns:repeat(3,1fr)}
	.slk-help-cards{grid-template-columns:1fr 1fr}
}
CSS;

		wp_add_inline_style( 'slk-child', $css );

		wp_register_script( 'slk-help', '', array(), null, true );
		wp_enqueue_script( 'slk-help' );

		$js = <<<'JS'
(function () {
	document.addEventListener('click', function (e) {
		var trigger = e.target.closest('.slk-faq-item__trigger');
		if (trigger) {
			var panel = document.getElementById(trigger.getAttribute('aria-controls'));
			var open = trigger.getAttribute('aria-expanded') === 'true';
			trigger.setAttribute('aria-expanded', open ? 'false' : 'true');
			if (panel) { panel.hidden = open; }
			var icon = trigger.querySelector('.slk-faq-item__icon');
			if (icon) { icon.textContent = open ? '＋' : '−'; }
			return;
		}

		var tab = e.target.closest('.slk-faq-tab');
		if (tab) {
			var list = tab.closest('.slk-faq-tabs');
			if (!list) { return; }
			Array.prototype.forEach.call(list.querySelectorAll('.slk-faq-tab'), function (t) {
				t.setAttribute('aria-pressed', t === tab ? 'true' : 'false');
			});
			var target = tab.getAttribute('data-faq-target');
			Array.prototype.forEach.call(document.querySelectorAll('.slk-faq-group'), function (group) {
				group.hidden = target !== 'all' && group.getAttribute('data-faq-group') !== target;
			});
		}
	});
})();
JS;

		wp_add_inline_script( 'slk-help', $js );
	},
	31
);
