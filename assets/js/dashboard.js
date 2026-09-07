/* global DoliSync, jQuery, dolisyncEscapeHtml, dolisyncAjaxError */
(function ($) {
	'use strict';

	const $app = $('.dolisync-dashboard');
	if (!$app.length) { return; }

	const esc = (value) => dolisyncEscapeHtml(value == null ? '' : String(value));
	const connectionLabels = {
		success: 'Conectado',
		failed: 'Conexión fallida',
		warning: 'Revisar conexión',
		unconfigured: 'Sin configurar',
		pending: 'Pendiente de comprobar'
	};
	const stockLabels = {
		success: 'Correcto',
		failed: 'Con incidencias',
		running: 'En proceso',
		pending: 'Sin ejecutar'
	};

	function pathValue(source, path) {
		return String(path || '').split('.').reduce(function (value, key) {
			return value && Object.prototype.hasOwnProperty.call(value, key) ? value[key] : null;
		}, source);
	}

	function renderValues(data) {
		$app.find('[data-dashboard-value]').each(function () {
			const value = pathValue(data, $(this).data('dashboard-value'));
			$(this).text(value === null || value === '' ? '—' : value);
		});
		$app.find('[data-dashboard-progress]').each(function () {
			const value = Math.max(0, Math.min(100, Number(pathValue(data, $(this).data('dashboard-progress')) || 0)));
			$(this).css('width', value + '%').attr('title', value + '% sincronizado');
		});
	}

	function renderConnection(connection) {
		const status = connection.status || 'pending';
		$app.attr('data-connection-status', status);
		$('#dolisync-dashboard-connection-label').text(connectionLabels[status] || connectionLabels.pending);
		if (Object.prototype.hasOwnProperty.call(connection, 'endpoint')) {
			$app.find('[data-dashboard-value="connection.endpoint"]').text(connection.endpoint || '—');
		}
		if (Object.prototype.hasOwnProperty.call(connection, 'dolibarr_version')) {
			$app.find('[data-dashboard-value="connection.dolibarr_version"]').text(connection.dolibarr_version || '—');
		}
		const checked = connection.checked_at || {};
		const checkedText = checked.display && checked.relative ? checked.relative + ' · ' + checked.display : (checked.display || 'Sin comprobar');
		const latency = connection.latency_ms == null ? '' : ' · ' + connection.latency_ms + ' ms';
		if (status === 'success') {
			$('#dolisync-dashboard-notice').empty();
		} else {
			const type = status === 'failed' ? 'error' : 'warning';
			const message = connection.message || (status === 'unconfigured' ? 'Configura DoliSync para empezar a sincronizar.' : 'La conexión necesita atención.');
			const action = status === 'unconfigured' ? '<a class="button button-primary" href="' + esc(DoliSync.settingsUrl || '') + '">Configurar conexión</a>' : '';
			$('#dolisync-dashboard-notice').html('<div class="notice notice-' + type + ' inline"><p><strong>' + esc(connectionLabels[status] || 'Aviso') + '.</strong> ' + esc(message) + ' ' + action + '</p></div>');
		}
		$('#dolisync-dashboard-updated').text('Conexión: ' + checkedText + latency);
	}

	function renderStock(stock) {
		const status = stock.running ? 'running' : (stock.status || 'pending');
		$('#dolisync-dashboard-stock-status').attr('data-status', status).text(stockLabels[status] || stockLabels.pending);
		$('.dolisync-dashboard-stock-card').attr('data-status', status);
	}

	function renderHealth(health, orders) {
		const alert = !!health.alert;
		$('#dolisync-dashboard-health-status').attr('data-status', alert ? 'warning' : 'success').text(alert ? 'Requiere atención' : 'Todo en orden');
		$('.dolisync-dashboard-health-card').attr('data-status', alert ? 'warning' : 'success');
		const issues = [];
		if (Number(health.errors_24h || 0) > 0) { issues.push(health.errors_24h + ' errores API en las últimas 24 horas'); }
		if (Number(orders.failed || 0) > 0) { issues.push(orders.failed + ' pedidos con incidencias'); }
		if (Number(health.open_conflicts || 0) > 0) { issues.push(health.open_conflicts + ' conflictos de identidad abiertos'); }
		if (Number(health.stalled_jobs || 0) > 0) { issues.push(health.stalled_jobs + ' trabajos posiblemente bloqueados'); }
		$('#dolisync-dashboard-health-summary').text(issues.length ? issues.join(' · ') + '.' : 'No se han detectado incidencias operativas que requieran atención.');
	}

	function activityIcon(type) {
		const icons = {stock: 'database', producto: 'products', pedido: 'cart', factura: 'media-document', contacto: 'groups', cliente: 'groups', sistema: 'admin-tools'};
		return icons[type] || 'update';
	}

	function renderActivity(items) {
		if (!items.length) {
			$('#dolisync-dashboard-activity').html('<div class="dolisync-dashboard-empty"><span class="dashicons dashicons-backup"></span><strong>Sin actividad registrada</strong><p>Las próximas sincronizaciones aparecerán aquí.</p></div>');
			return;
		}
		$('#dolisync-dashboard-activity').html(items.map(function (item) {
			const time = item.time || {};
			return '<article class="dolisync-dashboard-activity-item" data-status="' + esc(item.status || 'info') + '"><span class="dolisync-dashboard-activity-icon"><span class="dashicons dashicons-' + esc(activityIcon(item.type)) + '"></span></span><div><strong>' + esc(item.action || 'Actividad de DoliSync') + '</strong><p>' + esc(item.description || '') + '</p><small>' + esc(time.relative || '') + (time.display ? ' · ' + esc(time.display) : '') + '</small></div><i aria-hidden="true"></i></article>';
		}).join(''));
	}

	function render(data) {
		renderValues(data);
		renderConnection(data.connection || {});
		renderStock(data.stock || {});
		renderHealth(data.health || {}, data.orders || {});
		renderActivity(data.activity || []);
	}

	function finishLoading($button) {
		$button.prop('disabled', false).removeClass('is-loading');
	}

	function loadRemoteStatus(forceRemote, $button) {
		$.post(DoliSync.ajaxUrl, {
			action: 'dolisync_dashboard_remote_status',
			nonce: DoliSync.nonce,
			force_remote: forceRemote ? 1 : 0
		}).done(function (response) {
			if (!response.success) {
				$('#dolisync-dashboard-notice').html('<div class="notice notice-error inline"><p>' + esc(response.data && response.data.message ? response.data.message : 'No se pudo comprobar la conexión.') + '</p></div>');
				return;
			}
			renderConnection((response.data && response.data.connection) || {});
		}).fail(function (xhr) {
			$('#dolisync-dashboard-notice').html('<div class="notice notice-error inline"><p>' + dolisyncAjaxError(xhr, 'No se pudo comprobar la conexión.') + '</p></div>');
		}).always(function () {
			finishLoading($button);
		});
	}

	function loadDashboard(forceRemote) {
		const $button = $('#dolisync-dashboard-refresh').prop('disabled', true).addClass('is-loading');
		if (forceRemote) {
			$('#dolisync-dashboard-updated').text('Comprobando conexión con Dolibarr…');
		}
		$.post(DoliSync.ajaxUrl, {
			action: 'dolisync_dashboard_data',
			nonce: DoliSync.nonce
		}).done(function (response) {
			if (!response.success) {
				$('#dolisync-dashboard-notice').html('<div class="notice notice-error inline"><p>' + esc(response.data && response.data.message ? response.data.message : 'No se pudo cargar el panel.') + '</p></div>');
				finishLoading($button);
				return;
			}
			render(response.data || {});
			loadRemoteStatus(forceRemote, $button);
		}).fail(function (xhr) {
			$('#dolisync-dashboard-notice').html('<div class="notice notice-error inline"><p>' + dolisyncAjaxError(xhr, 'No se pudo cargar el panel.') + '</p></div>');
			finishLoading($button);
		});
	}

	$app.on('click', '#dolisync-dashboard-refresh', function () { loadDashboard(true); });
	loadDashboard(false);
})(jQuery);
