<?php
/**
 * FitView modal template — injected into wp_footer on product pages.
 *
 * State is managed by adding a CSS class to #fv-modal:
 *   fv-state-upload | fv-state-processing | fv-state-result | fv-state-error
 *
 * @package FitView
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div id="fv-overlay" class="fv-overlay" role="dialog" aria-modal="true" aria-labelledby="fv-modal-title" aria-live="polite">
    <div id="fv-modal" class="fv-modal fv-state-upload">

        <!-- ── Modal header ─────────────────────────────────────────────── -->
        <div class="fv-modal-header">
            <div class="fv-modal-logo" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" focusable="false">
                    <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z" fill="currentColor"/>
                </svg>
                <strong>fito</strong>
            </div>
            <button
                id="fv-close"
                class="fv-close-btn"
                type="button"
                aria-label="<?php esc_attr_e( 'Zamknij Fito', 'fitview' ); ?>"
            >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                    <path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
        </div>

        <!-- ── State: upload ────────────────────────────────────────────── -->
        <div class="fv-pane fv-pane-upload">
            <h2 id="fv-modal-title" class="fv-modal-title">
                <?php esc_html_e( 'Przymierz wirtualnie', 'fitview' ); ?>
            </h2>
            <p class="fv-modal-subtitle">
                <?php esc_html_e( 'Dodaj swoje zdjęcie, a AI pokaże jak będziesz wyglądać w tym ubraniu.', 'fitview' ); ?>
            </p>

            <!-- Upload zone (hidden after file is chosen) -->
            <div
                id="fv-upload-zone"
                class="fv-upload-zone"
                role="button"
                tabindex="0"
                aria-label="<?php esc_attr_e( 'Strefa przesyłania zdjęcia — kliknij lub przeciągnij plik', 'fitview' ); ?>"
            >
                <svg class="fv-upload-icon" width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                    <path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <circle cx="12" cy="13" r="4" stroke="currentColor" stroke-width="1.5"/>
                </svg>
                <p class="fv-upload-title"><?php esc_html_e( 'Dodaj zdjęcie sylwetki', 'fitview' ); ?></p>
                <p class="fv-upload-sub"><?php esc_html_e( 'Kliknij lub przeciągnij plik tutaj', 'fitview' ); ?></p>
                <small class="fv-upload-meta"><?php esc_html_e( 'JPG, PNG · maks. 10 MB', 'fitview' ); ?></small>

                <div class="fv-pose-tips">

                    <!-- Karta 1: Przodem (dobra poza, zielona) -->
                    <div class="fv-pose-tip fv-pose-good">
                        <svg viewBox="0 0 60 90" fill="none" aria-hidden="true" focusable="false">
                            <ellipse cx="30" cy="14" rx="10" ry="11" fill="#00C9AC"/>
                            <path d="M16 28 Q20 24 24 23 Q30 32 36 23 Q40 24 44 28 L46 65 L14 65 Z" fill="#00C9AC"/>
                            <path d="M14 65 L20 90 L28 90 L30 75 L32 90 L40 90 L46 65 Z" fill="#00C9AC"/>
                        </svg>
                        <div class="fv-pose-check">✓</div>
                        <div class="fv-pose-label"><?php esc_html_e( 'Przodem', 'fitview' ); ?></div>
                    </div>

                    <!-- Karta 2: Pod kątem (zła poza, czerwona) -->
                    <div class="fv-pose-tip fv-pose-bad">
                        <svg viewBox="0 0 60 90" fill="none" aria-hidden="true" focusable="false">
                            <g transform="rotate(18 30 50)">
                                <ellipse cx="30" cy="14" rx="10" ry="11" fill="#E8A8A8"/>
                                <path d="M16 28 Q20 24 24 23 Q30 32 36 23 Q40 24 44 28 L46 65 L14 65 Z" fill="#E8A8A8"/>
                                <path d="M14 65 L20 90 L28 90 L30 75 L32 90 L40 90 L46 65 Z" fill="#E8A8A8"/>
                            </g>
                        </svg>
                        <div class="fv-pose-check">✗</div>
                        <div class="fv-pose-label"><?php esc_html_e( 'Pod kątem', 'fitview' ); ?></div>
                    </div>

                    <!-- Karta 3: Cała sylwetka (dobra poza, zielona) -->
                    <div class="fv-pose-tip fv-pose-good">
                        <svg viewBox="0 0 60 90" fill="none" aria-hidden="true" focusable="false">
                            <rect x="1" y="1" width="58" height="88" rx="3" stroke="#00C9AC" stroke-width="1.5" stroke-dasharray="4 2"/>
                            <g transform="translate(1.5 2) scale(0.95)">
                                <ellipse cx="30" cy="14" rx="10" ry="11" fill="#00C9AC"/>
                                <path d="M16 28 Q20 24 24 23 Q30 32 36 23 Q40 24 44 28 L46 65 L14 65 Z" fill="#00C9AC"/>
                                <path d="M14 65 L20 90 L28 90 L30 75 L32 90 L40 90 L46 65 Z" fill="#00C9AC"/>
                            </g>
                        </svg>
                        <div class="fv-pose-check">✓</div>
                        <div class="fv-pose-label"><?php esc_html_e( 'Cała sylwetka', 'fitview' ); ?></div>
                    </div>

                </div>

                <div class="fv-upload-hint">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.5"/>
                        <path d="M12 8h.01M12 12v4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <span><?php esc_html_e( 'Najlepsze efekty: cała sylwetka, prosto, jednolite tło', 'fitview' ); ?></span>
                </div>
            </div>

            <input
                type="file"
                id="fv-file-input"
                accept="image/jpeg,image/png"
                style="display:none"
                aria-hidden="true"
                tabindex="-1"
            >

            <!-- Photo preview (shown after file chosen) -->
            <div class="fv-preview-wrap" id="fv-preview-wrap" style="display:none">
                <img
                    id="fv-preview-img"
                    class="fv-preview-img"
                    src=""
                    alt="<?php esc_attr_e( 'Podgląd Twojego zdjęcia', 'fitview' ); ?>"
                >
                <div class="fv-preview-badge">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
                        <path d="M5 13l4 4L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <?php esc_html_e( 'Zdjęcie gotowe', 'fitview' ); ?>
                </div>
            </div>

            <!-- Landscape orientation warning -->
            <div class="fv-pose-warning" id="fv-pose-warning" style="display:none">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
                    <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <line x1="12" y1="9" x2="12" y2="13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <line x1="12" y1="17" x2="12.01" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <div>
                    <strong><?php esc_html_e( 'Sprawdź zdjęcie', 'fitview' ); ?></strong>
                    <p><?php esc_html_e( 'Najlepsze efekty daje zdjęcie pionowe — cała sylwetka przodem do aparatu.', 'fitview' ); ?></p>
                </div>
            </div>

            <!-- Action buttons (shown after file chosen) -->
            <div id="fv-submit-row" style="display:none">
                <div style="display:flex; gap:8px; margin-top:12px">
                    <button class="fv-btn fv-btn-ghost fv-btn-sm" id="fv-change-photo" type="button" style="flex:1">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
                            <polyline points="1 4 1 10 7 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M3.51 15a9 9 0 102.13-9.36L1 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <?php esc_html_e( 'Zmień', 'fitview' ); ?>
                    </button>
                    <button class="fv-btn fv-btn-primary" id="fv-submit" type="button" style="flex:3">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
                            <path d="M15 4V2M15 16v-2M8 9h2M20 9h2M17.8 11.8L19 13M17.8 6.2L19 5M3 21l9-9M12.2 6.2L11 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <?php esc_html_e( 'Generuj wizualizację', 'fitview' ); ?>
                    </button>
                </div>
            </div>

            <p class="fv-privacy-note">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
                    <path d="M12 2L3 7v5c0 5.25 3.75 10.15 9 11.35C17.25 22.15 21 17.25 21 12V7l-9-5z" stroke="currentColor" stroke-width="1.5"/>
                    <path d="M9 12l2 2 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
                <?php esc_html_e( 'Twoje zdjęcie nie jest zapisywane na serwerze.', 'fitview' ); ?>
            </p>
        </div>

        <!-- ── State: processing ────────────────────────────────────────── -->
        <div class="fv-pane fv-pane-processing" aria-live="polite">
            <div class="fv-spinner" aria-hidden="true"></div>
            <div id="fv-status-main" class="fv-status-main"></div>
            <div id="fv-status-sub" class="fv-status-sub"></div>
            <div class="fv-pbar-wrap" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <div id="fv-pbar" class="fv-pbar" style="width:0%"></div>
            </div>
            <div id="fv-carousel" style="display:none"></div>
            <div class="fv-shop-msg" id="fv-shop-msg" style="display:none">
                <i class="ti ti-info-circle" aria-hidden="true"></i>
                <span class="fv-shop-msg-text" id="fv-shop-msg-text"></span>
            </div>
        </div>

        <!-- ── State: result ────────────────────────────────────────────── -->
        <div class="fv-pane fv-pane-result">
            <h2 class="fv-modal-title">
                <?php esc_html_e( 'Tak to wygląda na Tobie!', 'fitview' ); ?>
            </h2>
            <div class="fv-result-img-wrap">
                <img
                    id="fv-result-img"
                    class="fv-result-img"
                    src=""
                    alt="<?php esc_attr_e( 'Wynik wirtualnej przymierzalni', 'fitview' ); ?>"
                >
                <div class="fv-result-badge">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
                        <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5z" fill="currentColor"/>
                    </svg>
                    <?php esc_html_e( 'Fito AI', 'fitview' ); ?>
                </div>
            </div>
            <div class="fv-result-actions">
                <button id="fv-add-to-cart" class="fv-btn fv-btn-lime" type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
                        <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z" stroke="currentColor" stroke-width="1.5"/>
                        <line x1="3" y1="6" x2="21" y2="6" stroke="currentColor" stroke-width="1.5"/>
                        <path d="M16 10a4 4 0 01-8 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    <?php esc_html_e( 'Dodaj do koszyka', 'fitview' ); ?>
                </button>
                <button id="fv-new-photo" class="fv-btn fv-btn-ghost" type="button">
                    <?php esc_html_e( 'Nowe zdjęcie', 'fitview' ); ?>
                </button>
            </div>
        </div>

        <!-- ── State: error ─────────────────────────────────────────────── -->
        <div class="fv-pane fv-pane-error">
            <div class="fv-error-icon" aria-hidden="true">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" focusable="false">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.5"/>
                    <path d="M12 8v4M12 16h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </div>
            <h2 class="fv-modal-title">
                <?php esc_html_e( 'Coś poszło nie tak', 'fitview' ); ?>
            </h2>
            <p id="fv-error-message" class="fv-error-message">
                <?php esc_html_e( 'Wystąpił nieoczekiwany błąd.', 'fitview' ); ?>
            </p>
            <button id="fv-retry" class="fv-btn fv-btn-primary" type="button">
                <?php esc_html_e( 'Spróbuj ponownie', 'fitview' ); ?>
            </button>
        </div>

    </div><!-- /#fv-modal -->
</div><!-- /#fv-overlay -->
