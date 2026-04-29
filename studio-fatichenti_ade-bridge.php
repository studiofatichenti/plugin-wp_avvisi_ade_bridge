<?php
/**
 * Plugin Name:       Studio Fatichenti — Avvisi AdE Bridge
 * Plugin URI:        https://github.com/studiofatichenti/plugin-wp_avvisi_ade_bridge
 * Description:       Inoltra i submit del form Contact Form 7 "Avvisi AdE" al Portale Clienti dello studio (VM on-premise) firmando ogni richiesta con HMAC SHA-256. Nessun servizio terzo coinvolto.
 * Version:           1.1.1
 * Author:            Studio Fatichenti
 * Author URI:        https://studio.fatichenti.com
 * License:           GPL-2.0+
 * Requires PHP:      7.4
 * Update URI:        https://github.com/studiofatichenti/plugin-wp_avvisi_ade_bridge
 *
 * Sicurezza:
 *   - HMAC SHA-256 di ogni richiesta (firma + segreto condiviso col Portale)
 *   - Honeypot field iniettato automaticamente nel form
 *   - Time-based check (_loaded_at) iniettato via JS al render
 *   - Anti-replay (_ts server-side)
 *   - Fallback non distruttivo: se l'inoltro fallisce, CF7 manda comunque la mail
 *     standard allo studio (così non si perdono comunicazioni). Errore loggato.
 *
 * Aggiornamenti:
 *   Il plugin si aggiorna leggendo le release del repository pubblico GitHub
 *   "studiofatichenti/plugin-wp_avvisi_ade_bridge". Quando rilascio una nuova
 *   versione (tag vX.Y.Z), WordPress mostra il bottone "Aggiorna ora" come
 *   per tutti gli altri plugin. Nessun servizio terzo: solo l'API pubblica
 *   GitHub releases, chiamata server-side da WP.
 */

if (!defined('ABSPATH')) {
    exit;
}

const SFA_OPT          = 'sfatichenti_ade_bridge';
const SFA_LOG_OPT      = 'sfatichenti_ade_bridge_lastlog';
const SFA_VERSION      = '1.1.1';
const SFA_GH_OWNER     = 'studiofatichenti';
const SFA_GH_REPO      = 'plugin-wp_avvisi_ade_bridge';
const SFA_PLUGIN_SLUG  = 'studio-fatichenti_ade-bridge';
const SFA_UPDATE_TTL   = 12 * HOUR_IN_SECONDS;  // intervallo controllo nuove release

// ─── Settings page ───────────────────────────────────────────────

add_action('admin_menu', function () {
    add_options_page(
        'Avvisi AdE Bridge',
        'Avvisi AdE Bridge',
        'manage_options',
        'sfatichenti-ade-bridge',
        'sfa_settings_page_render'
    );
});

add_action('admin_init', function () {
    register_setting(SFA_OPT, SFA_OPT, 'sfa_sanitize_options');
});

function sfa_sanitize_options($input)
{
    return [
        'enabled'      => !empty($input['enabled']) ? 1 : 0,
        'cf7_form_id'  => isset($input['cf7_form_id']) ? intval($input['cf7_form_id']) : 0,
        'endpoint_url' => isset($input['endpoint_url']) ? esc_url_raw($input['endpoint_url']) : '',
        'api_key'      => isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '',
        'hmac_secret'  => isset($input['hmac_secret']) ? sanitize_text_field($input['hmac_secret']) : '',
    ];
}

function sfa_get_options()
{
    return wp_parse_args(get_option(SFA_OPT, []), [
        'enabled'      => 0,
        'cf7_form_id'  => 0,
        'endpoint_url' => 'https://portalestudio.fatichenti.com/api/ade-avviso',
        'api_key'      => '',
        'hmac_secret'  => '',
    ]);
}

function sfa_settings_page_render()
{
    $opts = sfa_get_options();
    $last = get_option(SFA_LOG_OPT, '');
    ?>
    <div class="wrap">
        <h1>Studio Fatichenti — Avvisi AdE Bridge</h1>
        <p>Inoltra i submit del form Contact Form 7 dedicato agli Avvisi AdE al Portale dello studio
           (VM on-premise) firmando ogni richiesta con HMAC SHA-256.
           Nessuna chiamata a servizi terzi.</p>

        <form method="post" action="options.php">
            <?php settings_fields(SFA_OPT); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Abilitato</th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo SFA_OPT; ?>[enabled]" value="1"
                                <?php checked($opts['enabled'], 1); ?>>
                            Inoltra al Portale (se disabilitato, CF7 manda solo la mail)
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">ID Form CF7</th>
                    <td>
                        <input type="number" min="0"
                            name="<?php echo SFA_OPT; ?>[cf7_form_id]"
                            value="<?php echo esc_attr($opts['cf7_form_id']); ?>" class="small-text">
                        <p class="description">
                            ID del form CF7 dedicato agli Avvisi AdE.
                            Lo trovi in <strong>Contatti → Moduli</strong> nella tabella (colonna ID).
                            Lascia 0 per agganciare TUTTI i form (sconsigliato).
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">URL endpoint Portale</th>
                    <td>
                        <input type="url" size="60"
                            name="<?php echo SFA_OPT; ?>[endpoint_url]"
                            value="<?php echo esc_attr($opts['endpoint_url']); ?>">
                        <p class="description">Default: <code>https://portalestudio.fatichenti.com/api/ade-avviso</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">API Key Portale (X-API-Key)</th>
                    <td>
                        <input type="text" size="60"
                            name="<?php echo SFA_OPT; ?>[api_key]"
                            value="<?php echo esc_attr($opts['api_key']); ?>"
                            autocomplete="off">
                        <p class="description">Stessa chiave configurata su <code>CRM_API_KEY</code> nel <code>.env</code> del Portale.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">HMAC secret condiviso</th>
                    <td>
                        <input type="password" size="60"
                            name="<?php echo SFA_OPT; ?>[hmac_secret]"
                            value="<?php echo esc_attr($opts['hmac_secret']); ?>"
                            autocomplete="off">
                        <p class="description">
                            Segreto condiviso col Portale (env <code>ADE_AVVISI_HMAC_SECRET</code> nel <code>.env</code>).
                            Genera una stringa lunga e casuale (es. <code>openssl rand -hex 32</code>).
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Salva impostazioni'); ?>
        </form>

        <h2>Ultimo evento</h2>
        <pre style="background:#fff;border:1px solid #ccd0d4;padding:0.6rem;font-size:0.82rem;max-height:240px;overflow:auto;"><?php
            echo esc_html($last ?: '— nessun evento ancora registrato —');
        ?></pre>
        <p class="description">
            Nomi dei campi attesi dal form CF7 (case sensitive):<br>
            <code>nominativo, codice_fiscale, email, telefono, oggetto, commento,
            data_ricezione_avviso, modello_dic, anno_imposta, tipologia_avviso, allegato</code>
        </p>
    </div>
    <?php
}

// ─── Inietta campi anti-bot nel form CF7 (honeypot + _loaded_at) ──

add_filter('wpcf7_form_elements', function ($form) {
    $opts = sfa_get_options();
    if (!$opts['enabled']) {
        return $form;
    }
    $current = function_exists('wpcf7_get_current_contact_form')
        ? wpcf7_get_current_contact_form()
        : (class_exists('WPCF7_ContactForm') ? WPCF7_ContactForm::get_current() : null);
    if (!$current) {
        return $form;
    }
    if ($opts['cf7_form_id'] && intval($current->id()) !== intval($opts['cf7_form_id'])) {
        return $form;
    }

    $rnd_id = 'sfa_la_' . wp_rand(1000, 9999);
    $hidden = ''
        . '<div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;visibility:hidden;height:0;overflow:hidden;">'
        . '  <label>Lascia vuoto: <input type="text" name="website" tabindex="-1" autocomplete="off"></label>'
        . '</div>'
        . '<input type="hidden" name="_loaded_at" id="' . esc_attr($rnd_id) . '" value="0">'
        . '<script>(function(){var e=document.getElementById("' . esc_js($rnd_id) . '");if(e){e.value=Date.now();}})();</script>';

    return $hidden . $form;
}, 10, 1);

// ─── Hook submit: inoltra al Portale firmando con HMAC ────────────

add_action('wpcf7_before_send_mail', function ($contact_form, &$abort, $submission) {
    $opts = sfa_get_options();
    if (!$opts['enabled']) {
        return;
    }
    if ($opts['cf7_form_id'] && intval($contact_form->id()) !== intval($opts['cf7_form_id'])) {
        return;
    }
    if (empty($opts['endpoint_url']) || empty($opts['api_key']) || empty($opts['hmac_secret'])) {
        sfa_log('[CONFIG] endpoint_url/api_key/hmac_secret mancante — inoltro saltato');
        return;
    }

    $sub = $submission ?: (class_exists('WPCF7_Submission') ? WPCF7_Submission::get_instance() : null);
    if (!$sub) {
        return;
    }
    $data = $sub->get_posted_data();

    // Honeypot: scarto silenzioso (non blocca CF7, solo non inoltra)
    if (!empty($data['website'])) {
        sfa_log('[HONEYPOT] Submit con honeypot compilato — inoltro saltato (CF7 invia mail comunque)');
        return;
    }

    // Mappa dai nomi del modulo CF7 esistente (form ID 3129) ai nomi attesi
    // dall'endpoint del Portale. Concateno cognome+nome in un unico nominativo
    // (il modulo non ha campo CF, quindi resta vuoto: il record nel CRM sarà
    // "orfano" finché l'operatore non lo associa al cliente manualmente).
    $cognome    = sfa_field($data, 'your-surname');
    $nome       = sfa_field($data, 'your-name');
    $nominativo = trim($cognome . ' ' . $nome);

    // Conversione data: HTML5 <input type="date"> manda YYYY-MM-DD,
    // l'endpoint si aspetta GG/MM/AAAA (convenzione italiana CRM).
    $data_ric_iso = sfa_field($data, 'DataRicezioneAvviso');
    $data_ric_it  = '';
    if ($data_ric_iso && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $data_ric_iso, $m)) {
        $data_ric_it = $m[3] . '/' . $m[2] . '/' . $m[1];
    }

    $payload = [
        'nominativo'             => $nominativo,
        'codice_fiscale'         => '',  // il modulo non lo chiede
        'email'                  => strtolower(sfa_field($data, 'your-email')),
        'telefono'               => sfa_field($data, 'Telefono'),
        'oggetto'                => sfa_field($data, 'your-subject'),
        'commento'               => sfa_field($data, 'your-message'),
        'data_ricezione_avviso'  => $data_ric_it,
        'modello_dic'            => '',  // compilato in CRM dopo l'import
        'anno_imposta'           => '',  // idem
        'tipologia_avviso'       => '',  // idem
        '_loaded_at'             => sfa_field($data, '_loaded_at'),
    ];

    // Allegato: CF7 salva i file in una cartella temporanea
    $first_file_path = '';
    $files = method_exists($sub, 'uploaded_files') ? $sub->uploaded_files() : [];
    foreach ($files as $field => $paths) {
        if (is_array($paths) && !empty($paths)) {
            $first_file_path = is_array($paths[0]) ? '' : (string)$paths[0];
        } elseif (is_string($paths) && $paths !== '') {
            $first_file_path = $paths;
        }
        if ($first_file_path) {
            break;
        }
    }
    $file_sha256 = '';
    if ($first_file_path && file_exists($first_file_path)) {
        $file_sha256 = hash_file('sha256', $first_file_path);
    }

    // HMAC SHA-256 su canonical = chiavi ordinate URL-encoded + |ts|sha=...
    $ts_ms = (int) round(microtime(true) * 1000);
    $sorted = $payload;
    ksort($sorted);
    $parts = [];
    foreach ($sorted as $k => $v) {
        $parts[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
    }
    $canonical = implode('&', $parts) . '|' . $ts_ms . '|sha=' . $file_sha256;
    $signature = hash_hmac('sha256', $canonical, $opts['hmac_secret']);

    // Costruisci body multipart/form-data manualmente
    $boundary = wp_generate_password(24, false);
    $body = '';
    $send = $payload;
    $send['_ts'] = (string)$ts_ms;
    $send['_signature'] = $signature;
    foreach ($send as $k => $v) {
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"" . $k . "\"\r\n\r\n";
        $body .= ((string)$v) . "\r\n";
    }
    if ($first_file_path && file_exists($first_file_path)) {
        $fname = basename($first_file_path);
        $fcontent = @file_get_contents($first_file_path);
        if ($fcontent !== false) {
            $mime = function_exists('mime_content_type') ? mime_content_type($first_file_path) : 'application/octet-stream';
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"allegato\"; filename=\"" . $fname . "\"\r\n";
            $body .= "Content-Type: " . $mime . "\r\n\r\n";
            $body .= $fcontent . "\r\n";
        }
    }
    $body .= "--{$boundary}--\r\n";

    $resp = wp_remote_post($opts['endpoint_url'], [
        'headers' => [
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            'X-API-Key'    => $opts['api_key'],
            'Origin'       => home_url(),
            'Referer'      => home_url(),
            'User-Agent'   => 'sfatichenti-ade-bridge/1.0',
        ],
        'body'    => $body,
        'timeout' => 30,
    ]);

    if (is_wp_error($resp)) {
        sfa_log('[ERR] wp_remote_post: ' . $resp->get_error_message());
        return;
    }
    $code = wp_remote_retrieve_response_code($resp);
    $rbody = wp_remote_retrieve_body($resp);
    if ($code !== 200) {
        sfa_log('[ERR] HTTP ' . $code . ' — ' . substr($rbody, 0, 500));
        return;
    }
    sfa_log('[OK] HTTP 200 — ' . substr($rbody, 0, 500));
}, 10, 3);

// ─── Helpers ──────────────────────────────────────────────────────

function sfa_field($data, $key)
{
    $v = isset($data[$key]) ? $data[$key] : '';
    if (is_array($v)) {
        $v = implode(', ', array_map('strval', $v));
    }
    return trim((string)$v);
}

function sfa_log($msg)
{
    $line = '[' . current_time('Y-m-d H:i:s') . '] ' . $msg;
    update_option(SFA_LOG_OPT, $line, false);
    if (function_exists('error_log')) {
        error_log('[sfatichenti-ade-bridge] ' . $msg);
    }
}

// ─── Aggiornamento automatico da GitHub Releases ──────────────────
//
// Il plugin controlla le release pubbliche del repo GitHub
// "studiofatichenti/plugin-wp_avvisi_ade_bridge" e quando trova un tag
// nuovo (es. v1.2.0) WordPress mostra il pulsante "Aggiorna ora" come
// per qualunque altro plugin del repository ufficiale.
//
// Procedura di rilascio:
//   1. Aggiorna l'header del file PHP: Version: 1.2.0
//   2. Aggiorna anche la costante SFA_VERSION = '1.2.0'
//   3. git tag v1.2.0 && git push origin v1.2.0
//   4. Su GitHub: Release → "Draft a new release" → tag v1.2.0 → "Publish release"
//      (GitHub crea automaticamente uno ZIP del codice; non serve allegarne uno custom)
//
// Nessun token, nessun servizio terzo: solo l'API pubblica github.com.

function sfa_plugin_basename()
{
    // restituisce slug/file.php (es. "studio-fatichenti_ade-bridge/studio-fatichenti_ade-bridge.php")
    return plugin_basename(__FILE__);
}

function sfa_get_latest_release()
{
    // Legge il tag git piu' recente del repo (i tag sono creati da
    // Gestione Sistemi al rilascio: niente "release" GitHub manuale).
    // Per qualunque tag, GitHub espone automaticamente uno zipball.
    $cached = get_site_transient('sfa_gh_latest_release');
    if ($cached !== false) {
        return $cached;
    }

    $url = sprintf('https://api.github.com/repos/%s/%s/tags', SFA_GH_OWNER, SFA_GH_REPO);
    $resp = wp_remote_get($url, [
        'timeout' => 10,
        'headers' => [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'sfatichenti-ade-bridge-updater/' . SFA_VERSION,
        ],
    ]);
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
        set_site_transient('sfa_gh_latest_release', null, 30 * MINUTE_IN_SECONDS);
        return null;
    }
    $tags = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($tags) || empty($tags)) {
        set_site_transient('sfa_gh_latest_release', null, 30 * MINUTE_IN_SECONDS);
        return null;
    }
    // Filtra i soli tag che iniziano con "v" e sono semver-compatibili.
    $candidates = [];
    foreach ($tags as $t) {
        if (empty($t['name']) || $t['name'][0] !== 'v') continue;
        $ver = ltrim($t['name'], 'v');
        if (preg_match('/^\d+\.\d+\.\d+$/', $ver)) {
            $candidates[$ver] = $t;
        }
    }
    if (empty($candidates)) {
        set_site_transient('sfa_gh_latest_release', null, 30 * MINUTE_IN_SECONDS);
        return null;
    }
    // Ordina e prende il tag piu' recente (semver-aware).
    uksort($candidates, 'version_compare');
    $latest_ver = array_key_last($candidates);
    $latest_tag = $candidates[$latest_ver];

    $info = [
        'version'      => $latest_ver,
        'download_url' => $latest_tag['zipball_url'],
        'html_url'     => sprintf('https://github.com/%s/%s/releases/tag/v%s', SFA_GH_OWNER, SFA_GH_REPO, $latest_ver),
        'published_at' => '',
        'body'         => sprintf('Tag v%s rilasciato da Gestione Sistemi.', $latest_ver),
    ];
    set_site_transient('sfa_gh_latest_release', $info, SFA_UPDATE_TTL);
    return $info;
}

// Hook: inietta il plugin nei "transient" degli aggiornamenti disponibili.
// IMPORTANTE: WordPress mostra il toggle "Abilita aggiornamenti automatici"
// nella pagina Plugin SOLO se il plugin compare in $transient->response
// (nuova versione) OPPURE in $transient->no_update (aggiornato all'ultima).
// Quindi popoliamo SEMPRE almeno uno dei due array.
add_filter('pre_set_site_transient_update_plugins', function ($transient) {
    if (empty($transient->checked)) {
        return $transient;
    }
    $basename = sfa_plugin_basename();
    $latest = sfa_get_latest_release();

    // Oggetto base con i metadati del plugin (uguale per entrambi i rami)
    $plugin_info = (object) [
        'slug'        => SFA_PLUGIN_SLUG,
        'plugin'      => $basename,
        'new_version' => SFA_VERSION,
        'url'         => sprintf('https://github.com/%s/%s', SFA_GH_OWNER, SFA_GH_REPO),
        'package'     => '',
        'tested'      => '6.5',
        'icons'       => [],
        'banners'     => [],
        'requires'    => '6.0',
        'requires_php'=> '7.4',
    ];

    if ($latest && version_compare($latest['version'], SFA_VERSION, '>')) {
        // Nuova versione disponibile → in $transient->response
        $plugin_info->new_version = $latest['version'];
        $plugin_info->url         = $latest['html_url'];
        $plugin_info->package     = $latest['download_url'];
        $transient->response[$basename] = $plugin_info;
    } else {
        // Plugin già all'ultima versione → in $transient->no_update
        // (necessario perché WP mostri il toggle "Auto-update")
        $transient->no_update[$basename] = $plugin_info;
    }
    return $transient;
});

// Hook: schermata "Visualizza dettagli" del plugin.
add_filter('plugins_api', function ($result, $action, $args) {
    if ($action !== 'plugin_information') {
        return $result;
    }
    if (empty($args->slug) || $args->slug !== SFA_PLUGIN_SLUG) {
        return $result;
    }
    $latest = sfa_get_latest_release();
    if (!$latest) {
        return $result;
    }
    return (object) [
        'name'          => 'Studio Fatichenti — Avvisi AdE Bridge',
        'slug'          => SFA_PLUGIN_SLUG,
        'version'       => $latest['version'],
        'author'        => '<a href="https://studio.fatichenti.com">Studio Fatichenti</a>',
        'homepage'      => sprintf('https://github.com/%s/%s', SFA_GH_OWNER, SFA_GH_REPO),
        'download_link' => $latest['download_url'],
        'requires'      => '6.0',
        'tested'        => '6.5',
        'requires_php'  => '7.4',
        'last_updated'  => $latest['published_at'],
        'sections'      => [
            'description' => '<p>Inoltra i submit di Contact Form 7 al Portale Clienti dello studio firmando ogni richiesta con HMAC SHA-256. Soluzione 100% on-premise.</p>',
            'changelog'   => '<pre>' . esc_html($latest['body']) . '</pre>',
        ],
    ];
}, 10, 3);

// Hook: dopo l'estrazione del ZIP, GitHub crea una cartella tipo
// "studiofatichenti-plugin-wp_avvisi_ade_bridge-abc1234". La rinominiamo
// in "studio-fatichenti_ade-bridge" altrimenti WP la attiva come plugin
// nuovo invece di sostituire quello esistente.
add_filter('upgrader_post_install', function ($response, $hook_extra, $result) {
    if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== sfa_plugin_basename()) {
        return $response;
    }
    global $wp_filesystem;
    $expected = trailingslashit(WP_PLUGIN_DIR) . SFA_PLUGIN_SLUG;
    if (!empty($result['destination']) && $result['destination'] !== $expected) {
        $wp_filesystem->move($result['destination'], $expected);
        $result['destination']      = $expected;
        $result['destination_name'] = SFA_PLUGIN_SLUG;
    }
    return $response;
}, 10, 3);

// Forza un check immediato al click su "Controlla aggiornamenti" nella pagina Plugin.
add_action('upgrader_process_complete', function ($upgrader, $hook_extra) {
    if (!empty($hook_extra['plugins']) && in_array(sfa_plugin_basename(), (array)$hook_extra['plugins'], true)) {
        delete_site_transient('sfa_gh_latest_release');
    }
}, 10, 2);
