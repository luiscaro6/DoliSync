/* global DoliSync, jQuery, dolisyncEscapeHtml, dolisyncAjaxError */
(function ($) {
	'use strict';
	const $app = $('.dolisync-categories-app');
	if (!$app.length) { return; }
	const esc = (value) => dolisyncEscapeHtml(value == null ? '' : String(value));
	let rows = [];
	let simulationToken = '';
	let simulationDirection = '';

	function card(category, platform) {
		if (!category) { return '<div class="dolisync-product-empty"><span class="dashicons dashicons-minus"></span><strong>No existe en ' + esc(platform) + '</strong></div>'; }
		return '<div class="dolisync-conflict-card"><strong>' + esc(category.name || 'Sin nombre') + '</strong><code>' + esc(category.slug || 'sin slug') + '</code><span>ID #' + esc(category.id) + ' · Padre: ' + (category.parent_id ? '#' + esc(category.parent_id) : 'raíz') + '</span></div>';
	}

	function categoryTable(data) {
		if (!data.length) { return '<div class="dolisync-products-zero"><span class="dashicons dashicons-category"></span><h2>No hay categorías</h2></div>'; }
		return '<table class="dolisync-products-table"><thead><tr><th>WooCommerce</th><th>Dolibarr</th><th>Relación</th></tr></thead><tbody>' + data.map(function (row) {
			return '<tr><td>' + card(row.woo, 'WooCommerce') + '</td><td>' + card(row.dolibarr, 'Dolibarr') + '</td><td><span class="dolisync-match ' + (row.linked ? 'dolisync-match-ok' : 'dolisync-match-missing') + '"><span class="dashicons ' + (row.linked ? 'dashicons-yes-alt' : 'dashicons-warning') + '"></span>' + (row.linked ? 'Relacionada' : 'Sin relacionar') + '</span>' + (row.synced_at ? '<small class="dolisync-linked">' + esc(row.synced_at) + '</small>' : '') + '</td></tr>';
		}).join('') + '</tbody></table>';
	}

	function renderCatalog() {
		const query = String($('#dolisync-categories-search').val() || '').toLowerCase();
		const status = $('#dolisync-categories-status').val() || 'all';
		const filtered = rows.filter(function (row) { return (!query || String(row.search || '').indexOf(query) !== -1) && (status === 'all' || (status === 'linked') === !!row.linked); });
		$('#dolisync-categories-table').html(categoryTable(filtered));
	}

	function loadCatalog() {
		$('#dolisync-categories-table').html('<div class="dolisync-products-loading"><span class="spinner is-active"></span>Leyendo ambos catálogos…</div>');
		$.post(DoliSync.ajaxUrl, {action: 'dolisync_categories_catalog', nonce: DoliSync.nonce}).done(function (response) {
			if (!response.success) { $('#dolisync-categories-notice').html('<div class="notice notice-error inline"><p>' + esc(response.data && response.data.message ? response.data.message : 'No se pudo cargar el catálogo.') + '</p></div>'); return; }
			rows = response.data.rows || [];
			const summary = response.data.summary || {};
			$('#dolisync-categories-count').text(summary.total || 0);
			$('#dolisync-category-relations-count').text(summary.linked || 0);
			$('#dolisync-categories-summary').html('<strong>' + esc(summary.total || 0) + '</strong> categorías · <strong>' + esc(summary.linked || 0) + '</strong> relacionadas · <strong>' + esc(summary.unlinked || 0) + '</strong> pendientes');
			$('#dolisync-category-relations-table').html(categoryTable(rows.filter(function (row) { return row.linked; })));
			renderCatalog();
		}).fail(function (xhr) { $('#dolisync-categories-notice').html('<div class="notice notice-error inline"><p>' + esc(dolisyncAjaxError(xhr, 'No se pudo cargar el catálogo.')) + '</p></div>'); });
	}

	$app.on('click', '[data-categories-tab]', function () {
		const tab = $(this).data('categories-tab');
		$app.find('[data-categories-tab]').removeClass('nav-tab-active'); $(this).addClass('nav-tab-active');
		$app.find('.dolisync-categories-panel').prop('hidden', true); $('#dolisync-categories-' + tab + '-panel').prop('hidden', false);
	});
	$app.on('input change', '#dolisync-categories-search, #dolisync-categories-status', renderCatalog);
	$app.on('click', '.dolisync-categories-reload', loadCatalog);
	$app.on('click', '.dolisync-run-categories-simulation', function () {
		const $button = $(this).prop('disabled', true); simulationToken = ''; simulationDirection = String($button.data('direction') || ''); $('#dolisync-apply-categories-simulation').prop('disabled', true);
		$('#dolisync-categories-simulation-table').html('<div class="dolisync-products-loading"><span class="spinner is-active"></span>Calculando sin modificar datos…</div>');
		$.post(DoliSync.ajaxUrl, {action: 'dolisync_categories_simulation', nonce: DoliSync.nonce, direction: simulationDirection}).done(function (response) {
			if (!response.success) { $('#dolisync-categories-simulation-table').html('<div class="notice notice-error inline"><p>' + esc(response.data && response.data.message ? response.data.message : 'No se pudo simular.') + '</p></div>'); return; }
			const items = response.data.items || []; const summary = response.data.summary || {}; simulationToken = response.data.token || '';
			$('#dolisync-categories-simulation-summary').html('<strong>' + esc(simulationDirection === 'dolibarr_to_woocommerce' ? 'Dolibarr → WooCommerce' : 'WooCommerce → Dolibarr') + '</strong> · <strong>' + esc(summary.total || 0) + '</strong> elementos · <strong>' + esc(summary.create || 0) + '</strong> altas · <strong>' + esc(summary.update || 0) + '</strong> actualizaciones · <strong>' + esc(summary.link || 0) + '</strong> relaciones · <strong>' + esc(summary.review || 0) + '</strong> para revisar');
			$('#dolisync-categories-simulation-table').html(items.length ? '<table class="dolisync-products-table"><thead><tr><th>Categoría</th><th>Dirección</th><th>Acción</th><th>Padre origen</th></tr></thead><tbody>' + items.map(function (item) { const action = item.action === 'link' ? 'Relacionar' : (item.action === 'review' ? 'Revisión manual (sin slug)' : (item.action === 'update' ? 'Actualizar' : 'Crear')); return '<tr><td><strong>' + esc(item.name) + '</strong><br><code>' + esc(item.slug || 'sin slug') + '</code></td><td>' + esc(item.direction === 'dolibarr_to_woocommerce' ? 'Dolibarr → WooCommerce' : 'WooCommerce → Dolibarr') + '</td><td>' + esc(action) + '</td><td>#' + esc(item.parent_id || 0) + '</td></tr>'; }).join('') + '</tbody></table>' : '<div class="dolisync-products-zero"><span class="dashicons dashicons-yes-alt"></span><h2>Sin cambios pendientes</h2></div>');
			$('#dolisync-apply-categories-simulation').text('Aplicar ' + (simulationDirection === 'dolibarr_to_woocommerce' ? 'Doli → Woo' : 'Woo → Doli')).prop('disabled', !simulationToken || Number(summary.create || 0) + Number(summary.update || 0) + Number(summary.link || 0) === 0);
		}).fail(function (xhr) { $('#dolisync-categories-simulation-table').html('<div class="notice notice-error inline"><p>' + esc(dolisyncAjaxError(xhr, 'No se pudo simular.')) + '</p></div>'); }).always(function () { $button.prop('disabled', false); });
	});
	$app.on('click', '#dolisync-apply-categories-simulation', function () {
		if (!simulationToken || !window.confirm('¿Aplicar la simulación de categorías?')) { return; }
		const $button = $(this).prop('disabled', true);
		$.post(DoliSync.ajaxUrl, {action: 'dolisync_sync_product_categories', nonce: DoliSync.nonce, simulation_token: simulationToken, direction: simulationDirection}).done(function (response) { simulationToken = ''; simulationDirection = ''; $('#dolisync-categories-notice').html('<div class="notice ' + (response.success ? 'notice-success' : 'notice-error') + ' inline"><p>' + esc(response.data && response.data.message ? response.data.message : 'Proceso finalizado.') + '</p></div>'); if (response.success) { loadCatalog(); } }).fail(function (xhr) { $('#dolisync-categories-notice').html('<div class="notice notice-error inline"><p>' + esc(dolisyncAjaxError(xhr, 'No se pudo aplicar.')) + '</p></div>'); }).always(function () { $button.prop('disabled', true); });
	});
	$app.on('click', '#dolisync-migrate-variation-categories', function () {
		if (!window.confirm('¿Añadir a las variantes existentes las categorías de sus productos padre?')) { return; }
		const $button = $(this).prop('disabled', true);
		const $result = $('#dolisync-variation-category-migration-result');
		const totals = {checked: 0, processed: 0, updated: 0, unchanged: 0, skipped: 0, errors: 0, categories_added: 0};
		const details = [];
		let runId = '';

		function renderProgress(total) {
			$result.html('<div class="dolisync-products-loading"><span class="spinner is-active"></span>Comprobadas <strong>' + esc(totals.checked) + '</strong> de <strong>' + esc(total || 0) + '</strong> variantes · Categorías añadidas: <strong>' + esc(totals.categories_added) + '</strong></div>');
		}

		function renderResult() {
			const noticeClass = totals.errors > 0 || totals.skipped > 0 ? 'notice-warning' : 'notice-success';
			let html = '<div class="notice ' + noticeClass + ' inline"><p><strong>Reparación completada.</strong> Variantes comprobadas: ' + esc(totals.checked) + ' · actualizadas: ' + esc(totals.updated) + ' · ya correctas: ' + esc(totals.unchanged) + ' · omitidas: ' + esc(totals.skipped) + ' · errores: ' + esc(totals.errors) + ' · categorías añadidas: ' + esc(totals.categories_added) + '.</p></div>';
			if (details.length) {
				html += '<table class="dolisync-products-table"><thead><tr><th>Variación WooCommerce</th><th>Producto hijo Dolibarr</th><th>Estado</th><th>Detalle</th></tr></thead><tbody>' + details.slice(0, 100).map(function (item) {
					return '<tr><td>#' + esc(item.wc_variation_id || 0) + '</td><td>#' + esc(item.dolibarr_variation_id || 0) + '</td><td>' + esc(item.status === 'error' ? 'Error' : 'Omitida') + '</td><td>' + esc(item.message || '') + '</td></tr>';
				}).join('') + '</tbody></table>';
				if (details.length > 100) { html += '<p><small>Se muestran las primeras 100 incidencias de ' + esc(details.length) + '.</small></p>'; }
			}
			$result.html(html);
		}

		function runBatch(offset) {
			$.post(DoliSync.ajaxUrl, {action: 'dolisync_migrate_variation_categories', nonce: DoliSync.nonce, offset: offset, per_page: 25, run_id: runId}).done(function (response) {
				if (!response.success) {
					$result.html('<div class="notice notice-error inline"><p>' + esc(response.data && response.data.message ? response.data.message : 'No se pudo reparar las categorías de las variantes.') + '</p></div>');
					$button.prop('disabled', false);
					return;
				}
				runId = response.data.run_id || runId;
				const stats = response.data.stats || {};
				Object.keys(totals).forEach(function (key) { totals[key] += Number(stats[key] || 0); });
				(stats.details || []).forEach(function (item) { details.push(item); });
				const pagination = response.data.pagination || {};
				if (pagination.has_more) {
					renderProgress(Number(pagination.total || 0));
					runBatch(Number(pagination.next_offset || 0));
					return;
				}
				renderResult();
				$button.prop('disabled', false);
			}).fail(function (xhr) {
				$result.html('<div class="notice notice-error inline"><p>' + esc(dolisyncAjaxError(xhr, 'No se pudo reparar las categorías de las variantes.')) + '</p></div>');
				$button.prop('disabled', false);
			});
		}

		renderProgress(0);
		runBatch(0);
	});
	loadCatalog();
})(jQuery);
