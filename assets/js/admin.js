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
	function toggleCfFields($scope) {
		const enabled = $scope.find('[name="cf_access_enabled"]').is(':checked');
		$scope.find('.dolisync-cf-fields input').prop('disabled', !enabled);
		$scope.find('.dolisync-cf-fields').toggleClass('is-disabled', !enabled);
	}
	$('.dolisync-onboarding, .dolisync-settings-form-panel').each(function () { toggleCfFields($(this)); });
	window.setTimeout(function () {
		$('.dolisync-onboarding, .dolisync-settings-form-panel').each(function () { toggleCfFields($(this)); });
	}, 0);
	$(document).on('change', '[name="cf_access_enabled"]', function () { toggleCfFields($(this).closest('form, .dolisync-onboarding')); });

	const $wizard = $('#dolisync-onboarding-form');
	if ($wizard.length) {
		let step = 0;
		const $steps = $wizard.find('.dolisync-onboarding-step');
		const showStep = function (next) {
			step = Math.max(0, Math.min($steps.length - 1, next));
			$steps.removeClass('is-active').eq(step).addClass('is-active');
			$('.dolisync-onboarding-progress span').each(function (i) { $(this).toggleClass('is-active', i <= step); });
			$wizard.find('.dolisync-onboarding-prev').prop('disabled', step === 0);
			$wizard.find('.dolisync-onboarding-next').prop('hidden', step === $steps.length - 1);
			$wizard.find('.dolisync-onboarding-finish').prop('hidden', step !== $steps.length - 1);
		};
		$wizard.on('click', '.dolisync-onboarding-next', function () {
			const currentInputs = $steps.eq(step).find(':input[required]');
			let valid = true; currentInputs.each(function () { if (!this.reportValidity()) valid = false; });
			if (valid) showStep(step + 1);
		});
		$wizard.on('click', '.dolisync-onboarding-prev', function () { showStep(step - 1); });
		$('#dolisync-onboarding-test').on('click', function () {
			const $button = $(this), $result = $('#dolisync-onboarding-result');
			if (!$wizard[0].reportValidity()) return;
			$button.prop('disabled', true); $result.html('<p>Conectando con Dolibarr…</p>');
			const payload = $wizard.serializeArray(); payload.push({name: 'action', value: 'dolisync_onboarding_save_test'}, {name: 'nonce', value: DoliSync.nonce});
			$.post(DoliSync.ajaxUrl, payload).done(function (response) {
				if (!response.success) { $result.html('<div class="notice notice-error inline"><p>' + dolisyncEscapeHtml(response.data.message) + '</p></div>'); return; }
				const ok = !!response.data.connected;
				$result.html('<div class="notice ' + (ok ? 'notice-success' : 'notice-error') + ' inline"><p><strong>' + (ok ? 'Conexión correcta' : 'No se pudo validar la conexión') + '</strong></p></div><pre>' + dolisyncEscapeHtml(JSON.stringify(response.data.result, null, 2)) + '</pre>');
				$wizard.find('.dolisync-onboarding-finish').prop('disabled', !ok);
			}).fail(function (xhr) { $result.html('<div class="notice notice-error inline"><p>' + dolisyncAjaxError(xhr) + '</p></div>'); }).always(function () { $button.prop('disabled', false); });
		});
		$wizard.on('click', '.dolisync-onboarding-finish', function () {
			$.post(DoliSync.ajaxUrl, {action: 'dolisync_onboarding_complete', nonce: DoliSync.nonce}).done(function (response) { if (response.success) window.location.href = response.data.redirect; });
		});
	}

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

	function productConflictCard(data, platform) {
		data = data || {};
		if (!data.id) { return '<div class="dolisync-product-empty"><span class="dashicons dashicons-minus"></span><strong>No disponible en ' + esc(platform) + '</strong></div>'; }
		return '<div class="dolisync-conflict-card"><strong>' + esc(data.name || 'Sin nombre') + '</strong><code>' + esc(data.sku || 'Sin SKU') + '</code><span>ID #' + esc(data.id) + ' · ' + esc(data.type || '') + '</span><small>Precio ' + esc(data.price == null ? '—' : data.price) + ' · Stock ' + esc(data.stock == null ? '—' : data.stock) + '</small></div>';
	}

	function loadProductConflicts(showNotice) {
		jQuery('#dolisync-product-conflicts-table').html('<div class="dolisync-products-loading"><span class="spinner is-active"></span>Leyendo conflictos…</div>');
		jQuery.post(DoliSync.ajaxUrl, {action: 'dolisync_product_conflicts', nonce: DoliSync.nonce}).done(function (response) {
			if (!response.success) { jQuery('#dolisync-product-conflicts-table').html('<div class="notice notice-error inline"><p>No se pudieron cargar los conflictos.</p></div>'); return; }
			const conflicts = response.data.rows || [];
			jQuery('#dolisync-product-conflicts-count').text(response.data.count || 0);
			if (!conflicts.length) {
				jQuery('#dolisync-product-conflicts-table').html('<div class="dolisync-products-zero"><span class="dashicons dashicons-yes-alt"></span><h2>No hay conflictos pendientes</h2><p>Las relaciones de productos son coherentes.</p></div>');
			} else {
				jQuery('#dolisync-product-conflicts-table').html('<table class="dolisync-products-table dolisync-conflicts-table"><thead><tr><th>WooCommerce</th><th>Dolibarr</th><th>Conflicto</th><th>Conservar y vincular</th></tr></thead><tbody>' + conflicts.map(function (row) {
					const disableWoo = row.wc_data && row.wc_data.id ? '' : ' disabled';
					const disableDoli = row.dolibarr_data && row.dolibarr_data.id ? '' : ' disabled';
					return '<tr><td>' + productConflictCard(row.wc_data, 'WooCommerce') + '</td><td>' + productConflictCard(row.dolibarr_data, 'Dolibarr') + '</td><td><div class="dolisync-conflict-reason"><span class="dolisync-match dolisync-match-missing"><span class="dashicons dashicons-warning"></span>Revisión necesaria</span><p>' + esc(row.message) + '</p><small>' + esc(row.conflict_type) + ' · ' + esc(row.updated_at) + '</small></div></td><td><div class="dolisync-row-actions dolisync-conflict-actions dolisync-product-conflict-actions" data-conflict-id="' + esc(row.id) + '"><button class="button button-primary dolisync-resolve-product-conflict" data-winner="dolibarr"' + disableDoli + '><span class="dashicons dashicons-arrow-left-alt"></span><span>Conservar Dolibarr</span></button><button class="button dolisync-resolve-product-conflict" data-winner="woocommerce"' + disableWoo + '><span class="dashicons dashicons-arrow-right-alt"></span><span>Conservar WooCommerce</span></button></div></td></tr>';
				}).join('') + '</tbody></table>');
			}
			if (showNotice) { jQuery('#dolisync-product-conflicts-notice').html('<div class="notice notice-success inline"><p>Conflictos actualizados.</p></div>'); }
		});
	}

	jQuery(document).on('click', '[data-products-tab]', function () {
		const tab = jQuery(this).data('products-tab');
		$app.find('[data-products-tab]').removeClass('nav-tab-active'); jQuery(this).addClass('nav-tab-active');
		$app.find('.dolisync-products-panel').prop('hidden', true); jQuery('#dolisync-products-' + tab + '-panel').prop('hidden', false);
		if (tab === 'conflicts') { loadProductConflicts(false); }
	});
	jQuery(document).on('click', '.dolisync-product-conflicts-reload', function () { loadProductConflicts(true); });
	jQuery(document).on('click', '.dolisync-resolve-product-conflict', function () {
		const $button = jQuery(this), $actions = $button.closest('.dolisync-product-conflict-actions');
		if (!window.confirm('Se reconstruirá la relación conservando el producto elegido. ¿Continuar?')) { return; }
		$actions.find('button').prop('disabled', true); $button.addClass('is-busy');
		jQuery.post(DoliSync.ajaxUrl, {action: 'dolisync_resolve_product_conflict', nonce: DoliSync.nonce, conflict_id: $actions.data('conflict-id'), winner: $button.data('winner')}).done(function (response) {
			const type = response.success ? 'success' : 'error';
			jQuery('#dolisync-product-conflicts-notice').html('<div class="notice notice-' + type + ' inline"><p>' + esc(response.data && response.data.message ? response.data.message : 'No se pudo resolver el conflicto.') + '</p></div>');
			if (response.success) { loadProductConflicts(false); loadCatalog(false); }
		}).always(function () { $button.removeClass('is-busy'); $actions.find('button').prop('disabled', false); });
	});

	loadCatalog(false);
	loadProductConflicts(false);
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
		const history = Array.isArray(email.history) ? email.history : [];
		const historyHtml = history.length ? '<details class="dolisync-email-history"><summary>Ver emails enviados (' + history.length + ')</summary><div class="dolisync-email-history-list">' + history.map(function (event) {
			const accepted = event.status === 'accepted';
			return '<div class="dolisync-email-history-item"><span class="dashicons ' + (accepted ? 'dashicons-yes-alt' : 'dashicons-warning') + '"></span><div><strong>' + esc(event.label) + '</strong><small>' + esc(event.at || 'Sin fecha') + ' · ' + (accepted ? 'Aceptado por el servidor' : 'Error de envío') + '</small></div></div>';
		}).join('') + '</div></details>' : '<small>Sin historial registrado</small>';
		let statusHtml = '';
		if (email.sent) {
			statusHtml = '<span class="dolisync-match dolisync-match-ok"><span class="dashicons dashicons-yes-alt"></span>Aceptado por el servidor</span>';
			return statusHtml + historyHtml;
		}
		if (email.status === 'queued' || email.status === 'retrying' || email.status === 'sending') {
			const label = email.status === 'queued' ? 'En cola para reintento' : (email.status === 'retrying' ? 'Reintentando' : 'Enviando');
			statusHtml = '<span class="dolisync-match dolisync-match-warning"><span class="dashicons dashicons-update"></span>' + label + '</span>';
			return statusHtml + historyHtml;
		}
		if (email.status === 'unavailable_sent') {
			statusHtml = '<span class="dolisync-match dolisync-match-warning"><span class="dashicons dashicons-email-alt"></span>Aviso enviado</span>';
			return statusHtml + historyHtml;
		}
		statusHtml = '<span class="dolisync-match dolisync-match-missing"><span class="dashicons dashicons-minus"></span>' + (email.status === 'failed' ? 'Fallido' : 'Pendiente') + '</span>';
		return statusHtml + historyHtml;
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
			visible.forEach(function (row) {
				const $actions = jQuery('.dolisync-order-actions[data-order-id="' + Number(row.id) + '"]');
				const doli = row.dolibarr || {};
				$actions.prepend('<button class="button dolisync-order-action" data-operation="retry_sync" ' + (row.ignored || doli.sent ? 'disabled' : '') + '><span class="dashicons dashicons-controls-repeat"></span><span>Reintentar</span></button>');
				if (!row.ignored) {
					$actions.closest('tr').find('.dolisync-status-cell').first().append('<small>Intentos de cola: ' + esc(doli.attempts || 0) + (doli.next_attempt_at ? ' · Próximo: ' + esc(doli.next_attempt_at) : '') + '</small>');
				}
			});
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
	jQuery(document).on('click', '.dolisync-orders-reload', function () {
		const $button = jQuery(this).prop('disabled', true).addClass('is-busy');
		let offset = 0;
		let updated = 0;
		let errors = [];
		const refreshBatch = function () {
			jQuery.post(DoliSync.ajaxUrl, {action: 'dolisync_orders_refresh_all', nonce: DoliSync.nonce, offset: offset}).done(function (response) {
				if (!response.success) {
					jQuery('#dolisync-orders-notice').html('<div class="notice notice-error inline"><p>' + esc(response.data && response.data.message ? response.data.message : 'No se pudieron actualizar los pedidos.') + '</p></div>');
					$button.prop('disabled', false).removeClass('is-busy');
					return;
				}
				const data = response.data || {};
				offset = Number(data.processed || offset);
				updated += Number(data.updated || 0);
				errors = errors.concat(data.errors || []);
				jQuery('#dolisync-orders-notice').html('<div class="notice notice-info inline"><p>Consultando Dolibarr: ' + esc(offset) + ' de ' + esc(data.total || 0) + ' pedidos.</p></div>');
				if (!data.done) { refreshBatch(); return; }
				const errorText = errors.length ? ' Errores: ' + errors.map(esc).join(' · ') : '';
				jQuery('#dolisync-orders-notice').html('<div class="notice ' + (errors.length ? 'notice-warning' : 'notice-success') + ' inline"><p>' + esc(updated) + ' pedidos actualizados desde Dolibarr.' + errorText + '</p></div>');
				$button.prop('disabled', false).removeClass('is-busy');
				loadOrders(false);
			}).fail(function (xhr) {
				jQuery('#dolisync-orders-notice').html('<div class="notice notice-error inline"><p>' + dolisyncAjaxError(xhr, 'No se pudieron actualizar los pedidos.') + '</p></div>');
				$button.prop('disabled', false).removeClass('is-busy');
			});
		};
		refreshBatch();
	});
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
		const operation = $button.data('operation');
		const orderId = Number($actions.data('order-id'));
		$actions.find('.button').addClass('disabled').prop('disabled', true);
		$button.addClass('is-busy');
		if (operation === 'retry_sync') {
			const progressMessages = [];
			let lastStage = '';
			const renderProgress = function (progress) {
				if (progress.stage && progress.stage !== lastStage) {
					lastStage = progress.stage;
					progressMessages.push(progress.message || progress.stage);
				}
				jQuery('#dolisync-orders-notice').html('<div class="notice notice-info inline dolisync-order-progress"><p><strong>Sincronizando el pedido #' + esc(orderId) + '</strong></p><ol>' + progressMessages.map(function (message, index) { return '<li' + (index === progressMessages.length - 1 ? ' class="is-current"' : '') + '>' + esc(message) + '</li>'; }).join('') + '</ol></div>');
			};
			const poll = window.setInterval(function () {
				jQuery.post(DoliSync.ajaxUrl, {action: 'dolisync_order_queue_progress', nonce: DoliSync.nonce, order_id: orderId}).done(function (response) {
					if (response.success) { renderProgress(response.data || {}); }
				});
			}, 700);
			renderProgress({stage: 'request', message: 'Solicitando el reintento inmediato…'});
			jQuery.post(DoliSync.ajaxUrl, {action: 'dolisync_order_action', nonce: DoliSync.nonce, order_id: orderId, operation: operation}).done(function (response) {
				window.clearInterval(poll);
				if (!response.success) {
					jQuery('#dolisync-orders-notice').html('<div class="notice notice-error inline"><p><strong>La sincronización se ha detenido</strong></p><ol>' + progressMessages.map(function (message) { return '<li>' + esc(message) + '</li>'; }).join('') + '</ol><p>' + esc(response.data && response.data.message ? response.data.message : 'No se pudo completar la sincronización.') + '</p></div>');
					return;
				}
				progressMessages.push(response.data.message);
				jQuery('#dolisync-orders-notice').html('<div class="notice notice-success inline"><p><strong>✓ Proceso completado</strong></p><ol>' + progressMessages.map(function (message) { return '<li>' + esc(message) + '</li>'; }).join('') + '</ol></div>');
				loadOrders(false);
			}).fail(function (xhr) {
				window.clearInterval(poll);
				jQuery('#dolisync-orders-notice').html('<div class="notice notice-error inline"><p><strong>La sincronización se ha detenido</strong></p><ol>' + progressMessages.map(function (message) { return '<li>' + esc(message) + '</li>'; }).join('') + '</ol><p>' + dolisyncAjaxError(xhr, 'No se pudo completar la sincronización.') + '</p></div>');
			}).always(function () {
				$button.removeClass('is-busy');
				$actions.find('.button').removeClass('disabled').prop('disabled', false);
			});
			return;
		}
		jQuery.post(DoliSync.ajaxUrl, {action: 'dolisync_order_action', nonce: DoliSync.nonce, order_id: orderId, operation: operation}).done(function (response) {
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
	function conflictCard(data, system) {
		data = data || {};
		return '<div class="dolisync-conflict-card dolisync-conflict-' + system + '"><strong>' + esc(data.name || 'Sin nombre') + '</strong><code>' + esc(data.document_id || data.idprof1 || data.siren || 'Sin documento') + '</code><span>' + esc(data.email || 'Sin correo') + '</span><small>ID #' + esc(data.id || '—') + (data.phone ? ' · ' + esc(data.phone) : '') + '</small></div>';
	}
	function loadConflicts(showNotice) {
		jQuery('#dolisync-conflicts-table').html('<div class="dolisync-products-loading"><span class="spinner is-active"></span>Leyendo conflictos…</div>');
		jQuery.post(DoliSync.ajaxUrl, {action: 'dolisync_contact_conflicts', nonce: DoliSync.nonce}).done(function (response) {
			if (!response.success) { jQuery('#dolisync-conflicts-table').html('<div class="dolisync-products-zero"><span class="dashicons dashicons-warning"></span><h2>No se pudieron cargar los conflictos</h2></div>'); return; }
			const conflicts = response.data.rows || [];
			jQuery('#dolisync-conflicts-count').text(response.data.count || 0);
			if (!conflicts.length) {
				jQuery('#dolisync-conflicts-table').html('<div class="dolisync-products-zero"><span class="dashicons dashicons-yes-alt"></span><h2>No hay conflictos pendientes</h2><p>Las identidades detectadas están vinculadas sin discrepancias.</p></div>');
			} else {
				jQuery('#dolisync-conflicts-table').html('<table class="dolisync-products-table dolisync-conflicts-table"><thead><tr><th>WooCommerce</th><th>Dolibarr</th><th>Conflicto</th><th>Conservar y vincular</th></tr></thead><tbody>' + conflicts.map(function (row) {
					return '<tr><td>' + conflictCard(row.wp_data, 'woo') + '</td><td>' + conflictCard(row.dolibarr_data, 'dolibarr') + '</td><td><div class="dolisync-conflict-reason"><span class="dolisync-match dolisync-match-missing"><span class="dashicons dashicons-warning"></span>Revisión necesaria</span><p>' + esc(row.message) + '</p><small>Detectado: ' + esc(row.updated_at) + '</small></div></td><td><div class="dolisync-row-actions dolisync-conflict-actions" data-conflict-id="' + esc(row.id) + '"><button class="button button-primary dolisync-resolve-conflict" data-winner="dolibarr"><span class="dashicons dashicons-arrow-left-alt"></span><span>Conservar Dolibarr</span></button><button class="button dolisync-resolve-conflict" data-winner="woocommerce"><span class="dashicons dashicons-arrow-right-alt"></span><span>Conservar WooCommerce</span></button></div></td></tr>';
				}).join('') + '</tbody></table>');
			}
			if (showNotice) { jQuery('#dolisync-conflicts-notice').html('<div class="notice notice-success inline"><p>Conflictos actualizados.</p></div>'); }
		}).fail(function (xhr) { jQuery('#dolisync-conflicts-table').html('<div class="notice notice-error inline"><p>' + dolisyncAjaxError(xhr, 'No se pudieron cargar los conflictos.') + '</p></div>'); });
	}
	jQuery(document).on('click', '.dolisync-customers-tabs [data-customers-tab]', function () {
		const tab = jQuery(this).data('customers-tab');
		jQuery('.dolisync-customers-tabs .nav-tab').removeClass('nav-tab-active'); jQuery(this).addClass('nav-tab-active');
		jQuery('.dolisync-customers-panel').prop('hidden', true); jQuery('#dolisync-customers-' + tab + '-panel').prop('hidden', false);
		if (tab === 'conflicts') { loadConflicts(false); }
	});
	jQuery(document).on('click', '.dolisync-conflicts-reload', function () { loadConflicts(true); });
	jQuery(document).on('click', '.dolisync-resolve-conflict', function () {
		const $button = jQuery(this), $actions = $button.closest('.dolisync-conflict-actions'), winner = $button.data('winner');
		if (!window.confirm('Se sobrescribirán los datos del otro sistema y ambos registros quedarán vinculados. ¿Continuar?')) { return; }
		$actions.find('.button').prop('disabled', true); $button.addClass('is-busy');
		jQuery.post(DoliSync.ajaxUrl, {action: 'dolisync_resolve_contact_conflict', nonce: DoliSync.nonce, conflict_id: $actions.data('conflict-id'), winner: winner}).done(function (response) {
			const type = response.success ? 'success' : 'error';
			jQuery('#dolisync-conflicts-notice').html('<div class="notice notice-' + type + ' inline"><p>' + esc(response.data && response.data.message ? response.data.message : 'No se pudo resolver el conflicto.') + '</p></div>');
			if (response.success) { loadConflicts(false); loadCustomers(false); }
		}).fail(function (xhr) { jQuery('#dolisync-conflicts-notice').html('<div class="notice notice-error inline"><p>' + dolisyncAjaxError(xhr, 'No se pudo resolver el conflicto.') + '</p></div>'); }).always(function () { $button.removeClass('is-busy'); $actions.find('.button').prop('disabled', false); });
	});
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
	loadConflicts(false);
}

jQuery(dolisyncInitCustomersCatalog);

// Simulación global de sincronizaciones: este flujo solo realiza lecturas.
jQuery(document).on('click', '.dolisync-preview-sync', function () {
	const $button = jQuery(this).prop('disabled', true).addClass('is-busy');
	const resource = String($button.data('resource') || '');
	const direction = String($button.data('direction') || '');
	jQuery.post(DoliSync.ajaxUrl, {action: 'dolisync_dry_run', nonce: DoliSync.nonce, resource: resource, direction: direction}).done(function (response) {
		if (!response.success) { window.alert(response.data && response.data.message ? response.data.message : 'No se pudo completar la simulación.'); return; }
		const s = response.data.summary || {};
		const warnings = (response.data.warnings || []).map(function (item) { return '\n• ' + item; }).join('');
		window.alert('Simulación de solo lectura\n\nCrear: ' + (s.create || 0) + '\nActualizar: ' + (s.update || 0) + '\nOmitir: ' + (s.skip || 0) + '\nConflictos: ' + (s.conflicts || 0) + '\nAdvertencias: ' + (s.warnings || 0) + warnings + '\n\nNo se ha modificado ningún dato.');
	}).fail(function (xhr) {
		window.alert(dolisyncAjaxError(xhr, 'No se pudo completar la simulación.'));
	}).always(function () { $button.prop('disabled', false).removeClass('is-busy'); });
});

jQuery(document).on('click', '#dolisync-copy-diagnostic', function () {
	const $button = jQuery(this).prop('disabled', true);
	jQuery.post(DoliSync.ajaxUrl, {action: 'dolisync_diagnostic_report', nonce: DoliSync.nonce}).done(function (response) {
		if (!response.success) { jQuery('#dolisync-diagnostic-result').text('No se pudo generar el informe.'); return; }
		const report = JSON.stringify(response.data.report, null, 2);
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(report).then(function () { jQuery('#dolisync-diagnostic-result').text('Informe copiado.'); });
		} else {
			window.prompt('Copia el informe anónimo:', report);
		}
	}).always(function () { $button.prop('disabled', false); });
});

// Antes de una sincronización masiva, obliga a revisar una simulación reciente.
(function () {
	const targets = {
		'dolisync-sync-stock': ['stock', 'dolibarr_to_woocommerce'],
		'dolisync-sync-products-dolibarr-to-woo': ['products', 'dolibarr_to_woocommerce'],
		'dolisync-sync-products-woo-to-dolibarr': ['products', 'woocommerce_to_dolibarr'],
		'dolisync-sync-dolibarr-to-woo': ['contacts', 'dolibarr_to_woocommerce'],
		'dolisync-sync-woo-to-dolibarr': ['contacts', 'woocommerce_to_dolibarr']
	};
	document.addEventListener('click', function (event) {
		const button = event.target.closest ? event.target.closest('button[id]') : null;
		if (!button || !targets[button.id] || button.dataset.dolisyncPreviewApproved === '1') { return; }
		event.preventDefault();
		event.stopImmediatePropagation();
		const target = targets[button.id];
		button.disabled = true;
		jQuery.post(DoliSync.ajaxUrl, {action: 'dolisync_dry_run', nonce: DoliSync.nonce, resource: target[0], direction: target[1]}).done(function (response) {
			if (!response.success) { window.alert(response.data && response.data.message ? response.data.message : 'No se pudo simular la sincronización.'); return; }
			const s = response.data.summary || {};
			const message = 'Revisión previa (sin cambios)\n\nCrear: ' + (s.create || 0) + '\nActualizar: ' + (s.update || 0) + '\nOmitir: ' + (s.skip || 0) + '\nConflictos: ' + (s.conflicts || 0) + '\nAdvertencias: ' + (s.warnings || 0) + '\n\n¿Ejecutar ahora la sincronización?';
			if (window.confirm(message)) {
				button.dataset.dolisyncPreviewApproved = '1';
				button.disabled = false;
				button.click();
				window.setTimeout(function () { delete button.dataset.dolisyncPreviewApproved; }, 1000);
			}
		}).fail(function (xhr) {
			window.alert(dolisyncAjaxError(xhr, 'No se pudo simular la sincronización.'));
		}).always(function () { button.disabled = false; });
	}, true);
}());
