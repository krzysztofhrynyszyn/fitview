<?php
/**
 * FitView SaaS API client.
 *
 * Communicates with the FitView SaaS backend instead of calling fal.ai directly.
 * The fal.ai API key lives only on the SaaS server — never in the plugin.
 *
 * @package FitView
 */

namespace FitView;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Thin client for the FitView SaaS backend.
 *
 * Reads fitview_api_key and fitview_backend_url from WordPress options.
 * All requests carry an X-FitView-Key header; the backend validates it
 * and proxies the request to fal.ai using its own server-side API key.
 */
class Api {

    /** @var string FitView shop API key (fv_live_xxxxx) */
    private string $api_key;

    /** @var string FitView backend base URL, no trailing slash */
    private string $backend_url;

    public function __construct() {
        $this->api_key     = (string) \get_option( 'fitview_api_key', '' );
        $this->backend_url = \rtrim( (string) \get_option( 'fitview_backend_url', '' ), '/' );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function is_configured(): bool {
        return ! empty( $this->api_key ) && ! empty( $this->backend_url );
    }

    private function endpoint( string $path ): string {
        return $this->backend_url . '/api/v1/' . \ltrim( $path, '/' );
    }

    private function plugin_headers(): array {
        return [
            'X-FitView-Key' => $this->api_key,
            'Content-Type'  => 'application/json',
        ];
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Submit a try-on job to the FitView backend.
     *
     * Sends the full product image gallery so the backend (Claude Vision)
     * can automatically choose the best clothing image.
     *
     * @param string $user_photo_url  Public URL of the user's uploaded photo.
     * @param int    $product_id      WooCommerce product ID.
     * @return array{success: bool, job_id?: string, result_url?: string, error?: string}
     */
    public function run_tryon( string $user_photo_url, int $product_id ): array {
        if ( ! $this->is_configured() ) {
            return [ 'success' => false, 'error' => 'missing_config' ];
        }

        $gallery_urls = [];

        // Główne zdjęcie produktu jako pierwsze
        $main_image_id = \get_post_thumbnail_id( $product_id );
        if ( $main_image_id ) {
            $main_url = \wp_get_attachment_url( $main_image_id );
            if ( $main_url ) {
                $gallery_urls[] = $main_url;
            }
        }

        // Pobierz obiekt produktu
        $product = \wc_get_product( $product_id );
        if ( $product ) {
            // Pobierz IDs zdjęć z galerii produktu
            $gallery_image_ids = $product->get_gallery_image_ids();

            \error_log( '[FitView] Gallery image IDs for product ' .
                $product_id . ': ' . implode( ', ', $gallery_image_ids ) );

            foreach ( $gallery_image_ids as $img_id ) {
                $url = \wp_get_attachment_url( $img_id );
                if ( $url && ! in_array( $url, $gallery_urls, true ) ) {
                    $gallery_urls[] = $url;
                }
            }
        }

        \error_log( '[FitView] Total images collected: ' .
            count( $gallery_urls ) . ' — ' . implode( ', ', $gallery_urls ) );

        $categories    = \wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'names' ] );
        $category_name = ! empty( $categories ) && ! \is_wp_error( $categories )
            ? $categories[0]
            : '';

        $response = \wp_remote_post(
            $this->endpoint( 'tryon' ),
            [
                'timeout' => 60,
                'headers' => $this->plugin_headers(),
                'body'    => \wp_json_encode( [
                    'person_image_url'   => $user_photo_url,
                    'clothing_image_url' => $gallery_urls[0] ?? '',
                    'clothing_images'    => $gallery_urls,
                    'product_id'         => (string) $product_id,
                    'category_name'      => $category_name,
                ] ),
            ]
        );

        if ( \is_wp_error( $response ) ) {
            \error_log( '[FitView] Backend connection error: ' . $response->get_error_message() );
            return [ 'success' => false, 'error' => 'connection_error' ];
        }

        $http_code = (int) \wp_remote_retrieve_response_code( $response );
        $body      = \wp_remote_retrieve_body( $response );
        $data      = \json_decode( $body, true );
        \error_log( '[FitView] Job submit — HTTP ' . $http_code . ': ' . $body );

        if ( $http_code === 429 ) {
            return [ 'success' => false, 'error' => 'limit_exceeded' ];
        }

        if ( $http_code === 401 || $http_code === 403 ) {
            return [ 'success' => false, 'error' => 'missing_config' ];
        }

        if ( $http_code >= 400 ) {
            \error_log( '[FitView] Backend error ' . $http_code . ': ' . $body );
            if ( isset( $data['error'] ) && $data['error'] === 'invalid_photo' ) {
                return [ 'success' => false, 'error' => 'invalid_photo' ];
            }
            return [ 'success' => false, 'error' => 'api_error' ];
        }

        // Async (202): backend queued the job, returns request_id.
        if ( ! empty( $data['request_id'] ) ) {
            \error_log( '[FitView] Job submitted, request_id: ' . $data['request_id'] );
            return [ 'success' => true, 'job_id' => \sanitize_text_field( $data['request_id'] ) ];
        }

        // Sync (200): backend returned result immediately.
        if ( ! empty( $data['result_url'] ) ) {
            return [ 'success' => true, 'result_url' => \esc_url_raw( $data['result_url'] ) ];
        }

        \error_log( '[FitView] Backend unexpected response: ' . $body );
        return [ 'success' => false, 'error' => 'unexpected_response' ];
    }

    /**
     * Check the status of a queued job via the FitView backend.
     *
     * The backend proxies the status check to fal.ai and returns the result.
     *
     * @param string $job_id  The fal.ai request_id returned by run_tryon().
     * @return array{success: bool, status?: string, result_url?: string, error?: string}
     */
    public function check_status( string $job_id ): array {
        if ( ! $this->is_configured() ) {
            return [ 'success' => false, 'error' => 'missing_config' ];
        }

        $url = $this->endpoint( 'status/' . \rawurlencode( $job_id ) );
        \error_log( '[FitView] Checking status for job: ' . $job_id );
        \error_log( '[FitView] Status URL: ' . $url );

        $response = \wp_remote_get(
            $url,
            [
                'timeout' => 30,
                'headers' => [ 'X-FitView-Key' => $this->api_key ],
            ]
        );

        if ( \is_wp_error( $response ) ) {
            \error_log( '[FitView] Status check error: ' . $response->get_error_message() );
            return [ 'success' => false, 'error' => 'connection_error' ];
        }

        $http_code = (int) \wp_remote_retrieve_response_code( $response );
        $body      = \wp_remote_retrieve_body( $response );
        $data      = \json_decode( $body, true );
        \error_log( '[FitView] Status response code: ' . $http_code );
        \error_log( '[FitView] Status response body: ' . $body );

        if ( $http_code >= 400 ) {
            \error_log( '[FitView] Status error ' . $http_code . ': ' . $body );
            return [ 'success' => false, 'error' => 'api_error' ];
        }

        $status = \sanitize_text_field( $data['status'] ?? 'UNKNOWN' );

        if ( $status === 'COMPLETED' ) {
            \error_log( '[FitView] Job completed, result_url: ' . ( $data['result_url'] ?? '' ) );
            return [
                'success'    => true,
                'status'     => 'COMPLETED',
                'result_url' => \esc_url_raw( $data['result_url'] ?? '' ),
            ];
        }

        if ( $status === 'FAILED' ) {
            \error_log( '[FitView] Job failed' );
            return [ 'success' => true, 'status' => 'FAILED' ];
        }

        // IN_QUEUE / IN_PROGRESS — caller should poll again.
        return [ 'success' => true, 'status' => $status ];
    }

    /**
     * Test connectivity and key validity against the FitView backend.
     *
     * Calls GET /api/v1/ping which returns shop name on success.
     *
     * @return array{success: bool, message: string}
     */
    public function test_connection(): array {
        if ( empty( $this->api_key ) ) {
            return [
                'success' => false,
                'message' => \__( 'Brak klucza API FitView (format: fv_live_…).', 'fitview' ),
            ];
        }

        if ( empty( $this->backend_url ) ) {
            return [
                'success' => false,
                'message' => \__( 'Brak URL backendu FitView.', 'fitview' ),
            ];
        }

        $response = \wp_remote_get(
            $this->endpoint( 'ping' ),
            [
                'timeout' => 10,
                'headers' => [ 'X-FitView-Key' => $this->api_key ],
            ]
        );

        if ( \is_wp_error( $response ) ) {
            return [
                'success' => false,
                'message' => \sprintf(
                    /* translators: %s: error message */
                    \__( 'Błąd połączenia z backendem: %s', 'fitview' ),
                    $response->get_error_message()
                ),
            ];
        }

        $code = (int) \wp_remote_retrieve_response_code( $response );
        $data = \json_decode( \wp_remote_retrieve_body( $response ), true );

        if ( $code === 200 && ! empty( $data['ok'] ) ) {
            $shop_name = \sanitize_text_field( $data['shop'] ?? '' );
            return [
                'success' => true,
                'message' => \sprintf(
                    /* translators: %s: shop name */
                    \__( 'Połączenie OK ✓  (sklep: %s)', 'fitview' ),
                    $shop_name
                ),
            ];
        }

        if ( $code === 401 || $code === 403 ) {
            return [
                'success' => false,
                'message' => \__( 'Nieprawidłowy klucz API FitView. Sprawdź klucz w panelu SaaS.', 'fitview' ),
            ];
        }

        return [
            'success' => false,
            /* translators: %d: HTTP status code */
            'message' => \sprintf( \__( 'Błąd połączenia (HTTP %d). Sprawdź URL backendu.', 'fitview' ), $code ),
        ];
    }
}
