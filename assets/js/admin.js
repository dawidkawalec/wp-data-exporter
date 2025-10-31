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

