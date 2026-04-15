(function () {
	let currentFilter = 'all';
	let currentView = 'list';
	let updateHymnSearchData = null;
	let deleteHymnSearchData = null;
	const structuralFields = {
		hymnal: true,
		hymn_number: true,
		hymn_section: true,
		is_active: true,
	};

	function getElement(id) {
		return document.getElementById(id);
	}

	function showHymnListArea() {
		getElement('hymn-list-search').style.display = '';
	}

	function hideHymnListArea() {
		getElement('hymn-list-search').style.display = 'none';
	}

	function clearHymnList() {
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

	function buildHymnDisplayLabel(hymnId) {
		const summaryRow = document.querySelector('.hymn-summary-row[data-hymn-id="' + hymnId + '"]');
		if (!summaryRow) {
			return '';
		}

		const detailRow = getDetailRow(hymnId);
		const hymnalInput = detailRow ? detailRow.querySelector('[data-field="hymnal"]') : null;
		const numberInput = detailRow ? detailRow.querySelector('[data-field="hymn_number"]') : null;
		const insertInput = detailRow ? detailRow.querySelector('[data-field="insert_use"]') : null;
		const hymnal = hymnalInput ? hymnalInput.value.trim() : '';
		const hymnNumber = numberInput ? numberInput.value.trim() : '';
		let label = (hymnal + ' ' + hymnNumber).trim();

		if (insertInput && insertInput.checked) {
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

	function setSavingState(input, isSaving) {
		if (!input) {
			return;
		}

		input.disabled = isSaving;
		input.classList.toggle('is-saving', isSaving);
	}

	function toggleHymnDetail(toggleButton) {
		const hymnId = toggleButton.getAttribute('data-hymn-id');
		const detailRow = getDetailRow(hymnId);
		const summaryRow = closestByClass(toggleButton, 'hymn-summary-row');
		const isExpanded = toggleButton.getAttribute('aria-expanded') === 'true';

		if (!detailRow) {
			return;
		}

		toggleButton.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
		detailRow.style.display = isExpanded ? 'none' : '';

		if (summaryRow) {
			summaryRow.classList.toggle('is-expanded', !isExpanded);
		}
	}

	async function loadHymns(filter, view = 'list') {
		try {
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
			const rows = table.querySelectorAll('tr');
			let visibleRows = 0;

			rows.forEach(function (row, index) {
				if (index === 0) {
					row.style.display = '';
					return;
				}

				if (row.classList.contains('hymn-detail-row')) {
					return;
				}

				const hymnId = row.getAttribute('data-hymn-id');
				const detailRow = hymnId ? getDetailRow(hymnId) : null;
				const toggleButton = row.querySelector('.hymn-expand-toggle');
				const isExpanded = toggleButton && toggleButton.getAttribute('aria-expanded') === 'true';
				const rowText = row.textContent.toLowerCase() + ' ' + (detailRow ? detailRow.textContent.toLowerCase() : '');
				const matches = query === '' || rowText.indexOf(query) !== -1;

				row.style.display = matches ? '' : 'none';
				if (detailRow) {
					detailRow.style.display = matches && isExpanded ? '' : 'none';
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

	async function updateHymnField(field, value, hymnId) {
		return fetchJson('ajax/update_hymn_field.php', {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({ id: hymnId, field: field, value: value }),
		});
	}

	async function saveInlineHymnEdit(input) {
		if (!hasClass(input, 'hymn-edit-input')) {
			return;
		}

		const hymnId = input.getAttribute('data-hymn-id');
		const fieldName = input.getAttribute('data-field');
		const originalValue = input.getAttribute('data-original-value');
		const newValue = input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value.trim();
		const rollbackValue = input.type === 'checkbox' ? originalValue === '1' : originalValue;

		if (newValue === originalValue) {
			return;
		}

		setSavingState(input, true);

		try {
			await updateHymnField(fieldName, newValue, hymnId);
			input.setAttribute('data-original-value', newValue);
			syncSummaryDisplay(hymnId);

			if (structuralFields[fieldName]) {
				await loadHymns(currentFilter, currentView);
				return;
			}

			applyHymnListFilter();
		} catch (error) {
			console.error('Error updating hymn:', error);
			if (input.type === 'checkbox') {
				input.checked = rollbackValue;
			} else {
				input.value = rollbackValue;
			}
			alert(error.message || 'Failed to update hymn.');
		} finally {
			setSavingState(input, false);
		}
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

		if (hasClass(target, 'hymn-edit-input')) {
			saveInlineHymnEdit(target);
		}

		handleHymnSearchSelection(event);
	}

	function handleDocumentInput(event) {
		const target = event.target;

		if (target.id === 'hymn-list-filter') {
			applyHymnListFilter();
		}

		handleHymnSearchSelection(event);
	}

	function handleFocusIn(event) {
		const target = event.target;
		if (hasClass(target, 'hymn-edit-input')) {
			target.setAttribute('data-original-value', target.value.trim());
		}
	}

	function handleDocumentClick(event) {
		const target = event.target;
		const summaryRow = closestByClass(target, 'hymn-summary-row');
		const toggleButton = closestByClass(target, 'hymn-expand-toggle');

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
		document.addEventListener('focusin', handleFocusIn);
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
