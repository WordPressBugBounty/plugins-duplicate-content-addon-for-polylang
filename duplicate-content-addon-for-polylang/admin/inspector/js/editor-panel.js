/**
 * Translation Inspector - Block Editor sidebar panel and "what changed" modal.
 */
( function( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.components ) {
		return;
	}

	const { registerPlugin } = wp.plugins;
	// wp.editor.PluginDocumentSettingPanel is the current home; wp.editPost's is the deprecated alias.
	const PluginDocumentSettingPanel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	if ( ! PluginDocumentSettingPanel ) {
		return;
	}

	const { createElement: el, useState, useEffect, useCallback, Fragment } = wp.element;
	const { Button, Spinner, Modal, Notice } = wp.components;
	const { sprintf } = wp.i18n;
	const speak = ( wp.a11y && wp.a11y.speak ) ? wp.a11y.speak : function() {};

	const config = window.dupcapEditorData || {};
	const postId = parseInt( config.postId, 10 ) || 0;
	const i18n = config.i18n || {};
	const LIST_LIMIT = 5;

	/**
	 * Call one of the panel's AJAX actions.
	 *
	 * @param {string} action Action name.
	 * @param {Object} params Extra parameters.
	 * @return {Promise<Object>} Resolves with the response data, rejects with an Error carrying a message.
	 */
	function request( action, params ) {
		const body = new window.URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', config.nonce );
		Object.keys( params || {} ).forEach( function( key ) {
			body.append( key, params[ key ] );
		} );

		return window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function( response ) {
			return response.json();
		} ).then( function( json ) {
			if ( json && json.success ) {
				return json.data;
			}
			throw new Error( ( json && json.data && json.data.message ) || '' );
		} );
	}

	/**
	 * A short list with a "+ N more" line.
	 *
	 * @param {string[]} items Labels.
	 * @param {Function} render Renders one item's content.
	 * @return {Object} List element.
	 */
	function shortList( items, render ) {
		return el( 'ul', { className: 'dupcap-editor-list' },
			items.slice( 0, LIST_LIMIT ).map( function( item, index ) {
				return el( 'li', { key: index }, render( item ) );
			} ),
			items.length > LIST_LIMIT && el( 'li', { key: 'more', className: 'description' },
				sprintf( i18n.moreItems, items.length - LIST_LIMIT )
			)
		);
	}

	function typeLabel( type ) {
		return { changed: i18n.typeChanged, added: i18n.typeAdded, removed: i18n.typeRemoved }[ type ] || type;
	}

	/**
	 * The modal that shows what changed in the source, field by field.
	 */
	function ChangesModal( props ) {
		const [ split, setSplit ] = useState( true );
		const [ data, setData ] = useState( null );
		const [ error, setError ] = useState( '' );

		useEffect( function() {
			let cancelled = false;
			setData( null );
			setError( '' );
			request( 'dupcap_editor_get_diff', { translation_id: postId, split: split ? 'true' : 'false' } )
				.then( function( result ) {
					if ( ! cancelled ) {
						setData( result );
					}
				} )
				.catch( function( e ) {
					if ( ! cancelled ) {
						setError( e.message || i18n.diffError );
					}
				} );
			return function() {
				cancelled = true;
			};
		}, [ split ] );

		return el( Modal, {
			title: i18n.diffModalTitle,
			onRequestClose: props.onClose,
			className: 'dupcap-diff-gutenberg-modal'
		},
			error && el( Notice, { status: 'error', isDismissible: false }, error ),
			! data && ! error && el( 'div', { className: 'dupcap-gutenberg-loading' }, el( Spinner ), el( 'span', null, i18n.diffLoading ) ),
			data && el( Fragment, null,
				el( 'p', { className: 'dupcap-diff-intro' },
					el( 'strong', null, data.source_title ),
					data.baseline_human ? ' — ' + sprintf( i18n.comparedWith, data.baseline_human ) : ''
				),
				data.baseline_date && el( 'p', { className: 'description' }, data.baseline_date ),
				data.partial && el( 'p', { className: 'description' }, i18n.partialNote ),
				el( 'div', { className: 'dupcap-diff-modal-toolbar' },
					el( 'div', { className: 'dupcap-diff-view-toggle', role: 'group', 'aria-label': i18n.diffModalTitle },
						el( 'button', { type: 'button', className: split ? 'active' : '', 'aria-pressed': split ? 'true' : 'false', onClick: function() { setSplit( true ); } }, i18n.sideBySide ),
						el( 'button', { type: 'button', className: ! split ? 'active' : '', 'aria-pressed': ! split ? 'true' : 'false', onClick: function() { setSplit( false ); } }, i18n.inline )
					)
				),
				el( 'div', { className: 'dupcap-diff-modal-body' },
					data.fields.length === 0
						? el( 'p', { className: 'dupcap-diff-empty' }, i18n.diffEmpty )
						: data.fields.map( function( item ) {
							return el( 'div', { key: item.key, className: 'dupcap-diff-field-block' },
								el( 'h3', { className: 'dupcap-diff-field-title' },
									item.label,
									el( 'span', { className: 'dupcap-change-tag dupcap-change-tag--' + item.type }, typeLabel( item.type ) )
								),
								item.truncated && el( 'p', { className: 'description' }, i18n.truncatedNote ),
								el( 'div', { className: 'dupcap-diff-table-wrap', dangerouslySetInnerHTML: { __html: item.diff } } )
							);
						} )
				),
				el( 'div', { className: 'dupcap-diff-modal-footer' },
					data.source_edit_url && el( Button, { variant: 'tertiary', href: data.source_edit_url, target: '_blank', rel: 'noopener noreferrer' }, i18n.openSource ),
					data.can_sync && props.status.status === 'outdated' && el( Button, {
						variant: 'primary',
						isBusy: props.marking,
						disabled: props.marking,
						onClick: props.onMark
					}, i18n.markSynced ),
					el( Button, { variant: 'secondary', onClick: props.onClose }, i18n.close )
				)
			)
		);
	}

	/**
	 * The panel in the document settings sidebar.
	 */
	function TranslationStatusPanel() {
		const [ status, setStatus ] = useState( null );
		const [ loading, setLoading ] = useState( true );
		const [ error, setError ] = useState( '' );
		const [ marking, setMarking ] = useState( false );
		const [ modalOpen, setModalOpen ] = useState( false );

		const fetchStatus = useCallback( function() {
			if ( ! postId ) {
				return;
			}
			request( 'dupcap_editor_get_status', { post_id: postId } )
				.then( function( data ) {
					setStatus( data );
					setError( '' );
				} )
				.catch( function( e ) {
					setError( e.message || i18n.loadError );
				} )
				.then( function() {
					setLoading( false );
				} );
		}, [] );

		useEffect( function() {
			fetchStatus();

			// Refresh after each real save, once the server has had time to update its markers.
			if ( wp.data && wp.data.select && wp.data.subscribe ) {
				let wasSaving = wp.data.select( 'core/editor' ).isSavingPost();
				const unsubscribe = wp.data.subscribe( function() {
					const editor = wp.data.select( 'core/editor' );
					const isSaving = editor.isSavingPost();
					if ( wasSaving && ! isSaving && ! editor.isAutosavingPost() ) {
						setTimeout( fetchStatus, 1200 );
					}
					wasSaving = isSaving;
				} );
				return unsubscribe;
			}
		}, [ fetchStatus ] );

		function markSynced() {
			setMarking( true );
			setError( '' );
			request( 'dupcap_editor_mark_synced', { translation_id: postId } )
				.then( function( data ) {
					setStatus( data );
					setModalOpen( false );
					speak( i18n.markedSynced );
				} )
				.catch( function( e ) {
					const message = e.message || i18n.markError;
					setError( message );
					speak( message, 'assertive' );
				} )
				.then( function() {
					setMarking( false );
				} );
		}

		const panelProps = {
			name: 'dupcap-translation-status',
			title: i18n.panelTitle,
			className: 'dupcap-gutenberg-panel',
			icon: 'translation'
		};

		if ( loading && ! status ) {
			return el( PluginDocumentSettingPanel, panelProps,
				el( 'div', { className: 'dupcap-gutenberg-loading' }, el( Spinner ), el( 'span', null, i18n.loading ) )
			);
		}

		if ( error && ! status ) {
			return el( PluginDocumentSettingPanel, panelProps,
				el( Notice, { status: 'error', isDismissible: false }, error ),
				el( Button, { variant: 'secondary', onClick: fetchStatus }, i18n.retry )
			);
		}

		if ( ! status || status.role === 'none' ) {
			return null;
		}

		let body = null;

		if ( status.role === 'source' ) {
			const slugs = Object.keys( status.translations || {} );
			body = el( 'div', { className: 'dupcap-editor-source-body' },
				el( 'span', { className: 'dupcap-badge dupcap-badge--source' },
					el( 'span', { className: 'dashicons dashicons-admin-site-alt3', 'aria-hidden': 'true' } ),
					' ' + i18n.sourceRole
				),
				el( 'p', { className: 'description' }, i18n.sourceRoleDesc ),
				slugs.length > 0 && el( 'div', { className: 'dupcap-editor-trans-list' },
					el( 'strong', null, i18n.translationsLabel ),
					el( 'ul', { className: 'dupcap-editor-trans-items' },
						slugs.map( function( slug ) {
							const t = status.translations[ slug ];
							return el( 'li', { key: slug, className: 'dupcap-editor-trans-item' },
								el( 'div', { className: 'dupcap-editor-trans-info' },
									t.flag_url && el( 'img', { className: 'dupcap-lang-flag', src: t.flag_url, alt: '', width: 16, height: 11 } ),
									el( 'span', { className: 'dupcap-editor-lang-name' }, t.language_name ),
									el( 'span', { className: 'dupcap-pill dupcap-pill--' + ( t.status_badge || 'info' ) }, t.status_label )
								),
								el( 'div', { className: 'dupcap-editor-trans-action' },
									t.edit_url
										? el( 'a', { className: 'button button-small', href: t.edit_url, target: '_blank', rel: 'noopener noreferrer' }, i18n.editTranslation )
										: ( t.add_url ? el( 'a', { className: 'button button-small button-primary', href: t.add_url, target: '_blank', rel: 'noopener noreferrer' }, i18n.addTranslation ) : null )
								)
							);
						} )
					)
				)
			);
		} else {
			const outdated = status.status === 'outdated';
			body = el( 'div', { className: 'dupcap-editor-trans-body' },
				el( 'div', { className: 'dupcap-editor-status-row' },
					el( 'span', { className: 'dupcap-editor-status-badge dupcap-editor-status-badge--' + ( status.status_badge || 'info' ) },
						el( 'span', { className: 'dashicons dashicons-' + ( status.status_icon || 'info' ), 'aria-hidden': 'true' } ),
						' ' + status.status_label
					)
				),
				status.notice && el( 'div', { className: 'dupcap-editor-notice dupcap-editor-notice--' + ( status.status_badge || 'info' ) },
					el( 'p', null, status.notice )
				),
				status.source && el( 'div', { className: 'dupcap-editor-source-info' },
					el( 'p', { className: 'dupcap-editor-meta-line' },
						el( 'strong', null, i18n.sourceLabel ),
						status.source.flag_url && el( 'img', { className: 'dupcap-lang-flag', src: status.source.flag_url, alt: '', width: 16, height: 11 } ),
						el( 'a', { href: status.source.edit_url, target: '_blank', rel: 'noopener noreferrer' }, status.source.title )
					),
					status.source.modified_human && el( 'p', { className: 'description' }, sprintf( i18n.sourceChanged, status.source.modified_human ) )
				),
				status.changes && status.changes.length > 0 && el( 'div', { className: 'dupcap-editor-changed-fields' },
					el( 'strong', null, i18n.changedLabel ),
					shortList( status.changes, function( change ) {
						return el( Fragment, null, change.label + ' ', el( 'span', { className: 'dupcap-change-tag dupcap-change-tag--' + change.type }, typeLabel( change.type ) ) );
					} )
				),
				status.still_same && status.still_same.length > 0 && el( 'div', { className: 'dupcap-editor-changed-fields' },
					el( 'strong', null, i18n.stillSameLabel ),
					shortList( status.still_same, function( label ) {
						return label;
					} )
				),
				error && el( Notice, { status: 'error', isDismissible: false }, error ),
				el( 'div', { className: 'dupcap-editor-actions' },
					status.has_diff && el( Button, {
						variant: 'primary',
						icon: 'visibility',
						onClick: function() { setModalOpen( true ); }
					}, i18n.seeChanges ),
					status.can_sync && outdated && el( Button, {
						variant: 'secondary',
						icon: 'yes',
						isBusy: marking,
						disabled: marking,
						onClick: markSynced
					}, marking ? i18n.markingSynced : i18n.markSynced ),
					status.can_sync && outdated && el( 'p', { className: 'description' }, i18n.markSyncedHint )
				)
			);
		}

		return el( Fragment, null,
			el( PluginDocumentSettingPanel, panelProps, body ),
			modalOpen && el( ChangesModal, {
				status: status,
				marking: marking,
				onMark: markSynced,
				onClose: function() { setModalOpen( false ); }
			} )
		);
	}

	registerPlugin( 'dupcap-translation-status-panel', {
		render: TranslationStatusPanel,
		icon: 'translation'
	} );

} )( window.wp );
