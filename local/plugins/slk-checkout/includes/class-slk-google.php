<?php
/**
 * Google sign in for checkout, backed by the "Login with Google" plugin
 * (rtCamp, slug login-with-google — see local/setup-plugins.sh).
 *
 * That plugin is installed and ACTIVE but ships with no OAuth client, because
 * the client id and secret can only come from Najath's own Google Cloud
 * console project. Until she enters them, this class must behave as if
 * Google sign in does not exist at all: available() reads the plugin's own
 * stored settings (never a guessed constant) and button() returns '' when
 * unconfigured, so callers can print it unconditionally without ever risking
 * a button that leads nowhere.
 *
 * Behavioural layer only — no markup styling here. button() returns bare
 * markup with our own class names; themes/slk-child/inc/checkout-view.php
 * styles it.
 *
 * @package slk
 */

defined( 'ABSPATH' ) || exit;

final class SLK_Google {

	/** The plugin file WordPress keys activation state by. */
	private const PLUGIN_FILE = 'login-with-google/login-with-google.php';

	/** Option the plugin stores its own settings under (Settings.php). */
	private const SETTINGS_OPTION = 'wp_google_login_settings';

	/**
	 * True only when the plugin is active AND a client id is configured.
	 *
	 * Read straight from the plugin's own stored option
	 * (`wp_google_login_settings['client_id']`, as written by its own
	 * settings screen) rather than a constant we would otherwise have to
	 * guess the name of.
	 */
	public static function available(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active( self::PLUGIN_FILE ) ) {
			return false;
		}

		$settings  = get_option( self::SETTINGS_OPTION, array() );
		$client_id = is_array( $settings ) ? ( $settings['client_id'] ?? '' ) : '';

		return '' !== trim( (string) $client_id );
	}

	/**
	 * Markup for the "Continue with Google" button, styled with our own
	 * classes, pointing at the plugin's own OAuth authorisation URL.
	 *
	 * Returns '' whenever available() is false, so a caller can print the
	 * result unconditionally and never end up with a dead button — the
	 * plugin's authorisation flow only works once Najath has entered a real
	 * client id and secret.
	 *
	 * @param string $redirect Optional URL to return to after a successful
	 *                         sign in. Passed straight into the plugin's own
	 *                         OAuth state via its Helper, the same mechanism
	 *                         its own [google_login] shortcode uses.
	 */
	public static function button( string $redirect = '' ): string {
		if ( ! self::available() ) {
			return '';
		}

		if ( ! function_exists( '\RtCamp\GoogleLogin\plugin' ) ) {
			return '';
		}

		try {
			$client = \RtCamp\GoogleLogin\plugin()->container()->get( 'gh_client' );
		} catch ( \Throwable $e ) {
			return '';
		}

		if ( ! is_object( $client ) || ! method_exists( $client, 'authorization_url' ) ) {
			return '';
		}

		$has_redirect_helper = '' !== $redirect
			&& class_exists( '\RtCamp\GoogleLogin\Utils\Helper' )
			&& method_exists( '\RtCamp\GoogleLogin\Utils\Helper', 'set_redirect_state_filter' );

		if ( $has_redirect_helper ) {
			\RtCamp\GoogleLogin\Utils\Helper::set_redirect_state_filter( $redirect );
		}

		$authorize_url = $client->authorization_url();

		if ( $has_redirect_helper ) {
			\RtCamp\GoogleLogin\Utils\Helper::remove_redirect_state_filter();
		}

		if ( '' === trim( (string) $authorize_url ) ) {
			return '';
		}

		return sprintf(
			'<a class="slk-google-button" href="%1$s"><span class="slk-google-button__icon" aria-hidden="true"></span><span class="slk-google-button__text">%2$s</span></a>',
			esc_url( $authorize_url ),
			esc_html__( 'Continue with Google', 'slk' )
		);
	}
}
