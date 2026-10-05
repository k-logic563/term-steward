( function () {
	'use strict';

	const modal = document.querySelector( '.term-steward-history-modal' );
	const strings = globalThis.termStewardHistory || {};
	let busy = false;
	let terminal = false;
	const maxAttempts = 3;
	const focusStorageKey = 'termStewardHistoryFocus';

	document.querySelectorAll( '.term-steward-undo-preview' ).forEach( ( button ) => {
		button.form?.addEventListener( 'submit', ( event ) => {
			if ( button.dataset.submitting === '1' ) {
				event.preventDefault();
				return;
			}
			button.dataset.submitting = '1';
			button.classList.add( 'is-loading' );
			button.setAttribute( 'aria-busy', 'true' );
			// Keep the named submitter enabled through native form serialization;
			// disabling it synchronously would omit undo_command from the POST body.
			globalThis.setTimeout( () => {
				button.disabled = true;
			}, 0 );
		} );
	} );
	const historyError = document.querySelector( '.term-steward-history-error' );
	if ( historyError ) {
		globalThis.requestAnimationFrame( () => historyError.focus() );
	}

	function resultFooter() {
		const footer = modal?.querySelector( '.term-steward-modal__footer' );
		if ( ! footer ) {
			return;
		}
		const close = document.createElement( 'button' );
		close.type = 'button';
		close.className = 'button tt-button tt-button--secondary term-steward-modal__cancel';
		close.textContent = strings.closeResult || 'Close';
		footer.replaceChildren( close );
	}

	function setModalLocked( locked ) {
		busy = locked;
		modal?.querySelectorAll( '.term-steward-modal__close, .term-steward-modal__cancel, [name="undo_command"]' ).forEach( ( button ) => {
			button.disabled = locked;
			button.classList.toggle( 'is-loading', locked && button.matches( '[name="undo_command"]' ) );
			if ( locked && button.matches( '[name="undo_command"]' ) ) {
				button.setAttribute( 'aria-busy', 'true' );
			} else {
				button.removeAttribute( 'aria-busy' );
			}
		} );
	}

	function closeModal() {
		if ( ! modal || busy ) {
			return;
		}
		modal.hidden = true;
		document.body.style.overflow = '';
		if ( terminal ) {
			globalThis.sessionStorage?.setItem( focusStorageKey, '.nav-tab[href*="view=history"]' );
			globalThis.location.href = globalThis.location.href;
			return;
		}
		document.querySelector( '.term-steward-undo-preview' )?.focus();
	}

	function showResult( progress ) {
		terminal = true;
		const heading = modal?.querySelector( '#term-steward-undo-heading' );
		if ( heading ) {
			heading.textContent = strings.resultTitle || 'Undo result';
		}
		showProgress( progress );
		resultFooter();
	}

	function showProgress( progress ) {
		const body = modal?.querySelector( '.term-steward-modal__body' );
		if ( ! body ) {
			return;
		}
		let status = body.querySelector( '.term-steward-undo-status' );
		let output = body.querySelector( '.term-steward-undo-progress' );
		if ( ! status ) {
			body.replaceChildren();
			status = document.createElement( 'p' );
			status.className = 'term-steward-undo-status';
			status.append( document.createElement( 'strong' ) );
			output = document.createElement( 'p' );
			output.className = 'term-steward-undo-progress';
			body.append( status, output );
		}
		status.querySelector( 'strong' ).textContent = progress.status_label;
		output.textContent = ( strings.progress || 'Progress: %1$d / %2$d; successful: %3$d; failed: %4$d; remaining: %5$d' )
			.replace( '%1$d', progress.processed )
			.replace( '%2$d', progress.total )
			.replace( '%3$d', progress.succeeded )
			.replace( '%4$d', progress.failed )
			.replace( '%5$d', progress.remaining );
	}

	function showStopped( message ) {
		const body = modal?.querySelector( '.term-steward-modal__body' );
		const heading = modal?.querySelector( '#term-steward-undo-heading' );
		terminal = true;
		if ( heading ) {
			heading.textContent = strings.stoppedTitle || 'Undo interrupted';
		}
		if ( body ) {
			const notice = document.createElement( 'p' );
			notice.className = 'notice notice-error inline term-steward-undo-error';
			notice.setAttribute( 'role', 'alert' );
			notice.textContent = message;
			body.append( notice );
		}
		resultFooter();
		setModalLocked( false );
	}

	async function requestBatch( form ) {
		const data = new globalThis.FormData( form );
		data.set( 'action', 'term_steward_undo_batch' );
		let lastError;
		for ( let attempt = 1; attempt <= maxAttempts; attempt++ ) {
			try {
				const response = await globalThis.fetch( modal.dataset.ajaxUrl || globalThis.ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' } );
				let result;
				try {
					result = await response.json();
				} catch {
					if ( response.status >= 500 ) {
						throw new Error( 'temporary response error' );
					}
					throw new globalThis.DOMException( strings.cannotContinue || 'Undo could not continue.', 'DataError' );
				}
				if ( ! response.ok || ! result.success ) {
					throw new globalThis.DOMException( result.data?.message || strings.cannotContinue || 'Undo could not continue.', 'DataError' );
				}
				return result.data;
			} catch ( error ) {
				if ( error?.name === 'DataError' ) {
					throw error;
				}
				lastError = error;
				if ( attempt < maxAttempts ) {
					await new Promise( ( resolve ) => globalThis.setTimeout( resolve, 250 * attempt ) );
				}
			}
		}
		throw lastError;
	}

	async function runUndo( form ) {
		if ( busy || ! form ) {
			return;
		}
		setModalLocked( true );
		try {
			let progress;
			let previousProcessed = -1;
			let requestCount = 0;
			do {
				progress = await requestBatch( form );
				requestCount++;
				showProgress( progress );
				if ( progress.has_more && ( progress.processed <= previousProcessed || progress.processed > progress.total || requestCount > progress.total + 1 ) ) {
					throw new globalThis.DOMException( strings.progressStopped || 'Undo was interrupted because server progress could not be verified. Resume it from operation history.', 'DataError' );
				}
				previousProcessed = progress.processed;
			} while ( progress.has_more && progress.status === 'undoing' );
			if ( [ 'undone', 'undo_partial_failed', 'failed' ].includes( progress.status ) ) {
				showResult( progress );
			} else {
				showStopped( strings.interrupted || 'Undo was interrupted. You can resume it from operation history.' );
			}
			setModalLocked( false );
		} catch ( error ) {
			showStopped( error?.name === 'DataError' ? error.message : ( strings.interrupted || 'Undo was interrupted. You can resume it from operation history.' ) );
		}
	}

	if ( modal ) {
		modal.hidden = false;
		document.body.style.overflow = 'hidden';
		modal.querySelector( '.term-steward-modal__dialog' )?.focus();
		modal.addEventListener( 'click', ( event ) => {
			if ( event.target === modal || event.target.closest( '.term-steward-modal__close, .term-steward-modal__cancel' ) ) {
				closeModal();
			}
		} );
		modal.addEventListener( 'submit', ( event ) => {
			if ( event.submitter?.value !== 'run_undo' ) {
				return;
			}
			event.preventDefault();
			event.submitter.disabled = true;
			runUndo( event.target );
		} );
		document.addEventListener( 'keydown', ( event ) => {
			if ( modal.hidden ) {
				return;
			}
			if ( event.key === 'Escape' ) {
				event.preventDefault();
				closeModal();
			} else if ( event.key === 'Tab' ) {
				const focusable = Array.from( modal.querySelectorAll( 'button:not(:disabled)' ) );
				const first = focusable[ 0 ];
				const last = focusable[ focusable.length - 1 ];
				if ( event.shiftKey && document.activeElement === first ) {
					event.preventDefault();
					last?.focus();
				} else if ( ! event.shiftKey && document.activeElement === last ) {
					event.preventDefault();
					first?.focus();
				}
			}
		} );
		if ( modal.dataset.autoContinue === '1' ) {
			runUndo( modal.querySelector( 'form' ) );
		}
	}

	const focusSelector = globalThis.sessionStorage?.getItem( focusStorageKey );
	if ( focusSelector && ! modal ) {
		globalThis.sessionStorage.removeItem( focusStorageKey );
		globalThis.requestAnimationFrame( () => document.querySelector( focusSelector )?.focus() );
	}

	document.querySelectorAll( '.term-steward-history-logs' ).forEach( ( section ) => {
		const button = section.querySelector( '.term-steward-log-toggle' );
		const list = section.querySelector( '.term-steward-change-summary' );
		if ( ! button || ! list ) {
			return;
		}
		const summary = Array.from( list.children, ( item ) => item.cloneNode( true ) );
		let full = [];
		let loaded = false;
		button.addEventListener( 'click', async () => {
			if ( button.getAttribute( 'aria-expanded' ) === 'true' ) {
				list.replaceChildren( ...summary.map( ( item ) => item.cloneNode( true ) ) );
				button.setAttribute( 'aria-expanded', 'false' );
				button.textContent = strings.showDetails || 'View details';
				return;
			}
			button.disabled = true;
			try {
				if ( ! loaded ) {
					const logs = [];
					let page = 1;
					let totalPages = 1;
					do {
						const data = new globalThis.FormData();
						data.set( 'action', 'term_steward_history_logs' );
						data.set( 'operation_id', section.dataset.operation );
						data.set( 'log_page', String( page ) );
						data.set( 'term_steward_undo_nonce', section.dataset.nonce );
						const response = await globalThis.fetch( globalThis.ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' } );
						const result = await response.json();
						if ( ! response.ok || ! result.success ) {
							throw new Error( 'log request failed' );
						}
						logs.push( ...result.data.items );
						totalPages = result.data.total_pages;
						page++;
					} while ( page <= totalPages );
					full = logs.map( ( log ) => {
						const item = document.createElement( 'li' );
						item.className = `term-steward-log term-steward-log--${ log.severity }`;
						const state = document.createElement( 'strong' );
						state.textContent = log.severity === 'error' ? ( strings.failure || 'Failed: ' ) : ( log.severity === 'warning' ? ( strings.warning || 'Warning: ' ) : ( strings.success || 'Success: ' ) );
						item.append( state, document.createTextNode( ` ${ log.label }（${ log.date }）` ) );
						return item;
					} );
					loaded = true;
				}
				list.replaceChildren( ...full.map( ( item ) => item.cloneNode( true ) ) );
				button.setAttribute( 'aria-expanded', 'true' );
				button.textContent = strings.collapse || 'Close';
			} catch {
				const error = document.createElement( 'p' );
				error.className = 'notice notice-error inline';
				error.setAttribute( 'role', 'alert' );
				error.textContent = section.dataset.error;
				section.append( error );
			} finally {
				button.disabled = false;
			}
		} );
	} );
}() );
