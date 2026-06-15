<?php
/*
Plugin Name: Simple SMTP
Plugin URI: https://github.com/cvaneyk/simple-smtp
Description: Configura el envío de correos mediante SMTP, sin publicidad sin anuncios, sin seguimiento, sin cookies. Simple y funcional.
Version: 1.3
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
        if (isset($_GET['smtp_test_result'])) {
            $class = ($_GET['smtp_test_result'] === 'success') ? 'notice-success' : 'notice-error';
            $message = ($_GET['smtp_test_result'] === 'success') 
                ? '¡Correo enviado correctamente!' 
                : 'Error al enviar el correo. Verifica la configuración SMTP.';
            ?>
            <div class="notice <?= $class ?> is-dismissible">
                <p><?= $message ?></p>
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
