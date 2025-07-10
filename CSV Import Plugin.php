<?php
/**
 * Plugin Name: Fospibay CSV Import Plugin
 * Description: Imports posts, categories, and images from a CSV in batches, cleans content, downloads images, sets featured image, saves galleries to ACF field, handles duplicates, and avoids re-uploading existing images using filename.
 * Version: 2.4
 * Author: Grok
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Add admin menu for CSV upload
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
}

// Admin page for uploading CSV
function fospibay_csv_import_page() {
    ?>
    <div class="wrap">
        <h1>Fospibay CSV Import</h1>
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
            fospibay_process_csv($_FILES['csv_file']['tmp_name'], $skip_existing, $batch_size, $delimiter);
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

// Process the uploaded CSV in batches
function fospibay_process_csv($file_path, $skip_existing = false, $batch_size = 50, $delimiter = ',') {
    if (!file_exists($file_path) || !is_readable($file_path)) {
        echo '<div class="error"><p>Error: No se pudo leer el archivo CSV.</p></div>';
        fospibay_log_error('No se pudo leer el archivo CSV: ' . $file_path);
        return;
    }

    fospibay_log_error('Archivo CSV subido: ' . $file_path . ', Existe: ' . (file_exists($file_path) ? 'Sí' : 'No') . ', Legible: ' . (is_readable($file_path) ? 'Sí' : 'No') . ', Delimitador: ' . $delimiter);

    // Read first few lines for debugging
    $file_handle = fopen($file_path, 'r');
    $preview_lines = [];
    for ($i = 0; $i < 3 && !feof($file_handle); $i++) {
        $line = fgets($file_handle);
        $preview_lines[] = trim($line);
    }
    fclose($file_handle);
    fospibay_log_error('Primeras líneas del CSV para depuración: ' . implode('\n', $preview_lines));

    $file = new SplFileObject($file_path);
    $file->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
    $file->setCsvControl($delimiter, '"', '\\');

    $headers = $file->fgetcsv();
    if ($headers && isset($headers[0])) {
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]); // Remove BOM
    }
    if (empty($headers) || count($headers) < 2) {
        echo '<div class="error"><p>Error: No se encontraron encabezados válidos en el CSV.</p></div>';
        fospibay_log_error('No se encontraron encabezados válidos en el CSV o encabezados insuficientes: ' . print_r($headers, true));
        return;
    }
    fospibay_log_error('Encabezados del CSV: ' . implode(', ', $headers));

    // Map headers to indices
    $header_map = array_flip($headers);
    $required_headers = ['Title', 'Content'];
    foreach ($required_headers as $req_header) {
        if (!isset($header_map[$req_header])) {
            echo '<div class="error"><p>Error: Falta el encabezado requerido "' . $req_header . '" en el CSV.</p></div>';
            fospibay_log_error('Falta el encabezado requerido "' . $req_header . '" en el CSV.');
            return;
        }
    }
    fospibay_log_error('Encabezados requeridos encontrados: ' . implode(', ', $required_headers));

    $featured_image_header = null;
    $possible_image_headers = ['URL', 'url', 'Featured Image', 'featured_image', 'image_url', 'Image URL'];
    foreach ($possible_image_headers as $header) {
        if (isset($header_map[$header])) {
            $featured_image_header = $header;
            fospibay_log_error('Encabezado de imagen destacada encontrado: ' . $header . ' (índice ' . $header_map[$header] . ')');
            break;
        }
    }
    if (!$featured_image_header) {
        fospibay_log_error('No se encontró encabezado de imagen destacada.');
    }

    $elementor_data_header = null;
    $possible_elementor_headers = ['_elementor_data', 'elementor_data', 'Elementor Data'];
    foreach ($possible_elementor_headers as $header) {
        if (isset($header_map[$header])) {
            $elementor_data_header = $header;
            fospibay_log_error('Encabezado de datos Elementor encontrado: ' . $header);
            break;
        }
    }

    $categories_header = null;
    $possible_categories_headers = ['Categorías', 'Categories', 'category'];
    foreach ($possible_categories_headers as $header) {
        if (isset($header_map[$header])) {
            $categories_header = $header;
            fospibay_log_error('Encabezado de categorías encontrado: ' . $header);
            break;
        }
    }

    $imported = 0;
    $updated = 0;
    $skipped = 0;
    $row_index = 2;
    $batch = [];
    $batch_number = 1;
    while (!$file->eof()) {
        $row = $file->fgetcsv();
        if ($row === false || $row === null || (is_array($row) && empty(array_filter($row, function($value) { return trim($value) !== ''; })))) {
            fospibay_log_error('Fila ' . $row_index . ' está vacía o inválida, omitiendo. Raw data: ' . print_r($row, true));
            $skipped++;
            $row_index++;
            continue;
        }

        // Pad row to match headers and combine
        $row = array_pad($row, count($headers), '');
        $data = array_combine($headers, $row);
        if ($data === false) {
            fospibay_log_error('Error al combinar fila ' . $row_index . ' con encabezados. Raw data: ' . print_r($row, true));
            $skipped++;
            $row_index++;
            continue;
        }
        fospibay_log_error('Datos de la fila ' . $row_index . ': ' . wp_json_encode($data));

        // Validate Title and Content
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
            } else {
                fospibay_log_error('No se encontró entrada existente para ID: ' . $post_id_from_csv . ' en fila ' . $row_index);
            }
        }
        if (!$existing_post_id) {
            $existing_post = get_page_by_title($post_title, OBJECT, $data['Post Type'] ?? 'post');
            if ($existing_post) {
                $existing_post_id = $existing_post->ID;
                fospibay_log_error('Entrada existente encontrada por título: ' . $existing_post_id . ' en fila ' . $row_index);
            } else {
                fospibay_log_error('No se encontró entrada existente para título: ' . $post_title . ' en fila ' . $row_index);
            }
        }

        if ($existing_post_id) {
            if ($skip_existing) {
                fospibay_log_error('Entrada existente encontrada (ID: ' . $existing_post_id . ') en fila ' . $row_index . ', omitiendo por configuración.');
                $skipped++;
                $row_index++;
                continue;
            } else {
                fospibay_log_error('Entrada existente encontrada (ID: ' . $existing_post_id . ') en fila ' . $row_index . ', actualizando.');
            }
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
        fospibay_log_error('Datos preparados para wp_insert_post en fila ' . $row_index . ': ' . wp_json_encode($post_data));

        if ($existing_post_id) {
            $post_data['ID'] = $existing_post_id;
        }

        fospibay_log_error('Antes de wp_insert_post para fila ' . $row_index . ': Título = ' . $post_data['post_title']);
        $post_id = wp_insert_post($post_data, true);
        if (is_wp_error($post_id)) {
            echo '<div class="error"><p>Error al crear/actualizar la entrada (fila ' . $row_index . '): ' . esc_html($post_id->get_error_message()) . '</p></div>';
            fospibay_log_error('Error al crear/actualizar entrada en fila ' . $row_index . ': ' . $post_id->get_error_message());
            $row_index++;
            continue;
        }

        $saved_post = get_post($post_id);
        fospibay_log_error('Después de wp_insert_post para fila ' . $row_index . ': Título guardado = ' . $saved_post->post_title . ', ID = ' . $post_id);

        if ($existing_post_id) {
            fospibay_log_error('Entrada actualizada con ID: ' . $post_id);
            $updated++;
        } else {
            fospibay_log_error('Entrada creada con ID: ' . $post_id);
            $imported++;
        }

        if ($categories_header && !empty($data[$categories_header])) {
            fospibay_log_error('Procesando categorías para entrada ID ' . $post_id . ': ' . $data[$categories_header]);
            $categories = array_filter(array_map('trim', explode('|', $data[$categories_header])));
            $category_ids = [];
            foreach ($categories as $category_name) {
                fospibay_log_error('Buscando categoría: ' . $category_name);
                $category = get_term_by('name', $category_name, 'category');
                if ($category && !is_wp_error($category)) {
                    $category_ids[] = $category->term_id;
                    fospibay_log_error('Categoría existente encontrada: ' . $category_name . ' (ID: ' . $category->term_id . ')');
                } else {
                    fospibay_log_error('Categoría no encontrada, creando: ' . $category_name);
                    $new_category = wp_insert_term($category_name, 'category');
                    if (!is_wp_error($new_category)) {
                        $category_ids[] = $new_category['term_id'];
                        fospibay_log_error('Categoría creada: ' . $category_name . ' (ID: ' . $new_category['term_id'] . ')');
                    } else {
                        fospibay_log_error('Error al crear categoría: ' . $category_name . ' - ' . $new_category->get_error_message());
                    }
                }
            }
            if (!empty($category_ids)) {
                fospibay_log_error('Asignando categorías a entrada ID ' . $post_id . ': ' . implode(', ', $category_ids));
                $result = wp_set_post_terms($post_id, $category_ids, 'category', false);
                if (is_wp_error($result)) {
                    fospibay_log_error('Error al asignar categorías a la entrada ID ' . $post_id . ': ' . $result->get_error_message());
                } else {
                    fospibay_log_error('Categorías asignadas a la entrada ID ' . $post_id . ': ' . implode(', ', $categories));
                }
            } else {
                fospibay_log_error('No se asignaron categorías a la entrada ID ' . $post_id . ': ninguna categoría válida encontrada.');
            }
        } else {
            fospibay_log_error('No se encontró columna de categorías o está vacía para la entrada ID ' . $post_id);
        }

        fospibay_clean_and_import_images($post_id, $data, $featured_image_header, $elementor_data_header);
        $row_index++;

        $batch[] = $row;
        if (count($batch) >= $batch_size || $file->eof()) {
            fospibay_log_error('Lote ' . $batch_number . ' procesado. Filas procesadas: ' . count($batch) . ', Creadas: ' . $imported . ', Actualizadas: ' . $updated . ', Omitidas: ' . $skipped);
            $batch = [];
            $batch_number++;
            wp_cache_flush();
        }
    }

    echo '<div class="updated"><p>Importación completada. Entradas creadas: ' . $imported . ', actualizadas: ' . $updated . ', omitidas: ' . $skipped . '</p></div>';
    fospibay_log_error('Importación completada. Entradas creadas: ' . $imported . ', actualizadas: ' . $updated . ', omitidas: ' . $skipped);
}

// Clean content and import images
function fospibay_clean_and_import_images($post_id, $data, $featured_image_header, $elementor_data_header) {
    $content = get_post_field('post_content', $post_id);
    fospibay_log_error('Contenido original para entrada ID ' . $post_id . ': ' . substr($content, 0, 100) . (strlen($content) > 100 ? '...' : ''));
    
    $content = preg_replace('/<!-- wp:[a-z\/]+ -->/', '', $content);
    $content = preg_replace('/<!-- \/wp:[a-z\/]+ -->/', '', $content);
    
    $content = preg_replace_callback(
        '/<img\s+[^>]*src="https:\/\/static\.xx\.fbcdn\.net\/images\/emoji\.php\/[^"]+"[^>]*alt="([^"]+)"[^>]*>/i',
        function ($matches) use ($post_id) {
            $emoji = $matches[1];
            fospibay_log_error('Reemplazando emoji imagen por: ' . $emoji . ' en entrada ID ' . $post_id);
            return $emoji;
        },
        $content
    );

    fospibay_log_error('Contenido limpio para entrada ID ' . $post_id . ': ' . substr($content, 0, 100) . (strlen($content) > 100 ? '...' : ''));
    $update_result = wp_update_post([
        'ID' => $post_id,
        'post_content' => $content
    ], true);
    if (is_wp_error($update_result)) {
        fospibay_log_error('Error al actualizar contenido de la entrada ID ' . $post_id . ': ' . $update_result->get_error_message());
    } else {
        fospibay_log_error('Contenido limpio actualizado para entrada ID: ' . $post_id);
    }

    if ($featured_image_header && !empty($data[$featured_image_header])) {
        $image_url = $data[$featured_image_header];
        fospibay_log_error('Procesando imagen destacada para entrada ID ' . $post_id . ': ' . $image_url);

        $response = wp_safe_remote_head($image_url, ['timeout' => 60]);
        if (is_wp_error($response)) {
            fospibay_log_error('Error al verificar accesibilidad de imagen destacada: ' . $image_url . ' - ' . $response->get_error_message());
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            fospibay_log_error('Respuesta HTTP para imagen destacada: ' . $image_url . ' - Código: ' . $response_code);
            if ($response_code !== 200) {
                fospibay_log_error('Error: La URL de la imagen destacada no es accesible: ' . $image_url . ' - Código HTTP: ' . $response_code);
            } else {
                $existing_image_id = fospibay_check_existing_image($image_url);
                if ($existing_image_id) {
                    $featured_image_id = $existing_image_id;
                    fospibay_log_error('Reutilizando imagen destacada existente para entrada ID ' . $post_id . ': ID ' . $featured_image_id);
                } else {
                    $featured_image_id = fospibay_download_and_attach_image($image_url, $post_id);
                    if ($featured_image_id && !is_wp_error($featured_image_id)) {
                        fospibay_log_error('Imagen destacada subida para entrada ID ' . $post_id . ': ID ' . $featured_image_id);
                    } else {
                        fospibay_log_error('Error al procesar imagen destacada para entrada ID ' . $post_id . ': ' . ($featured_image_id ? $featured_image_id->get_error_message() : 'URL inválida o fallo en la descarga'));
                    }
                }
                if ($featured_image_id && !is_wp_error($featured_image_id)) {
                    $attachment = get_post($featured_image_id);
                    if ($attachment && strpos($attachment->post_mime_type, 'image/') === 0) {
                        fospibay_log_error('Verificando tipo MIME de imagen destacada ID ' . $featured_image_id . ': ' . $attachment->post_mime_type);
                        $result = set_post_thumbnail($post_id, $featured_image_id);
                        if ($result) {
                            fospibay_log_error('Imagen destacada asignada correctamente a entrada ID ' . $post_id . ': ID ' . $featured_image_id);
                        } else {
                            fospibay_log_error('Error al asignar imagen destacada a entrada ID ' . $post_id . ': Fallo en set_post_thumbnail');
                        }
                    } else {
                        fospibay_log_error('Error: El ID ' . $featured_image_id . ' no corresponde a una imagen válida para entrada ID ' . $post_id);
                    }
                }
            }
        }
    } else {
        fospibay_log_error('No se encontró URL de imagen destacada para entrada ID ' . $post_id . ' (encabezado: ' . ($featured_image_header ?: 'ninguno') . ')');
    }

    if ($elementor_data_header && !empty($data[$elementor_data_header])) {
        fospibay_log_error('Procesando datos Elementor para entrada ID ' . $post_id . ': ' . substr($data[$elementor_data_header], 0, 100) . (strlen($data[$elementor_data_header]) > 100 ? '...' : ''));
        $elementor_data = json_decode($data[$elementor_data_header], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($elementor_data)) {
            fospibay_log_error('Datos Elementor decodificados correctamente para entrada ID ' . $post_id);
            $all_gallery_image_ids = [];

            foreach ($elementor_data as $section_index => $section) {
                if (!isset($section['elements']) || !is_array($section['elements'])) {
                    fospibay_log_error('Sección ' . $section_index . ' no tiene elementos válidos para entrada ID ' . $post_id);
                    continue;
                }
                foreach ($section['elements'] as $column_index => $column) {
                    if (!isset($column['elements']) || !is_array($column['elements'])) {
                        fospibay_log_error('Columna ' . $column_index . ' en sección ' . $section_index . ' no tiene elementos válidos para entrada ID ' . $post_id);
                        continue;
                    }
                    foreach ($column['elements'] as $widget_index => $widget) {
                        if (isset($widget['widgetType']) && $widget['widgetType'] === 'gallery' && !empty($widget['settings']['gallery'])) {
                            fospibay_log_error('Galería encontrada en widget ' . $widget_index . ' para entrada ID ' . $post_id . ', procesando ' . count($widget['settings']['gallery']) . ' imágenes');
                            foreach ($widget['settings']['gallery'] as $image_index => $image) {
                                if (!isset($image['url'])) {
                                    fospibay_log_error('Imagen ' . $image_index . ' en galería sin URL para entrada ID ' . $post_id);
                                    continue;
                                }
                                fospibay_log_error('Procesando imagen de galería: ' . $image['url'] . ' para entrada ID ' . $post_id);
                                $existing_image_id = fospibay_check_existing_image($image['url']);
                                if ($existing_image_id) {
                                    $image_id = $existing_image_id;
                                    fospibay_log_error('Reutilizando imagen de galería existente: ' . $image['url'] . ' - ID: ' . $image_id);
                                } else {
                                    $image_id = fospibay_download_and_attach_image($image['url'], $post_id);
                                    if ($image_id && !is_wp_error($image_id)) {
                                        fospibay_log_error('Imagen de galería subida con ID: ' . $image_id . ' para entrada ID ' . $post_id);
                                    } else {
                                        fospibay_log_error('Error al procesar imagen de galería: ' . $image['url'] . ' - ' . ($image_id ? $image_id->get_error_message() : 'URL inválida o fallo en la descarga'));
                                    }
                                }
                                if ($image_id && !is_wp_error($image_id)) {
                                    $all_gallery_image_ids[] = $image_id;
                                    fospibay_log_error('Imagen de galería ID ' . $image_id . ' añadida a la lista para entrada ID ' . $post_id);
                                }
                            }
                        }
                    }
                }
            }

            if (!empty($all_gallery_image_ids)) {
                if (function_exists('update_field')) {
                    fospibay_log_error('Guardando ' . count($all_gallery_image_ids) . ' imágenes de galería en ACF para entrada ID ' . $post_id);
                    update_field('field_686ea8c997852', $all_gallery_image_ids, $post_id);
                    fospibay_log_error('Imágenes de galería guardadas en ACF field_686ea8c997852 para entrada ID ' . $post_id . ': ' . implode(', ', $all_gallery_image_ids));
                } else {
                    fospibay_log_error('Error: La función update_field de ACF no está disponible para entrada ID ' . $post_id);
                }
            } else {
                fospibay_log_error('No se encontraron imágenes de galería válidas para entrada ID ' . $post_id);
            }
        } else {
            fospibay_log_error('Error al decodificar datos Elementor para entrada ID ' . $post_id . ': ' . json_last_error_msg());
        }
    } else {
        fospibay_log_error('No se encontró ' . ($elementor_data_header ?: 'ningún encabezado de datos Elementor') . ' para entrada ID ' . $post_id);
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
    fospibay_log_error('Metadatos de adjunto generados: ' . wp_json_encode($attachment_data));
    wp_update_attachment_metadata($attachment_id, $attachment_data);

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
