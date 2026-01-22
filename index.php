<?php
/**
 * Main Page - List All Sections
 * Materials Management System
 */

require_once __DIR__ . '/classes.php';

$section = new Section();
$sections = $section->getAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Browse our collection of educational materials organized by sections.">
    <title>Courses</title>
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/style.css">
</head>

<body>
    <!-- Header -->
    <header class="header">
        <div class="logo">Courses</div>
        <nav class="nav">
            <a href="<?= SITE_URL ?>/admin.php" class="nav-link">Admin</a>
        </nav>
    </header>

    <!-- Main Content -->
    <main class="main">
        <h2 class="page-title">All Courses</h2>

        <?php if (empty($sections)): ?>
            <div class="empty-state">
                <div class="empty-icon">📂</div>
                <h3>No Sections Yet</h3>
                <p>Start by creating sections in the admin panel.</p>
                <a href="<?= SITE_URL ?>/admin.php?action=section-form" class="btn btn-primary">Create Section</a>
            </div>
        <?php else: ?>
            <div class="sections-grid">
                <?php foreach ($sections as $s): ?>
                    <a href="<?= SITE_URL ?>/section/<?= sanitize($s['slug']) ?>" class="section-card">
                        <img src="<?= SITE_URL ?>/assets/images/sections/<?= sanitize($s['slug']) ?>.jpg"
                            alt="<?= sanitize($s['name']) ?>" class="section-image"
                            onerror="this.onerror=null; this.src='<?= SITE_URL ?>/assets/images/sections/default.jpg';">
                        <div class="section-info">
                            <h3 class="section-title"><?= sanitize($s['name']) ?></h3>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <p>&copy; <?= date('Y') ?> Course Materials. All rights reserved.</p>
    </footer>

    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
</body>

</html>