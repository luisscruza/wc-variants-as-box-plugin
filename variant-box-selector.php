<?php
/*
Plugin Name: Variant Box Selector
Description: Display WooCommerce variation attributes as selectable boxes.
Version: 1.0
*/

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('variant-box-selector-style', plugin_dir_url(__FILE__) . 'style.css');
    wp_enqueue_script('variant-box-selector-script', plugin_dir_url(__FILE__) . 'script.js', ['jquery'], null, true);
    
    // Localize script for AJAX
    wp_localize_script('variant-box-selector-script', 'variant_box_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('variant_box_nonce'),
        'notifications_enabled' => get_option('variant_box_enable_notifications', 1)
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
            '<div class="%s" data-attribute="%s" data-value="%s">%s</div>',
            esc_attr($class),
            esc_attr($select_name),
            esc_attr($option),
            esc_html($label)
        );
    }

    $boxes .= '</div>';

    return $boxes . $html;
}, 20, 2);

// Create database table for stock notifications on plugin activation
register_activation_hook(__FILE__, 'variant_box_create_notifications_table');

function variant_box_create_notifications_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'variant_stock_notifications';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        email varchar(100) NOT NULL,
        product_id bigint(20) NOT NULL,
        variant_attribute varchar(100) NOT NULL,
        variant_value varchar(100) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        notified tinyint(1) DEFAULT 0,
        PRIMARY KEY (id),
        KEY email (email),
        KEY product_variant (product_id, variant_attribute, variant_value)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// AJAX handler for saving stock notifications
add_action('wp_ajax_save_stock_notification', 'handle_stock_notification');
add_action('wp_ajax_nopriv_save_stock_notification', 'handle_stock_notification');

function handle_stock_notification() {
    // Verify nonce for security
    if (!wp_verify_nonce($_POST['nonce'], 'variant_box_nonce')) {
        wp_die('Security check failed');
    }
    
    $email = sanitize_email($_POST['notification_email']);
    $product_id = intval($_POST['product_id']);
    $variant_attribute = sanitize_text_field($_POST['variant_attribute']);
    $variant_value = sanitize_text_field($_POST['variant_value']);
    
    // Validate input
    if (!is_email($email)) {
        wp_send_json_error('Please enter a valid email address');
        return;
    }
    
    if (!$product_id || !$variant_attribute || !$variant_value) {
        wp_send_json_error('Missing required information');
        return;
    }
    
    // Check if email already exists for this product variant
    global $wpdb;
    $table_name = $wpdb->prefix . 'variant_stock_notifications';
    
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM $table_name WHERE email = %s AND product_id = %d AND variant_attribute = %s AND variant_value = %s",
        $email, $product_id, $variant_attribute, $variant_value
    ));
    
    if ($existing) {
        wp_send_json_error('You are already subscribed for notifications for this variant');
        return;
    }
    
    // Insert new notification request
    $result = $wpdb->insert(
        $table_name,
        [
            'email' => $email,
            'product_id' => $product_id,
            'variant_attribute' => $variant_attribute,
            'variant_value' => $variant_value
        ],
        ['%s', '%d', '%s', '%s']
    );
    
    if ($result === false) {
        wp_send_json_error('Failed to save notification request');
        return;
    }
    
    // Send admin notification email if enabled
    if (get_option('variant_box_enable_notifications', 1)) {
        variant_box_send_admin_notification($email, $product_id, $variant_attribute, $variant_value);
    }
    
    wp_send_json_success('Notification request saved successfully');
}

// Function to send admin notification email
function variant_box_send_admin_notification($customer_email, $product_id, $variant_attribute, $variant_value) {
    $admin_email = get_option('variant_box_notification_email', get_option('admin_email'));
    $product = wc_get_product($product_id);
    $product_name = $product ? $product->get_name() : 'Unknown Product';
    
    // Format variant info
    $attribute_label = str_replace('attribute_pa_', '', $variant_attribute);
    $attribute_label = str_replace('attribute_', '', $attribute_label);
    $attribute_label = ucwords(str_replace('_', ' ', $attribute_label));
    
    $subject = 'New Stock Notification Request - ' . $product_name;
    
    $message = "Hello,\n\n";
    $message .= "A customer has requested to be notified when a product variant comes back in stock.\n\n";
    $message .= "Details:\n";
    $message .= "Customer Email: " . $customer_email . "\n";
    $message .= "Product: " . $product_name . "\n";
    $message .= "Variant: " . $attribute_label . " - " . $variant_value . "\n";
    $message .= "Request Date: " . current_time('mysql') . "\n\n";
    
    if ($product) {
        $message .= "Product Link: " . get_edit_post_link($product_id) . "\n";
        $message .= "View Product: " . get_permalink($product_id) . "\n\n";
    }
    
    $message .= "You can manage all stock notification requests in your WordPress admin:\n";
    $message .= admin_url('admin.php?page=variant-stock-notifications') . "\n\n";
    
    $message .= "When you restock this item, don't forget to notify the customer!\n\n";
    $message .= "Best regards,\n";
    $message .= "Your " . get_bloginfo('name') . " Website";
    
    $headers = array('Content-Type: text/plain; charset=UTF-8');
    
    wp_mail($admin_email, $subject, $message, $headers);
}

// Enqueue admin styles
add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'variant-stock-notifications') !== false || strpos($hook, 'variant-notifications-settings') !== false) {
        wp_enqueue_style('variant-box-admin-style', plugin_dir_url(__FILE__) . 'admin-style.css');
    }
});

// Add admin menu for stock notifications
add_action('admin_menu', 'variant_box_add_admin_menu');

function variant_box_add_admin_menu() {
    add_menu_page(
        'Stock Notifications',
        'Stock Notifications',
        'manage_options',
        'variant-stock-notifications',
        'variant_box_notifications_page',
        'dashicons-email-alt',
        30
    );
    
    add_submenu_page(
        'variant-stock-notifications',
        'Notifications List',
        'Notifications List',
        'manage_options',
        'variant-stock-notifications',
        'variant_box_notifications_page'
    );
    
    add_submenu_page(
        'variant-stock-notifications',
        'Settings',
        'Settings',
        'manage_options',
        'variant-notifications-settings',
        'variant_box_settings_page'
    );
}

// Admin page for notifications list
function variant_box_notifications_page() {
    global $wpdb;
    
    // Handle bulk actions
    if (isset($_POST['action']) && check_admin_referer('variant_notifications_bulk_action')) {
        $action = $_POST['action'];
        $notification_ids = isset($_POST['notification_ids']) ? $_POST['notification_ids'] : [];
        
        if (!empty($notification_ids) && ($action === 'mark_notified' || $action === 'delete')) {
            $table_name = $wpdb->prefix . 'variant_stock_notifications';
            $ids_placeholder = implode(',', array_fill(0, count($notification_ids), '%d'));
            
            if ($action === 'mark_notified') {
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table_name SET notified = 1 WHERE id IN ($ids_placeholder)",
                    ...$notification_ids
                ));
                echo '<div class="notice notice-success"><p>Selected notifications marked as sent.</p></div>';
            } elseif ($action === 'delete') {
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM $table_name WHERE id IN ($ids_placeholder)",
                    ...$notification_ids
                ));
                echo '<div class="notice notice-success"><p>Selected notifications deleted.</p></div>';
            }
        }
    }
    
    // Get notifications from database with pagination
    $table_name = $wpdb->prefix . 'variant_stock_notifications';
    $per_page = 20;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;
    
    // Get total count
    $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $total_pages = ceil($total_items / $per_page);
    
    // Get notifications for current page
    $notifications = $wpdb->get_results($wpdb->prepare("
        SELECT n.*, p.post_title as product_name 
        FROM $table_name n 
        LEFT JOIN {$wpdb->posts} p ON n.product_id = p.ID 
        ORDER BY n.created_at DESC
        LIMIT %d OFFSET %d
    ", $per_page, $offset));
    
    ?>
    <div class="wrap variant-notifications-admin">
        <h1>Stock Notifications 
            <span class="title-count">(<?php echo $total_items; ?>)</span>
        </h1>
        
        <?php if (empty($notifications)): ?>
            <div class="card">
                <h2>No Notifications Yet</h2>
                <p>When customers request stock notifications for out-of-stock variants, they will appear here.</p>
                <p>Make sure your product variants are properly configured and marked as out of stock to enable the notification feature.</p>
            </div>
        <?php else: ?>
            <form method="post">
                <?php wp_nonce_field('variant_notifications_bulk_action'); ?>
                
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <label for="bulk-action-selector-top" class="screen-reader-text">Select bulk action</label>
                        <select name="action" id="bulk-action-selector-top">
                            <option value="-1">Bulk Actions</option>
                            <option value="mark_notified">Mark as Notified</option>
                            <option value="delete">Delete</option>
                        </select>
                        <input type="submit" class="button action" value="Apply">
                    </div>
                    
                    <?php if ($total_pages > 1): ?>
                    <div class="alignright">
                        <?php
                        echo paginate_links([
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page,
                            'type' => 'plain'
                        ]);
                        ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column">
                                <input type="checkbox" id="cb-select-all-1">
                            </td>
                            <th class="manage-column">Email</th>
                            <th class="manage-column">Product</th>
                            <th class="manage-column">Variant</th>
                            <th class="manage-column">Date Requested</th>
                            <th class="manage-column">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($notifications as $notification): ?>
                            <tr>
                                <th scope="row" class="check-column">
                                    <input type="checkbox" name="notification_ids[]" value="<?php echo esc_attr($notification->id); ?>">
                                </th>
                                <td><strong><?php echo esc_html($notification->email); ?></strong></td>
                                <td>
                                    <?php if ($notification->product_name): ?>
                                        <a href="<?php echo get_edit_post_link($notification->product_id); ?>" target="_blank">
                                            <?php echo esc_html($notification->product_name); ?>
                                        </a>
                                    <?php else: ?>
                                        <em>Product not found (ID: <?php echo $notification->product_id; ?>)</em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $attribute_label = str_replace('attribute_pa_', '', $notification->variant_attribute);
                                    $attribute_label = str_replace('attribute_', '', $attribute_label);
                                    echo '<strong>' . esc_html(ucwords(str_replace('_', ' ', $attribute_label))) . ':</strong> ';
                                    echo esc_html($notification->variant_value);
                                    ?>
                                </td>
                                <td><?php echo esc_html(mysql2date('F j, Y g:i a', $notification->created_at)); ?></td>
                                <td>
                                    <?php if ($notification->notified): ?>
                                        <span class="dashicons dashicons-yes-alt status-notified"></span>
                                        <span class="status-notified">Notified</span>
                                    <?php else: ?>
                                        <span class="dashicons dashicons-clock status-pending"></span>
                                        <span class="status-pending">Pending</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="alignright">
                        <?php
                        echo paginate_links([
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page,
                            'type' => 'plain'
                        ]);
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
    
    <script>
    // Handle select all checkbox
    document.addEventListener('DOMContentLoaded', function() {
        const selectAll = document.getElementById('cb-select-all-1');
        if (selectAll) {
            selectAll.addEventListener('change', function() {
                const checkboxes = document.querySelectorAll('input[name="notification_ids[]"]');
                checkboxes.forEach(checkbox => checkbox.checked = this.checked);
            });
        }
    });
    </script>
    <?php
}

// Admin page for settings
function variant_box_settings_page() {
    // Handle form submission
    if (isset($_POST['submit']) && check_admin_referer('variant_notifications_settings')) {
        $notification_email = sanitize_email($_POST['notification_email']);
        $enable_notifications = isset($_POST['enable_notifications']) ? 1 : 0;
        
        if (is_email($notification_email)) {
            update_option('variant_box_notification_email', $notification_email);
            update_option('variant_box_enable_notifications', $enable_notifications);
            echo '<div class="notice notice-success"><p>Settings saved successfully.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Please enter a valid email address.</p></div>';
        }
    }
    
    $current_email = get_option('variant_box_notification_email', get_option('admin_email'));
    $notifications_enabled = get_option('variant_box_enable_notifications', 1);
    
    // Get some stats
    global $wpdb;
    $table_name = $wpdb->prefix . 'variant_stock_notifications';
    $total_notifications = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $pending_notifications = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE notified = 0");
    
    ?>
    <div class="wrap variant-notifications-admin">
        <h1>Stock Notification Settings</h1>
        
        <!-- Stats Dashboard -->
        <div style="display: flex; gap: 20px; margin: 20px 0;">
            <div class="card" style="min-width: 200px;">
                <h3 style="margin-top: 0;">Total Requests</h3>
                <p style="font-size: 24px; font-weight: bold; color: #0073aa;"><?php echo $total_notifications; ?></p>
            </div>
            <div class="card" style="min-width: 200px;">
                <h3 style="margin-top: 0;">Pending</h3>
                <p style="font-size: 24px; font-weight: bold; color: #f56e28;"><?php echo $pending_notifications; ?></p>
            </div>
        </div>
        
        <form method="post" action="">
            <?php wp_nonce_field('variant_notifications_settings'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="enable_notifications">Enable Notifications</label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   id="enable_notifications" 
                                   name="enable_notifications" 
                                   value="1"
                                   <?php checked($notifications_enabled, 1); ?>>
                            Enable email notification collection for out-of-stock variants
                        </label>
                        <p class="description">
                            When enabled, customers can request email notifications for out-of-stock product variants.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="notification_email">Admin Notification Email</label>
                    </th>
                    <td>
                        <input type="email" 
                               id="notification_email" 
                               name="notification_email" 
                               value="<?php echo esc_attr($current_email); ?>" 
                               class="regular-text" 
                               required>
                        <p class="description">
                            This email address will receive notifications about new stock alert requests. 
                            You can manually notify customers when items are back in stock.
                        </p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(); ?>
        </form>
        
        <div class="card">
            <h2>How the Stock Notification System Works</h2>
            <ol>
                <li><strong>Customer Request:</strong> When customers click on out-of-stock product variants, they can enter their email to be notified when the item comes back in stock.</li>
                <li><strong>Admin Notification:</strong> You'll receive an email notification at the address above whenever a customer requests a stock alert.</li>
                <li><strong>Management:</strong> Use the <a href="<?php echo admin_url('admin.php?page=variant-stock-notifications'); ?>">Notifications List</a> to view all pending requests.</li>
                <li><strong>Customer Notification:</strong> When you restock items, manually notify customers or mark notifications as sent in the admin panel.</li>
            </ol>
            
            <h3>Managing Notifications</h3>
            <p>In the notifications list, you can:</p>
            <ul>
                <li><strong>View all requests:</strong> See customer emails, products, and variants requested</li>
                <li><strong>Mark as notified:</strong> When you've contacted customers about restocked items</li>
                <li><strong>Delete old requests:</strong> Clean up outdated or invalid notification requests</li>
                <li><strong>Bulk actions:</strong> Manage multiple notifications at once</li>
            </ul>
        </div>
        
        <?php if ($pending_notifications > 0): ?>
        <div class="card" style="border-left: 4px solid #f56e28;">
            <h3>Action Required</h3>
            <p>You have <strong><?php echo $pending_notifications; ?></strong> pending stock notification request(s). 
            <a href="<?php echo admin_url('admin.php?page=variant-stock-notifications'); ?>" class="button button-primary">
                View Pending Notifications
            </a></p>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
