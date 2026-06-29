<?php
/**
 * Plugin Name: FitView — Virtual Try-On
 * Plugin URI: https://fitview.app
 * Description: Wirtualna przymierzalnia AI dla WooCommerce. Klienci widzą jak będą wyglądać w ubraniu przed zakupem.
 * Version: 1.4.0
 * Author: FitView Team
 * License: GPL-2.0+
 * Text Domain: fitview
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FITVIEW_VERSION', '1.4.0' );
define( 'FITVIEW_PLUGIN_FILE', __FILE__ );
define( 'FITVIEW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FITVIEW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Show admin notice when WooCommerce is not active.
 */
add_action( 'admin_notices', static function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        echo '<div class="notice notice-error"><p>'
            . esc_html__( 'FitView wymaga aktywnej wtyczki WooCommerce. Aktywuj WooCommerce i spróbuj ponownie.', 'fitview' )
            . '</p></div>';
    }
} );

/**
 * Load and initialize all plugin classes after all plugins are loaded.
 */
add_action( 'plugins_loaded', static function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }

    require_once FITVIEW_PLUGIN_DIR . 'includes/class-fitview-api.php';
    require_once FITVIEW_PLUGIN_DIR . 'includes/class-fitview-plugin.php';
    require_once FITVIEW_PLUGIN_DIR . 'includes/class-fitview-rest.php';
    require_once FITVIEW_PLUGIN_DIR . 'includes/class-fitview-admin.php';
    require_once FITVIEW_PLUGIN_DIR . 'includes/class-fitview-auth.php';

    ( new FitView\Plugin() )->init();
    ( new FitView\Admin() )->init();
    ( new FitView\Rest() )->init();
    ( new FitView\Auth() )->init();
} );

/**
 * Activation: verify WooCommerce is present.
 */
register_activation_hook( FITVIEW_PLUGIN_FILE, static function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        deactivate_plugins( plugin_basename( FITVIEW_PLUGIN_FILE ) );
        wp_die(
            esc_html__( 'FitView wymaga aktywnej wtyczki WooCommerce. Aktywuj WooCommerce i spróbuj ponownie.', 'fitview' ),
            esc_html__( 'Błąd aktywacji FitView', 'fitview' ),
            [ 'back_link' => true ]
        );
    }

    // Set default options on first activation.
    add_option( 'fitview_mode', 'balanced' );
    add_option( 'fitview_position', 'after_add_to_cart' );
    add_option( 'fitview_enable_accounts', '0' );
} );

/**
 * Deactivation: clean up rate-limit transients.
 */
register_deactivation_hook( FITVIEW_PLUGIN_FILE, static function () {
    global $wpdb;
    // phpcs:disable WordPress.DB.DirectDatabaseQuery
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_fitview_%'" );
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_fitview_%'" );
    // phpcs:enable
} );
