<?php
/**
 * Admin Panel - All CRUD Operations
 * Materials Management System
 * 
 * Actions:
 * - (default) Dashboard
 * - sections: List sections
 * - section-form: Add/Edit section
 * - section-delete: Delete section
 * - materials: List materials
 * - material-form: Add/Edit material
 * - material-delete: Delete material
 */

require_once __DIR__ . '/classes.php';

session_start();

$action = $_GET['action'] ?? 'dashboard';
$sectionModel = new Section();
$materialModel = new Material();

$message = '';
$error = '';

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        switch ($action) {
            case 'section-form':
                $id = (int) ($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $slug = trim($_POST['slug'] ?? '') ?: generateSlug($name);

                if (empty($name)) {
                    $error = 'Section name is required.';
                } elseif ($sectionModel->slugExists($slug, $id ?: null)) {
                    $error = 'This slug already exists. Please choose another.';
                } else {
                    if ($id > 0) {
                        $sectionModel->update($id, $name, $slug);
                        $message = 'Section updated successfully!';
                    } else {
                        $sectionModel->create($name, $slug);
                        $message = 'Section created successfully!';
                    }
                    redirect(SITE_URL . '/admin.php?action=sections&success=1');
                }
                break;

            case 'material-form':
                $id = (int) ($_POST['id'] ?? 0);
                $data = [
                    'section_id' => (int) ($_POST['section_id'] ?? 0),
                    'title' => trim($_POST['title'] ?? ''),
                    'description' => trim($_POST['description'] ?? ''),
                    'file_type' => $_POST['file_type'] ?? '',
                    'file_url' => trim($_POST['file_url'] ?? ''),
                    'image_path' => $_POST['existing_image'] ?? null
                ];

                if (empty($data['title'])) {
                    $error = 'Title is required.';
                } elseif (empty($data['section_id'])) {
                    $error = 'Please select a section.';
                } elseif (!in_array($data['file_type'], ['gdrive_pdf', 'gdrive_word', 'image', 'youtube'])) {
                    $error = 'Invalid file type.';
                } else {
                    // Handle image upload
                    if ($data['file_type'] === 'image' && !empty($_FILES['image']['name'])) {
                        $uploadedImage = uploadImage($_FILES['image']);
                        if ($uploadedImage) {
                            // Delete old image if updating
                            if ($id > 0 && $data['image_path']) {
                                deleteImage($data['image_path']);
                            }
                            $data['image_path'] = $uploadedImage;
                        } else {
                            $error = 'Failed to upload image. Check file type and size.';
                        }
                    }

                    // Clear file_url for image type, clear image_path for gdrive/youtube types
                    if ($data['file_type'] === 'image') {
                        $data['file_url'] = null;
                    } else {
                        $data['image_path'] = null;
                    }
                    
                    // Validate YouTube URL if type is youtube
                    if ($data['file_type'] === 'youtube') {
                        if (empty($data['file_url'])) {
                            $error = 'YouTube URL is required for YouTube materials.';
                        } elseif (!getYouTubeVideoId($data['file_url'])) {
                            $error = 'Invalid YouTube URL. Please provide a valid YouTube video link.';
                        }
                    }

                    if (empty($error)) {
                        if ($id > 0) {
                            $materialModel->update($id, $data);
                            $message = 'Material updated successfully!';
                        } else {
                            $materialModel->create($data);
                            $message = 'Material created successfully!';
                        }
                        redirect(SITE_URL . '/admin.php?action=materials&success=1');
                    }
                }
                break;
        }
    }
}

// Handle delete actions
if ($action === 'section-delete' && isset($_GET['id'])) {
    $sectionModel->delete((int) $_GET['id']);
    redirect(SITE_URL . '/admin.php?action=sections&deleted=1');
}

if ($action === 'material-delete' && isset($_GET['id'])) {
    $materialModel->delete((int) $_GET['id']);
    redirect(SITE_URL . '/admin.php?action=materials&deleted=1');
}

// Success messages from redirects
if (isset($_GET['success'])) {
    $message = 'Operation completed successfully!';
}
if (isset($_GET['deleted'])) {
    $message = 'Item deleted successfully!';
}

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Course Materials</title>
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/style.css">
</head>

<body class="admin-body">
    <div class="admin-container">
        <!-- Sidebar -->
        <aside class="admin-sidebar">
            <div class="admin-logo">⚙️ Admin</div>
            <nav class="admin-nav">
                <a href="<?= SITE_URL ?>/admin.php" class="admin-link <?= $action === 'dashboard' ? 'active' : '' ?>">
                    📊 Dashboard
                </a>
                <a href="<?= SITE_URL ?>/admin.php?action=sections"
                    class="admin-link <?= str_starts_with($action, 'section') ? 'active' : '' ?>">
                    📁 Sections
                </a>
                <a href="<?= SITE_URL ?>/admin.php?action=materials"
                    class="admin-link <?= str_starts_with($action, 'material') ? 'active' : '' ?>">
                    📄 Materials
                </a>
                <hr class="admin-divider">
                <a href="<?= SITE_URL ?>/" class="admin-link" target="_blank">
                    🌐 View Site
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="admin-main">
            <?php if ($message): ?>
                <div class="alert alert-success">
                    <?= sanitize($message) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?= sanitize($error) ?>
                </div>
            <?php endif; ?>

            <?php
            switch ($action):
                case 'dashboard':
                    ?>
                    <!-- Dashboard -->
                    <div class="admin-header">
                        <h1>Dashboard</h1>
                    </div>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">📁</div>
                            <div class="stat-number">
                                <?= count($sectionModel->getAll()) ?>
                            </div>
                            <div class="stat-label">Sections</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">📄</div>
                            <div class="stat-number">
                                <?= $materialModel->getCount() ?>
                            </div>
                            <div class="stat-label">Materials</div>
                        </div>
                    </div>
                    <div class="quick-actions">
                        <h2>Quick Actions</h2>
                        <div class="actions-grid">
                            <a href="<?= SITE_URL ?>/admin.php?action=section-form" class="action-card">
                                <span class="action-icon">➕</span>
                                <span>Add Section</span>
                            </a>
                            <a href="<?= SITE_URL ?>/admin.php?action=material-form" class="action-card">
                                <span class="action-icon">➕</span>
                                <span>Add Material</span>
                            </a>
                        </div>
                    </div>
                    <?php
                    break;

                case 'sections':
                    $sections = $sectionModel->getAll();
                    ?>
                    <!-- Sections List -->
                    <div class="admin-header">
                        <h1>Sections</h1>
                        <a href="<?= SITE_URL ?>/admin.php?action=section-form" class="btn btn-primary">+ Add Section</a>
                    </div>
                    <?php if (empty($sections)): ?>
                        <div class="empty-state small">
                            <p>No sections yet. Create your first section!</p>
                        </div>
                    <?php else: ?>
                        <div class="admin-table-wrapper">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Slug</th>
                                        <th>Materials</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sections as $s): ?>
                                        <tr>
                                            <td><strong>
                                                    <?= sanitize($s['name']) ?>
                                                </strong></td>
                                            <td><code><?= sanitize($s['slug']) ?></code></td>
                                            <td>
                                                <?= $sectionModel->getMaterialCount($s['id']) ?>
                                            </td>
                                            <td>
                                                <?= date('M j, Y', strtotime($s['created_at'])) ?>
                                            </td>
                                            <td class="actions">
                                                <a href="<?= SITE_URL ?>/section/<?= $s['slug'] ?>" target="_blank" class="btn-icon"
                                                    title="View">👁️</a>
                                                <a href="<?= SITE_URL ?>/admin.php?action=section-form&id=<?= $s['id'] ?>"
                                                    class="btn-icon" title="Edit">✏️</a>
                                                <a href="<?= SITE_URL ?>/admin.php?action=section-delete&id=<?= $s['id'] ?>"
                                                    class="btn-icon delete" title="Delete"
                                                    onclick="return confirm('Delete this section and all its materials?')">🗑️</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <?php
                    break;

                case 'section-form':
                    $editSection = null;
                    if (isset($_GET['id'])) {
                        $editSection = $sectionModel->getById((int) $_GET['id']);
                    }
                    ?>
                    <!-- Section Form -->
                    <div class="admin-header">
                        <h1>
                            <?= $editSection ? 'Edit Section' : 'Add Section' ?>
                        </h1>
                    </div>
                    <form method="POST" class="admin-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="id" value="<?= $editSection['id'] ?? '' ?>">

                        <div class="form-group">
                            <label for="name">Section Name *</label>
                            <input type="text" id="name" name="name" required
                                value="<?= sanitize($editSection['name'] ?? $_POST['name'] ?? '') ?>"
                                placeholder="e.g., Getting Started">
                        </div>

                        <div class="form-group">
                            <label for="slug">URL Slug</label>
                            <input type="text" id="slug" name="slug"
                                value="<?= sanitize($editSection['slug'] ?? $_POST['slug'] ?? '') ?>"
                                placeholder="Leave empty to auto-generate">
                            <small>Used in URL: /section/<strong>your-slug</strong></small>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <?= $editSection ? 'Update' : 'Create' ?> Section
                            </button>
                            <a href="<?= SITE_URL ?>/admin.php?action=sections" class="btn btn-outline">Cancel</a>
                        </div>
                    </form>
                    <?php
                    break;

                case 'materials':
                    $materials = $materialModel->getAll();
                    $filterSection = isset($_GET['section_id']) ? (int) $_GET['section_id'] : null;
                    if ($filterSection) {
                        $materials = array_filter($materials, fn($m) => $m['section_id'] === $filterSection);
                    }
                    ?>
                    <!-- Materials List -->
                    <div class="admin-header">
                        <h1>Materials</h1>
                        <a href="<?= SITE_URL ?>/admin.php?action=material-form" class="btn btn-primary">+ Add Material</a>
                    </div>

                    <!-- Filter -->
                    <div class="filter-bar">
                        <select
                            onchange="window.location.href='<?= SITE_URL ?>/admin.php?action=materials' + (this.value ? '&section_id=' + this.value : '')">
                            <option value="">All Sections</option>
                            <?php foreach ($sectionModel->getAll() as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $filterSection === $s['id'] ? 'selected' : '' ?>>
                                    <?= sanitize($s['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if (empty($materials)): ?>
                        <div class="empty-state small">
                            <p>No materials yet. Add your first material!</p>
                        </div>
                    <?php else: ?>
                        <div class="admin-table-wrapper">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Section</th>
                                        <th>Type</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($materials as $m): ?>
                                        <tr>
                                            <td><strong>
                                                    <?= sanitize($m['title']) ?>
                                                </strong></td>
                                            <td>
                                                <?= sanitize($m['section_name']) ?>
                                            </td>
                                            <td><span class="badge">
                                                    <?= ucfirst(str_replace('_', ' ', $m['file_type'])) ?>
                                                </span></td>
                                            <td>
                                                <?= date('M j, Y', strtotime($m['created_at'])) ?>
                                            </td>
                                            <td class="actions">
                                                <a href="<?= SITE_URL ?>/material/<?= $m['id'] ?>" target="_blank" class="btn-icon"
                                                    title="View">👁️</a>
                                                <a href="<?= SITE_URL ?>/admin.php?action=material-form&id=<?= $m['id'] ?>"
                                                    class="btn-icon" title="Edit">✏️</a>
                                                <a href="<?= SITE_URL ?>/admin.php?action=material-delete&id=<?= $m['id'] ?>"
                                                    class="btn-icon delete" title="Delete"
                                                    onclick="return confirm('Delete this material?')">🗑️</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <?php
                    break;

                case 'material-form':
                    $editMaterial = null;
                    $preselectedSection = isset($_GET['section_id']) ? (int) $_GET['section_id'] : null;
                    if (isset($_GET['id'])) {
                        $editMaterial = $materialModel->getById((int) $_GET['id']);
                    }
                    $sections = $sectionModel->getAll();
                    ?>
                    <!-- Material Form -->
                    <div class="admin-header">
                        <h1>
                            <?= $editMaterial ? 'Edit Material' : 'Add Material' ?>
                        </h1>
                    </div>

                    <?php if (empty($sections)): ?>
                        <div class="alert alert-warning">
                            You need to <a href="<?= SITE_URL ?>/admin.php?action=section-form">create a section</a> first before
                            adding materials.
                        </div>
                    <?php else: ?>
                        <form method="POST" enctype="multipart/form-data" class="admin-form">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            <input type="hidden" name="id" value="<?= $editMaterial['id'] ?? '' ?>">
                            <input type="hidden" name="existing_image" value="<?= $editMaterial['image_path'] ?? '' ?>">

                            <div class="form-group">
                                <label for="section_id">Section *</label>
                                <select id="section_id" name="section_id" required>
                                    <option value="">Select a section</option>
                                    <?php foreach ($sections as $s): ?>
                                        <option value="<?= $s['id'] ?>" <?= ($editMaterial['section_id'] ?? $preselectedSection ?? '') == $s['id'] ? 'selected' : '' ?>>
                                            <?= sanitize($s['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="title">Title *</label>
                                <input type="text" id="title" name="title" required
                                    value="<?= sanitize($editMaterial['title'] ?? $_POST['title'] ?? '') ?>"
                                    placeholder="e.g., Introduction Guide">
                            </div>

                            <div class="form-group">
                                <label for="description">Description</label>
                                <textarea id="description" name="description" rows="4"
                                    placeholder="Brief description of this material..."><?= sanitize($editMaterial['description'] ?? $_POST['description'] ?? '') ?></textarea>
                            </div>

                            <div class="form-group">
                                <label for="file_type">File Type *</label>
                                <select id="file_type" name="file_type" required onchange="toggleFileFields()">
                                    <option value="">Select type</option>
                                    <option value="gdrive_pdf" <?= ($editMaterial['file_type'] ?? '') === 'gdrive_pdf' ? 'selected' : '' ?>>Google Drive PDF</option>
                                    <option value="gdrive_word" <?= ($editMaterial['file_type'] ?? '') === 'gdrive_word' ? 'selected' : '' ?>>Google Drive Word</option>
                                    <option value="image" <?= ($editMaterial['file_type'] ?? '') === 'image' ? 'selected' : '' ?>>
                                        Uploaded Image</option>
                                    <option value="youtube" <?= ($editMaterial['file_type'] ?? '') === 'youtube' ? 'selected' : '' ?>>YouTube Video</option>
                                </select>
                            </div>

                            <?php
                            $currentFileType = $editMaterial['file_type'] ?? '';
                            $showGdrive = in_array($currentFileType, ['gdrive_pdf', 'gdrive_word']);
                            $showYoutube = $currentFileType === 'youtube';
                            $showImage = $currentFileType === 'image';
                            ?>
                            <div class="form-group" id="gdrive-field" style="display: <?= $showGdrive ? 'block' : 'none' ?>;">
                                <label for="file_url">Google Drive Share Link</label>
                                <input type="url" id="file_url" name="file_url"
                                    value="<?= sanitize($editMaterial['file_url'] ?? '') ?>"
                                    placeholder="https://drive.google.com/file/d/.../view">
                                <small>Paste the share link from Google Drive</small>
                            </div>

                            <div class="form-group" id="youtube-field" style="display: <?= $showYoutube ? 'block' : 'none' ?>;">
                                <label for="youtube_url">YouTube Video URL</label>
                                <input type="url" id="youtube_url" name="file_url"
                                    value="<?= sanitize($editMaterial['file_url'] ?? '') ?>"
                                    placeholder="https://www.youtube.com/watch?v=... or https://youtu.be/...">
                                <small>Paste any YouTube video URL (watch, youtu.be, or embed format)</small>
                            </div>

                            <div class="form-group" id="image-field" style="display: <?= $showImage ? 'block' : 'none' ?>;">
                                <label for="image">Upload Image</label>
                                <?php if (!empty($editMaterial['image_path'])): ?>
                                    <div class="current-image">
                                        <img src="<?= UPLOAD_URL . sanitize($editMaterial['image_path']) ?>" alt="Current" width="100">
                                        <span>Current image</span>
                                    </div>
                                <?php endif; ?>
                                <input type="file" id="image" name="image" accept="image/*">
                                <small>Allowed: JPG, PNG, GIF, WebP (max 5MB)</small>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <?= $editMaterial ? 'Update' : 'Create' ?> Material
                                </button>
                                <a href="<?= SITE_URL ?>/admin.php?action=materials" class="btn btn-outline">Cancel</a>
                            </div>
                        </form>

                        <script>
                            function toggleFileFields() {
                                const fileTypeSelect = document.getElementById('file_type');
                                if (!fileTypeSelect) return;
                                
                                const type = fileTypeSelect.value;
                                const gdriveField = document.getElementById('gdrive-field');
                                const youtubeField = document.getElementById('youtube-field');
                                const imageField = document.getElementById('image-field');
                                
                                if (!gdriveField || !youtubeField || !imageField) return;
                                
                                // Hide all fields first
                                gdriveField.style.display = 'none';
                                youtubeField.style.display = 'none';
                                imageField.style.display = 'none';
                                
                                // Show appropriate field based on type
                                if (type === 'gdrive_pdf' || type === 'gdrive_word') {
                                    gdriveField.style.display = 'block';
                                } else if (type === 'youtube') {
                                    youtubeField.style.display = 'block';
                                } else if (type === 'image') {
                                    imageField.style.display = 'block';
                                }
                            }
                            
                            // Initialize on page load - use multiple methods to ensure it runs
                            (function() {
                                if (document.readyState === 'loading') {
                                    document.addEventListener('DOMContentLoaded', function() {
                                        setTimeout(toggleFileFields, 10);
                                    });
                                } else {
                                    setTimeout(toggleFileFields, 10);
                                }
                                // Also run on window load as backup
                                window.addEventListener('load', toggleFileFields);
                            })();

                            toggleFileFields()
                        </script>
                    <?php endif; ?>
                    <?php
                    break;

                default:
                    redirect(SITE_URL . '/admin.php');
            endswitch;
            ?>
        </main>
    </div>

    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
</body>

</html>