<?php
/**
 * Main plugin class — frontend rendering and asset management.
 *
 * @package FitView
 */

namespace FitView;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers WooCommerce hooks, enqueues assets and renders the try-on UI.
 */
class Plugin {

    /**
     * Register all frontend hooks.
     */
    public function init(): void {
        \add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        \add_action( 'woocommerce_single_product_summary', [ $this, 'render_tryon_button' ], 25 );
        \add_action( 'wp_footer', [ $this, 'render_modal' ], 99 );
        \add_action( 'fitview_tryon_completed', [ $this, 'clear_limit_cache' ] );
    }

    /**
     * Check whether FitView should be active for the current product.
     * Returns false when: not a product page, credentials missing, or product
     * is not in the configured category whitelist.
     */
    private function is_fitview_active_for_product(): bool {
        if ( ! \is_product() ) {
            return false;
        }

        if ( empty( \get_option( 'fitview_api_key', '' ) ) || empty( \get_option( 'fitview_backend_url', '' ) ) ) {
            return false;
        }

        $enabled_cats = (array) \get_option( 'fitview_enabled_categories', [] );
        if ( ! empty( $enabled_cats ) ) {
            $product_cats = \wp_get_post_terms( \get_the_ID(), 'product_cat', [ 'fields' => 'ids' ] );
            if ( \is_wp_error( $product_cats ) || empty( \array_intersect( $product_cats, $enabled_cats ) ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check whether the shop's visualisation limit is reached.
     * Result is cached in a 5-minute transient to avoid an HTTP call on every page load.
     */
    private function is_limit_reached(): bool {
        $cached = \get_transient( 'fitview_limit_status' );
        if ( $cached !== false ) {
            return $cached === 'limit_reached';
        }
        $api_key     = (string) \get_option( 'fitview_api_key', '' );
        $backend_url = \rtrim( (string) \get_option( 'fitview_backend_url', '' ), '/' );
        if ( ! $api_key || ! $backend_url ) {
            return false;
        }
        $response = \wp_remote_get(
            $backend_url . '/api/v1/ping',
            [ 'timeout' => 5, 'headers' => [ 'X-FitView-Key' => $api_key ] ]
        );
        if ( \is_wp_error( $response ) ) {
            return false;
        }
        $http_code = (int) \wp_remote_retrieve_response_code( $response );
        $data      = \json_decode( \wp_remote_retrieve_body( $response ), true );

        if ( $http_code === 403 && ( $data['error'] ?? '' ) === 'limit_reached' ) {
            \set_transient( 'fitview_limit_status', 'limit_reached', 1 * MINUTE_IN_SECONDS );
            return true;
        }

        $status = $data['status'] ?? 'active';
        \set_transient( 'fitview_limit_status', $status, 1 * MINUTE_IN_SECONDS );
        return $status === 'limit_reached';
    }

    public function clear_limit_cache(): void {
        \delete_transient( 'fitview_limit_status' );
    }

    /**
     * Enqueue CSS and JS only on single product pages.
     */
    public function enqueue_assets(): void {
        if ( ! $this->is_fitview_active_for_product() ) {
            return;
        }
        if ( $this->is_limit_reached() ) {
            return;
        }

        \wp_enqueue_style(
            'tabler-icons',
            'https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.31.0/tabler-icons.min.css',
            [],
            null
        );

        \wp_enqueue_style(
            'fitview-frontend',
            FITVIEW_PLUGIN_URL . 'assets/css/fitview-frontend.css',
            [],
            (string) \filemtime( FITVIEW_PLUGIN_DIR . 'assets/css/fitview-frontend.css' )
        );

        \wp_enqueue_script(
            'fitview-frontend',
            FITVIEW_PLUGIN_URL . 'assets/js/fitview-frontend.js',
            [],
            (string) \filemtime( FITVIEW_PLUGIN_DIR . 'assets/js/fitview-frontend.js' ),
            true
        );

        $product_image_url = '';
        $thumbnail_id      = \get_post_thumbnail_id( \get_the_ID() );
        if ( $thumbnail_id ) {
            $product_image_url = (string) \wp_get_attachment_url( $thumbnail_id );
        }

        \wp_localize_script(
            'fitview-frontend',
            'fitviewData',
            [
                'restUrl'      => \rest_url( 'fitview/v1/' ),
                'nonce'        => \wp_create_nonce( 'wp_rest' ),
                'productId'    => \get_the_ID(),
                'productImage' => \esc_url_raw( $product_image_url ),
                'shopMessages'  => \array_values( \array_filter( [
                    \get_option( 'fitview_msg_1', '' ),
                    \get_option( 'fitview_msg_2', '' ),
                    \get_option( 'fitview_msg_3', '' ),
                ] ) ),
                'carouselTitle' => \get_option( 'fitview_carousel_title', 'Może Cię zainteresować' ),
                'fabPosition'   => \get_option( 'fitview_fab_position', 'right' ),
            ]
        );
    }

    /**
     * Render the FitView strip CTA below the "Add to cart" button.
     *
     * Hook priority 25 places it after the price (20) and before the excerpt (30).
     */
    public function render_tryon_button(): void {
        if ( ! $this->is_fitview_active_for_product() ) {
            return;
        }
        if ( $this->is_limit_reached() ) {
            return;
        }
        if ( ! \get_option( 'fitview_show_strip', true ) ) {
            return;
        }
        ?>
        <div
            class="fv-strip"
            id="fv-strip-cta"
            role="button"
            tabindex="0"
            aria-label="<?php \esc_attr_e( 'Otwórz Fito — wirtualną przymierzalnię', 'fitview' ); ?>"
        >
            <svg class="fv-strip-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                <path d="M20 6.5H4C3.17 6.5 2.5 7.17 2.5 8v1c0 .83.67 1.5 1.5 1.5h.5v7a1 1 0 001 1h13a1 1 0 001-1v-7h.5c.83 0 1.5-.67 1.5-1.5V8c0-.83-.67-1.5-1.5-1.5zM17 17H7v-6.5h10V17z" fill="currentColor"/>
                <path d="M19 3.5l1.5 1.5M5 3.5L3.5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
            <span class="fv-strip-text">
                <strong><?php \esc_html_e( 'Fito', 'fitview' ); ?></strong>
                &mdash; <?php \esc_html_e( 'Przymierz wirtualnie', 'fitview' ); ?>
            </span>
            <span class="fv-strip-badge"><?php \esc_html_e( 'AI', 'fitview' ); ?></span>
        </div>
        <?php
    }

    /**
     * Inject the modal HTML into the page footer (only on product pages).
     */
    public function render_modal(): void {
        if ( ! $this->is_fitview_active_for_product() ) {
            return;
        }
        if ( $this->is_limit_reached() ) {
            return;
        }

        $template = FITVIEW_PLUGIN_DIR . 'templates/modal.php';
        if ( \file_exists( $template ) ) {
            include $template;
        }
    }
}
