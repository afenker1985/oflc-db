<?php
$page_title = 'Database Functions';
include 'includes/header.php';
include 'includes/db.php';
?>

<h3>Hymns</h3>

<p>This will manage and view the hymns currently included in the database.</p>

<div class="hymn-controls">
<div class="hymn-controls-row">
<button onclick="loadHymns('all', 'list')">All Hymns</button>
<button onclick="loadHymns('active', 'list')">Active Hymns</button>
<button onclick="loadHymns('inactive', 'list')">Inactive Hymns</button>
</div>
<div class="hymn-controls-row">
<button onclick="loadHymns('all', 'section')">Hymns By Section</button>
<button onclick="loadHymns('active', 'section')">Active Hymns By Section</button>
<button onclick="loadHymns('inactive', 'section')">Inactive Hymns By Section</button>
</div>
<div class="hymn-controls-row">
<button class="add-hymn-button" onclick="showAddForm()">Add Hymn</button>
<button class="update-hymn-button" onclick="showUpdateForm()">Update Hymn</button>
<button class="delete-hymn-button" onclick="showDeleteForm()">Delete Hymn</button>
</div>
</div>

<div id="hymn-list-search" class="hymn-list-search">
<label for="hymn-list-filter">Filter Current Hymn List</label>
<input type="text" id="hymn-list-filter" placeholder="Type to narrow the current hymn list">
</div>

<div id="hymn-content">

</div>

<script>
let currentFilter = 'all';
let currentView = 'list';
let currentScreen = 'list';
let updateHymnSearchData = null;
let deleteHymnSearchData = null;

function showHymnListArea() {
	document.getElementById('hymn-list-search').style.display = '';
}

function hideHymnListArea() {
	document.getElementById('hymn-list-search').style.display = 'none';
}

async function loadHymns(filter, view = 'list') {
	try {
		currentFilter = filter;
		currentView = view;
		const response = await fetch ('ajax/get_hymns.php?filter=' + encodeURIComponent(filter) + '&view=' + encodeURIComponent(view));
		const html = await response.text();
		document.getElementById('hymn-content').innerHTML = html;
		applyHymnListFilter();
		showHymnListArea();
		currentScreen = 'list';
	} catch (error) {
		console.error('Error loading hymns:', error);
		document.getElementById('hymn-content').innerHTML = 
			'<p style="color: red;">Failed to load form.</p>';
	}
}

function applyHymnListFilter() {
	const filterInput = document.getElementById('hymn-list-filter');
	const hymnContent = document.getElementById('hymn-content');

	if (!filterInput || !hymnContent) {
		return;
	}

	const query = filterInput.value.trim().toLowerCase();
	const tables = hymnContent.querySelectorAll('.hymn-table');

	tables.forEach(function(table) {
		const rows = table.querySelectorAll('tr');
		let visibleRows = 0;

		rows.forEach(function(row, index) {
			if (index === 0) {
				row.style.display = '';
				return;
			}

			const rowText = row.textContent.toLowerCase();
			const matches = query === '' || rowText.indexOf(query) !== -1;
			row.style.display = matches ? '' : 'none';

			if (matches) {
				visibleRows += 1;
			}
		});

		const section = table.closest('.hymn-section');
		if (section) {
			section.style.display = visibleRows > 0 ? '' : 'none';
		}
	});
}

async function showAddForm() {
	try {
		hideHymnListArea();
		currentScreen = 'form';
		const response = await fetch('ajax/get_add_hymn_form.php');
		const html = await response.text();
		document.getElementById('hymn-content').innerHTML = html;
	} catch (error) {
		console.error('Error loading add hymn form:', error);
		document.getElementById('hymn-content').innerHTML =
			'<p style="color: red;">Failed to load add hymn form.</p>';
	}
}

async function showUpdateForm() {
	try {
		hideHymnListArea();
		currentScreen = 'form';
		const response = await fetch('ajax/get_update_hymn_form.php');
		const html = await response.text();
		document.getElementById('hymn-content').innerHTML = html;
		initializeUpdateForm();
	} catch (error) {
		console.error('Error loading update hymn form:', error);
		document.getElementById('hymn-content').innerHTML =
			'<p style="color: red;">Failed to load update hymn form.</p>';
	}
}

async function showDeleteForm() {
	try {
		hideHymnListArea();
		currentScreen = 'form';
		const response = await fetch('ajax/get_delete_hymn_form.php');
		const html = await response.text();
		document.getElementById('hymn-content').innerHTML = html;
		initializeDeleteForm();
	} catch (error) {
		console.error('Error loading delete hymn form:', error);
		document.getElementById('hymn-content').innerHTML =
			'<p style="color: red;">Failed to load delete hymn form.</p>';
	}
}

function initializeUpdateForm() {
	const dataElement = document.getElementById('update-hymn-search-data');

	if (!dataElement) {
		updateHymnSearchData = null;
		return;
	}

	updateHymnSearchData = JSON.parse(dataElement.textContent);
}

function initializeDeleteForm() {
	const dataElement = document.getElementById('delete-hymn-search-data');

	if (!dataElement) {
		deleteHymnSearchData = null;
		return;
	}

	deleteHymnSearchData = JSON.parse(dataElement.textContent);
}

function fillHymnForm(formId, hymn) {
	const form = document.getElementById(formId);

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
	const form = document.getElementById('delete-hymn-form');

	if (!form || !form.elements.id.value) {
		alert('Select a hymn to delete first.');
		return;
	}

	const confirmation = prompt('Are you sure? This cannot be done. Type "DELETE" to delete the hymn.');

	if (confirmation !== 'DELETE') {
		return;
	}

	try {
		const response = await fetch('ajax/delete_hymn.php', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: new URLSearchParams({
				id: form.elements.id.value,
			}),
		});

		const result = await response.json();

		if (!response.ok || !result.success) {
			throw new Error(result.message || 'Failed to delete hymn.');
		}

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
	const form = document.getElementById('update-hymn-form');

	if (!form || !form.elements.id.value) {
		alert('Select a hymn to update first.');
		return;
	}

	try {
		const submitButton = form.querySelector('button');
		const formData = new FormData(form);

		if (submitButton) {
			submitButton.disabled = true;
		}

		const response = await fetch('ajax/update_hymn.php', {
			method: 'POST',
			body: formData,
		});

		const result = await response.json();

		if (!response.ok || !result.success) {
			throw new Error(result.message || 'Failed to update hymn.');
		}

		alert('Hymn updated.');
		await loadHymns(currentFilter, currentView);
	} catch (error) {
		console.error('Error updating hymn:', error);
		alert(error.message || 'Failed to update hymn.');
		const submitButton = form.querySelector('button');
		if (submitButton) {
			submitButton.disabled = false;
		}
	}
}

async function updateHymnField(field, value, hymnId) {
	const response = await fetch('ajax/update_hymn_field.php', {
		method: 'POST',
		headers: {
			'Content-Type': 'application/x-www-form-urlencoded',
		},
		body: new URLSearchParams({
			id: hymnId,
			field: field,
			value: value,
		}),
	});

	const result = await response.json();

	if (!response.ok || !result.success) {
		throw new Error(result.message || 'Failed to update hymn.');
	}

	return result;
}

async function updateHymnCheckbox(checkbox, fieldName) {
	const hymnId = checkbox.dataset.hymnId;
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
	const hymnId = input.dataset.hymnId;
	const originalValue = input.dataset.originalValue ?? input.defaultValue;
	const newValue = input.value.trim();

	if (newValue === originalValue) {
		return;
	}

	input.disabled = true;

	try {
		await updateHymnField('kernlieder_target', newValue, hymnId);
		input.dataset.originalValue = newValue;
	} catch (error) {
		console.error('Error updating hymn:', error);
		input.value = originalValue;
		alert('Failed to update Kernlieder.');
	} finally {
		input.disabled = false;
	}
}

document.addEventListener('DOMContentLoaded', function() {
	loadHymns('all', 'list');
});

document.addEventListener('input', function(event) {
	if (event.target.id === 'hymn-list-filter') {
		applyHymnListFilter();
	}
});

document.addEventListener('change', function(event) {
	if (event.target.classList.contains('active-toggle')) {
		updateHymnCheckbox(event.target, 'is_active');
	}

	if (event.target.classList.contains('insert-toggle')) {
		updateHymnCheckbox(event.target, 'insert_use');
	}

	if (event.target.classList.contains('kernlieder-input')) {
		updateKernlieder(event.target);
	}
});

document.addEventListener('focusin', function(event) {
	if (event.target.classList.contains('kernlieder-input')) {
		event.target.dataset.originalValue = event.target.value.trim();
	}
});

function handleHymnSearchSelection(event) {
	if (!event.target.classList.contains('hymn-search-input')) {
		return;
	}

	const formId = event.target.dataset.formId || 'update-hymn-form';
	const searchData = formId === 'delete-hymn-form' ? deleteHymnSearchData : updateHymnSearchData;

	if (!searchData) {
		return;
	}

	const hymnId = searchData.options ? searchData.options[event.target.value] : null;

	if (!hymnId) {
		return;
	}

	fillHymnForm(formId, searchData.hymns[hymnId]);
}

document.addEventListener('change', handleHymnSearchSelection);
document.addEventListener('input', handleHymnSearchSelection);

document.addEventListener('submit', async function(event) {
	if (!event.target.matches('#add-hymn-form')) {
		return;
	}

	event.preventDefault();

	const form = event.target;

	const submitButton = form.querySelector('button[type="submit"]');
	const formData = new FormData(form);
	let url = 'ajax/add_hymn.php';
	let actionLabel = 'add hymn';

	submitButton.disabled = true;

	try {
		const response = await fetch(url, {
			method: 'POST',
			body: formData,
		});

		const result = await response.json();

		if (!response.ok || !result.success) {
			throw new Error(result.message || 'Failed to ' + actionLabel + '.');
		}

		await loadHymns(currentFilter, currentView);
	} catch (error) {
		console.error('Error saving hymn:', error);
		alert(error.message || 'Failed to ' + actionLabel + '.');
		submitButton.disabled = false;
	}
});

</script>

<?php include 'includes/footer.php'; ?>
