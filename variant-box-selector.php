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
