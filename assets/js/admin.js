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
            $(document).on('click', '#run-cron-manually', this.handleRunCron.bind(this));
            $(document).on('click', '.csv-preview-modal', function(e) {
                if ($(e.target).hasClass('csv-preview-modal')) {
                    WooExporter.closePreviewModal();
                }
            });

            // Schedule events
            $(document).on('click', '#add-new-schedule', this.openScheduleModal.bind(this));
            $(document).on('click', '.edit-schedule-btn', this.editSchedule.bind(this));
            $(document).on('click', '.delete-schedule-btn', this.deleteSchedule.bind(this));
            $(document).on('click', '.toggle-schedule-btn', this.toggleSchedule.bind(this));
            $(document).on('click', '.schedule-modal-close', this.closeScheduleModal.bind(this));
            $(document).on('submit', '#schedule-form', this.handleScheduleFormSubmit.bind(this));
            $(document).on('change', '#schedule_frequency_type', this.updateFrequencyField.bind(this));
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
                end_date: $form.find('#end_date').val(),
                notification_email: $form.find('#notification_email').val()
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
        showPreviewModal: function(jobId, page) {
            const $modal = $('#csv-preview-modal');
            const $loading = $modal.find('.csv-preview-loading');
            const $tableWrapper = $modal.find('.csv-preview-table-wrapper');
            const $info = $modal.find('.csv-preview-info');

            page = page || 1;

            // Store current job ID for pagination
            $modal.data('current-job-id', jobId);

            // Show modal and loading
            $modal.fadeIn();
            $loading.show();
            $tableWrapper.empty().hide();

            // Fetch CSV data
            $.ajax({
                url: wooExporterAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'preview_export_csv',
                    nonce: wooExporterAdmin.nonce,
                    job_id: jobId,
                    page: page,
                    per_page: 100
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
            const currentPage = data.current_page || 1;
            const perPage = data.per_page || 100;
            const totalPages = data.total_pages || 1;

            // Calculate row range
            const startRow = ((currentPage - 1) * perPage) + 1;
            const endRow = Math.min(startRow + rows.length - 1, totalRows);

            // Show info with pagination
            let infoHtml = '<div style="display: flex; justify-content: space-between; align-items: center;">';
            infoHtml += '<div><strong>Wiersze:</strong> ' + startRow + '-' + endRow + ' z ' + totalRows + ' (bez nagłówka)</div>';
            
            // Pagination controls
            if (totalPages > 1) {
                infoHtml += '<div class="csv-pagination">';
                
                // First + Previous
                if (currentPage > 1) {
                    infoHtml += '<button class="button csv-page-btn" data-page="1" title="Pierwsza strona">«</button> ';
                    infoHtml += '<button class="button csv-page-btn" data-page="' + (currentPage - 1) + '" title="Poprzednia">‹</button> ';
                }
                
                // Page info
                infoHtml += '<span style="margin: 0 10px;"><strong>Strona ' + currentPage + ' / ' + totalPages + '</strong></span>';
                
                // Next + Last
                if (currentPage < totalPages) {
                    infoHtml += '<button class="button csv-page-btn" data-page="' + (currentPage + 1) + '" title="Następna">›</button> ';
                    infoHtml += '<button class="button csv-page-btn" data-page="' + totalPages + '" title="Ostatnia strona">»</button>';
                }
                
                infoHtml += '</div>';
            }
            infoHtml += '</div>';
            
            $info.html(infoHtml);

            // Bind pagination buttons
            $info.find('.csv-page-btn').on('click', function() {
                const page = $(this).data('page');
                const jobId = $('#csv-preview-modal').data('current-job-id');
                WooExporter.showPreviewModal(jobId, page);
            });

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
         * Handle run cron manually
         */
        handleRunCron: function(e) {
            e.preventDefault();
            
            const $btn = $(e.currentTarget);
            const originalHtml = $btn.html();
            
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update dashicons-spin"></span> Uruchamiam...');

            $.ajax({
                url: wooExporterAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'run_cron_manually',
                    nonce: wooExporterAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert('✅ ' + response.data.message + '\n\nOdśwież stronę za chwilę, aby zobaczyć zmiany.');
                        
                        // Reload page after 2 seconds
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        alert('Błąd: ' + (response.data.message || 'Nie udało się uruchomić crona.'));
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                },
                error: function() {
                    alert('Błąd połączenia. Spróbuj ponownie.');
                    $btn.prop('disabled', false).html(originalHtml);
                }
            });
        },

        /**
         * Open schedule modal (new or edit)
         */
        openScheduleModal: function(scheduleId) {
            // Handle if called from click event
            if (typeof scheduleId === 'object' && scheduleId.preventDefault) {
                scheduleId.preventDefault();
                scheduleId = null;
            }
            
            const $modal = $('#schedule-form-modal');
            const $form = $('#schedule-form');
            
            $form[0].reset();
            $('#schedule_id').val('');
            $('#schedule-modal-title').text('Nowy Harmonogram');
            
            if (scheduleId) {
                // Load schedule data for editing
                $('#schedule-modal-title').text('Edytuj Harmonogram');
                this.loadScheduleData(scheduleId);
            } else {
                // Set default date to today
                $('#schedule_start_date').val(new Date().toISOString().split('T')[0]);
            }
            
            this.updateFrequencyField();
            $modal.fadeIn();
        },

        /**
         * Close schedule modal
         */
        closeScheduleModal: function() {
            $('#schedule-form-modal').fadeOut();
        },

        /**
         * Load schedule data for editing
         */
        loadScheduleData: function(scheduleId) {
            $.ajax({
                url: wooExporterAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_schedule',
                    nonce: wooExporterAdmin.nonce,
                    schedule_id: scheduleId
                },
                success: function(response) {
                    if (response.success) {
                        const s = response.data.schedule;
                        $('#schedule_id').val(s.id);
                        $('#schedule_name').val(s.name);
                        $('#schedule_job_type').val(s.job_type);
                        $('#schedule_frequency_type').val(s.frequency_type);
                        $('#schedule_frequency_value').val(s.frequency_value);
                        $('#schedule_start_date').val(s.start_date);
                        $('#schedule_email').val(s.notification_email);
                        WooExporter.updateFrequencyField();
                    }
                }
            });
        },

        /**
         * Update frequency field labels based on type
         */
        updateFrequencyField: function() {
            const type = $('#schedule_frequency_type').val();
            const $label = $('#frequency_value_label');
            const $desc = $('#frequency_value_desc');
            const $input = $('#schedule_frequency_value');

            switch(type) {
                case 'daily':
                    $label.text('Co ile dni *');
                    $desc.text('1 = codziennie, 7 = co tydzień, 14 = co 2 tygodnie');
                    $input.attr({min: 1, max: 365, value: 7});
                    break;
                case 'weekly':
                    $label.text('Dzień tygodnia *');
                    $desc.text('1=Poniedziałek, 2=Wtorek, 3=Środa, 4=Czwartek, 5=Piątek, 6=Sobota, 7=Niedziela');
                    $input.attr({min: 1, max: 7, value: 1});
                    break;
                case 'monthly':
                    $label.text('Dzień miesiąca *');
                    $desc.text('1-31 (jeśli miesiąc ma mniej dni, użyje ostatniego dnia)');
                    $input.attr({min: 1, max: 31, value: 1});
                    break;
            }
        },

        /**
         * Handle schedule form submit
         */
        handleScheduleFormSubmit: function(e) {
            e.preventDefault();
            
            const $form = $(e.target);
            const scheduleId = $('#schedule_id').val();
            const action = scheduleId ? 'update_schedule' : 'create_schedule';
            
            const formData = {
                action: action,
                nonce: wooExporterAdmin.nonce,
                schedule_id: scheduleId,
                name: $('#schedule_name').val(),
                job_type: $('#schedule_job_type').val(),
                frequency_type: $('#schedule_frequency_type').val(),
                frequency_value: $('#schedule_frequency_value').val(),
                start_date: $('#schedule_start_date').val(),
                notification_email: $('#schedule_email').val()
            };

            console.log('Sending schedule data:', formData);

            $.ajax({
                url: wooExporterAdmin.ajax_url,
                type: 'POST',
                data: formData,
                success: function(response) {
                    console.log('Schedule response:', response);
                    if (response.success) {
                        alert('✅ ' + response.data.message);
                        WooExporter.closeScheduleModal();
                        location.reload();
                    } else {
                        alert('❌ ' + (response.data.message || 'Błąd zapisu.'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', xhr, status, error);
                    console.error('Response:', xhr.responseText);
                    alert('Błąd połączenia: ' + error + '\n\nOtwórz Console (F12) aby zobaczyć szczegóły.');
                }
            });
        },

        /**
         * Edit schedule
         */
        editSchedule: function(e) {
            const scheduleId = $(e.currentTarget).data('schedule-id');
            WooExporter.openScheduleModal(scheduleId);
        },

        /**
         * Delete schedule
         */
        deleteSchedule: function(e) {
            const $btn = $(e.currentTarget);
            const scheduleId = $btn.data('schedule-id');
            
            if (!confirm('Czy na pewno chcesz usunąć ten harmonogram? Nie wpłynie to na już wygenerowane eksporty.')) {
                return;
            }

            $.ajax({
                url: wooExporterAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'delete_schedule',
                    nonce: wooExporterAdmin.nonce,
                    schedule_id: scheduleId
                },
                success: function(response) {
                    if (response.success) {
                        $btn.closest('tr').fadeOut(400, function() { $(this).remove(); });
                    } else {
                        alert('Błąd: ' + (response.data.message || 'Nie udało się usunąć.'));
                    }
                }
            });
        },

        /**
         * Toggle schedule (pause/resume)
         */
        toggleSchedule: function(e) {
            const $btn = $(e.currentTarget);
            const scheduleId = $btn.data('schedule-id');
            const currentActive = $btn.data('active') == 1;
            const newActive = !currentActive;

            $.ajax({
                url: wooExporterAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'toggle_schedule',
                    nonce: wooExporterAdmin.nonce,
                    schedule_id: scheduleId,
                    is_active: newActive ? 1 : 0
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Błąd: ' + (response.data.message || 'Nie udało się zmienić statusu.'));
                    }
                }
            });
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



