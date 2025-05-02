jQuery(document).ready(function ($) {
    if ($('.variation-box').length === 1) {
        $('.variations').hide();
    }
    // Delegate click to document to ensure it catches dynamically loaded elements
    $(document).on('click', '.variation-box', function () {

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
