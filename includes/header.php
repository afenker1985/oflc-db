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

$nav_dropdowns = [
    'Database' => [
        ['href' => 'leaders.php', 'label' => 'Leaders'],
        ['href' => 'church-year.php', 'label' => 'Church Year'],
    ],
    'Hymns' => [
        ['href' => 'hymns.php', 'label' => 'Hymn Database'],
        ['href' => 'hymn-reports.php', 'label' => 'Hymn Reports'],
    ],
    'Services' => [
        ['href' => 'add-service.php', 'label' => 'Add a Service'],
        ['href' => 'update-service.php', 'label' => 'Update a Service'],
        ['href' => 'remove-service.php', 'label' => 'Remove a Service'],
    ],
];

foreach ($nav_dropdowns as &$nav_dropdown_items) {
    usort(
        $nav_dropdown_items,
        static function (array $left, array $right): int {
            return strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        }
    );
}
unset($nav_dropdown_items);
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
            <h2>Service Scheduler</h2>
        </div>

        <div class="main-nav">
            <ul>
                <li class="nav-item-home">
                    <a href="index.php" aria-label="Home">
                        <img src="home.png" width="25" alt="Home">
                    </a>
                </li>
                <?php foreach ($nav_dropdowns as $menu_label => $menu_items): ?>
                    <li class="nav-item-has-dropdown">
                        <a href="#" class="nav-link-with-caret" aria-haspopup="true"><?php echo htmlspecialchars($menu_label, ENT_QUOTES, 'UTF-8'); ?></a>
                        <ul class="nav-dropdown">
                            <?php foreach ($menu_items as $menu_item): ?>
                                <li>
                                    <a href="<?php echo htmlspecialchars((string) ($menu_item['href'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars((string) ($menu_item['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php endforeach; ?>
                <li><a href="schedule.php">Service Schedule</a></li>
            </ul>
        </div>

        <div class="content">
