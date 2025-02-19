jQuery(document).ready(function($) {
    $('.glue_link_cache_clear').on('click', function(e) {
        e.preventDefault();
        
        const $button = $(this);
        const originalText = $button.text();
        
        // Disable button and show loading state
        $button.prop('disabled', true).text('Clearing...');

        var data = {
            'action': 'glue_link_refresh_lists',
            'nonce': GlueLinkAdmin.nonce
        };

        $.post(GlueLinkAdmin.ajax_url, data, function(response) {
            if(response.success) {
                alert(GlueLinkAdmin.strings['cache_cleared']);
            } else {
                alert(GlueLinkAdmin.strings['cache_error'] + ' ' + (response.data?.message || 'Unknown error'));
            }
        }).always(function() {
            // Re-enable button and restore text
            $button.prop('disabled', false).text(originalText);
        });
    });
});
