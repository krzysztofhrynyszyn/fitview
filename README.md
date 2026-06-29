# FitView — Virtual Try-On

Wirtualna przymierzalnia AI dla WooCommerce. Klienci widzą jak będą wyglądać w ubraniu **przed zakupem**, używając modelu `fashn/tryon` z platformy [fal.ai](https://fal.ai).

---

## Wymagania

| Wymaganie | Minimalna wersja |
|-----------|-----------------|
| WordPress | 6.0 |
| WooCommerce | 7.0 |
| PHP | 8.0 |
| Klucz API fal.ai | — |

---

## Instalacja

1. Skopiuj folder `fitview/` do katalogu `/wp-content/plugins/`.
2. W panelu WordPress przejdź do **Wtyczki → Zainstalowane wtyczki**.
3. Kliknij **Aktywuj** przy wtyczce *FitView — Virtual Try-On*.
4. Upewnij się, że WooCommerce jest aktywny — wtyczka wyświetli komunikat jeśli nie jest.

---

## Konfiguracja

### 1. Klucz API fal.ai

1. Zarejestruj się na [fal.ai](https://fal.ai) i wygeneruj klucz API w panelu.
2. W WordPress przejdź do **WooCommerce → FitView**.
3. Wklej klucz w polu **Klucz API fal.ai** i kliknij **Testuj połączenie z fal.ai**.
4. Powinieneś zobaczyć `✓ Połączenie OK`.
5. Kliknij **Zapisz ustawienia**.

> **Bezpieczeństwo:** Klucz API jest przechowywany wyłącznie po stronie serwera i nigdy nie trafia do przeglądarki.

### 2. Tryb generowania

| Tryb | Czas | Jakość |
|------|------|--------|
| `performance` | ~15 s | dobra |
| `balanced` (domyślny) | ~25 s | bardzo dobra |
| `quality` | ~40 s | najlepsza |

### 3. Pozycja przycisku

- **Po cenie produktu** — wyświetla pasek CTA bezpośrednio pod ceną
- **Po przycisku „Dodaj do koszyka"** *(domyślne)* — wyświetla pasek CTA poniżej przycisku zakupu

---

## Testowanie po instalacji

1. Przejdź do **WooCommerce → FitView → Testuj połączenie z fal.ai** → `✓ Połączenie OK`.
2. Otwórz kartę dowolnego produktu z ustawionym zdjęciem głównym.
3. W prawym dolnym rogu zdjęcia powinna pojawić się ikona oka (FAB).  
   Pod przyciskiem „Dodaj do koszyka" powinien pojawić się pasek *FitView — Przymierz wirtualnie*.
4. Kliknij ikonę lub pasek → modal się otwiera.
5. Dodaj zdjęcie sylwetki (JPG/PNG, maks. 10 MB) → kliknij **Generuj wizualizację**.
6. Po 20–40 sekundach pojawia się wynik z fal.ai.

---

## Opcjonalne konta użytkowników (OAuth)

Funkcja pozwala użytkownikom logować się przez Google lub Meta, aby zapisywać swoje zdjęcia między sesjami.

### Włączenie

W **WooCommerce → FitView** zaznacz *Włącz logowanie przez Google / Meta*.

### Konfiguracja Google OAuth

1. Utwórz projekt w [Google Cloud Console](https://console.cloud.google.com).
2. Włącz **Google OAuth 2.0 API** i utwórz dane uwierzytelniające typu *Web application*.
3. Dodaj URI przekierowania:  
   `https://twojadomena.pl/?fitview_oauth=google`
4. Wstaw klucze do bazy danych:

```sql
INSERT INTO wp_options (option_name, option_value) VALUES
  ('fitview_google_client_id', 'TWÓJ_CLIENT_ID'),
  ('fitview_google_client_secret', 'TWÓJ_CLIENT_SECRET');
```

Lub przez WP-CLI:
```bash
wp option update fitview_google_client_id "TWÓJ_CLIENT_ID"
wp option update fitview_google_client_secret "TWÓJ_CLIENT_SECRET"
```

### Konfiguracja Meta (Facebook) OAuth

1. Utwórz aplikację w [Meta for Developers](https://developers.facebook.com).
2. Dodaj produkt **Facebook Login** i ustaw URI przekierowania:  
   `https://twojadomena.pl/?fitview_oauth=meta`
3. Wstaw klucze:

```bash
wp option update fitview_meta_app_id "TWÓJ_APP_ID"
wp option update fitview_meta_app_secret "TWÓJ_APP_SECRET"
```

---

## Architektura

```
Przeglądarka (fitview-frontend.js)
    │
    ├── POST /wp-json/fitview/v1/tryon
    │       ↓
    │   class-fitview-rest.php
    │       │  walidacja, rate limiting, temp file
    │       ↓
    │   class-fitview-api.php
    │       │  Authorization: Key {klucz_api}
    │       ↓
    │   fal.ai queue.fal.run/fashn/tryon
    │       ↓
    │   zwraca job_id
    │
    └── GET /wp-json/fitview/v1/status/{job_id}  (polling co 2s)
            ↓
        class-fitview-rest.php → class-fitview-api.php → fal.ai
            ↓
        status: COMPLETED + result_url
            ↓
        Przeglądarka wyświetla wynik
```

---

## Bezpieczeństwo

| Zabezpieczenie | Implementacja |
|----------------|---------------|
| CSRF protection | WP REST nonce (`X-WP-Nonce` header) na każdym endpoincie |
| API key isolation | Klucz API tylko w PHP; nigdy nie trafia do JS ani odpowiedzi REST |
| Input sanitization | `sanitize_text_field`, `absint`, `wp_check_filetype` |
| Output escaping | `esc_html`, `esc_url`, `esc_attr` we wszystkich szablonach |
| Temp file cleanup | `wp_tempnam` + `unlink` bezpośrednio po wysłaniu do fal.ai |
| Rate limiting | Maks. 10 requestów/użytkownik/godzina (WordPress transients) |
| MIME validation | Weryfikacja `mime_content_type` + rozszerzenie pliku |
| OAuth CSRF | Weryfikacja `state` parameter przy callback |

---

## Struktura plików

```
fitview/
├── fitview.php                      # Główny plik wtyczki
├── includes/
│   ├── class-fitview-plugin.php     # Hooki frontend, enqueue assetów
│   ├── class-fitview-api.php        # Komunikacja z fal.ai
│   ├── class-fitview-rest.php       # REST API endpointy
│   ├── class-fitview-auth.php       # OAuth Google i Meta
│   └── class-fitview-admin.php      # Panel ustawień wp-admin
├── assets/
│   ├── js/fitview-frontend.js       # Logika modalu (vanilla JS)
│   └── css/fitview-frontend.css     # Style (design system FitView)
├── templates/
│   └── modal.php                    # HTML modalu
└── README.md
```

---

## Design system (tokeny CSS)

Wszystkie klasy używają prefiksu `fv-` i bazują na zmiennych CSS:

```css
--fv-teal:   #00E5C4   /* główny akcent */
--fv-lime:   #AEFF47   /* CTA "Dodaj do koszyka" */
--fv-ink:    #0A1F1C   /* tekst główny */
--fv-mint:   #F0FDF8   /* tła */
```

---

## Licencja

GPL-2.0+  
Copyright © 2025 FitView Team
