/**
 * PostyCal Admin JavaScript
 *
 * Handles schedule management, modal interactions, and AJAX operations.
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
            // Modal controls.
            $( '#postycal-add-schedule' ).on( 'click', this.openAddModal.bind( this ) );
            $( '#postycal-cancel' ).on( 'click', this.closeModal.bind( this ) );
            $( '.postycal-modal-backdrop' ).on( 'click', this.closeModal.bind( this ) );

            // Form submission.
            $( '#postycal-schedule-form' ).on( 'submit', this.handleFormSubmit.bind( this ) );

            // Field type toggle.
            $( '#postycal-field-type' ).on( 'change', this.toggleRepeaterOptions.bind( this ) );

            // Trigger cron.
            $( '#postycal-trigger-cron' ).on( 'click', this.triggerCron.bind( this ) );

            // Dynamic event binding for edit/delete.
            $( document ).on( 'click', '.postycal-edit-schedule', this.openEditModal.bind( this ) );
            $( document ).on( 'click', '.postycal-delete-schedule', this.handleDelete.bind( this ) );

            // Escape key to close modal.
            $( document ).on( 'keydown', this.handleEscapeKey.bind( this ) );
        },

        /**
         * Open modal for adding new schedule.
         *
         * @param {Event} e Click event.
         */
        openAddModal: function( e ) {
            e.preventDefault();
            this.resetForm();
            $( '#postycal-modal-title' ).text( this.config.i18n.addSchedule );
            $( '#postycal-schedule-index' ).val( '' );
            $( '#postycal-modal' ).show();
            $( '#postycal-name' ).focus();
        },

        /**
         * Open modal for editing existing schedule.
         *
         * @param {Event} e Click event.
         */
        openEditModal: function( e ) {
            e.preventDefault();
            const index = $( e.currentTarget ).data( 'index' );
            const schedule = this.schedules[ index ];

            if ( ! schedule ) {
                return;
            }

            this.resetForm();
            $( '#postycal-modal-title' ).text( this.config.i18n.editSchedule );
            $( '#postycal-schedule-index' ).val( index );

            // Populate form fields.
            $( '#postycal-name' ).val( schedule.name );
            $( '#postycal-post-type' ).val( schedule.post_type );
            $( '#postycal-taxonomy' ).val( schedule.taxonomy );
            $( '#postycal-date-field' ).val( schedule.date_field );
            $( '#postycal-field-type' ).val( schedule.field_type || 'single' );
            $( '#postycal-sub-field' ).val( schedule.sub_field || '' );
            $( '#postycal-date-logic' ).val( schedule.date_logic || 'earliest' );
            $( '#postycal-upcoming-term' ).val( schedule.upcoming_term );
            $( '#postycal-past-term' ).val( schedule.past_term );
            $( '#postycal-use-time' ).prop( 'checked', schedule.use_time || false );

            // Toggle repeater options.
            this.toggleRepeaterOptions();

            $( '#postycal-modal' ).show();
            $( '#postycal-name' ).focus();
        },

        /**
         * Close the modal.
         */
        closeModal: function() {
            $( '#postycal-modal' ).hide();
            this.resetForm();
        },

        /**
         * Handle escape key press.
         *
         * @param {Event} e Keydown event.
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
            $( '#postycal-field-type' ).val( 'single' );
            $( '#postycal-use-time' ).prop( 'checked', false );
            this.toggleRepeaterOptions();
        },

        /**
         * Toggle repeater-specific options.
         */
        toggleRepeaterOptions: function() {
            const isRepeater = $( '#postycal-field-type' ).val() === 'repeater';
            const $repeaterRow = $( '#postycal-repeater-options' );
            const $logicRow = $( '#postycal-date-logic-row' );
            const $subField = $( '#postycal-sub-field' );

            if ( isRepeater ) {
                $repeaterRow.show();
                $logicRow.show();
                $subField.prop( 'required', true );
            } else {
                $repeaterRow.hide();
                $logicRow.hide();
                $subField.prop( 'required', false );
            }
        },

        /**
         * Handle form submission.
         *
         * @param {Event} e Submit event.
         */
        handleFormSubmit: function( e ) {
            e.preventDefault();

            const $form = $( '#postycal-schedule-form' );
            const $submitBtn = $form.find( 'button[type="submit"]' );
            const originalText = $submitBtn.text();

            $submitBtn.prop( 'disabled', true ).text( this.config.i18n.processing );

            const data = {
                action: 'postycal_save_schedule',
                nonce: this.config.nonce,
                index: $( '#postycal-schedule-index' ).val(),
                name: $( '#postycal-name' ).val(),
                post_type: $( '#postycal-post-type' ).val(),
                taxonomy: $( '#postycal-taxonomy' ).val(),
                date_field: $( '#postycal-date-field' ).val(),
                field_type: $( '#postycal-field-type' ).val(),
                sub_field: $( '#postycal-sub-field' ).val(),
                date_logic: $( '#postycal-date-logic' ).val(),
                upcoming_term: $( '#postycal-upcoming-term' ).val(),
                past_term: $( '#postycal-past-term' ).val(),
                use_time: $( '#postycal-use-time' ).is( ':checked' ) ? '1' : ''
            };

            $.post( this.config.ajaxUrl, data )
                .done( ( response ) => {
                    if ( response.success ) {
                        this.schedules = response.data.schedules;
                        this.refreshTable();
                        this.closeModal();
                        this.showNotice( 'success', response.data.message );
                    } else {
                        this.showNotice( 'error', response.data.message || this.config.i18n.saveError );
                    }
                } )
                .fail( () => {
                    this.showNotice( 'error', this.config.i18n.saveError );
                } )
                .always( () => {
                    $submitBtn.prop( 'disabled', false ).text( originalText );
                } );
        },

        /**
         * Handle schedule deletion.
         *
         * @param {Event} e Click event.
         */
        handleDelete: function( e ) {
            e.preventDefault();

            if ( ! confirm( this.config.i18n.confirmDelete ) ) {
                return;
            }

            const $btn = $( e.currentTarget );
            const index = $btn.data( 'index' );
            const originalText = $btn.text();

            $btn.prop( 'disabled', true ).text( this.config.i18n.processing );

            const data = {
                action: 'postycal_delete_schedule',
                nonce: this.config.nonce,
                index: index
            };

            $.post( this.config.ajaxUrl, data )
                .done( ( response ) => {
                    if ( response.success ) {
                        this.schedules = response.data.schedules;
                        this.refreshTable();
                        this.showNotice( 'success', response.data.message );
                    } else {
                        this.showNotice( 'error', response.data.message || this.config.i18n.deleteError );
                    }
                } )
                .fail( () => {
                    this.showNotice( 'error', this.config.i18n.deleteError );
                } )
                .always( () => {
                    $btn.prop( 'disabled', false ).text( originalText );
                } );
        },

        /**
         * Trigger manual cron run.
         *
         * @param {Event} e Click event.
         */
        triggerCron: function( e ) {
            e.preventDefault();

            const $btn = $( e.currentTarget );
            const originalText = $btn.text();

            $btn.prop( 'disabled', true ).text( this.config.i18n.processing );

            const data = {
                action: 'postycal_trigger_cron',
                nonce: this.config.nonce
            };

            $.post( this.config.ajaxUrl, data )
                .done( ( response ) => {
                    if ( response.success ) {
                        let message = response.data.message;

                        // Add results summary if available.
                        if ( response.data.results ) {
                            const results = response.data.results;
                            const summary = Object.entries( results )
                                .map( ( [ name, count ] ) => name + ': ' + count + ' transitioned' )
                                .join( ', ' );
                            if ( summary ) {
                                message += ' (' + summary + ')';
                            }
                        }

                        this.showNotice( 'success', message );
                    } else {
                        this.showNotice( 'error', response.data.message || this.config.i18n.triggerError );
                    }
                } )
                .fail( () => {
                    this.showNotice( 'error', this.config.i18n.triggerError );
                } )
                .always( () => {
                    $btn.prop( 'disabled', false ).text( originalText );
                } );
        },

        /**
         * Refresh the schedules table.
         */
        refreshTable: function() {
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

            this.schedules.forEach( ( schedule, index ) => {
                let fieldTypeLabel = schedule.field_type === 'repeater'
                    ? 'Repeater (' + ( schedule.date_logic || 'earliest' ) + ')'
                    : 'Single';

                // Add time indicator if enabled.
                if ( schedule.use_time ) {
                    fieldTypeLabel += ' + Time';
                }

                const row =
                    '<tr data-index="' + index + '">' +
                        '<td>' + this.escapeHtml( schedule.name ) + '</td>' +
                        '<td>' + this.escapeHtml( schedule.post_type ) + '</td>' +
                        '<td>' + this.escapeHtml( schedule.taxonomy ) + '</td>' +
                        '<td>' + this.escapeHtml( schedule.date_field ) + '</td>' +
                        '<td>' + this.escapeHtml( fieldTypeLabel ) + '</td>' +
                        '<td>' + this.escapeHtml( schedule.upcoming_term ) + '</td>' +
                        '<td>' + this.escapeHtml( schedule.past_term ) + '</td>' +
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
         *
         * @param {string} type    Notice type (success, error, warning).
         * @param {string} message Notice message.
         */
        showNotice: function( type, message ) {
            // Remove any existing notices.
            $( '.postycal-notice' ).remove();

            const notice =
                '<div class="notice notice-' + type + ' is-dismissible postycal-notice">' +
                    '<p>' + this.escapeHtml( message ) + '</p>' +
                    '<button type="button" class="notice-dismiss">' +
                        '<span class="screen-reader-text">Dismiss this notice.</span>' +
                    '</button>' +
                '</div>';

            $( '.wrap.postycal-settings h1' ).after( notice );

            // Auto-dismiss after 5 seconds.
            setTimeout( function() {
                $( '.postycal-notice' ).fadeOut( 300, function() {
                    $( this ).remove();
                } );
            }, 5000 );

            // Handle dismiss button click.
            $( '.postycal-notice .notice-dismiss' ).on( 'click', function() {
                $( this ).closest( '.postycal-notice' ).fadeOut( 300, function() {
                    $( this ).remove();
                } );
            } );
        },

        /**
         * Escape HTML entities.
         *
         * @param {string} str String to escape.
         * @return {string} Escaped string.
         */
        escapeHtml: function( str ) {
            if ( ! str ) {
                return '';
            }
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
