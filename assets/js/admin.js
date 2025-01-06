jQuery(function($) {
    function toggleTestFields() {
        var isTestMode = $('#woocommerce_simplepay_test_mode').is(':checked');
        
        $('[data-show-if="test_mode"]').closest('tr').toggle(isTestMode);
    }

    $('#woocommerce_simplepay_test_mode').on('change', toggleTestFields);
    toggleTestFields();
}); 