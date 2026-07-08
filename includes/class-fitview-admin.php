<?php
/**
 * Admin settings panel for FitView — WooCommerce → FitView.
 *
 * @package FitView
 */

namespace FitView;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers the FitView settings page under the WooCommerce admin menu.
 */
class Admin {

    /**
     * Available icons for shop messages (Tabler icon slugs => human label).
     *
     * @var array<string,string>
     */
    private const MSG_ICONS = [
        'ti-tag'          => 'Tag / kod rabatowy',
        'ti-truck'        => 'Dostawa',
        'ti-users'        => 'Klienci / opinie',
        'ti-shield-check' => 'Gwarancja / bezpieczne zakupy',
        'ti-gift'         => 'Prezent / promocja',
        'ti-sparkles'     => 'Nowość / AI',
    ];

    /**
     * Register all admin hooks.
     */
    public function init(): void {
        \add_action( 'admin_menu',                        [ $this, 'add_menu' ] );
        \add_action( 'admin_init',                        [ $this, 'register_settings' ] );
        \add_action( 'admin_enqueue_scripts',             [ $this, 'enqueue_scripts' ] );
        \add_action( 'wp_ajax_fitview_test_connection',   [ $this, 'ajax_test_connection' ] );
    }

    /**
     * Add FitView as a submenu under WooCommerce.
     */
    public function add_menu(): void {
        \add_submenu_page(
            'woocommerce',
            \__( 'Fito — Virtual Try-On', 'fitview' ),
            \__( 'Fito', 'fitview' ),
            'manage_woocommerce',
            'fitview-settings',
            [ $this, 'render_page' ]
        );
    }

    /**
     * Register plugin settings.
     */
    public function register_settings(): void {
        // ── FitView SaaS credentials ────────────────────────────────────────

        \register_setting(
            'fitview_settings',
            'fitview_api_key',
            [
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ]
        );

        \register_setting(
            'fitview_settings',
            'fitview_backend_url',
            [
                'sanitize_callback' => static function ( $v ) {
                    return \esc_url_raw( \rtrim( $v, '/' ) );
                },
                'default' => '',
            ]
        );

        // ── Display / behaviour ──────────────────────────────────────────────

        \register_setting(
            'fitview_settings',
            'fitview_position',
            [
                'sanitize_callback' => static function ( $v ) {
                    return \in_array( $v, [ 'after_price', 'after_add_to_cart' ], true ) ? $v : 'after_add_to_cart';
                },
                'default' => 'after_add_to_cart',
            ]
        );

        \register_setting(
            'fitview_settings',
            'fitview_enable_accounts',
            [
                'sanitize_callback' => static function ( $v ) {
                    return $v ? '1' : '0';
                },
                'default' => '0',
            ]
        );

        // ── Shop messages ────────────────────────────────────────────────────

        \register_setting(
            'fitview_settings',
            'fitview_msg_1',
            [ 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ]
        );

        \register_setting(
            'fitview_settings',
            'fitview_msg_2',
            [ 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ]
        );

        \register_setting(
            'fitview_settings',
            'fitview_msg_3',
            [ 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ]
        );

        \register_setting(
            'fitview_settings',
            'fitview_msg_1_icon',
            [ 'sanitize_callback' => [ $this, 'sanitize_msg_icon' ], 'default' => 'ti-tag' ]
        );

        \register_setting(
            'fitview_settings',
            'fitview_msg_2_icon',
            [ 'sanitize_callback' => [ $this, 'sanitize_msg_icon' ], 'default' => 'ti-users' ]
        );

        \register_setting(
            'fitview_settings',
            'fitview_msg_3_icon',
            [ 'sanitize_callback' => [ $this, 'sanitize_msg_icon' ], 'default' => 'ti-truck' ]
        );

        // ── Category whitelist ───────────────────────────────────────────────

        \register_setting(
            'fitview_settings',
            'fitview_enabled_categories',
            [
                'sanitize_callback' => static function ( $v ) {
                    if ( ! \is_array( $v ) ) {
                        return [];
                    }
                    return \array_map( 'intval', $v );
                },
                'default' => [],
            ]
        );

        // ── Carousel ─────────────────────────────────────────────────────────

        \register_setting(
            'fitview_settings',
            'fitview_carousel_title',
            [
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => 'Może Cię zainteresować',
            ]
        );

        \register_setting(
            'fitview_settings',
            'fitview_carousel_categories',
            [
                'sanitize_callback' => static function ( $v ) {
                    if ( ! \is_array( $v ) ) {
                        return [];
                    }
                    return \array_map( 'intval', $v );
                },
                'default' => [],
            ]
        );

        \register_setting( 'fitview_settings', 'fitview_fab_position', [
            'type'    => 'string',
            'default' => 'right',
        ] );

        \register_setting( 'fitview_settings', 'fitview_show_strip', [
            'type'    => 'boolean',
            'default' => true,
        ] );

        // ── Sections ─────────────────────────────────────────────────────────

        \add_settings_section( 'fitview_api',        \__( 'Połączenie z Fito SaaS', 'fitview' ),                       null, 'fitview-settings' );
        \add_settings_section( 'fitview_display',    \__( 'Wygląd i zachowanie', 'fitview' ),                             null, 'fitview-settings' );
        \add_settings_section( 'fitview_messages',   \__( 'Komunikaty podczas generowania', 'fitview' ),                  null, 'fitview-settings' );
        \add_settings_section( 'fitview_categories', \__( 'Kategorie produktów', 'fitview' ),                             null, 'fitview-settings' );
        \add_settings_section( 'fitview_carousel',   \__( 'Karuzela produktów podczas generowania', 'fitview' ),          null, 'fitview-settings' );

        // ── Fields ────────────────────────────────────────────────────────────

        \add_settings_field( 'fitview_backend_url',           \__( 'URL backendu Fito', 'fitview' ),             [ $this, 'field_backend_url' ],          'fitview-settings', 'fitview_api' );
        \add_settings_field( 'fitview_api_key',               \__( 'Klucz API Fito', 'fitview' ),                [ $this, 'field_api_key' ],               'fitview-settings', 'fitview_api' );
        \add_settings_field( 'fitview_position',              \__( 'Pozycja przycisku', 'fitview' ),                [ $this, 'field_position' ],              'fitview-settings', 'fitview_display' );
        \add_settings_field( 'fitview_show_strip', \__( 'Pasek pod przyciskiem koszyka', 'fitview' ), [ $this, 'render_show_strip_field' ], 'fitview-settings', 'fitview_display' );
        \add_settings_field(
            'fitview_fab_position',
            \__( 'Pozycja przycisku na zdjęciu', 'fitview' ),
            [ $this, 'render_fab_position_field' ],
            'fitview-settings',
            'fitview_display'
        );
        \add_settings_field( 'fitview_enable_accounts',       \__( 'Konta użytkowników', 'fitview' ),               [ $this, 'field_accounts' ],              'fitview-settings', 'fitview_display' );
        \add_settings_field( 'fitview_msg_1',                 \__( 'Komunikat 1 (np. darmowa dostawa)', 'fitview' ), [ $this, 'field_msg_1' ],                 'fitview-settings', 'fitview_messages' );
        \add_settings_field( 'fitview_msg_2',                 \__( 'Komunikat 2 (np. kod rabatowy)', 'fitview' ),    [ $this, 'field_msg_2' ],                 'fitview-settings', 'fitview_messages' );
        \add_settings_field( 'fitview_msg_3',                 \__( 'Komunikat 3 (np. liczba klientów)', 'fitview' ), [ $this, 'field_msg_3' ],                 'fitview-settings', 'fitview_messages' );
        \add_settings_field( 'fitview_enabled_categories',    \__( 'Aktywne kategorie', 'fitview' ),                 [ $this, 'field_categories' ],            'fitview-settings', 'fitview_categories' );
        \add_settings_field( 'fitview_carousel_title',        \__( 'Nagłówek karuzeli', 'fitview' ),                 [ $this, 'field_carousel_title' ],        'fitview-settings', 'fitview_carousel' );
        \add_settings_field( 'fitview_carousel_categories',   \__( 'Kategorie karuzeli', 'fitview' ),                [ $this, 'field_carousel_categories' ],   'fitview-settings', 'fitview_carousel' );
    }

    /**
     * Render the backend URL field.
     */
    public function field_backend_url(): void {
        $value = \esc_attr( (string) \get_option( 'fitview_backend_url', '' ) );
        ?>
        <input
            type="url"
            name="fitview_backend_url"
            id="fitview_backend_url"
            value="<?php echo $value; ?>"
            class="regular-text"
            placeholder="https://api.fitview.app"
        >
        <p class="description">
            <?php \esc_html_e( 'URL serwera Fito SaaS, np. https://api.twojadomena.pl — bez ukośnika na końcu.', 'fitview' ); ?>
        </p>
        <?php
    }

    /**
     * Render the FitView API key field with test button.
     */
    public function field_api_key(): void {
        $value = \esc_attr( (string) \get_option( 'fitview_api_key', '' ) );
        ?>
        <input
            type="password"
            name="fitview_api_key"
            id="fitview_api_key"
            value="<?php echo $value; ?>"
            class="regular-text"
            autocomplete="new-password"
            placeholder="fv_live_…"
        >
        <p class="description">
            <?php \esc_html_e( 'Klucz API wygenerowany w panelu Fito SaaS (format: fv_live_xxxxx).', 'fitview' ); ?>
        </p>
        <button
            type="button"
            id="fitview-test-btn"
            class="button button-secondary"
            style="margin-top:8px"
        >
            <?php \esc_html_e( 'Testuj połączenie z Fito', 'fitview' ); ?>
        </button>
        <span id="fitview-test-result" style="margin-left:10px;font-weight:500;vertical-align:middle"></span>
        <?php
    }

    /**
     * Render the button position select field.
     */
    public function field_position(): void {
        $current = (string) \get_option( 'fitview_position', 'after_add_to_cart' );
        $options = [
            'after_price'       => \__( 'Po cenie produktu', 'fitview' ),
            'after_add_to_cart' => \__( 'Po przycisku „Dodaj do koszyka" (domyślne)', 'fitview' ),
        ];
        echo '<select name="fitview_position" id="fitview_position">';
        foreach ( $options as $key => $label ) {
            \printf(
                '<option value="%s"%s>%s</option>',
                \esc_attr( $key ),
                \selected( $current, $key, false ),
                \esc_html( $label )
            );
        }
        echo '</select>';
    }

    /**
     * Render the optional accounts (OAuth) checkbox.
     */
    public function field_accounts(): void {
        $checked = \checked( \get_option( 'fitview_enable_accounts', '0' ), '1', false );
        echo '<label>';
        echo '<input type="checkbox" name="fitview_enable_accounts" id="fitview_enable_accounts" value="1"' . $checked . '>';
        echo ' ' . \esc_html__( 'Włącz logowanie przez Google / Meta (zapisywanie zdjęć)', 'fitview' );
        echo '</label>';
        echo '<p class="description">' . \esc_html__( 'Wymaga dodatkowej konfiguracji kluczy OAuth Google i Meta (patrz README).', 'fitview' ) . '</p>';
    }

    /**
     * Render the first shop message textarea.
     */
    public function field_msg_1(): void {
        $value = \esc_textarea( (string) \get_option( 'fitview_msg_1', '' ) );
        echo '<textarea name="fitview_msg_1" id="fitview_msg_1" class="large-text" rows="2"'
            . ' placeholder="' . \esc_attr( 'np. 🚚 Darmowa dostawa od 200 zł — Twój koszyk już spełnia warunek!' ) . '">'
            . $value . '</textarea>';
        $this->render_msg_icon_select( 1 );
    }

    /**
     * Render the second shop message textarea.
     */
    public function field_msg_2(): void {
        $value = \esc_textarea( (string) \get_option( 'fitview_msg_2', '' ) );
        echo '<textarea name="fitview_msg_2" id="fitview_msg_2" class="large-text" rows="2"'
            . ' placeholder="' . \esc_attr( 'np. 🏷️ Użyj kodu SUMMER10 i oszczędź 10% na tym produkcie' ) . '">'
            . $value . '</textarea>';
        $this->render_msg_icon_select( 2 );
    }

    /**
     * Render the third shop message textarea.
     */
    public function field_msg_3(): void {
        $value = \esc_textarea( (string) \get_option( 'fitview_msg_3', '' ) );
        echo '<textarea name="fitview_msg_3" id="fitview_msg_3" class="large-text" rows="2"'
            . ' placeholder="' . \esc_attr( 'np. ⭐ Ponad 2 400 klientów oceniło dopasowanie na 4,8/5' ) . '">'
            . $value . '</textarea>';
        $this->render_msg_icon_select( 3 );
    }

    /**
     * Render the icon <select> for a given shop message slot (1-3).
     */
    private function render_msg_icon_select( int $index ): void {
        $name    = 'fitview_msg_' . $index . '_icon';
        $default = [ 1 => 'ti-tag', 2 => 'ti-users', 3 => 'ti-truck' ][ $index ] ?? 'ti-tag';
        $current = (string) \get_option( $name, $default );

        echo '<p style="margin-top:6px;">';
        echo '<label for="' . \esc_attr( $name ) . '" style="margin-right:6px;">' . \esc_html__( 'Ikona:', 'fitview' ) . '</label>';
        echo '<select name="' . \esc_attr( $name ) . '" id="' . \esc_attr( $name ) . '">';
        foreach ( self::MSG_ICONS as $key => $label ) {
            printf(
                '<option value="%s"%s>%s</option>',
                \esc_attr( $key ),
                \selected( $current, $key, false ),
                \esc_html( $label )
            );
        }
        echo '</select>';
        echo '</p>';
    }

    /**
     * Sanitize a shop message icon slug against the allowed whitelist.
     */
    public function sanitize_msg_icon( $value ): string {
        $value = (string) $value;
        return \array_key_exists( $value, self::MSG_ICONS ) ? $value : 'ti-tag';
    }

    /**
     * Render the carousel title text field.
     */
    public function field_carousel_title(): void {
        $value = \esc_attr( (string) \get_option( 'fitview_carousel_title', 'Może Cię zainteresować' ) );
        echo '<input type="text" name="fitview_carousel_title" id="fitview_carousel_title"'
            . ' class="regular-text" value="' . $value . '">';
        echo '<p class="description">'
            . \esc_html__( 'Nagłówek karuzeli widoczny dla klienta podczas generowania.', 'fitview' )
            . '</p>';
    }

    /**
     * Render the carousel category checkboxes.
     */
    public function field_carousel_categories(): void {
        $all_cats = \get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
        ] );

        $enabled = (array) \get_option( 'fitview_carousel_categories', [] );

        if ( \is_wp_error( $all_cats ) || empty( $all_cats ) ) {
            echo '<p>' . \esc_html__( 'Brak kategorii z produktami.', 'fitview' ) . '</p>';
            return;
        }

        echo '<div style="max-height:260px;overflow-y:auto;border:1px solid #ddd;padding:10px;border-radius:4px">';
        foreach ( $all_cats as $cat ) {
            $checked = \in_array( $cat->term_id, $enabled, true ) ? ' checked' : '';
            \printf(
                '<div style="margin-bottom:6px"><label>'
                    . '<input type="checkbox" name="fitview_carousel_categories[]" value="%d"%s> '
                    . '%s <span style="color:#888;font-size:11px">(%d)</span>'
                    . '</label></div>',
                \esc_attr( (string) $cat->term_id ),
                $checked,
                \esc_html( $cat->name ),
                (int) $cat->count
            );
        }
        echo '</div>';
        echo '<p class="description">'
            . \esc_html__( 'Produkty z tych kategorii będą losowo prezentowane w karuzeli podczas generowania.', 'fitview' )
            . '</p>';
    }

    /**
     * Render the product category checkboxes.
     */
    public function field_categories(): void {
        $categories = \get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ] );

        $enabled = (array) \get_option( 'fitview_enabled_categories', [] );

        echo '<div style="margin-bottom:8px;display:flex;gap:6px;flex-wrap:wrap">';
        echo '<button type="button" id="cats-select-all" class="button button-secondary">'
            . \esc_html__( 'Zaznacz wszystkie', 'fitview' ) . '</button>';
        echo '<button type="button" id="cats-deselect-all" class="button button-secondary">'
            . \esc_html__( 'Odznacz wszystkie', 'fitview' ) . '</button>';
        echo '<button type="button" id="cats-show-selected" class="button button-secondary">'
            . \esc_html__( 'Tylko zaznaczone', 'fitview' ) . '</button>';
        echo '</div>';

        if ( \is_wp_error( $categories ) || empty( $categories ) ) {
            echo '<p>' . \esc_html__( 'Brak kategorii produktów.', 'fitview' ) . '</p>';
            return;
        }

        echo '<div style="max-height:260px;overflow-y:auto;border:1px solid #ddd;padding:10px;border-radius:4px">';
        foreach ( $categories as $cat ) {
            $checked = \in_array( $cat->term_id, $enabled, true ) ? ' checked' : '';
            \printf(
                '<div class="fv-cat-item" style="margin-bottom:6px"><label>'
                    . '<input type="checkbox" class="fv-cat-checkbox" name="fitview_enabled_categories[]" value="%d"%s> '
                    . '%s <span style="color:#888;font-size:11px">(%d)</span>'
                    . '</label></div>',
                \esc_attr( (string) $cat->term_id ),
                $checked,
                \esc_html( $cat->name ),
                (int) $cat->count
            );
        }
        echo '</div>';
        echo '<p class="description">'
            . \esc_html__( 'Odznacz wszystkie = FitView aktywny we wszystkich kategoriach (domyślne).', 'fitview' )
            . '</p>';
    }

    public function render_show_strip_field(): void {
        $value = \get_option( 'fitview_show_strip', true );
        ?>
        <label>
            <input type="checkbox" name="fitview_show_strip" value="1" <?php \checked( $value, true ); ?>>
            <?php \esc_html_e( 'Pokaż pasek "Przymierz wirtualnie" pod przyciskiem "Dodaj do koszyka"', 'fitview' ); ?>
        </label>
        <p class="description"><?php \esc_html_e( 'Odznacz jeśli chcesz mieć tylko przycisk na zdjęciu produktu.', 'fitview' ); ?></p>
        <?php
    }

    public function render_fab_position_field(): void {
        $value = \get_option( 'fitview_fab_position', 'right' );
        ?>
        <select name="fitview_fab_position" id="fitview_fab_position">
            <option value="right" <?php \selected( $value, 'right' ); ?>>
                <?php \esc_html_e( 'Prawy dolny róg (domyślne)', 'fitview' ); ?>
            </option>
            <option value="left" <?php \selected( $value, 'left' ); ?>>
                <?php \esc_html_e( 'Lewy dolny róg', 'fitview' ); ?>
            </option>
        </select>
        <p class="description"><?php \esc_html_e( 'Pozycja przycisku "Przymierz" na zdjęciu produktu.', 'fitview' ); ?></p>
        <?php
    }

    /**
     * Render the full settings page HTML.
     */
    public function render_page(): void {
        if ( ! \current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $api         = new Api();
        $api_key     = (string) \get_option( 'fitview_api_key', '' );
        $backend_url = \rtrim( (string) \get_option( 'fitview_backend_url', '' ), '/' );
        $usage_html  = '';
        $upgrade_html = '';

        if ( $api_key && $backend_url ) {
            $response = \wp_remote_get(
                $backend_url . '/api/v1/ping',
                [ 'timeout' => 10, 'headers' => [ 'X-FitView-Key' => $api_key ] ]
            );

            if ( ! \is_wp_error( $response ) ) {
                $http_code = (int) \wp_remote_retrieve_response_code( $response );
                $data      = \json_decode( \wp_remote_retrieve_body( $response ), true );
                $status    = $data['status'] ?? '';

                if ( $http_code === 403 && ( $data['error'] ?? '' ) === 'limit_reached' ) {
                    $upgrade_html = '<div style="background:#fef2f2;border:1px solid #fca5a5;border-left:4px solid #d63638;border-radius:6px;padding:16px 20px;margin-bottom:16px;max-width:600px">
                        <strong style="color:#d63638">⚠ Limit wizualizacji wyczerpany</strong>
                        <p style="margin:6px 0 0;font-size:13px">Przycisk FitView jest ukryty w Twoim sklepie. Przejdź na wyższy plan aby wznowić działanie wirtualnej przymierzalni.</p>
                        <a href="https://fitview.app/pricing" target="_blank" class="button button-primary" style="margin-top:10px">Zmień plan →</a>
                    </div>';
                } elseif ( $http_code === 200 ) {
                    $usage   = (int) ( $data['usage'] ?? 0 );
                    $limit   = (int) ( $data['limit'] ?? 0 );
                    $plan    = \sanitize_text_field( $data['plan'] ?? '' );
                    $percent   = $limit > 0 ? round( ( $usage / $limit ) * 100 ) : 0;
                    $bar_color = $percent >= 90 ? '#d63638' : ( $percent >= 70 ? '#dba617' : '#00A882' );

                    $usage_html = sprintf(
                        '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 20px;margin-bottom:20px;max-width:600px">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                                <strong style="font-size:14px">Wykorzystanie w tym miesiącu</strong>
                                <span style="font-size:12px;color:#666;background:#f0f0f0;padding:2px 8px;border-radius:10px">%s</span>
                            </div>
                            <div style="background:#f0f0f0;border-radius:4px;height:8px;margin-bottom:8px">
                                <div style="background:%s;width:%d%%;height:8px;border-radius:4px;transition:width 0.3s"></div>
                            </div>
                            <div style="display:flex;justify-content:space-between;font-size:13px">
                                <span><strong>%d</strong> / %d wizualizacji</span>
                                <span style="color:%s">%d%% wykorzystane</span>
                            </div>
                        </div>',
                        \esc_html( strtoupper( $plan ) ),
                        \esc_attr( $bar_color ),
                        $percent,
                        $usage,
                        $limit,
                        \esc_attr( $bar_color ),
                        $percent
                    );
                }
            }
        }

        ?>
        <div class="wrap">
            <h1 style="display:flex;align-items:center;gap:8px">
                <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#00E5C4"></span>
                <?php \esc_html_e( 'Fito — Virtual Try-On', 'fitview' ); ?>
            </h1>
            <p><?php \esc_html_e( 'Konfiguracja wirtualnej przymierzalni AI dla WooCommerce.', 'fitview' ); ?></p>

            <?php echo $upgrade_html ?? ''; echo $usage_html; ?>

            <?php \settings_errors( 'fitview_settings' ); ?>

            <form method="post" action="options.php">
                <?php
                \settings_fields( 'fitview_settings' );
                \do_settings_sections( 'fitview-settings' );
                \submit_button( \__( 'Zapisz ustawienia', 'fitview' ) );
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Enqueue the admin script only on the FitView settings page.
     *
     * @param string $hook  Current admin page hook suffix.
     */
    public function enqueue_scripts( string $hook ): void {
        if ( $hook !== 'woocommerce_page_fitview-settings' ) {
            return;
        }

        \wp_enqueue_script( 'jquery' );
        \wp_add_inline_script( 'jquery', $this->admin_js() );
    }

    /**
     * AJAX handler for the "Test connection" button.
     */
    public function ajax_test_connection(): void {
        \check_ajax_referer( 'fitview_admin_nonce', 'nonce' );

        if ( ! \current_user_can( 'manage_woocommerce' ) ) {
            \wp_send_json_error( [ 'message' => \__( 'Brak uprawnień.', 'fitview' ) ] );
            return;
        }

        $api    = new Api();
        $result = $api->test_connection();

        if ( $result['success'] ) {
            \wp_send_json_success( $result );
        } else {
            \wp_send_json_error( $result );
        }
    }

    /**
     * Inline JavaScript for the admin page (test connection button).
     *
     * @return string
     */
    private function admin_js(): string {
        $ajax_url = \esc_js( \admin_url( 'admin-ajax.php' ) );
        $nonce    = \esc_js( \wp_create_nonce( 'fitview_admin_nonce' ) );
        $testing  = \esc_js( \__( 'Testuję…', 'fitview' ) );
        $btn_text = \esc_js( \__( 'Testuj połączenie z FitView', 'fitview' ) );

        return <<<JS
(function($){
    $(document).on('click', '#fitview-test-btn', function() {
        var btn    = $(this);
        var result = $('#fitview-test-result');
        btn.prop('disabled', true).text('{$testing}');
        result.text('').css('color', '');

        $.post(
            '{$ajax_url}',
            { action: 'fitview_test_connection', nonce: '{$nonce}' },
            function(data) {
                if (data.success && data.data && data.data.success) {
                    result.text('✓ ' + data.data.message).css('color', '#00A882');
                } else {
                    var msg = (data.data && data.data.message) ? data.data.message : 'Błąd połączenia';
                    result.text('✗ ' + msg).css('color', '#d63638');
                }
            }
        )
        .fail(function() {
            result.text('✗ Błąd sieci — sprawdź połączenie').css('color', '#d63638');
        })
        .always(function() {
            btn.prop('disabled', false).text('{$btn_text}');
        });
    });
})(jQuery);

(function() {
    var selectAll   = document.getElementById('cats-select-all');
    var deselectAll = document.getElementById('cats-deselect-all');
    var showOnly    = document.getElementById('cats-show-selected');

    if (selectAll) {
        selectAll.addEventListener('click', function() {
            document.querySelectorAll('.fv-cat-checkbox').forEach(function(cb) { cb.checked = true; });
        });
    }
    if (deselectAll) {
        deselectAll.addEventListener('click', function() {
            document.querySelectorAll('.fv-cat-checkbox').forEach(function(cb) { cb.checked = false; });
        });
    }
    if (showOnly) {
        var showingAll = true;
        showOnly.addEventListener('click', function() {
            showingAll = !showingAll;
            document.querySelectorAll('.fv-cat-item').forEach(function(item) {
                var cb = item.querySelector('.fv-cat-checkbox');
                item.style.display = (!showingAll && cb && !cb.checked) ? 'none' : '';
            });
            showOnly.textContent = showingAll ? 'Tylko zaznaczone' : 'Pokaż wszystkie';
        });
    }
})();
JS;
    }
}
