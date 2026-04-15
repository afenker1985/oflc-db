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
<button class="clear-list-button" onclick="clearHymnList()">Clear List</button>
<button class="add-hymn-button" onclick="showAddForm()">Add Hymn</button>
<button class="delete-hymn-button" onclick="showDeleteForm()">Delete Hymn</button>
</div>
</div>

<div id="hymn-list-search" class="hymn-list-search">
<label for="hymn-list-filter">Filter Current Hymn List</label>
<input type="text" id="hymn-list-filter" placeholder="Type to narrow the current hymn list">
</div>

<div id="hymn-content">
<p>Select a button above to load hymns or open a hymn form.</p>
</div>

<script>
(function() {
	var supportsModernJs = !!(
		window.fetch &&
		window.Promise &&
		window.URLSearchParams &&
		window.FormData &&
		document.querySelector &&
		Array.prototype.forEach
	);
	var script = document.createElement('script');
	script.src = supportsModernJs ? 'js/hymns-modern.js' : 'js/hymns-legacy.js';
	document.body.appendChild(script);
}());
</script>

<?php include 'includes/footer.php'; ?>
