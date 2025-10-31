# WooCommerce Advanced Data Exporter

Zaawansowana wtyczka WordPress do eksportu danych WooCommerce z przetwarzaniem w tle, zaprojektowana do obsługi dużych sklepów bez ryzyka timeout'ów.

## 🎯 Funkcje

- **Przetwarzanie w Tle**: Wszystkie eksporty są przetwarzane asynchronicznie przez WP Cron
- **Batch Processing**: Dane są pobierane i zapisywane w paczkach (500 rekordów), aby uniknąć problemów z pamięcią
- **Dwa Typy Eksportu**:
  - **Marketing**: Agregowane dane klientów (jeden wiersz per email)
  - **Analityka**: Szczegółowe dane zamówień (jeden wiersz per produkt)
- **Bezpieczne Pobieranie**: Pliki chronione hashem i kontrolą uprawnień
- **Email Notifications**: Automatyczne powiadomienia po zakończeniu eksportu
- **Historia Eksportów**: Przegląd wszystkich wygenerowanych eksportów

## 📋 Wymagania

- **WordPress**: 6.5 lub wyższy
- **WooCommerce**: 8.0 lub wyższy
- **PHP**: 8.0 lub wyższy
- **Composer**: Do instalacji zależności

## 🚀 Instalacja

### 1. Klonowanie Repozytorium

```bash
cd wp-content/plugins/
git clone https://github.com/dawidkawalec/wp-data-exporter.git data-exporter
cd data-exporter
```

### 2. Instalacja Zależności

```bash
php composer.phar install --no-dev
```

Lub jeśli masz Composer zainstalowany globalnie:

```bash
composer install --no-dev
```

### 3. Aktywacja Wtyczki

1. Przejdź do WordPress Admin → Wtyczki
2. Znajdź "WooCommerce Advanced Data Exporter"
3. Kliknij "Aktywuj"

Po aktywacji wtyczka automatycznie:
- Utworzy tabelę `wp_export_jobs` w bazie danych
- Utworzy katalog `/wp-content/uploads/woo-exporter/` dla plików
- Zaplanuje zadanie cron (wykonywane co 5 minut)

## 📊 Typy Eksportu

### Eksport Marketingowy

**Kolumny CSV:**
- `email` - Adres email klienta
- `first_name` - Imię
- `last_name` - Nazwisko
- `zgoda_marketingowa` - Zgoda marketingowa (⚠️ wymaga konfiguracji)
- `total_spent` - Suma wydanych środków
- `order_count` - Liczba zamówień
- `last_order_date` - Data ostatniego zamówienia

**Zastosowanie:** Kampanie email marketingowe, segmentacja klientów, CRM

### Eksport Analityczny

**Kolumny CSV:**
- `order_id` - ID zamówienia
- `order_date` - Data zamówienia
- `order_status` - Status zamówienia
- `order_total` - Wartość zamówienia
- `order_currency` - Waluta
- `billing_email` - Email rozliczeniowy
- `billing_phone` - Telefon
- `billing_full_name` - Pełne imię i nazwisko
- `billing_city` - Miasto
- `billing_postcode` - Kod pocztowy
- `user_id` - ID użytkownika WordPress
- `item_name` - Nazwa produktu
- `item_quantity` - Ilość
- `item_total` - Wartość pozycji
- `coupons_used` - Użyte kupony
- `zgoda_marketingowa` - Zgoda marketingowa (⚠️ wymaga konfiguracji)

**Zastosowanie:** Analiza sprzedaży, raporty produktowe, analiza kohort

## ⚠️ WAŻNE: Konfiguracja Zgody Marketingowej

**BLOKER:** Pole `zgoda_marketingowa` obecnie zawiera placeholder `'TODO_FIND_MARKETING_CONSENT'`.

### Jak znaleźć lokalizację pola:

1. **Sprawdź wp_postmeta** (meta zamówienia):
```sql
SELECT meta_key, meta_value 
FROM wp_postmeta 
WHERE post_id IN (SELECT ID FROM wp_posts WHERE post_type = 'shop_order' LIMIT 5)
AND meta_key LIKE '%zgoda%' OR meta_key LIKE '%consent%' OR meta_key LIKE '%marketing%';
```

2. **Sprawdź wp_usermeta** (meta użytkownika):
```sql
SELECT meta_key, meta_value 
FROM wp_usermeta 
WHERE meta_key LIKE '%zgoda%' OR meta_key LIKE '%consent%' OR meta_key LIKE '%marketing%';
```

3. **Sprawdź niestandardowe tabele** innych wtyczek

### Jak zaktualizować kod:

Po znalezieniu właściwego `meta_key`, edytuj plik:
`src/Export/DataQuery.php`

**Jeśli to `wp_postmeta`:**
```php
// Znajdź linię 43 i 142 i zamień:
'TODO_FIND_MARKETING_CONSENT' as zgoda_marketingowa

// Na:
pm_consent.meta_value as zgoda_marketingowa

// Dodaj JOIN przed WHERE:
LEFT JOIN {$wpdb->postmeta} pm_consent ON p.ID = pm_consent.post_id 
    AND pm_consent.meta_key = 'TWOJ_META_KEY'
```

**Jeśli to `wp_usermeta`:**
```php
// Zamień na:
um_consent.meta_value as zgoda_marketingowa

// Dodaj JOIN:
LEFT JOIN {$wpdb->usermeta} um_consent ON pm_customer_id.meta_value = um_consent.user_id 
    AND um_consent.meta_key = 'TWOJ_META_KEY'
```

## 🔧 Użycie

### Tworzenie Eksportu

1. Przejdź do **Eksport Danych** w menu WordPress Admin
2. Wybierz **Typ Eksportu** (Marketing lub Analityka)
3. Opcjonalnie ustaw filtry dat
4. Kliknij **Generuj Eksport**
5. Otrzymasz email z linkiem do pobrania po zakończeniu

### Historia Eksportów

1. Przejdź do zakładki **Historia Eksportów**
2. Zobacz status wszystkich swoich eksportów
3. Pobierz ukończone pliki

### Ręczne Uruchomienie Cron

Jeśli WP Cron nie działa automatycznie:

```bash
# W katalogu głównym WordPress
wp cron event run woo_exporter_process_jobs
```

Lub dodaj do crontab systemowego:

```bash
*/5 * * * * cd /path/to/wordpress && php wp-cron.php > /dev/null 2>&1
```

## 📁 Struktura Projektu

```
data-exporter/
├── src/
│   ├── Admin/
│   │   ├── AdminPage.php      # Panel administracyjny
│   │   └── AjaxHandler.php    # Obsługa AJAX
│   ├── Cron/
│   │   └── ExportWorker.php   # Worker przetwarzający zadania
│   ├── Database/
│   │   ├── Schema.php         # Schemat bazy danych
│   │   └── Job.php            # Model zadania
│   ├── Download/
│   │   └── FileHandler.php    # Obsługa pobierania plików
│   └── Export/
│       ├── DataQuery.php      # Zapytania SQL
│       └── CsvGenerator.php   # Generator CSV
├── assets/
│   ├── css/
│   │   └── admin.css          # Style panelu
│   └── js/
│       └── admin.js           # JavaScript panelu
├── vendor/                     # Zależności Composer (nie w repo)
├── uploads/                    # Wygenerowane pliki (nie w repo)
├── composer.json              # Konfiguracja Composer
├── woo-data-exporter.php     # Główny plik wtyczki
└── README.md                  # Ta dokumentacja
```

## 🛡️ Bezpieczeństwo

- **Nonce Verification**: Wszystkie żądania AJAX są weryfikowane
- **Capability Checks**: Tylko użytkownicy z uprawnieniami `manage_woocommerce`
- **Protected Downloads**: Pliki chronione hashem i kontrolą uprawnień
- **Expiration**: Pliki automatycznie wygasają po 7 dniach
- **Directory Protection**: Katalog uploads chroniony `.htaccess`

## 🐛 Debugging

### Włączanie logów WordPress

W `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Logi w: `wp-content/debug.log`

### Sprawdzanie statusu zadań

```sql
SELECT * FROM wp_export_jobs ORDER BY created_at DESC LIMIT 10;
```

### Testowanie zapytań SQL

Edytuj `src/Export/DataQuery.php` i dodaj:

```php
error_log('SQL Query: ' . $sql);
error_log('Results count: ' . count($results));
```

## 📝 Notatki Techniczne

### Batch Processing

- Domyślny rozmiar paczki: **500 rekordów**
- Można zmienić w `src/Cron/ExportWorker.php` → `BATCH_SIZE`
- Maksymalny czas wykonania cron: **45 sekund**

### Wydajność SQL

Zapytania używają:
- Bezpośrednich JOIN'ów zamiast WooCommerce API
- Indeksów na `post_type`, `post_status`, `meta_key`
- LIMIT i OFFSET dla paginacji

### Memory Management

- `unset()` po każdej paczce
- Brak ładowania całego resultsetu do pamięci
- League\CSV używa stream'ów

## 🔄 Aktualizacje

```bash
cd wp-content/plugins/data-exporter
git pull origin main
php composer.phar install --no-dev
```

## 📞 Support

- **Issues**: https://github.com/dawidkawalec/wp-data-exporter/issues
- **Email**: [twój email]

## 📄 Licencja

GPL-3.0-or-later

## 🙏 Credits

- **League CSV**: https://csv.thephpleague.com/
- **Autor**: Dawid Kawalec

