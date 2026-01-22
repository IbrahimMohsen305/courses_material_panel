<?php
/**
 * Section Page - Display Materials for a Section
 * Materials Management System
 */

require_once __DIR__ . '/classes.php';

// Get slug from URL
$slug = $_GET['slug'] ?? '';

if (empty($slug)) {
    redirect(SITE_URL);
}

$sectionModel = new Section();
$materialModel = new Material();

$section = $sectionModel->getBySlug($slug);

if (!$section) {
    http_response_code(404);
    $error = true;
} else {
    $error = false;
    $materials = $materialModel->getBySectionId($section['id']);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description"
        content="<?= $error ? 'Section not found' : 'Browse materials in ' . sanitize($section['name']) ?>">
    <title><?= $error ? 'Not Found' : sanitize($section['name']) ?> - Courses</title>
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/style.css">
</head>

<body>
    <!-- Header -->
    <header class="header">
        <div class="logo">Courses</div>
        <nav class="nav">
            <a href="<?= SITE_URL ?>/" class="nav-link">Home</a>
            <a href="<?= SITE_URL ?>/admin.php" class="nav-link">Admin</a>
        </nav>
    </header>

    <!-- Main Content -->
    <main class="main">
        <?php if ($error): ?>
            <div class="error-state">
                <div class="error-icon">🔍</div>
                <h2>Section Not Found</h2>
                <p>The section you're looking for doesn't exist.</p>
                <a href="<?= SITE_URL ?>/" class="btn btn-primary">Back to Home</a>
            </div>
        <?php else: ?>
            <!-- Breadcrumb -->
            <nav class="breadcrumb">
                <a href="<?= SITE_URL ?>/">Home</a>
                <span class="separator">/</span>
                <span class="current"><?= sanitize($section['name']) ?></span>
            </nav>

            <h2 class="page-title"><?= sanitize($section['name']) ?></h2>

            <?php if (empty($materials)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📄</div>
                    <h3>No Materials Yet</h3>
                    <p>This section doesn't have any materials yet.</p>
                    <a href="<?= SITE_URL ?>/admin.php?action=material-form&section_id=<?= $section['id'] ?>"
                        class="btn btn-primary">Add Material</a>
                </div>
            <?php else: ?>
                <div class="materials-grid">
                    <?php foreach ($materials as $m): ?>
                        <a href="<?= SITE_URL ?>/material/<?= $m['id'] ?>" class="material-card">
                            <?php if ($m['file_type'] === 'image' && !empty($m['image_path'])): ?>
                                <img src="<?= UPLOAD_URL . sanitize($m['image_path']) ?>" alt="<?= sanitize($m['title']) ?>"
                                    class="material-thumb">
                            <?php else: ?>
                                <div class="material-thumb-placeholder">
                                    <?php
                                    $icon = match ($m['file_type']) {
                                        'gdrive_pdf' => '📕',
                                        'gdrive_word' => '📘',
                                        'image' => '🖼️',
                                        'youtube' => '▶️',
                                        default => '📄'
                                    };
                                    echo $icon;
                                    ?>
                                </div>
                            <?php endif; ?>
                            <div class="material-content">
                                <h3 class="material-title"><?= sanitize($m['title']) ?></h3>
                                <?php if ($m['description']): ?>
                                    <p class="material-desc"><?= sanitize(substr($m['description'], 0, 80)) ?>...</p>
                                <?php endif; ?>
                                <span class="material-badge"><?= ucfirst(str_replace('_', ' ', $m['file_type'])) ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <p>&copy; <?= date('Y') ?> Course Materials. All rights reserved.</p>
    </footer>

    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
</body>

</html>