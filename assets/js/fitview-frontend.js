/**
 * FitView — Virtual Try-On  |  frontend logic
 *
 * Vanilla ES6+, no external dependencies, no jQuery.
 * Initialised by wp_localize_script with the global `fitviewData` object.
 */

/* global fitviewData */

const FitView = (() => {
    // ── Private state ──────────────────────────────────────────────────────

    let _data         = null;  // fitviewData from PHP
    let _currentState = null;  // active modal state string
    let _currentFile  = null;  // File object selected by the user
    let _jobId        = null;  // fal.ai async job ID
    let _pollTimer    = null;  // setTimeout handle for status polling
    let _progressStepTimers = [];  // setTimeout handles for fake progress steps
    let _shopMsgInterval    = null; // setInterval handle for rotating shop messages
    let _carouselScrollRaf    = null;  // requestAnimationFrame handle for carousel auto-scroll
    let _carouselPauseTimer   = null;  // setTimeout handle for resuming after manual scroll
    let _carouselScrollPaused = false; // true while user-initiated pause is active

    // ── Stage data (drives progress bar, status texts, step states) ────────

    const STAGE_DATA = [
        { delay: 0,     progress: 0,  main: 'Przesyłam zdjęcie...',        sub: 'Weryfikuję format i jakość obrazu' },
        { delay: 6000,  progress: 15, main: 'Pobieram zdjęcie produktu...', sub: 'Łączę się z bazą produktów sklepu' },
        { delay: 12000, progress: 30, main: 'Analizuję sylwetkę...',        sub: 'Wykrywam proporcje i postawę ciała' },
        { delay: 18000, progress: 50, main: 'Dopasowuję krój ubrania...',   sub: 'Kalibruję rozmiar do Twojej sylwetki' },
        { delay: 24000, progress: 70, main: 'Generuję wizualizację AI...',  sub: 'Nakładam teksturę i oświetlenie' },
        { delay: 30000, progress: 85, main: 'Optymalizuję jakość...',       sub: 'Wygładzam szczegóły obrazu' },
        { delay: 36000, progress: 95, main: 'Prawie gotowe!',               sub: 'Finalizuję rendering końcowy' },
    ];

    // ── Error messages ──────────────────────────────────────────────────────

    const ERROR_MESSAGES = {
        bad_format:                   'Dodaj zdjęcie w formacie JPG lub PNG.',
        too_large:                    'Zdjęcie jest za duże. Maksymalny rozmiar to 10 MB.',
        connection_error:             'Serwis jest chwilowo niedostępny. Spróbuj za chwilę.',
        generation_failed:            'Generowanie trwa dłużej niż zwykle. Spróbuj ponownie.',
        fitview_connection_error:     'Serwis jest chwilowo niedostępny. Spróbuj za chwilę.',
        fitview_api_error:            'Serwis jest chwilowo niedostępny. Spróbuj za chwilę.',
        fitview_rate_limit_exceeded:  'Przekroczono limit zapytań. Spróbuj ponownie za godzinę.',
        fitview_no_product_image:     'Produkt nie ma zdjęcia głównego — przymierzalnia niedostępna.',
        fitview_missing_api_key:      'Serwis jest chwilowo niedostępny.',
    };

    // ── DOM helpers ────────────────────────────────────────────────────────

    const el = (id) => document.getElementById(id);

    // ── Initialisation ─────────────────────────────────────────────────────

    /**
     * Entry point — called with fitviewData from PHP.
     * @param {Object} data  wp_localize_script payload
     */
    function init(data) {
        _data = data;
        _injectFab();
        _bindModal();
    }

    // ── FAB injection ───────────────────────────────────────────────────────

    /**
     * Inject the floating eye button onto the WooCommerce product gallery.
     */
    function _injectFab() {
        const gallery = document.querySelector('.woocommerce-product-gallery');
        if (!gallery) return;

        // Ensure the gallery is a positioning context.
        if (getComputedStyle(gallery).position === 'static') {
            gallery.style.position = 'relative';
        }

        const fab = document.createElement('button');
        fab.className   = 'fv-fab';
        fab.type        = 'button';
        fab.setAttribute('aria-label', 'Otwórz FitView — wirtualną przymierzalnię');
        fab.innerHTML   = _eyeIconSVG();

        const fabLabel = document.createElement('div');
        fabLabel.className   = 'fv-fab-label';
        fabLabel.textContent = 'Przymierz wirtualnie';

        gallery.appendChild(fab);
        gallery.appendChild(fabLabel);

        // stopPropagation prevents the click from bubbling to the gallery or any
        // parent form — ensures only the FAB opens the modal, nothing else.
        fab.addEventListener('click', (e) => { e.stopPropagation(); openModal(); });
    }

    // ── Modal binding ───────────────────────────────────────────────────────

    /**
     * Attach all event listeners to the modal that was injected by modal.php.
     */
    function _bindModal() {
        const overlay = el('fv-overlay');
        if (!overlay) return;

        // Close on backdrop click.
        // The overlay backdrop has pointer-events:none (CSS), so only clicks
        // that bubble up from inside .fv-modal reach this handler.
        // The e.target === overlay check therefore only fires when the user
        // clicks the dark backdrop area directly (impossible with current CSS,
        // kept as a safety net if the theme overrides pointer-events).
        overlay.addEventListener('click', (e) => {
            if (!overlay.classList.contains('open')) return;
            // Do not close if click originated from WooCommerce add-to-cart button
            const addToCart = e.target.closest(
                '.single_add_to_cart_button, .add_to_cart_button, [name="add-to-cart"], form.cart'
            );
            if (addToCart) return;
            if (e.target === overlay) closeModal();
        });

        // Close button.
        const closeBtn = el('fv-close');
        if (closeBtn) closeBtn.addEventListener('click', closeModal);

        // Strip CTA (rendered by PHP in the product summary).
        const strip = el('fv-strip-cta');
        if (strip) {
            strip.addEventListener('click', openModal);
            strip.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openModal(); }
            });
        }

        // Upload zone: click → trigger file input.
        const uploadZone = el('fv-upload-zone');
        const fileInput  = el('fv-file-input');

        if (uploadZone && fileInput) {
            uploadZone.addEventListener('click', (e) => {
                console.log('[FitView] Upload zone clicked, target:', e.target.tagName, e.target.className);
                fileInput.click();
                console.log('[FitView] File input triggered');
            });

            uploadZone.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); }
            });

            uploadZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                uploadZone.classList.add('dragging');
            });

            uploadZone.addEventListener('dragleave', (e) => {
                if (!uploadZone.contains(e.relatedTarget)) {
                    uploadZone.classList.remove('dragging');
                }
            });

            uploadZone.addEventListener('drop', (e) => {
                e.preventDefault();
                uploadZone.classList.remove('dragging');
                const file = e.dataTransfer && e.dataTransfer.files[0];
                if (file) _handleFile(file);
            });

            fileInput.addEventListener('change', () => {
                const file = fileInput.files && fileInput.files[0];
                console.log('[FitView] File input change fired, file:', file ? file.name : 'none');
                if (file) _handleFile(file);
            });
        } else {
            console.warn('[FitView] Upload zone or file input not found. Zone:', !!uploadZone, 'Input:', !!fileInput);
        }

        // Submit button.
        const submitBtn = el('fv-submit');
        if (submitBtn) submitBtn.addEventListener('click', _submitTryon);

        // Retry button (error state).
        const retryBtn = el('fv-retry');
        if (retryBtn) retryBtn.addEventListener('click', () => setState('upload'));

        // New photo button (result state).
        const newPhotoBtn = el('fv-new-photo');
        if (newPhotoBtn) newPhotoBtn.addEventListener('click', _resetUpload);

        // Change photo button (upload state, after file chosen).
        const changePhotoBtn = el('fv-change-photo');
        if (changePhotoBtn) changePhotoBtn.addEventListener('click', _resetUpload);

        // Add to cart via WooCommerce AJAX.
        const addToCartBtn = el('fv-add-to-cart');
        if (addToCartBtn) {
            addToCartBtn.addEventListener('click', async (e) => {
                e.stopPropagation();

                // Get product ID from body class (postid-XX)
                const bodyClass = document.body.className;
                const postIdMatch = bodyClass.match(/postid-(\d+)/);
                const productId = postIdMatch ? postIdMatch[1] : null;

                if (!productId) {
                    console.error('[FitView] Could not find product ID');
                    closeModal();
                    return;
                }

                try {
                    addToCartBtn.textContent = 'Dodawanie...';
                    addToCartBtn.disabled = true;

                    // First get the cart nonce from Store API
                    let storeNonce = '';
                    try {
                        const nonceRes = await fetch('/wp-json/wc/store/v1/cart', {
                            method: 'GET',
                            credentials: 'include',
                        });
                        storeNonce = nonceRes.headers.get('Nonce') || '';
                        console.log('[FitView] Store API nonce:', storeNonce);
                    } catch (nonceErr) {
                        console.warn('[FitView] Could not get store nonce:', nonceErr);
                    }

                    const storeApiUrl = '/wp-json/wc/store/v1/cart/add-item';
                    const storeResponse = await fetch(storeApiUrl, {
                        method: 'POST',
                        credentials: 'include',
                        headers: {
                            'Content-Type': 'application/json',
                            ...(storeNonce ? { 'Nonce': storeNonce } : {}),
                        },
                        body: JSON.stringify({
                            id: parseInt(productId),
                            quantity: 1,
                        }),
                    });

                    const storeData = await storeResponse.json();
                    console.log('[FitView] Store API response:', storeData);

                    if (storeResponse.ok && !storeData.code) {
                        addToCartBtn.textContent = '✓ Dodano do koszyka!';
                        document.body.dispatchEvent(new CustomEvent('wc-blocks_added_to_cart', { bubbles: true }));
                        setTimeout(() => closeModal(), 1000);
                    } else {
                        console.error('[FitView] Store API error:', storeData);
                        addToCartBtn.textContent = 'Błąd — spróbuj ponownie';
                        addToCartBtn.disabled = false;
                    }

                } catch (err) {
                    console.error('[FitView] Add to cart fetch error:', err);
                    addToCartBtn.textContent = 'Błąd — spróbuj ponownie';
                    addToCartBtn.disabled = false;
                }
            });
        }

        // Escape key.
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && _currentState) closeModal();
        });
    }

    // ── Modal open / close ──────────────────────────────────────────────────

    function openModal() {
        const overlay = el('fv-overlay');
        if (!overlay) return;
        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';
        setState('upload');

        const savedPhotoUrl = sessionStorage.getItem('fitview_person_image_url');
        if (savedPhotoUrl) {
            _restoreSavedPhoto(savedPhotoUrl);
        }

        // Move focus to the close button for accessibility.
        const closeBtn = el('fv-close');
        if (closeBtn) setTimeout(() => closeBtn.focus(), 50);
    }

    function closeModal() {
        const overlay = el('fv-overlay');
        if (!overlay) return;
        overlay.classList.remove('open');
        document.body.style.overflow = '';
        _stopPoll();
        _clearProgressTimers();
        _currentState = null;
    }

    /**
     * Set the modal's visible state by swapping a CSS class on #fv-modal.
     * @param {'upload'|'processing'|'result'|'error'} state
     */
    function setState(state) {
        _currentState = state;
        const modal = el('fv-modal');
        if (!modal) return;
        modal.className = 'fv-modal fv-state-' + state;
    }

    // ── File handling ───────────────────────────────────────────────────────

    /**
     * Validate the selected file and render a preview.
     * @param {File} file
     */
    function _handleFile(file) {
        console.log('[FitView] File selected:', file.name, '| type:', file.type, '| size:', file.size);

        if (!['image/jpeg', 'image/png'].includes(file.type)) {
            console.warn('[FitView] Bad format:', file.type);
            _showError('bad_format');
            return;
        }

        if (file.size > 10 * 1024 * 1024) {
            console.warn('[FitView] File too large:', file.size);
            _showError('too_large');
            return;
        }

        _currentFile = file;

        const reader = new FileReader();
        reader.onload = (e) => {
            const dataUrl = e.target.result;
            const img = new window.Image();
            img.onload = () => {
                console.log('[FitView] Image loaded, dimensions:', img.width, 'x', img.height, '| portrait:', img.width <= img.height);
                _showUploadPreview(dataUrl, img.width <= img.height);
            };
            img.onerror = () => {
                console.error('[FitView] Failed to load image for dimension check');
                _showUploadPreview(dataUrl, true);
            };
            img.src = dataUrl;
        };
        reader.onerror = () => console.error('[FitView] FileReader error');
        reader.readAsDataURL(file);
    }

    function _showUploadPreview(dataUrl, isPortrait) {
        try { sessionStorage.setItem('fitview_person_image_url', dataUrl); } catch (_e) { /* quota exceeded — skip persistence */ }

        const previewImg  = el('fv-preview-img');
        const previewWrap = el('fv-preview-wrap');
        const warning     = el('fv-pose-warning');
        const uploadZone  = el('fv-upload-zone');
        const submitRow   = el('fv-submit-row');
        const submitBtn   = el('fv-submit');

        if (previewImg)  previewImg.src = dataUrl;
        if (previewWrap) previewWrap.style.display = 'flex';
        if (warning)     warning.style.display = isPortrait ? 'none' : 'flex';
        if (uploadZone)  uploadZone.style.display = 'none';
        if (submitRow)   submitRow.style.display = 'block';
        if (submitBtn)   submitBtn.disabled = false;
    }

    // ── Try-on submission ───────────────────────────────────────────────────

    /**
     * Build FormData, POST to the REST endpoint, handle sync or async response.
     */
    async function _submitTryon() {
        if (!_currentFile || !_data) return;

        setState('processing');
        _startProgress();
        loadCarousel(_data.productId);

        const formData = new FormData();
        formData.append('user_photo', _currentFile);
        formData.append('product_id', String(_data.productId));

        try {
            const response = await fetch(_data.restUrl + 'tryon', {
                method:  'POST',
                headers: { 'X-WP-Nonce': _data.nonce },
                body:    formData,
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                _clearProgressTimers();
                if (data.code === 'invalid_photo' || data.code === 'fitview_invalid_photo' || data.error === 'invalid_photo') {
                    _showError('invalid_photo', {
                        title:    'Nieprawidłowe zdjęcie',
                        message:  'Dodaj zdjęcie przedstawiające osobę w pełnej sylwetce lub do pasa.',
                        btnLabel: 'Dodaj inne zdjęcie',
                    });
                } else {
                    _showError(data.code || 'connection_error');
                }
                return;
            }

            // Sync mode: fal.ai returned the result immediately.
            if (data.result_url) {
                _clearProgressTimers();
                _showResult(data.result_url);
                return;
            }

            // Async mode: start polling.
            if (data.job_id) {
                _jobId = data.job_id;
                _startPoll();
            }
        } catch (_err) {
            _clearProgressTimers();
            _showError('connection_error');
        }
    }

    // ── Status polling ──────────────────────────────────────────────────────

    function _startPoll() {
        _pollTimer = setTimeout(_poll, 2000);
    }

    function _stopPoll() {
        if (_pollTimer !== null) {
            clearTimeout(_pollTimer);
            _pollTimer = null;
        }
    }

    async function _poll() {
        if (!_jobId || !_data) return;
        console.log('[FitView] Polling job_id:', _jobId);

        try {
            const response = await fetch(_data.restUrl + 'status/' + encodeURIComponent(_jobId), {
                headers: { 'X-WP-Nonce': _data.nonce },
            });

            const data = await response.json();
            console.log('[FitView] Status response:', data);

            if (!response.ok || !data.success) {
                _clearProgressTimers();
                _showError(data.code || 'connection_error');
                return;
            }

            if (data.status === 'COMPLETED' && data.result_url) {
                console.log('[FitView] Result URL:', data.result_url);
                _clearProgressTimers();
                _showResult(data.result_url);
                return;
            }

            if (data.status === 'FAILED') {
                _clearProgressTimers();
                _showError('generation_failed');
                return;
            }

            // Still in queue or processing — schedule the next poll.
            _pollTimer = setTimeout(_poll, 2000);
        } catch (_err) {
            _clearProgressTimers();
            _showError('connection_error');
        }
    }

    // ── Progress animation ──────────────────────────────────────────────────

    function _startProgress() {
        _applyStage(STAGE_DATA[0]);

        STAGE_DATA.slice(1).forEach((stage) => {
            const timer = setTimeout(() => {
                if (_currentState === 'processing') _applyStage(stage);
            }, stage.delay);
            _progressStepTimers.push(timer);
        });

        _startShopMessages();
    }

    function _applyStage(stage) {
        _setProgress(stage.progress);

        const mainEl = el('fv-status-main');
        const subEl  = el('fv-status-sub');
        if (mainEl) mainEl.textContent = stage.main;
        if (subEl)  subEl.textContent  = stage.sub;
    }

    function _startShopMessages() {
        if (!_data || !_data.shopMessages) return;
        const msgs = Object.values(_data.shopMessages).filter(Boolean);
        if (!msgs.length) return;

        const msgEl     = el('fv-shop-msg');
        const msgTextEl = el('fv-shop-msg-text');
        if (!msgEl || !msgTextEl) return;

        let idx = 0;
        msgTextEl.textContent = msgs[idx];
        msgEl.style.display = 'flex';

        _shopMsgInterval = setInterval(() => {
            if (_currentState !== 'processing') return;
            idx = (idx + 1) % msgs.length;
            msgTextEl.textContent = msgs[idx];
        }, 5000);
    }

    function _clearProgressTimers() {
        _progressStepTimers.forEach((t) => clearTimeout(t));
        _progressStepTimers = [];
        if (_shopMsgInterval !== null) {
            clearInterval(_shopMsgInterval);
            _shopMsgInterval = null;
        }
        if (_carouselScrollRaf !== null) {
            cancelAnimationFrame(_carouselScrollRaf);
            _carouselScrollRaf = null;
        }
        clearTimeout(_carouselPauseTimer);
        _carouselScrollPaused = false;
        const msgEl = el('fv-shop-msg');
        if (msgEl) msgEl.style.display = 'none';
        const carouselEl = el('fv-carousel');
        if (carouselEl) { carouselEl.innerHTML = ''; carouselEl.style.display = 'none'; }
    }

    function _setProgress(value) {
        const bar = el('fv-pbar');
        if (bar) {
            bar.style.width = value + '%';
            const wrap = bar.closest('[role="progressbar"]');
            if (wrap) wrap.setAttribute('aria-valuenow', String(value));
        }
    }

    // ── Carousel ────────────────────────────────────────────────────────────

    async function loadCarousel(productId) {
        try {
            const res = await fetch(
                _data.restUrl + 'carousel-products?product_id=' + productId,
                { headers: { 'X-WP-Nonce': _data.nonce } }
            );
            const products = await res.json();
            if (!products || products.length === 0) return;

            const container = el('fv-carousel');
            if (!container) return;

            const ITEM_W = 130;
            const GAP    = 12;
            const STEP   = ITEM_W + GAP;

            const arrowCss = [
                'position:absolute', 'top:50%', 'transform:translateY(-50%)',
                'width:30px', 'height:30px', 'border-radius:50%',
                'background:rgba(0,0,0,0.45)', 'color:#fff',
                'border:none', 'cursor:pointer', 'z-index:10',
                'display:flex', 'align-items:center', 'justify-content:center',
                'font-size:22px', 'line-height:1', 'padding:0',
                'user-select:none', 'flex-shrink:0',
            ].join(';');

            // Duplicate items so the strip can loop seamlessly: when the first copy
            // is fully scrolled past, scrollLeft is snapped back by half — invisible
            // to the user because both halves are identical.
            const doubled = [...products, ...products];

            container.innerHTML = `
                <div class="fv-carousel-header">
                    <span class="fv-carousel-title">
                        <i class="ti ti-sparkles" aria-hidden="true"></i>
                        ${_data.carouselTitle}
                    </span>
                </div>
                <div style="position:relative;">
                    <div id="fv-cstrip"
                         style="display:flex;gap:${GAP}px;overflow-x:hidden;">
                        ${doubled.map(p => `
                            <a href="${p.url}" class="fv-carousel-item"
                               style="flex:0 0 ${ITEM_W}px;"
                               target="_blank" rel="noopener">
                                <div class="fv-carousel-img">
                                    <img src="${p.image}" alt="${p.name}" loading="lazy">
                                </div>
                                <div class="fv-carousel-info">
                                    <div class="fv-carousel-name">${p.name}</div>
                                    <div class="fv-carousel-price">${p.price}</div>
                                </div>
                            </a>
                        `).join('')}
                    </div>
                    <button id="fv-carr-left"  type="button"
                            style="${arrowCss};left:4px;"
                            aria-label="Przewiń w lewo">&#8249;</button>
                    <button id="fv-carr-right" type="button"
                            style="${arrowCss};right:4px;"
                            aria-label="Przewiń w prawo">&#8250;</button>
                </div>
            `;

            const strip    = document.getElementById('fv-cstrip');
            const btnLeft  = document.getElementById('fv-carr-left');
            const btnRight = document.getElementById('fv-carr-right');

            function pauseFor3s() {
                _carouselScrollPaused = true;
                clearTimeout(_carouselPauseTimer);
                _carouselPauseTimer = setTimeout(() => { _carouselScrollPaused = false; }, 3000);
            }

            function tick() {
                if (strip && !_carouselScrollPaused) {
                    strip.scrollLeft += 0.8;
                    // Seamless loop: once we've scrolled past the first copy, snap back.
                    if (strip.scrollLeft >= strip.scrollWidth / 2) {
                        strip.scrollLeft -= strip.scrollWidth / 2;
                    }
                }
                _carouselScrollRaf = requestAnimationFrame(tick);
            }

            _carouselScrollRaf = requestAnimationFrame(tick);

            btnLeft?.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                pauseFor3s();
                strip.scrollLeft = Math.max(0, strip.scrollLeft - STEP);
            });

            btnRight?.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                pauseFor3s();
                strip.scrollLeft += STEP;
            });

            container.style.display = 'block';

        } catch (e) {
            console.log('[FitView] Carousel error:', e);
        }
    }

    // ── Result display ──────────────────────────────────────────────────────

    function _showResult(url) {
        _setProgress(100);
        const img = el('fv-result-img');
        if (img) {
            img.onload = () => setState('result');
            img.onerror = () => _showError('connection_error');
            img.src = url;
        } else {
            setState('result');
        }
    }

    // ── Error display ───────────────────────────────────────────────────────

    function _showError(code, opts = {}) {
        const msgEl    = el('fv-error-message');
        const titleEl  = el('fv-error-title');
        const retryBtn = el('fv-retry');

        // Cache original HTML text on first call so subsequent calls can restore it.
        if (titleEl  && !titleEl.dataset.default)  titleEl.dataset.default  = titleEl.textContent;
        if (retryBtn && !retryBtn.dataset.default)  retryBtn.dataset.default = retryBtn.textContent;

        const msg = opts.message || ERROR_MESSAGES[code] || 'Wystąpił nieoczekiwany błąd. Spróbuj ponownie.';
        if (msgEl)   msgEl.textContent   = msg;
        if (titleEl) titleEl.textContent = opts.title    || titleEl.dataset.default  || '';
        if (retryBtn) retryBtn.textContent = opts.btnLabel || retryBtn.dataset.default || 'Spróbuj ponownie';

        setState('error');
    }

    // ── Reset upload ────────────────────────────────────────────────────────

    function _resetUpload() {
        sessionStorage.removeItem('fitview_person_image_url');

        const changeSavedBtn = el('fv-change-saved-photo');
        if (changeSavedBtn) changeSavedBtn.remove();

        _currentFile = null;
        _jobId       = null;

        const previewImg  = el('fv-preview-img');
        const previewWrap = el('fv-preview-wrap');
        const warning     = el('fv-pose-warning');
        const uploadZone  = el('fv-upload-zone');
        const submitRow   = el('fv-submit-row');
        const submitBtn   = el('fv-submit');
        const fileInput   = el('fv-file-input');

        if (previewImg)  previewImg.src = '';
        if (previewWrap) previewWrap.style.display = 'none';
        if (warning)     warning.style.display = 'none';
        if (uploadZone)  uploadZone.style.display = '';
        if (submitRow)   submitRow.style.display = 'none';
        if (submitBtn)   submitBtn.disabled = true;
        if (fileInput)   fileInput.value = '';

        setState('upload');
    }

    // ── Session photo restore ───────────────────────────────────────────────

    function _restoreSavedPhoto(dataUrl) {
        const img = new window.Image();
        img.onload = () => {
            _currentFile = _dataUrlToFile(dataUrl, 'photo.jpg');
            _showUploadPreview(dataUrl, img.width <= img.height);
            _injectChangeSavedPhotoBtn();
        };
        img.onerror = () => {
            sessionStorage.removeItem('fitview_person_image_url');
        };
        img.src = dataUrl;
    }

    function _injectChangeSavedPhotoBtn() {
        if (el('fv-change-saved-photo')) return;
        const previewWrap = el('fv-preview-wrap');
        if (!previewWrap) return;
        const btn = document.createElement('button');
        btn.id          = 'fv-change-saved-photo';
        btn.type        = 'button';
        btn.className   = 'fv-change-saved-photo-btn';
        btn.textContent = 'Zmień zdjęcie';
        btn.addEventListener('click', _resetUpload);
        previewWrap.appendChild(btn);
    }

    function _dataUrlToFile(dataUrl, filename) {
        const [header, data] = dataUrl.split(',');
        const mime  = header.match(/:(.*?);/)[1];
        const bytes = atob(data);
        const arr   = new Uint8Array(bytes.length);
        for (let i = 0; i < bytes.length; i++) arr[i] = bytes.charCodeAt(i);
        return new File([arr], filename, { type: mime });
    }

    // ── SVG assets ──────────────────────────────────────────────────────────

    function _eyeIconSVG() {
        return `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
            <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z" fill="currentColor"/>
        </svg>`;
    }

    // ── Public API ──────────────────────────────────────────────────────────

    return { init, openModal, closeModal, setState };
})();

// ── Bootstrap ───────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    if (typeof fitviewData !== 'undefined') {
        FitView.init(fitviewData);
    }
});
