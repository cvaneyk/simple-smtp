<?php
/*
Plugin Name: Simple SMTP
Plugin URI: https://github.com/cvaneyk/simple-smtp
Description: Configura el envío de correos mediante SMTP, sin publicidad sin anuncios, sin seguimiento, sin cookies. Simple y funcional.
Version: 1.4
Author: Carlos Van Eyk
Update URI: https://github.com/cvaneyk/simple-smtp
*/

if (!defined('ABSPATH')) {
    exit; // Acceso directo no permitido
}

define('SIMPLE_SMTP_VERSION', '1.3');
define('SIMPLE_SMTP_GITHUB_USER', 'cvaneyk');
define('SIMPLE_SMTP_GITHUB_REPO', 'simple-smtp');
define('SIMPLE_SMTP_FILE', __FILE__);
define('SIMPLE_SMTP_LOG_DB_VERSION', '1.0');
define('SIMPLE_SMTP_LOG_MAX_ENTRIES', 200);

// Menú de configuración
add_action('admin_menu', 'custom_smtp_menu');
function custom_smtp_menu() {
    add_options_page(
        'Configuración SMTP',
        'Custom SMTP',
        'manage_options',
        'custom-smtp',
        'smtp_settings_page'
    );

    add_submenu_page(
        'options-general.php',
        'Registro de correos SMTP',
        'Registro SMTP',
        'manage_options',
        'simple-smtp-logs',
        'simple_smtp_logs_page'
    );
}

/* =====================================================================
 * Registro de correos enviados (log)
 * ===================================================================== */

// Crea la tabla de log al activar el plugin.
register_activation_hook(SIMPLE_SMTP_FILE, 'simple_smtp_create_log_table');

// También la crea/actualiza en sitios donde el plugin ya estaba activo
// (las actualizaciones normales no disparan el hook de activación).
add_action('plugins_loaded', 'simple_smtp_maybe_upgrade_log_table');
function simple_smtp_maybe_upgrade_log_table() {
    if (get_option('simple_smtp_log_db_version') !== SIMPLE_SMTP_LOG_DB_VERSION) {
        simple_smtp_create_log_table();
    }
}

function simple_smtp_create_log_table() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'simple_smtp_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sent_at DATETIME NOT NULL,
        to_email TEXT NOT NULL,
        subject TEXT NOT NULL,
        message LONGTEXT NOT NULL,
        headers TEXT NULL,
        status VARCHAR(20) NOT NULL,
        error_message TEXT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    update_option('simple_smtp_log_db_version', SIMPLE_SMTP_LOG_DB_VERSION);
}

// Registra cada correo enviado correctamente.
add_action('wp_mail_succeeded', 'simple_smtp_log_mail_succeeded');
function simple_smtp_log_mail_succeeded($mail_data) {
    simple_smtp_insert_log($mail_data, 'sent', '');
}

// Registra cada correo que falló al enviarse.
add_action('wp_mail_failed', 'simple_smtp_log_mail_failed');
function simple_smtp_log_mail_failed($wp_error) {
    $mail_data = $wp_error->get_error_data('wp_mail_failed');
    if (!is_array($mail_data)) {
        $mail_data = array();
    }
    simple_smtp_insert_log($mail_data, 'failed', $wp_error->get_error_message());
}

function simple_smtp_insert_log($mail_data, $status, $error_message) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'simple_smtp_logs';

    $to = '';
    if (!empty($mail_data['to'])) {
        $to = is_array($mail_data['to']) ? implode(', ', $mail_data['to']) : $mail_data['to'];
    }

    $headers = '';
    if (!empty($mail_data['headers'])) {
        $headers = is_array($mail_data['headers']) ? implode("\n", $mail_data['headers']) : $mail_data['headers'];
    }

    $wpdb->insert(
        $table_name,
        array(
            'sent_at'       => current_time('mysql'),
            'to_email'      => $to,
            'subject'       => isset($mail_data['subject']) ? $mail_data['subject'] : '',
            'message'       => isset($mail_data['message']) ? $mail_data['message'] : '',
            'headers'       => $headers,
            'status'        => $status,
            'error_message' => $error_message,
        ),
        array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
    );

    // Evita que la tabla crezca sin límite: conserva solo los últimos N registros.
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    if ($count > SIMPLE_SMTP_LOG_MAX_ENTRIES) {
        $excess = $count - SIMPLE_SMTP_LOG_MAX_ENTRIES;
        $wpdb->query($wpdb->prepare("DELETE FROM $table_name ORDER BY id ASC LIMIT %d", $excess));
    }
}

// Página de administración: listado del registro de correos.
function simple_smtp_logs_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Acceso no autorizado');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'simple_smtp_logs';

    if (isset($_POST['simple_smtp_clear_logs'])) {
        check_admin_referer('simple_smtp_clear_logs_action', 'simple_smtp_clear_logs_nonce');
        $wpdb->query("TRUNCATE TABLE $table_name");
        echo '<div class="notice notice-success is-dismissible"><p>Registro de correos vaciado.</p></div>';
    }

    $per_page = 20;
    $paged    = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset   = ($paged - 1) * $per_page;

    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $logs  = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name ORDER BY id DESC LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ));

    $total_pages = max(1, (int) ceil($total / $per_page));
    ?>
    <div class="wrap">
        <h2>Registro de correos enviados</h2>

        <form method="post" style="margin: 1em 0;">
            <?php wp_nonce_field('simple_smtp_clear_logs_action', 'simple_smtp_clear_logs_nonce'); ?>
            <?php submit_button(
                'Vaciar registro',
                'delete',
                'simple_smtp_clear_logs',
                false,
                array('onclick' => "return confirm('¿Seguro que quieres borrar todo el registro de correos?');")
            ); ?>
        </form>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:150px;">Fecha</th>
                    <th>Para</th>
                    <th>Asunto</th>
                    <th style="width:90px;">Estado</th>
                    <th>Detalle</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)) : ?>
                    <tr><td colspan="5">No hay correos registrados todavía.</td></tr>
                <?php else : foreach ($logs as $log) : ?>
                    <tr>
                        <td><?= esc_html($log->sent_at) ?></td>
                        <td><?= esc_html($log->to_email) ?></td>
                        <td><?= esc_html($log->subject) ?></td>
                        <td>
                            <?php if ($log->status === 'sent') : ?>
                                <span style="color:#008a20;font-weight:600;">Enviado</span>
                            <?php else : ?>
                                <span style="color:#d63638;font-weight:600;">Error</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <details>
                                <summary>Ver mensaje</summary>
                                <?php if (!empty($log->error_message)) : ?>
                                    <p><strong>Error:</strong> <?= esc_html($log->error_message) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($log->headers)) : ?>
                                    <p><strong>Cabeceras:</strong><br><?= nl2br(esc_html($log->headers)) ?></p>
                                <?php endif; ?>
                                <p><strong>Mensaje:</strong></p>
                                <div style="max-height:200px;overflow:auto;border:1px solid #ccd0d4;padding:8px;background:#fff;">
                                    <?= wp_kses_post($log->message) ?>
                                </div>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1) : ?>
            <div class="tablenav">
                <div class="tablenav-pages">
                    <?php
                    echo wp_kses_post(paginate_links(array(
                        'base'    => add_query_arg('paged', '%#%'),
                        'format'  => '',
                        'current' => $paged,
                        'total'   => $total_pages,
                    )));
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

// Configuración SMTP
add_action('phpmailer_init', 'custom_smtp_settings');
function custom_smtp_settings($phpmailer) {
    $phpmailer->isSMTP();
    $phpmailer->Host = get_option('smtp_host');
    $phpmailer->SMTPAuth = true;
    $phpmailer->Port = get_option('smtp_port');
    $phpmailer->Username = get_option('smtp_user');
    $phpmailer->Password = get_option('smtp_pass');
    $phpmailer->SMTPSecure = get_option('smtp_encryption');
    $phpmailer->From = get_option('from_email');
    $phpmailer->FromName = get_option('from_name');
}

// Página de ajustes
function smtp_settings_page() {
    ?>
    <div class="wrap">
        <h2>Configuración SMTP Personalizada</h2>
        <form method="post" action="options.php">
            <?php 
            settings_fields('custom-smtp-group');
            do_settings_sections('custom-smtp');
            ?>
            <table class="form-table">
                <tr>
                    <th>Servidor SMTP</th>
                    <td><input type="text" name="smtp_host" value="<?= esc_attr(get_option('smtp_host')) ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Puerto</th>
                    <td>
                        <input type="number" name="smtp_port" value="<?= esc_attr(get_option('smtp_port')) ?>" class="small-text">
                        <p class="description">(465 para SSL, 587 para TLS)</p>
                    </td>
                </tr>
                <tr>
                    <th>Cifrado</th>
                    <td>
                        <select name="smtp_encryption">
                            <option value="ssl" <?php selected(get_option('smtp_encryption'), 'ssl'); ?>>SSL</option>
                            <option value="tls" <?php selected(get_option('smtp_encryption'), 'tls'); ?>>TLS</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Usuario SMTP</th>
                    <td><input type="text" name="smtp_user" value="<?= esc_attr(get_option('smtp_user')) ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Contraseña SMTP</th>
                    <td><input type="password" name="smtp_pass" value="<?= esc_attr(get_option('smtp_pass')) ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Correo remitente</th>
                    <td><input type="email" name="from_email" value="<?= esc_attr(get_option('from_email')) ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Nombre remitente</th>
                    <td><input type="text" name="from_name" value="<?= esc_attr(get_option('from_name')) ?>" class="regular-text"></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <h3>Enviar correo de prueba</h3>
        <form method="post" action="<?= esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="send_test_email">
            <input type="email" name="test_email" placeholder="Correo de destino" required>
            <?php wp_nonce_field('test_email_action', 'test_email_nonce'); ?>
            <?php submit_button('Enviar correo de prueba', 'primary', 'send_test_email', false); ?>
        </form>
        
        <?php
        // Mostrar notificaciones
        $smtp_test_result = isset($_GET['smtp_test_result'])
            ? sanitize_text_field(wp_unslash($_GET['smtp_test_result']))
            : '';
        if ($smtp_test_result) {
            $class = ($smtp_test_result === 'success') ? 'notice-success' : 'notice-error';
            $message = ($smtp_test_result === 'success')
                ? '¡Correo enviado correctamente!'
                : 'Error al enviar el correo. Verifica la configuración SMTP.';
            ?>
            <div class="notice <?= esc_attr($class) ?> is-dismissible">
                <p><?= esc_html($message) ?></p>
            </div>
            <?php
        }
        ?>
    </div>
    <?php
}

// Procesar correo de prueba
add_action('admin_post_send_test_email', 'procesar_correo_prueba');
function procesar_correo_prueba() {
    check_admin_referer('test_email_action', 'test_email_nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die('Acceso no autorizado');
    }
    
    $to = sanitize_email($_POST['test_email']);
    $result = wp_mail(
        $to,
        'Prueba SMTP - ' . get_bloginfo('name'),
        'Este es un correo de prueba desde tu sitio WordPress',
        ['Content-Type: text/html; charset=UTF-8']
    );
    
    wp_redirect(add_query_arg(
        'smtp_test_result',
        $result ? 'success' : 'error',
        admin_url('options-general.php?page=custom-smtp')
    ));
    exit;
}

// Registrar opciones
add_action('admin_init', 'register_smtp_settings');
function register_smtp_settings() {
    register_setting('custom-smtp-group', 'smtp_host', 'sanitize_text_field');
    register_setting('custom-smtp-group', 'smtp_port', 'intval');
    register_setting('custom-smtp-group', 'smtp_encryption', 'sanitize_text_field');
    register_setting('custom-smtp-group', 'smtp_user', 'sanitize_text_field');
    register_setting('custom-smtp-group', 'smtp_pass', 'sanitize_text_field');
    register_setting('custom-smtp-group', 'from_email', 'sanitize_email');
    register_setting('custom-smtp-group', 'from_name', 'sanitize_text_field');
}

/* =====================================================================
 * Actualización automática desde GitHub Releases
 * ---------------------------------------------------------------------
 * Cuando publiques un nuevo Release en GitHub con una etiqueta de versión
 * (ej. "1.3" o "v1.3"), todos los WordPress donde esté instalado el plugin
 * mostrarán el aviso de actualización en Plugins, como cualquier plugin
 * oficial. No necesitas tocar nada más: solo subir el Release.
 * ===================================================================== */

// Consulta el último Release de GitHub (con caché de 6 horas para no saturar la API).
function simple_smtp_get_latest_release() {
    $cache_key = 'simple_smtp_github_release';
    $cached = get_transient($cache_key);
    if (false !== $cached) {
        return $cached;
    }

    $url = sprintf(
        'https://api.github.com/repos/%s/%s/releases/latest',
        SIMPLE_SMTP_GITHUB_USER,
        SIMPLE_SMTP_GITHUB_REPO
    );

    $response = wp_remote_get($url, array(
        'timeout' => 15,
        'headers' => array(
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'Simple-SMTP-WordPress-Plugin',
        ),
    ));

    if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
        // Cacheamos un fallo breve para no reintentar en cada carga.
        set_transient($cache_key, null, 30 * MINUTE_IN_SECONDS);
        return null;
    }

    $release = json_decode(wp_remote_retrieve_body($response));
    if (empty($release) || empty($release->tag_name)) {
        set_transient($cache_key, null, 30 * MINUTE_IN_SECONDS);
        return null;
    }

    set_transient($cache_key, $release, 6 * HOUR_IN_SECONDS);
    return $release;
}

// Devuelve el número de versión limpio a partir de la etiqueta (quita la "v" inicial).
function simple_smtp_clean_version($tag) {
    return ltrim($tag, 'vV');
}

// Elige la URL del paquete .zip a descargar para la actualización.
function simple_smtp_get_package_url($release) {
    // Si subiste un .zip como "asset" en el Release, lo usamos.
    if (!empty($release->assets) && is_array($release->assets)) {
        foreach ($release->assets as $asset) {
            if (!empty($asset->browser_download_url)
                && substr($asset->browser_download_url, -4) === '.zip') {
                return $asset->browser_download_url;
            }
        }
    }
    // Si no, usamos el zip del código fuente que genera GitHub automáticamente.
    return isset($release->zipball_url) ? $release->zipball_url : '';
}

// Inyecta la información de actualización en el sistema de WordPress.
add_filter('pre_set_site_transient_update_plugins', 'simple_smtp_check_for_update');
function simple_smtp_check_for_update($transient) {
    if (empty($transient->checked)) {
        return $transient;
    }

    $release = simple_smtp_get_latest_release();
    if (!$release) {
        return $transient;
    }

    $new_version = simple_smtp_clean_version($release->tag_name);
    $plugin_file = plugin_basename(SIMPLE_SMTP_FILE);

    if (version_compare($new_version, SIMPLE_SMTP_VERSION, '>')) {
        $package = simple_smtp_get_package_url($release);

        $update = new stdClass();
        $update->slug        = dirname($plugin_file) === '.' ? 'simple-smtp' : dirname($plugin_file);
        $update->plugin      = $plugin_file;
        $update->new_version = $new_version;
        $update->url         = 'https://github.com/' . SIMPLE_SMTP_GITHUB_USER . '/' . SIMPLE_SMTP_GITHUB_REPO;
        $update->package     = $package;

        $transient->response[$plugin_file] = $update;
    }

    return $transient;
}

// Muestra la información del plugin (ventana "Ver detalles") con datos del Release.
add_filter('plugins_api', 'simple_smtp_plugin_info', 20, 3);
function simple_smtp_plugin_info($result, $action, $args) {
    if ('plugin_information' !== $action) {
        return $result;
    }

    $plugin_file = plugin_basename(SIMPLE_SMTP_FILE);
    $slug = dirname($plugin_file) === '.' ? 'simple-smtp' : dirname($plugin_file);
    if (empty($args->slug) || $args->slug !== $slug) {
        return $result;
    }

    $release = simple_smtp_get_latest_release();
    if (!$release) {
        return $result;
    }

    $info = new stdClass();
    $info->name          = 'Simple SMTP';
    $info->slug          = $slug;
    $info->version       = simple_smtp_clean_version($release->tag_name);
    $info->author        = 'Carlos Van Eyk';
    $info->homepage      = 'https://github.com/' . SIMPLE_SMTP_GITHUB_USER . '/' . SIMPLE_SMTP_GITHUB_REPO;
    $info->download_link = simple_smtp_get_package_url($release);
    $info->sections      = array(
        'description' => !empty($release->body)
            ? nl2br(esc_html($release->body))
            : 'Configura el envío de correos mediante SMTP. Simple y funcional.',
    );

    return $info;
}

// Renombra la carpeta extraída del zip de GitHub para que coincida con la del plugin.
add_filter('upgrader_source_selection', 'simple_smtp_fix_source_dir', 10, 4);
function simple_smtp_fix_source_dir($source, $remote_source, $upgrader, $hook_extra = null) {
    global $wp_filesystem;

    if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(SIMPLE_SMTP_FILE)) {
        return $source;
    }

    $plugin_file = plugin_basename(SIMPLE_SMTP_FILE);
    // Solo aplica si el plugin vive dentro de su propia carpeta.
    if (dirname($plugin_file) === '.') {
        return $source;
    }

    $desired_slug = dirname($plugin_file);
    $corrected    = trailingslashit($remote_source) . $desired_slug;

    if ($source === trailingslashit($corrected)) {
        return $source;
    }

    if ($wp_filesystem->move($source, $corrected, true)) {
        return trailingslashit($corrected);
    }

    return $source;
}

// Limpia la caché del Release cuando se completa una actualización.
add_action('upgrader_process_complete', 'simple_smtp_clear_release_cache', 10, 0);
function simple_smtp_clear_release_cache() {
    delete_transient('simple_smtp_github_release');
}
