<?php

/**
 * Plugin Name: Auto-Linking Masivo para Link Whisper con Análisis
 * Description: Agrgear links de forma automatica en Post para Link Whisper
 * Version: 2.1.3
 * Author: Awesome Med
 */


function is_link_whisper_active() {
    // Método 1: Comprobar clases comunes
    $possible_classes = array(
        'Link_Whisper', 'WPIL_Base', 'WPIL_Plugin', 'WPIL_AutoLink', 'WPIL_Report'
    );
    
    foreach ($possible_classes as $class) {
        if (class_exists($class)) {
            return true;
        }
    }
    
    // Método 2: Comprobar funciones comunes
    $possible_functions = array(
        'wpil_autolink_content', 'wpil_link_whisper_init', 'wpil_get_report_data'
    );
    
    foreach ($possible_functions as $function) {
        if (function_exists($function)) {
            return true;
        }
    }
    
    // Método 3: Comprobar constantes
    if (defined('WPIL_PLUGIN_NAME') || defined('WPIL_VERSION')) {
        return true;
    }
    
    // Método 4: Comprobar tablas en la base de datos
    global $wpdb;
    $possible_tables = array(
        $wpdb->prefix . 'wpil_keyword_links',
        $wpdb->prefix . 'wpil_broken_links',
        $wpdb->prefix . 'wpil_report_links'
    );
    
    foreach ($possible_tables as $table) {
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
        if ($table_exists) {
            return true;
        }
    }
    
    // Método 5: Comprobar plugins activos
    $active_plugins = get_option('active_plugins', array());
    foreach ($active_plugins as $plugin) {
        if (strpos($plugin, 'link-whisper') !== false || strpos($plugin, 'linkwhisper') !== false) {
            return true;
        }
    }
    
    return false;
}

/**
 * Función para obtener los keywords configurados en Link Whisper
 */
function get_link_whisper_keywords_from_db() {
    global $wpdb;
    $keywords = array();
    
    // Buscar en tablas específicas
    $tables_to_check = array(
        $wpdb->prefix . 'wpil_keyword_links',
        $wpdb->prefix . 'wpil_keywords',
        $wpdb->prefix . 'link_whisper_keywords',
    );
    
    $found_table = false;
    foreach ($tables_to_check as $table_name) {
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
            $found_table = true;
            
            // Obtener los keywords
            $query = "SELECT * FROM {$table_name} WHERE 1";
            $results = $wpdb->get_results($query, ARRAY_A);
            
            if (!empty($results)) {
                foreach ($results as $row) {
                    // Los nombres de columnas pueden variar
                    $keyword = isset($row['keyword']) ? $row['keyword'] : 
                               (isset($row['phrase']) ? $row['phrase'] : '');
                    
                    $url = isset($row['url']) ? $row['url'] : 
                           (isset($row['link_url']) ? $row['link_url'] : '');
                    
                    $post_id = isset($row['target_post_id']) ? $row['target_post_id'] : 
                               (isset($row['post_id']) ? $row['post_id'] : 0);
                    
                    if (!empty($keyword)) {
                        $keywords[$keyword] = array(
                            'url' => $url,
                            'post_id' => $post_id
                        );
                    }
                }
            }
            break;
        }
    }
    
    // Buscar en opciones de WordPress si no se encontraron en tablas
    if (empty($keywords)) {
        $options_to_check = array(
            'wpil_link_whisper_autolink_keywords',
            'wpil_autolink_keywords',
            'wpil_keyword_links',
            'link_whisper_keywords'
        );
        
        foreach ($options_to_check as $option_name) {
            $option_data = get_option($option_name);
            if (!empty($option_data) && is_array($option_data)) {
                $keywords = $option_data;
                break;
            }
        }
    }
    
    return $keywords;
}

/**
 * Función para aplicar autolinks a un post
 */
function manual_apply_autolinks($post_id, $max_links = 10) {
    global $wpdb;
    
    // Para depuración
    $debug = array(
        'post_id' => $post_id,
        'keywords_encontrados' => array(),
        'keywords_buscados' => array(),
        'contenido_analizado' => false,
        'idioma_post' => 'unknown'
    );
    
    $post = get_post($post_id);
    if (!$post) {
        return false;
    }
    
    // Detectar el idioma del post actual
    $post_language = 'unknown';

    // Verificar si debemos respetar el idioma
    $respect_language = get_option('lw_respect_language', 1); // 1 = sí, 0 = no
    
    // Intentar detectar con WPML
    if (function_exists('wpml_get_language_information')) {
        $lang_info = wpml_get_language_information(null, $post_id);
        if (isset($lang_info['language_code'])) {
            $post_language = $lang_info['language_code'];
        }
    }
    
    // Intentar con Polylang si aún es desconocido
    if ($post_language === 'unknown' && function_exists('pll_get_post_language')) {
        $lang = pll_get_post_language($post_id);
        if (!empty($lang)) {
            $post_language = $lang;
        }
    }
    
    // Intentar detectar por la URL del post
    if ($post_language === 'unknown') {
        $post_url = get_permalink($post_id);
        $post_language = detect_link_language($post_url);
    }
    
    $debug['idioma_post'] = $post_language;
    
    $content = $post->post_content;
    $original_content = $content;
    $links_added = 0;
    
    $debug['contenido_analizado'] = true;
    $debug['longitud_contenido'] = strlen($content);
    
    // Obtener keywords de la tabla correcta
    $keywords = array();
    
    // Buscar en la tabla wpha_wpil_keywords (como se ve en la imagen)
    $keyword_table = $wpdb->prefix . 'wpil_keywords';
    if ($wpdb->get_var("SHOW TABLES LIKE '$keyword_table'") === $keyword_table) {
        $query = "SELECT * FROM {$keyword_table} WHERE 1";
        $results = $wpdb->get_results($query, ARRAY_A);
        
        if (!empty($results)) {
            foreach ($results as $row) {
                $keyword = isset($row['keyword']) ? $row['keyword'] : '';
                $link = isset($row['link']) ? $row['link'] : '';
                
                if (!empty($keyword) && !empty($link)) {
                    // Detectar el idioma del enlace destino
                    $link_language = detect_link_language($link);
                    
                    $keywords[$keyword] = array(
                        'url' => $link,
                        'language' => $link_language
                    );
                    $debug['keywords_buscados'][] = array(
                        'keyword' => $keyword,
                        'language' => $link_language
                    );
                }
            }
        }
    }
    
    // Resto del código para buscar en tablas alternativas si es necesario...
    
    // Registrar cuántos keywords estamos buscando
    $debug['total_keywords'] = count($keywords);
    
    if (empty($keywords)) {
        // No hay keywords configurados, guardar el log de debug
        $debug['error'] = 'No se encontraron keywords configurados en Link Whisper';
        update_post_meta($post_id, '_autolink_debug', $debug);
        return 0;
    }
    
    // Aplicar cada keyword, respetando el idioma
    foreach ($keywords as $keyword => $link_data) {
        if ($links_added >= $max_links) {
            break;
        }
        
        // Determinar URL destino y su idioma
        $target_url = $link_data['url'] ?? '';
        $target_language = $link_data['language'] ?? 'unknown';
        
        // Sólo aplicar enlaces del mismo idioma que el post, o permitir todos si no se conoce el idioma
        //$language_match = ($post_language === 'unknown' || $target_language === 'unknown' || $post_language === $target_language);
        $language_match = !$respect_language || $post_language === 'unknown' || $target_language === 'unknown' || $post_language === $target_language;
        
        if (!$language_match) {
            $debug['skipped_keywords'][] = array(
                'keyword' => $keyword,
                'reason' => "Idioma del post ($post_language) no coincide con idioma del enlace ($target_language)"
            );
            continue; // Saltar a la siguiente iteración
        }
        
        // Verificación de presencia del keyword
        if (!empty($target_url)) {
            // Verificar coincidencia sin importar mayúsculas/minúsculas
            if (stripos($content, $keyword) !== false) {
                $debug['keywords_encontrados'][] = $keyword;
                
                // Patrón más flexible para capturar el keyword
                $pattern = '/\b(' . preg_quote($keyword, '/') . ')\b(?![^<]*>|[^<>]*<\/a>)/i';
                $replacement = '<a href="' . esc_url($target_url) . '">$1</a>';
                
                $new_content = preg_replace($pattern, $replacement, $content, 1);
                
                if ($new_content !== $content) {
                    $content = $new_content;
                    $links_added++;
                    $debug['links_agregados'][] = array(
                        'keyword' => $keyword,
                        'url' => $target_url,
                        'language' => $target_language
                    );
                }
            }
        }
    }
    
    // Actualizar el post solo si se agregaron links
    if ($links_added > 0 && $content !== $original_content) {
        wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $content
        ));
    }
    
    // Guardar el log de debug para este post
    $debug['total_links_agregados'] = $links_added;
    update_post_meta($post_id, '_autolink_debug', $debug);
    
    return $links_added;
}

function diagnosticar_autolink_problema() {
    global $wpdb;
    
    // 1. Verificar si hay keywords configurados
    $keyword_table = $wpdb->prefix . 'wpil_keyword_links';
    $keywords = array();
    
    if ($wpdb->get_var("SHOW TABLES LIKE '$keyword_table'") === $keyword_table) {
        $query = "SELECT COUNT(*) FROM {$keyword_table} WHERE enabled = 1";
        $count = $wpdb->get_var($query);
        
        echo "<p>Cantidad de keywords configurados en la tabla: {$count}</p>";
        
        if ($count > 0) {
            $query = "SELECT keyword, url FROM {$keyword_table} WHERE enabled = 1 LIMIT 10";
            $results = $wpdb->get_results($query, ARRAY_A);
            
            echo "<p>Primeros 10 keywords configurados:</p><ul>";
            foreach ($results as $row) {
                echo "<li>Keyword: '{$row['keyword']}' - URL: '{$row['url']}'</li>";
            }
            echo "</ul>";
        }
    } else {
        echo "<p>La tabla de keywords no existe.</p>";
    }
    
    // 2. Verificar la presencia de keywords en contenido
    $args = array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => 20,
    );
    
    $posts = get_posts($args);
    $keywords_sample = $wpdb->get_col("SELECT keyword FROM {$keyword_table} WHERE enabled = 1 LIMIT 10");
    
    if (!empty($posts) && !empty($keywords_sample)) {
        echo "<p>Buscando keywords en los primeros 20 posts:</p><ul>";
        
        foreach ($posts as $post) {
            $found = false;
            
            foreach ($keywords_sample as $keyword) {
                if (stripos($post->post_content, $keyword) !== false) {
                    echo "<li>Post ID {$post->ID}: '{$post->post_title}' contiene el keyword '{$keyword}'</li>";
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                echo "<li>Post ID {$post->ID}: '{$post->post_title}' no contiene ninguno de los keywords buscados</li>";
            }
        }
        
        echo "</ul>";
    }
}


/**
 * Función para analizar y detectar links internos en un post
 */
function analyze_post_internal_links($post_id) {
    // Obtener el post
    $post = get_post($post_id);
    if (!$post) {
        return false;
    }
    
    $content = $post->post_content;
    $post_title = $post->post_title;
    $domain = parse_url(get_site_url(), PHP_URL_HOST);
    
    // Obtener todos los enlaces dentro del contenido
    $links = array();
    $internal_links_count = 0;
    $external_links_count = 0;
    
    // Buscar todos los enlaces en el contenido
    preg_match_all('/<a\s[^>]*href=([\'"])(?<href>.+?)\1[^>]*>(?<anchor>.+?)<\/a>/i', $content, $matches);
    
    if (!empty($matches['href'])) {
        foreach ($matches['href'] as $index => $url) {
            $anchor_text = strip_tags($matches['anchor'][$index]);
            
            // Determinar si es un enlace interno o externo
            $parsed_url = parse_url($url);
            $is_internal = false;
            
            // Comprobar si es un enlace interno (mismo dominio)
            if (empty($parsed_url['host']) || $parsed_url['host'] === $domain) {
                $is_internal = true;
                $internal_links_count++;
                
                // Intentar identificar a qué post apunta si es interno
                $target_post_id = url_to_postid($url);
                if ($target_post_id > 0) {
                    $target_post = get_post($target_post_id);
                    $target_title = ($target_post) ? $target_post->post_title : 'Desconocido';
                } else {
                    $target_title = 'Internal page';
                }
            } else {
                $external_links_count++;
                $target_title = 'External link';
            }
            
            $links[] = array(
                'url' => $url,
                'anchor' => $anchor_text,
                'is_internal' => $is_internal,
                'target_title' => $target_title
            );
        }
    }
    
    return array(
        'post_id' => $post_id,
        'post_title' => $post_title,
        'internal_links_count' => $internal_links_count,
        'external_links_count' => $external_links_count,
        'total_links' => $internal_links_count + $external_links_count,
        'links' => $links
    );
}

/**
 * Función para escanear todos los posts y obtener información de links internos
 */
function scan_all_posts_for_internal_links($post_ids = null) {
    // Si no se proporcionan IDs específicos, obtener todos los posts
    if ($post_ids === null) {
        $args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        );
        
        $post_ids = get_posts($args);
    }
    
    $results = array();
    $posts_with_links = 0;
    $posts_without_links = 0;
    $total_internal_links = 0;
    
    foreach ($post_ids as $post_id) {
        $post_data = analyze_post_internal_links($post_id);
        
        if ($post_data) {
            $results[$post_id] = $post_data;
            $total_internal_links += $post_data['internal_links_count'];
            
            if ($post_data['internal_links_count'] > 0) {
                $posts_with_links++;
            } else {
                $posts_without_links++;
            }
        }
    }
    
    return array(
        'posts_analyzed' => count($post_ids),
        'posts_with_internal_links' => $posts_with_links,
        'posts_without_internal_links' => $posts_without_links,
        'total_internal_links' => $total_internal_links,
        'posts_data' => $results
    );
}

/**
 * Registra los datos de procesamiento por lotes
 */
function register_batch_processing_options() {
    add_option('lw_batch_processing_data', '', '', 'no');
    add_option('lw_batch_processing_status', '', '', 'no');
}
register_activation_hook(__FILE__, 'register_batch_processing_options');

/**
 * Agrega botón y estilos para el procesamiento por lotes y análisis
 */
function link_whisper_bulk_autolink_button() {
    // Solo mostrar en la página de posts
    $screen = get_current_screen();
    if ($screen->id !== 'edit-post') {
        return;
    }
    
    // Añadir estilos para la interfaz
    ?>
    <style>
        /* Estilos para la barra de progreso */
        .progress-container {
            width: 100%;
            background-color: #f1f1f1;
            border-radius: 4px;
            margin: 10px 0;
            display: none;
        }
        .progress-bar {
            height: 20px;
            background-color: #4CAF50;
            border-radius: 4px;
            width: 0%;
            text-align: center;
            line-height: 20px;
            color: white;
            transition: width 0.3s;
        }
        .lw-stats {
            margin-top: 5px;
            font-size: 12px;
            display: none;
        }
        .lw-batch-buttons {
            margin: 10px 0;
        }
        .lw-batch-buttons button {
            margin-right: 5px;
        }
        #cancel-autolink-button {
            display: none;
        }
        
        /* Estilos para la tabla de análisis */
        .internal-links-report {
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            padding: 15px;
            margin: 15px 0;
            display: none;
        }
        .internal-links-report h3 {
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .report-summary {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        .report-summary-item {
            flex: 0 0 22%;
            background: #f9f9f9;
            border: 1px solid #eee;
            padding: 10px;
            text-align: center;
            border-radius: 3px;
            margin-bottom: 10px;
        }
        .report-summary-item .number {
            font-size: 24px;
            font-weight: bold;
            color: #23282d;
        }
        .report-summary-item .label {
            color: #646970;
            font-size: 13px;
        }
        .report-posts-table {
            width: 100%;
            border-collapse: collapse;
        }
        .report-posts-table th {
            text-align: left;
            border-bottom: 1px solid #eee;
            padding: 8px;
            font-weight: 600;
        }
        .report-posts-table td {
            padding: 8px;
            border-bottom: 1px solid #f0f0f0;
        }
        .report-posts-table tr:hover {
            background-color: #f9f9f9;
        }
        .no-links {
            color: #d63638;
        }
        .has-links {
            color: #008a20;
        }
        .post-links-details {
            font-size: 12px;
            border: none;
            background: none;
            padding: 2px 5px;
            cursor: pointer;
            color: #2271b1;
            text-decoration: underline;
        }
        
        /* Estilos para el modal */
        .post-links-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 99999;
            display: none;
        }
        .post-links-modal-content {
            position: relative;
            width: 80%;
            max-width: 800px;
            max-height: 80%;
            margin: 5% auto;
            background: #fff;
            padding: 20px;
            border-radius: 4px;
            overflow-y: auto;
        }
        .post-links-modal-close {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 20px;
            cursor: pointer;
        }
        .links-list {
            margin-top: 20px;
        }
        .links-list table {
            width: 100%;
            border-collapse: collapse;
        }
        .links-list th {
            text-align: left;
            border-bottom: 1px solid #eee;
            padding: 8px;
        }
        .links-list td {
            padding: 8px;
            border-bottom: 1px solid #f0f0f0;
        }
        .link-internal {
            color: #2271b1;
        }
        .link-external {
            color: #d63638;
        }
        .analyze-loading {
            text-align: center;
            padding: 20px;
            display: none;
        }
        .separate-section {
            margin-top: 0;
            padding-top: 0;
            border-top: 1px solid #eee;
        }
    </style>
    
    <div class="wrap">
        <!-- Botones para Auto-Linking masivo con procesamiento por lotes -->
        <div class="lw-batch-buttons">
            <button id="bulk-autolink-button" class="button button-primary">
                Apply Bulk Auto-Linking
            </button>
            <button id="cancel-autolink-button" class="button">
                Cancel process
            </button>
            <span id="bulk-autolink-status" style="margin-left: 10px;"></span>
        </div>

        <div class="lw-batch-buttons">
                <button id="analyze-internal-links" class="button">
                    Analyze Internal Links
                </button>
                <span id="analyze-status" style="margin-left: 10px;"></span>
        </div>
        
        <div class="progress-container" id="progress-container">
            <div class="progress-bar" id="progress-bar">0%</div>
        </div>
        
        <div class="lw-stats" id="lw-stats">
            <div>Posts processed: <span id="processed-count">0</span> de <span id="total-count">0</span></div>
            <div>Links added: <span id="links-count">0</span></div>
            <div>Velocity: <span id="speed-info">0</span> posts/second</div>
            <div>Estimated time: <span id="time-remaining">calculated...</span></div>
        </div>
        
        <!-- Sección para análisis de links internos -->
        <div class="separate-section">
           
            
            <!-- Sección para el reporte de links internos -->
            <div class="internal-links-report" id="internal-links-report">
                <h3>Internal Links Report</h3>
                
                <div class="analyze-loading" id="analyze-loading">
                    <img src="<?php echo admin_url('images/spinner.gif'); ?>" alt="Cargando..."> 
                    Analyzing posts, please wait...
                </div>
                
                <div id="report-content" style="display:none;">
                    <div class="report-summary">
                        <div class="report-summary-item">
                            <div class="number" id="total-posts">0</div>
                            <div class="label">Posts Analyzed</div>
                        </div>
                        <div class="report-summary-item">
                            <div class="number" id="posts-with-links">0</div>
                            <div class="label">Posts with Links</div>
                        </div>
                        <div class="report-summary-item">
                            <div class="number" id="posts-without-links">0</div>
                            <div class="label">Posts without Links</div>
                        </div>
                        <div class="report-summary-item">
                            <div class="number" id="total-links">0</div>
                            <div class="label">Total Internal Links</div>
                        </div>
                    </div>
                    
                    <table class="report-posts-table" id="report-posts-table">
                        <thead>
                            <tr>
                                <th>Post Title</th>
                                <th>Internal Links</th>
                                <th>External Links</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="report-table-body">
                            <!-- Los datos de la tabla se llenarán con JavaScript -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Modal para ver detalles de links -->
        <div class="post-links-modal" id="post-links-modal">
            <div class="post-links-modal-content">
                <span class="post-links-modal-close" id="post-links-modal-close">&times;</span>
                <h3 id="post-links-modal-title">Links in: Post title</h3>
                
                <div class="links-list">
                    <table>
                        <thead>
                            <tr>
                                <th>Anchor Text</th>
                                <th>URL</th>
                                <th>Type</th>
                                <th>Target</th>
                            </tr>
                        </thead>
                        <tbody id="links-list-body">
                            <!-- Los datos se llenarán con JavaScript -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        /**************************************
         * PROCESAMIENTO POR LOTES
         **************************************/
        var isBatchProcessing = false;
        var batchSize = 70; // Número de posts por lote
        var totalProcessed = 0;
        var totalLinks = 0;
        var totalPosts = 0;
        var startTime;
        var postIdsToProcess = [];
        var currentBatch = 0;
        
        $('#bulk-autolink-button').click(function() {
            if (isBatchProcessing) {
                return;
            }
            
            if (!confirm('Are you sure? This action will apply Auto-Linking to all published posts, adding up to 5 links per post.')) {
                return;
            }
            
            startBatchProcessing();
        });
        
        $('#cancel-autolink-button').click(function() {
            if (confirm('Are you sure you want to cancel the process?')) {
                isBatchProcessing = false;
                resetUI();
                $('#bulk-autolink-status').html('<span style="color:orange">Process canceled by the user.</span>');
                
                // Informar al servidor que se canceló el proceso
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'link_whisper_cancel_batch',
                        nonce: '<?php echo wp_create_nonce('link_whisper_batch_nonce'); ?>'
                    }
                });
            }
        });
        
        function startBatchProcessing() {
            isBatchProcessing = true;
            startTime = new Date();
            totalProcessed = 0;
            totalLinks = 0;
            currentBatch = 0;
            
            // Actualizar la interfaz
            $('#bulk-autolink-button').prop('disabled', true);
            $('#cancel-autolink-button').show();
            $('#bulk-autolink-status').html('<span style="color:blue">Preparing process...</span>');
            $('#progress-container').show();
            $('#lw-stats').show();
            
            // Iniciar el proceso solicitando la lista de posts
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'link_whisper_init_batch',
                    nonce: '<?php echo wp_create_nonce('link_whisper_batch_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        postIdsToProcess = response.data.post_ids;
                        totalPosts = postIdsToProcess.length;
                        
                        $('#total-count').text(totalPosts);
                        $('#bulk-autolink-status').html('<span style="color:blue">Processing posts...</span>');
                        
                        // Iniciar el procesamiento por lotes
                        processBatch();
                    } else {
                        $('#bulk-autolink-status').html('<span style="color:red">Error: ' + response.data + '</span>');
                        resetUI();
                    }
                },
                error: function() {
                    $('#bulk-autolink-status').html('<span style="color:red">Connection error</span>');
                    resetUI();
                }
            });
        }
        
        function processBatch() {
            if (!isBatchProcessing || currentBatch >= Math.ceil(totalPosts / batchSize)) {
                // Proceso terminado o cancelado
                if (isBatchProcessing) {
                    finishBatchProcessing();
                }
                return;
            }
            
            // Calcular el rango de este lote
            var startIndex = currentBatch * batchSize;
            var endIndex = Math.min(startIndex + batchSize, totalPosts);
            var batchIds = postIdsToProcess.slice(startIndex, endIndex);
            
            // Actualizar la interfaz
            var percentComplete = Math.floor((totalProcessed / totalPosts) * 100);
            $('#progress-bar').css('width', percentComplete + '%').text(percentComplete + '%');
            $('#processed-count').text(totalProcessed);
            $('#links-count').text(totalLinks);
            
            // Calcular velocidad y tiempo restante
            if (totalProcessed > 0) {
                var elapsedSeconds = (new Date() - startTime) / 1000;
                var postsPerSecond = (totalProcessed / elapsedSeconds).toFixed(2);
                $('#speed-info').text(postsPerSecond);
                
                var remainingPosts = totalPosts - totalProcessed;
                var remainingSeconds = remainingPosts / postsPerSecond;
                var remainingText = '';
                
                if (remainingSeconds > 60) {
                    var minutes = Math.floor(remainingSeconds / 60);
                    var seconds = Math.floor(remainingSeconds % 60);
                    remainingText = minutes + 'm ' + seconds + 's';
                } else {
                    remainingText = Math.floor(remainingSeconds) + ' seconds';
                }
                
                $('#time-remaining').text(remainingText);
            }
            
            // Procesar este lote
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'link_whisper_process_batch',
                    nonce: '<?php echo wp_create_nonce('link_whisper_batch_nonce'); ?>',
                    post_ids: batchIds
                },
                success: function(response) {
                    if (response.success) {
                        totalProcessed += response.data.processed;
                        totalLinks += response.data.links_added;
                        currentBatch++;
                        
                        // Procesar el siguiente lote
                        processBatch();
                    } else {
                        $('#bulk-autolink-status').html('<span style="color:red">Error: ' + response.data + '</span>');
                        resetUI();
                    }
                },
                error: function() {
                    $('#bulk-autolink-status').html('<span style="color:red">Connection error during processing</span>');
                    resetUI();
                }
            });
        }
        
        function finishBatchProcessing() {
            var totalTime = ((new Date() - startTime) / 1000).toFixed(2);
            $('#progress-bar').css('width', '100%').text('100%');
            $('#processed-count').text(totalPosts);
            $('#bulk-autolink-status').html('<span style="color:green">✓ Completed! ' + 
                totalProcessed + ' processed posts. ' + 
                totalLinks + ' links added in ' + totalTime + ' seconds.</span>');
            
            resetUI(true);
        }
        
        function resetUI(completed = false) {
            isBatchProcessing = false;
            $('#bulk-autolink-button').prop('disabled', false);
            $('#cancel-autolink-button').hide();
            
            if (!completed) {
                $('#progress-container').hide();
                $('#lw-stats').hide();
            }
        }
        
        /**************************************
         * ANÁLISIS DE LINKS INTERNOS
         **************************************/
        var analysisData = null;
        
        // Botón para analizar links internos
        $('#analyze-internal-links').click(function() {
            $('#analyze-status').html('');
            $('#analyze-loading').show();
            $('#report-content').hide();
            $('#internal-links-report').show();
            
            // Solicitar análisis
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'link_whisper_analyze_internal_links',
                    nonce: '<?php echo wp_create_nonce('link_whisper_batch_nonce'); ?>'
                },
                success: function(response) {
                    $('#analyze-loading').hide();
                    
                    if (response.success) {
                        analysisData = response.data;
                        displayAnalysisResults(analysisData);
                        $('#report-content').show();
                    } else {
                        $('#analyze-status').html('<span style="color:red">Error: ' + response.data + '</span>');
                    }
                },
                error: function() {
                    $('#analyze-loading').hide();
                    $('#analyze-status').html('<span style="color:red">Connection error</span>');
                }
            });
        });
        
        // Función para mostrar los resultados del análisis
        function displayAnalysisResults(data) {
            // Actualizar los números del resumen
            $('#total-posts').text(data.posts_analyzed);
            $('#posts-with-links').text(data.posts_with_internal_links);
            $('#posts-without-links').text(data.posts_without_internal_links);
            $('#total-links').text(data.total_internal_links);
            
            // Limpiar la tabla
            $('#report-table-body').empty();
            
            // Llenar la tabla con los datos de los posts
            var posts = data.posts_data;
            
            // Convertir el objeto a un array para ordenar
            var postsArray = Object.values(posts);
            
            // Ordenar los posts: primero los que no tienen links internos
            postsArray.sort(function(a, b) {
                return a.internal_links_count - b.internal_links_count;
            });
            
            // Agregar filas a la tabla
            $.each(postsArray, function(index, post) {
                var row = '<tr>' +
                    '<td>' + post.post_title + '</td>' +
                    '<td class="' + (post.internal_links_count > 0 ? 'has-links' : 'no-links') + '">' + 
                        post.internal_links_count + '</td>' +
                    '<td>' + post.external_links_count + '</td>' +
                    '<td><button class="post-links-details" data-post-id="' + post.post_id + '">View Details</button></td>' +
                    '</tr>';
                
                $('#report-table-body').append(row);
            });
            
            // Si el escaneo fue parcial, mostrar un mensaje
            /*if (data.partial_scan) {
                $('#analyze-status').html('<span style="color:orange">Nota: Se analizaron solo los primeros 500 posts para evitar sobrecargar el servidor.</span>');
            }*/
            
            // Activar los botones de detalles
            $('.post-links-details').click(function() {
                var postId = $(this).data('post-id');
                showPostLinksDetails(postId);
            });
        }
        
        // Función para mostrar los detalles de links de un post
        function showPostLinksDetails(postId) {
            // Si ya tenemos los datos, mostrarlos directamente
            if (analysisData && analysisData.posts_data[postId]) {
                displayPostLinksModal(analysisData.posts_data[postId]);
            } else {
                // Si no, solicitarlos al servidor
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'link_whisper_get_post_links',
                        nonce: '<?php echo wp_create_nonce('link_whisper_batch_nonce'); ?>',
                        post_id: postId
                    },
                    success: function(response) {
                        if (response.success) {
                            displayPostLinksModal(response.data);
                        } else {
                            alert('Error: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('Error de conexión');
                    }
                });
            }
        }
        
        // Función para mostrar el modal con los detalles de links
        function displayPostLinksModal(postData) {
            // Actualizar título del modal
            $('#post-links-modal-title').text('Links en: ' + postData.post_title);
            
            // Limpiar la tabla de links
            $('#links-list-body').empty();
            
            // Llenar la tabla con los links
            if (postData.links && postData.links.length > 0) {
                $.each(postData.links, function(index, link) {
                    var linkType = link.is_internal ? 
                        '<span class="link-internal">Internal</span>' : 
                        '<span class="link-external">External</span>';
                    
                    var row = '<tr>' +
                        '<td>' + link.anchor + '</td>' +
                        '<td><a href="' + link.url + '" target="_blank">' + link.url + '</a></td>' +
                        '<td>' + linkType + '</td>' +
                        '<td>' + link.target_title + '</td>' +
                        '</tr>';
                    
                    $('#links-list-body').append(row);
                });
            } else {
                $('#links-list-body').html('<tr><td colspan="4">No links were found in this post.</td></tr>');
            }
            
            // Mostrar el modal
            $('#post-links-modal').show();
        }
        
        // Cerrar el modal
        $('#post-links-modal-close').click(function() {
            $('#post-links-modal').hide();
        });
        
        // Cerrar el modal al hacer clic fuera del contenido
        $(window).click(function(event) {
            if (event.target.id === 'post-links-modal') {
                $('#post-links-modal').hide();
            }
        });
    });
    </script>
    <?php
}
add_action('admin_notices', 'link_whisper_bulk_autolink_button');

/**
 * Endpoint AJAX para inicializar el procesamiento por lotes
 */
function link_whisper_init_batch_ajax() {
    // Verificar nonce y permisos
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'link_whisper_batch_nonce')) {
        wp_send_json_error('Security error');
    }
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('You do not have sufficient permissions');
    }
    
    // Verificar Link Whisper
    if (!is_link_whisper_active()) {
        wp_send_json_error('Link Whisper is not active or could not be detected correctly.');
    }
    
    // Obtener todos los posts publicados
    $args = array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
    );
    
    $post_ids = get_posts($args);
    
    if (empty($post_ids)) {
        wp_send_json_error('No published posts were found to process.');
    }
    
    // Guardar el estado del procesamiento por lotes
    $batch_data = array(
        'post_ids' => $post_ids,
        'total' => count($post_ids),
        'processed' => 0,
        'links_added' => 0,
        'start_time' => time(),
        'status' => 'running'
    );
    
    update_option('lw_batch_processing_data', $batch_data);
    update_option('lw_batch_processing_status', 'running');
    
    wp_send_json_success(array(
        'post_ids' => $post_ids,
        'total' => count($post_ids)
    ));
}
add_action('wp_ajax_link_whisper_init_batch', 'link_whisper_init_batch_ajax');

/**
 * Endpoint AJAX para procesar un lote de posts
 */
function link_whisper_process_batch_ajax() {
    // Verificar nonce y permisos
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'link_whisper_batch_nonce')) {
        wp_send_json_error('Security error');
    }
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('You do not have sufficient permissions');
    }
    
    // Verificar que tenemos IDs de posts para procesar
    if (!isset($_POST['post_ids']) || !is_array($_POST['post_ids'])) {
        wp_send_json_error('No valid post IDs were received');
    }
    
    $post_ids = array_map('intval', $_POST['post_ids']);
    $processed = 0;
    $links_added = 0;
    
    // Verificar el estado del procesamiento
    $status = get_option('lw_batch_processing_status');
    if ($status !== 'running') {
        wp_send_json_error('Batch processing has been canceled or has already ended.');
    }
    
    // Procesar cada post
    foreach ($post_ids as $post_id) {
        $added = manual_apply_autolinks($post_id, 5);
        
        if ($added !== false) {
            $links_added += $added;
            $processed++;
        }
    }
    
    // Actualizar el estado global del procesamiento
    $batch_data = get_option('lw_batch_processing_data');
    if (is_array($batch_data)) {
        $batch_data['processed'] += $processed;
        $batch_data['links_added'] += $links_added;
        update_option('lw_batch_processing_data', $batch_data);
    }
    
    wp_send_json_success(array(
        'processed' => $processed,
        'links_added' => $links_added
    ));
}
add_action('wp_ajax_link_whisper_process_batch', 'link_whisper_process_batch_ajax');

/**
 * Endpoint AJAX para cancelar el procesamiento por lotes
 */
function link_whisper_cancel_batch_ajax() {
    // Verificar nonce y permisos
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'link_whisper_batch_nonce')) {
        wp_send_json_error('Security error');
    }
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('You do not have sufficient permissions');
    }
    
    // Actualizar el estado a "cancelado"
    update_option('lw_batch_processing_status', 'cancelled');
    
    $batch_data = get_option('lw_batch_processing_data');
    if (is_array($batch_data)) {
        $batch_data['status'] = 'cancelled';
        update_option('lw_batch_processing_data', $batch_data);
    }
    
    wp_send_json_success('Process successfully canceled');
}
add_action('wp_ajax_link_whisper_cancel_batch', 'link_whisper_cancel_batch_ajax');

/**
 * Endpoint AJAX para analizar links internos
 */
function analyze_internal_links_ajax() {
    // Verificar nonce y permisos
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'link_whisper_batch_nonce')) {
        wp_send_json_error('Error de seguridad');
    }
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('You do not have sufficient permissions');
    }
    
    // Obtener todos los posts publicados
    $args = array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
    );
    
    $post_ids = get_posts($args);
    
    if (empty($post_ids)) {
        wp_send_json_error('No published posts were found to analyze.');
    }
    
    // Analizar por lotes si hay muchos posts
    if (count($post_ids) > 100) {
        // Solo analizar los primeros 100 posts para evitar timeout
        $post_ids = array_slice($post_ids, 0, 500);
        $partial_scan = true;
    } else {
        $partial_scan = false;
    }
    
    // Realizar el análisis
    $results = scan_all_posts_for_internal_links($post_ids);
    $results['partial_scan'] = $partial_scan;
    
    // Guardar los resultados en una opción para consulta posterior
    update_option('lw_internal_links_analysis', $results);
    
    wp_send_json_success($results);
}
add_action('wp_ajax_link_whisper_analyze_internal_links', 'analyze_internal_links_ajax');

/**
 * Endpoint AJAX para obtener detalles de links de un post específico
 */
function get_post_links_details_ajax() {
    // Verificar nonce y permisos
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'link_whisper_batch_nonce')) {
        wp_send_json_error('Error de seguridad');
    }
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('No tienes permisos suficientes');
    }
    
    // Verificar ID del post
    if (!isset($_POST['post_id']) || !intval($_POST['post_id'])) {
        wp_send_json_error('Invalid post ID');
    }
    
    $post_id = intval($_POST['post_id']);
    $post_data = analyze_post_internal_links($post_id);
    
    if (!$post_data) {
        wp_send_json_error('The post could not be parsed.');
    }
    
    wp_send_json_success($post_data);
}
add_action('wp_ajax_link_whisper_get_post_links', 'get_post_links_details_ajax');

/**
 * Añade un botón de diagnóstico al panel
 */
function agregar_boton_diagnostico() {
    // Solo mostrar en la página de posts
    $screen = get_current_screen();
    if ($screen->id !== 'edit-post') {
        return;
    }
    ?>
    <div class="wrap" style="margin-top:0; padding: 10px; background: #f8f8f8; border: 1px solid #ddd;">
        <button id="diagnostico-autolink" class="button">
        Diagnose connection with Link Whisper
        </button>
        <div id="resultado-diagnostico" style="margin-top: 10px; display: none; max-height: 400px; overflow-y: auto; padding: 10px; background: #fff; border: 1px solid #eee;"></div>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        $('#diagnostico-autolink').click(function() {
            var resultadoDiv = $('#resultado-diagnostico');
            resultadoDiv.html('<p>Diagnosing... please wait.</p>');
            resultadoDiv.show();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'link_whisper_diagnostico',
                    nonce: '<?php echo wp_create_nonce('link_whisper_batch_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        resultadoDiv.html(response.data);
                    } else {
                        resultadoDiv.html('<p style="color:red">Error: ' + response.data + '</p>');
                    }
                },
                error: function() {
                    resultadoDiv.html('<p style="color:red">Connection error during diagnosis</p>');
                }
            });
        });
    });
    </script>
    <?php
}
add_action('admin_notices', 'agregar_boton_diagnostico');

/**
 * Endpoint AJAX para el diagnóstico
 */
function link_whisper_diagnostico_ajax() {
    // Verificar nonce y permisos
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'link_whisper_batch_nonce')) {
        wp_send_json_error('Error de seguridad');
    }
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('No tienes permisos suficientes');
    }
    
    global $wpdb;
    $output = '<h4>Diagnostic results:</h4>';
    
    // 1. Verificar si Link Whisper está activo
    $output .= '<h5>1. Link Whisper Status:</h5>';
    if (is_link_whisper_active()) {
        $output .= '<p style="color:green">✅ Link Whisper is active.</p>';
    } else {
        $output .= '<p style="color:red">❌ Link Whisper is not active or was not detected correctly.</p>';
    }
    
    // 2. Verificar keywords configurados - ACTUALIZADO PARA MÚLTIPLES TABLAS
    $output .= '<h5>2. Keywords configured:</h5>';
    
    $keyword_table = $wpdb->prefix . 'wpil_keywords'; // Primera tabla a verificar
    $keywords_found = false;
    
    if ($wpdb->get_var("SHOW TABLES LIKE '$keyword_table'") === $keyword_table) {
        $query = "SELECT COUNT(*) FROM {$keyword_table}";
        $count = $wpdb->get_var($query);
        
        if ($count > 0) {
            $keywords_found = true;
            $output .= '<p style="color:green">✅ They were found ' . $count . ' keywords in the table ' . $keyword_table . '.</p>';
            
            $query = "SELECT keyword, link FROM {$keyword_table} LIMIT 10";
            $results = $wpdb->get_results($query, ARRAY_A);
            
            $output .= '<p>First 10 keywords configured:</p><ul>';
            foreach ($results as $row) {
                $output .= "<li><strong>{$row['keyword']}</strong> → {$row['link']}</li>";
            }
            $output .= '</ul>';
        }
    }
    
    // Verificar tabla alternativa si no encontramos en la primera
    if (!$keywords_found) {
        $alt_table = $wpdb->prefix . 'wpil_keyword_links';
        if ($wpdb->get_var("SHOW TABLES LIKE '$alt_table'") === $alt_table) {
            $query = "SELECT COUNT(*) FROM {$alt_table} WHERE enabled = 1";
            $count = $wpdb->get_var($query);
            
            if ($count > 0) {
                $keywords_found = true;
                $output .= '<p style="color:green">✅ Se encontraron ' . $count . ' keywords in the table ' . $alt_table . '.</p>';
                
                $query = "SELECT keyword, url FROM {$alt_table} WHERE enabled = 1 LIMIT 10";
                $results = $wpdb->get_results($query, ARRAY_A);
                
                $output .= '<p>First 10 keywords configured:</p><ul>';
                foreach ($results as $row) {
                    $output .= "<li><strong>{$row['keyword']}</strong> → {$row['url']}</li>";
                }
                $output .= '</ul>';
            }
        }
    }
    
    // Si no encontramos keywords en ninguna tabla
    if (!$keywords_found) {
        $output .= '<p style="color:red">❌ No configured keywords were found in any Link Whisper table.</p>';
        $output .= '<p>You must configure keywords in the Auto-Linking section of Link Whisper before applying the bulk process.</p>';
    }
    
    // 3. Verificar presencia de keywords en posts
    if ($keywords_found) {
        $output .= '<h5>3. Checking keywords in the content:</h5>';
        
        // Obtener una muestra de keywords para verificación
        if (isset($results) && !empty($results)) {
            $keywords_sample = array();
            foreach ($results as $row) {
                $keyword_field = isset($row['keyword']) ? 'keyword' : 'phrase';
                $keywords_sample[] = $row[$keyword_field];
                if (count($keywords_sample) >= 5) break;
            }
            
            // Obtener una muestra de posts
            $args = array(
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => 10,
            );
            
            $posts = get_posts($args);
            
            if (!empty($posts) && !empty($keywords_sample)) {
                $found_count = 0;
                $output .= '<p>Searching for keywords in 10 random posts:</p>';
                
                foreach ($posts as $post) {
                    $found = false;
                    $matches = array();
                    
                    foreach ($keywords_sample as $keyword) {
                        if (stripos($post->post_content, $keyword) !== false) {
                            $matches[] = $keyword;
                            $found = true;
                        }
                    }
                    
                    if ($found) {
                        $found_count++;
                        $output .= "<p>✅ Post <strong>{$post->post_title}</strong> contains the keywords: " . implode(', ', $matches) . "</p>";
                    }
                }
                
                if ($found_count == 0) {
                    $output .= '<p style="color:red">❌ None of the example keywords were found in any of the analyzed posts. This is likely the reason why no links are added.</p>';
                    $output .= '<p>Make sure the keywords you set actually appear in the text of your posts.</p>';
                } else {
                    $output .= "<p>Keywords were found in {$found_count} of 10 posts analyzed.</p>";
                    
                    if ($found_count < 5) {
                        $output .= '<p style="color:orange">⚠️ Few posts contain the keywords. This could be the reason why few links are added.</p>';
                    }
                }
            }
        }
    }
    
    
    wp_send_json_success($output);
}
add_action('wp_ajax_link_whisper_diagnostico', 'link_whisper_diagnostico_ajax');

/**
 * Función para detectar el idioma de un enlace basado en su URL
 * 
 * @param string $url URL del enlace a analizar
 * @param int $post_id ID del post (opcional, para verificar plugins multilingües)
 * @return string Código del idioma detectado ('es', 'en', etc.) o 'unknown'
 */
function detect_link_language($url, $post_id = 0) {
    // Patrones comunes para detectar idioma en URLs
    $patterns = array(
        'es' => array('/\/es\//', '/-es\//', '/\/es\./', '-es.', '/\.es\//', '/\/es$/', '/-es$/'),
        'en' => array('/\/en\//', '/-en\//', '/\/en\./', '-en.', '/\.en\//', '/\/en$/', '/-en$/'),
        // Puedes agregar más idiomas aquí siguiendo el mismo patrón
    );
    
    // Inicializar con idioma desconocido
    $detected_language = 'unknown';
    
    // Verificar patrones básicos en la URL
    foreach ($patterns as $language => $lang_patterns) {
        foreach ($lang_patterns as $pattern) {
            if (preg_match($pattern, $url)) {
                $detected_language = $language;
                break 2; // Salir de ambos bucles
            }
        }
    }
    
    // Si no se detectó el idioma por la URL, intentar con plugins multilingües
    if ($detected_language === 'unknown' && $post_id > 0) {
        // Compatibilidad con WPML
        if (function_exists('wpml_get_language_information')) {
            $lang_info = wpml_get_language_information(null, $post_id);
            if (isset($lang_info['language_code'])) {
                $detected_language = $lang_info['language_code'];
            }
        }
        
        // Compatibilidad con Polylang
        if (function_exists('pll_get_post_language')) {
            $lang = pll_get_post_language($post_id);
            if (!empty($lang)) {
                $detected_language = $lang;
            }
        }
        
        // Compatibilidad con TranslatePress
        // TranslatePress almacena la información de idioma en la URL, así que ya debería haberse detectado
    }
    
    return $detected_language;
}

/**
 * Agrega opciones de configuración para el plugin
 */
function link_whisper_bulk_settings_page() {
    // Verificar si es la página de opciones de Link Whisper
    $screen = get_current_screen();
    if ($screen->id !== 'link-whisper_page_link-whisper-settings') {
        return;
    }
    
    // Guardar las opciones si se enviaron
    if (isset($_POST['save_lw_bulk_settings']) && check_admin_referer('lw_bulk_settings_nonce')) {
        $respect_language = isset($_POST['lw_respect_language']) ? 1 : 0;
        update_option('lw_respect_language', $respect_language);
        echo '<div class="notice notice-success is-dismissible"><p>Configuración guardada.</p></div>';
    }
    
    // Obtener las opciones actuales
    $respect_language = get_option('lw_respect_language', 1); // Por defecto respeta el idioma
    
    ?>
    <div class="wrap">
        <h2>Configuración de Auto-Linking Masivo</h2>
        
        <form method="post" action="">
            <?php wp_nonce_field('lw_bulk_settings_nonce'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">Compatibilidad multilingüe</th>
                    <td>
                        <label>
                            <input type="checkbox" name="lw_respect_language" value="1" <?php checked(1, $respect_language); ?>>
                            Respetar el idioma de los posts (solo enlazar a contenido del mismo idioma)
                        </label>
                        <p class="description">Si esta opción está activada, solo se añadirán enlaces a posts que estén en el mismo idioma que el post actual.</p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="save_lw_bulk_settings" class="button-primary" value="Guardar configuración">
            </p>
        </form>
    </div>
    <?php
}
add_action('admin_head', 'link_whisper_bulk_settings_page');