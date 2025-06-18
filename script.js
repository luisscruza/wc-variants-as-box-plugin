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
});
