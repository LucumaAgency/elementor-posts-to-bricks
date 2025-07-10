<?php
/**
 * Plugin Name: Fospibay CSV Import Plugin
 * Description: Imports posts, categories, and images from a CSV in batches, cleans content, downloads images, sets featured image, saves galleries to ACF field, handles duplicates, and avoids re-uploading existing images using filename. Includes a feature to import only featured images for posts without them.
 * Version: 2.10
 * Author: Grok
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Add admin menu for CSV upload and featured image import
add_action('admin_menu', 'fospibay_add_admin_menu');
function fospibay_add_admin_menu() {
    add_menu_page(
        'Fospibay CSV Import',
        'Fospibay Import',
        'manage_options',
        'fospibay-csv-import',
        'fospibay_csv_import_page',
        'dashicons-upload'
    );
    add_submenu_page(
        'fospibay-csv-import',
        'Importar Imágenes Destacadas',
        'Importar Imágenes Destacadas',
        'manage_options',
        'fospibay-featured-image-import',
        'fospibay_featured_image_import_page'
    );
}

// Admin page for uploading CSV (main import)
function fospibay_csv_import_page() {
    ?>
    <div class="wrap">
        <h1>Fospibay CSV Import</h1>
        <?php
        $state = get_option('fospibay_import_state', false);
        if ($state && file_exists($state['file'])) {
            echo '<div class="notice notice-info"><p>Importación previa interrumpida en fila ' . esc_html($state['row_index']) . '. <a href="' . esc_url(add_query_arg('resume_import', '1')) . '">Reanudar</a> o <a href="' . esc_url(add_query_arg('cancel_import', '1')) . '">Cancelar</a></p></div>';
        }
        if (isset($_GET['cancel_import']) && wp_verify_nonce($_GET['_wpnonce'], 'fospibay_cancel_import')) {
            unlink($state['file']);
            delete_option('fospibay_import_state');
            echo '<div class="updated"><p>Importación cancelada.</p></div>';
        }
        ?>
        <form method="post" enctype="multipart/form-data">
            <p>
                <label for="csv_file">Selecciona el archivo CSV:</label><br>
                <input type="file" name="csv_file" id="csv_file" accept=".csv">
            </p>
            <p>
                <label for="skip_existing">
                    <input type="checkbox" name="skip_existing" id="skip_existing" value="1">
                    Ignorar entradas existentes (no actualizar ni importar duplicados)
                </label>
            </p>
            <p>
                <label for="batch_size">Tamaño del lote (filas por lote):</label><br>
                <input type="number" name="batch_size" id="batch_size" value="50" min="1" max="1000">
            </p>
            <p>
                <label for="delimiter">Delimitador CSV:</label><br>
                <select name="delimiter" id="delimiter">
                    <option value=",">Coma (,)</option>
                    <option value=";">Punto y coma (;)</option>
                </select>
            </p>
            <?php wp_nonce_field('fospibay_csv_import', 'fospibay_csv_nonce'); ?>
            <p>
                <input type="submit" class="button button-primary" value="Importar CSV">
            </p>
        </form>
        <?php
        if (isset($_POST['fospibay_csv_nonce']) && wp_verify_nonce($_POST['fospibay_csv_nonce'], 'fospibay_csv_import') && !empty($_FILES['csv_file']['tmp_name'])) {
            $skip_existing = isset($_POST['skip_existing']) && $_POST['skip_existing'] == '1';
            $batch_size = isset($_POST['batch_size']) ? max(1, min(1000, absint($_POST['batch_size']))) : 50;
            $delimiter = isset($_POST['delimiter']) && $_POST['delimiter'] === ';' ? ';' : ',';
            $upload_dir = wp_upload_dir();
            $target_file = $upload_dir['path'] . '/fospibay-import-' . time() . '.csv';
            move_uploaded_file($_FILES['csv_file']['tmp_name'], $target_file);
            update_option('fospibay_import_state', [
                'file' => $target_file,
                'row_index' => 2,
                'imported' => 0,
                'updated' => 0,
                'skipped' => 0,
                'batch_size' => $batch_size,
                'skip_existing' => $skip_existing,
                'delimiter' => $delimiter,
                'offset' => 0
            ]);
            wp_schedule_single_event(time(), 'fospibay_process_batch');
            echo '<div class="updated"><p>Importación iniciada en segundo plano. Consulta el log en <code>' . esc_html(WP_CONTENT_DIR . '/fospibay-import-log.txt') . '</code> o espera a que finalice.</p></div>';
        }
        ?>
        <p>Consulta el archivo de log en <code><?php echo esc_html(WP_CONTENT_DIR . '/fospibay-import-log.txt'); ?></code> para detalles de la importación.</p>
    </div>
    <?php
}

// Admin page for importing featured images only
function fospibay_featured_image_import_page() {
    ?>
    <div class="wrap">
        <h1>Importar Imágenes Destacadas</h1>
        <p>Sube el mismo CSV usado para importar las entradas. El plugin buscará entradas sin imagen destacada y asignará las imágenes desde la columna 'Featured' o 'URL_2'.</p>
        <form method="post" enctype="multipart/form-data">
            <p>
                <label for="csv_file">Selecciona el archivo CSV:</label><br>
                <input type="file" name="csv_file" id="csv_file" accept=".csv">
            </p>
            <p>
                <label for="delimiter">Delimitador CSV:</label><br>
                <select name="delimiter" id="delimiter">
                    <option value=",">Coma (,)</option>
                    <option value=";">Punto y coma (;)</option>
                </select>
            </p>
            <?php wp_nonce_field('fospibay_featured_image_import', 'fospibay_featured_image_nonce'); ?>
            <p>
                <input type="submit" class="button button-primary" value="Importar Imágenes Destacadas">
            </p>
        </form>
        <?php
        if (isset($_POST['fospibay_featured_image_nonce']) && wp_verify_nonce($_POST['fospibay_featured_image_nonce'], 'fospibay_featured_image_import') && !empty($_FILES['csv_file']['tmp_name'])) {
            $delimiter = isset($_POST['delimiter']) && $_POST['delimiter'] === ';' ? ';' : ',';
            fospibay_import_featured_images($_FILES['csv_file']['tmp_name'], $delimiter);
        }
        ?>
        <p>Consulta el archivo de log en <code><?php echo esc_html(WP_CONTENT_DIR . '/fospibay-import-log.txt'); ?></code> para detalles de la importación.</p>
    </div>
    <?php
}

// Log errors to a file for debugging
function fospibay_log_error($message) {
    $log_file = WP_CONTENT_DIR . '/fospibay-import-log.txt';
    $timestamp = current_time('mysql');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
}

// Check if an image already exists by filename
function fospibay_check_existing_image($image_url) {
    global $wpdb;
    $filename = basename($image_url);
    fospibay_log_error('Verificando imagen existente para nombre de archivo: ' . $filename);
    $attachment = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_name = %s",
            sanitize_title(pathinfo($filename, PATHINFO_FILENAME))
        )
    );
    if ($attachment) {
        fospibay_log_error('Imagen existente encontrada para nombre de archivo: ' . $filename . ' - ID: ' . $attachment->ID);
        return $attachment->ID;
    }
    fospibay_log_error('No se encontró imagen existente para nombre de archivo: ' . $filename);
    return false;
}

// Import only featured images for posts without them
function fospibay_import_featured_images($file_path, $delimiter) {
    if (!file_exists($file_path) || !is_readable($file_path)) {
        echo '<div class="error"><p>Error: No se pudo leer el archivo CSV.</p></div>';
        fospibay_log_error('No se pudo leer el archivo CSV: ' . $file_path);
        return;
    }

    fospibay_log_error('Iniciando importación de imágenes destacadas. Archivo: ' . $file_path . ', Delimitador: ' . $delimiter);

    // Get posts without featured images
    $args = [
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'meta_query' => [
            [
                'key' => '_thumbnail_id',
                'compare' => 'NOT EXISTS',
            ],
        ],
        'fields' => 'ids',
    ];
    $query = new WP_Query($args);
    $posts_without_featured = $query->posts;
    if (empty($posts_without_featured)) {
        echo '<div class="notice notice-info"><p>No se encontraron entradas sin imagen destacada.</p></div>';
        fospibay_log_error('No se encontraron entradas sin imagen destacada.');
        return;
    }
    $posts_without_featured = array_flip($posts_without_featured); // Use post IDs as keys for faster lookup
    fospibay_log_error('Entradas sin imagen destacada encontradas: ' . count($posts_without_featured));

    // Read CSV headers
    $file_handle = fopen($file_path, 'r');
    $raw_headers = fgetcsv($file_handle, 0, $delimiter, '"', '\\');
    if ($raw_headers && isset($raw_headers[0])) {
        $raw_headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $raw_headers[0]); // Remove BOM
    }
    fospibay_log_error('Encabezados crudos del CSV: ' . print_r($raw_headers, true));
    if (empty($raw_headers) || count($raw_headers) < 2) {
        echo '<div class="error"><p>Error: No se encontraron encabezados válidos en el CSV.</p></div>';
        fospibay_log_error('No se encontraron encabezados válidos en el CSV.');
        fclose($file_handle);
        return;
    }

    // Handle duplicate headers
    $header_map = [];
    $used_headers = [];
    foreach ($raw_headers as $index => $header) {
        $original_header = trim($header);
        $new_header = $original_header;
        $suffix = 1;
        while (isset($used_headers[$new_header])) {
            $new_header = $original_header . '_' . $suffix;
            $suffix++;
        }
        $header_map[$new_header] = $index;
        $used_headers[$new_header] = true;
    }
    $headers = array_keys($header_map);
    fospibay_log_error('Encabezados del CSV procesados: ' . implode(', ', $headers) . ' (Total: ' . count($headers) . ')');

    // Find featured image header
    $featured_image_header = null;
    $possible_image_headers = ['Featured', 'URL', 'url', 'Featured Image', 'featured_image', 'image_url', 'Image URL', 'URL_2'];
    foreach ($possible_image_headers as $header) {
        if (in_array($header, $headers)) {
            $featured_image_header = $header;
            fospibay_log_error('Encabezado de imagen destacada encontrado: ' . $header . ' (índice ' . $header_map[$header] . ')');
            break;
        }
    }
    if (!$featured_image_header) {
        echo '<div class="error"><p>Error: No se encontró una columna de imagen destacada en el CSV.</p></div>';
        fospibay_log_error('No se encontró encabezado de imagen destacada.');
        fclose($file_handle);
        return;
    }

    // Process CSV rows
    $updated = 0;
    $skipped = 0;
    $row_index = 2;
    while (($row = fgetcsv($file_handle, 0, $delimiter, '"', '\\')) !== false) {
        fospibay_log_error('Datos crudos de la fila ' . $row_index . ': ' . print_r($row, true));
        if (empty(array_filter($row, function($value) { return trim($value) !== ''; }))) {
            fospibay_log_error('Fila ' . $row_index . ' está vacía o inválida, omitiendo.');
            $skipped++;
            $row_index++;
            continue;
        }

        if (count($row) !== count($headers)) {
            fospibay_log_error('Advertencia en fila ' . $row_index . ': Número de columnas (' . count($row) . ') no coincide con encabezados (' . count($headers) . '). Ajustando fila.');
            $row = array_slice($row, 0, count($headers));
            $row = array_pad($row, count($headers), '');
            fospibay_log_error('Fila ajustada ' . $row_index . ': ' . print_r($row, true));
        }

        $data = array_combine($headers, $row);
        if ($data === false) {
            fospibay_log_error('Error al combinar fila ' . $row_index . ' con encabezados. Raw data: ' . print_r($row, true));
            $skipped++;
            $row_index++;
            continue;
        }

        $post_id_from_csv = !empty($data['ID']) ? absint($data['ID']) : 0;
        if (!$post_id_from_csv || !isset($posts_without_featured[$post_id_from_csv])) {
            fospibay_log_error('Fila ' . $row_index . ' omitida: ID ' . $post_id_from_csv . ' no válido o entrada ya tiene imagen destacada.');
            $skipped++;
            $row_index++;
            continue;
        }

        $image_url = trim($data[$featured_image_header]);
        if (empty($image_url) || !filter_var($image_url, FILTER_VALIDATE_URL)) {
            fospibay_log_error('Fila ' . $row_index . ' omitida: URL de imagen destacada inválida o vacía: ' . $image_url);
            $skipped++;
            $row_index++;
            continue;
        }

        fospibay_log_error('Procesando imagen destacada para entrada ID ' . $post_id_from_csv . ': ' . $image_url);
        $existing_image_id = fospibay_check_existing_image($image_url);
        $featured_image_id = $existing_image_id ?: fospibay_download_and_attach_image($image_url, $post_id_from_csv);
        if ($featured_image_id && !is_wp_error($featured_image_id)) {
            $attachment = get_post($featured_image_id);
            if ($attachment && strpos($attachment->post_mime_type, 'image/') === 0) {
                $file_path = get_attached_file($featured_image_id);
                if ($file_path) {
                    $attachment_data = wp_generate_attachment_metadata($featured_image_id, $file_path);
                    wp_update_attachment_metadata($featured_image_id, $attachment_data);
                    fospibay_log_error('Metadatos de imagen destacada generados para ID ' . $featured_image_id);
                }
                $result = set_post_thumbnail($post_id_from_csv, $featured_image_id);
                if ($result) {
                    fospibay_log_error('Imagen destacada asignada correctamente a entrada ID ' . $post_id_from_csv . ': ID ' . $featured_image_id);
                    $updated++;
                } else {
                    fospibay_log_error('Error al asignar imagen destacada a entrada ID ' . $post_id_from_csv);
                    $skipped++;
                }
            } else {
                fospibay_log_error('Error: El ID ' . $featured_image_id . ' no corresponde a una imagen válida para entrada ID ' . $post_id_from_csv);
                $skipped++;
            }
        } else {
            fospibay_log_error('Error al procesar imagen destacada para entrada ID ' . $post_id_from_csv . ': ' . ($featured_image_id ? $featured_image_id->get_error_message() : 'URL inválida o fallo en la descarga'));
            $skipped++;
        }

        $row_index++;
    }

    fclose($file_handle);
    echo '<div class="updated"><p>Importación de imágenes destacadas completada. Entradas actualizadas: ' . $updated . ', omitidas: ' . $skipped . '</p></div>';
    fospibay_log_error('Importación de imágenes destacadas completada. Entradas actualizadas: ' . $updated . ', omitidas: ' . $skipped);
}

// Process a batch of CSV rows (main import)
add_action('fospibay_process_batch', 'fospibay_process_batch');
function fospibay_process_batch() {
    $state = get_option('fospibay_import_state', false);
    if (!$state || !file_exists($state['file'])) {
        fospibay_log_error('Estado de importación inválido o archivo no encontrado.');
        delete_option('fospibay_import_state');
        return;
    }

    $file_path = $state['file'];
    $row_index = $state['row_index'];
    $imported = $state['imported'];
    $updated = $state['updated'];
    $skipped = $state['skipped'];
    $batch_size = $state['batch_size'];
    $skip_existing = $state['skip_existing'];
    $delimiter = $state['delimiter'];
    $offset = $state['offset'];
    $batch_number = floor($row_index / $batch_size) + 1;
    $start_time = microtime(true);

    fospibay_log_error('Iniciando procesamiento de lote ' . $batch_number . ' desde offset ' . $offset . ', tamaño de lote: ' . $batch_size);

    if (!file_exists($file_path) || !is_readable($file_path)) {
        fospibay_log_error('No se pudo leer el archivo CSV: ' . $file_path);
        delete_option('fospibay_import_state');
        return;
    }

    $file_handle = fopen($file_path, 'r');
    if ($offset === 0) {
        $raw_headers = fgetcsv($file_handle, 0, $delimiter, '"', '\\');
        if ($raw_headers && isset($raw_headers[0])) {
            $raw_headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $raw_headers[0]);
        }
        fospibay_log_error('Encabezados crudos del CSV: ' . print_r($raw_headers, true));
        if (empty($raw_headers) || count($raw_headers) < 2) {
            fospibay_log_error('No se encontraron encabezados válidos en el CSV.');
            fclose($file_handle);
            delete_option('fospibay_import_state');
            return;
        }

        $header_map = [];
        $used_headers = [];
        foreach ($raw_headers as $index => $header) {
            $original_header = trim($header);
            $new_header = $original_header;
            $suffix = 1;
            while (isset($used_headers[$new_header])) {
                $new_header = $original_header . '_' . $suffix;
                $suffix++;
            }
            $header_map[$new_header] = $index;
            $used_headers[$new_header] = true;
        }
        $headers = array_keys($header_map);
        fospibay_log_error('Encabezados del CSV procesados: ' . implode(', ', $headers) . ' (Total: ' . count($headers) . ')');

        $required_headers = ['Title', 'Content'];
        foreach ($required_headers as $req_header) {
            if (!in_array($req_header, $headers)) {
                fospibay_log_error('Falta el encabezado requerido "' . $req_header . '" en el CSV.');
                fclose($file_handle);
                delete_option('fospibay_import_state');
                return;
            }
        }

        $featured_image_header = null;
        $possible_image_headers = ['Featured', 'URL', 'url', 'Featured Image', 'featured_image', 'image_url', 'Image URL', 'URL_2'];
        foreach ($possible_image_headers as $header) {
            if (in_array($header, $headers)) {
                $featured_image_header = $header;
                fospibay_log_error('Encabezado de imagen destacada encontrado: ' . $header . ' (índice ' . $header_map[$header] . ')');
                break;
            }
        }

        $elementor_data_header = null;
        $possible_elementor_headers = ['_elementor_data', 'elementor_data', 'Elementor Data'];
        foreach ($possible_elementor_headers as $header) {
            if (in_array($header, $headers)) {
                $elementor_data_header = $header;
                fospibay_log_error('Encabezado de datos Elementor encontrado: ' . $header);
                break;
            }
        }

        $categories_header = null;
        $possible_categories_headers = ['Categorías', 'Categories', 'category'];
        foreach ($possible_categories_headers as $header) {
            if (in_array($header, $headers)) {
                $categories_header = $header;
                fospibay_log_error('Encabezado de categorías encontrado: ' . $header);
                break;
            }
        }

        $state['headers'] = $headers;
        $state['header_map'] = $header_map;
        $state['featured_image_header'] = $featured_image_header;
        $state['elementor_data_header'] = $elementor_data_header;
        $state['categories_header'] = $categories_header;
        update_option('fospibay_import_state', $state);
    } else {
        fseek($file_handle, $offset);
        $headers = $state['headers'];
        $header_map = $state['header_map'];
        $featured_image_header = $state['featured_image_header'];
        $elementor_data_header = $state['elementor_data_header'];
        $categories_header = $state['categories_header'];
    }

    $batch = [];
    $processed = 0;
    while ($processed < $batch_size && ($row = fgetcsv($file_handle, 0, $delimiter, '"', '\\')) !== false) {
        if (microtime(true) - $start_time > (ini_get('max_execution_time') * 0.8)) {
            fospibay_log_error('Tiempo de ejecución cercano al límite, deteniendo lote ' . $batch_number);
            break;
        }

        fospibay_log_error('Datos crudos de la fila ' . $row_index . ': ' . print_r($row, true));
        if (empty(array_filter($row, function($value) { return trim($value) !== ''; }))) {
            fospibay_log_error('Fila ' . $row_index . ' está vacía o inválida, omitiendo.');
            $skipped++;
            $row_index++;
            continue;
        }

        if (count($row) !== count($headers)) {
            fospibay_log_error('Advertencia en fila ' . $row_index . ': Número de columnas (' . count($row) . ') no coincide con encabezados (' . count($headers) . '). Ajustando fila.');
            $row = array_slice($row, 0, count($headers));
            $row = array_pad($row, count($headers), '');
            fospibay_log_error('Fila ajustada ' . $row_index . ': ' . print_r($row, true));
        }

        $data = array_combine($headers, $row);
        if ($data === false) {
            fospibay_log_error('Error al combinar fila ' . $row_index . ' con encabezados. Raw data: ' . print_r($row, true));
            $skipped++;
            $row_index++;
            continue;
        }
        fospibay_log_error('Datos de la fila ' . $row_index . ': ' . wp_json_encode($data));

        $title = isset($data['Title']) ? trim($data['Title']) : '';
        $content = isset($data['Content']) ? trim($data['Content']) : '';
        $title_empty = empty($title);
        $content_empty = empty($content);
        if ($title_empty || $content_empty) {
            $reason = $title_empty && $content_empty ? 'Título y contenido vacíos' : ($title_empty ? 'Título vacío' : 'Contenido vacío');
            fospibay_log_error('Fila ' . $row_index . ' omitida: ' . $reason . '.');
            $skipped++;
            $row_index++;
            continue;
        }

        $post_title = ucfirst(strtolower(sanitize_text_field(wp_check_invalid_utf8($title, true))));
        fospibay_log_error('Título procesado para la fila ' . $row_index . ': ' . $post_title);
        $post_id_from_csv = !empty($data['ID']) ? absint($data['ID']) : 0;
        fospibay_log_error('ID proporcionado en CSV para la fila ' . $row_index . ': ' . $post_id_from_csv);

        $existing_post_id = 0;
        if ($post_id_from_csv) {
            $existing_post = get_post($post_id_from_csv);
            if ($existing_post && $existing_post->post_type === ($data['Post Type'] ?? 'post')) {
                $existing_post_id = $post_id_from_csv;
                fospibay_log_error('Entrada existente encontrada por ID: ' . $existing_post_id . ' en fila ' . $row_index);
            }
        }
        if (!$existing_post_id) {
            $existing_post = get_page_by_title($post_title, OBJECT, $data['Post Type'] ?? 'post');
            if ($existing_post) {
                $existing_post_id = $existing_post->ID;
                fospibay_log_error('Entrada existente encontrada por título: ' . $existing_post_id . ' en fila ' . $row_index);
            }
        }

        if ($existing_post_id && $skip_existing) {
            fospibay_log_error('Entrada existente encontrada (ID: ' . $existing_post_id . ') en fila ' . $row_index . ', omitiendo por configuración.');
            $skipped++;
            $row_index++;
            continue;
        }

        $post_date = !empty($data['Date']) ? $data['Date'] : current_time('mysql');
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $post_date)) {
            fospibay_log_error('Fecha inválida en fila ' . $row_index . ': ' . $post_date . '. Usando fecha actual: ' . current_time('mysql'));
            $post_date = current_time('mysql');
        }

        $post_data = [
            'post_title' => $post_title,
            'post_content' => $content,
            'post_type' => !empty($data['Post Type']) ? $data['Post Type'] : 'post',
            'post_status' => !empty($data['Status']) ? $data['Status'] : 'publish',
            'post_date' => $post_date,
        ];
        if ($existing_post_id) {
            $post_data['ID'] = $existing_post_id;
        }

        $post_id = wp_insert_post($post_data, true);
        if (is_wp_error($post_id)) {
            fospibay_log_error('Error al crear/actualizar entrada en fila ' . $row_index . ': ' . $post_id->get_error_message());
            $skipped++;
            $row_index++;
            continue;
        }

        if ($existing_post_id) {
            $updated++;
        } else {
            $imported++;
        }

        if ($categories_header && !empty($data[$categories_header])) {
            $categories = array_filter(array_map('trim', explode('|', $data[$categories_header])));
            $category_ids = [];
            foreach ($categories as $category_name) {
                $category = get_term_by('name', $category_name, 'category');
                if ($category && !is_wp_error($category)) {
                    $category_ids[] = $category->term_id;
                } else {
                    $new_category = wp_insert_term($category_name, 'category');
                    if (!is_wp_error($new_category)) {
                        $category_ids[] = $new_category['term_id'];
                    }
                }
            }
            if (!empty($category_ids)) {
                wp_set_post_terms($post_id, $category_ids, 'category', false);
            }
        }

        fospibay_clean_and_import_images($post_id, $data, $featured_image_header, $elementor_data_header);
        $row_index++;
        $processed++;
        $batch[] = $row;

        update_option('fospibay_import_state', array_merge($state, [
            'row_index' => $row_index,
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'offset' => ftell($file_handle)
        ]));
    }

    fospibay_log_error('Lote ' . $batch_number . ' procesado. Filas procesadas: ' . count($batch) . ', Creadas: ' . $imported . ', Actualizadas: ' . $updated . ', Omitidas: ' . $skipped);
    wp_cache_flush();

    if (!feof($file_handle)) {
        wp_schedule_single_event(time(), 'fospibay_process_batch');
    } else {
        fospibay_log_error('Importación completada. Entradas creadas: ' . $imported . ', actualizadas: ' . $updated . ', omitidas: ' . $skipped);
        unlink($file_path);
        delete_option('fospibay_import_state');
    }

    fclose($file_handle);
}

// Clean content and import images
function fospibay_clean_and_import_images($post_id, $data, $featured_image_header, $elementor_data_header) {
    $content = get_post_field('post_content', $post_id);
    $content = preg_replace('/<!-- wp:[a-z\/]+ -->/', '', $content);
    $content = preg_replace('/<!-- \/wp:[a-z\/]+ -->/', '', $content);
    $content = preg_replace_callback(
        '/<img\s+[^>]*src="https:\/\/static\.xx\.fbcdn\.net\/images\/emoji\.php\/[^"]+"[^>]*alt="([^"]+)"[^>]*>/i',
        function ($matches) {
            return $matches[1];
        },
        $content
    );
    wp_update_post(['ID' => $post_id, 'post_content' => $content], true);

    if ($featured_image_header && !empty($data[$featured_image_header])) {
        $image_url = trim($data[$featured_image_header]);
        if (filter_var($image_url, FILTER_VALIDATE_URL)) {
            $existing_image_id = fospibay_check_existing_image($image_url);
            $featured_image_id = $existing_image_id ?: fospibay_download_and_attach_image($image_url, $post_id);
            if ($featured_image_id && !is_wp_error($featured_image_id)) {
                $attachment = get_post($featured_image_id);
                if ($attachment && strpos($attachment->post_mime_type, 'image/') === 0) {
                    $file_path = get_attached_file($featured_image_id);
                    if ($file_path) {
                        $attachment_data = wp_generate_attachment_metadata($featured_image_id, $file_path);
                        wp_update_attachment_metadata($featured_image_id, $attachment_data);
                    }
                    set_post_thumbnail($post_id, $featured_image_id);
                }
            }
        }
    }

    if ($elementor_data_header && !empty($data[$elementor_data_header])) {
        $elementor_data = json_decode($data[$elementor_data_header], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($elementor_data)) {
            $all_gallery_image_ids = [];
            foreach ($elementor_data as $section) {
                if (!isset($section['elements'])) continue;
                foreach ($section['elements'] as $column) {
                    if (!isset($column['elements'])) continue;
                    foreach ($column['elements'] as $widget) {
                        if (isset($widget['widgetType']) && $widget['widgetType'] === 'gallery' && !empty($widget['settings']['gallery'])) {
                            foreach ($widget['settings']['gallery'] as $image) {
                                if (!isset($image['url'])) continue;
                                $existing_image_id = fospibay_check_existing_image($image['url']);
                                $image_id = $existing_image_id ?: fospibay_download_and_attach_image($image['url'], $post_id);
                                if ($image_id && !is_wp_error($image_id)) {
                                    $all_gallery_image_ids[] = $image_id;
                                }
                            }
                        }
                    }
                }
            }
            if (!empty($all_gallery_image_ids) && function_exists('update_field')) {
                update_field('field_686ea8c997852', $all_gallery_image_ids, $post_id);
            }
        }
    }
}

// Download and attach an image to the media library
function fospibay_download_and_attach_image($image_url, $post_id) {
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    fospibay_log_error('Iniciando descarga de imagen para entrada ID ' . $post_id . ': ' . $image_url);
    if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
        fospibay_log_error('URL de imagen inválida: ' . $image_url . ' para entrada ID ' . $post_id);
        return new WP_Error('invalid_url', 'URL de imagen inválida: ' . $image_url);
    }

    $existing_image_id = fospibay_check_existing_image($image_url);
    if ($existing_image_id) {
        fospibay_log_error('Imagen existente encontrada, omitiendo descarga para entrada ID ' . $post_id . ': ID ' . $existing_image_id);
        return $existing_image_id;
    }

    $image_data = wp_safe_remote_get($image_url, ['timeout' => 60]);
    if (is_wp_error($image_data)) {
        fospibay_log_error('Error al descargar imagen: ' . $image_url . ' - ' . $image_data->get_error_message());
        return $image_data;
    }

    $image_content = wp_remote_retrieve_body($image_data);
    if (empty($image_content)) {
        fospibay_log_error('Error: Contenido de imagen vacío para: ' . $image_url);
        return new WP_Error('empty_content', 'Contenido de imagen vacío para: ' . $image_url);
    }
    fospibay_log_error('Imagen descargada correctamente, tamaño: ' . strlen($image_content) . ' bytes para: ' . $image_url);

    $filename = basename($image_url);
    if (empty($filename) || !preg_match('/\.(jpg|jpeg|png|gif)$/i', $filename)) {
        $filename = 'image-' . $post_id . '-' . time() . '.jpg';
        fospibay_log_error('Nombre de archivo generado para imagen sin nombre: ' . $filename);
    }

    $upload_dir = wp_upload_dir();
    if (!is_writable($upload_dir['path'])) {
        fospibay_log_error('Error: El directorio de subidas no es escribible: ' . $upload_dir['path']);
        return new WP_Error('upload_dir_not_writable', 'El directorio de subidas no es escribible: ' . $upload_dir['path']);
    }
    fospibay_log_error('Directorio de subidas verificado: ' . $upload_dir['path']);

    $upload = wp_upload_bits($filename, null, $image_content);
    if ($upload['error']) {
        fospibay_log_error('Error al subir imagen: ' . $image_url . ' - ' . $upload['error']);
        return new WP_Error('upload_failed', 'Error al subir imagen: ' . $upload['error']);
    }
    fospibay_log_error('Imagen subida al servidor: ' . $upload['file']);

    $file_path = $upload['file'];
    $file_type = wp_check_filetype($filename, null);
    fospibay_log_error('Tipo de archivo detectado: ' . ($file_type['type'] ? $file_type['type'] : 'image/jpeg'));

    $attachment = [
        'post_mime_type' => $file_type['type'] ? $file_type['type'] : 'image/jpeg',
        'post_title' => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
        'post_content' => '',
        'post_status' => 'inherit',
        'guid' => $image_url,
    ];
    fospibay_log_error('Datos de adjunto preparados: ' . wp_json_encode($attachment));

    $attachment_id = wp_insert_attachment($attachment, $file_path, $post_id);
    if (is_wp_error($attachment_id)) {
        fospibay_log_error('Error al crear adjunto para imagen: ' . $image_url . ' - ' . $attachment_id->get_error_message());
        return $attachment_id;
    }
    fospibay_log_error('Adjunto creado con ID: ' . $attachment_id);

    $attachment_data = wp_generate_attachment_metadata($attachment_id, $file_path);
    wp_update_attachment_metadata($attachment_id, $attachment_data);
    fospibay_log_error('Metadatos de adjunto generados: ' . wp_json_encode($attachment_data));

    fospibay_log_error('Imagen subida correctamente: ' . $image_url . ' - ID: ' . $attachment_id);
    return $attachment_id;
}

// Add custom field to Bricks dynamic data
add_filter('bricks/dynamic_data/post_fields', 'fospibay_add_custom_fields_to_bricks', 10, 1);
function fospibay_add_custom_fields_to_bricks($fields) {
    $fields['field_686ea8c997852'] = 'Acf Gallery Images';
    return $fields;
}
?>
