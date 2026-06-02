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

    const PostyCalAdmin = {

        config: window.postycal || {},
        schedules: [],
        termsCache: {},

        init: function() {
            this.schedules = this.config.schedules || [];
            this.bindEvents();
        },

        bindEvents: function() {
            const self = this;

            // Modal controls.
            $( '#postycal-add-schedule' ).on( 'click', function( e ) { self.openAddModal( e ); } );
            $( '#postycal-cancel' ).on( 'click', function() { self.closeModal(); } );
            $( '.postycal-modal-backdrop' ).on( 'click', function() { self.closeModal(); } );
            $( document ).on( 'keydown', function( e ) { self.handleEscapeKey( e ); } );

            // Form.
            $( '#postycal-schedule-form' ).on( 'submit', function( e ) { self.handleFormSubmit( e ); } );

            // Dynamic term loading.
            $( '#postycal-taxonomy' ).on( 'change', function() { self.handleTaxonomyChange(); } );

            // Trigger cron.
            $( '#postycal-trigger-cron' ).on( 'click', function( e ) { self.triggerCron( e ); } );

            // Edit / delete (delegated — table is re-rendered after AJAX).
            $( document ).on( 'click', '.postycal-edit-schedule', function( e ) { self.openEditModal( e ); } );
            $( document ).on( 'click', '.postycal-delete-schedule', function( e ) { self.handleDelete( e ); } );
        },

        // -----------------------------------------------------------------
        // Modal open / close
        // -----------------------------------------------------------------

        openAddModal: function( e ) {
            e.preventDefault();
            this.resetForm();
            $( '#postycal-modal-title' ).text( this.config.i18n.addSchedule );
            $( '#postycal-schedule-index' ).val( '' );
            $( '#postycal-modal' ).show();
            $( '#postycal-name' ).trigger( 'focus' );
        },

        openEditModal: function( e ) {
            e.preventDefault();
            const self     = this;
            const index    = $( e.currentTarget ).data( 'index' );
            const schedule = this.schedules[ index ];

            if ( ! schedule ) {
                return;
            }

            this.resetForm();
            $( '#postycal-modal-title' ).text( this.config.i18n.editSchedule );
            $( '#postycal-schedule-index' ).val( index );

            // Populate static fields.
            $( '#postycal-name' ).val( schedule.name );
            $( '#postycal-post-type' ).val( schedule.post_type );
            $( '#postycal-use-time' ).prop( 'checked', schedule.use_time || false );

            // Set taxonomy then load its terms before restoring selections.
            $( '#postycal-taxonomy' ).val( schedule.taxonomy );
            this.loadTaxonomyTerms( schedule.taxonomy, function() {
                $( '#postycal-upcoming-term' ).val( schedule.upcoming_term );
                $( '#postycal-active-term' ).val( schedule.active_term );
                $( '#postycal-past-term' ).val( schedule.past_term );
            } );

            $( '#postycal-modal' ).show();
            $( '#postycal-name' ).trigger( 'focus' );
        },

        closeModal: function() {
            $( '#postycal-modal' ).hide();
            this.resetForm();
        },

        handleEscapeKey: function( e ) {
            if ( e.key === 'Escape' && $( '#postycal-modal' ).is( ':visible' ) ) {
                this.closeModal();
            }
        },

        resetForm: function() {
            $( '#postycal-schedule-form' )[ 0 ].reset();
            $( '#postycal-schedule-index' ).val( '' );
            $( '#postycal-use-time' ).prop( 'checked', false );

            const placeholder = '<option value="">' + this.config.i18n.selectTaxonomyFirst + '</option>';
            $( '#postycal-upcoming-term' ).html( placeholder );
            $( '#postycal-active-term' ).html( placeholder );
            $( '#postycal-past-term' ).html( placeholder );
        },

        // -----------------------------------------------------------------
        // Taxonomy / term loading
        // -----------------------------------------------------------------

        handleTaxonomyChange: function() {
            const taxonomy = $( '#postycal-taxonomy' ).val();
            const placeholder = '<option value="">' + this.config.i18n.selectTaxonomyFirst + '</option>';

            if ( ! taxonomy ) {
                $( '#postycal-upcoming-term, #postycal-active-term, #postycal-past-term' ).html( placeholder );
                return;
            }

            this.loadTaxonomyTerms( taxonomy );
        },

        loadTaxonomyTerms: function( taxonomy, callback ) {
            const self     = this;
            const $spinner = $( '#postycal-terms-spinner' );
            const loading  = '<option value="">' + this.config.i18n.loading + '</option>';

            if ( this.termsCache[ taxonomy ] ) {
                this.populateTermDropdowns( this.termsCache[ taxonomy ] );
                if ( callback ) callback();
                return;
            }

            $( '#postycal-upcoming-term, #postycal-active-term, #postycal-past-term' ).html( loading );
            $spinner.addClass( 'is-active' );

            $.post( this.config.ajaxUrl, {
                action: 'postycal_get_taxonomy_terms',
                nonce: this.config.nonce,
                taxonomy: taxonomy
            } )
            .done( function( response ) {
                if ( response.success ) {
                    self.termsCache[ taxonomy ] = response.data.terms;
                    self.populateTermDropdowns( response.data.terms );
                } else {
                    const err = '<option value="">' + self.config.i18n.noTermsFound + '</option>';
                    $( '#postycal-upcoming-term, #postycal-active-term, #postycal-past-term' ).html( err );
                }
                if ( callback ) callback();
            } )
            .fail( function() {
                const err = '<option value="">' + self.config.i18n.errorLoadingTerms + '</option>';
                $( '#postycal-upcoming-term, #postycal-active-term, #postycal-past-term' ).html( err );
            } )
            .always( function() {
                $spinner.removeClass( 'is-active' );
            } );
        },

        populateTermDropdowns: function( terms ) {
            const self = this;
            let html   = '<option value="">' + this.config.i18n.selectTerm + '</option>';

            if ( ! terms || terms.length === 0 ) {
                html = '<option value="">' + this.config.i18n.noTermsFound + '</option>';
            } else {
                terms.forEach( function( term ) {
                    html += '<option value="' + self.escapeHtml( term.slug ) + '">';
                    html += self.escapeHtml( term.name ) + ' (' + self.escapeHtml( term.slug ) + ')';
                    html += '</option>';
                } );
            }

            $( '#postycal-upcoming-term' ).html( html );
            $( '#postycal-active-term' ).html( html );
            $( '#postycal-past-term' ).html( html );
        },

        // -----------------------------------------------------------------
        // Form submission
        // -----------------------------------------------------------------

        handleFormSubmit: function( e ) {
            e.preventDefault();

            const self       = this;
            const $form      = $( '#postycal-schedule-form' );
            const $submitBtn = $form.find( 'button[type="submit"]' );
            const origText   = $submitBtn.text();

            $submitBtn.prop( 'disabled', true ).text( this.config.i18n.processing );

            const data = {
                action:        'postycal_save_schedule',
                nonce:         this.config.nonce,
                index:         $( '#postycal-schedule-index' ).val(),
                name:          $( '#postycal-name' ).val(),
                post_type:     $( '#postycal-post-type' ).val(),
                taxonomy:      $( '#postycal-taxonomy' ).val(),
                upcoming_term: $( '#postycal-upcoming-term' ).val(),
                active_term:   $( '#postycal-active-term' ).val(),
                past_term:     $( '#postycal-past-term' ).val(),
                use_time:      $( '#postycal-use-time' ).is( ':checked' ) ? '1' : ''
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
                    $submitBtn.prop( 'disabled', false ).text( origText );
                } );
        },

        // -----------------------------------------------------------------
        // Delete
        // -----------------------------------------------------------------

        handleDelete: function( e ) {
            e.preventDefault();

            if ( ! confirm( this.config.i18n.confirmDelete ) ) {
                return;
            }

            const self    = this;
            const $btn    = $( e.currentTarget );
            const index   = $btn.data( 'index' );
            const origTxt = $btn.text();

            $btn.prop( 'disabled', true ).text( this.config.i18n.processing );

            $.post( this.config.ajaxUrl, {
                action: 'postycal_delete_schedule',
                nonce:  this.config.nonce,
                index:  index
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
                $btn.prop( 'disabled', false ).text( origTxt );
            } );
        },

        // -----------------------------------------------------------------
        // Manual cron trigger
        // -----------------------------------------------------------------

        triggerCron: function( e ) {
            e.preventDefault();

            const self    = this;
            const $btn    = $( e.currentTarget );
            const origTxt = $btn.text();

            $btn.prop( 'disabled', true ).text( this.config.i18n.processing );

            $.post( this.config.ajaxUrl, {
                action: 'postycal_trigger_cron',
                nonce:  this.config.nonce
            } )
            .done( function( response ) {
                if ( response.success ) {
                    let message = response.data.message;

                    if ( response.data.results ) {
                        const parts = [];
                        $.each( response.data.results, function( name, counts ) {
                            const detail = name + ': '
                                + counts.published + ' ' + self.config.i18n.published + ', '
                                + counts.expired + ' ' + self.config.i18n.expired;
                            parts.push( detail );
                        } );
                        if ( parts.length ) {
                            message += ' (' + parts.join( ' | ' ) + ')';
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
                $btn.prop( 'disabled', false ).text( origTxt );
            } );
        },

        // -----------------------------------------------------------------
        // Table refresh
        // -----------------------------------------------------------------

        refreshTable: function() {
            const self  = this;
            const $tbody = $( '#postycal-schedules-table tbody' );
            $tbody.empty();

            if ( this.schedules.length === 0 ) {
                $tbody.append(
                    '<tr class="no-items"><td colspan="7">' +
                    this.escapeHtml( this.config.i18n.noSchedules ) +
                    '</td></tr>'
                );
                $( '#postycal-trigger-cron' ).hide();
                return;
            }

            $( '#postycal-trigger-cron' ).show();

            this.schedules.forEach( function( schedule, index ) {
                const timeLabel = schedule.use_time ? ' (time-aware)' : '';
                const row =
                    '<tr data-index="' + index + '">' +
                        '<td>' + self.escapeHtml( schedule.name ) + '</td>' +
                        '<td>' + self.escapeHtml( schedule.post_type ) + '</td>' +
                        '<td>' + self.escapeHtml( schedule.taxonomy + timeLabel ) + '</td>' +
                        '<td>' + self.escapeHtml( schedule.upcoming_term ) + '</td>' +
                        '<td>' + self.escapeHtml( schedule.active_term ) + '</td>' +
                        '<td>' + self.escapeHtml( schedule.past_term ) + '</td>' +
                        '<td>' +
                            '<button type="button" class="button postycal-edit-schedule" data-index="' + index + '">' +
                                self.escapeHtml( self.config.i18n.editButton ) +
                            '</button> ' +
                            '<button type="button" class="button postycal-delete-schedule" data-index="' + index + '">' +
                                self.escapeHtml( self.config.i18n.deleteButton ) +
                            '</button>' +
                        '</td>' +
                    '</tr>';
                $tbody.append( row );
            } );
        },

        // -----------------------------------------------------------------
        // Notices
        // -----------------------------------------------------------------

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
                $( '.postycal-notice' ).fadeOut( 300, function() { $( this ).remove(); } );
            }, 5000 );

            $( '.postycal-notice .notice-dismiss' ).on( 'click', function() {
                $( this ).closest( '.postycal-notice' ).fadeOut( 300, function() { $( this ).remove(); } );
            } );
        },

        // -----------------------------------------------------------------
        // Utility
        // -----------------------------------------------------------------

        escapeHtml: function( str ) {
            if ( ! str && str !== 0 ) return '';
            const div = document.createElement( 'div' );
            div.appendChild( document.createTextNode( String( str ) ) );
            return div.innerHTML;
        }
    };

    $( document ).ready( function() {
        PostyCalAdmin.init();
    } );

} )( jQuery );
