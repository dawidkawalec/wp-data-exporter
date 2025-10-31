/**
 * Template Builder JavaScript
 */

(function($) {
    'use strict';

    const TemplateBuilder = {
        selectedFields: [],
        fieldAliases: {},
        
        init: function() {
            // Load existing template data if editing
            if (typeof templateBuilderData !== 'undefined' && templateBuilderData.existingTemplate) {
                this.selectedFields = templateBuilderData.existingTemplate.selected_fields || [];
                this.fieldAliases = templateBuilderData.existingTemplate.field_aliases || {};
            }
            
            this.bindEvents();
            this.updateSelectedList();
        },

        bindEvents: function() {
            // Field checkboxes
            $(document).on('change', '.field-checkbox', this.handleFieldToggle.bind(this));
            
            // Search
            $('#field-search').on('keyup', this.handleSearch.bind(this));
            
            // Group toggle
            $(document).on('click', '.field-group-toggle', this.toggleGroup.bind(this));
            
            // Preview navigation
            $('#load-order-preview').on('click', this.loadPreview.bind(this));
            $('#preview-order-id').on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    TemplateBuilder.loadPreview();
                }
            });
            
            // Quick preview links
            $(document).on('click', '.quick-preview-link', function(e) {
                e.preventDefault();
                const orderId = $(this).data('order-id');
                $('#preview-order-id').val(orderId);
                TemplateBuilder.loadPreview();
            });
            
            // Form submit
            $('#template-builder-form').on('submit', this.handleSubmit.bind(this));
            
            // Alias editing
            $(document).on('click', '.edit-alias-btn', this.editAlias.bind(this));
            $(document).on('click', '.remove-field-btn', this.removeField.bind(this));
        },

        /**
         * Handle field checkbox toggle
         */
        handleFieldToggle: function(e) {
            const $checkbox = $(e.target);
            const field = $checkbox.val();
            
            if ($checkbox.is(':checked')) {
                if (!this.selectedFields.includes(field)) {
                    this.selectedFields.push(field);
                }
            } else {
                this.selectedFields = this.selectedFields.filter(f => f !== field);
                delete this.fieldAliases[field];
            }
            
            this.updateSelectedList();
        },

        /**
         * Update selected fields list
         */
        updateSelectedList: function() {
            const $list = $('#selected-fields-list');
            const $count = $('#selected-count');
            
            $count.text(this.selectedFields.length);
            
            if (this.selectedFields.length === 0) {
                $list.html('<p class="no-fields-selected" style="color: #646970; text-align: center; padding: 40px 0;">Zaznacz pola z lewej strony</p>');
                return;
            }
            
            let html = '<div class="selected-fields-items">';
            this.selectedFields.forEach((field, index) => {
                const alias = this.fieldAliases[field] || this.getDefaultLabel(field);
                html += '<div class="selected-field-item" data-field="' + field + '" style="padding: 10px; border-bottom: 1px solid #e5e5e5; display: flex; justify-content: space-between; align-items: center;">';
                html += '<div style="flex: 1;">';
                html += '<strong>' + (index + 1) + '.</strong> ';
                html += '<code style="font-size: 12px;">' + field + '</code><br>';
                html += '<span style="color: #646970; font-size: 12px;">Alias w CSV: <strong>' + alias + '</strong></span>';
                html += '</div>';
                html += '<div>';
                html += '<button type="button" class="button button-small edit-alias-btn" data-field="' + field + '">Zmień alias</button> ';
                html += '<button type="button" class="button button-small button-link-delete remove-field-btn" data-field="' + field + '">✕</button>';
                html += '</div>';
                html += '</div>';
            });
            html += '</div>';
            
            $list.html(html);
        },

        /**
         * Get default label for field
         */
        getDefaultLabel: function(field) {
            let label = field.replace(/^_?(billing|shipping|order|wc)_/, '');
            label = label.replace(/_/g, ' ');
            return label.split(' ').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
        },

        /**
         * Search fields
         */
        handleSearch: function(e) {
            const search = $(e.target).val().toLowerCase();
            
            $('.field-item').each(function() {
                const text = $(this).text().toLowerCase();
                $(this).toggle(text.includes(search));
            });
            
            // Hide empty groups
            $('.field-group').each(function() {
                const $group = $(this);
                const hasVisible = $group.find('.field-item:visible').length > 0;
                $group.toggle(hasVisible);
            });
        },

        /**
         * Toggle group
         */
        toggleGroup: function(e) {
            const $toggle = $(e.currentTarget);
            const $items = $toggle.next('.field-group-items');
            const $icon = $toggle.find('.dashicons');
            
            $items.slideToggle(200);
            $icon.toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-right-alt2');
        },

        /**
         * Edit alias
         */
        editAlias: function(e) {
            const field = $(e.target).data('field');
            const currentAlias = this.fieldAliases[field] || this.getDefaultLabel(field);
            const newAlias = prompt('Podaj nazwę kolumny w pliku CSV:', currentAlias);
            
            if (newAlias && newAlias.trim() !== '') {
                this.fieldAliases[field] = newAlias.trim();
                this.updateSelectedList();
            }
        },

        /**
         * Remove field
         */
        removeField: function(e) {
            const field = $(e.target).data('field');
            this.selectedFields = this.selectedFields.filter(f => f !== field);
            delete this.fieldAliases[field];
            
            // Uncheck checkbox
            $('.field-checkbox[value="' + field + '"]').prop('checked', false);
            
            this.updateSelectedList();
        },

        /**
         * Load preview - shows ALL fields from order
         */
        loadPreview: function() {
            const orderId = $('#preview-order-id').val();
            
            if (!orderId) {
                $('#preview-status').text('Wpisz ID zamówienia').css('color', '#d63638');
                return;
            }
            
            $('#preview-status').html('<span class="woo-exporter-loading"></span> Ładowanie...').css('color', '#646970');
            
            $.ajax({
                url: wooExporterAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'preview_template_values',
                    nonce: wooExporterAdmin.nonce,
                    order_id: orderId,
                    fields: JSON.stringify([])
                },
                success: function(response) {
                    if (response.success) {
                        TemplateBuilder.renderPreviewTable(response.data);
                        $('#preview-status').text('✓ Załadowano zamówienie #' + orderId).css('color', '#00a32a');
                    } else {
                        $('#preview-status').text('Błąd: ' + (response.data.message || 'Nie znaleziono')).css('color', '#d63638');
                    }
                },
                error: function(xhr) {
                    $('#preview-status').text('Błąd połączenia: ' + xhr.status).css('color', '#d63638');
                }
            });
        },

        /**
         * Render preview - inline under each field
         */
        renderPreviewTable: function(data) {
            const values = data.values || {};
            
            // Update each field with its preview value
            $('.field-item').each(function() {
                const $item = $(this);
                const field = $item.data('field');
                const $previewDiv = $item.find('.field-preview-value');
                
                if (values.hasOwnProperty(field)) {
                    let value = values[field];
                    if (!value || value === '') {
                        $previewDiv.html('<span style="color: #ccc;">(pusta wartość)</span>');
                    } else {
                        // Truncate long values
                        const displayValue = value.length > 80 ? value.substring(0, 80) + '...' : value;
                        $previewDiv.html('<strong style="color: #646970;">Przykład:</strong> ' + TemplateBuilder.escapeHtml(displayValue));
                    }
                } else {
                    $previewDiv.html('<span style="color: #ccc;">(brak danych)</span>');
                }
            });
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
         * Handle form submit
         */
        handleSubmit: function(e) {
            e.preventDefault();
            
            console.log('handleSubmit called');
            console.log('Selected fields:', this.selectedFields);
            
            if (this.selectedFields.length === 0) {
                alert('Wybierz przynajmniej jedno pole!');
                return;
            }
            
            const templateId = $('#template_id').val();
            const isEditing = templateId && templateId !== '' && templateId !== '0';
            const action = isEditing ? 'update_template' : 'create_template';
            
            const formData = {
                action: action,
                nonce: wooExporterAdmin.nonce,
                name: $('#template_name').val(),
                description: $('#template_description').val(),
                selected_fields: JSON.stringify(this.selectedFields),
                field_aliases: JSON.stringify(this.fieldAliases),
                field_order: JSON.stringify(this.selectedFields)
            };
            
            // Only add template_id if editing
            if (isEditing) {
                formData.template_id = templateId;
            }
            
            $.ajax({
                url: wooExporterAdmin.ajax_url,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        alert('✅ ' + response.data.message);
                        window.location.href = 'admin.php?page=woo-data-exporter&tab=templates';
                    } else {
                        alert('❌ ' + (response.data.message || 'Błąd zapisu'));
                    }
                },
                error: function(xhr) {
                    alert('Błąd połączenia: ' + xhr.status);
                }
            });
        }
    };

    $(document).ready(function() {
        if ($('#template-builder-form').length) {
            TemplateBuilder.init();
            
            // Auto-load preview on page load
            if ($('#preview-order-id').val()) {
                TemplateBuilder.loadPreview();
            }
        }
    });

})(jQuery);

