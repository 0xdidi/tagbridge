( function () {
	'use strict';

	function initToggleForms() {
		var toggles = document.querySelectorAll( '[data-tagbridge-auto-submit]' );

		Array.prototype.forEach.call( toggles, function ( input ) {
			input.addEventListener( 'change', function () {
				if ( input.form ) {
					input.form.submit();
				}
			} );
		} );
	}

	function initTabs() {
		var tablists = document.querySelectorAll( '.tagbridge-tabs' );

		Array.prototype.forEach.call( tablists, function ( tablist ) {
			var tabs = Array.prototype.slice.call(
				tablist.querySelectorAll( '[role="tab"]' )
			);
			if ( ! tabs.length ) {
				return;
			}

			var scope = tablist.closest( '[data-tagbridge-module]' ) || document;

			function panelFor( tab ) {
				return scope.querySelector(
					'#' + tab.getAttribute( 'aria-controls' )
				);
			}

			function select( tab, setFocus ) {
				tabs.forEach( function ( other ) {
					var selected = other === tab;
					other.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
					other.setAttribute( 'tabindex', selected ? '0' : '-1' );
					other.classList.toggle( 'is-active', selected );

					var panel = panelFor( other );
					if ( panel ) {
						panel.hidden = ! selected;
						panel.classList.toggle( 'is-active', selected );
					}
				} );

				if ( setFocus ) {
					tab.focus();
				}
			}

			tabs.forEach( function ( tab, index ) {
				tab.addEventListener( 'click', function () {
					select( tab, false );
				} );

				tab.addEventListener( 'keydown', function ( event ) {
					var next;
					switch ( event.key ) {
						case 'ArrowRight':
						case 'ArrowDown':
							next = tabs[ ( index + 1 ) % tabs.length ];
							break;
						case 'ArrowLeft':
						case 'ArrowUp':
							next = tabs[ ( index - 1 + tabs.length ) % tabs.length ];
							break;
						case 'Home':
							next = tabs[ 0 ];
							break;
						case 'End':
							next = tabs[ tabs.length - 1 ];
							break;
						default:
							return;
					}
					event.preventDefault();
					select( next, true );
				} );
			} );
		} );
	}

	function moduleScopes() {
		var modules = document.querySelectorAll( '[data-tagbridge-module]' );
		return modules.length ? Array.prototype.slice.call( modules ) : [ document ];
	}

	function initRegion() {
		moduleScopes().forEach( function ( scope ) {
			var region = scope.querySelector( '[data-tagbridge-region]' );
			var customHost = scope.querySelector( '[data-tagbridge-custom-host]' );

			if ( ! region || ! customHost ) {
				return;
			}

			function sync() {
				customHost.hidden = 'custom' !== region.value;
			}

			region.addEventListener( 'change', sync );
			sync();
		} );
	}

	function initMasters() {
		moduleScopes().forEach( function ( scope ) {
			var masters = scope.querySelectorAll( '[data-tagbridge-master], [data-hp-master]' );

			Array.prototype.forEach.call( masters, function ( master ) {
				var key = master.getAttribute( 'data-tagbridge-master' ) || master.getAttribute( 'data-hp-master' );
				var body = scope.querySelector(
					'[data-tagbridge-dependent="' + key + '"], [data-hp-dependent="' + key + '"]'
				);
				var input = master.querySelector( 'input[type="checkbox"]' );

				if ( ! body || ! input ) {
					return;
				}

				function sync() {
					body.classList.toggle( 'is-muted', ! input.checked );
				}

				input.addEventListener( 'change', sync );
				sync();
			} );
		} );
	}

	function init() {
		initToggleForms();
		initTabs();
		initRegion();
		initMasters();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
