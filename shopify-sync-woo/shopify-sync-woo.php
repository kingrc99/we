<?php
/*
Plugin Name: Shopify Sync for WooCommerce
Description: Sincronizza prodotti e inventario da Shopify su WooCommerce (Sync Manuale Pagina-per-Pagina Configurabile).
Version: 1.7.7
Author: Il Tuo Nome (con AI)
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: shopify-sync-woo
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// --- Costanti Principali ---
define( 'SSW_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SSW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SSW_VERSION', '1.7.7' ); // Versione aggiornata

// Nomi delle opzioni nel database di WordPress
define( 'SSW_OPTION_SHOPIFY_DOMAIN', 'ssw_shopify_domain' );
define( 'SSW_OPTION_API_KEY', 'ssw_shopify_api_key' ); // Nota: Chiave API Shopify standard, non usata per Admin API Access Token auth.
define( 'SSW_OPTION_API_PASSWORD', 'ssw_shopify_api_password' ); // Questo è l'Admin API Access Token
define( 'SSW_OPTION_ENABLE_IMAGE_SYNC', 'ssw_enable_image_sync' );
define( 'SSW_OPTION_BATCH_SIZE', 'ssw_batch_size' );
define( 'SSW_SETTINGS_GROUP', 'ssw_settings_group');
define( 'SSW_NEXT_PAGE_INFO_OPTION', 'ssw_next_page_info'); // Salva stato paginazione per sync manuale/cron
define( 'SSW_LAST_SYNC_SUMMARY_OPTION', 'ssw_last_sync_summary'); // Salva ultimo riepilogo pagina sync

// Nomi dei Meta Field usati sui prodotti/variazioni/allegati WC
define('SSW_META_SHOPIFY_PRODUCT_ID', '_ssw_shopify_product_id'); // Su prodotto WC padre
define('SSW_META_SHOPIFY_VARIANT_ID', '_ssw_shopify_variant_id'); // Su variazione WC
define('SSW_META_LAST_SYNC_TIME', '_ssw_last_sync_time'); // Su prodotto/variazione WC
define('SSW_META_SHOPIFY_IMAGE_ID', '_ssw_shopify_image_id'); // Su allegato WP, per tracciare ID immagine Shopify
define('SSW_META_SHOPIFY_FEATURED_IMAGE_ID', '_ssw_shopify_featured_image_id'); // Su allegato WP, se era l'immagine featured del prodotto Shopify
define('SSW_META_SHOPIFY_VARIANT_ID_FOR_IMAGE', '_ssw_shopify_variant_id_for_image'); // Su allegato WP, se l'immagine è specificamente per una variante Shopify


// --- Inclusione File Necessari ---
// Assicurati che l'ordine sia logico per le dipendenze
require_once SSW_PLUGIN_PATH . 'includes/api-handler.php';     // Gestore chiamate API Shopify
require_once SSW_PLUGIN_PATH . 'includes/sync-products.php';    // Logica di sincronizzazione prodotti e varianti
require_once SSW_PLUGIN_PATH . 'includes/admin-settings.php'; // Registrazione impostazioni plugin
require_once SSW_PLUGIN_PATH . 'includes/admin-dashboard.php'; // Pagina admin e UI


// --- Hook Menu Admin ---
add_action('admin_menu', 'ssw_register_admin_dashboard');
/**
 * Registra la pagina della dashboard del plugin nel menu admin di WordPress.
 */
function ssw_register_admin_dashboard() {
    add_menu_page(
        __('Shopify Sync Dashboard', 'shopify-sync-woo'),   // Titolo Pagina
        __('Shopify Sync', 'shopify-sync-woo'),             // Titolo Menu
        'manage_options',                                   // Capability richiesta
        'shopify-sync-woo-dashboard',                       // Slug Menu
        'ssw_render_dashboard_page',                        // Funzione che renderizza la pagina (da admin-dashboard.php)
        'dashicons-update-alt',                             // Icona Menu
        58                                                  // Posizione nel menu
    );
}


// --- AJAX Handler per Testare la Connessione API Shopify ---
add_action('wp_ajax_ssw_test_api_credentials', 'ssw_test_api_credentials_callback');
/**
 * Gestisce la richiesta AJAX per testare le credenziali API Shopify.
 * Riceve dominio e token via POST, esegue il test e restituisce un JSON.
 */
function ssw_test_api_credentials_callback() {
    check_ajax_referer('ssw_test_api_nonce', 'nonce'); // Verifica il nonce per sicurezza

    $domain = isset($_POST['domain']) ? sanitize_text_field(wp_unslash($_POST['domain'])) : null;
    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : null;

    if (empty($domain) || empty($token)) {
        wp_send_json_error(['message' => __('Dominio Shopify e Admin API Access Token sono obbligatori per il test.', 'shopify-sync-woo')]);
        return;
    }

    // Chiama la funzione di test passando le credenziali ricevute (da api-handler.php)
    $test_result = ssw_test_shopify_connection($domain, $token);

    if ($test_result) {
        wp_send_json_success(['message' => __('Connessione API Shopify riuscita!', 'shopify-sync-woo')]);
    } else {
        // Gli errori specifici dovrebbero essere già stati loggati da ssw_shopify_api_get()
        wp_send_json_error(['message' => __('Connessione API Shopify fallita. Controlla le credenziali, i permessi della Custom App su Shopify e i log PHP del server.', 'shopify-sync-woo')]);
    }
}


// --- Cron Job Automatico (Processa UNA pagina di prodotti ogni ora) ---
add_filter('cron_schedules', 'ssw_add_hourly_cron_schedule');
/**
 * Aggiunge un intervallo di cron personalizzato "ogni ora" se non esiste già.
 */
function ssw_add_hourly_cron_schedule($schedules) {
    if (!isset($schedules['ssw_hourly'])) {
        $schedules['ssw_hourly'] = [
            'interval' => 3600, // Ogni ora (in secondi)
            'display'  => __('Ogni Ora (Per Shopify Sync)', 'shopify-sync-woo')
        ];
    }
    return $schedules;
}

add_action('ssw_hourly_event', 'ssw_trigger_auto_sync_page');
/**
 * Funzione chiamata da WP-Cron per processare una pagina di sincronizzazione.
 */
function ssw_trigger_auto_sync_page() {
    ssw_debug_log_to_file("CRON Shopify Sync (Page Sync): Avvio processo automatico di UNA pagina.", "INFO");
    
    // Recupera il page_info per la prossima pagina da sincronizzare
    $next_page_info = get_option(SSW_NEXT_PAGE_INFO_OPTION, null); // null indica di iniziare dalla prima pagina

    if ($next_page_info === 'END') {
        ssw_debug_log_to_file("CRON Shopify Sync (Page Sync): Sincronizzazione automatica già completata (END). Nessuna azione.", "INFO");
        return; // Sincronizzazione già completata
    }
    
    if ($next_page_info === null) {
        ssw_debug_log_to_file("CRON Shopify Sync (Page Sync): Inizio dalla prima pagina (page_info è null).", "INFO");
    }

    try {
        // Verifica esistenza funzione prima di chiamare (sicurezza extra)
        if (function_exists('ssw_sync_single_page')) {
            $result = ssw_sync_single_page($next_page_info); // Chiama la funzione di sincronizzazione pagina

            // Aggiorna le opzioni con i risultati
            $next_page_info_result = $result['next_page_info'] ?? 'END';
            update_option(SSW_NEXT_PAGE_INFO_OPTION, $next_page_info_result);
            update_option(SSW_LAST_SYNC_SUMMARY_OPTION, $result); // Salva l'intero riepilogo

            if ($next_page_info_result === 'END') {
                update_option('ssw_last_sync_time_auto', time()); // Registra tempo completamento sync auto
                ssw_debug_log_to_file("CRON Shopify Sync (Page Sync): Fine sincronizzazione automatica (raggiunto END).", "INFO");
            } else {
                ssw_debug_log_to_file("CRON Shopify Sync (Page Sync): Pagina processata. Prossimo page_info salvato: " . esc_html($next_page_info_result), "INFO");
            }
        } else {
             ssw_debug_log_to_file("CRON Shopify Sync ERRORE: Funzione ssw_sync_single_page non trovata!", "ERROR");
        }
    } catch (Exception $e) {
        ssw_debug_log_to_file("CRON Shopify Sync (Page Sync) ERRORE ECCEZIONE: " . $e->getMessage(), "ERROR");
    }
}

// --- Hook di Attivazione / Disattivazione / Disinstallazione ---
register_activation_hook(__FILE__, 'ssw_activate_plugin');
/**
 * Azioni all'attivazione del plugin: schedula il cron job se non esiste.
 */
function ssw_activate_plugin() {
    if (!wp_next_scheduled('ssw_hourly_event')) {
        wp_schedule_event(time() + 300, 'ssw_hourly', 'ssw_hourly_event'); // Avvia tra 5 minuti, poi ogni ora
    }
}

register_deactivation_hook(__FILE__, 'ssw_deactivate_plugin');
/**
 * Azioni alla disattivazione del plugin: rimuove il cron job schedulato.
 */
function ssw_deactivate_plugin() {
    wp_clear_scheduled_hook('ssw_hourly_event');
}

register_uninstall_hook(__FILE__, 'ssw_uninstall_plugin');
/**
 * Azioni alla disinstallazione del plugin: pulisce opzioni e cron job.
 */
function ssw_uninstall_plugin() {
    ssw_debug_log_to_file("Disinstallazione plugin Shopify Sync...", "INFO");
    // Cancella tutte le opzioni del plugin
    delete_option(SSW_OPTION_SHOPIFY_DOMAIN);
    delete_option(SSW_OPTION_API_KEY);
    delete_option(SSW_OPTION_API_PASSWORD);
    delete_option(SSW_OPTION_ENABLE_IMAGE_SYNC);
    delete_option(SSW_OPTION_BATCH_SIZE);
    delete_option(SSW_NEXT_PAGE_INFO_OPTION);
    delete_option(SSW_LAST_SYNC_SUMMARY_OPTION);
    delete_option('ssw_last_sync_time_auto');
    delete_option('ssw_last_sync_time_manual'); // Opzione per tracciare l'ultima sync manuale completata

    // Rimuovi il cron job (dovrebbe essere già fatto da deactivate, ma per sicurezza)
    wp_clear_scheduled_hook('ssw_hourly_event');
    
    // Potrebbe essere utile rimuovere i meta dei prodotti, ma è un'operazione distruttiva
    // e andrebbe fatta con cautela o come opzione separata.
    // Esempio:
    // global $wpdb;
    // $wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key LIKE '_ssw_shopify_%'");

    ssw_debug_log_to_file("Plugin Shopify Sync disinstallato, opzioni e cron rimossi.", "INFO");
}

// --- Caricamento Text Domain per Traduzioni ---
add_action( 'plugins_loaded', 'ssw_load_textdomain' ); // Cambiato da 'init' a 'plugins_loaded' per pratica migliore
/**
 * Carica il text domain del plugin per le traduzioni.
 */
function ssw_load_textdomain() {
    load_plugin_textdomain(
        'shopify-sync-woo',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages/'
    );
}