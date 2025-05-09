<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Esegue una chiamata GET all'API Admin di Shopify.
 *
 * @param string $endpoint L'endpoint API da chiamare (es. 'products.json', 'products/count.json').
 * @param array $args Argomenti aggiuntivi per la chiamata API (es. ['limit' => 15, 'page_info' => '...']).
 * @param string|null $domain_override Dominio Shopify da usare al posto di quello salvato.
 * @param string|null $token_override Admin API Access Token da usare al posto di quello salvato.
 * @return array|false Un array contenente 'body' (dati decodificati) e 'headers' (oggetti header) in caso di successo, altrimenti false.
 */
function ssw_shopify_api_get($endpoint, $args = [], $domain_override = null, $token_override = null) {
    $domain = $domain_override ?: get_option(SSW_OPTION_SHOPIFY_DOMAIN);
    $access_token = $token_override ?: get_option(SSW_OPTION_API_PASSWORD); // Admin API Access Token

    if (empty($domain) || empty($access_token)) {
        $error_message = "Shopify Sync Error: Dominio Shopify (" . esc_html($domain) . ") o Admin API Access Token non configurati.";
        if ($domain_override || $token_override) { // Se erano passati come override, indica che quelli specifici mancano
            $error_message = "Shopify Sync Error: Dominio Shopify o Admin API Access Token forniti per il test non sono validi.";
        }
        error_log($error_message);
        return false;
    }

    // Pulisce l'endpoint e costruisce l'URL base
    $endpoint = ltrim($endpoint, '/');
    $api_version = '2024-04'; // Mantieni aggiornata se necessario
    $base_url = "https://{$domain}/admin/api/{$api_version}/{$endpoint}";

    // Aggiunge gli argomenti query all'URL
    $url = add_query_arg($args, $base_url);
    $url = esc_url_raw($url); // Sanifica l'URL finale

    // Prepara gli argomenti per wp_remote_get
    $request_args = [
        'headers' => [
            'Content-Type'           => 'application/json',
            'X-Shopify-Access-Token' => $access_token, // Metodo di autenticazione sicuro
        ],
        'timeout' => 45, // Timeout leggermente aumentato per chiamate API
    ];

    // Esegui la chiamata
    $response = wp_remote_get($url, $request_args);

    // Gestione errori di connessione WordPress
    if (is_wp_error($response)) {
        error_log("Shopify Sync WP Error: Fallita connessione a {$url}. Errore: " . $response->get_error_message());
        return false;
    }

    // Ottieni codice, corpo e header della risposta
    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    $response_headers = wp_remote_retrieve_headers($response);

    // Controlla il codice di stato HTTP
    if ($response_code >= 200 && $response_code < 300) {
        // Successo
        return [
            'body'    => json_decode($response_body, true), // Decodifica JSON in array associativo
            'headers' => $response_headers                // Oggetto headers
        ];
    } else {
        // Errore API Shopify (4xx o 5xx)
        $error_message = "Shopify API Error: Code {$response_code}";
        $decoded_body = json_decode($response_body, true);
        if (isset($decoded_body['errors'])) {
            $error_details = is_array($decoded_body['errors']) ? json_encode($decoded_body['errors']) : $decoded_body['errors'];
            $error_message .= " - {$error_details}";
        } else {
            // Aggiungi parte del corpo se non ci sono 'errors' specifici
            $error_message .= " | Body: " . substr($response_body, 0, 200);
        }
        error_log($error_message . " | URL: " . esc_url(remove_query_arg('access_token', $url)));
        return false; // Indica fallimento
    }
}

/**
 * Testa la connessione all'API di Shopify recuperando i dettagli dello shop.
 *
 * @param string|null $domain_override Dominio Shopify da usare per il test.
 * @param string|null $token_override Admin API Access Token da usare per il test.
 * @return bool True se la connessione ha successo, false altrimenti.
 */
function ssw_test_shopify_connection($domain_override = null, $token_override = null) {
    // Usiamo l'endpoint 'shop.json' che richiede permessi minimi (read_shop)
    $result = ssw_shopify_api_get('shop.json', [], $domain_override, $token_override);

    // Verifica se la chiamata ha avuto successo e contiene l'oggetto 'shop'
    if ($result !== false && isset($result['body']['shop'])) {
        return true; // Connessione OK
    } else {
        // L'errore specifico è già stato loggato da ssw_shopify_api_get
        return false; // Connessione Fallita
    }
}

/**
 * Estrae l'URL 'next' dall'header Link per la paginazione basata su cursore.
 *
 * @param object $headers L'oggetto headers restituito da wp_remote_retrieve_headers.
 * @return string|null L'URL completo per la pagina successiva, o null se non presente.
 */
function ssw_get_next_page_url_from_headers($headers) {
    if ( ! $headers || ! $headers->offsetExists('link') ) { // Controlla se l'header esiste
        return null;
    }
    $link_header = $headers->offsetGet('link');

    if (preg_match('/<([^>]+)>;\s*rel="next"/', $link_header, $matches)) {
        return trim($matches[1]);
    }
    return null;
}

/**
 * Estrae il parametro 'page_info' dall'URL fornito (usato per la paginazione).
 *
 * @param string|null $url L'URL della pagina successiva.
 * @return string|null Il valore di page_info o null.
 */
function ssw_get_page_info_from_url($url) {
    if (!$url) return null;
    $query_string = parse_url($url, PHP_URL_QUERY);
    if (!$query_string) return null;
    parse_str($query_string, $query_params);
    return isset($query_params['page_info']) ? $query_params['page_info'] : null;
}