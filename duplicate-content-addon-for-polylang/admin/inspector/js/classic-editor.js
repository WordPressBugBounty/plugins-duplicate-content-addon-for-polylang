/**
 * Translation Inspector - Classic Editor metabox and the "what changed" dialog.
 *
 * Also drives the "See what changed" button on the Inspector dashboard.
 */
( function( $ ) {
	'use strict';

	const config = window.dupcapClassicData || {};
	const i18n = config.i18n || {};
	const speak = ( window.wp && window.wp.a11y && window.wp.a11y.speak ) ? window.wp.a11y.speak : function() {};
	const sprintf = ( window.wp && window.wp.i18n && window.wp.i18n.sprintf ) ? window.wp.i18n.sprintf : function( format, value ) {
		return format.replace( '%s', value );
	};

	let currentSplitView = true;
	let activeTranslationId = 0;
	let lastFocused = null;

	function typeLabel( type ) {
		return { changed: i18n.typeChanged, added: i18n.typeAdded, removed: i18n.typeRemoved }[ type ] || type;
	}

	function closeModal() {
		$( '.dupcap-diff-modal-overlay' ).remove();
		if ( lastFocused && document.body.contains( lastFocused ) ) {
			lastFocused.focus();
		}
		lastFocused = null;
	}

	function showModalError( $overlay, message ) {
		$overlay.find( '.dupcap-diff-modal-body' ).empty().append(
			$( '<p class="dupcap-diff-error" role="alert"></p>' ).text( message || i18n.diffError )
		);
	}

	/**
	 * Build and show the dialog, then load the diff into it.
	 *
	 * @param {number}  translationId Translation post ID.
	 * @param {boolean} splitView     Side-by-side (true) or inline (false).
	 */
	function showDiffModal( translationId, splitView ) {
		activeTranslationId = translationId;
		currentSplitView = typeof splitView === 'boolean' ? splitView : true;

		if ( ! $( '.dupcap-diff-modal-overlay' ).length ) {
			lastFocused = document.activeElement;
		}
		$( '.dupcap-diff-modal-overlay' ).remove();

		const $overlay = $( '<div class="dupcap-diff-modal-overlay"></div>' );
		const $modal = $( '<div class="dupcap-diff-modal" role="dialog" aria-modal="true" aria-labelledby="dupcap-diff-modal-title" tabindex="-1"></div>' );

		const $header = $( '<div class="dupcap-diff-modal-header"></div>' )
			.append( $( '<h2 class="dupcap-diff-modal-title" id="dupcap-diff-modal-title"></h2>' ).text( i18n.diffModalTitle ) )
			.append( $( '<button type="button" class="dupcap-diff-modal-close"></button>' ).attr( 'aria-label', i18n.close ).text( '×' ) );

		const $toolbar = $( '<div class="dupcap-diff-modal-toolbar"></div>' ).append(
			$( '<div class="dupcap-diff-view-toggle" role="group"></div>' ).attr( 'aria-label', i18n.diffModalTitle )
				.append( $( '<button type="button" class="dupcap-toggle-split"></button>' ).text( i18n.sideBySide ).toggleClass( 'active', currentSplitView ).attr( 'aria-pressed', currentSplitView ? 'true' : 'false' ) )
				.append( $( '<button type="button" class="dupcap-toggle-unified"></button>' ).text( i18n.inline ).toggleClass( 'active', ! currentSplitView ).attr( 'aria-pressed', currentSplitView ? 'false' : 'true' ) )
		);

		const $intro = $( '<div class="dupcap-diff-modal-intro"></div>' );
		const $body = $( '<div class="dupcap-diff-modal-body" aria-live="polite"></div>' )
			.append( $( '<p class="dupcap-diff-modal-loading"></p>' ).append( '<span class="spinner is-active"></span>' ).append( document.createTextNode( ' ' + i18n.diffLoading ) ) );

		const $footer = $( '<div class="dupcap-diff-modal-footer"></div>' )
			.append( $( '<a class="button dupcap-modal-open-source" target="_blank" rel="noopener noreferrer"></a>' ).text( i18n.openSource ).hide() )
			.append( $( '<button type="button" class="button button-primary dupcap-modal-mark-synced"></button>' ).text( i18n.markSynced ).hide() )
			.append( $( '<button type="button" class="button button-secondary dupcap-modal-btn-close"></button>' ).text( i18n.close ) );

		$modal.append( $header, $toolbar, $intro, $body, $footer );
		$overlay.append( $modal );
		$( 'body' ).append( $overlay );
		$modal.trigger( 'focus' );

		$.ajax( {
			url: config.ajaxUrl || window.ajaxurl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'dupcap_editor_get_diff',
				translation_id: translationId,
				split: currentSplitView ? 'true' : 'false',
				nonce: config.nonce
			}
		} ).done( function( res ) {
			if ( ! res || ! res.success || ! res.data ) {
				showModalError( $overlay, res && res.data && res.data.message );
				return;
			}

			const data = res.data;
			$intro.empty().append( $( '<p></p>' ).append( $( '<strong></strong>' ).text( data.source_title ) )
				.append( document.createTextNode( data.baseline_human ? ' — ' + sprintf( i18n.comparedWith, data.baseline_human ) : '' ) ) );
			if ( data.baseline_date ) {
				$intro.append( $( '<p class="description"></p>' ).text( data.baseline_date ) );
			}
			if ( data.partial ) {
				$intro.append( $( '<p class="description"></p>' ).text( i18n.partialNote ) );
			}

			$body.empty();
			if ( data.fields && data.fields.length > 0 ) {
				data.fields.forEach( function( item ) {
					const $block = $( '<div class="dupcap-diff-field-block"></div>' );
					$block.append(
						$( '<h3 class="dupcap-diff-field-title"></h3>' )
							.append( document.createTextNode( item.label + ' ' ) )
							.append( $( '<span></span>' ).addClass( 'dupcap-change-tag dupcap-change-tag--' + item.type ).text( typeLabel( item.type ) ) )
					);
					if ( item.truncated ) {
						$block.append( $( '<p class="description"></p>' ).text( i18n.truncatedNote ) );
					}
					// The diff table is built and sanitized on the server (wp_text_diff + wp_kses).
					$block.append( $( '<div class="dupcap-diff-table-wrap"></div>' ).html( item.diff ) );
					$body.append( $block );
				} );
			} else {
				$body.append( $( '<p class="dupcap-diff-empty"></p>' ).text( i18n.diffEmpty ) );
			}

			if ( data.source_edit_url ) {
				$footer.find( '.dupcap-modal-open-source' ).attr( 'href', data.source_edit_url ).show();
			}
			if ( data.can_sync ) {
				$footer.find( '.dupcap-modal-mark-synced' ).show();
			}
		} ).fail( function() {
			showModalError( $overlay );
		} );
	}

	// Close: button, backdrop click, Escape.
	$( document ).on( 'click', '.dupcap-diff-modal-close, .dupcap-modal-btn-close', function( e ) {
		e.preventDefault();
		closeModal();
	} );

	$( document ).on( 'click', '.dupcap-diff-modal-overlay', function( e ) {
		if ( $( e.target ).hasClass( 'dupcap-diff-modal-overlay' ) ) {
			closeModal();
		}
	} );

	$( document ).on( 'keydown', function( e ) {
		const $overlay = $( '.dupcap-diff-modal-overlay' );
		if ( ! $overlay.length ) {
			return;
		}
		if ( e.key === 'Escape' ) {
			closeModal();
			return;
		}
		// Keep Tab inside the dialog.
		if ( e.key === 'Tab' ) {
			const $focusable = $overlay.find( 'a[href], button:not([disabled])' ).filter( ':visible' );
			if ( ! $focusable.length ) {
				return;
			}
			const first = $focusable.get( 0 );
			const last = $focusable.get( $focusable.length - 1 );
			if ( e.shiftKey && ( document.activeElement === first || document.activeElement === $overlay.find( '.dupcap-diff-modal' ).get( 0 ) ) ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		}
	} );

	// Side by side / inline.
	$( document ).on( 'click', '.dupcap-toggle-split', function( e ) {
		e.preventDefault();
		if ( ! currentSplitView && activeTranslationId ) {
			showDiffModal( activeTranslationId, true );
		}
	} );

	$( document ).on( 'click', '.dupcap-toggle-unified', function( e ) {
		e.preventDefault();
		if ( currentSplitView && activeTranslationId ) {
			showDiffModal( activeTranslationId, false );
		}
	} );

	// Open the dialog from the classic metabox or the dashboard table.
	$( document ).on( 'click', '.dupcap-btn-view-diff, .dupcap-view-diff-btn', function( e ) {
		e.preventDefault();
		const translationId = $( this ).data( 'translation-id' ) || $( this ).closest( '[data-translation-id]' ).data( 'translation-id' );
		if ( translationId ) {
			showDiffModal( parseInt( translationId, 10 ), true );
		}
	} );

	// Mark as up to date: from the metabox or from inside the dialog.
	$( document ).on( 'click', '.dupcap-btn-mark-synced, .dupcap-modal-mark-synced', function( e ) {
		e.preventDefault();
		const $btn = $( this );
		const translationId = $btn.data( 'translation-id' ) || activeTranslationId;
		if ( ! translationId ) {
			return;
		}

		const label = $btn.text();
		$btn.prop( 'disabled', true ).text( i18n.markingSynced );

		$.ajax( {
			url: config.ajaxUrl || window.ajaxurl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'dupcap_editor_mark_synced',
				translation_id: translationId,
				with_html: '1',
				nonce: config.nonce
			}
		} ).done( function( res ) {
			if ( ! res || ! res.success ) {
				const message = ( res && res.data && res.data.message ) || i18n.markError;
				$btn.prop( 'disabled', false ).text( label );
				$( '.dupcap-diff-modal-footer' ).find( '.dupcap-diff-error' ).remove();
				$( '.dupcap-diff-modal-footer' ).prepend( $( '<span class="dupcap-diff-error" role="alert"></span>' ).text( message ) );
				speak( message, 'assertive' );
				return;
			}

			closeModal();
			speak( i18n.markedSynced );

			// Classic editor: refresh the metabox in place.
			const $metabox = $( '.dupcap-editor-metabox' );
			if ( $metabox.length && res.data && res.data.html ) {
				// Server-rendered, escaped markup.
				$metabox.html( res.data.html );
			}

			// Inspector dashboard: let the row's own "Rescan issue" re-check it. It removes the row from the
			// saved report as well, so it does not come back on the next page load.
			const $row = $( '.dupcap-view-diff-btn[data-translation-id="' + translationId + '"]' ).closest( 'tr' );
			if ( $row.length ) {
				const rescan = $row.find( '[data-dupcap-rescan-content-issue]' ).get( 0 );
				if ( rescan ) {
					rescan.click();
				} else {
					$row.fadeOut( 300, function() {
						$( this ).remove();
					} );
				}
			}
		} ).fail( function() {
			$btn.prop( 'disabled', false ).text( label );
			$( '.dupcap-diff-modal-footer' ).find( '.dupcap-diff-error' ).remove();
			$( '.dupcap-diff-modal-footer' ).prepend( $( '<span class="dupcap-diff-error" role="alert"></span>' ).text( i18n.markError ) );
			speak( i18n.markError, 'assertive' );
		} );
	} );

} )( window.jQuery );
