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

	function clearHymnList() {
		hideHymnListArea();
		getElement('hymn-content').innerHTML = '<p>Select a button above to load hymns or open a hymn form.</p>';
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

				const matches = query === '' || row.textContent.toLowerCase().indexOf(query) !== -1;
				row.style.display = matches ? '' : 'none';
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

	async function updateHymnCheckbox(checkbox, fieldName) {
		const hymnId = checkbox.getAttribute('data-hymn-id');
		const fieldValue = checkbox.checked ? 1 : 0;
		const originalState = !checkbox.checked;
		checkbox.disabled = true;

		try {
			await updateHymnField(fieldName, fieldValue, hymnId);
			await loadHymns(currentFilter, currentView);
		} catch (error) {
			console.error('Error updating hymn:', error);
			checkbox.checked = originalState;
			alert('Failed to update hymn.');
			checkbox.disabled = false;
		}
	}

	async function updateKernlieder(input) {
		const hymnId = input.getAttribute('data-hymn-id');
		const storedOriginal = input.getAttribute('data-original-value');
		const originalValue = storedOriginal !== null ? storedOriginal : input.defaultValue;
		const newValue = input.value.trim();

		if (newValue === originalValue) {
			return;
		}

		input.disabled = true;
		try {
			await updateHymnField('kernlieder_target', newValue, hymnId);
			input.setAttribute('data-original-value', newValue);
		} catch (error) {
			console.error('Error updating hymn:', error);
			input.value = originalValue;
			alert('Failed to update Kernlieder.');
		} finally {
			input.disabled = false;
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

		if (target.classList.contains('active-toggle')) {
			updateHymnCheckbox(target, 'is_active');
		}

		if (target.classList.contains('insert-toggle')) {
			updateHymnCheckbox(target, 'insert_use');
		}

		if (target.classList.contains('kernlieder-input')) {
			updateKernlieder(target);
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
		if (target.classList.contains('kernlieder-input')) {
			target.setAttribute('data-original-value', target.value.trim());
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
		document.addEventListener('input', handleDocumentInput);
		document.addEventListener('change', handleDocumentChange);
		document.addEventListener('focusin', handleFocusIn);
		document.addEventListener('submit', handleAddFormSubmit);
		hideHymnListArea();
	}

	window.loadHymns = loadHymns;
	window.showAddForm = showAddForm;
	window.showUpdateForm = showUpdateForm;
	window.showDeleteForm = showDeleteForm;
	window.clearHymnList = clearHymnList;
	window.submitUpdateForm = submitUpdateForm;
	window.submitDeleteForm = submitDeleteForm;
	window.deleteSelectedHymn = deleteSelectedHymn;

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
