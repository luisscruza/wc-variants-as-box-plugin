<?php
/*
Plugin Name: Variant Box Selector
Description: Display WooCommerce variation attributes as selectable boxes.
Version: 1.0
*/

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('variant-box-selector-style', plugin_dir_url(__FILE__) . 'style.css');
    wp_enqueue_script('variant-box-selector-script', plugin_dir_url(__FILE__) . 'script.js', ['jquery'], null, true);
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
    foreach ($options as $option) {
        $label = wc_attribute_label($option, $product);
        $boxes .= sprintf(
            '<div class="variation-box" data-attribute="%s" data-value="%s">%s</div>',
            esc_attr($select_name),
            esc_attr($option),
            esc_html($label)
        );
    }
    $boxes .= '</div>';

    return $boxes . $html;
}, 20, 2);

