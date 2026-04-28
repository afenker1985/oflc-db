(function () {
	var currentFilter = 'all';
	var currentView = 'list';
	var updateHymnSearchData = null;
	var deleteHymnSearchData = null;

	function getElement(id) {
		return document.getElementById(id);
	}

	function trimString(value) {
		return String(value).replace(/^\s+|\s+$/g, '');
	}

	function showHymnListArea() {
		getElement('hymn-list-search').style.display = '';
	}

	function hideHymnListArea() {
		getElement('hymn-list-search').style.display = 'none';
	}

	function resetHymnListFilter() {
		var filterInput = getElement('hymn-list-filter');
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
		return !!(element && (' ' + element.className + ' ').indexOf(' ' + className + ' ') !== -1);
	}

	function encodeParams(params) {
		var parts = [];
		var key;

		for (key in params) {
			if (params.hasOwnProperty(key)) {
				parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(params[key]));
			}
		}

		return parts.join('&');
	}

	function xhrRequest(method, url, body, contentType, callback) {
		var request = new XMLHttpRequest();
		request.open(method, url, true);

		if (contentType) {
			request.setRequestHeader('Content-Type', contentType);
		}

		request.onreadystatechange = function () {
			if (request.readyState !== 4) {
				return;
			}

			if (request.status >= 200 && request.status < 300) {
				callback(null, request.responseText);
				return;
			}

			callback(new Error('Request failed.'));
		};

		request.send(body || null);
	}

	function closestByClass(element, className) {
		while (element) {
			if (element.className && (' ' + element.className + ' ').indexOf(' ' + className + ' ') !== -1) {
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
		var summaryRow = document.querySelector('.hymn-summary-row[data-hymn-id="' + hymnId + '"]');
		var detailRow = getDetailRow(hymnId);
		var hymnalInput;
		var numberInput;
		var insertValue;
		var label;

		if (!summaryRow || !detailRow) {
			return '';
		}

		hymnalInput = detailRow.querySelector('[data-field="hymnal"]');
		numberInput = detailRow.querySelector('[data-field="hymn_number"]');
		insertValue = summaryRow.getAttribute('data-insert-use') === '1';
		label = trimString((hymnalInput ? hymnalInput.value : '') + ' ' + (numberInput ? numberInput.value : ''));

		if (insertValue) {
			label += '*';
		}

		return label;
	}

	function syncSummaryDisplay(hymnId) {
		var displayTarget = document.querySelector('[data-hymn-display-id="' + hymnId + '"]');
		var titleTarget = document.querySelector('[data-hymn-title-id="' + hymnId + '"]');
		var detailRow = getDetailRow(hymnId);
		var titleInput = detailRow ? detailRow.querySelector('[data-field="hymn_title"]') : null;

		if (displayTarget) {
			displayTarget.innerHTML = '';
			displayTarget.appendChild(document.createTextNode(buildHymnDisplayLabel(hymnId)));
		}

		if (titleTarget && titleInput) {
			titleTarget.innerHTML = '';
			titleTarget.appendChild(document.createTextNode(trimString(titleInput.value)));
		}
	}

	function setHymnCardSavingState(hymnId, isSaving) {
		var entry = getHymnEntry(hymnId);
		var buttons = entry ? entry.querySelectorAll('.js-hymn-save-button, .js-hymn-cancel-button') : [];
		var i;

		for (i = 0; i < buttons.length; i += 1) {
			buttons[i].disabled = isSaving;
		}
	}

	function collapseHymnDetail(hymnId) {
		var detailRow = getDetailRow(hymnId);
		var summaryRow = document.querySelector('.hymn-summary-row[data-hymn-id="' + hymnId + '"]');
		var toggleButton = document.querySelector('.hymn-expand-toggle[data-hymn-id="' + hymnId + '"]');

		if (!detailRow || !toggleButton || toggleButton.getAttribute('aria-expanded') !== 'true') {
			return;
		}

		toggleButton.setAttribute('aria-expanded', 'false');
		detailRow.style.display = 'none';
		detailRow.className = detailRow.className.replace(/\bis-expanded\b/g, '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
		if (!hasClass(detailRow, 'is-collapsed')) {
			detailRow.className += ' is-collapsed';
		}

		if (summaryRow) {
			summaryRow.className = summaryRow.className.replace(/\bis-expanded\b/g, '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
		}
	}

	function collapseOtherHymnDetails(activeHymnId) {
		var toggleButtons = document.querySelectorAll('.hymn-expand-toggle[aria-expanded="true"]');
		var i;

		for (i = 0; i < toggleButtons.length; i += 1) {
			var hymnId = toggleButtons[i].getAttribute('data-hymn-id');

			if (hymnId !== activeHymnId) {
				collapseHymnDetail(hymnId);
			}
		}
	}

	function closeHymnDetailById(hymnId) {
		collapseHymnDetail(hymnId);
	}

	function collectHymnPayload(hymnId) {
		var hymnalInput = getHymnEditInput(hymnId, 'hymnal');
		var numberInput = getHymnEditInput(hymnId, 'hymn_number');
		var titleInput = getHymnEditInput(hymnId, 'hymn_title');
		var tuneInput = getHymnEditInput(hymnId, 'hymn_tune');
		var sectionInput = getHymnEditInput(hymnId, 'hymn_section');
		var kernliederInput = getHymnEditInput(hymnId, 'kernlieder_target');
		var insertInput = getHymnEditInput(hymnId, 'insert_use');
		var activeInput = getHymnEditInput(hymnId, 'is_active');
		var params = {
			id: hymnId,
			hymnal: hymnalInput ? trimString(hymnalInput.value) : '',
			hymn_number: numberInput ? trimString(numberInput.value) : '',
			hymn_title: titleInput ? trimString(titleInput.value) : '',
			hymn_tune: tuneInput ? trimString(tuneInput.value) : '',
			hymn_section: sectionInput ? trimString(sectionInput.value) : '',
			kernlieder_target: kernliederInput ? trimString(kernliederInput.value) : '0'
		};

		if (insertInput && insertInput.checked) {
			params.insert_use = '1';
		}

		if (activeInput && activeInput.checked) {
			params.is_active = '1';
		}

		return encodeParams(params);
	}

	function restoreHymnCardOriginalValues(hymnId) {
		var inputs = getHymnEditInputs(hymnId);
		var i;

		for (i = 0; i < inputs.length; i += 1) {
			var input = inputs[i];
			var originalValue = input.getAttribute('data-original-value') || '';

			if (input.type === 'checkbox') {
				input.checked = originalValue === '1';
			} else {
				input.value = originalValue;
			}
		}

		syncSummaryDisplay(hymnId);
	}

	function toggleHymnDetail(toggleButton) {
		var hymnId = toggleButton.getAttribute('data-hymn-id');
		var detailRow = getDetailRow(hymnId);
		var summaryRow = closestByClass(toggleButton, 'hymn-summary-row');
		var isExpanded = toggleButton.getAttribute('aria-expanded') === 'true';

		if (!detailRow) {
			return;
		}

		if (!isExpanded) {
			collapseOtherHymnDetails(hymnId);
		}

		toggleButton.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
		detailRow.style.display = isExpanded ? 'none' : 'table-row';
		if (isExpanded) {
			detailRow.className = detailRow.className.replace(/\bis-expanded\b/g, '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
			if (!hasClass(detailRow, 'is-collapsed')) {
				detailRow.className += ' is-collapsed';
			}
		} else {
			detailRow.className = detailRow.className.replace(/\bis-collapsed\b/g, '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
			if (!hasClass(detailRow, 'is-expanded')) {
				detailRow.className += ' is-expanded';
			}
		}

		if (summaryRow) {
			if (isExpanded) {
				summaryRow.className = summaryRow.className.replace(/\bis-expanded\b/g, '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
			} else if (!hasClass(summaryRow, 'is-expanded')) {
				summaryRow.className += ' is-expanded';
			}
		}
	}

	function loadHymns(filter, view) {
		if (typeof view === 'undefined') {
			view = 'list';
		}

		if (currentFilter !== filter || currentView !== view) {
			resetHymnListFilter();
		}
		currentFilter = filter;
		currentView = view;

		xhrRequest('GET', 'ajax/get_hymns.php?filter=' + encodeURIComponent(filter) + '&view=' + encodeURIComponent(view), null, null, function (error, responseText) {
			if (error) {
				if (window.console && window.console.error) {
					window.console.error('Error loading hymns:', error);
				}
				getElement('hymn-content').innerHTML = '<p style="color: red;">Failed to load form.</p>';
				return;
			}

			getElement('hymn-content').innerHTML = responseText;
			applyHymnListFilter();
			showHymnListArea();
		});
	}

	function applyHymnListFilter() {
		var filterInput = getElement('hymn-list-filter');
		var hymnContent = getElement('hymn-content');
		var query;
		var tables;
		var i;

		if (!filterInput || !hymnContent) {
			return;
		}

		query = trimString(filterInput.value).toLowerCase();
		tables = hymnContent.querySelectorAll('.hymn-table');

		for (i = 0; i < tables.length; i += 1) {
			var table = tables[i];
			var rows = table.querySelectorAll('.hymn-summary-row');
			var visibleRows = 0;
			var j;

			for (j = 0; j < rows.length; j += 1) {
				var row = rows[j];
				var matches;
				var hymnId;
				var detailRow;
				var entry;
				var spacer;
				var toggleButton;
				var isExpanded;
				var rowText;

				hymnId = row.getAttribute('data-hymn-id');
				detailRow = hymnId ? getDetailRow(hymnId) : null;
				entry = closestByClass(row, 'hymn-entry');
				spacer = document.querySelector('[data-hymn-spacer-id="' + hymnId + '"]');
				toggleButton = row.querySelector('.hymn-expand-toggle');
				isExpanded = toggleButton && toggleButton.getAttribute('aria-expanded') === 'true';
				rowText = row.textContent.toLowerCase() + ' ' + (detailRow ? detailRow.textContent.toLowerCase() : '');
				matches = query === '' || rowText.indexOf(query) !== -1;
				if (entry) {
					entry.style.display = matches ? 'table-row-group' : 'none';
				}
				if (spacer) {
					spacer.style.display = matches ? 'table-row-group' : 'none';
				}
				row.style.display = '';
				if (detailRow) {
					detailRow.style.display = matches && isExpanded ? 'table-row' : 'none';
					if (matches && isExpanded) {
						detailRow.className = detailRow.className.replace(/\bis-collapsed\b/g, '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
						if (!hasClass(detailRow, 'is-expanded')) {
							detailRow.className += ' is-expanded';
						}
					} else {
						detailRow.className = detailRow.className.replace(/\bis-expanded\b/g, '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
						if (!hasClass(detailRow, 'is-collapsed')) {
							detailRow.className += ' is-collapsed';
						}
					}
				}
				if (matches) {
					visibleRows += 1;
				}
			}

			var section = closestByClass(table, 'hymn-section');
			if (section) {
				section.style.display = visibleRows > 0 ? '' : 'none';
			}
		}
	}

	function showAddForm() {
		resetHymnListFilter();
		hideHymnListArea();
		xhrRequest('GET', 'ajax/get_add_hymn_form.php', null, null, function (error, responseText) {
			if (error) {
				if (window.console && window.console.error) {
					window.console.error('Error loading add hymn form:', error);
				}
				getElement('hymn-content').innerHTML = '<p style="color: red;">Failed to load add hymn form.</p>';
				return;
			}

			getElement('hymn-content').innerHTML = responseText;
		});
	}

	function initializeUpdateForm() {
		var dataElement = getElement('update-hymn-search-data');
		updateHymnSearchData = dataElement ? JSON.parse(dataElement.textContent) : null;
	}

	function initializeDeleteForm() {
		var dataElement = getElement('delete-hymn-search-data');
		deleteHymnSearchData = dataElement ? JSON.parse(dataElement.textContent) : null;
	}

	function showUpdateForm() {
		resetHymnListFilter();
		hideHymnListArea();
		xhrRequest('GET', 'ajax/get_update_hymn_form.php', null, null, function (error, responseText) {
			if (error) {
				if (window.console && window.console.error) {
					window.console.error('Error loading update hymn form:', error);
				}
				getElement('hymn-content').innerHTML = '<p style="color: red;">Failed to load update hymn form.</p>';
				return;
			}

			getElement('hymn-content').innerHTML = responseText;
			initializeUpdateForm();
		});
	}

	function showDeleteForm() {
		resetHymnListFilter();
		hideHymnListArea();
		xhrRequest('GET', 'ajax/get_delete_hymn_form.php', null, null, function (error, responseText) {
			if (error) {
				if (window.console && window.console.error) {
					window.console.error('Error loading delete hymn form:', error);
				}
				getElement('hymn-content').innerHTML = '<p style="color: red;">Failed to load delete hymn form.</p>';
				return;
			}

			getElement('hymn-content').innerHTML = responseText;
			initializeDeleteForm();
		});
	}

	function fillHymnForm(formId, hymn) {
		var form = getElement(formId);
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

	function deleteSelectedHymn() {
		var form = getElement('delete-hymn-form');
		var confirmation;

		if (!form || !form.elements.id.value) {
			alert('Select a hymn to delete first.');
			return;
		}

		confirmation = prompt('Are you sure? This cannot be done. Type "DELETE" to delete the hymn.');
		if (confirmation !== 'DELETE') {
			return;
		}

		xhrRequest('POST', 'ajax/delete_hymn.php', encodeParams({ id: form.elements.id.value }), 'application/x-www-form-urlencoded', function (error, responseText) {
			var result;

			if (error) {
				if (window.console && window.console.error) {
					window.console.error('Error deleting hymn:', error);
				}
				alert('Failed to delete hymn.');
				return;
			}

			result = JSON.parse(responseText);
			if (!result.success) {
				alert(result.message || 'Failed to delete hymn.');
				return;
			}

			loadHymns(currentFilter, currentView);
		});
	}

	function submitDeleteForm() {
		deleteSelectedHymn();
	}

	function formToString(form) {
		var elements = form.elements;
		var params = {};
		var i;

		for (i = 0; i < elements.length; i += 1) {
			var element = elements[i];
			if (!element.name || element.disabled) {
				continue;
			}
			if ((element.type === 'checkbox' || element.type === 'radio') && !element.checked) {
				continue;
			}
			params[element.name] = element.value;
		}

		return encodeParams(params);
	}

	function submitUpdateForm() {
		var form = getElement('update-hymn-form');
		var submitButton;

		if (!form || !form.elements.id.value) {
			alert('Select a hymn to update first.');
			return;
		}

		submitButton = form.querySelector('button');
		if (submitButton) {
			submitButton.disabled = true;
		}

		xhrRequest('POST', 'ajax/update_hymn.php', formToString(form), 'application/x-www-form-urlencoded', function (error, responseText) {
			var result;

			if (error) {
				if (window.console && window.console.error) {
					window.console.error('Error updating hymn:', error);
				}
				alert('Failed to update hymn.');
				if (submitButton) {
					submitButton.disabled = false;
				}
				return;
			}

			result = JSON.parse(responseText);
			if (!result.success) {
				alert(result.message || 'Failed to update hymn.');
				if (submitButton) {
					submitButton.disabled = false;
				}
				return;
			}

			alert('Hymn updated.');
			loadHymns(currentFilter, currentView);
		});
	}

	function saveHymnCard(hymnId) {
		setHymnCardSavingState(hymnId, true);
		xhrRequest('POST', 'ajax/update_hymn.php', collectHymnPayload(hymnId), 'application/x-www-form-urlencoded', function (error, responseText) {
			var result;

			if (error) {
				if (window.console && window.console.error) {
					window.console.error('Error updating hymn:', error);
				}
				alert('Failed to update hymn.');
				setHymnCardSavingState(hymnId, false);
				return;
			}

			result = JSON.parse(responseText);
			if (!result.success) {
				alert(result.message || 'Failed to update hymn.');
				setHymnCardSavingState(hymnId, false);
				return;
			}

			loadHymns(currentFilter, currentView);
		});
	}

	function cancelHymnCard(hymnId) {
		restoreHymnCardOriginalValues(hymnId);
		closeHymnDetailById(hymnId);
	}

	function handleHymnSearchSelection(event) {
		var target = event.target || event.srcElement;
		var formId;
		var searchData;
		var hymnId;

		if (!target || (' ' + target.className + ' ').indexOf(' hymn-search-input ') === -1) {
			return;
		}

		formId = target.getAttribute('data-form-id') || 'update-hymn-form';
		searchData = formId === 'delete-hymn-form' ? deleteHymnSearchData : updateHymnSearchData;
		if (!searchData || !searchData.options) {
			return;
		}

		hymnId = searchData.options[target.value];
		if (!hymnId) {
			return;
		}

		fillHymnForm(formId, searchData.hymns[hymnId]);
	}

	function handleDocumentChange(event) {
		var target = event.target || event.srcElement;

		handleHymnSearchSelection(event);

		if (hasClass(target, 'hymn-edit-input')) {
			syncSummaryDisplay(target.getAttribute('data-hymn-id'));
		}
	}

	function handleDocumentInput(event) {
		var target = event.target || event.srcElement;

		if (target && target.id === 'hymn-list-filter') {
			applyHymnListFilter();
		}

		if (hasClass(target, 'hymn-edit-input') && target.type !== 'checkbox') {
			syncSummaryDisplay(target.getAttribute('data-hymn-id'));
		}

		handleHymnSearchSelection(event);
	}

	function handleDocumentClick(event) {
		var target = event.target || event.srcElement;
		var saveButton = closestByClass(target, 'js-hymn-save-button');
		var cancelButton = closestByClass(target, 'js-hymn-cancel-button');
		var summaryRow = closestByClass(target, 'hymn-summary-row');
		var toggleButton = closestByClass(target, 'hymn-expand-toggle');

		if (saveButton) {
			saveHymnCard(saveButton.getAttribute('data-hymn-id'));
			return;
		}

		if (cancelButton) {
			cancelHymnCard(cancelButton.getAttribute('data-hymn-id'));
			return;
		}

		if (summaryRow && !closestByClass(target, 'active-column') && !hasClass(target, 'hymn-edit-input')) {
			toggleButton = summaryRow.querySelector('.hymn-expand-toggle');
			if (toggleButton) {
				toggleHymnDetail(toggleButton);
			}
			return;
		}

		if (toggleButton) {
			toggleHymnDetail(toggleButton);
		}
	}

	function handleAddFormSubmit(event) {
		var form = event.target || event.srcElement;
		var submitButton;

		if (!form || form.id !== 'add-hymn-form') {
			return;
		}

		if (event.preventDefault) {
			event.preventDefault();
		} else {
			event.returnValue = false;
		}

		submitButton = form.querySelector('button[type="submit"]');
		if (submitButton) {
			submitButton.disabled = true;
		}

		xhrRequest('POST', 'ajax/add_hymn.php', formToString(form), 'application/x-www-form-urlencoded', function (error, responseText) {
			var result;

			if (error) {
				if (window.console && window.console.error) {
					window.console.error('Error saving hymn:', error);
				}
				alert('Failed to add hymn.');
				if (submitButton) {
					submitButton.disabled = false;
				}
				return;
			}

			result = JSON.parse(responseText);
			if (!result.success) {
				alert(result.message || 'Failed to add hymn.');
				if (submitButton) {
					submitButton.disabled = false;
				}
				return;
			}

			loadHymns(currentFilter, currentView);
		});
	}

	function handleUpdateFormSubmit(event) {
		var form = event.target || event.srcElement;

		if (!form || form.id !== 'update-hymn-form') {
			return;
		}

		if (event.preventDefault) {
			event.preventDefault();
		} else {
			event.returnValue = false;
		}

		submitUpdateForm();
	}

	function init() {
		document.addEventListener('click', handleDocumentClick, false);
		document.addEventListener('input', handleDocumentInput, false);
		document.addEventListener('change', handleDocumentChange, false);
		document.addEventListener('submit', handleAddFormSubmit, false);
		document.addEventListener('submit', handleUpdateFormSubmit, false);
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
		document.addEventListener('DOMContentLoaded', init, false);
	} else {
		init();
	}
}());
