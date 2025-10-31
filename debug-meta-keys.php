<?php
/**
 * Debug script - pokazuje wszystkie meta keys z zamówienia
 * Uruchom: https://twoja-domena.local/wp-content/plugins/data-exporter/debug-meta-keys.php
 */

// Load WordPress
require_once '../../../wp-load.php';

// Check if user is admin
if (!current_user_can('manage_options')) {
    die('Brak uprawnień - musisz być zalogowany jako admin');
}

global $wpdb;

// Get latest order ID
$latest_order = $wpdb->get_var("
    SELECT ID 
    FROM {$wpdb->posts} 
    WHERE post_type = 'shop_order' 
    AND post_status IN ('wc-completed', 'wc-processing')
    ORDER BY post_date DESC 
    LIMIT 1
");

if (!$latest_order) {
    die('Brak zamówień w bazie');
}

// Get all meta keys from this order
$all_meta = $wpdb->get_results($wpdb->prepare("
    SELECT meta_key, meta_value 
    FROM {$wpdb->postmeta} 
    WHERE post_id = %d 
    ORDER BY meta_key
", $latest_order));

?>
<!DOCTYPE html>
<html>
<head>
    <title>Debug Meta Keys - Zamówienie #<?php echo $latest_order; ?></title>
    <style>
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
            padding: 20px;
            background: #f0f0f1;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 { color: #1d2327; }
        .search {
            margin: 20px 0;
            padding: 10px;
            background: #f6f7f7;
            border-radius: 4px;
        }
        input[type="text"] {
            width: 100%;
            padding: 10px;
            font-size: 16px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e5e5;
        }
        th {
            background: #f6f7f7;
            font-weight: 600;
            position: sticky;
            top: 0;
        }
        tr:hover {
            background: #f9f9f9;
        }
        .meta-key {
            font-family: 'Courier New', monospace;
            color: #0073aa;
            font-weight: 500;
        }
        .meta-value {
            color: #646970;
            max-width: 600px;
            word-wrap: break-word;
        }
        .highlight {
            background: yellow;
            padding: 2px 4px;
        }
        .stats {
            background: #d1ecf1;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Debug Meta Keys - Zamówienie #<?php echo $latest_order; ?></h1>
        
        <div class="stats">
            <strong>Znaleziono:</strong> <?php echo count($all_meta); ?> meta keys
        </div>

        <div class="search">
            <input type="text" id="searchInput" placeholder="🔍 Szukaj po meta_key (np. 'zgoda', 'consent', 'marketing', 'gdpr', 'newsletter')..." onkeyup="filterTable()">
        </div>

        <table id="metaTable">
            <thead>
                <tr>
                    <th style="width: 40%">Meta Key</th>
                    <th style="width: 60%">Meta Value</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_meta as $meta): ?>
                <tr>
                    <td class="meta-key"><?php echo esc_html($meta->meta_key); ?></td>
                    <td class="meta-value"><?php echo esc_html(substr($meta->meta_value, 0, 200)); ?><?php echo strlen($meta->meta_value) > 200 ? '...' : ''; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script>
        function filterTable() {
            const input = document.getElementById('searchInput');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('metaTable');
            const rows = table.getElementsByTagName('tr');

            for (let i = 1; i < rows.length; i++) {
                const key = rows[i].getElementsByClassName('meta-key')[0];
                const value = rows[i].getElementsByClassName('meta-value')[0];
                
                if (key && value) {
                    const keyText = key.textContent || key.innerText;
                    const valueText = value.textContent || value.innerText;
                    
                    if (keyText.toLowerCase().indexOf(filter) > -1 || valueText.toLowerCase().indexOf(filter) > -1) {
                        rows[i].style.display = '';
                        
                        // Highlight search term
                        if (filter && keyText.toLowerCase().indexOf(filter) > -1) {
                            const regex = new RegExp('(' + filter + ')', 'gi');
                            key.innerHTML = keyText.replace(regex, '<span class="highlight">$1</span>');
                        } else {
                            key.textContent = keyText;
                        }
                    } else {
                        rows[i].style.display = 'none';
                    }
                }
            }
        }
    </script>
</body>
</html>

