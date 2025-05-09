<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Hook per registrare le impostazioni del plugin quando l'admin viene inizializzato
add_action('admin_init', 'ssw_register_plugin_settings');

/**
 * Registra le impostazioni del plugin, le sezioni e i campi usando la Settings API di WordPress.
 */
function ssw_register_plugin_settings() {

    // Definisce il gruppo unico a cui appartengono tutte le nostre impostazioni
    $settings_group = SSW_SETTINGS_GROUP; // Corrisponde a 'ssw_settings_group'

    // Registra le singole opzioni salvate nel database
    register_setting($settings_group, SSW_OPTION_SHOPIFY_DOMAIN, 'sanitize_text_field');
    register_setting($settings_group, SSW_OPTION_API_KEY, 'sanitize_text_field');
    register_setting($settings_group, SSW_OPTION_API_PASSWORD, 'sanitize_text_field'); // Salva il token
    register_setting($settings_group, SSW_OPTION_ENABLE_IMAGE_SYNC, 'absint'); // Salva 0 o 1
    register_setting(
        $settings_group,
        SSW_OPTION_BATCH_SIZE,
        [
            'type'              => 'integer',
            'sanitize_callback' => 'ssw_sanitize_batch_size', // Funzione personalizzata per validare
            'default'           => 15,                       // Valore di default se non impostato
        ]
    );

    // --- Definisce le Sezioni Visibili nella Pagina Impostazioni ---
    $page_slug = 'ssw-settings'; // Lo slug della pagina definito in do_settings_sections

    // Sezione per le Credenziali API
    add_settings_section(
        'ssw_main_section',                                 // ID univoco
        __('Credenziali API Shopify', 'shopify-sync-woo'), // Titolo visibile
        null,                                              // Funzione per mostrare testo sotto il titolo (non usata)
        $page_slug                                         // Pagina admin dove apparirà
    );

    // Sezione per le Opzioni di Sincronizzazione
    add_settings_section(
        'ssw_sync_options_section',                         // ID univoco
        __('Opzioni di Sincronizzazione', 'shopify-sync-woo'), // Titolo visibile
        null,                                               // Callback (non usata)
        $page_slug                                          // Pagina admin
    );


    // --- Definisce i Campi Specifici per Ogni Opzione ---

    // Campi nella sezione Credenziali API ('ssw_main_section')
    add_settings_field( SSW_OPTION_SHOPIFY_DOMAIN, __('Shopify Domain', 'shopify-sync-woo'), 'ssw_render_text_input_field', $page_slug, 'ssw_main_section', ['id' => SSW_OPTION_SHOPIFY_DOMAIN, 'description' => __('Es: il-tuo-negozio.myshopify.com', 'shopify-sync-woo')] );
    add_settings_field( SSW_OPTION_API_KEY, __('API Key', 'shopify-sync-woo'), 'ssw_render_text_input_field', $page_slug, 'ssw_main_section', ['id' => SSW_OPTION_API_KEY, 'description' => __('La Chiave API dalla tua Custom App Shopify.', 'shopify-sync-woo')] );
    add_settings_field( SSW_OPTION_API_PASSWORD, __('Admin API Access Token', 'shopify-sync-woo'), 'ssw_render_password_field', $page_slug, 'ssw_main_section', ['id' => SSW_OPTION_API_PASSWORD, 'description' => __('Il token di accesso API Admin (mostrato una sola volta).', 'shopify-sync-woo')] );

    // Campi nella sezione Opzioni Sync ('ssw_sync_options_section')
     add_settings_field( SSW_OPTION_ENABLE_IMAGE_SYNC, __('Sincronizza Immagini', 'shopify-sync-woo'), 'ssw_render_checkbox_field', $page_slug, 'ssw_sync_options_section', ['id' => SSW_OPTION_ENABLE_IMAGE_SYNC, 'description' => __('ATTENZIONE: Rallenta molto la sincronizzazione e può causare timeout/crash su server con poche risorse.', 'shopify-sync-woo')] );
     add_settings_field( SSW_OPTION_BATCH_SIZE, __('Prodotti per Pagina', 'shopify-sync-woo'), 'ssw_render_number_input_field', $page_slug, 'ssw_sync_options_section', ['id' => SSW_OPTION_BATCH_SIZE, 'description' => __('Numero prodotti processati per click (consigliato 15-50, max 250). Valori alti aumentano rischio timeout.', 'shopify-sync-woo'), 'min' => 5, 'max' => 250, 'step' => 5] );
}

// --- Funzioni Callback per Renderizzare l'HTML dei Campi ---

function ssw_render_text_input_field($args) {
    $option_name = $args['id']; $option_value = get_option($option_name);
    echo '<input type="text" id="' . esc_attr($option_name) . '" name="' . esc_attr($option_name) . '" value="' . esc_attr($option_value) . '" class="regular-text" />';
    if (!empty($args['description'])) { echo '<p class="description">' . esc_html($args['description']) . '</p>'; }
}
function ssw_render_password_field($args) {
    $option_name = $args['id']; $option_value = get_option($option_name);
    echo '<input type="password" id="' . esc_attr($option_name) . '" name="' . esc_attr($option_name) . '" value="' . esc_attr($option_value) . '" class="regular-text" />';
    if (!empty($args['description'])) { echo '<p class="description">' . esc_html($args['description']) . '</p>'; }
}
function ssw_render_checkbox_field($args) {
    $option_name = $args['id']; $option_value = get_option($option_name, 0); // Default a 0 (non checkato)
    echo '<input type="checkbox" id="' . esc_attr($option_name) . '" name="' . esc_attr($option_name) . '" value="1" ' . checked( 1, $option_value, false ) . ' />';
    if (!empty($args['description'])) { echo '<label for="'.esc_attr($option_name).'"><span class="description">' . esc_html($args['description']) . '</span></label>'; }
}
function ssw_render_number_input_field($args) {
    $option_name = $args['id']; $option_value = get_option($option_name, 15); // Default a 15 per batch size
    $min = $args['min'] ?? 1; $max = $args['max'] ?? 250; $step = $args['step'] ?? 1;
    echo '<input type="number" id="' . esc_attr($option_name) . '" name="' . esc_attr($option_name) . '" value="' . esc_attr($option_value) . '" class="small-text" min="' . esc_attr($min) . '" max="' . esc_attr($max) . '" step="' . esc_attr($step) . '" />';
    if (!empty($args['description'])) { echo '<p class="description">' . esc_html($args['description']) . '</p>'; }
}

// --- Funzione di Sanificazione/Validazione per Batch Size ---
function ssw_sanitize_batch_size($input) {
    $input = absint($input); // Converte in intero positivo o 0
    $default_value = 15; $min_value = 5; $max_value = 250;
    if ($input < $min_value) {
        $input = $default_value;
        add_settings_error(SSW_OPTION_BATCH_SIZE, 'value_too_low', sprintf(__('Il Batch Size minimo è %d. Impostato al valore predefinito di %d.', 'shopify-sync-woo'), $min_value, $default_value), 'warning');
    }
    if ($input > $max_value) {
        $input = $max_value;
        add_settings_error(SSW_OPTION_BATCH_SIZE, 'value_too_high', sprintf(__('Il Batch Size massimo è %d (limite API Shopify). Impostato a %d.', 'shopify-sync-woo'), $max_value, $max_value), 'warning');
    }
    return $input; // Ritorna il valore sanificato
}