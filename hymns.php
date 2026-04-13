<?php
$page_title = 'Database Functions';
include 'includes/header.php';
include 'includes/db.php';
?>

<h3>Hymns</h3>

<p>This will manage and view the hymns currently included in the database.</p>

<div class="hymn-controls">
<button onclick="loadHymns('all')">List All Hymns</button>
<button onclick="loadHymns('active')">List Active Hymns</button>
<button onclick="loadHymns('inactive')">List Inactive Hymns</button>
<button onclick="showAddForm()">Add Hymn</button>

<div id="hymn-content">

</div>

<script>
async function loadHymns(filter) {
	try {
		const response = await fetch ('ajax/get_hymns.php?filter=' + encodeURIComponent(filter));
		const html = await response.text();
		document.getElementById('hymn-content').innerHTML = html;
	} catch (error) {
		console.error('Error loading hymns:', error);
		document.getElementById('hymn-content').innerHTML = 
			'<p style="color: red;">Failed to load form.</p>';
	}
}

document.addEventListener('DOMContentLoaded', function() {
	loadHymns('all');
});

</script>

<?php include 'includes/footer.php'; ?>