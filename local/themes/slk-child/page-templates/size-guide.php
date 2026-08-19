<?php
/**
 * Template Name: Size Guide
 *
 * "Find your size" — the measurement reference page. Copy, table numbers and
 * structure reproduced exactly from design/sections/06-mobile.html
 * ("Size guide") and design/sections/08-desktop2.html ("Desktop size
 * guide"); see brand law 1. One house measurement chart currently exists,
 * shared across the three category tabs — the tab switcher is real and
 * keyboard-operable, not decorative.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

get_header();

$slk_sizeguide_tabs = array(
	'abayas'  => __( 'Abayas', 'slk' ),
	'dresses' => __( 'Dresses', 'slk' ),
	'hijabs'  => __( 'Hijabs', 'slk' ),
);

$slk_sizeguide_rows = array(
	array( 'label' => __( 'Bust', 'slk' ), 'values' => array( '90-96', '96-102', '102-110', '110-118' ) ),
	array( 'label' => __( 'Length', 'slk' ), 'values' => array( '134', '138', '140', '142' ) ),
	array( 'label' => __( 'Sleeve', 'slk' ), 'values' => array( '58', '59', '60', '61' ) ),
	array( 'label' => __( 'Hip room', 'slk' ), 'values' => array( '116', '122', '130', '138' ) ),
);

$slk_sizeguide_howto = array(
	array(
		'n' => '01',
		'l' => __( 'Bust', 'slk' ),
		't' => __( 'Measure around the fullest part, keeping the tape parallel to the floor, over the clothes you would wear underneath.', 'slk' ),
	),
	array(
		'n' => '02',
		'l' => __( 'Length', 'slk' ),
		't' => __( 'Measure from the highest point of your shoulder straight down to your ankle bone.', 'slk' ),
	),
	array(
		'n' => '03',
		'l' => __( 'Sleeve', 'slk' ),
		't' => __( 'Measure from the shoulder point to the wrist bone, with your arm relaxed at your side.', 'slk' ),
	),
);

$slk_sizeguide_wa = function_exists( 'slk_whatsapp_url' )
	? slk_whatsapp_url( __( 'Hi! I need help picking a size.', 'slk' ) )
	: '';
?>

<div id="primary" class="slk-sizeguide">
	<div class="slk-container">

		<div class="slk-sizeguide__intro">
			<h1><?php esc_html_e( 'Find your size', 'slk' ); ?></h1>
			<p>
				<?php esc_html_e( 'All measurements are in centimetres, taken flat on the garment. Our cuts sit loose on purpose, so if you are between sizes, take the smaller one.', 'slk' ); ?>
			</p>
		</div>

		<div class="slk-sizeguide__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Size guide by category', 'slk' ); ?>">
			<?php foreach ( $slk_sizeguide_tabs as $slk_tab_key => $slk_tab_label ) : ?>
				<button type="button"
					class="slk-sizeguide__tab<?php echo ( 'abayas' === $slk_tab_key ) ? ' is-active' : ''; ?>"
					role="tab"
					id="slk-tab-<?php echo esc_attr( $slk_tab_key ); ?>"
					aria-controls="slk-tabpanel-<?php echo esc_attr( $slk_tab_key ); ?>"
					aria-selected="<?php echo ( 'abayas' === $slk_tab_key ) ? 'true' : 'false'; ?>"
					data-slk-sizetab="<?php echo esc_attr( $slk_tab_key ); ?>"
				><?php echo esc_html( $slk_tab_label ); ?></button>
			<?php endforeach; ?>
		</div>

		<div class="slk-sizeguide__body">

			<div class="slk-sizeguide__table-col">
				<?php foreach ( $slk_sizeguide_tabs as $slk_tab_key => $slk_tab_label ) : ?>
					<div id="slk-tabpanel-<?php echo esc_attr( $slk_tab_key ); ?>"
						class="slk-sizeguide__panel<?php echo ( 'abayas' !== $slk_tab_key ) ? ' slk-sr-only' : ''; ?>"
						role="tabpanel"
						aria-labelledby="slk-tab-<?php echo esc_attr( $slk_tab_key ); ?>"
						<?php echo ( 'abayas' !== $slk_tab_key ) ? 'hidden' : ''; ?>
					>
						<div class="slk-sizeguide__table-wrap">
							<table class="slk-sizeguide__table">
								<caption class="slk-sr-only">
									<?php
									/* translators: %s: category name, e.g. "Abayas". */
									printf( esc_html__( 'Measurements in centimetres for %s, sizes S to XL', 'slk' ), esc_html( $slk_tab_label ) );
									?>
								</caption>
								<thead>
									<tr>
										<th scope="col"><span class="slk-sr-only"><?php esc_html_e( 'Measurement', 'slk' ); ?></span></th>
										<th scope="col">S</th>
										<th scope="col" class="is-mid">M</th>
										<th scope="col">L</th>
										<th scope="col">XL</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $slk_sizeguide_rows as $slk_row ) : ?>
										<tr>
											<th scope="row"><?php echo esc_html( $slk_row['label'] ); ?></th>
											<?php foreach ( $slk_row['values'] as $slk_i => $slk_v ) : ?>
												<td class="<?php echo ( 1 === $slk_i ) ? 'is-mid' : ''; ?>"><?php echo esc_html( $slk_v ); ?></td>
											<?php endforeach; ?>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="slk-sizeguide__side">
				<span class="slk-eyebrow slk-sizeguide__howto-h"><?php esc_html_e( 'How to measure', 'slk' ); ?></span>
				<div class="slk-sizeguide__howto">
					<?php foreach ( $slk_sizeguide_howto as $slk_step ) : ?>
						<div class="slk-panel slk-sizeguide__howto-card">
							<span class="slk-sizeguide__howto-n"><?php echo esc_html( $slk_step['n'] ); ?></span>
							<span class="slk-sizeguide__howto-t">
								<b><?php echo esc_html( $slk_step['l'] ); ?></b>: <?php echo esc_html( $slk_step['t'] ); ?>
							</span>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="slk-sizeguide__wa-card">
					<div class="slk-sizeguide__wa-h"><?php esc_html_e( 'Between two sizes?', 'slk' ); ?></div>
					<p class="slk-sizeguide__wa-p">
						<?php
						printf(
							/* translators: %d: number of days to start an exchange. */
							esc_html__( 'Send us your bust and height on WhatsApp and we will measure the actual piece before it ships. If it is still wrong, you can exchange it within %d days.', 'slk' ),
							function_exists( 'slk_exchange_window_days' ) ? (int) slk_exchange_window_days() : 7
						);
						?>
					</p>
					<?php if ( $slk_sizeguide_wa ) : ?>
						<a class="slk-btn slk-btn--secondary slk-sizeguide__wa-btn" href="<?php echo esc_url( $slk_sizeguide_wa ); ?>">
							<?php esc_html_e( 'W · Ask about sizing', 'slk' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</div>

		</div>

	</div>
</div>

<?php get_footer(); ?>
