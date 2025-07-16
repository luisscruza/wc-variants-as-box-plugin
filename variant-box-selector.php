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
        'nonce' => wp_create_nonce('variant_box_nonce')
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
    
    wp_send_json_success('Notification request saved successfully');
}
