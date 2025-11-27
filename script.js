jQuery(document).ready(function ($) {
    if ($('.variation-box').length === 1) {
        $('.variations').hide();
    }
    // Delegate click to document to ensure it catches dynamically loaded elements
    $(document).on('click', '.variation-box', function () {
        if ($(this).hasClass('out-of-stock')) {
            const form = $(this).closest('form.variations_form');

            form.find('.variation-box').removeClass('selected');
            $(this).addClass('selected');
            form.find('select').val('').trigger('change');

            // Show out of stock message
            let message = form.find('.out-of-stock-message');

            if (!message.length) {
                message = $('<div class="out-of-stock-message">Agotado</div>');
                // Try multiple placement options for better theme compatibility
                if (form.find('.single_variation_wrap').length) {
                    form.find('.single_variation_wrap').before(message);
                } else if (form.find('.variations').length) {
                    form.find('.variations').after(message);
                } else {
                    form.prepend(message);
                }
            }
            message.show();

            // Hide add to cart button
            const addToCartBtn = form.find('.single_add_to_cart_button');
            addToCartBtn.hide();

            // Show notify me form
            let notifyForm = form.find('.notify-me-form');
            if (!notifyForm.length) {
                const productId = form.find('input[name="product_id"]').val();
                const variationId = form.find('input[name="variation_id"]').val() || '';
                notifyForm = $(`
                    <div class="notify-me-form">
                        <h4>Notificarme cuando vuelva a estar disponible</h4>
                        <input type="email" class="notify-email-input" placeholder="Ingresa tu correo electrónico" required />
                        <input type="hidden" class="notify-product-id" value="${productId}" />
                        <input type="hidden" class="notify-variation-id" value="${variationId}" />
                        <input type="hidden" class="notify-variant-name" value="${$(this).text()}" />
                        <button type="button" class="notify-submit-btn">Notificarme</button>
                        <div class="notify-message"></div>
                    </div>
                `);
                if (form.find('.single_variation_wrap').length) {
                    form.find('.single_variation_wrap').before(notifyForm);
                } else if (form.find('.variations').length) {
                    form.find('.variations').after(notifyForm);
                } else {
                    form.append(notifyForm);
                }
            }
            notifyForm.show();

            return;
        }

        const value = $(this).data('value');
        const attributeName = $(this).data('attribute');

        const form = $(this).closest('form.variations_form');

        // Hide out of stock message when selecting valid option
        form.find('.out-of-stock-message').fadeOut();
        form.find('.single_add_to_cart_button').show();
        form.find('.notify-me-form').hide();

        const select = form.find('select[name="' + attributeName + '"]');

        if (select.length) {
            select.val(value).trigger('change');

            $(this).siblings().removeClass('selected');
            $(this).addClass('selected');
        } else {
            console.warn('[Variant Box] Select not found for:', attributeName);
        }
    });

    // Handle notify me form submission
    $(document).on('click', '.notify-submit-btn', function () {
        const form = $(this).closest('.notify-me-form');
        const email = form.find('.notify-email-input').val();
        const productId = form.find('.notify-product-id').val();
        const variationId = form.find('.notify-variation-id').val();
        const variantName = form.find('.notify-variant-name').val();
        const messageDiv = form.find('.notify-message');

        if (!email || !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
            messageDiv.html('<span class="error">Por favor ingresa un correo electrónico válido</span>').show();
            return;
        }

        $(this).prop('disabled', true).text('Enviando...');

        $.ajax({
            url: variantBoxData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'variant_box_notify_me',
                nonce: variantBoxData.nonce,
                email: email,
                product_id: productId,
                variation_id: variationId,
                variant_name: variantName
            },
            success: function (response) {
                if (response.success) {
                    messageDiv.html('<span class="success">¡Gracias! Te notificaremos cuando este artículo vuelva a estar disponible.</span>').show();
                    form.find('.notify-email-input').val('');
                } else {
                    messageDiv.html('<span class="error">' + (response.data || 'Ocurrió un error') + '</span>').show();
                }
            },
            error: function () {
                messageDiv.html('<span class="error">Ha ocurrido un error. Por favor intenta de nuevo.</span>').show();
            },
            complete: function () {
                form.find('.notify-submit-btn').prop('disabled', false).text('Notificarme');
            }
        });
    });
});