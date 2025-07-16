jQuery(document).ready(function ($) {
    if ($('.variation-box').length === 1) {
        $('.variations').hide();
    }
    // Delegate click to document to ensure it catches dynamically loaded elements
    $(document).on('click', '.variation-box', function () {
        if ($(this).hasClass('out-of-stock')) {
            const form = $(this).closest('form.variations_form');
            form.find('.variation-box').removeClass('selected');
            form.find('select').val('').trigger('change');
            
            // Check if notifications are enabled before showing the form
            if (variant_box_ajax.notifications_enabled == '1') {
                // Show email notification form for out-of-stock items
                showEmailNotificationForm($(this));
            }
            return;
        }

        const value = $(this).data('value');
        const attributeName = $(this).data('attribute');

        const form = $(this).closest('form.variations_form');
        const select = form.find('select[name="' + attributeName + '"]');

        if (select.length) {
            select.val(value).trigger('change');

            // Mark as selected visually
            $(this).siblings().removeClass('selected');
            $(this).addClass('selected');
        } else {
            console.warn('[Variant Box] Select not found for:', attributeName);
        }
    });

    // Function to show email notification form for out-of-stock variants
    function showEmailNotificationForm(clickedBox) {
        const existingForm = clickedBox.parent().find('.email-notification-form');
        if (existingForm.length) {
            existingForm.remove();
            return;
        }

        const variantName = clickedBox.text();
        const productId = $('form.variations_form').find('input[name="product_id"]').val();
        const variationData = {
            attribute: clickedBox.data('attribute'),
            value: clickedBox.data('value')
        };

        const emailForm = `
            <div class="email-notification-form">
                <h4>Get notified when "${variantName}" is back in stock</h4>
                <form class="stock-notification-form">
                    <input type="hidden" name="product_id" value="${productId}">
                    <input type="hidden" name="variant_attribute" value="${variationData.attribute}">
                    <input type="hidden" name="variant_value" value="${variationData.value}">
                    <div class="email-input-group">
                        <input type="email" name="notification_email" placeholder="Enter your email address" required>
                        <button type="submit">Notify Me</button>
                        <button type="button" class="cancel-btn">Cancel</button>
                    </div>
                    <div class="notification-message" style="display: none;"></div>
                </form>
            </div>
        `;

        clickedBox.parent().append(emailForm);
    }

    // Handle email notification form submission
    $(document).on('submit', '.stock-notification-form', function (e) {
        e.preventDefault();
        
        const form = $(this);
        const messageDiv = form.find('.notification-message');
        const submitBtn = form.find('button[type="submit"]');
        
        // Show loading state
        submitBtn.prop('disabled', true).text('Submitting...');
        messageDiv.hide();

        $.ajax({
            url: variant_box_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'save_stock_notification',
                nonce: variant_box_ajax.nonce,
                ...Object.fromEntries(new FormData(form[0]))
            },
            success: function (response) {
                if (response.success) {
                    messageDiv.removeClass('error').addClass('success')
                        .text('Thank you! We\'ll notify you when this item is back in stock.')
                        .show();
                    form.find('input[type="email"]').val('');
                } else {
                    messageDiv.removeClass('success').addClass('error')
                        .text(response.data || 'An error occurred. Please try again.')
                        .show();
                }
            },
            error: function () {
                messageDiv.removeClass('success').addClass('error')
                    .text('Network error. Please try again.')
                    .show();
            },
            complete: function () {
                submitBtn.prop('disabled', false).text('Notify Me');
            }
        });
    });

    // Handle cancel button
    $(document).on('click', '.email-notification-form .cancel-btn', function () {
        $(this).closest('.email-notification-form').remove();
    });
});
