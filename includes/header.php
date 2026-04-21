<?php
$page_title = isset($page_title) ? $page_title : 'Hymn Database';
$stylesheet_files = isset($stylesheet_files) && is_array($stylesheet_files) && $stylesheet_files !== []
    ? $stylesheet_files
    : [
        'css/main.css',
        'css/hymns.css',
        'css/services.css',
    ];
$body_class = isset($body_class) ? trim((string) $body_class) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></title>
    <?php foreach ($stylesheet_files as $stylesheet_file): ?>
        <?php
        $stylesheet_path = __DIR__ . '/../' . $stylesheet_file;
        $stylesheet_version = file_exists($stylesheet_path) ? filemtime($stylesheet_path) : time();
        ?>
        <link rel="stylesheet" type="text/css" href="<?php echo htmlspecialchars($stylesheet_file, ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo rawurlencode((string) $stylesheet_version); ?>">
    <?php endforeach; ?>
</head>
<body<?php echo $body_class !== '' ? ' class="' . htmlspecialchars($body_class, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
    <div class="container">
        <div class="site-header">
            <h1>Our Father's Evangelical Lutheran Church</h1>
            <h2>Hymn Database and Scheduler</h2>
        </div>

        <div class="main-nav">
            <ul>
                <li class="nav-item-home">
                    <a href="index.php" aria-label="Home">
                        <img src="home.png" width="25" alt="Home">
                    </a>
                </li>
                <li class="nav-item-has-dropdown">
                    <a href="#" class="nav-link-with-caret" aria-haspopup="true">Database</a>
                    <ul class="nav-dropdown">
                        <li><a href="leaders.php">Leaders</a></li>
                    </ul>
                </li>
                <li class="nav-item-has-dropdown">
                    <a href="#" class="nav-link-with-caret" aria-haspopup="true">Hymns</a>
                    <ul class="nav-dropdown">
                        <li><a href="hymns.php">Hymn Database</a></li>
                        <li><a href="hymn-reports.php">Hymn Reports</a></li>
                    </ul>
                </li>
                <li class="nav-item-has-dropdown">
                    <a href="#" class="nav-link-with-caret" aria-haspopup="true">Services</a>
                    <ul class="nav-dropdown">
                        <li><a href="add-service.php">Add a Service</a></li>
                        <li><a href="update-service.php">Update a Service</a></li>
                        <li><a href="remove-service.php">Remove a Service</a></li>
                    </ul>
                </li>
                <li><a href="schedule.php">Service Schedule</a></li>
            </ul>
        </div>

        <div class="content">
