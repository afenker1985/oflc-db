(function () {
	let currentFilter = 'all';
	let currentView = 'list';
	let updateHymnSearchData = null;
	let deleteHymnSearchData = null;

	function getElement(id) {
		return document.getElementById(id);
	}

	function showHymnListArea() {
		getElement('hymn-list-search').style.display = '';
	}

	function hideHymnListArea() {
		getElement('hymn-list-search').style.display = 'none';
	}

	function resetHymnListFilter() {
		const filterInput = getElement('hymn-list-filter');
		if (filterInput) {
			filterInput.value = '';
		}
	}

	function clearHymnList() {
		resetHymnListFilter();
		hideHymnListArea();
		getElement('hymn-content').innerHTML = '<p>Select a button above to load hymns or open a hymn form.</p>';
	}

	function hasClass(element, className) {
		return !!(element && element.classList && element.classList.contains(className));
	}

	async function fetchText(url) {
		const response = await fetch(url);
		if (!response.ok) {
			throw new Error('Request failed.');
		}
		return response.text();
	}

	async function fetchJson(url, options) {
		const response = await fetch(url, options);
		const result = await response.json();
		if (!response.ok || !result.success) {
			throw new Error(result.message || 'Request failed.');
		}
		return result;
	}

	function closestByClass(element, className) {
		while (element) {
			if (element.classList && element.classList.contains(className)) {
				return element;
			}
			element = element.parentNode;
		}
		return null;
	}

	function getDetailRow(hymnId) {
		return document.querySelector('[data-hymn-detail-id="' + hymnId + '"]');
	}

	function getHymnEntry(hymnId) {
		return document.querySelector('[data-hymn-entry-id="' + hymnId + '"]');
	}

	function getHymnEditInputs(hymnId) {
		return document.querySelectorAll('.hymn-edit-input[data-hymn-id="' + hymnId + '"]');
	}

	function getHymnEditInput(hymnId, fieldName) {
		return document.querySelector('.hymn-edit-input[data-hymn-id="' + hymnId + '"][data-field="' + fieldName + '"]');
	}

	function buildHymnDisplayLabel(hymnId) {
		const summaryRow = document.querySelector('.hymn-summary-row[data-hymn-id="' + hymnId + '"]');
		if (!summaryRow) {
			return '';
		}

		const detailRow = getDetailRow(hymnId);
		const hymnalInput = detailRow ? detailRow.querySelector('[data-field="hymnal"]') : null;
		const numberInput = detailRow ? detailRow.querySelector('[data-field="hymn_number"]') : null;
		const hymnal = hymnalInput ? hymnalInput.value.trim() : '';
		const hymnNumber = numberInput ? numberInput.value.trim() : '';
		let label = (hymnal + ' ' + hymnNumber).trim();
		const insertValue = summaryRow.getAttribute('data-insert-use') === '1';

		if (insertValue) {
			label += '*';
		}

		return label;
	}

	function syncSummaryDisplay(hymnId) {
		const displayTarget = document.querySelector('[data-hymn-display-id="' + hymnId + '"]');
		const titleTarget = document.querySelector('[data-hymn-title-id="' + hymnId + '"]');
		const detailRow = getDetailRow(hymnId);
		const titleInput = detailRow ? detailRow.querySelector('[data-field="hymn_title"]') : null;

		if (displayTarget) {
			displayTarget.textContent = buildHymnDisplayLabel(hymnId);
		}

		if (titleTarget && titleInput) {
			titleTarget.textContent = titleInput.value.trim();
		}
	}

	function setHymnCardSavingState(hymnId, isSaving) {
		const entry = getHymnEntry(hymnId);
		const actionButtons = entry ? entry.querySelectorAll('.js-hymn-save-button, .js-hymn-cancel-button') : [];

		actionButtons.forEach(function (button) {
			button.disabled = isSaving;
		});
	}

	function collapseHymnDetail(hymnId) {
		const detailRow = getDetailRow(hymnId);
		const summaryRow = document.querySelector('.hymn-summary-row[data-hymn-id="' + hymnId + '"]');
		const toggleButton = document.querySelector('.hymn-expand-toggle[data-hymn-id="' + hymnId + '"]');

		if (!detailRow || !toggleButton || toggleButton.getAttribute('aria-expanded') !== 'true') {
			return;
		}

		toggleButton.setAttribute('aria-expanded', 'false');
		detailRow.classList.remove('is-expanded');
		detailRow.classList.add('is-collapsed');
		detailRow.style.display = 'none';

		if (summaryRow) {
			summaryRow.classList.remove('is-expanded');
		}
	}

	function collapseOtherHymnDetails(activeHymnId) {
		document.querySelectorAll('.hymn-expand-toggle[aria-expanded="true"]').forEach(function (toggleButton) {
			const hymnId = toggleButton.getAttribute('data-hymn-id');

			if (hymnId !== activeHymnId) {
				collapseHymnDetail(hymnId);
			}
		});
	}

	function closeHymnDetailById(hymnId) {
		collapseHymnDetail(hymnId);
	}

	function collectHymnPayload(hymnId) {
		const hymnalInput = getHymnEditInput(hymnId, 'hymnal');
		const numberInput = getHymnEditInput(hymnId, 'hymn_number');
		const titleInput = getHymnEditInput(hymnId, 'hymn_title');
		const tuneInput = getHymnEditInput(hymnId, 'hymn_tune');
		const sectionInput = getHymnEditInput(hymnId, 'hymn_section');
		const kernliederInput = getHymnEditInput(hymnId, 'kernlieder_target');
		const insertInput = getHymnEditInput(hymnId, 'insert_use');
		const activeInput = getHymnEditInput(hymnId, 'is_active');
		const payload = new URLSearchParams();

		payload.set('id', hymnId);
		payload.set('hymnal', hymnalInput ? hymnalInput.value.trim() : '');
		payload.set('hymn_number', numberInput ? numberInput.value.trim() : '');
		payload.set('hymn_title', titleInput ? titleInput.value.trim() : '');
		payload.set('hymn_tune', tuneInput ? tuneInput.value.trim() : '');
		payload.set('hymn_section', sectionInput ? sectionInput.value.trim() : '');
		payload.set('kernlieder_target', kernliederInput ? kernliederInput.value.trim() : '0');

		if (insertInput && insertInput.checked) {
			payload.set('insert_use', '1');
		}

		if (activeInput && activeInput.checked) {
			payload.set('is_active', '1');
		}

		return payload;
	}

	function restoreHymnCardOriginalValues(hymnId) {
		getHymnEditInputs(hymnId).forEach(function (input) {
			const originalValue = input.getAttribute('data-original-value') || '';

			if (input.type === 'checkbox') {
				input.checked = originalValue === '1';
			} else {
				input.value = originalValue;
			}
		});

		syncSummaryDisplay(hymnId);
	}

	function toggleHymnDetail(toggleButton) {
		const hymnId = toggleButton.getAttribute('data-hymn-id');
		const detailRow = getDetailRow(hymnId);
		const summaryRow = closestByClass(toggleButton, 'hymn-summary-row');
		const isExpanded = toggleButton.getAttribute('aria-expanded') === 'true';

		if (!detailRow) {
			return;
		}

		if (!isExpanded) {
			collapseOtherHymnDetails(hymnId);
		}

		toggleButton.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
		detailRow.classList.toggle('is-expanded', !isExpanded);
		detailRow.classList.toggle('is-collapsed', isExpanded);
		detailRow.style.display = isExpanded ? 'none' : 'table-row';

		if (summaryRow) {
			summaryRow.classList.toggle('is-expanded', !isExpanded);
		}
	}

	async function loadHymns(filter, view = 'list') {
		try {
			if (currentFilter !== filter || currentView !== view) {
				resetHymnListFilter();
			}
			currentFilter = filter;
			currentView = view;
			const html = await fetchText('ajax/get_hymns.php?filter=' + encodeURIComponent(filter) + '&view=' + encodeURIComponent(view));
			getElement('hymn-content').innerHTML = html;
			applyHymnListFilter();
			showHymnListArea();
		} catch (error) {
			console.error('Error loading hymns:', error);
			getElement('hymn-content').innerHTML = '<p style="color: red;">Failed to load form.</p>';
		}
	}

	function applyHymnListFilter() {
		const filterInput = getElement('hymn-list-filter');
		const hymnContent = getElement('hymn-content');
		if (!filterInput || !hymnContent) {
			return;
		}

		const query = filterInput.value.trim().toLowerCase();
		const tables = hymnContent.querySelectorAll('.hymn-table');

		tables.forEach(function (table) {
			const rows = table.querySelectorAll('.hymn-summary-row');
			let visibleRows = 0;

			rows.forEach(function (row) {
				const hymnId = row.getAttribute('data-hymn-id');
				const detailRow = hymnId ? getDetailRow(hymnId) : null;
				const entry = closestByClass(row, 'hymn-entry');
				const spacer = document.querySelector('[data-hymn-spacer-id="' + hymnId + '"]');
				const toggleButton = row.querySelector('.hymn-expand-toggle');
				const isExpanded = toggleButton && toggleButton.getAttribute('aria-expanded') === 'true';
				const rowText = row.textContent.toLowerCase() + ' ' + (detailRow ? detailRow.textContent.toLowerCase() : '');
				const matches = query === '' || rowText.indexOf(query) !== -1;

				if (entry) {
					entry.style.display = matches ? 'table-row-group' : 'none';
				}
				if (spacer) {
					spacer.style.display = matches ? 'table-row-group' : 'none';
				}
				row.style.display = '';
				if (detailRow) {
					detailRow.classList.toggle('is-expanded', matches && isExpanded);
					detailRow.classList.toggle('is-collapsed', !(matches && isExpanded));
					detailRow.style.display = matches && isExpanded ? 'table-row' : 'none';
				}
				if (matches) {
					visibleRows += 1;
				}
			});

			const section = closestByClass(table, 'hymn-section');
			if (section) {
				section.style.display = visibleRows > 0 ? '' : 'none';
			}
		});
	}

	async function showAddForm() {
		try {
			resetHymnListFilter();
			hideHymnListArea();
			const html = await fetchText('ajax/get_add_hymn_form.php');
			getElement('hymn-content').innerHTML = html;
		} catch (error) {
			console.error('Error loading add hymn form:', error);
			getElement('hymn-content').innerHTML = '<p style="color: red;">Failed to load add hymn form.</p>';
		}
	}

	function initializeUpdateForm() {
		const dataElement = getElement('update-hymn-search-data');
		updateHymnSearchData = dataElement ? JSON.parse(dataElement.textContent) : null;
	}

	function initializeDeleteForm() {
		const dataElement = getElement('delete-hymn-search-data');
		deleteHymnSearchData = dataElement ? JSON.parse(dataElement.textContent) : null;
	}

	async function showUpdateForm() {
		try {
			resetHymnListFilter();
			hideHymnListArea();
			const html = await fetchText('ajax/get_update_hymn_form.php');
			getElement('hymn-content').innerHTML = html;
			initializeUpdateForm();
		} catch (error) {
			console.error('Error loading update hymn form:', error);
			getElement('hymn-content').innerHTML = '<p style="color: red;">Failed to load update hymn form.</p>';
		}
	}

	async function showDeleteForm() {
		try {
			resetHymnListFilter();
			hideHymnListArea();
			const html = await fetchText('ajax/get_delete_hymn_form.php');
			getElement('hymn-content').innerHTML = html;
			initializeDeleteForm();
		} catch (error) {
			console.error('Error loading delete hymn form:', error);
			getElement('hymn-content').innerHTML = '<p style="color: red;">Failed to load delete hymn form.</p>';
		}
	}

	function fillHymnForm(formId, hymn) {
		const form = getElement(formId);
		if (!form || !hymn) {
			return;
		}

		form.elements.id.value = hymn.id || '';
		form.elements.hymnal.value = hymn.hymnal || '';
		form.elements.hymn_number.value = hymn.hymn_number || '';
		form.elements.hymn_title.value = hymn.hymn_title || '';
		form.elements.hymn_tune.value = hymn.hymn_tune || '';
		form.elements.hymn_section.value = hymn.hymn_section || '';
		form.elements.kernlieder_target.value = hymn.kernlieder_target || 0;
		form.elements.insert_use.checked = Number(hymn.insert_use) === 1;
		form.elements.is_active.checked = Number(hymn.is_active) === 1;
	}

	async function deleteSelectedHymn() {
		const form = getElement('delete-hymn-form');
		if (!form || !form.elements.id.value) {
			alert('Select a hymn to delete first.');
			return;
		}

		const confirmation = prompt('Are you sure? This cannot be done. Type "DELETE" to delete the hymn.');
		if (confirmation !== 'DELETE') {
			return;
		}

		try {
			await fetchJson('ajax/delete_hymn.php', {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams({ id: form.elements.id.value }),
			});
			await loadHymns(currentFilter, currentView);
		} catch (error) {
			console.error('Error deleting hymn:', error);
			alert(error.message || 'Failed to delete hymn.');
		}
	}

	async function submitDeleteForm() {
		await deleteSelectedHymn();
	}

	async function submitUpdateForm() {
		const form = getElement('update-hymn-form');
		if (!form || !form.elements.id.value) {
			alert('Select a hymn to update first.');
			return;
		}

		const submitButton = form.querySelector('button');
		if (submitButton) {
			submitButton.disabled = true;
		}

		try {
			await fetchJson('ajax/update_hymn.php', {
				method: 'POST',
				body: new FormData(form),
			});
			alert('Hymn updated.');
			await loadHymns(currentFilter, currentView);
		} catch (error) {
			console.error('Error updating hymn:', error);
			alert(error.message || 'Failed to update hymn.');
			if (submitButton) {
				submitButton.disabled = false;
			}
		}
	}

	async function saveHymnCard(hymnId) {
		setHymnCardSavingState(hymnId, true);

		try {
			await fetchJson('ajax/update_hymn.php', {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: collectHymnPayload(hymnId),
			});
			await loadHymns(currentFilter, currentView);
		} catch (error) {
			console.error('Error updating hymn:', error);
			alert(error.message || 'Failed to update hymn.');
			setHymnCardSavingState(hymnId, false);
		}
	}

	function cancelHymnCard(hymnId) {
		restoreHymnCardOriginalValues(hymnId);
		closeHymnDetailById(hymnId);
	}

	function handleHymnSearchSelection(event) {
		const target = event.target;
		if (!target.classList.contains('hymn-search-input')) {
			return;
		}

		const formId = target.getAttribute('data-form-id') || 'update-hymn-form';
		const searchData = formId === 'delete-hymn-form' ? deleteHymnSearchData : updateHymnSearchData;
		if (!searchData || !searchData.options) {
			return;
		}

		const hymnId = searchData.options[target.value];
		if (!hymnId) {
			return;
		}

		fillHymnForm(formId, searchData.hymns[hymnId]);
	}

	function handleDocumentChange(event) {
		const target = event.target;

		handleHymnSearchSelection(event);

		if (hasClass(target, 'hymn-edit-input')) {
			syncSummaryDisplay(target.getAttribute('data-hymn-id'));
		}
	}

	function handleDocumentInput(event) {
		const target = event.target;

		if (target.id === 'hymn-list-filter') {
			applyHymnListFilter();
		}

		if (hasClass(target, 'hymn-edit-input') && target.type !== 'checkbox') {
			syncSummaryDisplay(target.getAttribute('data-hymn-id'));
		}

		handleHymnSearchSelection(event);
	}

	function handleDocumentClick(event) {
		const target = event.target;
		const saveButton = closestByClass(target, 'js-hymn-save-button');
		const cancelButton = closestByClass(target, 'js-hymn-cancel-button');
		const summaryRow = closestByClass(target, 'hymn-summary-row');
		const toggleButton = closestByClass(target, 'hymn-expand-toggle');

		if (saveButton) {
			saveHymnCard(saveButton.getAttribute('data-hymn-id'));
			return;
		}

		if (cancelButton) {
			cancelHymnCard(cancelButton.getAttribute('data-hymn-id'));
			return;
		}

		if (summaryRow && !closestByClass(target, 'active-column') && !hasClass(target, 'hymn-edit-input')) {
			const rowToggle = summaryRow.querySelector('.hymn-expand-toggle');
			if (rowToggle) {
				toggleHymnDetail(rowToggle);
			}
			return;
		}

		if (toggleButton) {
			toggleHymnDetail(toggleButton);
		}
	}

	async function handleAddFormSubmit(event) {
		if (!event.target.matches('#add-hymn-form')) {
			return;
		}

		event.preventDefault();
		const form = event.target;
		const submitButton = form.querySelector('button[type="submit"]');
		if (submitButton) {
			submitButton.disabled = true;
		}

		try {
			await fetchJson('ajax/add_hymn.php', {
				method: 'POST',
				body: new FormData(form),
			});
			await loadHymns(currentFilter, currentView);
		} catch (error) {
			console.error('Error saving hymn:', error);
			alert(error.message || 'Failed to add hymn.');
			if (submitButton) {
				submitButton.disabled = false;
			}
		}
	}

	function init() {
		document.addEventListener('click', handleDocumentClick);
		document.addEventListener('input', handleDocumentInput);
		document.addEventListener('change', handleDocumentChange);
		document.addEventListener('submit', handleAddFormSubmit);
		hideHymnListArea();
	}

	window.loadHymns = loadHymns;
	window.showAddForm = showAddForm;
	window.showDeleteForm = showDeleteForm;
	window.clearHymnList = clearHymnList;
	window.submitDeleteForm = submitDeleteForm;
	window.deleteSelectedHymn = deleteSelectedHymn;

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
