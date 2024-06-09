jQuery(document).ready(function ($) {
    $('#wcap-error-log-filter').on('change', '#error_category', function () {
        const category = $(this).val();
        $.ajax({
            url: wcap_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'wcap_filter_logs',
                error_category: category,
                nonce: wcap_ajax.nonce
            },
            success: function (response) {
                if (response.success) {
                    const logs = response.data;
                    const tbody = $('#wcap-error-log-table tbody');
                    tbody.empty();
                    logs.forEach(log => {
                        const row = `
                            <tr>
                                <td>${log.date}</td>
                                <td>${log.type}</td>
                                <td>${log.details}</td>
                                <td>${log.product_id}</td>
                                <td>${log.product_name}</td>
                                <td>${log.is_variation ? 'Yes' : 'No'}</td>
                            </tr>
                        `;
                        tbody.append(row);
                    });
                }
            }
        });
    });

    $('#wcap-clear-log').on('click', function () {
        $.ajax({
            url: wcap_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'wcap_clear_logs',
                nonce: wcap_ajax.nonce
            },
            success: function (response) {
                if (response.success) {
                    $('#wcap-error-log-table tbody').empty();
                }
            }
        });
    });
});
