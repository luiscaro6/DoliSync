(function ($) {
	'use strict';

	var DoliSyncAdmin = {
		init: function () {
			this.bindEvents();
		},

		bindEvents: function () {
			$(document).on('submit', 'form', function () {
				return true;
			});
		}
	};

	$(function () {
		DoliSyncAdmin.init();
	});
})(jQuery);

function dolisyncEscapeHtml(value) {
	return jQuery('<div>').text(value == null ? '' : String(value)).html();
}

function dolisyncAjaxError(xhr, fallback) {
	const response = xhr && xhr.responseJSON ? xhr.responseJSON : {};
	const message = response.data && response.data.message
		? response.data.message
		: (xhr && xhr.responseText ? xhr.responseText : (fallback || 'No se pudo completar la solicitud.'));
	return dolisyncEscapeHtml(message);
}

document.addEventListener('DOMContentLoaded', function () {

	document.querySelectorAll('.dolisync-log-row').forEach(function (row) {

		row.addEventListener('click', function () {

			const logId = row.dataset.logId;
			const details = document.getElementById('log-details-' + logId);

			if (details.style.display === 'table-row') {
				details.style.display = 'none';
			} else {
				details.style.display = 'table-row';
			}

		});

	});

	// Manejador para acciones internas
	document.querySelectorAll('.dolisync-action-row').forEach(function (row) {

		row.addEventListener('click', function () {

			const actionId = row.dataset.actionId;
			const details = document.getElementById('action-details-' + actionId);

			if (details.style.display === 'table-row') {
				details.style.display = 'none';
			} else {
				details.style.display = 'table-row';
			}

		});

	});

});

jQuery(function ($) {
	$('#dolisync-load-warehouses').on('click', function () {
		const $button = $(this); const $select = $('#warehouse_id'); const $result = $('#dolisync-warehouses-result');
		$button.prop('disabled', true); $result.html('<p>Consultando almacenes en Dolibarr…</p>');
		$.post(DoliSync.ajaxUrl, {action: 'dolisync_get_warehouses', nonce: DoliSync.nonce}).done(function (response) {
			if (!response.success) { $result.html('<div class="notice notice-error inline"><p>' + dolisyncEscapeHtml(response.data && response.data.message) + '</p></div>'); return; }
			const current = String($select.val() || ''); $select.empty().append($('<option>', {value: '', text: 'Selecciona un almacén…'}));
			(response.data.warehouses || []).forEach(function (item) {
				const text = item.name + (item.ref ? ' · ' + item.ref : '') + ' (#' + item.id + ')' + (item.active ? '' : ' · Inactivo');
				$select.append($('<option>', {value: item.id, text: text, disabled: !item.active}).attr('data-name', item.name));
			});
			$select.val(current); $result.html('<div class="notice notice-success inline"><p>' + response.data.warehouses.length + ' almacenes encontrados.</p></div>');
		}).fail(function (xhr) { $result.html('<div class="notice notice-error inline"><p>' + dolisyncAjaxError(xhr) + '</p></div>'); }).always(function () { $button.prop('disabled', false); });
	});

	$('#dolisync-sync-stock').on('click', function () {
		const $button = $(this);
		const $result = $('#dolisync-product-sync-result');
		const nonce = $button.data('nonce');
		const totals = {checked: 0, updated: 0, unchanged: 0, skipped: 0, errors: 0};
		$button.prop('disabled', true);
		$result.html('<div class="notice notice-info inline"><p>Comprobando existencias en Dolibarr…</p></div>').show();

		function runBatch(offset, runId) {
			$.ajax({
				url: DoliSync.ajaxUrl,
				type: 'POST',
				data: {action: 'dolisync_sync_stock', nonce: nonce, offset: offset, run_id: runId || ''},
				success: function (response) {
					if (!response.success) {
						$result.html('<div class="notice notice-error inline"><p><strong>✗ Error:</strong> ' + dolisyncEscapeHtml(response.data && response.data.message ? response.data.message : 'Error desconocido.') + '</p></div>');
						$button.prop('disabled', false);
						return;
					}
					const stats = response.data.stats || {};
					Object.keys(totals).forEach(function (key) { totals[key] += Number(stats[key] || 0); });
					if (response.data.has_more) {
						$result.html('<div class="notice notice-info inline"><p>Productos comprobados: ' + totals.checked + '…</p></div>');
						runBatch(Number(response.data.next_offset || 0), response.data.run_id || runId);
						return;
					}
					const noticeClass = totals.errors > 0 ? 'notice-warning' : 'notice-success';
					const title = totals.errors > 0 ? 'Stock sincronizado con incidencias.' : 'Stock sincronizado correctamente.';
					const html = '<ul style="list-style: disc; margin-left: 20px;">' +
						'<li>Comprobados: ' + totals.checked + '</li>' +
						'<li>Actualizados: ' + totals.updated + '</li>' +
						'<li>Sin cambios: ' + totals.unchanged + '</li>' +
						'<li>Omitidos: ' + totals.skipped + '</li>' +
						'<li>Errores: ' + totals.errors + '</li></ul>';
					$result.html('<div class="notice ' + noticeClass + ' inline"><p><strong>' + (totals.errors > 0 ? '⚠ ' : '✓ ') + title + '</strong></p>' + html + '</div>');
					$button.prop('disabled', false);
				},
				error: function (xhr, status, error) {
					$result.html('<div class="notice notice-error inline"><p><strong>✗ Error:</strong> ' + dolisyncAjaxError(xhr, error || status) + '</p></div>');
					$button.prop('disabled', false);
				}
			});
		}
		runBatch(0, '');
	});

	$('#dolisync-test-connection').on('click', function () {

		const $button = $(this);
		const $result = $('#dolisync-test-result');

		$button.prop('disabled', true);
		$result.html('Probando conexión...');

		$.ajax({
            url: DoliSync.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dolisync_test_connection',
                nonce: DoliSync.nonce
            },

            beforeSend: function () {
                $button.prop('disabled', true);
                $result.html(
                    '<span>Probando conexión...</span>'
                );
            },

            success: function (response) {

                if (response.success) {
                        const data = response.data || {};
                        const message = data.message || '';
                        const isWarning = data.notice_type === 'warning' || !!data.warning_code || /no parece provenir de Dolibarr/i.test(message);
                        const icon = isWarning ? '?' : '✓';
                        const background = isWarning ? '#fffbeb' : '#ecfdf5';
                        const borderColor = isWarning ? '#d97706' : '#16a34a';
                        const textColor = isWarning ? '#92400e' : '#166534';

                        $result.html(
                            '<div class="dolisync-test-result" style="margin-top: 0; padding: 14px 16px; border-left: 4px solid ' + borderColor + '; border-radius: 10px; background: ' + background + '; color: ' + textColor + ';">' +
							'<strong>' + icon + ' ' + dolisyncEscapeHtml(message) + '</strong>' +
                            '<div style="margin-top: 6px; font-size: 13px; opacity: 0.9;">' + response.data.time_ms + ' ms</div>' +
                            '</div>'
                        );
                    refreshLastCheckTime();

                } else {

                    $result.html(
                        '<div class="notice notice-error inline">' +
                        '<p>✗ ' +
						dolisyncEscapeHtml(response.data && response.data.message ? response.data.message : 'No se pudo conectar.') +
                        '</p>' +
                        '</div>'
                    );

                }
            },

            error: function (xhr) {

                $result.html(
                    '<div class="notice notice-error inline">' +
					'<p>✗ ' + dolisyncAjaxError(xhr, 'Error de comunicación (' + xhr.status + ').') + '</p>' +
                    '</div>'
                );

            },

            complete: function () {
                $button.prop('disabled', false);
            }
        });

	});

});
function refreshLastCheckTime() {
    const $timeDisplay = jQuery('#dolisync-last-check-value');

    jQuery.post(DoliSync.ajaxUrl, {
        action: 'dolisync_get_last_check_time',
        nonce: DoliSync.nonce
    }, function(response) {
        if (response.success) {
            $timeDisplay.attr('data-timestamp', response.data.timestamp || 0);
            $timeDisplay.text(response.data.time_ago || 'sin fecha');
        }
    });
}

jQuery(function ($) {
    let timerInterval;

    function updateRelativeTime() {
        const $container = $('#dolisync-last-check-value');
        const timestamp = parseInt($container.attr('data-timestamp'), 10);

        if (!timestamp || timestamp === 0) return;

        const now = Math.floor(Date.now() / 1000);
        const diff = now - timestamp;

        if (diff < 0) {
            $container.text('0 seg');
            return;
        }

        let text = '';
        if (diff < 60) {
            text = diff + ' seg'; // Segundos
        } else {
            const mins = Math.floor(diff / 60);
            text = mins + ' min'; // Minutos
        }

        $container.text(text);
    }

    // Actualiza el contador cada segundo
    timerInterval = setInterval(updateRelativeTime, 1000);

    // Esta es la función que "resetea" el contador tras el test
    window.refreshLastCheckTime = function() {
        $.post(DoliSync.ajaxUrl, {
            action: 'dolisync_get_last_check_time',
            nonce: DoliSync.nonce
        }, function(response) {
            if (response.success) {
                // Actualizamos el atributo data-timestamp con el nuevo tiempo del servidor
                $('#dolisync-last-check-value').attr('data-timestamp', response.data.timestamp);
                // Forzamos la actualización visual inmediata
                updateRelativeTime();
            }
        });
    };
});

// Manejador para sincronización de contactos
jQuery(function ($) {
    $('#dolisync-sync-dolibarr-to-woo').on('click', function () {
        const $button = $(this);
        const $result = $('#dolisync-sync-result');
        const nonce = $button.data('nonce');

        $button.prop('disabled', true);
        $result.html(
            '<div class="notice notice-info inline"><p>Sincronizando contactos...</p></div>'
        ).show();

        $.ajax({
            url: DoliSync.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dolisync_sync_contacts',
                nonce: nonce
            },

            success: function (response) {
                if (response.success) {
                    const stats = response.data.stats;
                    const message = response.data.message;

                    let statsHtml = '<ul style="list-style: disc; margin-left: 20px;">';
                    statsHtml += '<li>Creados: ' + stats.created + '</li>';
                    statsHtml += '<li>Actualizados: ' + stats.updated + '</li>';
                    statsHtml += '<li>Omitidos: ' + stats.skipped + '</li>';
                    if (stats.errors > 0) {
                        statsHtml += '<li style="color: #d63638;">Errores: ' + stats.errors + '</li>';
                    }
                    statsHtml += '</ul>';

                    $result.html(
						'<div class="notice notice-success inline"><p><strong>✓ ' + dolisyncEscapeHtml(message) + '</strong></p>' +
                        statsHtml +
                        '</div>'
                    );

                    // Recargar la tabla de relaciones
                    setTimeout(function () {
                        location.reload();
                    }, 2000);
                } else {
                    $result.html(
                        '<div class="notice notice-error inline"><p><strong>✗ Error:</strong> ' +
						dolisyncEscapeHtml(response.data && response.data.message ? response.data.message : 'Error desconocido.') +
                        '</p></div>'
                    );
                }
            },

            error: function (xhr, status, error) {
                $result.html(
                    '<div class="notice notice-error inline"><p><strong>✗ Error AJAX:</strong> ' +
                    error +
                    '</p></div>'
                );
            },

            complete: function () {
                $button.prop('disabled', false);
            }
        });
    });

    // Manejador para sincronización inversa de contactos (WooCommerce → Dolibarr)
    $('#dolisync-sync-woo-to-dolibarr').on('click', function () {
        const $button = $(this);
        const $result = $('#dolisync-sync-result');
        const nonce = $button.data('nonce');

        $button.prop('disabled', true);
        $result.html(
            '<div class="notice notice-info inline"><p>Sincronizando clientes...</p></div>'
        ).show();

        $.ajax({
            url: DoliSync.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dolisync_sync_contacts_reverse',
                nonce: nonce
            },

            success: function (response) {
                if (response.success) {
                    const stats = response.data.stats;
                    const message = response.data.message;

                    let statsHtml = '<ul style="list-style: disc; margin-left: 20px;">';
                    statsHtml += '<li>Creados: ' + stats.created + '</li>';
                    statsHtml += '<li>Actualizados: ' + stats.updated + '</li>';
                    statsHtml += '<li>Omitidos: ' + stats.skipped + '</li>';
                    if (stats.errors > 0) {
                        statsHtml += '<li style="color: #d63638;">Errores: ' + stats.errors + '</li>';
                    }
                    statsHtml += '</ul>';

                    $result.html(
						'<div class="notice notice-success inline"><p><strong>✓ ' + dolisyncEscapeHtml(message) + '</strong></p>' +
                        statsHtml +
                        '</div>'
                    );

                    // Recargar la tabla de relaciones
                    setTimeout(function () {
                        location.reload();
                    }, 2000);
                } else {
                    $result.html(
                        '<div class="notice notice-error inline"><p><strong>✗ Error:</strong> ' +
						dolisyncEscapeHtml(response.data && response.data.message ? response.data.message : 'Error desconocido.') +
                        '</p></div>'
                    );
                }
            },

            error: function (xhr, status, error) {
                $result.html(
                    '<div class="notice notice-error inline"><p><strong>✗ Error AJAX:</strong> ' +
                    error +
                    '</p></div>'
                );
            },

            complete: function () {
                $button.prop('disabled', false);
            }
        });
    });

    // Manejador para sincronización de productos (Dolibarr → WooCommerce)
    $('#dolisync-sync-products-dolibarr-to-woo').on('click', function () {
        const $button = $(this);
        const $result = $('#dolisync-product-sync-result');
        const nonce = $button.data('nonce');
        const totals = {created: 0, mapped: 0, updated: 0, skipped: 0, errors: 0};
        let runId = '';

        $button.prop('disabled', true);
        $result.html('<div class="notice notice-info inline"><p>Sincronizando productos...</p></div>').show();

        function runPage(page) {
            $.ajax({
                url: DoliSync.ajaxUrl,
                type: 'POST',
                data: {action: 'dolisync_sync_products', nonce: nonce, page_number: page, per_page: 5, run_id: runId},
                success: function (response) {
                    if (!response.success) {
						$result.html('<div class="notice notice-error inline"><p><strong>✗ Error:</strong> ' + dolisyncEscapeHtml(response.data && response.data.message ? response.data.message : 'Error desconocido.') + '</p></div>');
                        $button.prop('disabled', false);
                        return;
                    }
                    const stats = response.data.stats || {};
                    runId = response.data.run_id || runId;
                    Object.keys(totals).forEach(function (key) { totals[key] += Number(stats[key] || 0); });
                    const pagination = response.data.pagination || {};
                    $result.html('<div class="notice notice-info inline"><p>Procesada página ' + (page + 1) + '.</p></div>');
                    if (pagination.has_more) {
                        runPage(Number(pagination.next_page));
                        return;
                    }
                    let html = '<ul style="list-style: disc; margin-left: 20px;"><li>Creados: ' + totals.created + '</li><li>Mapeados por SKU o nombre: ' + totals.mapped + '</li><li>Actualizados: ' + totals.updated + '</li><li>Omitidos: ' + totals.skipped + '</li><li>Errores: ' + totals.errors + '</li></ul>';
					const noticeClass = totals.errors > 0 ? 'notice-warning' : 'notice-success';
					const heading = totals.errors > 0 ? 'Sincronización completada con incidencias.' : 'Sincronización completada.';
					$result.html('<div class="notice ' + noticeClass + ' inline"><p><strong>' + (totals.errors > 0 ? '⚠ ' : '✓ ') + heading + '</strong></p>' + html + '</div>');
                    $button.prop('disabled', false);
                    setTimeout(function () { location.reload(); }, 2000);
                },
                error: function (xhr, status, error) {
					error = dolisyncAjaxError(xhr, error || status);
                    $result.html('<div class="notice notice-error inline"><p><strong>✗ Error AJAX:</strong> ' + error + '</p></div>');
                    $button.prop('disabled', false);
                }
            });
        }
        runPage(0);
    });

    // Manejador para sincronización de categorías de productos (Dolibarr → WooCommerce)
    $('#dolisync-sync-product-categories').on('click', function () {
        const $button = $(this);
        const $result = $('#dolisync-product-sync-result');
        const nonce = $button.data('nonce');

        $button.prop('disabled', true);
        $result.html('<div class="notice notice-info inline"><p>Sincronizando categorías...</p></div>').show();

        $.ajax({
            url: DoliSync.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dolisync_sync_product_categories',
                nonce: nonce
            },
            success: function (response) {
                if (response.success) {
                    const stats = response.data.stats;
                    const message = response.data.message;

                    let statsHtml = '<ul style="list-style: disc; margin-left: 20px;">';
                    statsHtml += '<li>Actualizados: ' + stats.updated + '</li>';
                    statsHtml += '<li>Omitidos: ' + stats.skipped + '</li>';
                    if (stats.errors > 0) {
                        statsHtml += '<li style="color: #d63638;">Errores: ' + stats.errors + '</li>';
                    }
                    statsHtml += '</ul>';

					const noticeClass = totals.errors > 0 ? 'notice-warning' : 'notice-success';
					$result.html(
						'<div class="notice ' + noticeClass + ' inline"><p><strong>' + (totals.errors > 0 ? '⚠ ' : '✓ ') + dolisyncEscapeHtml(message) + '</strong></p>' +
                        statsHtml +
                        '</div>'
                    );

                    setTimeout(function () {
                        location.reload();
                    }, 2000);
                } else {
					$result.html('<div class="notice notice-error inline"><p><strong>✗ Error:</strong> ' + dolisyncEscapeHtml(response.data && response.data.message ? response.data.message : 'Error desconocido.') + '</p></div>');
                }
            },
            error: function (xhr, status, error) {
				const response = xhr.responseJSON || {};
				error = dolisyncAjaxError(xhr, error || status);
                $result.html('<div class="notice notice-error inline"><p><strong>✗ Error AJAX:</strong> ' + error + '</p></div>');
            },
            complete: function () {
                $button.prop('disabled', false);
            }
        });
    });

    // Manejador para sincronización manual WooCommerce → Dolibarr
    $('#dolisync-sync-products-woo-to-dolibarr').on('click', function () {
        const $button = $(this);
        const $result = $('#dolisync-product-sync-result');
        const nonce = $button.data('nonce');
        const totals = {created: 0, mapped: 0, updated: 0, skipped: 0, errors: 0};
        let runId = '';
        let expectedTotal = 0;

        $button.prop('disabled', true);
        $result.html('<div class="notice notice-info inline"><p>Exportando productos...</p></div>').show();

        function runPage(page) {
        $.ajax({
            url: DoliSync.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dolisync_sync_products_reverse',
                nonce: nonce,
                page_number: page,
                per_page: 5,
                run_id: runId
            },
            success: function (response) {
                if (response.success) {
                    const stats = response.data.stats || {};
                    runId = response.data.run_id || runId;
                    const message = response.data.message;
					Object.keys(totals).forEach(function (key) { totals[key] += Number(stats[key] || 0); });
					const pagination = response.data.pagination || {};
					expectedTotal = Number(pagination.total || expectedTotal);
					if (pagination.has_more) {
						$result.html('<div class="notice notice-info inline"><p>Procesada página ' + page + '.</p></div>');
						runPage(Number(pagination.next_page));
						return;
					}

                    let statsHtml = '<ul style="list-style: disc; margin-left: 20px;">';
                    statsHtml += '<li>Productos encontrados en WooCommerce: ' + expectedTotal + '</li>';
                    statsHtml += '<li>Creados: ' + totals.created + '</li>';
                    statsHtml += '<li>Mapeados: ' + totals.mapped + '</li>';
                    statsHtml += '<li>Actualizados: ' + totals.updated + '</li>';
                    statsHtml += '<li>Omitidos: ' + totals.skipped + '</li>';
                    if (totals.errors > 0) {
                        statsHtml += '<li style="color: #d63638;">Errores: ' + totals.errors + '</li>';
                    }
                    statsHtml += '</ul>';

                    $result.html(
						'<div class="notice notice-success inline"><p><strong>✓ ' + dolisyncEscapeHtml(message) + '</strong></p>' +
                        statsHtml +
                        '</div>'
                    );

                    setTimeout(function () {
                        location.reload();
                    }, 2000);
                } else {
					$result.html('<div class="notice notice-error inline"><p><strong>✗ Error:</strong> ' + dolisyncEscapeHtml(response.data && response.data.message ? response.data.message : 'Error desconocido.') + '</p></div>');
                }
            },
            error: function (xhr, status, error) {
				const response = xhr.responseJSON || {};
				error = dolisyncAjaxError(xhr, error || status);
                $result.html('<div class="notice notice-error inline"><p><strong>✗ Error AJAX:</strong> ' + error + '</p></div>');
            },
			complete: function (xhr) {
				const response = xhr.responseJSON || {};
				const pagination = response.data && response.data.pagination ? response.data.pagination : {};
				if (!response.success || !pagination.has_more) {
					$button.prop('disabled', false);
				}
			}
        });
		}
		runPage(1);
    });
});
/* global DoliSync, jQuery */

function dolisyncInitProductsCatalog() {
	const $app = jQuery('.dolisync-products-app');
	if (!$app.length) {
		return;
	}

	let rows = [];
	let filteredRows = [];
	let page = 1;
	let pageSize = 20;

	const esc = (value) => dolisyncEscapeHtml(value === null || typeof value === 'undefined' ? '' : String(value));
	const money = (value) => value === '' || value === null || typeof value === 'undefined' ? '—' : esc(value);

	function variationHtml(variations) {
		if (!variations || !variations.length) {
			return '';
		}
		return '<details class="dolisync-variations"><summary>' + variations.length + ' ' + (variations.length === 1 ? 'variación' : 'variaciones') + '</summary>' +
			'<div class="dolisync-variation-list">' + variations.map(function (variation) {
				const attrs = (variation.attributes || []).filter(Boolean).join(' · ');
				return '<div><strong>' + esc(variation.effective_sku || variation.sku || ('#' + variation.id)) + '</strong><span>' +
					esc(attrs || variation.name || '') + '</span><small>' + money(variation.price) + ' · Stock ' +
					esc(variation.stock === null || typeof variation.stock === 'undefined' ? '—' : variation.stock) + '</small></div>';
			}).join('') + '</div></details>';
	}

	function productHtml(product, platform) {
		if (!product) {
			return '<div class="dolisync-product-empty"><span class="dashicons dashicons-minus"></span><strong>No existe en ' + esc(platform) + '</strong></div>';
		}
		const edit = product.edit_url ? '<a href="' + esc(product.edit_url) + '" class="dolisync-product-edit" aria-label="Editar producto"><span class="dashicons dashicons-external"></span></a>' : '';
		return '<div class="dolisync-product-card">' +
			'<div class="dolisync-product-card-head"><span class="dolisync-platform dolisync-platform-' + esc(platform.toLowerCase()) + '">' + esc(platform) + '</span>' + edit + '</div>' +
			'<h3>' + esc(product.name || 'Sin nombre') + '</h3>' +
			'<div class="dolisync-product-meta"><code>' + esc(product.effective_sku || product.sku || 'Sin SKU') + '</code>' + (product.sku_generated ? '<span title="WooCommerce no tiene SKU; DoliSync genera esta referencia al exportar">generado</span>' : '') + '<span>#' + esc(product.id) + '</span><span>' + esc(product.type || '') + '</span></div>' +
			'<div class="dolisync-product-numbers"><span><small>Precio sin IVA</small><strong>' + money(product.price) + '</strong></span><span><small>Stock</small><strong>' +
			esc(product.stock === null || typeof product.stock === 'undefined' ? '—' : product.stock) + '</strong></span></div>' +
			variationHtml(product.variations) + '</div>';
	}

	function statusHtml(row) {
		if (row.ignored) {
			return '<span class="dolisync-match dolisync-match-ignored"><span class="dashicons dashicons-hidden"></span> Omitido</span><small>' + esc(row.ignored_at || '') + '</small>';
		}
		if (row.comparison === 'match') {
			return '<span class="dolisync-match dolisync-match-ok"><span class="dashicons dashicons-yes-alt"></span> Coincide</span>';
		}
		if (row.comparison === 'different') {
			return '<span class="dolisync-match dolisync-match-warning"><span class="dashicons dashicons-warning"></span> Diferencias</span><small>' + esc((row.differences || []).join(', ')) + '</small>';
		}
		return '<span class="dolisync-match dolisync-match-missing"><span class="dashicons dashicons-minus"></span> Sin pareja</span>';
	}

	function actionsHtml(row) {
		const wcId = row.woo ? row.woo.id : 0;
		const dolibarrId = row.dolibarr ? row.dolibarr.id : 0;
		return '<div class="dolisync-row-actions" data-wc-id="' + wcId + '" data-dolibarr-id="' + dolibarrId + '">' +
			'<button class="button dolisync-product-action" data-operation="refresh" ' + (!dolibarrId || row.ignored ? 'disabled' : '') + ' title="Obtener de nuevo este producto de Dolibarr"><span class="dashicons dashicons-update"></span><span>Refrescar</span></button>' +
			'<button class="button dolisync-product-action" data-operation="woo_to_dolibarr" ' + (!wcId || row.ignored ? 'disabled' : '') + ' title="Sincronizar WooCommerce a Dolibarr"><span class="dashicons dashicons-arrow-right-alt"></span><span>Woo → Doli</span></button>' +
			'<button class="button dolisync-product-action" data-operation="dolibarr_to_woo" ' + (!dolibarrId || row.ignored ? 'disabled' : '') + ' title="Sincronizar Dolibarr a WooCommerce"><span class="dashicons dashicons-arrow-left-alt"></span><span>Doli → Woo</span></button>' +
			'<button class="button dolisync-product-action" data-operation="' + (row.ignored ? 'restore' : 'ignore') + '"><span class="dashicons ' + (row.ignored ? 'dashicons-undo' : 'dashicons-hidden') + '"></span><span>' + (row.ignored ? 'Restaurar' : 'Omitir') + '</span></button></div>';
	}

	function render() {
		const start = (page - 1) * pageSize;
		const visible = filteredRows.slice(start, start + pageSize);
		if (!visible.length) {
			jQuery('#dolisync-products-table').html('<div class="dolisync-products-zero"><span class="dashicons dashicons-search"></span><h2>No hay productos que coincidan</h2><p>Prueba con otro nombre, SKU o ID.</p></div>');
		} else {
			jQuery('#dolisync-products-table').html('<table class="dolisync-products-table"><thead><tr><th>WooCommerce</th><th>Dolibarr</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>' +
				visible.map(function (row) {
					const alone = !row.woo || !row.dolibarr;
					return '<tr class="' + (row.ignored ? 'dolisync-row-ignored' : (alone ? 'dolisync-row-unmatched' : '')) + '">' +
						'<td>' + productHtml(row.woo, 'Woo') + '</td><td>' + productHtml(row.dolibarr, 'Dolibarr') + '</td>' +
						'<td class="dolisync-status-cell">' + statusHtml(row) + (row.linked ? '<small class="dolisync-linked">Vinculado' + (row.synced_at ? ' · ' + esc(row.synced_at) : '') + '</small>' : '') + '</td>' +
						'<td>' + actionsHtml(row) + '</td></tr>';
				}).join('') + '</tbody></table>');
		}
		const pages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
		jQuery('#dolisync-products-pagination').html('<button class="button" data-page="' + (page - 1) + '" ' + (page <= 1 ? 'disabled' : '') + '>← Anterior</button><span>Página ' + page + ' de ' + pages + '</span><button class="button" data-page="' + (page + 1) + '" ' + (page >= pages ? 'disabled' : '') + '>Siguiente →</button>');
	}

	function applySearch() {
		const query = jQuery('#dolisync-products-search').val().toString().trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
		const status = jQuery('#dolisync-products-status-filter').val() || 'all';
		filteredRows = rows.filter(function (row) {
			const matchesSearch = !query || row.search.indexOf(query) !== -1;
			const matchesStatus = status === 'all' || (status === 'ignored' ? row.ignored : (status === 'match' ? !row.ignored && row.comparison === 'match' : !row.ignored && row.comparison !== 'match'));
			return matchesSearch && matchesStatus;
		});
		page = 1;
		render();
	}

	function loadCatalog(showNotice) {
		jQuery('#dolisync-products-table').html('<div class="dolisync-products-loading"><span class="spinner is-active"></span>Leyendo ambos catálogos…</div>');
		jQuery.post(DoliSync.ajaxUrl, {action: 'dolisync_products_catalog', nonce: DoliSync.nonce}).done(function (response) {
			if (!response.success) {
				jQuery('#dolisync-products-table').html('<div class="dolisync-products-zero"><span class="dashicons dashicons-warning"></span><h2>No se pudo cargar el catálogo</h2><p>' + esc(response.data && response.data.message ? response.data.message : 'Comprueba la conexión con Dolibarr.') + '</p></div>');
				return;
			}
			rows = response.data.rows || [];
			pageSize = response.data.page_size || 20;
			const summary = response.data.summary || {};
			jQuery('#dolisync-products-summary').html('<span><strong>' + (summary.total || 0) + '</strong> filas</span><span class="is-ok"><strong>' + (summary.matching || 0) + '</strong> coinciden</span><span><strong>' + (summary.unmatched || 0) + '</strong> sin pareja</span><span><strong>' + (summary.ignored || 0) + '</strong> omitidos</span>');
			applySearch();
			if (showNotice) {
				jQuery('#dolisync-products-notice').html('<div class="notice notice-success inline"><p>Catálogo actualizado.</p></div>');
			}
		}).fail(function (xhr) {
			jQuery('#dolisync-products-table').html('<div class="dolisync-products-zero"><span class="dashicons dashicons-warning"></span><h2>No se pudo cargar el catálogo</h2><p>' + dolisyncAjaxError(xhr, 'Comprueba la conexión con Dolibarr.') + '</p></div>');
		});
	}

	jQuery(document).on('input', '#dolisync-products-search', applySearch);
	jQuery(document).on('change', '#dolisync-products-status-filter', applySearch);
	jQuery(document).on('click', '#dolisync-products-pagination button', function () {
		page = Number(jQuery(this).data('page')) || 1;
		render();
	});
	jQuery(document).on('click', '.dolisync-catalog-reload', function () { loadCatalog(true); });
	jQuery(document).on('click', '.dolisync-product-action', function () {
		const $button = jQuery(this);
		const $actions = $button.closest('.dolisync-row-actions');
		const operation = $button.data('operation');
		$actions.find('button').prop('disabled', true);
		$button.addClass('is-busy');
		jQuery.post(DoliSync.ajaxUrl, {
			action: 'dolisync_product_action', nonce: DoliSync.nonce, operation: operation,
			wc_id: $actions.data('wc-id'), dolibarr_id: $actions.data('dolibarr-id')
		}).done(function (response) {
			if (!response.success) {
				jQuery('#dolisync-products-notice').html('<div class="notice notice-error inline"><p>' + esc(response.data && response.data.message ? response.data.message : 'No se pudo completar la acción.') + '</p></div>');
				return;
			}
			jQuery('#dolisync-products-notice').html('<div class="notice notice-success inline"><p><strong>✓</strong> ' + esc(response.data.message) + '</p></div>');
			loadCatalog(false);
		}).fail(function (xhr) {
			jQuery('#dolisync-products-notice').html('<div class="notice notice-error inline"><p>' + dolisyncAjaxError(xhr, 'No se pudo completar la acción.') + '</p></div>');
		}).always(function () {
			$button.removeClass('is-busy');
			$actions.find('[data-operation="refresh"], [data-operation="dolibarr_to_woo"]').prop('disabled', !$actions.data('dolibarr-id'));
			$actions.find('[data-operation="woo_to_dolibarr"]').prop('disabled', !$actions.data('wc-id'));
			$actions.find('[data-operation="ignore"], [data-operation="restore"]').prop('disabled', false);
		});
	});

	loadCatalog(false);
}

jQuery(dolisyncInitProductsCatalog);

function dolisyncInitOrdersCatalog() {
	const $app = jQuery('.dolisync-orders-app');
	if (!$app.length) {
		return;
	}

	let rows = [];
	let filteredRows = [];
	let page = 1;
	let pageSize = 20;
	let selectedOrders = new Set();
	const esc = (value) => dolisyncEscapeHtml(value === null || typeof value === 'undefined' ? '' : String(value));

	function badge(ok, yes, no) {
		return '<span class="dolisync-match ' + (ok ? 'dolisync-match-ok' : 'dolisync-match-missing') + '"><span class="dashicons ' + (ok ? 'dashicons-yes-alt' : 'dashicons-minus') + '"></span>' + esc(ok ? yes : no) + '</span>';
	}

	function emailStatusHtml(email) {
		if (email.sent) {
			return '<span class="dolisync-match dolisync-match-ok"><span class="dashicons dashicons-yes-alt"></span>Aceptado por el servidor</span>';
		}
		if (email.status === 'queued' || email.status === 'retrying' || email.status === 'sending') {
			const label = email.status === 'queued' ? 'En cola para reintento' : (email.status === 'retrying' ? 'Reintentando' : 'Enviando');
			return '<span class="dolisync-match dolisync-match-warning"><span class="dashicons dashicons-update"></span>' + label + '</span>';
		}
		return '<span class="dolisync-match dolisync-match-missing"><span class="dashicons dashicons-minus"></span>' + (email.status === 'failed' ? 'Fallido' : 'Pendiente') + '</span>';
	}

	function render() {
		const start = (page - 1) * pageSize;
		const visible = filteredRows.slice(start, start + pageSize);
		if (!visible.length) {
			jQuery('#dolisync-orders-table').html('<div class="dolisync-products-zero"><span class="dashicons dashicons-search"></span><h2>No hay pedidos que coincidan</h2><p>Prueba con otro pedido, cliente, email o factura.</p></div>');
		} else {
			const allSelected = filteredRows.length > 0 && filteredRows.every(function (row) { return selectedOrders.has(Number(row.id)); });
			jQuery('#dolisync-orders-table').html('<table class="dolisync-products-table dolisync-orders-table"><thead><tr><th class="dolisync-select-cell"><input type="checkbox" class="dolisync-orders-select-all" aria-label="Seleccionar todos los resultados" ' + (allSelected ? 'checked' : '') + '></th><th>Pedido WooCommerce</th><th>Dolibarr</th><th>Factura por email</th><th>PDF local</th><th>Acciones</th></tr></thead><tbody>' + visible.map(function (row) {
				const doli = row.dolibarr || {};
				const email = row.email || {};
				const pdf = row.pdf || {};
				const error = doli.error ? '<small class="dolisync-order-error" title="' + esc(doli.error) + '">' + esc(doli.error) + '</small>' : '';
				const omitted = '<span class="dolisync-match dolisync-match-ignored"><span class="dashicons dashicons-hidden"></span>Omitido</span><small>' + esc(row.ignored_at || '') + '</small>';
				return '<tr class="dolisync-order-' + esc(row.overall) + '"><td class="dolisync-select-cell"><input type="checkbox" class="dolisync-order-select" value="' + esc(row.id) + '" ' + (selectedOrders.has(Number(row.id)) ? 'checked' : '') + '></td><td><div class="dolisync-order-card"><div><a href="' + esc(row.edit_url) + '"><strong>#' + esc(row.number) + '</strong></a><span class="dolisync-order-state">' + esc(row.status) + '</span></div><h3>' + esc(row.customer) + '</h3><small>' + esc(row.email_address || 'Sin email') + '</small><div class="dolisync-order-meta"><span>' + esc(row.date) + '</span><strong>' + esc(row.total) + '</strong></div></div></td>' +
					'<td class="dolisync-status-cell">' + (row.ignored ? omitted : badge(doli.sent, 'Enviado correctamente', doli.invoice_id ? 'Sincronización pendiente' : 'No enviado') + '<small>' + (doli.invoice_ref ? esc(doli.invoice_ref) : (doli.invoice_id ? 'Factura #' + esc(doli.invoice_id) : 'Sin factura vinculada')) + (doli.synced_at ? ' · ' + esc(doli.synced_at) : '') + '</small>' + error) + '</td>' +
					'<td class="dolisync-status-cell">' + (row.ignored ? omitted : emailStatusHtml(email) + '<small>' + esc(email.at ? ('Aceptado: ' + email.at) : ('Intentos: ' + (email.attempts || 0))) + '</small>' + (email.next_retry_at ? '<small>Próximo intento: ' + esc(email.next_retry_at) + '</small>' : '') + (email.error ? '<small class="dolisync-order-error">' + esc(email.error) + '</small>' : '')) + '</td>' +
					'<td class="dolisync-status-cell">' + (row.ignored ? omitted : badge(pdf.available, 'PDF disponible', 'PDF no disponible') + '<small class="dolisync-order-filename" title="' + esc(pdf.filename || '') + '">' + esc(pdf.filename || '') + '</small>' + (pdf.downloaded_at ? '<small>' + esc(pdf.downloaded_at) + '</small>' : '') + (pdf.error ? '<small class="dolisync-order-error">' + esc(pdf.error) + '</small>' : '')) + '</td>' +
					'<td><div class="dolisync-row-actions dolisync-order-actions" data-order-id="' + esc(row.id) + '"><button class="button dolisync-order-action" data-operation="refresh" ' + (!doli.invoice_id || row.ignored ? 'disabled' : '') + '><span class="dashicons dashicons-update"></span><span>Actualizar</span></button><button class="button dolisync-order-action" data-operation="resend_email" ' + (!doli.invoice_id || row.ignored ? 'disabled' : '') + '><span class="dashicons dashicons-email-alt"></span><span>Reenviar email</span></button><a class="button dolisync-order-download ' + (!pdf.available || row.ignored ? 'disabled' : '') + '" href="' + esc(DoliSync.ajaxUrl + '?action=dolisync_order_download_pdf&nonce=' + encodeURIComponent(DoliSync.nonce) + '&order_id=' + row.id) + '" ' + (!pdf.available || row.ignored ? 'aria-disabled="true"' : '') + '><span class="dashicons dashicons-download"></span><span>Descargar PDF</span></a><button class="button dolisync-order-action" data-operation="' + (row.ignored ? 'restore' : 'ignore') + '"><span class="dashicons ' + (row.ignored ? 'dashicons-undo' : 'dashicons-hidden') + '"></span><span>' + (row.ignored ? 'Restaurar' : 'Omitir') + '</span></button></div></td></tr>';
			}).join('') + '</tbody></table>');
		}
		const pages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
		jQuery('#dolisync-orders-pagination').html('<button class="button" data-page="' + (page - 1) + '" ' + (page <= 1 ? 'disabled' : '') + '>← Anterior</button><span>Página ' + page + ' de ' + pages + '</span><button class="button" data-page="' + (page + 1) + '" ' + (page >= pages ? 'disabled' : '') + '>Siguiente →</button>');
		jQuery('.dolisync-orders-bulk [data-bulk-operation]').prop('disabled', selectedOrders.size === 0);
	}

	function applySearch() {
		const query = jQuery('#dolisync-orders-search').val().toString().trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
		const status = jQuery('#dolisync-orders-status-filter').val() || 'all';
		filteredRows = rows.filter(function (row) {
			return (!query || row.search.indexOf(query) !== -1) && (status === 'all' || row.overall === status);
		});
		page = 1;
		render();
	}

	function loadOrders(showNotice) {
		jQuery('#dolisync-orders-table').html('<div class="dolisync-products-loading"><span class="spinner is-active"></span>Leyendo pedidos…</div>');
		jQuery.post(DoliSync.ajaxUrl, {action: 'dolisync_orders_catalog', nonce: DoliSync.nonce}).done(function (response) {
			if (!response.success) {
				jQuery('#dolisync-orders-table').html('<div class="dolisync-products-zero"><span class="dashicons dashicons-warning"></span><h2>No se pudieron cargar los pedidos</h2><p>' + esc(response.data && response.data.message ? response.data.message : 'Inténtalo de nuevo.') + '</p></div>');
				return;
			}
			rows = response.data.rows || [];
			pageSize = response.data.page_size || 20;
			const summary = response.data.summary || {};
			jQuery('#dolisync-orders-summary').html('<span><strong>' + (summary.total || 0) + '</strong> pedidos</span><span class="is-ok"><strong>' + (summary.ok || 0) + '</strong> completos</span><span><strong>' + (summary.emailed || 0) + '</strong> emails aceptados</span><span><strong>' + (summary.pdf || 0) + '</strong> con PDF</span><span><strong>' + (summary.ignored || 0) + '</strong> omitidos</span>');
			selectedOrders.clear();
			applySearch();
			if (showNotice) {
				jQuery('#dolisync-orders-notice').html('<div class="notice notice-success inline"><p>Pedidos actualizados.</p></div>');
			}
		}).fail(function (xhr) {
			jQuery('#dolisync-orders-table').html('<div class="dolisync-products-zero"><span class="dashicons dashicons-warning"></span><h2>No se pudieron cargar los pedidos</h2><p>' + dolisyncAjaxError(xhr, 'Inténtalo de nuevo.') + '</p></div>');
		});
	}

	jQuery(document).on('input', '#dolisync-orders-search', applySearch);
	jQuery(document).on('change', '#dolisync-orders-status-filter', applySearch);
	jQuery(document).on('click', '#dolisync-orders-pagination button', function () { page = Number(jQuery(this).data('page')) || 1; render(); });
	jQuery(document).on('click', '.dolisync-orders-reload', function () { loadOrders(true); });
	jQuery(document).on('change', '.dolisync-orders-select-all', function () {
		const checked = this.checked;
		filteredRows.forEach(function (row) { if (checked) { selectedOrders.add(Number(row.id)); } else { selectedOrders.delete(Number(row.id)); } });
		render();
	});
	jQuery(document).on('change', '.dolisync-order-select', function () {
		const id = Number(this.value); if (this.checked) { selectedOrders.add(id); } else { selectedOrders.delete(id); } render();
	});
	jQuery(document).on('click', '.dolisync-orders-bulk [data-bulk-operation]', function () {
		const operation = jQuery(this).data('bulk-operation');
		jQuery.post(DoliSync.ajaxUrl, {action: 'dolisync_order_action', nonce: DoliSync.nonce, operation: 'bulk_' + operation, order_ids: Array.from(selectedOrders)}).done(function (response) {
			if (!response.success) { jQuery('#dolisync-orders-notice').html('<div class="notice notice-error inline"><p>' + esc(response.data && response.data.message ? response.data.message : 'No se pudo completar la acción.') + '</p></div>'); return; }
			jQuery('#dolisync-orders-notice').html('<div class="notice notice-success inline"><p>' + esc(response.data.message) + '</p></div>'); loadOrders(false);
		});
	});
	jQuery(document).on('click', '.dolisync-ignore-all-orders', function () {
		if (!window.confirm('¿Quieres omitir todos los pedidos de WooCommerce? Podrás restaurarlos posteriormente desde el filtro Omitidos.')) { return; }
		const $button = jQuery(this).prop('disabled', true);
		jQuery.post(DoliSync.ajaxUrl, {action: 'dolisync_order_action', nonce: DoliSync.nonce, operation: 'bulk_ignore_all'}).done(function (response) {
			if (!response.success) { jQuery('#dolisync-orders-notice').html('<div class="notice notice-error inline"><p>' + esc(response.data && response.data.message ? response.data.message : 'No se pudieron omitir los pedidos.') + '</p></div>'); return; }
			jQuery('#dolisync-orders-notice').html('<div class="notice notice-success inline"><p>' + esc(response.data.message) + '</p></div>'); loadOrders(false);
		}).fail(function (xhr) {
			jQuery('#dolisync-orders-notice').html('<div class="notice notice-error inline"><p>' + dolisyncAjaxError(xhr, 'No se pudieron omitir los pedidos.') + '</p></div>');
		}).always(function () { $button.prop('disabled', false); });
	});
	jQuery(document).on('click', '.dolisync-order-download.disabled', function (event) { event.preventDefault(); });
	jQuery(document).on('click', '.dolisync-order-action', function () {
		const $button = jQuery(this);
		const $actions = $button.closest('.dolisync-order-actions');
		$actions.find('.button').addClass('disabled').prop('disabled', true);
		$button.addClass('is-busy');
		jQuery.post(DoliSync.ajaxUrl, {action: 'dolisync_order_action', nonce: DoliSync.nonce, order_id: $actions.data('order-id'), operation: $button.data('operation')}).done(function (response) {
			if (!response.success) {
				jQuery('#dolisync-orders-notice').html('<div class="notice notice-error inline"><p>' + esc(response.data && response.data.message ? response.data.message : 'No se pudo completar la acción.') + '</p></div>');
				return;
			}
			jQuery('#dolisync-orders-notice').html('<div class="notice notice-success inline"><p><strong>✓</strong> ' + esc(response.data.message) + '</p></div>');
			loadOrders(false);
		}).fail(function (xhr) {
			jQuery('#dolisync-orders-notice').html('<div class="notice notice-error inline"><p>' + dolisyncAjaxError(xhr, 'No se pudo completar la acción.') + '</p></div>');
		}).always(function () {
			$button.removeClass('is-busy');
			$actions.find('.button').removeClass('disabled').prop('disabled', false);
		});
	});
	loadOrders(false);
}

jQuery(dolisyncInitOrdersCatalog);

function dolisyncInitCustomersCatalog() {
	const $app = jQuery('.dolisync-customers-app');
	if (!$app.length) { return; }
	let rows = [], filteredRows = [], page = 1, pageSize = 20;
	const esc = function (value) { return jQuery('<div>').text(value == null ? '' : value).html(); };
	const badge = function (row) {
		if (row.status === 'ignored') { return '<span class="dolisync-match dolisync-match-ignored"><span class="dashicons dashicons-hidden"></span>Omitido</span>'; }
		if (row.dolibarr_id) { return '<span class="dolisync-match dolisync-match-ok"><span class="dashicons dashicons-yes-alt"></span>Enviado correctamente</span>'; }
		if (row.status === 'incomplete') { return '<span class="dolisync-match dolisync-match-missing"><span class="dashicons dashicons-warning"></span>Faltan datos</span>'; }
		return '<span class="dolisync-match dolisync-match-warning"><span class="dashicons dashicons-clock"></span>Pendiente de envío</span>';
	};
	function render() {
		const start = (page - 1) * pageSize;
		const visible = filteredRows.slice(start, start + pageSize);
		if (!visible.length) {
			jQuery('#dolisync-customers-table').html('<div class="dolisync-products-zero"><span class="dashicons dashicons-groups"></span><h2>No hay clientes que coincidan</h2><p>Prueba con otro nombre, email, DNI o estado.</p></div>');
		} else {
			jQuery('#dolisync-customers-table').html('<table class="dolisync-products-table dolisync-customers-table"><thead><tr><th>Cliente WooCommerce</th><th>Datos de contacto</th><th>Calidad de datos</th><th>Dolibarr</th><th>Acciones</th></tr></thead><tbody>' + visible.map(function (row) {
				const required = row.missing && row.missing.length ? '<div class="dolisync-customer-alert"><strong>Necesario:</strong> ' + esc(row.missing.join(', ')) + '</div>' : '<span class="dolisync-customer-complete"><span class="dashicons dashicons-yes"></span> Datos esenciales completos</span>';
				const recommended = row.recommended && row.recommended.length ? '<small>Recomendado completar: ' + esc(row.recommended.join(', ')) + '</small>' : '<small>Ficha de contacto completa</small>';
				const linkOrigin = row.relation_source && row.relation_source.indexOf('order') !== -1 ? ' · vinculado desde pedido' : '';
				const doliMeta = row.dolibarr_id ? '<small>Tercero #' + esc(row.dolibarr_id) + linkOrigin + (row.synced_at ? ' · ' + esc(row.synced_at) : '') + '</small>' : '<small>Sin tercero vinculado</small>';
				return '<tr class="dolisync-customer-' + esc(row.status) + '"><td><div class="dolisync-customer-card"><div><span class="dolisync-customer-avatar">' + esc((row.name || '?').charAt(0).toUpperCase()) + '</span><div><a href="' + esc(row.edit_url) + '"><strong>' + esc(row.name) + '</strong></a><small>Cliente #' + esc(row.id) + ' · Alta ' + esc(row.registered) + '</small></div></div><span class="dolisync-order-state">' + esc(row.orders) + ' pedidos</span></div></td>' +
					'<td><div class="dolisync-customer-contact"><strong>' + esc(row.email || 'Sin email') + '</strong><span>' + esc(row.phone || 'Sin teléfono') + '</span><small>' + esc(row.address || 'Sin dirección') + (row.country ? ' · ' + esc(row.country) : '') + '</small></div></td>' +
					'<td><div class="dolisync-customer-quality">' + required + recommended + (row.dni ? '<code>' + esc(row.dni) + '</code>' : '') + '</div></td>' +
					'<td class="dolisync-status-cell">' + badge(row) + doliMeta + (row.ignored_at ? '<small>Desde ' + esc(row.ignored_at) + '</small>' : '') + '</td>' +
					'<td><div class="dolisync-row-actions dolisync-customer-actions" data-user-id="' + esc(row.id) + '"><button class="button dolisync-customer-action" data-operation="sync" ' + (row.status === 'ignored' || (row.missing && row.missing.length) ? 'disabled' : '') + '><span class="dashicons dashicons-update"></span><span>' + (row.dolibarr_id ? 'Volver a sincronizar' : 'Vincular / enviar') + '</span></button><a class="button" href="' + esc(row.edit_url) + '"><span class="dashicons dashicons-edit"></span><span>Editar datos</span></a><button class="button dolisync-customer-action" data-operation="' + (row.status === 'ignored' ? 'restore' : 'ignore') + '"><span class="dashicons ' + (row.status === 'ignored' ? 'dashicons-undo' : 'dashicons-hidden') + '"></span><span>' + (row.status === 'ignored' ? 'Restaurar' : 'Omitir') + '</span></button></div></td></tr>';
			}).join('') + '</tbody></table>');
		}
		const pages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
		if (page > pages) { page = pages; return render(); }
		jQuery('#dolisync-customers-pagination').html('<button class="button" data-page="' + (page - 1) + '" ' + (page <= 1 ? 'disabled' : '') + '>← Anterior</button><span>Página ' + page + ' de ' + pages + '</span><button class="button" data-page="' + (page + 1) + '" ' + (page >= pages ? 'disabled' : '') + '>Siguiente →</button>');
	}
	function applySearch() {
		const query = jQuery('#dolisync-customers-search').val().toString().trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
		const status = jQuery('#dolisync-customers-status-filter').val() || 'all';
		filteredRows = rows.filter(function (row) {
			const statusMatches = status === 'all' || row.status === status || (status === 'synced' && row.dolibarr_id && row.status !== 'ignored');
			return (!query || row.search.indexOf(query) !== -1) && statusMatches;
		});
		page = 1; render();
	}
	function loadCustomers(showNotice) {
		jQuery('#dolisync-customers-table').html('<div class="dolisync-products-loading"><span class="spinner is-active"></span>Leyendo clientes…</div>');
		jQuery.post(DoliSync.ajaxUrl, {action: 'dolisync_customers_catalog', nonce: DoliSync.nonce}).done(function (response) {
			if (!response.success) { jQuery('#dolisync-customers-table').html('<div class="dolisync-products-zero"><span class="dashicons dashicons-warning"></span><h2>No se pudieron cargar los clientes</h2><p>' + esc(response.data && response.data.message ? response.data.message : 'Inténtalo de nuevo.') + '</p></div>'); return; }
			rows = response.data.rows || []; pageSize = response.data.page_size || 20;
			const summary = response.data.summary || {};
			jQuery('#dolisync-customers-summary').html('<span><strong>' + (summary.total || 0) + '</strong> clientes</span><span class="is-ok"><strong>' + (summary.synced || 0) + '</strong> enviados</span><span><strong>' + (summary.pending || 0) + '</strong> pendientes</span><span><strong>' + (summary.incomplete || 0) + '</strong> incompletos</span><span><strong>' + (summary.ignored || 0) + '</strong> omitidos</span>');
			applySearch();
			if (showNotice) { jQuery('#dolisync-customers-notice').html('<div class="notice notice-success inline"><p>Clientes actualizados.</p></div>'); }
		}).fail(function (xhr) { jQuery('#dolisync-customers-table').html('<div class="dolisync-products-zero"><span class="dashicons dashicons-warning"></span><h2>No se pudieron cargar los clientes</h2><p>' + dolisyncAjaxError(xhr, 'Inténtalo de nuevo.') + '</p></div>'); });
	}
	jQuery(document).on('input', '#dolisync-customers-search', applySearch);
	jQuery(document).on('change', '#dolisync-customers-status-filter', applySearch);
	jQuery(document).on('click', '#dolisync-customers-pagination button', function () { page = Number(jQuery(this).data('page')) || 1; render(); });
	jQuery(document).on('click', '.dolisync-customers-reload', function () { loadCustomers(true); });
	jQuery(document).on('click', '.dolisync-customer-action', function () {
		const $button = jQuery(this), $actions = $button.closest('.dolisync-customer-actions');
		$actions.find('.button').prop('disabled', true); $button.addClass('is-busy');
		jQuery.post(DoliSync.ajaxUrl, {action: 'dolisync_customer_action', nonce: DoliSync.nonce, user_id: $actions.data('user-id'), operation: $button.data('operation')}).done(function (response) {
			const type = response.success ? 'success' : 'error';
			jQuery('#dolisync-customers-notice').html('<div class="notice notice-' + type + ' inline"><p>' + esc(response.data && response.data.message ? response.data.message : 'No se pudo completar la acción.') + '</p></div>');
			if (response.success) { loadCustomers(false); }
		}).fail(function (xhr) { jQuery('#dolisync-customers-notice').html('<div class="notice notice-error inline"><p>' + dolisyncAjaxError(xhr, 'No se pudo completar la acción.') + '</p></div>'); }).always(function () { $button.removeClass('is-busy'); $actions.find('.button').prop('disabled', false); });
	});
	loadCustomers(false);
}

jQuery(dolisyncInitCustomersCatalog);
