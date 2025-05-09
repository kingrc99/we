<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

function ssw_render_dashboard_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __('Non hai permessi sufficienti per accedere a questa pagina.', 'shopify-sync-woo') );
    }

    $sync_result = null;
    $reload_needed = false; // Flag per gestire il redirect dopo azioni POST

    // --- GESTIONE AZIONI POST (NON PER TEST API, che ora è AJAX) ---

    // Reset Stato Sync
    if (isset($_POST['ssw_action']) && $_POST['ssw_action'] === 'reset_sync' && isset($_POST['ssw_reset_sync_nonce'])) {
        if (wp_verify_nonce($_POST['ssw_reset_sync_nonce'], 'ssw_reset_sync_action')) {
            delete_option(SSW_NEXT_PAGE_INFO_OPTION);
            delete_option(SSW_LAST_SYNC_SUMMARY_OPTION);
            add_settings_error('ssw_sync_results', 'sync_reset', __('Stato sincronizzazione resettato. Puoi riavviare.', 'shopify-sync-woo'), 'info');
            set_transient('settings_errors', get_settings_errors(), 30); // Salva per dopo redirect
            $reload_needed = true;
        } else {
            add_settings_error('ssw_sync_results', 'nonce_fail_reset', __('Errore di sicurezza (reset nonce).', 'shopify-sync-woo'), 'error');
            set_transient('settings_errors', get_settings_errors(), 30);
            $reload_needed = true;
        }
    }

    // Sincronizzazione Manuale Pagina Singola
    if ( isset( $_POST['ssw_manual_sync_nonce'], $_POST['ssw_sync_now'] ) ) {
        if ( wp_verify_nonce( $_POST['ssw_manual_sync_nonce'], 'ssw_manual_sync_action' ) ) {
            $page_info_to_process = $_POST['ssw_current_page_info'] ?? null;
             if ($page_info_to_process === 'START') { $page_info_to_process = null;} // Interpreta 'START' come null (prima pagina)

             if ($page_info_to_process === 'END') {
                  add_settings_error('ssw_sync_results', 'sync_already_complete', __('Sincronizzazione già completata. Clicca "Riavvia da Capo" per ricominciare.', 'shopify-sync-woo'), 'info');
                  // Non resettare automaticamente, l'utente userà il pulsante reset se vuole
                  // delete_option(SSW_NEXT_PAGE_INFO_OPTION); // Commentato: il reset è un'azione a parte
                  $last_summary = get_option(SSW_LAST_SYNC_SUMMARY_OPTION, null); // Recupera ultimo summary se disponibile
             } else {
                try {
                    if (function_exists('ssw_sync_single_page')) {
                        $sync_result = ssw_sync_single_page($page_info_to_process);

                        $next_page_info = $sync_result['next_page_info'] ?? 'END';
                        update_option(SSW_NEXT_PAGE_INFO_OPTION, $next_page_info);
                        if (is_array($sync_result)) { update_option(SSW_LAST_SYNC_SUMMARY_OPTION, $sync_result); }

                        $processed = $sync_result['processed_on_page'] ?? 0; $failed = $sync_result['failed_on_page'] ?? 0; $page_num = $sync_result['current_page_num'] ?? '?';
                        $skipped = $sync_result['skipped_on_page'] ?? 0; $attempted = $sync_result['attempted_on_page'] ?? ($processed + $failed + $skipped);

                        $msg = sprintf(__('Pagina %s processata. Prodotti tentati: %d (Sincronizzati: %d, Saltati: %d, Falliti: %d).', 'shopify-sync-woo'), $page_num, $attempted, $processed, $skipped, $failed);
                        if ($next_page_info !== 'END') { $msg .= ' ' . __('Pronto per pagina successiva.', 'shopify-sync-woo'); }
                        else { $msg .= ' ' . __('Sincronizzazione completata!', 'shopify-sync-woo'); update_option('ssw_last_sync_time_manual', time()); }
                        add_settings_error('ssw_sync_results', 'sync_page_summary', $msg, ($failed > 0 ? 'warning' : 'success'));
                    } else {
                        add_settings_error('ssw_sync_results', 'sync_function_missing', __('Errore: Funzione di sincronizzazione pagina non trovata.', 'shopify-sync-woo'), 'error');
                        error_log("Shopify Sync Error: Funzione ssw_sync_single_page non definita.");
                    }
                } catch (Exception $e) {
                     add_settings_error('ssw_sync_results', 'sync_fatal_error', __( 'ERRORE FATALE: ', 'shopify-sync-woo' ) . $e->getMessage(), 'error');
                     error_log("Shopify Sync Fatal Error (Manual Page Sync): " . $e->getMessage());
                     // Potrebbe essere utile resettare per evitare loop, o lasciare che l'utente decida.
                     // delete_option(SSW_NEXT_PAGE_INFO_OPTION);
                }
                 $last_summary = $sync_result; // Aggiorna last_summary per la visualizzazione immediata se non c'è redirect
            }
        } else {
            add_settings_error('ssw_sync_results', 'nonce_fail_sync', __('Errore sicurezza (sync nonce).', 'shopify-sync-woo'), 'error');
        }
        set_transient('settings_errors', get_settings_errors(), 30); // Salva per dopo redirect
        $reload_needed = true; // Richiede redirect per mostrare i messaggi correttamente e evitare resubmit
    } else {
        // Se non c'è un'azione POST di sync, carica l'ultimo sommario salvato
        $last_summary = get_option(SSW_LAST_SYNC_SUMMARY_OPTION, null);
    }


    // Esegui il redirect se necessario
    if ($reload_needed) {
        // Rimuovi parametri specifici della nostra azione per pulire l'URL, ma mantieni altri parametri (es. ?page=...)
        $redirect_url = remove_query_arg(['ssw_action', 'ssw_manual_sync_nonce', '_wpnonce', 'ssw_sync_now', 'ssw_current_page_info', 'ssw_reset_sync_nonce', 'action_done'], wp_unslash($_SERVER['REQUEST_URI']));
        $redirect_url = add_query_arg('action_processed', time(), $redirect_url); // Aggiunge un parametro per forzare il refresh
        wp_safe_redirect($redirect_url);
        exit;
    }

    // Recupera i settings_errors dal transient se presenti (dopo un redirect)
    $transient_notices = get_transient('settings_errors');
    if ($transient_notices) {
        delete_transient('settings_errors'); // Pulisci il transient
        // Aggiungi i notices recuperati a quelli correnti per la visualizzazione
        foreach ($transient_notices as $notice) {
            add_settings_error($notice['setting'], $notice['code'], $notice['message'], $notice['type']);
        }
    }

    $next_page_info_for_form = get_option(SSW_NEXT_PAGE_INFO_OPTION, 'START');
    $last_sync_auto_time = get_option('ssw_last_sync_time_auto');
    $next_scheduled_sync = wp_next_scheduled('ssw_hourly_event');

    ?>
    <div class="wrap ssw-dashboard">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        <?php
            settings_errors('ssw_api_test_results', false, true); // Per messaggi AJAX (anche se ora gestiti da JS, utile per fallback o vecchi transient)
            settings_errors('ssw_sync_results', false, true);   // Per messaggi di sync/reset
        ?>

        <div id="ssw-api-test-results-ajax" style="margin-top: 10px; margin-bottom:15px;"></div> <?php // DIV per messaggi AJAX del test API ?>

        <div class="ssw-dashboard-content">
            <div class="ssw-section ssw-settings-section">
                 <h2><span class="dashicons dashicons-admin-generic"></span> <?php _e('Impostazioni Shopify Sync', 'shopify-sync-woo'); ?></h2>
                 <form method="post" action="options.php">
                     <?php settings_fields( SSW_SETTINGS_GROUP ); ?>
                     <?php do_settings_sections( 'ssw-settings' ); // Stampa campi API, Immagini, Batch Size ?>
                     <?php // Nonce per l'AJAX test (letto da JS) ?>
                     <input type="hidden" id="ssw_test_api_nonce_field" value="<?php echo wp_create_nonce('ssw_test_api_nonce'); ?>">
                     <?php submit_button( __('Salva Impostazioni', 'shopify-sync-woo') ); ?>
                 </form>
                 <div style="margin-top: 15px; padding-top: 15px; border-top: 1px dashed #eee;">
                     <button type="button" id="ssw-test-api-button" class="button button-secondary">
                         <?php _e('Test Connessione API', 'shopify-sync-woo'); ?>
                     </button>
                     <span id="ssw-test-api-spinner" class="spinner" style="float:none; vertical-align: middle;"></span>
                     <p class="description"><?php _e('Verifica se le credenziali API (come inserite sopra) funzionano. Non salva le impostazioni.', 'shopify-sync-woo'); ?></p>
                 </div>
            </div>

            <div class="ssw-section ssw-sync-section">
                 <h2><span class="dashicons dashicons-update"></span> <?php _e('Sincronizzazione Manuale (Pagina per Pagina)', 'shopify-sync-woo'); ?></h2>
                 <p><em><?php _e('NOTA: La sincronizzazione immagini può essere attivata/disattivata nelle impostazioni.', 'shopify-sync-woo'); ?></em></p>

                 <?php if ($last_summary): // Mostra sempre l'ultimo sommario se esiste, anche se non è appena avvenuto un sync ?>
                     <div class="ssw-last-summary">
                         <strong><?php _e('Ultimo Riepilogo Pagina Processata:', 'shopify-sync-woo'); ?></strong><br>
                         <?php
                            $attempted_lp = (int)($last_summary['attempted_on_page'] ?? 0);
                            $processed_lp = (int)($last_summary['processed_on_page'] ?? 0);
                            $skipped_lp = (int)($last_summary['skipped_on_page'] ?? 0);
                            $failed_lp = (int)($last_summary['failed_on_page'] ?? 0);
                            printf(__('Pagina: %s | Tentati: %d (Sincronizzati: %d, Saltati: %d, Falliti: %d)', 'shopify-sync-woo'),
                             esc_html($last_summary['current_page_num'] ?? '?'), $attempted_lp, $processed_lp, $skipped_lp, $failed_lp ); ?><br>
                         <i><?php echo esc_html( $last_summary['message'] ?? ''); ?></i>
                     </div>
                 <?php endif; ?>

                 <form id="ssw-manual-sync-form" method="post" action="<?php echo esc_url(admin_url('admin.php?page=shopify-sync-woo-dashboard')); ?>">
                     <?php wp_nonce_field( 'ssw_manual_sync_action', 'ssw_manual_sync_nonce' ); ?>
                     <input type="hidden" name="ssw_current_page_info" value="<?php echo esc_attr($next_page_info_for_form); ?>" />
                     <?php
                        $button_text = __('Avvia Sincronizzazione', 'shopify-sync-woo');
                        $button_class = 'button-primary';
                        if ($next_page_info_for_form === 'END') {
                            // Se è 'END', il sync è completo. Il pulsante dovrebbe essere "Riavvia" ma questo è gestito dal Reset.
                            // Quindi mostriamo un pulsante disabilitato o un messaggio, e il pulsante "Resetta" diventa più importante.
                            // Per ora, manteniamo il pulsante di Sync, ma sarà meno utile.
                            $button_text = __('Sincronizzazione Completata', 'shopify-sync-woo');
                            // $button_class = 'button-secondary'; // Potrebbe essere disabilitato
                        } elseif ($next_page_info_for_form !== 'START') {
                            $button_text = __('Sincronizza Pagina Successiva', 'shopify-sync-woo');
                        }
                     ?>
                     <input type="submit" name="ssw_sync_now" class="button <?php echo $button_class; ?>" value="<?php echo esc_attr($button_text); ?>" <?php if ($next_page_info_for_form === 'END') echo 'disabled'; ?>>
                     <p class="description">
                        <?php
                        if ($next_page_info_for_form === 'END') {
                            _e('Sincronizzazione completata. Per ricominciare, usa il pulsante "Resetta Stato Sync".', 'shopify-sync-woo');
                        } else {
                            _e('Clicca per processare la prossima pagina di prodotti da Shopify.', 'shopify-sync-woo');
                        }
                        ?>
                     </p>
                 </form>

                 <form id="ssw-reset-sync-form" method="post" action="<?php echo esc_url(admin_url('admin.php?page=shopify-sync-woo-dashboard')); ?>" style="margin-top:20px;">
                      <input type="hidden" name="ssw_action" value="reset_sync" />
                      <?php wp_nonce_field( 'ssw_reset_sync_action', 'ssw_reset_sync_nonce' ); ?>
                      <button type="submit" class="button button-secondary button-small"> <?php _e('Resetta Stato Sync', 'shopify-sync-woo'); ?> </button>
                      <p class="description"><?php _e('Usa questo per cancellare lo stato della sincronizzazione e permettere di riavviarla da capo.', 'shopify-sync-woo'); ?></p>
                 </form>

                 <div class="ssw-sync-status-auto">
                      <h4><?php _e('Sincronizzazione Automatica (WP-Cron)', 'shopify-sync-woo'); ?></h4>
                      <p class="description" style="margin-bottom: 10px;"><?php _e('Il processo automatico esegue UNA pagina ogni ora, se configurato e attivo.', 'shopify-sync-woo'); ?></p>
                      <ul>
                        <li><?php _e('Ultima sinc. automatica:', 'shopify-sync-woo'); ?> <strong><?php echo $last_sync_auto_time ? wp_date( get_option('date_format') . ' ' . get_option('time_format'), $last_sync_auto_time ) : __('Mai eseguita', 'shopify-sync-woo'); ?></strong></li>
                        <li><?php _e('Prossimo avvio automatico:', 'shopify-sync-woo'); ?> <strong><?php echo $next_scheduled_sync ? wp_date( get_option('date_format') . ' ' . get_option('time_format'), $next_scheduled_sync ) : __('Non schedulato', 'shopify-sync-woo'); ?></strong> (<?php echo $next_scheduled_sync ? sprintf( __('tra %s', 'shopify-sync-woo'), human_time_diff( time(), $next_scheduled_sync ) ) : __('N/D', 'shopify-sync-woo'); ?>)</li>
                      </ul>
                 </div>
            </div>

        </div>
        <style>
            .ssw-dashboard-content { display: flex; flex-wrap: wrap; gap: 20px; }
            .ssw-section { background: #fff; padding: 20px; border: 1px solid #ccd0d4; min-width: 300px; flex: 1; }
            .ssw-section h2 { margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px; }
            .ssw-section .dashicons { margin-right: 5px; }
            .ssw-last-summary { border: 1px solid #e0e0e0; background: #f9f9f9; padding: 10px 15px; margin-bottom: 15px; border-radius: 3px; }
            .ssw-sync-status-auto { margin-top: 25px; padding-top: 15px; border-top: 1px dashed #eee; }
            #ssw-api-test-results-ajax .notice { margin: 0; padding: 10px; }
            .spinner { visibility: hidden; } /* Default hidden */
            .spinner.is-active { visibility: visible; } /* Make spinner visible when active */
        </style>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#ssw-test-api-button').on('click', function() {
                    var button = $(this);
                    var spinner = $('#ssw-test-api-spinner');
                    var resultsDiv = $('#ssw-api-test-results-ajax');

                    resultsDiv.html(''); // Pulisci risultati precedenti
                    spinner.addClass('is-active');
                    button.prop('disabled', true);

                    var shopifyDomain = $('#<?php echo esc_js(SSW_OPTION_SHOPIFY_DOMAIN); ?>').val();
                    // La API Key (SSW_OPTION_API_KEY) non è usata per l'autenticazione header-based con Admin API Access Token.
                    // Se la si volesse includere per altri scopi, decommentare la riga sotto.
                    // var apiKey = $('#<?php echo esc_js(SSW_OPTION_API_KEY); ?>').val();
                    var apiToken = $('#<?php echo esc_js(SSW_OPTION_API_PASSWORD); ?>').val();
                    var nonce = $('#ssw_test_api_nonce_field').val(); // Prende il nonce dal campo hidden

                    $.ajax({
                        url: ajaxurl, // WordPress AJAX URL
                        type: 'POST',
                        data: {
                            action: 'ssw_test_api_credentials', // La nostra action AJAX
                            nonce: nonce,
                            domain: shopifyDomain,
                            // api_key: apiKey, // Se necessaria
                            token: apiToken
                        },
                        success: function(response) {
                            if (response.success) {
                                resultsDiv.html('<div class="notice notice-success is-dismissible"><p>' + $('<div>').text(response.data.message).html() + '</p></div>');
                            } else {
                                var errorMsg = (response.data && response.data.message) ? response.data.message : '<?php echo esc_js(__("Errore sconosciuto durante il test API.", "shopify-sync-woo")); ?>';
                                resultsDiv.html('<div class="notice notice-error is-dismissible"><p>' + $('<div>').text(errorMsg).html() + '</p></div>');
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            var errorMsg = '<?php echo esc_js(__("Errore AJAX: ", "shopify-sync-woo")); ?>' + $('<div>').text(textStatus + ' - ' + errorThrown).html();
                            if (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                                errorMsg = $('<div>').text(jqXHR.responseJSON.data.message).html();
                            } else if (jqXHR.responseText) {
                                try {
                                    var errData = JSON.parse(jqXHR.responseText);
                                    if(errData.data && errData.data.message) errorMsg = $('<div>').text(errData.data.message).html();
                                } catch(e){
                                     // Potrebbe non essere JSON, in tal caso usa il testo grezzo con cautela
                                     // errorMsg += '<br><pre>' + $('<div>').text(jqXHR.responseText.substring(0, 500)).html() + '</pre>';
                                }
                            }
                            resultsDiv.html('<div class="notice notice-error is-dismissible"><p>' + errorMsg + '</p></div>');
                        },
                        complete: function() {
                            spinner.removeClass('is-active');
                            button.prop('disabled', false);
                        }
                    });
                });

                // Per rendere i messaggi dismissible (aggiunti da JS) cliccabili per chiuderli
                $('body').on('click', '.notice.is-dismissible .notice-dismiss', function() {
                    $(this).closest('.notice').fadeOut();
                });
                 // Aggiungere il pulsante di chiusura se non presente (WordPress di solito lo fa lato server)
                $('#ssw-api-test-results-ajax').on('DOMNodeInserted', '.notice.is-dismissible', function() {
                    var $notice = $(this);
                    if ($notice.find('.notice-dismiss').length === 0) {
                        $notice.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text"><?php _e("Ignora questo avviso.", "shopify-sync-woo"); ?></span></button>');
                    }
                });
            });
        </script>
    </div> <?php // .wrap ?>
    <?php
}