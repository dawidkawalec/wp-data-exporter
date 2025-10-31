# PRD: WooCommerce Advanced Exporter
- **Wersja:** 1.0
- **Data:** 2025-10-31
- **Autor:** Architekt PRD & Dawid

## 1. Executive Summary

Projekt polega na stworzeniu zaawansowanej wtyczki do WordPress/WooCommerce, która umożliwia eksport danych o zamówieniach i klientach do plików CSV. Wtyczka będzie oferować dwa wyspecjalizowane formaty eksportu: jeden zoptymalizowany dla działań marketingowych (unikalni klienci) i drugi dla celów analitycznych (szczegółowe dane o produktach w zamówieniach). Aby zapewnić skalowalność i uniknąć timeoutów na dużych sklepach, proces generowania plików będzie realizowany asynchronicznie w tle przy użyciu WP Cron i dedykowanej tabeli do zarządzania zadaniami.

## 2. Główne Cele i User Stories

- **Cel Biznesowy:** Umożliwienie zespołowi marketingu i analityki samodzielnego i niezawodnego pozyskiwania danych ze sklepu WooCommerce bez angażowania deweloperów.
- **User Story 1 (Marketing):** Jako pracownik marketingu, chcę jednym kliknięciem wygenerować listę unikalnych klientów z ich zgodami marketingowymi, abym mógł łatwo importować te dane do naszego systemu mailingowego.
- **User Story 2 (Analityka):** Jako analityk, chcę wygenerować szczegółowy raport sprzedaży, gdzie każdy wiersz odpowiada jednemu produktowi w zamówieniu, abym mógł przeprowadzić dogłębną analizę sprzedaży produktów i zachowań klientów.

## 3. Architektura Systemu

System opiera się na architekturze zadań w tle (background jobs), aby zapewnić stabilność i skalowalność.

**Przepływ działania:**
1.  **UI (Panel Admina):** Użytkownik w panelu `/wp-admin/` wybiera typ eksportu i filtry (np. daty), a następnie klika "Generuj".
2.  **AJAX Request:** Przeglądarka wysyła asynchroniczny request do endpointu `admin-ajax.php`, zlecając utworzenie zadania.
3.  **Job Creation:** Backend WordPressa tworzy nowy wpis w dedykowanej tabeli `wp_export_jobs` ze statusem `pending` i parametrami eksportu.
4.  **Cron Worker:** Co kilka minut, zadanie WP Cron sprawdza tabelę `wp_export_jobs` w poszukiwaniu zadań `pending`.
5.  **Processing:** Gdy worker znajdzie zadanie, zmienia jego status na `processing` i zaczyna generować plik CSV, pobierając dane z bazy w małych paczkach (np. 200-500 rekordów na iterację), aby uniknąć problemów z pamięcią i czasem wykonania skryptu.
6.  **Finalizacja:** Po przetworzeniu wszystkich danych, status zadania jest zmieniany na `completed`, plik jest zapisywany w bezpiecznej lokalizacji, a do użytkownika wysyłany jest email z linkiem do pobrania. Ukończone zadania są widoczne w panelu wtyczki w zakładce "Historia Eksportów".

## 4. Stack Technologiczny

- **Backend:** PHP 8.0+
- **Framework:** WordPress 6.5+, WooCommerce 8.0+
- **Zarządzanie Zależnościami:** Composer
- **Kluczowe Biblioteki:**
    - `league/csv`: Do bezpiecznego i wydajnego generowania plików CSV.
- **Struktura Kodu:** Autoloading PSR-4, przestrzenie nazw (np. `WooExporter\...`).
- **Baza Danych:** Dostęp przez globalny obiekt `$wpdb` z użyciem zapytań SQL dla maksymalnej wydajności.

## 5. Schemat Bazy Danych

Do zarządzania zadaniami zostanie utworzona nowa tabela.



      
    
sql
CREATE TABLE wp_export_jobs (
id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
job_type VARCHAR(50) NOT NULL COMMENT 'Typ: marketing_export lub analytics_export',
status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'Status: pending, processing, completed, failed',
filters JSON NULL,
file_path VARCHAR(255) NULL COMMENT 'Ścieżka do wygenerowanego pliku',
file_url_hash VARCHAR(64) NULL COMMENT 'Bezpieczny hash do generowania linku pobierania',
error_message TEXT NULL,
requester_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'ID usera, który zlecił zadanie',
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (id),
INDEX status_idx (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


## 6. API Endpoints

**Endpoint do tworzenia zadań eksportu**

- **Akcja (Action):** `create_export_job`
- **URL:** `/wp-admin/admin-ajax.php`
- **Metoda:** `POST`
- **Parametry:**
    - `action: 'create_export_job'` (string, wymagane)
    - `nonce: '...'` (string, wymagane, z `wp_create_nonce`)
    - `export_type: 'marketing' | 'analytics'` (string, wymagane)
    - `filters: '{ "start_date": "YYYY-MM-DD", "end_date": "YYYY-MM-DD" }'` (string, opcjonalny, JSON)
- **Odpowiedź (sukces, 200 OK):**
  

      
    
json
{ "success": true, "message": "Zadanie eksportu zostało dodane do kolejki." }

- **Odpowiedź (błąd, 4xx/5xx):**
  

      
    
json
{ "success": false, "message": "Wystąpił błąd: [opis]" }


## 7. Struktura Eksportów (Pliki CSV)

### 7.1. Eksport Marketingowy
**Cel:** Unikalni klienci. Jeden wiersz per adres e-mail.

**Kolumny:**
- `email`
- `first_name`
- `last_name`
- `zgoda_marketingowa`
- `total_spent`
- `order_count`
- `last_order_date`

### 7.2. Eksport Analityczny
**Cel:** Szczegółowa analiza. Jeden wiersz per produkt w zamówieniu.

**Kolumny:**
- `order_id`
- `order_date`
- `order_status`
- `order_total`
- `order_currency`
- `billing_email`
- `billing_phone`
- `billing_full_name`
- `billing_city`
- `billing_postcode`
- `user_id`
- `item_name`
- `item_quantity`
- `item_total`
- `coupons_used`
- `zgoda_marketingowa`

## 8. Plan Wdrożenia (Fazy)

- **Faza 0: Dochodzenie (BLOKER)**
    - **Zadanie:** Zidentyfikować, gdzie w bazie danych przechowywana jest informacja o `zgoda_marketingowa`. Możliwe lokalizacje: `wp_postmeta`, `wp_usermeta`, dedykowana tabela innej wtyczki. **Bez tej informacji implementacja logiki jest niemożliwa.**
- **Faza 1: Inicjalizacja projektu**
    - Stworzenie struktury plików wtyczki.
    - Konfiguracja `composer.json` (PSR-4, `league/csv`).
    - Implementacja logiki tworzenia tabeli `wp_export_jobs` przy aktywacji wtyczki.
- **Faza 2: Implementacja Backendu (Worker)**
    - Stworzenie logiki zadania WP Cron, która pobiera i przetwarza zadania.
    - Implementacja zapytań SQL do pobierania danych (z placeholderem dla zgody marketingowej).
    - Integracja `league/csv` do generowania plików w paczkach.
    - Implementacja logiki wysyłki e-mail po zakończeniu zadania.
- **Faza 3: Implementacja Frontendu (Panel Admina)**
    - Stworzenie strony w panelu admina z dwiema zakładkami ("Nowy Eksport", "Historia Eksportów").
    - Implementacja formularza z filtrami i przyciskami.
    - Napisanie kodu JavaScript (AJAX) do komunikacji z backendem.
    - Stworzenie widoku historii z użyciem `WP_List_Table`.
- **Faza 4: Testy i Wdrożenie**
    - Testy na środowisku deweloperskim z dużą ilością danych.
    - Testy timeoutów i zużycia pamięci.
    - Wdrożenie na środowisko produkcyjne.

## 9. Ryzyka

1.  **Nieznana lokalizacja `zgoda_marketingowa` (Bloker):** Brak możliwości finalizacji logiki zapytań SQL bez tej informacji.
2.  **Niezawodność WP Cron:** Na niektórych hostingach WP Cron jest zawodny. Może być konieczne skonfigurowanie systemowego crona na serwerze, który będzie wywoływał `wp-cron.php`.
3.  **Wydajność zapytań:** Przy milionach zamówień nawet zoptymalizowane zapytania SQL mogą być wolne. Wymaga to dokładnego indeksowania tabel.
4.  **Konflikty wtyczek:** Istnieje ryzyko konfliktów z innymi wtyczkami, szczególnie tymi modyfikującymi proces zamówienia.

## 10. Metryki Sukcesu

- **Stabilność:** Wtyczka pomyślnie generuje eksporty dla >99% zleconych zadań.
- **Wydajność:** Czas generowania eksportu dla 100 000 zamówień nie przekracza akceptowalnego progu (np. 15-20 minut).
- **Użyteczność:** Zespół marketingu i analityki regularnie i samodzielnie korzysta z wtyczki, redukując liczbę zapytań do działu IT.