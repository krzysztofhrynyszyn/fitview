<?php
/**
 * Optional OAuth authentication via Google and Meta.
 *
 * Enabled only when fitview_enable_accounts = '1'.
 * Requires fitview_google_client_id / fitview_google_client_secret
 * and fitview_meta_app_id / fitview_meta_app_secret to be set in wp_options.
 *
 * @package FitView
 */

namespace FitView;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles Google and Meta OAuth flows for optional FitView user accounts.
 *
 * This implementation provides the full flow structure. To activate it:
 *  1. Enable accounts in WooCommerce → FitView settings.
 *  2. Create a Google OAuth app and a Meta/Facebook app.
 *  3. Store their credentials in wp_options (fitview_google_client_id, etc.).
 *  4. Add the callback URL to each provider's allowed redirect URIs.
 */
class Auth {

    /** @var string Query parameter used to identify the OAuth callback */
    private const CALLBACK_PARAM = 'fitview_oauth';

    /**
     * Register hooks only when the feature is enabled.
     */
    public function init(): void {
        if ( \get_option( 'fitview_enable_accounts', '0' ) !== '1' ) {
            return;
        }

        \add_action( 'wp_ajax_fitview_oauth_init',        [ $this, 'handle_oauth_init' ] );
        \add_action( 'wp_ajax_nopriv_fitview_oauth_init', [ $this, 'handle_oauth_init' ] );
        \add_action( 'init', [ $this, 'handle_oauth_callback' ] );
    }

    /**
     * AJAX: return the provider authorization URL so the frontend can redirect.
     */
    public function handle_oauth_init(): void {
        \check_ajax_referer( 'fitview_oauth_nonce', 'nonce' );

        $provider = \sanitize_text_field( \wp_unslash( $_POST['provider'] ?? '' ) );

        if ( ! \in_array( $provider, [ 'google', 'meta' ], true ) ) {
            \wp_send_json_error( [ 'message' => \__( 'Nieprawidłowy dostawca autoryzacji.', 'fitview' ) ] );
            return;
        }

        $auth_url = $this->build_auth_url( $provider );
        if ( ! $auth_url ) {
            \wp_send_json_error( [
                'message' => \sprintf(
                    /* translators: %s: provider name */
                    \__( 'Autoryzacja przez %s nie jest skonfigurowana.', 'fitview' ),
                    \ucfirst( $provider )
                ),
            ] );
            return;
        }

        \wp_send_json_success( [ 'redirect_url' => $auth_url ] );
    }

    /**
     * Handle the OAuth provider callback (redirected back to our site).
     *
     * Listens for ?fitview_oauth=google|meta&code=...&state=...
     */
    public function handle_oauth_callback(): void {
        $provider = \sanitize_text_field( \wp_unslash( $_GET[ self::CALLBACK_PARAM ] ?? '' ) );

        if ( ! \in_array( $provider, [ 'google', 'meta' ], true ) ) {
            return;
        }

        $code  = \sanitize_text_field( \wp_unslash( $_GET['code'] ?? '' ) );
        $state = \sanitize_text_field( \wp_unslash( $_GET['state'] ?? '' ) );

        if ( empty( $code ) ) {
            \wp_safe_redirect( \wc_get_page_permalink( 'shop' ) );
            exit;
        }

        // CSRF protection: verify the state parameter.
        $state_key    = 'fitview_oauth_state_' . \md5( $provider );
        $stored_state = \get_transient( $state_key );

        if ( ! $stored_state || ! \hash_equals( (string) $stored_state, $state ) ) {
            \wp_safe_redirect( \wc_get_page_permalink( 'shop' ) );
            exit;
        }

        \delete_transient( $state_key );

        $user_data = $this->exchange_code_for_profile( $provider, $code );
        if ( ! $user_data ) {
            \wp_safe_redirect( \wc_get_page_permalink( 'shop' ) );
            exit;
        }

        $this->authenticate_user( $user_data, $provider );

        \wp_safe_redirect( \wc_get_page_permalink( 'shop' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the OAuth authorization URL for the given provider.
     *
     * @param string $provider 'google' | 'meta'
     * @return string|null  Full authorization URL, or null if credentials are missing.
     */
    private function build_auth_url( string $provider ): ?string {
        $state = \wp_generate_password( 32, false );
        \set_transient( 'fitview_oauth_state_' . \md5( $provider ), $state, 10 * MINUTE_IN_SECONDS );

        $callback_url = \esc_url_raw( \add_query_arg( self::CALLBACK_PARAM, $provider, \home_url( '/' ) ) );

        if ( $provider === 'google' ) {
            $client_id = (string) \get_option( 'fitview_google_client_id', '' );
            if ( empty( $client_id ) ) {
                return null;
            }
            return 'https://accounts.google.com/o/oauth2/v2/auth?' . \http_build_query( [
                'client_id'     => $client_id,
                'redirect_uri'  => $callback_url,
                'response_type' => 'code',
                'scope'         => 'openid email profile',
                'state'         => $state,
                'access_type'   => 'online',
            ] );
        }

        if ( $provider === 'meta' ) {
            $app_id = (string) \get_option( 'fitview_meta_app_id', '' );
            if ( empty( $app_id ) ) {
                return null;
            }
            return 'https://www.facebook.com/v18.0/dialog/oauth?' . \http_build_query( [
                'client_id'     => $app_id,
                'redirect_uri'  => $callback_url,
                'response_type' => 'code',
                'scope'         => 'email,public_profile',
                'state'         => $state,
            ] );
        }

        return null;
    }

    /**
     * Exchange an authorization code for the user's profile data.
     *
     * @param string $provider 'google' | 'meta'
     * @param string $code     Authorization code from the provider.
     * @return array{email: string, name: string}|null
     */
    private function exchange_code_for_profile( string $provider, string $code ): ?array {
        $callback_url = \esc_url_raw( \add_query_arg( self::CALLBACK_PARAM, $provider, \home_url( '/' ) ) );

        if ( $provider === 'google' ) {
            return $this->google_token_exchange( $code, $callback_url );
        }

        if ( $provider === 'meta' ) {
            return $this->meta_token_exchange( $code, $callback_url );
        }

        return null;
    }

    /**
     * Google token exchange and profile fetch.
     *
     * @param string $code
     * @param string $callback_url
     * @return array{email: string, name: string}|null
     */
    private function google_token_exchange( string $code, string $callback_url ): ?array {
        $client_id     = (string) \get_option( 'fitview_google_client_id', '' );
        $client_secret = (string) \get_option( 'fitview_google_client_secret', '' );

        if ( empty( $client_id ) || empty( $client_secret ) ) {
            return null;
        }

        $token_response = \wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'timeout' => 15,
            'body'    => [
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => $callback_url,
                'grant_type'    => 'authorization_code',
            ],
        ] );

        if ( \is_wp_error( $token_response ) ) {
            return null;
        }

        $token_data   = \json_decode( \wp_remote_retrieve_body( $token_response ), true );
        $access_token = $token_data['access_token'] ?? '';

        if ( empty( $access_token ) ) {
            return null;
        }

        $profile_response = \wp_remote_get( 'https://www.googleapis.com/oauth2/v2/userinfo', [
            'timeout' => 15,
            'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
        ] );

        if ( \is_wp_error( $profile_response ) ) {
            return null;
        }

        $profile = \json_decode( \wp_remote_retrieve_body( $profile_response ), true );

        return [
            'email' => \sanitize_email( $profile['email'] ?? '' ),
            'name'  => \sanitize_text_field( $profile['name'] ?? '' ),
        ];
    }

    /**
     * Meta/Facebook token exchange and profile fetch.
     *
     * @param string $code
     * @param string $callback_url
     * @return array{email: string, name: string}|null
     */
    private function meta_token_exchange( string $code, string $callback_url ): ?array {
        $app_id     = (string) \get_option( 'fitview_meta_app_id', '' );
        $app_secret = (string) \get_option( 'fitview_meta_app_secret', '' );

        if ( empty( $app_id ) || empty( $app_secret ) ) {
            return null;
        }

        $token_url = \add_query_arg( [
            'client_id'     => $app_id,
            'redirect_uri'  => \rawurlencode( $callback_url ),
            'client_secret' => $app_secret,
            'code'          => $code,
        ], 'https://graph.facebook.com/v18.0/oauth/access_token' );

        $token_response = \wp_remote_get( $token_url, [ 'timeout' => 15 ] );

        if ( \is_wp_error( $token_response ) ) {
            return null;
        }

        $token_data   = \json_decode( \wp_remote_retrieve_body( $token_response ), true );
        $access_token = $token_data['access_token'] ?? '';

        if ( empty( $access_token ) ) {
            return null;
        }

        $profile_url = \add_query_arg(
            [ 'fields' => 'email,name', 'access_token' => $access_token ],
            'https://graph.facebook.com/me'
        );

        $profile_response = \wp_remote_get( $profile_url, [ 'timeout' => 15 ] );

        if ( \is_wp_error( $profile_response ) ) {
            return null;
        }

        $profile = \json_decode( \wp_remote_retrieve_body( $profile_response ), true );

        return [
            'email' => \sanitize_email( $profile['email'] ?? '' ),
            'name'  => \sanitize_text_field( $profile['name'] ?? '' ),
        ];
    }

    /**
     * Log in an existing WordPress user or create a new one from OAuth profile data.
     *
     * @param array{email: string, name: string} $user_data
     * @param string $provider  'google' | 'meta'
     */
    private function authenticate_user( array $user_data, string $provider ): void {
        $email = \sanitize_email( $user_data['email'] ?? '' );

        if ( ! \is_email( $email ) ) {
            return;
        }

        $user = \get_user_by( 'email', $email );

        if ( ! $user ) {
            $user_id = \wp_insert_user( [
                'user_login'   => \sanitize_user( $email, true ),
                'user_email'   => $email,
                'user_pass'    => \wp_generate_password( 24 ),
                'display_name' => \sanitize_text_field( $user_data['name'] ?? $email ),
                'role'         => 'customer',
            ] );

            if ( \is_wp_error( $user_id ) ) {
                \error_log( '[FitView] OAuth register error: ' . $user_id->get_error_message() );
                return;
            }

            \update_user_meta( $user_id, 'fitview_oauth_provider', $provider );
            $user = \get_user_by( 'id', $user_id );
        }

        if ( ! $user ) {
            return;
        }

        \wp_set_current_user( $user->ID );
        \wp_set_auth_cookie( $user->ID );
    }
}
