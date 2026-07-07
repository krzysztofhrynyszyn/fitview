<?php
/**
 * WP REST API endpoints for FitView.
 *
 * @package FitView
 */

namespace FitView;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers /fitview/v1/tryon and /fitview/v1/status/{job_id} endpoints.
 *
 * Security:
 *  - Every public endpoint requires a valid WP REST nonce.
 *  - User photos are uploaded to WP uploads dir (for a public URL) and deleted when the job completes.
 *  - Rate limiting is enforced via WordPress transients (10 req / user / hour).
 */
class Rest {

    /** @var int Maximum accepted upload size in bytes (10 MB) */
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    /** @var int Maximum requests allowed per user per hour */
    private const RATE_LIMIT = 10;

    /**
     * Register REST API hooks.
     */
    public function init(): void {
        \add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /**
     * Register all FitView REST routes.
     */
    public function register_routes(): void {
        \register_rest_route(
            'fitview/v1',
            '/tryon',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'handle_tryon' ],
                'permission_callback' => [ $this, 'verify_nonce' ],
            ]
        );

        \register_rest_route(
            'fitview/v1',
            '/status/(?P<job_id>[a-zA-Z0-9\-_]+)',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'handle_status' ],
                'permission_callback' => [ $this, 'verify_nonce' ],
                'args'                => [
                    'job_id' => [
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => static function ( $value ) {
                            return (bool) \preg_match( '/^[a-zA-Z0-9\-_]{1,128}$/', $value );
                        },
                    ],
                ],
            ]
        );

        \register_rest_route(
            'fitview/v1',
            '/test-connection',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'handle_test_connection' ],
                'permission_callback' => static function () {
                    return \current_user_can( 'manage_woocommerce' );
                },
            ]
        );

        \register_rest_route(
            'fitview/v1',
            '/carousel-products',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_carousel_products' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'product_id' => [
                        'required'          => false,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    /**
     * Verify the WP REST nonce sent via X-WP-Nonce header.
     *
     * Works for both logged-in and guest users because wp_create_nonce / wp_verify_nonce
     * are session-based and provide CSRF protection for unauthenticated visitors.
     *
     * @param \WP_REST_Request $request
     * @return true|\WP_Error
     */
    public function verify_nonce( \WP_REST_Request $request ) {
        $nonce = $request->get_header( 'X-WP-Nonce' );

        if ( empty( $nonce ) || ! \wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new \WP_Error(
                'rest_forbidden',
                \__( 'Nieprawidłowy token bezpieczeństwa.', 'fitview' ),
                [ 'status' => 403 ]
            );
        }

        return true;
    }

    /**
     * Handle POST /fitview/v1/tryon
     *
     * Accepts a multipart upload with user_photo (file) and product_id (int).
     * Validates the file, uploads to WP uploads dir for a public URL, calls fal.ai,
     * and returns job_id (async) or result_url (sync). The uploaded file is deleted when the job completes.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_tryon( \WP_REST_Request $request ) {
        // Rate limit check before any expensive work.
        $rate_check = $this->check_rate_limit();
        if ( \is_wp_error( $rate_check ) ) {
            return $rate_check;
        }

        $files = $request->get_file_params();

        if ( empty( $files['user_photo'] ) || (int) $files['user_photo']['error'] !== UPLOAD_ERR_OK ) {
            return new \WP_Error(
                'no_photo',
                \__( 'Brak zdjęcia użytkownika.', 'fitview' ),
                [ 'status' => 400 ]
            );
        }

        $file = $files['user_photo'];

        // Size validation.
        if ( (int) $file['size'] > self::MAX_FILE_SIZE ) {
            return new \WP_Error(
                'file_too_large',
                \__( 'Zdjęcie jest za duże. Maksymalny rozmiar to 10 MB.', 'fitview' ),
                [ 'status' => 413 ]
            );
        }

        // Extension validation via WordPress whitelist.
        $file_type = \wp_check_filetype(
            $file['name'],
            [ 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png' ]
        );

        if ( empty( $file_type['ext'] ) ) {
            return new \WP_Error(
                'invalid_file_type',
                \__( 'Dodaj zdjęcie w formacie JPG lub PNG.', 'fitview' ),
                [ 'status' => 415 ]
            );
        }

        // MIME type validation — checks actual file content, not just the extension.
        $actual_mime = (string) \mime_content_type( $file['tmp_name'] );
        if ( ! \in_array( $actual_mime, [ 'image/jpeg', 'image/png' ], true ) ) {
            return new \WP_Error(
                'invalid_mime_type',
                \__( 'Dodaj zdjęcie w formacie JPG lub PNG.', 'fitview' ),
                [ 'status' => 415 ]
            );
        }

        // Product validation.
        $product_id = \absint( $request->get_param( 'product_id' ) );
        if ( ! $product_id || \get_post_type( $product_id ) !== 'product' ) {
            return new \WP_Error(
                'invalid_product',
                \__( 'Nieprawidłowy produkt.', 'fitview' ),
                [ 'status' => 400 ]
            );
        }

        if ( ! \get_post_thumbnail_id( $product_id ) ) {
            return new \WP_Error(
                'no_product_image',
                \__( 'Produkt nie ma zdjęcia głównego.', 'fitview' ),
                [ 'status' => 400 ]
            );
        }

        // Upload to the WP uploads directory to obtain a publicly accessible URL.
        // fal-ai/image-apps-v2/virtual-try-on requires a URL, not base64.
        if ( ! \function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $upload = \wp_handle_upload(
            $file,
            [
                'test_form' => false,
                'test_type' => false,
            ]
        );

        if ( ! empty( $upload['error'] ) ) {
            return new \WP_Error(
                'upload_failed',
                \__( 'Błąd podczas przetwarzania pliku.', 'fitview' ),
                [ 'status' => 500 ]
            );
        }

        $user_photo_url  = $upload['url'];
        $user_photo_file = $upload['file'];

        $api    = new Api();
        $result = $api->run_tryon( $user_photo_url, $product_id );

        if ( ! $result['success'] ) {
            @\unlink( $user_photo_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return $this->api_error_to_wp_error( $result['error'] ?? 'unknown' );
        }

        $this->increment_rate_limit();

        // Async mode: store job metadata so handle_status() can clean up the file.
        if ( ! empty( $result['job_id'] ) ) {
            \error_log( '[FitView] Job submitted, request_id: ' . $result['job_id'] );
            \set_transient(
                'fitview_job_' . $result['job_id'],
                [
                    'product_id'      => $product_id,
                    'user_photo_file' => $user_photo_file,
                ],
                HOUR_IN_SECONDS
            );

            return new \WP_REST_Response(
                [ 'success' => true, 'job_id' => $result['job_id'] ],
                202
            );
        }

        // Sync mode: delete uploaded file and return the result URL directly.
        @\unlink( $user_photo_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        return new \WP_REST_Response(
            [ 'success' => true, 'result_url' => $result['result_url'] ],
            200
        );
    }

    /**
     * Handle GET /fitview/v1/status/{job_id}
     *
     * Proxies a status check to fal.ai and returns the current state.
     * Returns result_url when status is COMPLETED.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_status( \WP_REST_Request $request ) {
        $job_id = \sanitize_text_field( $request->get_param( 'job_id' ) );
        \error_log( '[FitView] Checking status for job: ' . $job_id );
        $stored = \get_transient( 'fitview_job_' . $job_id );

        // Ensure this job was created by our plugin (not a fishing attempt).
        if ( false === $stored ) {
            return new \WP_Error(
                'invalid_job',
                \__( 'Nieznane zadanie.', 'fitview' ),
                [ 'status' => 404 ]
            );
        }

        // Transient holds an array with product_id and the path to the user photo.
        $user_photo_file = \is_array( $stored ) ? ( $stored['user_photo_file'] ?? '' ) : '';

        $api    = new Api();
        $result = $api->check_status( $job_id );

        if ( ! $result['success'] ) {
            return $this->api_error_to_wp_error( $result['error'] ?? 'unknown' );
        }

        if ( $result['status'] === 'COMPLETED' ) {
            \delete_transient( 'fitview_job_' . $job_id );
            if ( $user_photo_file ) {
                @\unlink( $user_photo_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
            \do_action( 'fitview_tryon_completed' );
            return new \WP_REST_Response(
                [
                    'success'    => true,
                    'status'     => 'COMPLETED',
                    'result_url' => $result['result_url'] ?? '',
                ],
                200
            );
        }

        if ( $result['status'] === 'FAILED' ) {
            \delete_transient( 'fitview_job_' . $job_id );
            if ( $user_photo_file ) {
                @\unlink( $user_photo_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
            return new \WP_REST_Response(
                [ 'success' => false, 'status' => 'FAILED', 'error' => 'generation_failed' ],
                200
            );
        }

        return new \WP_REST_Response(
            [ 'success' => true, 'status' => $result['status'] ],
            200
        );
    }

    /**
     * Handle POST /fitview/v1/test-connection (admin only)
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function handle_test_connection( \WP_REST_Request $request ): \WP_REST_Response {
        $api    = new Api();
        $result = $api->test_connection();
        return new \WP_REST_Response( $result, $result['success'] ? 200 : 503 );
    }

    /**
     * Handle GET /fitview/v1/carousel-products
     *
     * Returns up to 8 random products from the configured carousel categories,
     * excluding the current product.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_carousel_products( \WP_REST_Request $request ): \WP_REST_Response {
        $product_id = \absint( $request->get_param( 'product_id' ) );
        $cat_ids    = \get_option( 'fitview_carousel_categories', [] );

        if ( empty( $cat_ids ) ) {
            return \rest_ensure_response( [] );
        }

        $args = [
            'post_type'      => 'product',
            'posts_per_page' => 8,
            'post_status'    => 'publish',
            'post__not_in'   => $product_id ? [ $product_id ] : [],
            'orderby'        => 'rand',
            'tax_query'      => [ [
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => \array_map( 'intval', (array) $cat_ids ),
                'operator' => 'IN',
            ] ],
        ];

        $query    = new \WP_Query( $args );
        $products = [];

        foreach ( $query->posts as $post ) {
            $product = \wc_get_product( $post->ID );
            if ( ! $product ) {
                continue;
            }

            $image_id  = $product->get_image_id();
            $image_url = $image_id
                ? \wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' )
                : \wc_placeholder_img_src( 'woocommerce_thumbnail' );

            $products[] = [
                'id'    => $post->ID,
                'name'  => $product->get_name(),
                'price' => \strip_tags( \wc_price( $product->get_price() ) ),
                'url'   => \get_permalink( $post->ID ),
                'image' => $image_url ?: '',
            ];
        }

        return \rest_ensure_response( $products );
    }

    // -------------------------------------------------------------------------
    // Rate limiting helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether the current user/IP has exceeded the rate limit.
     *
     * @return true|\WP_Error
     */
    private function check_rate_limit() {
        $count = (int) \get_transient( $this->rate_limit_key() );

        if ( $count >= self::RATE_LIMIT ) {
            return new \WP_Error(
                'rate_limit_exceeded',
                \__( 'Przekroczono limit zapytań. Spróbuj ponownie za godzinę.', 'fitview' ),
                [ 'status' => 429 ]
            );
        }

        return true;
    }

    /**
     * Increment the rate-limit counter for the current user/IP.
     */
    private function increment_rate_limit(): void {
        $key   = $this->rate_limit_key();
        $count = (int) \get_transient( $key );

        // Use set_transient with the full TTL each time to keep the 1-hour window sliding.
        \set_transient( $key, $count + 1, HOUR_IN_SECONDS );
    }

    /**
     * Build a unique transient key for rate limiting.
     *
     * Logged-in users are identified by user ID; guests by a hash of their IP.
     *
     * @return string
     */
    private function rate_limit_key(): string {
        if ( \is_user_logged_in() ) {
            return 'fitview_rate_user_' . \get_current_user_id();
        }

        $ip = \sanitize_text_field( \wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
        return 'fitview_rate_ip_' . \md5( $ip );
    }

    // -------------------------------------------------------------------------
    // Error mapping
    // -------------------------------------------------------------------------

    /**
     * Convert an internal API error code to a WP_Error with an appropriate HTTP status.
     *
     * @param string $error_code
     * @return \WP_Error
     */
    private function api_error_to_wp_error( string $error_code ): \WP_Error {
        $map = [
            'missing_api_key'     => [ \__( 'Serwis jest chwilowo niedostępny.', 'fitview' ), 503 ],
            'connection_error'    => [ \__( 'Serwis jest chwilowo niedostępny. Spróbuj za chwilę.', 'fitview' ), 503 ],
            'api_error'           => [ \__( 'Serwis jest chwilowo niedostępny. Spróbuj za chwilę.', 'fitview' ), 503 ],
            'invalid_photo'       => [ \__( 'Nieprawidłowe zdjęcie.', 'fitview' ), 400 ],
            'generation_failed'   => [ \__( 'Generowanie nie powiodło się. Spróbuj ponownie.', 'fitview' ), 500 ],
            'unexpected_response' => [ \__( 'Serwis jest chwilowo niedostępny. Spróbuj za chwilę.', 'fitview' ), 503 ],
            'limit_exceeded'      => [ \__( 'Sklep wyczerpał miesięczny limit wizualizacji.', 'fitview' ), 429 ],
            'missing_config'      => [ \__( 'Serwis jest chwilowo niedostępny.', 'fitview' ), 503 ],
            'photo_not_found'     => [ \__( 'Błąd przetwarzania zdjęcia.', 'fitview' ), 500 ],
            'cannot_read_photo'   => [ \__( 'Błąd przetwarzania zdjęcia.', 'fitview' ), 500 ],
        ];

        [ $message, $status ] = $map[ $error_code ] ?? [ \__( 'Nieznany błąd.', 'fitview' ), 500 ];

        return new \WP_Error( 'fitview_' . $error_code, $message, [ 'status' => $status ] );
    }
}
