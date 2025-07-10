<?php
/**
 * Plugin Name: FOSPIBAY CSV Import Plugin
 * Description: Imports posts, categories, and images from a CSV, cleans content, downloads images, sets featured image, saves galleries to ACF field, handles duplicates, and avoids re-uploading existing images.
 * Version: 2.0
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
        'FOSPIBAY CSV Import',
        'FOSPIBAY Import',
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
        <h1>FOSPIBAY CSV Import</h1>
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
            <?php wp_nonce_field('fospibay_csv_import', 'fospibay_csv_nonce'); ?>
            <p>
                <input type="submit" class="button button-primary" value="Importar CSV">
            </p>
        </form>
        <?php
        if (isset($_POST['fospibay_csv_nonce']) && wp_verify_nonce($_POST['fospibay_csv_nonce'], 'fospibay_csv_import') && !empty($_FILES['csv_file']['tmp_name'])) {
            $skip_existing = isset($_POST['skip_existing']) && $_POST['skip_existing'] == '1';
            fospibay_process_csv($_FILES['csv_file']['tmp_name'], $skip_existing);
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

// Check if an image already exists by URL
function fospibay_check_existing_image($image_url) {
    global $wpdb;
    $attachment = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND guid = %s",
            $image_url
        )
    );
    if ($attachment) {
        fospibay_log_error('Imagen existente encontrada para URL: ' . $image_url . ' - ID: ' . $attachment->ID);
        return $attachment->ID;
    }
    fospibay_log_error('No se encontró imagen existente para URL: ' . $image_url);
    return false;
}

// Process the uploaded CSV
function fospibay_process_csv($file_path, $skip_existing = false) {
    if (!file_exists($file_path) || !is_readable($file_path)) {
        echo '<div class="error"><p>Error: No se pudo leer el archivo CSV.</p></div>';
        fospibay_log_error('No se pudo leer el archivo CSV: ' . $file_path);
        return;
    }

    // Use SplFileObject for robust CSV parsing
    $file = new SplFileObject($file_path);
    $file->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
    $file->setCsvControl(',', '"', '\\');

    // Read headers
    $headers = $file->fgetcsv();
    if (empty($headers)) {
        echo '<div class="error"><p>Error: No se encontraron encabezados en el CSV.</p></div>';
        fospibay_log_error('No se encontraron encabezados en el CSV.');
        return;
    }
    fospibay_log_error('Encabezados del CSV: ' . implode(', ', $headers));

    // Validate required headers
    if (!isset($headers[1]) || $headers[1] !== 'Title' || !isset($headers[2]) || $headers[2] !== 'Content') {
        echo '<div class="error"><p>Error: El CSV debe contener los encabezados "Title" (columna 2) y "Content" (columna 3).</p></div>';
        fospibay_log_error('Encabezados requeridos "Title" (columna 2) y "Content" (columna 3) no encontrados.');
        return;
    }

    // Find header for featured image
    $featured_image_header = null;
    $possible_image_headers = ['URL', 'url', 'Featured Image', 'featured_image', 'image_url', 'Image URL'];
    $url_headers = array_keys(array_filter($headers, function($header) use ($possible_image_headers) {
        return in_array($header, $possible_image_headers);
    }));
    if (!empty($url_headers)) {
        $featured_image_header = $headers[$url_headers[0]]; // Use the first URL header
        fospibay_log_error('Encabezado de imagen destacada encontrado: ' . $featured_image_header . ' (índice ' . $url_headers[0] . ')');
    } else {
        fospibay_log_error('No se encontró encabezado de imagen destacada.');
    }

    // Find header for Elementor data
    $elementor_data_header = null;
    $possible_elementor_headers = ['_elementor_data', 'elementor_data', 'Elementor Data'];
    foreach ($possible_elementor_headers as $header) {
        if (in_array($header, $headers)) {
            $elementor_data_header = $header;
            fospibay_log_error('Encabezado de datos Elementor encontrado: ' . $header);
            break;
        }
    }

    // Find header for categories
    $categories_header = null;
    $possible_categories_headers = ['Categorías', 'Categories', 'category'];
    foreach ($possible_categories_headers as $header) {
        if (in_array($header, $headers)) {
            $categories_header = $header;
            fospibay_log_error('Encabezado de categorías encontrado: ' . $header);
            break;
        }
    }

    // Process rows
    $imported = 0;
    $updated = 0;
    $skipped = 0;
    $row_index = 2; // Start after headers
    while (!$file->eof()) {
        $row = $file->fgetcsv();
        if ($row === false || empty(array_filter($row))) {
            fospibay_log_error('Fila ' . $row_index . ' está vacía o inválida, omitiendo.');
            $row_index++;
            continue;
        }

        // Ensure row has enough columns
        $row = array_pad($row, count($headers), '');
        $data = array_combine($headers, $row);
        fospibay_log_error('Datos de la fila ' . $row_index . ': ' . wp_json_encode($data));

        // Get title from index 1 (first Title column) explicitly
        $post_title = !empty($row[1]) ? sanitize_text_field(wp_check_invalid_utf8($row[1], true)) : 'Entrada sin título';
        fospibay_log_error('Título procesado para la fila ' . $row_index . ' (índice 1): ' . $post_title);
        $post_id_from_csv = !empty($data['ID']) ? absint($data['ID']) : 0;

        // Check if post exists by ID or title
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

        // Handle existing posts
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

        // Validate and format date
        $post_date = !empty($data['Date']) ? $data['Date'] : current_time('mysql');
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $post_date)) {
            fospibay_log_error('Fecha inválida en fila ' . $row_index . ': ' . $post_date . '. Usando fecha actual.');
            $post_date = current_time('mysql');
        }

        // Prepare post data
        $post_data = [
            'post_title' => $post_title,
            'post_content' => $data['Content'] ?? '',
            'post_type' => !empty($data['Post Type']) ? $data['Post Type'] : 'post',
            'post_status' => !empty($data['Status']) ? $data['Status'] : 'publish',
            'post_date' => $post_date,
        ];

        // Set ID for update if post exists
        if ($existing_post_id) {
            $post_data['ID'] = $existing_post_id;
        }

        // Create or update post
        fospibay_log_error('Antes de wp_insert_post para fila ' . $row_index . ': post_title = ' . $post_data['post_title']);
        $post_id = wp_insert_post($post_data, true);
        if (is_wp_error($post_id)) {
            echo '<div class="error"><p>Error al crear/actualizar la entrada (fila ' . $row_index . '): ' . esc_html($post_id->get_error_message()) . '</p></div>';
            fospibay_log_error('Error al crear/actualizar entrada en fila ' . $row_index . ': ' . $post_id->get_error_message());
            $row_index++;
            continue;
        }

        // Verify post title after creation/update
        $saved_post = get_post($post_id);
        fospibay_log_error('Después de wp_insert_post para fila ' . $row_index . ': post_title guardado = ' . $saved_post->post_title);

        // Log creation or update
        if ($existing_post_id) {
            fospibay_log_error('Entrada actualizada con ID: ' . $post_id);
            $updated++;
        } else {
            fospibay_log_error('Entrada creada con ID: ' . $post_id);
            $imported++;
        }

        // Handle categories
        if ($categories_header && !empty($data[$categories_header])) {
            $categories = array_filter(array_map('trim', explode('|', $data[$categories_header])));
            $category_ids = [];
            foreach ($categories as $category_name) {
                $category = get_term_by('name', $category_name, 'category');
                if ($category && !is_wp_error($category)) {
                    $category_ids[] = $category->term_id;
                    fospibay_log_error('Categoría existente encontrada: ' . $category_name . ' (ID: ' . $category->term_id . ')');
                } else {
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

        // Clean content and process images
        fospibay_clean_and_import_images($post_id, $data, $featured_image_header, $elementor_data_header);
        $row_index++;
    }

    echo '<div class="updated"><p>Importación completada. Entradas creadas: ' . $imported . ', actualizadas: ' . $updated . ', omitidas: ' . $skipped . '</p></div>';
    fospibay_log_error('Importación completada. Entradas creadas: ' . $imported . ', actualizadas: ' . $updated . ', omitidas: ' . $skipped);
}

// Clean content and import images
function fospibay_clean_and_import_images($post_id, $data, $featured_image_header, $elementor_data_header) {
    // Clean post content
    $content = get_post_field('post_content', $post_id);
    
    // Remove WordPress block tags
    $content = preg_replace('/<!-- wp:[a-z\/]+ -->/', '', $content);
    $content = preg_replace('/<!-- \/wp:[a-z\/]+ -->/', '', $content);
    
    // Replace emoji images with Unicode based on alt attribute
    $content = preg_replace_callback(
        '/<img\s+[^>]*src="https:\/\/static\.xx\.fbcdn\.net\/images\/emoji\.php\/[^"]+"[^>]*alt="([^"]+)"[^>]*>/i',
        function ($matches) use ($post_id) {
            $emoji = $matches[1];
            fospibay_log_error('Reemplazando emoji imagen por: ' . $emoji . ' en entrada ID ' . $post_id);
            return $emoji;
        },
        $content
    );

    // Update cleaned content
    $update_result = wp_update_post([
        'ID' => $post_id,
        'post_content' => $content
    ], true);
    if (is_wp_error($update_result)) {
        fospibay_log_error('Error al actualizar contenido de la entrada ID ' . $post_id . ': ' . $update_result->get_error_message());
    } else {
        fospibay_log_error('Contenido limpio actualizado para entrada ID: ' . $post_id);
    }

    // Handle featured image
    if ($featured_image_header && !empty($data[$featured_image_header])) {
        $image_url = $data[$featured_image_header];
        fospibay_log_error('Procesando imagen destacada para entrada ID ' . $post_id . ': ' . $image_url);

        // Check if URL is accessible
        $response = wp_safe_remote_head($image_url, ['timeout' => 60]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            fospibay_log_error('Error: La URL de la imagen destacada no es accesible: ' . $image_url . ' - ' . (is_wp_error($response) ? $response->get_error_message() : 'Código HTTP: ' . wp_remote_retrieve_response_code($response)));
        } else {
            // Check for existing image
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
                // Verify attachment exists and is an image
                $attachment = get_post($featured_image_id);
                if ($attachment && strpos($attachment->post_mime_type, 'image/') === 0) {
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
    } else {
        fospibay_log_error('No se encontró URL de imagen destacada para entrada ID ' . $post_id . ' (encabezado: ' . ($featured_image_header ?: 'ninguno') . ')');
    }

    // Handle gallery images from Elementor data
    if ($elementor_data_header && !empty($data[$elementor_data_header])) {
        fospibay_log_error('Procesando ' . $elementor_data_header . ' para entrada ID ' . $post_id);
        $elementor_data = json_decode($data[$elementor_data_header], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($elementor_data)) {
            $all_gallery_image_ids = [];

            // Extract all gallery images
            foreach ($elementor_data as $section) {
                if (!isset($section['elements']) || !is_array($section['elements'])) continue;
                foreach ($section['elements'] as $column) {
                    if (!isset($column['elements']) || !is_array($column['elements'])) continue;
                    foreach ($column['elements'] as $widget) {
                        if (isset($widget['widgetType']) && $widget['widgetType'] === 'gallery' && !empty($widget['settings']['gallery'])) {
                            foreach ($widget['settings']['gallery'] as $image) {
                                if (!isset($image['url'])) continue;
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
                                }
                            }
                        }
                    }
                }
            }

            // Save gallery images to ACF field
            if (!empty($all_gallery_image_ids)) {
                if (function_exists('update_field')) {
                    update_field('field_686ea8c997852', $all_gallery_image_ids, $post_id);
                    fospibay_log_error('Imágenes de galería guardadas en ACF field_686ea8c997852 para entrada ID ' . $post_id . ': ' . implode(', ', $all_gallery_image_ids));
                } else {
                    fospibay_log_error('Error: La función update_field de ACF no está disponible para entrada ID ' . $post_id);
                }
            } else {
                fospibay_log_error('No se encontraron imágenes de galería válidas para entrada ID ' . $post_id);
            }
        } else {
            fospibay_log_error('Error al decodificar ' . $elementor_data_header . ' para entrada ID ' . $post_id . ': ' . json_last_error_msg());
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

    // Validate URL
    if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
        fospibay_log_error('URL de imagen inválida: ' . $image_url . ' para entrada ID ' . $post_id);
        return new WP_Error('invalid_url', 'URL de imagen inválida: ' . $image_url);
    }

    // Download the image
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

    $filename = basename($image_url);
    if (empty($filename) || !preg_match('/\.(jpg|jpeg|png|gif)$/i', $filename)) {
        $filename = 'image-' . $post_id . '-' . time() . '.jpg';
        fospibay_log_error('Nombre de archivo generado para imagen sin nombre: ' . $filename);
    }

    // Check write permissions
    $upload_dir = wp_upload_dir();
    if (!is_writable($upload_dir['path'])) {
        fospibay_log_error('Error: El directorio de subidas no es escribible: ' . $upload_dir['path']);
        return new WP_Error('upload_dir_not_writable', 'El directorio de subidas no es escribible: ' . $upload_dir['path']);
    }

    // Upload image
    $upload = wp_upload_bits($filename, null, $image_content);
    if ($upload['error']) {
        fospibay_log_error('Error al subir imagen: ' . $image_url . ' - ' . $upload['error']);
        return new WP_Error('upload_failed', 'Error al subir imagen: ' . $upload['error']);
    }

    $file_path = $upload['file'];
    $file_type = wp_check_filetype($filename, null);

    $attachment = [
        'post_mime_type' => $file_type['type'] ? $file_type['type'] : 'image/jpeg',
        'post_title' => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
        'post_content' => '',
        'post_status' => 'inherit',
        'guid' => $image_url, // Set the original URL as the guid
    ];

    $attachment_id = wp_insert_attachment($attachment, $file_path, $post_id);
    if (is_wp_error($attachment_id)) {
        fospibay_log_error('Error al crear adjunto para imagen: ' . $image_url . ' - ' . $attachment_id->get_error_message());
        return $attachment_id;
    }

    $attachment_data = wp_generate_attachment_metadata($attachment_id, $file_path);
    wp_update_attachment_metadata($attachment_id, $attachment_data);

    fospibay_log_error('Imagen subida correctamente: ' . $image_url . ' - ID: ' . $attachment_id);
    return $attachment_id;
}

// Add custom field to Bricks dynamic data
add_filter('bricks/dynamic_data/post_fields', 'fospibay_add_custom_fields_to_bricks', 10, 1);
function fospibay_add_custom_fields_to_bricks($fields) {
    $fields['field_686ea8c997852'] = 'ACF Gallery Images';
    return $fields;
}
?>

haz que se haga por lotes, y que los titulos se cambien a Mayus solo la primera letra, porque en el original todo está en mayus, entonces quiero estandarizar esto
