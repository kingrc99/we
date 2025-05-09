<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * sync-products.php
 * Funzioni per la sincronizzazione dei prodotti da Shopify a WooCommerce.
 * Version: 1.8.0 (Inizializzazione $wc_variation più robusta)
 */

// Funzione ssw_debug_log_to_file (invariata)
if (!function_exists('ssw_debug_log_to_file')) {
    function ssw_debug_log_to_file($message, $log_level = 'DEBUG') {
        $standard_log_message = "Shopify Sync ({$log_level}): " . $message;
        error_log($standard_log_message);
        $upload_dir_info = wp_upload_dir();
        if (isset($upload_dir_info['basedir']) && $upload_dir_info['basedir']) {
            if (is_writable($upload_dir_info['basedir'])) {
                $log_file = $upload_dir_info['basedir'] . '/ssw-sync-debug.log';
                $timestamp = current_time('Y-m-d H:i:s');
                $formatted_message = "[{$timestamp}] [{$log_level}] {$message}\n";
                @file_put_contents($log_file, $formatted_message, FILE_APPEND);
            } else {
                error_log("Shopify Sync (ERROR): La directory di upload ('" . esc_html($upload_dir_info['basedir']) . "') non è scrivibile.");
            }
        } elseif (isset($upload_dir_info['error']) && $upload_dir_info['error']) {
            error_log("Shopify Sync (ERROR): Errore directory Upload di WP: " . esc_html($upload_dir_info['error']));
        } else {
            error_log("Shopify Sync (ERROR): Directory di upload base non trovata.");
        }
    }
}

// Funzione ssw_sync_single_page (invariata dalla v1.7.9)
function ssw_sync_single_page($page_info = null) {
    $batch_size = (int) get_option(SSW_OPTION_BATCH_SIZE, 10);
    if ($batch_size <= 0 || $batch_size > 250) $batch_size = 10;
    $enable_image_sync = (bool) get_option(SSW_OPTION_ENABLE_IMAGE_SYNC, 0); 

    $api_args = ['limit' => $batch_size];
    if ($page_info && $page_info !== 'START' && $page_info !== 'END') {
        $api_args['page_info'] = $page_info;
    }

    ssw_debug_log_to_file("Avvio ssw_sync_single_page. Page Info: " . esc_html($page_info ?: 'PRIMA PAGINA') . ", Batch Size: {$batch_size}, Immagini: " . ($enable_image_sync ? 'Si' : 'No'), "INFO");
    $shopify_response = ssw_shopify_api_get('products.json', $api_args);
    $summary_counts = ['attempted_on_page' => 0, 'processed_on_page' => 0, 'skipped_on_page' => 0, 'failed_on_page' => 0, 'current_page_num' => $page_info ?: '1', 'next_page_info' => null, 'message' => ''];

    if (!$shopify_response || !isset($shopify_response['body']['products'])) {
        $error_message = __('Errore nel recuperare i prodotti da Shopify.', 'shopify-sync-woo');
        if(is_wp_error($shopify_response)) $error_message .= ' WP_Error: ' . $shopify_response->get_error_message();
        ssw_debug_log_to_file("Errore API Shopify: " . $error_message, "ERROR");
        $summary_counts['message'] = $error_message;
        $summary_counts['failed_on_page'] = $batch_size;
        return $summary_counts;
    }

    $products_data = $shopify_response['body']['products'];
    $summary_counts['attempted_on_page'] = count($products_data);
    ssw_debug_log_to_file("Recuperati {$summary_counts['attempted_on_page']} prodotti da Shopify.", "INFO");

    if (empty($products_data)) {
        $summary_counts['message'] = __('Nessun prodotto trovato...', 'shopify-sync-woo');
        $summary_counts['next_page_info'] = 'END';
        ssw_debug_log_to_file("Nessun prodotto trovato in questa pagina.", "INFO");
        return $summary_counts;
    }

    foreach ($products_data as $product_data) {
        try {
            ssw_process_shopify_product_data($product_data, $enable_image_sync, $summary_counts);
        } catch (Exception $e) {
            ssw_debug_log_to_file("Eccezione proc. prodotto Shopify ID " . ($product_data['id'] ?? 'N/A') . ": " . $e->getMessage(), "ERROR");
            $summary_counts['failed_on_page']++;
        }
    }

    $next_page_url = ssw_get_next_page_url_from_headers($shopify_response['headers']);
    $summary_counts['next_page_info'] = $next_page_url ? ssw_get_page_info_from_url($next_page_url) : 'END';
    $summary_counts['message'] = sprintf(__('Pagina processata. T: %d, S: %d, Skip: %d, F: %d.', 'shopify-sync-woo'), $summary_counts['attempted_on_page'], $summary_counts['processed_on_page'], $summary_counts['skipped_on_page'], $summary_counts['failed_on_page']);
    ssw_debug_log_to_file("Fine ssw_sync_single_page. Riepilogo: " . $summary_counts['message'], "INFO");
    return $summary_counts;
}

function ssw_process_shopify_product_data($shopify_product_data, $enable_image_sync, &$summary_counts) {
    $shopify_product_id = $shopify_product_data['id'] ?? null;
    if (!$shopify_product_id) { /* ... (come v1.7.9) ... */ return null;}
    $shopify_variants = $shopify_product_data['variants'] ?? [];
    // ssw_debug_log_to_file("Inizio proc. Prodotto Shopify ID: {$shopify_product_id}...", "INFO"); // Log ridotto per brevità

    if (empty($shopify_variants)) { /* ... (come v1.7.9) ... */ return null;}

    $has_any_sellable_variant = false;
    // ... (logica $has_any_sellable_variant come v1.7.9) ...
    foreach ($shopify_variants as $variant_data_check) {
        $is_sellable = true;
        if (isset($variant_data_check['inventory_management']) && $variant_data_check['inventory_management'] === 'shopify' && isset($variant_data_check['inventory_policy']) && $variant_data_check['inventory_policy'] === 'deny' && isset($variant_data_check['inventory_quantity']) && (int)$variant_data_check['inventory_quantity'] <= 0) {
            $is_sellable = false;
        }
        if ($is_sellable) { $has_any_sellable_variant = true; break; }
    }


    if (!$has_any_sellable_variant) { /* ... (come v1.7.9) ... */ return null; }

    $existing_product_id = ssw_get_product_id_by_shopify_id($shopify_product_id);
    $product_is_variable_type = count($shopify_variants) > 1 || (isset($shopify_product_data['options']) && count($shopify_product_data['options']) > 0 && isset($shopify_product_data['options'][0]['name']) && $shopify_product_data['options'][0]['name'] !== 'Title' && count($shopify_product_data['options'][0]['values']) > 0) ;
    $is_new_product = false;

    if ($existing_product_id) { /* ... (come v1.7.9) ... */ } 
    else { $is_new_product = true; $product = $product_is_variable_type ? new WC_Product_Variable() : new WC_Product_Simple(); /* ... */ }
    if (empty($product)) { // Fallback se $product non è stato inizializzato
        ssw_debug_log_to_file("Errore CRITICO: Oggetto \$product non inizializzato per Shopify ID {$shopify_product_id}.", "ERROR");
        $summary_counts['failed_on_page']++; return null;
    }

    // ... (set_name, set_description, set_slug, meta, categorie, immagine principale - come da v1.7.9)
    $product->set_name(wp_kses_post($shopify_product_data['title'] ?? ''));
    $product->set_description(wp_kses_post($shopify_product_data['body_html'] ?? ''));
    $product->set_slug(sanitize_title($shopify_product_data['title'] ?? ('product-' . $shopify_product_id))); 
    $product->update_meta_data(SSW_META_SHOPIFY_PRODUCT_ID, $shopify_product_id);
    $product->update_meta_data(SSW_META_LAST_SYNC_TIME, time());

    if (!$product_is_variable_type && isset($shopify_variants[0]['sku']) && !empty($shopify_variants[0]['sku'])) {
        $product->set_sku(sanitize_text_field($shopify_variants[0]['sku']));
    }
    if (!empty($shopify_product_data['tags'])) { /* ... (logica categorie come da v1.7.8) ... */ }
    if ($enable_image_sync && isset($shopify_product_data['image']['src'])) {
        ssw_debug_log_to_file("Tentativo sync immagine principale per Shopify ID {$shopify_product_id} (URL: " . esc_url($shopify_product_data['image']['src']) .")", "DEBUG");
        $image_id = ssw_sync_image($shopify_product_data['image'], ($existing_product_id ?: ($product->get_id() ?: null)), true, $shopify_product_id);
        if ($image_id) { $product->set_image_id($image_id); ssw_debug_log_to_file("Immagine principale impostata per WC ID " . $product->get_id() . ": WP Attachment ID {$image_id}", "INFO"); }
        else { ssw_debug_log_to_file("Sinc. immagine principale fallita o non necessaria per Shopify ID {$shopify_product_id}", "WARNING"); }
    }
    if (!$product_is_variable_type) { /* ... (logica prodotto semplice come da v1.7.8) ... */ }


    try {
        $product_id = $product->save();
        if ($product_id <= 0) throw new Exception("Salvataggio prod. fallito, ID non valido ({$product_id}). Shopify ID: {$shopify_product_id}");
        // ... (log come prima)
    } catch (Exception $e) { /* ... (come v1.7.9) ... */ return null;}

    if ($product_is_variable_type) {
        // ... (Logica attributi padre come v1.7.8, incluso $product->save() dopo set_attributes) ...
        // Questa parte è cruciale per le variazioni
        $wc_attributes = [];
        if (isset($shopify_product_data['options']) && is_array($shopify_product_data['options'])) {
            foreach ($shopify_product_data['options'] as $option_index => $shopify_option) {
                if (empty($shopify_option['name']) || empty($shopify_option['values']) || $shopify_option['name'] === 'Title') continue;
                $attribute_name = $shopify_option['name']; $attribute = new WC_Product_Attribute();
                $attribute->set_name($attribute_name); $attribute->set_options($shopify_option['values']); 
                $attribute->set_position($option_index); $attribute->set_visible(true); $attribute->set_variation(true); 
                $wc_attributes[] = $attribute;
            }
        }
        if (!empty($wc_attributes)) { $product->set_attributes($wc_attributes); $product->save(); ssw_debug_log_to_file("Attributi salvati per prod. variabile WC ID {$product_id}. N. Attr: " . count($wc_attributes), "INFO"); }


        $processed_variation_ids_this_sync = [];
        foreach ($shopify_variants as $variant_data) {
            // ... (sellable check per questa variante, come prima) ...
            if (!$is_this_variant_sellable) { /* ... (logica skip variante come prima) ... */ continue; }
            
            $variation_attributes_for_wc = []; 
            // ... (logica per $variation_attributes_for_wc come da v1.7.8, usando slug per globali) ...

            // --- INIZIALIZZAZIONE ROBUSTA di $wc_variation ---
            $wc_variation = null; // Resetta per ogni iterazione
            $variation_id_wc = ssw_get_variation_id_by_shopify_variant_id($product_id, $variant_data['id'] ?? null);

            if ($variation_id_wc) {
                $fetched_variation = wc_get_product($variation_id_wc);
                if ($fetched_variation instanceof WC_Product_Variation) {
                    $wc_variation = $fetched_variation;
                    ssw_debug_log_to_file("Variante WC ID {$variation_id_wc} trovata e caricata per Shopify Variant ID " . ($variant_data['id'] ?? 'N/A'), "DEBUG");
                } else {
                    ssw_debug_log_to_file("ID Variante WC {$variation_id_wc} trovato, ma wc_get_product non ha restituito WC_Product_Variation. Risultato: " . gettype($fetched_variation) . ". Creazione nuova per Shopify Variant ID " . ($variant_data['id'] ?? 'N/A'), "WARNING");
                    $wc_variation = new WC_Product_Variation();
                    $variation_id_wc = 0; // Tratta come nuova perché il caricamento è fallito
                }
            } else {
                $wc_variation = new WC_Product_Variation();
                ssw_debug_log_to_file("Nessuna variante WC esistente trovata per Shopify Variant ID " . ($variant_data['id'] ?? 'N/A') . ". Creazione nuova.", "DEBUG");
            }

            // Controllo CRITICO: assicurati che $wc_variation sia un oggetto valido
            if (!($wc_variation instanceof WC_Product_Variation)) {
                ssw_debug_log_to_file("ERRORE CRITICO: \$wc_variation non è un oggetto WC_Product_Variation valido prima di impostare i dati per Shopify Variant ID " . ($variant_data['id'] ?? 'N/A') . ". Saltando questa variante.", "ERROR");
                $summary_counts['failed_on_page']++; // Incrementa conteggio falliti
                continue; // Salta questa specifica variante
            }
            // --- FINE INIZIALIZZAZIONE ROBUSTA ---

            if (!$variation_id_wc) { // Se è una nuova variazione (o il caricamento è fallito e la trattiamo come nuova)
                $wc_variation->set_parent_id($product_id);
            }
            
            // Ora puoi usare $wc_variation con più sicurezza
            $wc_variation->set_attributes($variation_attributes_for_wc); 
            $wc_variation->set_regular_price(sanitize_text_field($variant_data['price'] ?? '0'));
            // ... (imposta compare_at_price, sale_price come prima)
            if (isset($variant_data['compare_at_price']) && !empty($variant_data['compare_at_price']) && (float)$variant_data['compare_at_price'] > (float)($variant_data['price'] ?? 0)) {
                $wc_variation->set_regular_price(sanitize_text_field($variant_data['compare_at_price']));
                $wc_variation->set_sale_price(sanitize_text_field($variant_data['price'] ?? '0'));
            } else $wc_variation->set_sale_price('');

            $wc_variation->set_sku(sanitize_text_field($variant_data['sku'] ?? '')); // RIGA 201 (circa) nel file dell'utente
            
            // ... (imposta gestione stock, quantità, stato stock come prima)
            // ... (imposta immagine variante se $enable_image_sync, come prima, aggiungendo log)
            if ($enable_image_sync && isset($variant_data['image_id']) && $variant_data['image_id']) {
                $variant_image_data = null; /* ... (trova $variant_image_data come prima) ... */
                if ($variant_image_data) {
                    ssw_debug_log_to_file("Tentativo sync immagine per variante Shopify ID {$variant_data['id']} (URL: " . esc_url($variant_image_data['src'] ?? 'N/A') . ")", "DEBUG");
                    $var_image_id = ssw_sync_image($variant_image_data, $product_id, false, $shopify_product_id, $variant_data['id'] ?? null);
                    if ($var_image_id) { $wc_variation->set_image_id($var_image_id); ssw_debug_log_to_file("Immagine variante impostata per WC Var ID (sarà {$wc_variation->get_id()}): WP Attach ID {$var_image_id}", "INFO");}
                    else { ssw_debug_log_to_file("Sinc. immagine variante fallita per Shopify Var ID {$variant_data['id']}", "WARNING");}
                }
            }


            $wc_variation->update_meta_data(SSW_META_SHOPIFY_VARIANT_ID, $variant_data['id'] ?? null);
            $wc_variation->update_meta_data(SSW_META_LAST_SYNC_TIME, time());
            try { /* ... (save $wc_variation con gestione errore SKU come da v1.7.9) ... */ }
            catch (Exception $e_var_general) { /* ... */ }
        } 
        // (Logica pulizia variazioni orfane come prima)
    } 

    // (Logica galleria immagini come da v1.7.9, con i log aggiunti per il tentativo)
    if ($enable_image_sync && isset($shopify_product_data['images']) && is_array($shopify_product_data['images'])) {
        ssw_debug_log_to_file("Inizio sync immagini galleria per Shopify ID {$shopify_product_id}. N. Img totali: " . count($shopify_product_data['images']), "DEBUG");
        // ... (resto della logica galleria, con i ssw_debug_log_to_file prima di ssw_sync_image)
    }
    
    try { /* ... (Salvataggio finale e sync figli come da v1.7.8) ... */ }
    catch (Exception $e_final_save) { /* ... */ }

    $summary_counts['processed_on_page']++;
    return $product_id;
}

// Funzioni ssw_get_product_id_by_shopify_id, ssw_get_variation_id_by_shopify_variant_id (CORRETTE come da v1.7.8)
function ssw_get_product_id_by_shopify_id($shopify_product_id) {
    if (empty($shopify_product_id)) return 0;
    $query_args = array('post_type' => 'product', 'post_status' => 'any', 'meta_query' => array( array( 'key' => SSW_META_SHOPIFY_PRODUCT_ID, 'value' => $shopify_product_id, 'compare' => '=' ) ), 'posts_per_page' => 1, 'fields' => 'ids');
    $found_products = get_posts($query_args);
    return !empty($found_products) ? (int)$found_products[0] : 0;
}
function ssw_get_variation_id_by_shopify_variant_id($parent_product_id_wc, $shopify_variant_id) {
    if (empty($parent_product_id_wc) || empty($shopify_variant_id)) return 0;
    $query_args = array('post_type' => 'product_variation', 'post_status' => 'any', 'post_parent' => $parent_product_id_wc, 'meta_query' => array( array( 'key' => SSW_META_SHOPIFY_VARIANT_ID, 'value' => $shopify_variant_id, 'compare' => '=' ) ), 'posts_per_page' => 1, 'fields' => 'ids');
    $found_variations = get_posts($query_args);
    return !empty($found_variations) ? (int)$found_variations[0] : 0;
}

// Funzione ssw_sync_image (come da v1.7.8, con logging debug e gestione SSW_META_SHOPIFY_VARIANT_ID_FOR_IMAGE)
function ssw_sync_image($image_data, $parent_post_id = null, $is_featured = false, $shopify_product_id = 0, $shopify_variant_id = null) {
    if (empty($image_data['src'])) { ssw_debug_log_to_file("Sync immagine fallito: src vuoto. Shopify Prod ID {$shopify_product_id}, Var ID {$shopify_variant_id}", "WARNING"); return false; }
    $image_url = $image_data['src']; $shopify_image_id = $image_data['id'] ?? null;
    $image_alt_text = $image_data['alt'] ?? ($shopify_product_id ? 'Immagine per prodotto ' . $shopify_product_id : 'Immagine Prodotto');
    $tracking_meta_key = SSW_META_SHOPIFY_IMAGE_ID;
    if ($shopify_image_id) {
        $query_args_img = ['post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1, 'meta_query' => [ [ 'key' => $tracking_meta_key, 'value' => $shopify_image_id, 'compare' => '=' ] ], 'fields' => 'ids'];
        $found_images = get_posts($query_args_img);
        if (!empty($found_images)) { ssw_debug_log_to_file("Immagine Shopify ID {$shopify_image_id} già esistente come WP ID {$found_images[0]}. Skipping download.", "DEBUG"); return (int)$found_images[0]; }
    }
    if(!function_exists('media_handle_sideload')) { require_once(ABSPATH . 'wp-admin/includes/media.php'); require_once(ABSPATH . 'wp-admin/includes/file.php'); require_once(ABSPATH . 'wp-admin/includes/image.php');}
    ssw_debug_log_to_file("Download immagine: {$image_url} per Shopify Prod ID {$shopify_product_id}", "DEBUG");
    add_filter( 'http_request_timeout', 'ssw_extended_timeout_for_images_filter', 9999 ); $tmp_file = download_url($image_url, 120); remove_filter( 'http_request_timeout', 'ssw_extended_timeout_for_images_filter', 9999 );
    if (is_wp_error($tmp_file)) { ssw_debug_log_to_file("Errore download immagine {$image_url}: " . $tmp_file->get_error_message(), "ERROR"); return false; }
    $file_array = []; preg_match('/[^\?]+\.(jpg|jpeg|gif|png|webp)/i', $image_url, $matches);
    if ($matches && isset($matches[0])) $file_array['name'] = basename($matches[0]); else { $file_parts = pathinfo($image_url); $file_array['name'] = ($file_parts['filename'] ?? 'shopify_img_' . time()) . '.' . ($file_parts['extension'] ?? 'jpg'); }
    $file_array['tmp_name'] = $tmp_file;
    $attachment_id = media_handle_sideload($file_array, $parent_post_id ?: 0, $image_alt_text); @unlink($tmp_file);
    if (is_wp_error($attachment_id)) { ssw_debug_log_to_file("Errore sideload immagine {$image_url}: " . $attachment_id->get_error_message(), "ERROR"); return false; }
    if ($shopify_image_id) {
        update_post_meta($attachment_id, $tracking_meta_key, $shopify_image_id);
        if ($is_featured) update_post_meta($attachment_id, SSW_META_SHOPIFY_FEATURED_IMAGE_ID, $shopify_image_id);
        if ($shopify_variant_id && defined('SSW_META_SHOPIFY_VARIANT_ID_FOR_IMAGE')) update_post_meta($attachment_id, SSW_META_SHOPIFY_VARIANT_ID_FOR_IMAGE, $shopify_variant_id);
        update_post_meta($attachment_id, '_shopify_product_origin_id_debug', $shopify_product_id);
    }
    ssw_debug_log_to_file("Immagine Shopify ID {$shopify_image_id} (URL: {$image_url}) sinc. come WP ID {$attachment_id} per prod. Shopify ID {$shopify_product_id}.", "INFO");
    return (int) $attachment_id;
}
if (!function_exists('ssw_extended_timeout_for_images_filter')) { function ssw_extended_timeout_for_images_filter( $timeout ) { return 120; } }