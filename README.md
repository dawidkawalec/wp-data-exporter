# WooCommerce Advanced Data Exporter

Zaawansowana wtyczka WordPress do eksportu danych WooCommerce z przetwarzaniem w tle, zaprojektowana do obsługi dużych sklepów bez ryzyka timeout'ów.

## 🎯 Funkcje

- **Przetwarzanie w Tle**: Wszystkie eksporty są przetwarzane asynchronicznie przez WP Cron
- **Batch Processing**: Dane są pobierane i zapisywane w paczkach (500 rekordów), aby uniknąć problemów z pamięcią
- **Dwa Typy Eksportu**:
  - **Marketing**: Agregowane dane klientów (jeden wiersz per email)
  - **Analityka**: Szczegółowe dane zamówień (jeden wiersz per produkt)
- **Custom Email Notifications**: Wysyłka powiadomień na dowolne adresy email (wiele odbiorców)
- **Cykliczne Raporty**: Automatyczne generowanie raportów według harmonogramu
  - Co X dni (codziennie, co tydzień, co 2 tygodnie, etc.)
  - Co tydzień w określony dzień (poniedziałek, wtorek, etc.)
  - Co miesiąc w określony dzień (1., 15., ostatni dzień, etc.)
- **Zarządzanie Harmonogramami**: Edycja, pauza, wznowienie, usuwanie
- **Historia Harmonogramów**: Zobacz wszystkie raporty wygenerowane z danego harmonogramu
- **Podgląd CSV z Paginacją**: Przeglądaj dane bezpośrednio w panelu (100 wierszy/strona)
- **Bezpieczne Pobieranie**: Pliki chronione hashem i kontrolą uprawnień
- **Historia Eksportów**: Przegląd wszystkich wygenerowanych eksportów z opcją usuwania

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

## ✅ Konfiguracja Zgody Marketingowej - ROZWIĄZANE

Pole `zgoda_marketingowa` jest automatycznie parsowane z `_additional_terms` (WooCommerce Checkout Manager).

### Jak to działa:

1. Wtyczka pobiera pole `_additional_terms` z `wp_postmeta`
2. To pole zawiera PHP serialized array z checkboxami z checkout
3. Funkcja `parse_consent_field()` automatycznie:
   - Deserializuje dane
   - Znajduje checkbox o nazwie "Zgoda marketingowa" / "consent" / "marketing"
   - Zwraca "tak" (zaznaczone) lub "nie" (odznaczone)

### Jeśli używasz innej wtyczki:

Użyj narzędzia diagnostycznego: `/wp-content/plugins/data-exporter/debug-meta-keys.php`

1. Otwórz w przeglądarce jako admin
2. Szukaj: `zgoda`, `consent`, `marketing`
3. Znajdź właściwy `meta_key`
4. Edytuj `src/Export/DataQuery.php` linia ~60 i ~209:
   ```php
   LEFT JOIN {$wpdb->postmeta} pm_consent ON p.ID = pm_consent.post_id 
       AND pm_consent.meta_key = 'TWOJ_META_KEY'
   ```

## 🔧 Użycie

### Tworzenie Jednorazowego Eksportu

1. Przejdź do **Eksport Danych** → **Nowy Eksport**
2. Wybierz **Typ Eksportu** (Marketing lub Analityka)
3. Opcjonalnie ustaw filtry dat
4. **NOWOŚĆ:** Opcjonalnie podaj email(e) do powiadomienia (oddzielone przecinkami)
5. Kliknij **Generuj Eksport**
6. Otrzymasz email z linkiem do pobrania po zakończeniu

### Zaplanowane Raporty (Cykliczne)

1. Przejdź do **Eksport Danych** → **Zaplanowane Raporty**
2. Kliknij **+ Dodaj Nowy Harmonogram**
3. Wypełnij formularz:
   - **Nazwa**: np. "Raport tygodniowy Marketing"
   - **Typ eksportu**: Marketing lub Analityka
   - **Częstotliwość**:
     - **Codziennie / Co X dni**: np. 7 = co tydzień, 14 = co 2 tygodnie
     - **Co tydzień**: wybierz dzień tygodnia (1=Pon, 7=Nie)
     - **Co miesiąc**: wybierz dzień miesiąca (1-31)
   - **Data rozpoczęcia**: Kiedy zacząć
   - **Email powiadomienia**: Gdzie wysyłać raporty (wiele adresów OK)
4. Kliknij **Zapisz Harmonogram**
5. Harmonogram będzie automatycznie generował eksporty!

### Zarządzanie Harmonogramami

- **Edytuj**: Zmień ustawienia harmonogramu
- **Pauza/Wznów**: Tymczasowo zatrzymaj lub wznów harmonogram
- **📊 Ikona**: Zobacz historię wszystkich raportów wygenerowanych z tego harmonogramu
- **Usuń**: Usuń harmonogram (nie wpłynie na już wygenerowane pliki)

### Historia Eksportów

1. Przejdź do zakładki **Historia Eksportów**
2. Zobacz status wszystkich swoich eksportów
3. **Podgląd**: Przeglądaj CSV w przeglądarce (100 wierszy/strona, paginacja)
4. **Pobierz**: Ściągnij plik CSV
5. **Usuń**: Usuń stary eksport
6. **Uruchom Cron Ręcznie**: (tylko admin) Natychmiastowe przetworzenie pending jobów

### Ręczne Uruchomienie Cron

**Opcja 1: Przycisk w panelu (zalecane)**
- Przejdź do zakładki "Historia Eksportów"
- Kliknij **"Uruchom Cron Ręcznie"** (tylko dla adminów)
- Automatyczne przetwarzanie wszystkich pending jobów

**Opcja 2: WP-CLI**
```bash
# Przetwarzanie eksportów (co 5 min)
wp cron event run woo_exporter_process_jobs

# Sprawdzanie harmonogramów (co 1h)
wp cron event run woo_exporter_check_schedules
```

**Opcja 3: Systemowy crontab**
```bash
*/5 * * * * cd /path/to/wordpress && php wp-cron.php > /dev/null 2>&1
```

## 📁 Struktura Projektu

```
data-exporter/
├── src/
│   ├── Admin/
│   │   ├── AdminPage.php      # Panel administracyjny (3 zakładki)
│   │   └── AjaxHandler.php    # Obsługa AJAX (jobs + schedules)
│   ├── Cron/
│   │   ├── ExportWorker.php   # Worker przetwarzający zadania
│   │   └── ScheduleWorker.php # Worker sprawdzający harmonogramy
│   ├── Database/
│   │   ├── Schema.php         # Schemat bazy danych (2 tabele)
│   │   ├── Job.php            # Model zadania
│   │   └── Schedule.php       # Model harmonogramu
│   ├── Download/
│   │   └── FileHandler.php    # Obsługa pobierania plików
│   └── Export/
│       ├── DataQuery.php      # Zapytania SQL
│       └── CsvGenerator.php   # Generator CSV
├── assets/
│   ├── css/
│   │   └── admin.css          # Style panelu + modali
│   └── js/
│       └── admin.js           # JavaScript (jobs + schedules + preview)
├── vendor/                     # Zależności Composer (nie w repo)
├── uploads/                    # Wygenerowane pliki (nie w repo)
├── debug-meta-keys.php        # Narzędzie diagnostyczne
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

### Zadania Cron

- **woo_exporter_process_jobs**: Co 5 minut - przetwarza pending eksporty
- **woo_exporter_check_schedules**: Co 1 godzinę - sprawdza harmonogramy i tworzy nowe joby

### Wydajność SQL

Zapytania używają:
- Bezpośrednich JOIN'ów zamiast WooCommerce API
- Indeksów na `post_type`, `post_status`, `meta_key`
- LIMIT i OFFSET dla paginacji

### Memory Management

- `unset()` po każdej paczce
- Brak ładowania całego resultsetu do pamięci
- League\CSV używa stream'ów

### Tabele Bazy Danych

1. **wp_export_jobs**: Kolejka eksportów
   - Kolumny: id, job_type, status, filters, file_path, notification_email, schedule_id, etc.
   - Indeksy: status, job_type, schedule_id, created_at

2. **wp_export_schedules**: Harmonogramy
   - Kolumny: id, name, job_type, frequency_type, frequency_value, next_run_date, etc.
   - Indeksy: next_run_date, is_active, created_by

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

