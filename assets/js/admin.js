( function () {
	'use strict';

	const cfg = window.mleiAdmin;
	if ( ! cfg ) {
		return;
	}
	const i18n = cfg.i18n;

	function format( str ) {
		const args = Array.prototype.slice.call( arguments, 1 );
		return str.replace( /%(\d+)\$s/g, function ( m, n ) {
			return args[ n - 1 ];
		} );
	}

	function request( data ) {
		data.append( 'nonce', cfg.nonce );
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
		} )
			.then( function ( response ) {
				return response.json().catch( function () {
					throw new Error( i18n.error );
				} );
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error(
						( json && json.data && json.data.message ) || i18n.error
					);
				}
				return json.data;
			} );
	}

	function Runner( form, startAction, buildData ) {
		const box = form.parentNode.querySelector( '.mlei-progress' );
		const bar = box.querySelector( '.mlei-bar' );
		const status = box.querySelector( '.mlei-status' );
		const resume = box.querySelector( '.mlei-resume' );
		const map = box.querySelector( '.mlei-map' );
		const tbody = box.querySelector( 'tbody' );
		const submit = form.querySelector( '[type=submit]' );
		let job = null;
		let running = false;

		function setProgress( pos, total ) {
			const pct = total ? Math.round( ( pos / total ) * 100 ) : 0;
			bar.firstElementChild.style.width = pct + '%';
			bar.setAttribute( 'aria-valuenow', pct );
			status.textContent = format( i18n.progress, pos, total );
		}

		function cell( row, text ) {
			const td = document.createElement( 'td' );
			if ( text instanceof window.Node ) {
				td.appendChild( text );
			} else {
				td.textContent = text;
			}
			row.appendChild( td );
		}

		function addResult( r ) {
			const tr = document.createElement( 'tr' );
			tr.className = 'mlei-' + r.status;
			cell( tr, r.old_id || '' );
			cell( tr, i18n.statuses[ r.status ] || r.status );
			cell( tr, r.new_id || '' );
			if ( r.new_url && /^https?:\/\//.test( r.new_url ) ) {
				const a = document.createElement( 'a' );
				a.href = r.new_url;
				a.target = '_blank';
				a.rel = 'noopener';
				a.textContent = r.new_url;
				cell( tr, a );
			} else {
				cell( tr, r.message || '' );
			}
			tbody.insertBefore( tr, tbody.firstChild );
		}

		function fail( err ) {
			running = false;
			status.textContent = err.message;
			resume.hidden = ! job;
			submit.disabled = false;
		}

		function next() {
			const data = new FormData();
			data.append( 'action', 'mlei_process' );
			data.append( 'job', job );
			request( data )
				.then( function ( res ) {
					res.results.forEach( addResult );
					setProgress( res.pos, res.total );
					if ( res.done ) {
						running = false;
						status.textContent =
							format( i18n.progress, res.pos, res.total ) +
							' ' +
							i18n.done;
						map.href =
							cfg.mapUrl + '&job=' + encodeURIComponent( job );
						map.hidden = false;
						submit.disabled = false;
						return;
					}
					next();
				} )
				.catch( fail );
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			const data = buildData( form );
			if ( 'string' === typeof data ) {
				// A validation message instead of form data.
				tbody.textContent = '';
				resume.hidden = true;
				map.hidden = true;
				box.hidden = false;
				status.textContent = data;
				return;
			}
			data.append( 'action', startAction );
			job = null;
			running = true;
			submit.disabled = true;
			resume.hidden = true;
			map.hidden = true;
			tbody.textContent = '';
			box.hidden = false;
			setProgress( 0, 0 );
			status.textContent = i18n.starting;

			request( data )
				.then( function ( res ) {
					job = res.job;
					setProgress( 0, res.total );
					next();
				} )
				.catch( fail );
		} );

		resume.addEventListener( 'click', function () {
			resume.hidden = true;
			running = true;
			submit.disabled = true;
			next();
		} );

		window.addEventListener( 'beforeunload', function ( e ) {
			if ( running ) {
				e.preventDefault();
				e.returnValue = i18n.leave;
				return i18n.leave;
			}
		} );
	}

	const importForm = document.getElementById( 'mlei-import-form' );
	if ( importForm ) {
		Runner( importForm, 'mlei_start_import', function ( form ) {
			const input = form.querySelector( 'input[type=file]' );
			if ( ! input.files.length ) {
				return i18n.noFile;
			}
			const data = new FormData();
			data.append( 'csv', input.files[ 0 ] );
			if ( form.querySelector( 'input[name=download]' ).checked ) {
				data.append( 'download', '1' );
			}
			return data;
		} );
	}

	const transferForm = document.getElementById( 'mlei-transfer-form' );
	if ( transferForm ) {
		Runner( transferForm, 'mlei_start_transfer', function ( form ) {
			const source = form.querySelector( '[name=source]' ).value;
			const target = form.querySelector( '[name=target]' ).value;
			if ( ! source || ! target || source === target ) {
				return i18n.sameSite;
			}
			const data = new FormData();
			data.append( 'source', source );
			data.append( 'target', target );
			return data;
		} );
	}
} )();
