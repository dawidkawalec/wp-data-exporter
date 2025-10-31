/**
 * Admin panel JavaScript for WooCommerce Data Exporter
 */

(function($) {
    'use strict';

    const WooExporter = {
        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Bind events
         */
        bindEvents: function() {
            $('#woo-exporter-form').on('submit', this.handleFormSubmit.bind(this));
            $(document).on('click', '.delete-export-btn', this.handleDelete.bind(this));
            $(document).on('click', '.preview-export-btn', this.handlePreview.bind(this));
            $(document).on('click', '.csv-preview-close', this.closePreviewModal.bind(this));
            $(document).on('click', '.csv-preview-modal', function(e) {
                if ($(e.target).hasClass('csv-preview-modal')) {
                    WooExporter.closePreviewModal();
                }
            });
        },

        /**
         * Handle form submission
         */
        handleFormSubmit: function(e) {
            e.preventDefault();

            const $form = $(e.target);
            const $submitBtn = $form.find('#submit-export');
            const $resultDiv = $('#export-result');

            // Get form data
            const formData = {
                action: 'create_export_job',
                nonce: wooExporterAdmin.nonce,
                export_type: $form.find('#export_type').val(),
                start_date: $form.find('#start_date').val(),
                end_date: $form.find('#end_date').val()
            };

            // Validate
            if (!formData.export_type) {
                this.showMessage($resultDiv, wooExporterAdmin.strings.error, 'Wybierz typ eksportu.', 'error');
                return;
            }

            // Disable button
            $submitBtn.prop('disabled', true);
            $submitBtn.html('<span class="dashicons dashicons-update dashicons-spin"></span> ' + wooExporterAdmin.strings.processing);

            // Send AJAX request
            $.ajax({
                url: wooExporterAdmin.ajax_url,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        WooExporter.showMessage(
                            $resultDiv, 
                            wooExporterAdmin.strings.success, 
                            response.data.message + ' Eksport zostanie przetworzony w tle. Otrzymasz email z linkiem do pobrania.', 
                            'success'
                        );
                        
                        // Reset form
                        $form[0].reset();

                        // Optional: redirect to history after 2 seconds
                        setTimeout(function() {
                            window.location.href = window.location.pathname + '?page=woo-data-exporter&tab=history';
                        }, 2000);
                    } else {
                        WooExporter.showMessage(
                            $resultDiv, 
                            wooExporterAdmin.strings.error, 
                            response.data.message || 'Wystąpił nieznany błąd.', 
                            'error'
                        );
                    }
                },
                error: function(xhr, status, error) {
                    WooExporter.showMessage(
                        $resultDiv, 
                        wooExporterAdmin.strings.error, 
                        'Błąd połączenia: ' + error, 
                        'error'
                    );
                },
                complete: function() {
                    // Re-enable button
                    $submitBtn.prop('disabled', false);
                    $submitBtn.html('<span class="dashicons dashicons-download"></span> Generuj Eksport');
                }
            });
        },

        /**
         * Show message
         */
        showMessage: function($container, title, message, type) {
            $container.removeClass('success error info').addClass(type);
            $container.html('<strong>' + title + '</strong><br>' + message);
            $container.fadeIn();

            // Auto-hide after 10 seconds for success messages
            if (type === 'success') {
                setTimeout(function() {
                    $container.fadeOut();
                }, 10000);
            }
        },

        /**
         * Handle delete button click
         */
        handleDelete: function(e) {
            e.preventDefault();
            
            const $btn = $(e.currentTarget);
            const jobId = $btn.data('job-id');
            
            if (!confirm('Czy na pewno chcesz usunąć ten eksport? Ta operacja jest nieodwracalna.')) {
                return;
            }

            $btn.prop('disabled', true).text('Usuwanie...');

            $.ajax({
                url: wooExporterAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'delete_export_job',
                    nonce: wooExporterAdmin.nonce,
                    job_id: jobId
                },
                success: function(response) {
                    if (response.success) {
                        // Remove row from table
                        $btn.closest('tr').fadeOut(400, function() {
                            $(this).remove();
                        });
                    } else {
                        alert('Błąd: ' + (response.data.message || 'Nie udało się usunąć eksportu.'));
                        $btn.prop('disabled', false).text('Usuń');
                    }
                },
                error: function() {
                    alert('Błąd połączenia. Spróbuj ponownie.');
                    $btn.prop('disabled', false).text('Usuń');
                }
            });
        },

        /**
         * Handle preview button click
         */
        handlePreview: function(e) {
            e.preventDefault();
            
            const $btn = $(e.currentTarget);
            const jobId = $btn.data('job-id');
            
            WooExporter.showPreviewModal(jobId);
        },

        /**
         * Show preview modal
         */
        showPreviewModal: function(jobId) {
            const $modal = $('#csv-preview-modal');
            const $loading = $modal.find('.csv-preview-loading');
            const $tableWrapper = $modal.find('.csv-preview-table-wrapper');
            const $info = $modal.find('.csv-preview-info');

            // Show modal and loading
            $modal.fadeIn();
            $loading.show();
            $tableWrapper.empty().hide();
            $info.empty();

            // Fetch CSV data
            $.ajax({
                url: wooExporterAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'preview_export_csv',
                    nonce: wooExporterAdmin.nonce,
                    job_id: jobId
                },
                success: function(response) {
                    $loading.hide();
                    
                    if (response.success) {
                        WooExporter.renderCsvPreview(response.data, $tableWrapper, $info);
                    } else {
                        $tableWrapper.html('<p class="error">' + (response.data.message || 'Błąd ładowania podglądu.') + '</p>').show();
                    }
                },
                error: function() {
                    $loading.hide();
                    $tableWrapper.html('<p class="error">Błąd połączenia.</p>').show();
                }
            });
        },

        /**
         * Render CSV preview table
         */
        renderCsvPreview: function(data, $wrapper, $info) {
            const headers = data.headers || [];
            const rows = data.rows || [];
            const totalRows = data.total_rows || 0;

            // Show info
            $info.html('<p><strong>Wyświetlono:</strong> ' + rows.length + ' z ' + totalRows + ' wierszy (bez nagłówka)</p>');

            // Build table
            let html = '<table class="wp-list-table widefat striped">';
            
            // Headers
            html += '<thead><tr>';
            headers.forEach(function(header) {
                html += '<th>' + WooExporter.escapeHtml(header) + '</th>';
            });
            html += '</tr></thead>';

            // Rows
            html += '<tbody>';
            rows.forEach(function(row) {
                html += '<tr>';
                row.forEach(function(cell) {
                    html += '<td>' + WooExporter.escapeHtml(cell || '') + '</td>';
                });
                html += '</tr>';
            });
            html += '</tbody></table>';

            $wrapper.html(html).fadeIn();
        },

        /**
         * Close preview modal
         */
        closePreviewModal: function() {
            $('#csv-preview-modal').fadeOut();
        },

        /**
         * Escape HTML
         */
        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        /**
         * Check job status (for future polling functionality)
         */
        checkJobStatus: function(jobId) {
            $.ajax({
                url: wooExporterAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_job_status',
                    nonce: wooExporterAdmin.nonce,
                    job_id: jobId
                },
                success: function(response) {
                    if (response.success) {
                        console.log('Job status:', response.data);
                        // Handle status update
                    }
                }
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        WooExporter.init();
    });

})(jQuery);

