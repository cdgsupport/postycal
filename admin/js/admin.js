/**
 * PostyCal Admin JavaScript
 *
 * Handles schedule management, modal interactions, AJAX operations,
 * and dynamic loading of ACF fields and taxonomy terms.
 *
 * @package PostyCal
 * @since 2.0.0
 */

( function( $ ) {
    'use strict';

    /**
     * PostyCal Admin module.
     */
    const PostyCalAdmin = {
        /**
         * Configuration from PHP.
         */
        config: window.postycal || {},

        /**
         * Cached schedules.
         */
        schedules: [],

        /**
         * Cached ACF fields by post type.
         */
        fieldsCache: {},

        /**
         * Cached terms by taxonomy.
         */
        termsCache: {},

        /**
         * Current editing schedule.
         */
        editingSchedule: null,

        /**
         * Initialize the admin module.
         */
        init: function() {
            this.schedules = this.config.schedules || [];
            this.bindEvents();
        },

        /**
         * Bind event handlers.
         */
        bindEvents: function() {
            const self = this;

            // Modal controls.
            $( '#postycal-add-schedule' ).on( 'click', function( e ) { self.openAddModal( e ); } );
            $( '#postycal-cancel' ).on( 'click', function() { self.closeModal(); } );
            $( '.postycal-modal-backdrop' ).on( 'click', function() { self.closeModal(); } );

            // Form submission.
            $( '#postycal-schedule-form' ).on( 'submit', function( e ) { self.handleFormSubmit( e ); } );

            // Dynamic field loading.
            $( '#postycal-post-type' ).on( 'change', function() { self.handlePostTypeChange(); } );
            $( '#postycal-taxonomy' ).on( 'change', function() { self.handleTaxonomyChange(); } );
            $( '#postycal-date-field' ).on( 'change', function() { self.handleDateFieldChange(); } );

            // Manual entry toggle.
            $( '#postycal-manual-field-entry' ).on( 'change', function() { self.toggleManualFieldEntry(); } );

            // Trigger cron.
            $( '#postycal-trigger-cron' ).on( 'click', function( e ) { self.triggerCron( e ); } );

            // Dynamic event binding for edit/delete.
            $( document ).on( 'click', '.postycal-edit-schedule', function( e ) { self.openEditModal( e ); } );
            $( document ).on( 'click', '.postycal-delete-schedule', function( e ) { self.handleDelete( e ); } );

            // Escape key to close modal.
            $( document ).on( 'keydown', function( e ) { self.handleEscapeKey( e ); } );
        },

        /**
         * Open modal for adding new schedule.
         */
        openAddModal: function( e ) {
            e.preventDefault();
            this.editingSchedule = null;
            this.resetForm();
            $( '#postycal-modal-title' ).text( this.config.i18n.addSchedule );
            $( '#postycal-schedule-index' ).val( '' );
            $( '#postycal-modal' ).show();
            $( '#postycal-name' ).focus();
        },

        /**
         * Open modal for editing existing schedule.
         */
        openEditModal: function( e ) {
            e.preventDefault();
            const index = $( e.currentTarget ).data( 'index' );
            const schedule = this.schedules[ index ];

            if ( ! schedule ) {
                return;
            }

            const self = this;
            this.editingSchedule = schedule;
            this.resetForm();
            $( '#postycal-modal-title' ).text( this.config.i18n.editSchedule );
            $( '#postycal-schedule-index' ).val( index );

            // Populate basic fields.
            $( '#postycal-name' ).val( schedule.name );
            $( '#postycal-post-type' ).val( schedule.post_type );
            $( '#postycal-taxonomy' ).val( schedule.taxonomy );
            $( '#postycal-date-logic' ).val( schedule.date_logic || 'earliest' );
            $( '#postycal-use-time' ).prop( 'checked', schedule.use_time || false );

            // Load ACF fields for the post type.
            this.loadAcfFields( schedule.post_type, function() {
                const $dateField = $( '#postycal-date-field' );
                
                if ( $dateField.find( 'option[value="' + schedule.date_field + '"]' ).length > 0 ) {
                    $dateField.val( schedule.date_field );
                    self.handleDateFieldChange();
                    
                    if ( schedule.field_type === 'repeater' && schedule.sub_field ) {
                        $( '#postycal-sub-field' ).val( schedule.sub_field );
                    }
                } else {
                    // Field not found - enable manual entry.
                    $( '#postycal-manual-field-entry' ).prop( 'checked', true ).trigger( 'change' );
                    $( '#postycal-date-field-manual' ).val( schedule.date_field );
                    
                    if ( schedule.field_type === 'repeater' ) {
                        $( '#postycal-sub-field-row' ).show();
                        $( '#postycal-date-logic-row' ).show();
                        $( '#postycal-sub-field-manual' ).show().val( schedule.sub_field );
                        $( '#postycal-sub-field' ).hide();
                    }
                }
            } );

            // Load taxonomy terms.
            this.loadTaxonomyTerms( schedule.taxonomy, function() {
                $( '#postycal-upcoming-term' ).val( schedule.upcoming_term );
                $( '#postycal-past-term' ).val( schedule.past_term );
            } );

            $( '#postycal-modal' ).show();
            $( '#postycal-name' ).focus();
        },

        /**
         * Close the modal.
         */
        closeModal: function() {
            $( '#postycal-modal' ).hide();
            this.editingSchedule = null;
            this.resetForm();
        },

        /**
         * Handle escape key press.
         */
        handleEscapeKey: function( e ) {
            if ( e.key === 'Escape' && $( '#postycal-modal' ).is( ':visible' ) ) {
                this.closeModal();
            }
        },

        /**
         * Reset the form.
         */
        resetForm: function() {
            $( '#postycal-schedule-form' )[ 0 ].reset();
            $( '#postycal-schedule-index' ).val( '' );
            $( '#postycal-use-time' ).prop( 'checked', false );
            $( '#postycal-manual-field-entry' ).prop( 'checked', false );
            
            // Reset date field dropdown.
            $( '#postycal-date-field' ).html( '<option value="">' + this.config.i18n.selectPostTypeFirst + '</option>' ).show();
            $( '#postycal-date-field-manual' ).hide().val( '' );
            $( '.postycal-manual-entry' ).hide();
            
            // Reset sub-field.
            $( '#postycal-sub-field' ).html( '<option value="">' + this.config.i18n.selectRepeaterFirst + '</option>' ).show();
            $( '#postycal-sub-field-manual' ).hide().val( '' );
            $( '#postycal-sub-field-row' ).hide();
            $( '#postycal-date-logic-row' ).hide();
            
            // Reset term dropdowns.
            $( '#postycal-upcoming-term' ).html( '<option value="">' + this.config.i18n.selectTaxonomyFirst + '</option>' );
            $( '#postycal-past-term' ).html( '<option value="">' + this.config.i18n.selectTaxonomyFirst + '</option>' );
        },

        /**
         * Handle post type selection change.
         */
        handlePostTypeChange: function() {
            const postType = $( '#postycal-post-type' ).val();
            
            if ( ! postType ) {
                $( '#postycal-date-field' ).html( '<option value="">' + this.config.i18n.selectPostTypeFirst + '</option>' );
                $( '.postycal-manual-entry' ).hide();
                return;
            }

            this.loadAcfFields( postType );
        },

        /**
         * Load ACF fields for a post type.
         */
        loadAcfFields: function( postType, callback ) {
            const self = this;
            const $select = $( '#postycal-date-field' );
            const $spinner = $( '#postycal-fields-spinner' );

            // Check cache first.
            if ( this.fieldsCache[ postType ] ) {
                this.populateFieldsDropdown( this.fieldsCache[ postType ] );
                if ( callback ) callback();
                return;
            }

            $select.html( '<option value="">' + this.config.i18n.loading + '</option>' );
            $spinner.addClass( 'is-active' );

            $.post( this.config.ajaxUrl, {
                action: 'postycal_get_acf_fields',
                nonce: this.config.nonce,
                post_type: postType
            } )
            .done( function( response ) {
                if ( response.success ) {
                    self.fieldsCache[ postType ] = response.data.fields;
                    self.populateFieldsDropdown( response.data.fields );
                } else {
                    $select.html( '<option value="">' + self.config.i18n.noFieldsFound + '</option>' );
                }
                if ( callback ) callback();
            } )
            .fail( function() {
                $select.html( '<option value="">' + self.config.i18n.errorLoadingFields + '</option>' );
            } )
            .always( function() {
                $spinner.removeClass( 'is-active' );
            } );
        },

        /**
         * Populate the fields dropdown.
         */
        populateFieldsDropdown: function( fields ) {
            const self = this;
            const $select = $( '#postycal-date-field' );
            let html = '<option value="">' + this.config.i18n.selectDateField + '</option>';

            if ( ! fields || fields.length === 0 ) {
                html = '<option value="">' + this.config.i18n.noFieldsFound + '</option>';
                $select.html( html );
                $( '.postycal-manual-entry' ).show();
                return;
            }

            // Group fields by type.
            const dateFields = fields.filter( function( f ) {
                return f.type === 'date_picker' || f.type === 'date_time_picker';
            } );
            const repeaterFields = fields.filter( function( f ) {
                return f.type === 'repeater' && f.sub_fields && f.sub_fields.length > 0;
            } );

            // Add date fields.
            if ( dateFields.length > 0 ) {
                html += '<optgroup label="' + this.config.i18n.dateFields + '">';
                dateFields.forEach( function( field ) {
                    const typeLabel = field.type === 'date_time_picker' ? ' (DateTime)' : ' (Date)';
                    html += '<option value="' + self.escapeHtml( field.name ) + '" data-type="single" data-field-type="' + field.type + '">';
                    html += self.escapeHtml( field.label ) + typeLabel + ' [' + self.escapeHtml( field.group ) + ']';
                    html += '</option>';
                } );
                html += '</optgroup>';
            }

            // Add repeater fields.
            if ( repeaterFields.length > 0 ) {
                html += '<optgroup label="' + this.config.i18n.repeaterFields + '">';
                repeaterFields.forEach( function( field ) {
                    html += '<option value="' + self.escapeHtml( field.name ) + '" data-type="repeater" data-subfields=\'' + JSON.stringify( field.sub_fields ) + '\'>';
                    html += self.escapeHtml( field.label ) + ' (Repeater) [' + self.escapeHtml( field.group ) + ']';
                    html += '</option>';
                } );
                html += '</optgroup>';
            }

            $select.html( html );
            $( '.postycal-manual-entry' ).show();
        },

        /**
         * Handle date field selection change.
         */
        handleDateFieldChange: function() {
            const $selected = $( '#postycal-date-field option:selected' );
            const fieldType = $selected.data( 'type' );
            const acfFieldType = $selected.data( 'field-type' );
            
            // Auto-check use_time for datetime fields.
            if ( acfFieldType === 'date_time_picker' ) {
                $( '#postycal-use-time' ).prop( 'checked', true );
            }

            if ( fieldType === 'repeater' ) {
                $( '#postycal-sub-field-row' ).show();
                $( '#postycal-date-logic-row' ).show();
                const subFields = $selected.data( 'subfields' );
                this.populateSubFieldsDropdown( subFields );
            } else {
                $( '#postycal-sub-field-row' ).hide();
                $( '#postycal-date-logic-row' ).hide();
            }
        },

        /**
         * Populate the sub-fields dropdown for repeaters.
         */
        populateSubFieldsDropdown: function( subFields ) {
            const self = this;
            const $select = $( '#postycal-sub-field' );
            let html = '<option value="">' + this.config.i18n.selectSubField + '</option>';

            if ( subFields && subFields.length > 0 ) {
                subFields.forEach( function( field ) {
                    const typeLabel = field.type === 'date_time_picker' ? ' (DateTime)' : ' (Date)';
                    html += '<option value="' + self.escapeHtml( field.name ) + '" data-field-type="' + field.type + '">';
                    html += self.escapeHtml( field.label ) + typeLabel;
                    html += '</option>';
                } );
            }

            $select.html( html );
        },

        /**
         * Toggle manual field entry mode.
         */
        toggleManualFieldEntry: function() {
            const isManual = $( '#postycal-manual-field-entry' ).is( ':checked' );
            
            if ( isManual ) {
                $( '#postycal-date-field' ).hide();
                $( '#postycal-date-field-manual' ).show();
                $( '#postycal-sub-field' ).hide();
                $( '#postycal-sub-field-manual' ).show();
                $( '#postycal-sub-field-row' ).show();
                $( '#postycal-date-logic-row' ).show();
            } else {
                $( '#postycal-date-field' ).show();
                $( '#postycal-date-field-manual' ).hide();
                $( '#postycal-sub-field' ).show();
                $( '#postycal-sub-field-manual' ).hide();
                this.handleDateFieldChange();
            }
        },

        /**
         * Handle taxonomy selection change.
         */
        handleTaxonomyChange: function() {
            const taxonomy = $( '#postycal-taxonomy' ).val();
            
            if ( ! taxonomy ) {
                $( '#postycal-upcoming-term' ).html( '<option value="">' + this.config.i18n.selectTaxonomyFirst + '</option>' );
                $( '#postycal-past-term' ).html( '<option value="">' + this.config.i18n.selectTaxonomyFirst + '</option>' );
                return;
            }

            this.loadTaxonomyTerms( taxonomy );
        },

        /**
         * Load terms for a taxonomy.
         */
        loadTaxonomyTerms: function( taxonomy, callback ) {
            const self = this;
            const $upcomingSelect = $( '#postycal-upcoming-term' );
            const $pastSelect = $( '#postycal-past-term' );
            const $spinner = $( '#postycal-terms-spinner' );

            // Check cache first.
            if ( this.termsCache[ taxonomy ] ) {
                this.populateTermsDropdowns( this.termsCache[ taxonomy ] );
                if ( callback ) callback();
                return;
            }

            $upcomingSelect.html( '<option value="">' + this.config.i18n.loading + '</option>' );
            $pastSelect.html( '<option value="">' + this.config.i18n.loading + '</option>' );
            $spinner.addClass( 'is-active' );

            $.post( this.config.ajaxUrl, {
                action: 'postycal_get_taxonomy_terms',
                nonce: this.config.nonce,
                taxonomy: taxonomy
            } )
            .done( function( response ) {
                if ( response.success ) {
                    self.termsCache[ taxonomy ] = response.data.terms;
                    self.populateTermsDropdowns( response.data.terms );
                } else {
                    const errorHtml = '<option value="">' + self.config.i18n.noTermsFound + '</option>';
                    $upcomingSelect.html( errorHtml );
                    $pastSelect.html( errorHtml );
                }
                if ( callback ) callback();
            } )
            .fail( function() {
                const errorHtml = '<option value="">' + self.config.i18n.errorLoadingTerms + '</option>';
                $upcomingSelect.html( errorHtml );
                $pastSelect.html( errorHtml );
            } )
            .always( function() {
                $spinner.removeClass( 'is-active' );
            } );
        },

        /**
         * Populate term dropdowns.
         */
        populateTermsDropdowns: function( terms ) {
            const self = this;
            const $upcomingSelect = $( '#postycal-upcoming-term' );
            const $pastSelect = $( '#postycal-past-term' );
            
            let html = '<option value="">' + this.config.i18n.selectTerm + '</option>';

            if ( ! terms || terms.length === 0 ) {
                html = '<option value="">' + this.config.i18n.noTermsFound + '</option>';
            } else {
                terms.forEach( function( term ) {
                    html += '<option value="' + self.escapeHtml( term.slug ) + '">';
                    html += self.escapeHtml( term.name ) + ' (' + self.escapeHtml( term.slug ) + ')';
                    html += '</option>';
                } );
            }

            $upcomingSelect.html( html );
            $pastSelect.html( html );
        },

        /**
         * Handle form submission.
         */
        handleFormSubmit: function( e ) {
            e.preventDefault();

            const self = this;
            const $form = $( '#postycal-schedule-form' );
            const $submitBtn = $form.find( 'button[type="submit"]' );
            const originalText = $submitBtn.text();

            $submitBtn.prop( 'disabled', true ).text( this.config.i18n.processing );

            // Determine field values based on manual entry mode.
            const isManual = $( '#postycal-manual-field-entry' ).is( ':checked' );
            const dateField = isManual ? $( '#postycal-date-field-manual' ).val() : $( '#postycal-date-field' ).val();
            const subField = isManual ? $( '#postycal-sub-field-manual' ).val() : $( '#postycal-sub-field' ).val();
            
            // Determine field type.
            let fieldType = 'single';
            if ( isManual ) {
                fieldType = subField ? 'repeater' : 'single';
            } else {
                const $selectedField = $( '#postycal-date-field option:selected' );
                fieldType = $selectedField.data( 'type' ) || 'single';
            }

            const data = {
                action: 'postycal_save_schedule',
                nonce: this.config.nonce,
                index: $( '#postycal-schedule-index' ).val(),
                name: $( '#postycal-name' ).val(),
                post_type: $( '#postycal-post-type' ).val(),
                taxonomy: $( '#postycal-taxonomy' ).val(),
                date_field: dateField,
                field_type: fieldType,
                sub_field: subField,
                date_logic: $( '#postycal-date-logic' ).val(),
                upcoming_term: $( '#postycal-upcoming-term' ).val(),
                past_term: $( '#postycal-past-term' ).val(),
                use_time: $( '#postycal-use-time' ).is( ':checked' ) ? '1' : ''
            };

            $.post( this.config.ajaxUrl, data )
                .done( function( response ) {
                    if ( response.success ) {
                        self.schedules = response.data.schedules;
                        self.refreshTable();
                        self.closeModal();
                        self.showNotice( 'success', response.data.message );
                    } else {
                        self.showNotice( 'error', response.data.message || self.config.i18n.saveError );
                    }
                } )
                .fail( function() {
                    self.showNotice( 'error', self.config.i18n.saveError );
                } )
                .always( function() {
                    $submitBtn.prop( 'disabled', false ).text( originalText );
                } );
        },

        /**
         * Handle schedule deletion.
         */
        handleDelete: function( e ) {
            e.preventDefault();

            if ( ! confirm( this.config.i18n.confirmDelete ) ) {
                return;
            }

            const self = this;
            const $btn = $( e.currentTarget );
            const index = $btn.data( 'index' );
            const originalText = $btn.text();

            $btn.prop( 'disabled', true ).text( this.config.i18n.processing );

            $.post( this.config.ajaxUrl, {
                action: 'postycal_delete_schedule',
                nonce: this.config.nonce,
                index: index
            } )
            .done( function( response ) {
                if ( response.success ) {
                    self.schedules = response.data.schedules;
                    self.refreshTable();
                    self.showNotice( 'success', response.data.message );
                } else {
                    self.showNotice( 'error', response.data.message || self.config.i18n.deleteError );
                }
            } )
            .fail( function() {
                self.showNotice( 'error', self.config.i18n.deleteError );
            } )
            .always( function() {
                $btn.prop( 'disabled', false ).text( originalText );
            } );
        },

        /**
         * Trigger manual cron run.
         */
        triggerCron: function( e ) {
            e.preventDefault();

            const self = this;
            const $btn = $( e.currentTarget );
            const originalText = $btn.text();

            $btn.prop( 'disabled', true ).text( this.config.i18n.processing );

            $.post( this.config.ajaxUrl, {
                action: 'postycal_trigger_cron',
                nonce: this.config.nonce
            } )
            .done( function( response ) {
                if ( response.success ) {
                    let message = response.data.message;

                    if ( response.data.results ) {
                        const results = response.data.results;
                        const summary = Object.entries( results )
                            .map( function( entry ) {
                                return entry[0] + ': ' + entry[1] + ' transitioned';
                            } )
                            .join( ', ' );
                        if ( summary ) {
                            message += ' (' + summary + ')';
                        }
                    }

                    self.showNotice( 'success', message );
                } else {
                    self.showNotice( 'error', response.data.message || self.config.i18n.triggerError );
                }
            } )
            .fail( function() {
                self.showNotice( 'error', self.config.i18n.triggerError );
            } )
            .always( function() {
                $btn.prop( 'disabled', false ).text( originalText );
            } );
        },

        /**
         * Refresh the schedules table.
         */
        refreshTable: function() {
            const self = this;
            const $tbody = $( '#postycal-schedules-table tbody' );
            $tbody.empty();

            if ( this.schedules.length === 0 ) {
                $tbody.append(
                    '<tr class="no-items"><td colspan="8">' +
                    'No schedules configured. Click "Add New Schedule" to create one.' +
                    '</td></tr>'
                );
                $( '#postycal-trigger-cron' ).hide();
                return;
            }

            $( '#postycal-trigger-cron' ).show();

            this.schedules.forEach( function( schedule, index ) {
                let fieldTypeLabel = schedule.field_type === 'repeater'
                    ? 'Repeater (' + ( schedule.date_logic || 'earliest' ) + ')'
                    : 'Single';

                if ( schedule.use_time ) {
                    fieldTypeLabel += ' + Time';
                }

                const row =
                    '<tr data-index="' + index + '">' +
                        '<td>' + self.escapeHtml( schedule.name ) + '</td>' +
                        '<td>' + self.escapeHtml( schedule.post_type ) + '</td>' +
                        '<td>' + self.escapeHtml( schedule.taxonomy ) + '</td>' +
                        '<td>' + self.escapeHtml( schedule.date_field ) + '</td>' +
                        '<td>' + self.escapeHtml( fieldTypeLabel ) + '</td>' +
                        '<td>' + self.escapeHtml( schedule.upcoming_term ) + '</td>' +
                        '<td>' + self.escapeHtml( schedule.past_term ) + '</td>' +
                        '<td>' +
                            '<button type="button" class="button postycal-edit-schedule" data-index="' + index + '">' +
                                'Edit' +
                            '</button> ' +
                            '<button type="button" class="button postycal-delete-schedule" data-index="' + index + '">' +
                                'Delete' +
                            '</button>' +
                        '</td>' +
                    '</tr>';
                $tbody.append( row );
            } );
        },

        /**
         * Show admin notice.
         */
        showNotice: function( type, message ) {
            $( '.postycal-notice' ).remove();

            const notice =
                '<div class="notice notice-' + type + ' is-dismissible postycal-notice">' +
                    '<p>' + this.escapeHtml( message ) + '</p>' +
                    '<button type="button" class="notice-dismiss">' +
                        '<span class="screen-reader-text">Dismiss this notice.</span>' +
                    '</button>' +
                '</div>';

            $( '.wrap.postycal-settings h1' ).after( notice );

            setTimeout( function() {
                $( '.postycal-notice' ).fadeOut( 300, function() {
                    $( this ).remove();
                } );
            }, 5000 );

            $( '.postycal-notice .notice-dismiss' ).on( 'click', function() {
                $( this ).closest( '.postycal-notice' ).fadeOut( 300, function() {
                    $( this ).remove();
                } );
            } );
        },

        /**
         * Escape HTML entities.
         */
        escapeHtml: function( str ) {
            if ( ! str ) return '';
            const div = document.createElement( 'div' );
            div.appendChild( document.createTextNode( str ) );
            return div.innerHTML;
        }
    };

    // Initialize on document ready.
    $( document ).ready( function() {
        PostyCalAdmin.init();
    } );

} )( jQuery );
