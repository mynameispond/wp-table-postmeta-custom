jQuery(document).ready(function($) {
    // Only run on the table-types page (detect by check for #new_table_slug)
    if ($('#new_table_slug').length === 0) {
        return;
    }

    var $container = $('#wppc-table-types-container');
    var $card = $container.closest('.wppc-card');
    
    // Function to strip inline onclick confirmation since we'll handle confirmation via JS delegate
    function stripInlineOnclick() {
        $container.find('.button-link-delete').removeAttr('onclick');
    }

    // Strip on page load
    stripInlineOnclick();

    // Helper to show/hide loading
    var $loadingOverlay = null;
    function showLoading() {
        $card.addClass('wppc-loading-relative');
        $loadingOverlay = $('<div class="wppc-loading-overlay"><div class="wppc-spinner"></div></div>');
        $card.append($loadingOverlay);
        $('.wppc-admin-wrap button, .wppc-admin-wrap input[type="submit"]').prop('disabled', true);
    }

    function hideLoading() {
        if ($loadingOverlay) {
            $loadingOverlay.remove();
            $loadingOverlay = null;
        }
        $card.removeClass('wppc-loading-relative');
        $('.wppc-admin-wrap button, .wppc-admin-wrap input[type="submit"]').prop('disabled', false);
    }

    // Helper to show notice
    function showNotice(type, message) {
        // Remove existing dynamic notices
        $('.wppc-admin-wrap .notice').remove();

        var noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
        var $notice = $(
            '<div class="notice ' + noticeClass + ' is-dismissible wppc-animate-slide-in">' +
            '<p>' + message + '</p>' +
            '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>' +
            '</div>'
        );

        // Prepend to the page container
        $('.wppc-admin-wrap').prepend($notice);
    }

    // Dismiss notice handler
    $(document).on('click', '.notice.is-dismissible .notice-dismiss', function() {
        $(this).closest('.notice').fadeOut(function() {
            $(this).remove();
        });
    });

    // 1. Intercept "สร้างตารางใหม่" form submit
    $('#new_table_slug').closest('form').on('submit', function(e) {
        e.preventDefault();
        
        var $form = $(this);
        var slug = $('#new_table_slug').val();

        showLoading();

        $.post(wppc_params.ajax_url, {
            action: 'wppc_create_table',
            nonce: wppc_params.nonces.create_table,
            new_table_slug: slug
        })
        .done(function(response) {
            if (response.success) {
                // Update table HTML
                $container.html(response.data.html);
                stripInlineOnclick();
                
                // Clear input
                $('#new_table_slug').val('');
                
                // Show success notice
                showNotice('success', response.data.message);
            } else {
                // Show error notice
                showNotice('error', response.data.message || 'เกิดข้อผิดพลาดในการสร้างตาราง');
            }
        })
        .fail(function(xhr) {
            var errorMsg = 'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์';
            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                errorMsg = xhr.responseJSON.data.message;
            }
            showNotice('error', errorMsg);
        })
        .always(function() {
            hideLoading();
        });
    });

    // 2. Intercept "ลบตาราง" button click using event delegation
    $container.on('click', '.button-link-delete', function(e) {
        e.preventDefault();

        var $btn = $(this);
        var $form = $btn.closest('form');
        var slug = $form.find('input[name="table"]').val();

        if (!confirm('ยืนยันการลบตารางและข้อมูลทั้งหมด?')) {
            return;
        }

        showLoading();

        $.post(wppc_params.ajax_url, {
            action: 'wppc_delete_table',
            nonce: wppc_params.nonces.delete_table,
            table: slug
        })
        .done(function(response) {
            if (response.success) {
                // Update table HTML
                $container.html(response.data.html);
                stripInlineOnclick();
                
                // Show success notice
                showNotice('success', response.data.message);
            } else {
                // Show error notice
                showNotice('error', response.data.message || 'เกิดข้อผิดพลาดในการลบตาราง');
            }
        })
        .fail(function(xhr) {
            var errorMsg = 'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์';
            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                errorMsg = xhr.responseJSON.data.message;
            }
            showNotice('error', errorMsg);
        })
        .always(function() {
            hideLoading();
        });
    });
});
