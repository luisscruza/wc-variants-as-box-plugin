jQuery(document).ready(function ($) {
    console.log('[Variant Box] Plugin loaded');
    console.log('[Variant Box] Found variation boxes:', $('.variation-box').length);
    
    if ($('.variation-box').length === 1) {
        $('.variations').hide();
    }
    // Delegate click to document to ensure it catches dynamically loaded elements
    $(document).on('click', '.variation-box', function () {
        console.log('[Variant Box] Box clicked!');
        console.log('[Variant Box] Has out-of-stock class:', $(this).hasClass('out-of-stock'));
        console.log('[Variant Box] Box classes:', $(this).attr('class'));
        console.log('[Variant Box] Box data:', $(this).data());
        
        if ($(this).hasClass('out-of-stock')) {
            console.log('[Variant Box] OUT OF STOCK BOX CLICKED!');
            const form = $(this).closest('form.variations_form');
            console.log('[Variant Box] Form found:', form.length);
            
            form.find('.variation-box').removeClass('selected');
            $(this).addClass('selected');
            form.find('select').val('').trigger('change');

            // Show out of stock message
            let message = form.find('.out-of-stock-message');
            console.log('[Variant Box] Existing message found:', message.length);
            
            if (!message.length) {
                console.log('[Variant Box] Creating new message');
                message = $('<div class="out-of-stock-message">Out of stock</div>');
                // Try multiple placement options for better theme compatibility
                if (form.find('.single_variation_wrap').length) {
                    console.log('[Variant Box] Placing before single_variation_wrap');
                    form.find('.single_variation_wrap').before(message);
                } else if (form.find('.variations').length) {
                    console.log('[Variant Box] Placing after variations');
                    form.find('.variations').after(message);
                } else {
                    console.log('[Variant Box] Placing at form start');
                    form.prepend(message);
                }
            }
            message.show();
            console.log('[Variant Box] Message displayed, is visible:', message.is(':visible'));

            // Hide add to cart button
            const addToCartBtn = form.find('.single_add_to_cart_button');
            console.log('[Variant Box] Add to cart button found:', addToCartBtn.length);
            addToCartBtn.prop('disabled', true).css('opacity', '0.5');

            return;
        }

        const value = $(this).data('value');
        const attributeName = $(this).data('attribute');

        const form = $(this).closest('form.variations_form');

        // Hide out of stock message when selecting valid option
        form.find('.out-of-stock-message').fadeOut();
        form.find('.single_add_to_cart_button').prop('disabled', false).css('opacity', '1');

        const select = form.find('select[name="' + attributeName + '"]');

        if (select.length) {
            select.val(value).trigger('change');

            $(this).siblings().removeClass('selected');
            $(this).addClass('selected');
        } else {
            console.warn('[Variant Box] Select not found for:', attributeName);
        }
    });
});