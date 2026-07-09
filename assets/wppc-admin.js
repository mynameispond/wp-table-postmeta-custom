jQuery(document).ready(function($) {
    // Helper to show/hide loading
    var $loadingOverlay = null;

    function showLoading($card) {
        $card.addClass('wppc-loading-relative');
        $loadingOverlay = $('<div class="wppc-loading-overlay"><div class="wppc-spinner"></div></div>');
        $card.append($loadingOverlay);
        $('.wppc-admin-wrap button, .wppc-admin-wrap input, .wppc-admin-wrap textarea, .wppc-admin-wrap select').prop('disabled', true);
    }

    function hideLoading($card) {
        if ($loadingOverlay) {
            $loadingOverlay.remove();
            $loadingOverlay = null;
        }
        $card.removeClass('wppc-loading-relative');
        $('.wppc-admin-wrap button, .wppc-admin-wrap input, .wppc-admin-wrap textarea, .wppc-admin-wrap select').prop('disabled', false);
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

    // ==========================================
    // 1. Table Types Page Handling
    // ==========================================
    if ($('#new_table_slug').length > 0) {
        var $container = $('#wppc-table-types-container');
        var $card = $container.closest('.wppc-card');

        // Function to strip inline onclick confirmation since we'll handle confirmation via JS delegate
        function stripInlineOnclick() {
            $container.find('.button-link-delete').removeAttr('onclick');
        }

        // Strip on page load
        stripInlineOnclick();

        // Intercept "สร้างตารางใหม่" form submit
        $('#new_table_slug').closest('form').on('submit', function(e) {
            e.preventDefault();
            var slug = $('#new_table_slug').val();

            showLoading($card);

            $.post(wppc_params.ajax_url, {
                action: 'wppc_create_table',
                nonce: wppc_params.nonces.create_table,
                new_table_slug: slug
            })
            .done(function(response) {
                if (response.success) {
                    $container.html(response.data.html);
                    stripInlineOnclick();
                    $('#new_table_slug').val('');
                    showNotice('success', response.data.message);
                } else {
                    showNotice('error', (response.data && response.data.message) || 'เกิดข้อผิดพลาดในการสร้างตาราง');
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
                hideLoading($card);
            });
        });

        // Intercept "ลบตาราง" button click using event delegation
        $container.on('click', '.button-link-delete', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $form = $btn.closest('form');
            var slug = $form.find('input[name="table"]').val();

            if (!confirm('ยืนยันการลบตารางและข้อมูลทั้งหมด?')) {
                return;
            }

            showLoading($card);

            $.post(wppc_params.ajax_url, {
                action: 'wppc_delete_table',
                nonce: wppc_params.nonces.delete_table,
                table: slug
            })
            .done(function(response) {
                if (response.success) {
                    $container.html(response.data.html);
                    stripInlineOnclick();
                    showNotice('success', response.data.message);
                } else {
                    showNotice('error', (response.data && response.data.message) || 'เกิดข้อผิดพลาดในการลบตาราง');
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
                hideLoading($card);
            });
        });
    }

    // ==========================================
    // 2. Data Manager Page Handling (AJAX Search & Pagination)
    // ==========================================
    if ($('#wppc-data-table-container').length > 0) {
        var $dataContainer = $('#wppc-data-table-container');

        // Checkbox select-all handling using delegated jQuery events
        $dataContainer.on('change', '#wppc-select-all-top, #wppc-select-all', function() {
            var isChecked = $(this).prop('checked');
            $('#wppc-select-all-top, #wppc-select-all').prop('checked', isChecked);
            $('.wppc-row-cb').prop('checked', isChecked);
        });

        $dataContainer.on('change', '.wppc-row-cb', function() {
            var allChecked = $('.wppc-row-cb').length === $('.wppc-row-cb:checked').length;
            $('#wppc-select-all-top, #wppc-select-all').prop('checked', allChecked);
        });

        function fetchTable(data) {
            var $tableCard = $dataContainer.find('.wppc-card');
            showLoading($tableCard);

            $.get(wppc_params.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    $dataContainer.html(response.html);
                } else {
                    showNotice('error', response.message || 'เกิดข้อผิดพลาดในการโหลดข้อมูล');
                }
            })
            .fail(function(xhr) {
                showNotice('error', 'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์');
            })
            .always(function() {
                hideLoading($tableCard);
            });
        }

        // Intercept search/filter form submit
        $dataContainer.on('submit', 'form[method="get"]', function(e) {
            e.preventDefault();
            var $form = $(this);
            var filterPostId = $form.find('input[name="filter_post_id"]').val();
            var filterMetaKey = $form.find('input[name="filter_meta_key"]').val();
            var filterMetaValue = $form.find('input[name="filter_meta_value"]').val();
            var table = $form.find('input[name="table"]').val() || wppc_params.active_slug;

            fetchTable({
                action: 'wppc_get_data_table',
                nonce: wppc_params.nonces.get_data_table,
                table: table,
                filter_post_id: filterPostId,
                filter_meta_key: filterMetaKey,
                filter_meta_value: filterMetaValue,
                paged: 1
            });
        });

        // Intercept pagination link clicks
        $dataContainer.on('click', '.tablenav-pages a', function(e) {
            e.preventDefault();
            var href = $(this).attr('href');
            var match = href.match(/[?&]paged=(\d+)/);
            var paged = match ? parseInt(match[1], 10) : 1;

            var filterPostId = $dataContainer.find('input[name="filter_post_id"]').val() || '';
            var filterMetaKey = $dataContainer.find('input[name="filter_meta_key"]').val() || '';
            var filterMetaValue = $dataContainer.find('input[name="filter_meta_value"]').val() || '';
            var table = $dataContainer.find('input[name="table"]').val() || wppc_params.active_slug;

            fetchTable({
                action: 'wppc_get_data_table',
                nonce: wppc_params.nonces.get_data_table,
                table: table,
                filter_post_id: filterPostId,
                filter_meta_key: filterMetaKey,
                filter_meta_value: filterMetaValue,
                paged: paged
            });
        });

        // Intercept search form "ล้างคำค้น" link click
        $dataContainer.on('click', 'form[method="get"] a.wppc-clear-search', function(e) {
            e.preventDefault();
            var table = $dataContainer.find('input[name="table"]').val() || wppc_params.active_slug;
            fetchTable({
                action: 'wppc_get_data_table',
                nonce: wppc_params.nonces.get_data_table,
                    table: table,
                    filter_post_id: '',
                    filter_meta_key: '',
                    filter_meta_value: '',
                    paged: 1
                });
            }
        });
    }
});
