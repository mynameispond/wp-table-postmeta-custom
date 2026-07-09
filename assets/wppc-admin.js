jQuery(document).ready(function($) {
    // Helper to show/hide loading
    function showLoading($card) {
        $card.addClass('wppc-loading-relative');
        $card.find('.wppc-loading-overlay').remove(); // safeguard
        var $loadingOverlay = $('<div class="wppc-loading-overlay"><div class="wppc-spinner"></div></div>');
        $card.append($loadingOverlay);
        $('.wppc-admin-wrap button, .wppc-admin-wrap input, .wppc-admin-wrap textarea, .wppc-admin-wrap select').prop('disabled', true);
    }

    function hideLoading($card) {
        $card.find('.wppc-loading-overlay').remove();
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
    // 2. Data Manager Page Handling (AJAX Search & Pagination & CRUD Operations)
    // ==========================================
    if ($('#wppc-data-table-container').length > 0) {
        var $dataContainer = $('#wppc-data-table-container');
        var $form = $('#wppc_post_id').closest('form');

        function stripDataManagerOnclick() {
            $dataContainer.find('.button-link-delete').removeAttr('onclick');
        }

        // Strip on load
        stripDataManagerOnclick();

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
                    stripDataManagerOnclick();
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
            var $formGet = $(this);
            var filterPostId = $formGet.find('input[name="filter_post_id"]').val();
            var filterMetaKey = $formGet.find('input[name="filter_meta_key"]').val();
            var filterMetaValue = $formGet.find('input[name="filter_meta_value"]').val();
            var table = $formGet.find('input[name="table"]').val() || wppc_params.active_slug;

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
        });

        // Edit Record Hook (delegated on #wppc-data-table-container)
        $dataContainer.on('click', 'a.wppc-edit-record', function(e) {
            e.preventDefault();
            var editId = $(this).data('id') || 0;
            if (editId <= 0) {
                return;
            }
            var href = $(this).attr('href');
            var tableMatch = href.match(/[?&]table=([^&]+)/);
            var table = tableMatch ? tableMatch[1] : (wppc_params.active_slug || '');

            var $formCard = $form.closest('.wppc-card');
            showLoading($formCard);

            $.get(wppc_params.ajax_url, {
                action: 'wppc_ajax_get_record',
                nonce: wppc_params.nonces.get_data_table,
                meta_id: editId,
                table: table
            })
            .done(function(response) {
                if (response.success) {
                    $form.find('input[name="meta_id"]').val(editId);
                    $form.find('#wppc_post_id').val(response.record.post_id);
                    $form.find('#wppc_meta_key').val(response.record.meta_key);
                    $form.find('#wppc_meta_value').val(response.record.meta_value);

                    $form.find('input[type="submit"]').val('อัปเดตข้อมูล');

                    // Show or create cancel edit button
                    var $cancelBtn = $form.find('.wppc-cancel-edit');
                    if ($cancelBtn.length === 0) {
                        $cancelBtn = $('<button type="button" class="button wppc-cancel-edit" style="margin-left: 8px;">ยกเลิกแก้ไข</button>');
                        $form.find('input[type="submit"]').after($cancelBtn);
                    }
                    $cancelBtn.show();

                    // Scroll to form smoothly
                    $('html, body').animate({
                        scrollTop: $form.offset().top - 100
                    }, 500);
                } else {
                    showNotice('error', response.message || 'ไม่สามารถดึงข้อมูลได้');
                }
            })
            .fail(function() {
                showNotice('error', 'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์');
            })
            .always(function() {
                hideLoading($formCard);
            });
        });

        // Cancel Edit Hook
        $form.on('click', '.wppc-cancel-edit', function(e) {
            e.preventDefault();
            $form.find('#wppc_post_id').val('');
            $form.find('#wppc_meta_key').val('');
            $form.find('#wppc_meta_value').val('');
            $form.find('input[name="meta_id"]').val('0');
            $form.find('input[type="submit"]').val('เพิ่มข้อมูล');
            $form.find('.wppc-cancel-edit').hide();
        });

        // Save Form Hook
        $form.on('submit', function(e) {
            e.preventDefault();
            var $formCard = $form.closest('.wppc-card');
            showLoading($formCard);

            var formData = $form.serializeArray();
            formData = formData.filter(function(item) {
                return item.name !== 'wppc_action' && item.name !== 'action';
            });
            formData.push({ name: 'action', value: 'wppc_ajax_save_record' });
            formData.push({ name: 'nonce', value: wppc_params.nonces.save_record });

            $.post(wppc_params.ajax_url, formData)
            .done(function(response) {
                if (response.success) {
                    showNotice('success', response.message || 'บันทึกข้อมูลเรียบร้อย');

                    // Reset form and clear edit state
                    $form.find('#wppc_post_id').val('');
                    $form.find('#wppc_meta_key').val('');
                    $form.find('#wppc_meta_value').val('');
                    $form.find('input[name="meta_id"]').val('0');
                    $form.find('input[type="submit"]').val('เพิ่มข้อมูล');
                    $form.find('.wppc-cancel-edit').hide();
                    $form.find('a:contains("ยกเลิกแก้ไข")').hide();

                    // Reload table
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
                        paged: 1
                    });
                } else {
                    showNotice('error', response.message || 'บันทึกข้อมูลไม่สำเร็จ');
                }
            })
            .fail(function() {
                showNotice('error', 'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์');
            })
            .always(function() {
                hideLoading($formCard);
            });
        });

        // Delete Record Hook
        $dataContainer.on('click', 'tbody .button-link-delete', function(e) {
            e.preventDefault();
            if (!confirm('ยืนยันการลบข้อมูลนี้?')) {
                return;
            }
            var $btn = $(this);
            var $row = $btn.closest('tr');
            var metaId = $row.find('.wppc-row-cb').val();
            var table = $dataContainer.find('input[name="table"]').val() || wppc_params.active_slug;

            var $tableCard = $dataContainer.find('.wppc-card');
            showLoading($tableCard);

            $.post(wppc_params.ajax_url, {
                action: 'wppc_ajax_delete_record',
                nonce: wppc_params.nonces.delete_record,
                meta_id: metaId,
                table: table
            })
            .done(function(response) {
                if (response.success) {
                    showNotice('success', response.message || 'ลบข้อมูลเรียบร้อย');
                    var filterPostId = $dataContainer.find('input[name="filter_post_id"]').val() || '';
                    var filterMetaKey = $dataContainer.find('input[name="filter_meta_key"]').val() || '';
                    var filterMetaValue = $dataContainer.find('input[name="filter_meta_value"]').val() || '';
                    var activePageMatch = $dataContainer.find('.tablenav-pages a.current').first();
                    var pagedVal = activePageMatch.length > 0 ? parseInt(activePageMatch.text(), 10) : 1;
                    fetchTable({
                        action: 'wppc_get_data_table',
                        nonce: wppc_params.nonces.get_data_table,
                        table: table,
                        filter_post_id: filterPostId,
                        filter_meta_key: filterMetaKey,
                        filter_meta_value: filterMetaValue,
                        paged: pagedVal
                    });
                } else {
                    showNotice('error', response.message || 'ลบข้อมูลไม่สำเร็จ');
                    hideLoading($tableCard);
                }
            })
            .fail(function() {
                showNotice('error', 'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์');
                hideLoading($tableCard);
            });
        });

        // Bulk Delete Hook
        $dataContainer.on('submit', '#wppc-bulk-form', function(e) {
            e.preventDefault();
            var bulkIds = [];
            $('.wppc-row-cb:checked').each(function() {
                bulkIds.push($(this).val());
            });
            if (bulkIds.length === 0) {
                alert('ไม่ได้เลือกข้อมูลที่ต้องการลบ');
                return;
            }
            if (!confirm('ยืนยันการลบข้อมูลที่เลือก?')) {
                return;
            }

            var table = $dataContainer.find('input[name="table"]').val() || wppc_params.active_slug;
            var $tableCard = $dataContainer.find('.wppc-card');
            showLoading($tableCard);

            $.post(wppc_params.ajax_url, {
                action: 'wppc_ajax_bulk_delete',
                nonce: wppc_params.nonces.bulk_delete,
                table: table,
                bulk_ids: bulkIds
            })
            .done(function(response) {
                if (response.success) {
                    showNotice('success', response.message || 'ลบข้อมูลเรียบร้อย');
                    var filterPostId = $dataContainer.find('input[name="filter_post_id"]').val() || '';
                    var filterMetaKey = $dataContainer.find('input[name="filter_meta_key"]').val() || '';
                    var filterMetaValue = $dataContainer.find('input[name="filter_meta_value"]').val() || '';
                    fetchTable({
                        action: 'wppc_get_data_table',
                        nonce: wppc_params.nonces.get_data_table,
                        table: table,
                        filter_post_id: filterPostId,
                        filter_meta_key: filterMetaKey,
                        filter_meta_value: filterMetaValue,
                        paged: 1
                    });
                } else {
                    showNotice('error', response.message || 'ลบข้อมูลไม่สำเร็จ');
                    hideLoading($tableCard);
                }
            })
            .fail(function() {
                showNotice('error', 'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์');
                hideLoading($tableCard);
            });
        });

        // Truncate Table Hook
        $dataContainer.on('submit', 'form:has(input[name="wppc_action"][value="truncate_table"])', function(e) {
            e.preventDefault();
            var table = $(this).find('input[name="table"]').val() || wppc_params.active_slug;
            if (!confirm('ยืนยันการล้างข้อมูลทั้งหมดในตาราง ' + table + '? การกระทำนี้ไม่สามารถย้อนกลับได้')) {
                return;
            }

            var $tableCard = $dataContainer.find('.wppc-card');
            showLoading($tableCard);

            $.post(wppc_params.ajax_url, {
                action: 'wppc_ajax_truncate_table',
                nonce: wppc_params.nonces.truncate_table,
                table: table
            })
            .done(function(response) {
                if (response.success) {
                    showNotice('success', response.message || 'ล้างข้อมูลทั้งตารางเรียบร้อย');
                    fetchTable({
                        action: 'wppc_get_data_table',
                        nonce: wppc_params.nonces.get_data_table,
                        table: table,
                        filter_post_id: '',
                        filter_meta_key: '',
                        filter_meta_value: '',
                        paged: 1
                    });
                } else {
                    showNotice('error', response.message || 'ล้างข้อมูลไม่สำเร็จ');
                    hideLoading($tableCard);
                }
            })
            .fail(function() {
                showNotice('error', 'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์');
                hideLoading($tableCard);
            });
        });
    }
});
