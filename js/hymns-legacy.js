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

	function clearHymnList() {
		hideHymnListArea();
		getElement('hymn-content').innerHTML = '<p>Select a button above to load hymns or open a hymn form.</p>';
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

	function loadHymns(filter, view) {
		if (typeof view === 'undefined') {
			view = 'list';
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
			var rows = table.querySelectorAll('tr');
			var visibleRows = 0;
			var j;

			for (j = 0; j < rows.length; j += 1) {
				var row = rows[j];
				var matches;

				if (j === 0) {
					row.style.display = '';
					continue;
				}

				matches = query === '' || row.textContent.toLowerCase().indexOf(query) !== -1;
				row.style.display = matches ? '' : 'none';
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

	function updateHymnField(field, value, hymnId, callback) {
		xhrRequest('POST', 'ajax/update_hymn_field.php', encodeParams({ id: hymnId, field: field, value: value }), 'application/x-www-form-urlencoded', function (error, responseText) {
			var result;

			if (error) {
				callback(error);
				return;
			}

			result = JSON.parse(responseText);
			if (!result.success) {
				callback(new Error(result.message || 'Failed to update hymn.'));
				return;
			}

			callback(null, result);
		});
	}

	function updateHymnCheckbox(checkbox, fieldName) {
		var hymnId = checkbox.getAttribute('data-hymn-id');
		var fieldValue = checkbox.checked ? 1 : 0;
		var originalState = !checkbox.checked;

		checkbox.disabled = true;
		updateHymnField(fieldName, fieldValue, hymnId, function (error) {
			if (error) {
				if (window.console && window.console.error) {
					window.console.error('Error updating hymn:', error);
				}
				checkbox.checked = originalState;
				alert('Failed to update hymn.');
				checkbox.disabled = false;
				return;
			}

			loadHymns(currentFilter, currentView);
		});
	}

	function updateKernlieder(input) {
		var hymnId = input.getAttribute('data-hymn-id');
		var originalValue = input.getAttribute('data-original-value');
		var newValue = trimString(input.value);

		if (originalValue === null) {
			originalValue = input.defaultValue;
		}

		if (newValue === originalValue) {
			return;
		}

		input.disabled = true;
		updateHymnField('kernlieder_target', newValue, hymnId, function (error) {
			if (error) {
				if (window.console && window.console.error) {
					window.console.error('Error updating hymn:', error);
				}
				input.value = originalValue;
				alert('Failed to update Kernlieder.');
			} else {
				input.setAttribute('data-original-value', newValue);
			}

			input.disabled = false;
		});
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

		if (target && (' ' + target.className + ' ').indexOf(' active-toggle ') !== -1) {
			updateHymnCheckbox(target, 'is_active');
		}

		if (target && (' ' + target.className + ' ').indexOf(' insert-toggle ') !== -1) {
			updateHymnCheckbox(target, 'insert_use');
		}

		if (target && (' ' + target.className + ' ').indexOf(' kernlieder-input ') !== -1) {
			updateKernlieder(target);
		}

		handleHymnSearchSelection(event);
	}

	function handleDocumentInput(event) {
		var target = event.target || event.srcElement;

		if (target && target.id === 'hymn-list-filter') {
			applyHymnListFilter();
		}

		handleHymnSearchSelection(event);
	}

	function handleFocusIn(event) {
		var target = event.target || event.srcElement;
		if (target && (' ' + target.className + ' ').indexOf(' kernlieder-input ') !== -1) {
			target.setAttribute('data-original-value', trimString(target.value));
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

	function init() {
		document.addEventListener('input', handleDocumentInput, false);
		document.addEventListener('change', handleDocumentChange, false);
		document.addEventListener('focusin', handleFocusIn, false);
		document.addEventListener('submit', handleAddFormSubmit, false);
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
