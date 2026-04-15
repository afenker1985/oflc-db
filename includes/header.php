<?php
$page_title = isset($page_title) ? $page_title : 'Hymn Database';
$styles_path = __DIR__ . '/../css/styles.css';
$styles_version = file_exists($styles_path) ? filemtime($styles_path) : time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" type="text/css" href="css/styles.css?v=<?php echo rawurlencode((string) $styles_version); ?>">
</head>
<body>
    <div class="container">
        <div class="site-header">
            <h1>Our Father's Evangelical Lutheran Church</h1>
            <h2>Hymn Database and Scheduler</h2>
        </div>

        <div class="main-nav">
            <ul>
                <li><a href="/"><img src="home.png" width="25px"></a></li>
                <li><a href="hymns.php">Hymns</a></li>
                <li><a href="planning.php">Service Planning</a></li>
                <li><a href="review.php">Service Review</a></li>
                <li><a href="schedule.php">Service Schedule</a></li>
            </ul>
        </div>

        <div class="content">
