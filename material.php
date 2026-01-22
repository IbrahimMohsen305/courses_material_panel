<?php
/**
 * Material Details Page - View Single Material
 * Materials Management System
 */

require_once __DIR__ . '/classes.php';

// Get ID from URL
$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    redirect(SITE_URL);
}

$materialModel = new Material();
$material = $materialModel->getById($id);

if (!$material) {
    http_response_code(404);
    $error = true;
} else {
    $error = false;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= $error ? 'Material not found' : sanitize($material['title']) ?>">
    <title><?= $error ? 'Not Found' : sanitize($material['title']) ?> - Courses</title>
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
                <h2>Material Not Found</h2>
                <p>The material you're looking for doesn't exist.</p>
                <a href="<?= SITE_URL ?>/" class="btn btn-primary">Back to Home</a>
            </div>
        <?php else: ?>
            <!-- Breadcrumb -->
            <nav class="breadcrumb">
                <a href="<?= SITE_URL ?>/">Home</a>
                <span class="separator">/</span>
                <a
                    href="<?= SITE_URL ?>/section/<?= sanitize($material['section_slug']) ?>"><?= sanitize($material['section_name']) ?></a>
                <span class="separator">/</span>
                <span class="current"><?= sanitize($material['title']) ?></span>
            </nav>

            <article class="material-detail">
                <header class="material-header">
                    <div class="material-type-large">
                        <?php
                        $icon = match ($material['file_type']) {
                            'gdrive_pdf' => '📕',
                            'gdrive_word' => '📘',
                            'image' => '🖼️',
                            'youtube' => '▶️',
                            default => '📄'
                        };
                        echo $icon;
                        ?>
                    </div>
                    <div class="material-info">
                        <h1><?= sanitize($material['title']) ?></h1>
                        <span
                            class="material-badge large"><?= ucfirst(str_replace('_', ' ', $material['file_type'])) ?></span>
                    </div>
                </header>

                <?php if ($material['description']): ?>
                    <div class="material-description">
                        <h3>Description</h3>
                        <p><?= nl2br(sanitize($material['description'])) ?></p>
                    </div>
                <?php endif; ?>

                <div class="material-preview">
                    <?php if ($material['file_type'] === 'image' && $material['image_path']): ?>
                        <!-- Image Display -->
                        <div class="image-preview">
                            <img src="<?= UPLOAD_URL . sanitize($material['image_path']) ?>"
                                alt="<?= sanitize($material['title']) ?>" loading="lazy">
                        </div>
                    <?php elseif (in_array($material['file_type'], ['gdrive_pdf', 'gdrive_word']) && $material['file_url']): ?>
                        <!-- Google Drive Embed -->
                        <div class="gdrive-preview">
                            <iframe src="<?= getGoogleDriveEmbedUrl($material['file_url']) ?>" width="100%" height="600"
                                frameborder="0" allowfullscreen>
                            </iframe>
                            <div class="gdrive-actions">
                                <a href="<?= sanitize($material['file_url']) ?>" target="_blank" rel="noopener noreferrer"
                                    class="btn btn-secondary">
                                    Open in New Tab ↗
                                </a>
                            </div>
                        </div>
                    <?php elseif ($material['file_type'] === 'youtube' && $material['file_url']): ?>
                        <!-- YouTube Embed -->
                        <div class="youtube-preview">
                            <div class="youtube-wrapper">
                                <iframe src="<?= getYouTubeEmbedUrl($material['file_url']) ?>?rel=0" 
                                    width="100%" height="600" frameborder="0" 
                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                                    allowfullscreen>
                                </iframe>
                            </div>
                            <div class="youtube-actions">
                                <a href="<?= sanitize($material['file_url']) ?>" target="_blank" rel="noopener noreferrer"
                                    class="btn btn-secondary">
                                    Watch on YouTube ↗
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <p>No preview available for this material.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <footer class="material-footer">
                    <a href="<?= SITE_URL ?>/section/<?= sanitize($material['section_slug']) ?>" class="btn btn-outline">
                        ← Back to <?= sanitize($material['section_name']) ?>
                    </a>
                </footer>
            </article>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <p>&copy; <?= date('Y') ?> Course Materials. All rights reserved.</p>
    </footer>

    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
</body>

</html>