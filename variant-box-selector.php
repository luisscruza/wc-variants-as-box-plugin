<?php
/*
Plugin Name: Variant Box Selector
Description: Display WooCommerce variation attributes as selectable boxes.
Version: 1.0
*/

// Create database table on plugin activation
register_activation_hook(__FILE__, 'variant_box_create_table');

function variant_box_create_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'variant_notify_requests';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        email varchar(255) NOT NULL,
        product_id bigint(20) NOT NULL,
        variation_id bigint(20) DEFAULT NULL,
        variant_name varchar(255) DEFAULT NULL,
        date_requested datetime DEFAULT CURRENT_TIMESTAMP,
        notified tinyint(1) DEFAULT 0,
        PRIMARY KEY  (id),
        KEY email (email),
        KEY product_id (product_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('variant-box-selector-style', plugin_dir_url(__FILE__) . 'style.css');
    wp_enqueue_script('variant-box-selector-script', plugin_dir_url(__FILE__) . 'script.js', ['jquery'], null, true);

    // Localize script with AJAX data
    wp_localize_script('variant-box-selector-script', 'variantBoxData', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('variant_box_notify_nonce')
    ]);
}, 99);

add_filter('woocommerce_dropdown_variation_attribute_options_html', function ($html, $args) {
    if (!is_product()) return $html;

    $options   = $args['options'] ?? [];
    $attribute = $args['attribute'] ?? '';
    $product   = $args['product'] ?? null;

    if (empty($options) || !$product instanceof WC_Product || !$attribute) {
        return $html;
    }

    $select_name = 'attribute_' . sanitize_title($attribute);
    $boxes = '<div class="variation-boxes">';

    $available_variations = $product->get_available_variations();

    foreach ($options as $option) {
        $label = wc_attribute_label($option, $product);
        $is_in_stock = false;

        // Loop through variations to check if this option is in stock
        foreach ($available_variations as $variation) {
            $attributes = $variation['attributes'] ?? [];
            if (
                isset($attributes[$select_name]) &&
                $attributes[$select_name] === $option &&
                $variation['is_in_stock']
            ) {
                $is_in_stock = true;
                break;
            }
        }

        $class = 'variation-box';
        if (!$is_in_stock) {
            $class .= ' out-of-stock';
        }

        $boxes .= sprintf(
            '<div class="%s" data-attribute="%s" id="variantboxid" data-value="%s">%s</div>',
            esc_attr($class),
            esc_attr($select_name),
            esc_attr($option),
            esc_html($label)
        );
    }

    $boxes .= '</div>';

    return $boxes . $html;
}, 20, 2);

// AJAX handler for notify me requests
add_action('wp_ajax_variant_box_notify_me', 'variant_box_handle_notify_request');
add_action('wp_ajax_nopriv_variant_box_notify_me', 'variant_box_handle_notify_request');

function variant_box_handle_notify_request()
{
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'variant_box_notify_nonce')) {
        wp_send_json_error('Verificación de seguridad fallida');
        return;
    }

    // Validate email
    $email = sanitize_email($_POST['email'] ?? '');
    if (!is_email($email)) {
        wp_send_json_error('Correo electrónico inválido');
        return;
    }

    // Get and validate data
    $product_id = absint($_POST['product_id'] ?? 0);
    $variation_id = absint($_POST['variation_id'] ?? 0);
    $variant_name = sanitize_text_field($_POST['variant_name'] ?? '');

    if (!$product_id) {
        wp_send_json_error('Producto inválido');
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'variant_notify_requests';

    // Check if already registered
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table_name WHERE email = %s AND product_id = %d AND (variation_id = %d OR variation_id IS NULL) AND notified = 0",
        $email,
        $product_id,
        $variation_id
    ));

    if ($existing) {
        wp_send_json_success('Ya estás registrado para recibir notificaciones');
        return;
    }

    // Insert into database
    $inserted = $wpdb->insert(
        $table_name,
        [
            'email' => $email,
            'product_id' => $product_id,
            'variation_id' => $variation_id ?: null,
            'variant_name' => $variant_name,
            'date_requested' => current_time('mysql')
        ],
        ['%s', '%d', '%d', '%s', '%s']
    );

    if (!$inserted) {
        wp_send_json_error('Error al guardar la solicitud');
        return;
    }

    // Send email to admin
    $admin_email = get_option('admin_email');
    $product = wc_get_product($product_id);
    $product_name = $product ? $product->get_name() : "Product #$product_id";

    $subject = "Nuevo pedido de notificación de reposición de stock";
    $message = "Un cliente ha solicitado ser notificado cuando un producto vuelva a estar en stock:\n\n";
    $message .= "Correo electrónico del cliente: $email\n";
    $message .= "Producto: $product_name\n";
    if ($variant_name) {
        $message .= "Variante: $variant_name\n";
    }
    $message .= "ID del producto: $product_id\n";
    if ($variation_id) {
        $message .= "ID de la variante: $variation_id\n";
    }
    $message .= "\nVer todas las solicitudes en el panel de administración de WordPress:  Solicitudes de reposición de stock\n";

    wp_mail($admin_email, $subject, $message);

    wp_send_json_success('Registro exitoso');
}

// Add admin menu
add_action('admin_menu', 'variant_box_add_admin_menu');

function variant_box_add_admin_menu()
{
    add_menu_page(
        'Notificaciones de Stock',
        'Notificaciones de Stock',
        'manage_options',
        'variant-box-notifications',
        'variant_box_admin_page',
        'dashicons-email-alt',
        58
    );
}

// Admin page content
function variant_box_admin_page()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'variant_notify_requests';

    // Handle delete action
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id']) && check_admin_referer('delete_notify_' . $_GET['id'])) {
        $wpdb->delete($table_name, ['id' => absint($_GET['id'])], ['%d']);
        echo '<div class="notice notice-success"><p>Solicitud eliminada exitosamente.</p></div>';
    }

    // Handle mark as notified action
    if (isset($_GET['action']) && $_GET['action'] === 'mark_notified' && isset($_GET['id']) && check_admin_referer('notify_' . $_GET['id'])) {
        $wpdb->update($table_name, ['notified' => 1], ['id' => absint($_GET['id'])], ['%d'], ['%d']);
        echo '<div class="notice notice-success"><p>Marcado como notificado.</p></div>';
    }

    // Handle export
    if (isset($_GET['action']) && $_GET['action'] === 'export' && check_admin_referer('export_notify_requests')) {
        $requests = $wpdb->get_results("SELECT * FROM $table_name ORDER BY date_requested DESC", ARRAY_A);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="notify-requests-' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        if (!empty($requests)) {
            fputcsv($output, array_keys($requests[0]));
            foreach ($requests as $request) {
                fputcsv($output, $request);
            }
        }
        fclose($output);
        exit;
    }

    // Get filter status
    $filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
    $where = '';
    if ($filter === 'pending') {
        $where = ' WHERE notified = 0';
    } elseif ($filter === 'notified') {
        $where = ' WHERE notified = 1';
    }

    // Get requests
    $requests = $wpdb->get_results("SELECT * FROM $table_name $where ORDER BY date_requested DESC");
    $total = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $pending = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE notified = 0");
    $notified_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE notified = 1");

?>
    <div class="wrap">
        <h1>Solicitudes de Notificación de Stock</h1>

        <div style="margin: 20px 0;">
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=variant-box-notifications&action=export'), 'export_notify_requests'); ?>" class="button">Exportar a CSV</a>
        </div>

        <ul class="subsubsub">
            <li><a href="?page=variant-box-notifications&filter=all" <?php echo $filter === 'all' ? 'class="current"' : ''; ?>>Todas (<?php echo $total; ?>)</a> | </li>
            <li><a href="?page=variant-box-notifications&filter=pending" <?php echo $filter === 'pending' ? 'class="current"' : ''; ?>>Pendientes (<?php echo $pending; ?>)</a> | </li>
            <li><a href="?page=variant-box-notifications&filter=notified" <?php echo $filter === 'notified' ? 'class="current"' : ''; ?>>Notificadas (<?php echo $notified_count; ?>)</a></li>
        </ul>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Correo</th>
                    <th>Producto</th>
                    <th>Variante</th>
                    <th>Fecha de Solicitud</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requests)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 20px;">No se encontraron solicitudes de notificación.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($requests as $request):
                        $product = wc_get_product($request->product_id);
                        $product_name = $product ? $product->get_name() : "Producto #" . $request->product_id;
                    ?>
                        <tr>
                            <td><?php echo esc_html($request->id); ?></td>
                            <td><?php echo esc_html($request->email); ?></td>
                            <td>
                                <?php echo esc_html($product_name); ?>
                                <?php if ($product): ?>
                                    <br><a href="<?php echo get_edit_post_link($request->product_id); ?>" target="_blank">Editar Producto</a>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($request->variant_name ?: '-'); ?></td>
                            <td><?php echo esc_html(date('Y-m-d H:i', strtotime($request->date_requested))); ?></td>
                            <td>
                                <?php if ($request->notified): ?>
                                    <span style="color: green;">✓ Notificado</span>
                                <?php else: ?>
                                    <span style="color: orange;">⏳ Pendiente</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$request->notified): ?>
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=variant-box-notifications&action=mark_notified&id=' . $request->id), 'notify_' . $request->id); ?>" class="button button-small">Marcar como Notificado</a>
                                <?php endif; ?>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=variant-box-notifications&action=delete&id=' . $request->id), 'delete_notify_' . $request->id); ?>" class="button button-small" onclick="return confirm('¿Estás seguro de que quieres eliminar esta solicitud?');">Eliminar</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div style="margin-top: 20px; padding: 15px; background: #fff; border-left: 4px solid #0073aa;">
            <h3>Cómo usar esta función:</h3>
            <ol>
                <li>Los clientes verán un formulario "Notificarme cuando vuelva a estar disponible" cuando seleccionen una variante agotada.</li>
                <li>Cuando envíen su correo electrónico, recibirás un email de notificación y la solicitud aparecerá aquí.</li>
                <li>Cuando el producto/variante vuelva a estar en stock, envíales manualmente un correo y marca la solicitud como "Notificado".</li>
                <li>Puedes exportar todas las solicitudes a CSV para usarlas con herramientas de email marketing.</li>
            </ol>
        </div>
    </div>
<?php
}
