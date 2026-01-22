<?php
/**
 * Materials Management System - Single File Application
 * All functionality combined into one PHP file
 */

// ============================================================================
// CONFIGURATION & DATABASE
// ============================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('DB_HOST', 'localhost');
define('DB_NAME', 'courses_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('SITE_URL', 'http://localhost/Courses');
define('UPLOAD_PATH', __DIR__ . '/uploads/images/');
define('UPLOAD_URL', SITE_URL . '/uploads/images/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024);
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp']);

class Database {
    private static ?PDO $instance = null;
    
    public static function getInstance(): PDO {
        if (self::$instance === null) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ];
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                die("Database connection failed: " . $e->getMessage());
            }
        }
        return self::$instance;
    }
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function generateSlug(string $text): string {
    $text = preg_replace('/[^a-zA-Z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', $text);
    return strtolower(trim($text, '-'));
}

function redirect(string $url): void {
    header("Location: $url");
    exit;
}

function getGoogleDriveEmbedUrl(string $url): string {
    if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
        return "https://drive.google.com/file/d/{$matches[1]}/preview";
    }
    if (preg_match('/\/document\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
        return "https://docs.google.com/document/d/{$matches[1]}/preview";
    }
    return $url;
}

function getYouTubeEmbedUrl(string $url): string {
    $patterns = [
        '/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/',
        '/youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/',
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $matches)) {
            return "https://www.youtube.com/embed/{$matches[1]}";
        }
    }
    
    return $url;
}

function getYouTubeVideoId(string $url): ?string {
    $patterns = [
        '/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/',
        '/youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/',
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $matches)) {
            return $matches[1];
        }
    }
    
    return null;
}

function uploadImage(array $file): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > MAX_FILE_SIZE) return null;
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS)) return null;
    
    if (!is_dir(UPLOAD_PATH)) {
        mkdir(UPLOAD_PATH, 0755, true);
    }
    
    $filename = uniqid('img_') . '.' . $ext;
    $destination = UPLOAD_PATH . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return $filename;
    }
    
    return null;
}

function deleteImage(string $filename): void {
    $path = UPLOAD_PATH . $filename;
    if (file_exists($path)) {
        unlink($path);
    }
}

function generateCSRFToken(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken(string $token): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ============================================================================
// MODEL CLASSES
// ============================================================================

class Section {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAll(): array {
        $stmt = $this->db->query("SELECT * FROM sections ORDER BY created_at DESC");
        return $stmt->fetchAll();
    }

    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM sections WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function getBySlug(string $slug): ?array {
        $stmt = $this->db->prepare("SELECT * FROM sections WHERE slug = ?");
        $stmt->execute([$slug]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function create(string $name, string $slug): int {
        $stmt = $this->db->prepare("INSERT INTO sections (name, slug) VALUES (?, ?)");
        $stmt->execute([$name, $slug]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $name, string $slug): bool {
        $stmt = $this->db->prepare("UPDATE sections SET name = ?, slug = ? WHERE id = ?");
        return $stmt->execute([$name, $slug, $id]);
    }

    public function delete(int $id): bool {
        $material = new Material();
        $materials = $material->getBySectionId($id);
        foreach ($materials as $m) {
            if ($m['image_path']) {
                deleteImage($m['image_path']);
            }
        }
        $stmt = $this->db->prepare("DELETE FROM sections WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function getMaterialCount(int $id): int {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM materials WHERE section_id = ?");
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool {
        $sql = "SELECT COUNT(*) FROM sections WHERE slug = ?";
        $params = [$slug];
        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }
}

class Material {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAll(): array {
        $stmt = $this->db->query("
            SELECT m.*, s.name as section_name, s.slug as section_slug 
            FROM materials m 
            JOIN sections s ON m.section_id = s.id 
            ORDER BY m.created_at DESC
        ");
        return $stmt->fetchAll();
    }

    public function getBySectionId(int $sectionId): array {
        $stmt = $this->db->prepare("SELECT * FROM materials WHERE section_id = ? ORDER BY created_at DESC");
        $stmt->execute([$sectionId]);
        return $stmt->fetchAll();
    }

    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT m.*, s.name as section_name, s.slug as section_slug 
            FROM materials m 
            JOIN sections s ON m.section_id = s.id 
            WHERE m.id = ?
        ");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO materials (section_id, title, description, file_type, file_url, image_path) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['section_id'],
            $data['title'],
            $data['description'] ?? null,
            $data['file_type'],
            $data['file_url'] ?? null,
            $data['image_path'] ?? null
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool {
        $stmt = $this->db->prepare("
            UPDATE materials 
            SET section_id = ?, title = ?, description = ?, file_type = ?, file_url = ?, image_path = ? 
            WHERE id = ?
        ");
        return $stmt->execute([
            $data['section_id'],
            $data['title'],
            $data['description'] ?? null,
            $data['file_type'],
            $data['file_url'] ?? null,
            $data['image_path'] ?? null,
            $id
        ]);
    }

    public function delete(int $id): bool {
        $material = $this->getById($id);
        if ($material && $material['image_path']) {
            deleteImage($material['image_path']);
        }
        $stmt = $this->db->prepare("DELETE FROM materials WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function getCount(): int {
        $stmt = $this->db->query("SELECT COUNT(*) FROM materials");
        return (int) $stmt->fetchColumn();
    }
}

// ============================================================================
// ROUTING
// ============================================================================

session_start();

$route = $_GET['route'] ?? 'home';
$sectionModel = new Section();
$materialModel = new Material();
$message = '';
$error = '';

// Handle admin POST requests
if ($route === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_GET['action'] ?? 'dashboard';
    
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
                    } else {
                        $sectionModel->create($name, $slug);
                    }
                    redirect(SITE_URL . '/?route=admin&action=sections&success=1');
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
                    if ($data['file_type'] === 'image' && !empty($_FILES['image']['name'])) {
                        $uploadedImage = uploadImage($_FILES['image']);
                        if ($uploadedImage) {
                            if ($id > 0 && $data['image_path']) {
                                deleteImage($data['image_path']);
                            }
                            $data['image_path'] = $uploadedImage;
                        } else {
                            $error = 'Failed to upload image. Check file type and size.';
                        }
                    }

                    if ($data['file_type'] === 'image') {
                        $data['file_url'] = null;
                    } else {
                        $data['image_path'] = null;
                    }
                    
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
                        } else {
                            $materialModel->create($data);
                        }
                        redirect(SITE_URL . '/?route=admin&action=materials&success=1');
                    }
                }
                break;
        }
    }
}

// Handle admin delete actions
if ($route === 'admin') {
    $action = $_GET['action'] ?? 'dashboard';
    
    if ($action === 'section-delete' && isset($_GET['id'])) {
        $sectionModel->delete((int) $_GET['id']);
        redirect(SITE_URL . '/?route=admin&action=sections&deleted=1');
    }

    if ($action === 'material-delete' && isset($_GET['id'])) {
        $materialModel->delete((int) $_GET['id']);
        redirect(SITE_URL . '/?route=admin&action=materials&deleted=1');
    }

    if (isset($_GET['success'])) {
        $message = 'Operation completed successfully!';
    }
    if (isset($_GET['deleted'])) {
        $message = 'Item deleted successfully!';
    }
}

$csrfToken = generateCSRFToken();

// ============================================================================
// RENDER VIEWS
// ============================================================================

if ($route === 'admin') {
    $action = $_GET['action'] ?? 'dashboard';
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
        <aside class="admin-sidebar">
            <div class="admin-logo">⚙️ Admin</div>
            <nav class="admin-nav">
                <a href="<?= SITE_URL ?>/?route=admin" class="admin-link <?= $action === 'dashboard' ? 'active' : '' ?>">📊 Dashboard</a>
                <a href="<?= SITE_URL ?>/?route=admin&action=sections" class="admin-link <?= str_starts_with($action, 'section') ? 'active' : '' ?>">📁 Sections</a>
                <a href="<?= SITE_URL ?>/?route=admin&action=materials" class="admin-link <?= str_starts_with($action, 'material') ? 'active' : '' ?>">📄 Materials</a>
                <hr class="admin-divider">
                <a href="<?= SITE_URL ?>/?route=home" class="admin-link" target="_blank">🌐 View Site</a>
            </nav>
        </aside>
        <main class="admin-main">
            <?php if ($message): ?>
                <div class="alert alert-success"><?= sanitize($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= sanitize($error) ?></div>
            <?php endif; ?>
            <?php
            switch ($action):
                case 'dashboard':
                    ?>
                    <div class="admin-header"><h1>Dashboard</h1></div>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">📁</div>
                            <div class="stat-number"><?= count($sectionModel->getAll()) ?></div>
                            <div class="stat-label">Sections</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">📄</div>
                            <div class="stat-number"><?= $materialModel->getCount() ?></div>
                            <div class="stat-label">Materials</div>
                        </div>
                    </div>
                    <div class="quick-actions">
                        <h2>Quick Actions</h2>
                        <div class="actions-grid">
                            <a href="<?= SITE_URL ?>/?route=admin&action=section-form" class="action-card">
                                <span class="action-icon">➕</span>
                                <span>Add Section</span>
                            </a>
                            <a href="<?= SITE_URL ?>/?route=admin&action=material-form" class="action-card">
                                <span class="action-icon">➕</span>
                                <span>Add Material</span>
                            </a>
                        </div>
                    </div>
                    <?php break;
                case 'sections':
                    $sections = $sectionModel->getAll();
                    ?>
                    <div class="admin-header">
                        <h1>Sections</h1>
                        <a href="<?= SITE_URL ?>/?route=admin&action=section-form" class="btn btn-primary">+ Add Section</a>
                    </div>
                    <?php if (empty($sections)): ?>
                        <div class="empty-state small"><p>No sections yet. Create your first section!</p></div>
                    <?php else: ?>
                        <div class="admin-table-wrapper">
                            <table class="admin-table">
                                <thead>
                                    <tr><th>Name</th><th>Slug</th><th>Materials</th><th>Created</th><th>Actions</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sections as $s): ?>
                                        <tr>
                                            <td><strong><?= sanitize($s['name']) ?></strong></td>
                                            <td><code><?= sanitize($s['slug']) ?></code></td>
                                            <td><?= $sectionModel->getMaterialCount($s['id']) ?></td>
                                            <td><?= date('M j, Y', strtotime($s['created_at'])) ?></td>
                                            <td class="actions">
                                                <a href="<?= SITE_URL ?>/?route=section&slug=<?= $s['slug'] ?>" target="_blank" class="btn-icon" title="View">👁️</a>
                                                <a href="<?= SITE_URL ?>/?route=admin&action=section-form&id=<?= $s['id'] ?>" class="btn-icon" title="Edit">✏️</a>
                                                <a href="<?= SITE_URL ?>/?route=admin&action=section-delete&id=<?= $s['id'] ?>" class="btn-icon delete" title="Delete" onclick="return confirm('Delete this section and all its materials?')">🗑️</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <?php break;
                case 'section-form':
                    $editSection = null;
                    if (isset($_GET['id'])) {
                        $editSection = $sectionModel->getById((int) $_GET['id']);
                    }
                    ?>
                    <div class="admin-header"><h1><?= $editSection ? 'Edit Section' : 'Add Section' ?></h1></div>
                    <form method="POST" class="admin-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="id" value="<?= $editSection['id'] ?? '' ?>">
                        <div class="form-group">
                            <label for="name">Section Name *</label>
                            <input type="text" id="name" name="name" required value="<?= sanitize($editSection['name'] ?? $_POST['name'] ?? '') ?>" placeholder="e.g., Getting Started">
                        </div>
                        <div class="form-group">
                            <label for="slug">URL Slug</label>
                            <input type="text" id="slug" name="slug" value="<?= sanitize($editSection['slug'] ?? $_POST['slug'] ?? '') ?>" placeholder="Leave empty to auto-generate">
                            <small>Used in URL: /section/<strong>your-slug</strong></small>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary"><?= $editSection ? 'Update' : 'Create' ?> Section</button>
                            <a href="<?= SITE_URL ?>/?route=admin&action=sections" class="btn btn-outline">Cancel</a>
                        </div>
                    </form>
                    <?php break;
                case 'materials':
                    $materials = $materialModel->getAll();
                    $filterSection = isset($_GET['section_id']) ? (int) $_GET['section_id'] : null;
                    if ($filterSection) {
                        $materials = array_filter($materials, fn($m) => $m['section_id'] === $filterSection);
                    }
                    ?>
                    <div class="admin-header">
                        <h1>Materials</h1>
                        <a href="<?= SITE_URL ?>/?route=admin&action=material-form" class="btn btn-primary">+ Add Material</a>
                    </div>
                    <div class="filter-bar">
                        <select onchange="window.location.href='<?= SITE_URL ?>/?route=admin&action=materials' + (this.value ? '&section_id=' + this.value : '')">
                            <option value="">All Sections</option>
                            <?php foreach ($sectionModel->getAll() as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $filterSection === $s['id'] ? 'selected' : '' ?>><?= sanitize($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (empty($materials)): ?>
                        <div class="empty-state small"><p>No materials yet. Add your first material!</p></div>
                    <?php else: ?>
                        <div class="admin-table-wrapper">
                            <table class="admin-table">
                                <thead>
                                    <tr><th>Title</th><th>Section</th><th>Type</th><th>Created</th><th>Actions</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($materials as $m): ?>
                                        <tr>
                                            <td><strong><?= sanitize($m['title']) ?></strong></td>
                                            <td><?= sanitize($m['section_name']) ?></td>
                                            <td><span class="badge"><?= ucfirst(str_replace('_', ' ', $m['file_type'])) ?></span></td>
                                            <td><?= date('M j, Y', strtotime($m['created_at'])) ?></td>
                                            <td class="actions">
                                                <a href="<?= SITE_URL ?>/?route=material&id=<?= $m['id'] ?>" target="_blank" class="btn-icon" title="View">👁️</a>
                                                <a href="<?= SITE_URL ?>/?route=admin&action=material-form&id=<?= $m['id'] ?>" class="btn-icon" title="Edit">✏️</a>
                                                <a href="<?= SITE_URL ?>/?route=admin&action=material-delete&id=<?= $m['id'] ?>" class="btn-icon delete" title="Delete" onclick="return confirm('Delete this material?')">🗑️</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <?php break;
                case 'material-form':
                    $editMaterial = null;
                    $preselectedSection = isset($_GET['section_id']) ? (int) $_GET['section_id'] : null;
                    if (isset($_GET['id'])) {
                        $editMaterial = $materialModel->getById((int) $_GET['id']);
                    }
                    $sections = $sectionModel->getAll();
                    ?>
                    <div class="admin-header"><h1><?= $editMaterial ? 'Edit Material' : 'Add Material' ?></h1></div>
                    <?php if (empty($sections)): ?>
                        <div class="alert alert-warning">You need to <a href="<?= SITE_URL ?>/?route=admin&action=section-form">create a section</a> first before adding materials.</div>
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
                                        <option value="<?= $s['id'] ?>" <?= ($editMaterial['section_id'] ?? $preselectedSection ?? '') == $s['id'] ? 'selected' : '' ?>><?= sanitize($s['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="title">Title *</label>
                                <input type="text" id="title" name="title" required value="<?= sanitize($editMaterial['title'] ?? $_POST['title'] ?? '') ?>" placeholder="e.g., Introduction Guide">
                            </div>
                            <div class="form-group">
                                <label for="description">Description</label>
                                <textarea id="description" name="description" rows="4" placeholder="Brief description of this material..."><?= sanitize($editMaterial['description'] ?? $_POST['description'] ?? '') ?></textarea>
                            </div>
                            <div class="form-group">
                                <label for="file_type">File Type *</label>
                                <select id="file_type" name="file_type" required onchange="toggleFileFields()">
                                    <option value="">Select type</option>
                                    <option value="gdrive_pdf" <?= ($editMaterial['file_type'] ?? '') === 'gdrive_pdf' ? 'selected' : '' ?>>Google Drive PDF</option>
                                    <option value="gdrive_word" <?= ($editMaterial['file_type'] ?? '') === 'gdrive_word' ? 'selected' : '' ?>>Google Drive Word</option>
                                    <option value="image" <?= ($editMaterial['file_type'] ?? '') === 'image' ? 'selected' : '' ?>>Uploaded Image</option>
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
                                <input type="url" id="file_url" name="file_url" value="<?= sanitize($editMaterial['file_url'] ?? '') ?>" placeholder="https://drive.google.com/file/d/.../view">
                                <small>Paste the share link from Google Drive</small>
                            </div>
                            <div class="form-group" id="youtube-field" style="display: <?= $showYoutube ? 'block' : 'none' ?>;">
                                <label for="youtube_url">YouTube Video URL</label>
                                <input type="url" id="youtube_url" name="file_url" value="<?= sanitize($editMaterial['file_url'] ?? '') ?>" placeholder="https://www.youtube.com/watch?v=... or https://youtu.be/...">
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
                                <button type="submit" class="btn btn-primary"><?= $editMaterial ? 'Update' : 'Create' ?> Material</button>
                                <a href="<?= SITE_URL ?>/?route=admin&action=materials" class="btn btn-outline">Cancel</a>
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
                                gdriveField.style.display = 'none';
                                youtubeField.style.display = 'none';
                                imageField.style.display = 'none';
                                if (type === 'gdrive_pdf' || type === 'gdrive_word') {
                                    gdriveField.style.display = 'block';
                                } else if (type === 'youtube') {
                                    youtubeField.style.display = 'block';
                                } else if (type === 'image') {
                                    imageField.style.display = 'block';
                                }
                            }
                            (function() {
                                if (document.readyState === 'loading') {
                                    document.addEventListener('DOMContentLoaded', function() {
                                        setTimeout(toggleFileFields, 10);
                                    });
                                } else {
                                    setTimeout(toggleFileFields, 10);
                                }
                                window.addEventListener('load', toggleFileFields);
                            })();
                            toggleFileFields();
                        </script>
                    <?php endif; ?>
                    <?php break;
            endswitch;
            ?>
        </main>
    </div>
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
</body>
</html>
    <?php
    exit;
}

if ($route === 'section') {
    $slug = $_GET['slug'] ?? '';
    if (empty($slug)) {
        redirect(SITE_URL);
    }
    
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
    <meta name="description" content="<?= $error ? 'Section not found' : 'Browse materials in ' . sanitize($section['name']) ?>">
    <title><?= $error ? 'Not Found' : sanitize($section['name']) ?> - Courses</title>
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/style.css">
</head>
<body>
    <header class="header">
        <div class="logo">Courses</div>
        <nav class="nav">
            <a href="<?= SITE_URL ?>/?route=home" class="nav-link">Home</a>
            <a href="<?= SITE_URL ?>/?route=admin" class="nav-link">Admin</a>
        </nav>
    </header>
    <main class="main">
        <?php if ($error): ?>
            <div class="error-state">
                <div class="error-icon">🔍</div>
                <h2>Section Not Found</h2>
                <p>The section you're looking for doesn't exist.</p>
                <a href="<?= SITE_URL ?>/?route=home" class="btn btn-primary">Back to Home</a>
            </div>
        <?php else: ?>
            <nav class="breadcrumb">
                <a href="<?= SITE_URL ?>/?route=home">Home</a>
                <span class="separator">/</span>
                <span class="current"><?= sanitize($section['name']) ?></span>
            </nav>
            <h2 class="page-title"><?= sanitize($section['name']) ?></h2>
            <?php if (empty($materials)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📄</div>
                    <h3>No Materials Yet</h3>
                    <p>This section doesn't have any materials yet.</p>
                    <a href="<?= SITE_URL ?>/?route=admin&action=material-form&section_id=<?= $section['id'] ?>" class="btn btn-primary">Add Material</a>
                </div>
            <?php else: ?>
                <div class="materials-grid">
                    <?php foreach ($materials as $m): ?>
                        <a href="<?= SITE_URL ?>/?route=material&id=<?= $m['id'] ?>" class="material-card">
                            <?php if ($m['file_type'] === 'image' && !empty($m['image_path'])): ?>
                                <img src="<?= UPLOAD_URL . sanitize($m['image_path']) ?>" alt="<?= sanitize($m['title']) ?>" class="material-thumb">
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
    <footer class="footer">
        <p>&copy; <?= date('Y') ?> Course Materials. All rights reserved.</p>
    </footer>
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
</body>
</html>
    <?php
    exit;
}

if ($route === 'material') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        redirect(SITE_URL);
    }
    
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
    <header class="header">
        <div class="logo">Courses</div>
        <nav class="nav">
            <a href="<?= SITE_URL ?>/?route=home" class="nav-link">Home</a>
            <a href="<?= SITE_URL ?>/?route=admin" class="nav-link">Admin</a>
        </nav>
    </header>
    <main class="main">
        <?php if ($error): ?>
            <div class="error-state">
                <div class="error-icon">🔍</div>
                <h2>Material Not Found</h2>
                <p>The material you're looking for doesn't exist.</p>
                <a href="<?= SITE_URL ?>/?route=home" class="btn btn-primary">Back to Home</a>
            </div>
        <?php else: ?>
            <nav class="breadcrumb">
                <a href="<?= SITE_URL ?>/?route=home">Home</a>
                <span class="separator">/</span>
                <a href="<?= SITE_URL ?>/?route=section&slug=<?= sanitize($material['section_slug']) ?>"><?= sanitize($material['section_name']) ?></a>
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
                        <span class="material-badge large"><?= ucfirst(str_replace('_', ' ', $material['file_type'])) ?></span>
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
                        <div class="image-preview">
                            <img src="<?= UPLOAD_URL . sanitize($material['image_path']) ?>" alt="<?= sanitize($material['title']) ?>" loading="lazy">
                        </div>
                    <?php elseif (in_array($material['file_type'], ['gdrive_pdf', 'gdrive_word']) && $material['file_url']): ?>
                        <div class="gdrive-preview">
                            <iframe src="<?= getGoogleDriveEmbedUrl($material['file_url']) ?>" width="100%" height="600" frameborder="0" allowfullscreen></iframe>
                            <div class="gdrive-actions">
                                <a href="<?= sanitize($material['file_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary">Open in New Tab ↗</a>
                            </div>
                        </div>
                    <?php elseif ($material['file_type'] === 'youtube' && $material['file_url']): ?>
                        <div class="youtube-preview">
                            <div class="youtube-wrapper">
                                <iframe src="<?= getYouTubeEmbedUrl($material['file_url']) ?>?rel=0" width="100%" height="600" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                            </div>
                            <div class="youtube-actions">
                                <a href="<?= sanitize($material['file_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary">Watch on YouTube ↗</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state"><p>No preview available for this material.</p></div>
                    <?php endif; ?>
                </div>
                <footer class="material-footer">
                    <a href="<?= SITE_URL ?>/?route=section&slug=<?= sanitize($material['section_slug']) ?>" class="btn btn-outline">← Back to <?= sanitize($material['section_name']) ?></a>
                </footer>
            </article>
        <?php endif; ?>
    </main>
    <footer class="footer">
        <p>&copy; <?= date('Y') ?> Course Materials. All rights reserved.</p>
    </footer>
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
</body>
</html>
    <?php
    exit;
}

// Home page
$sections = $sectionModel->getAll();
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
    <header class="header">
        <div class="logo">Courses</div>
        <nav class="nav">
            <a href="<?= SITE_URL ?>/?route=admin" class="nav-link">Admin</a>
        </nav>
    </header>
    <main class="main">
        <h2 class="page-title">All Courses</h2>
        <?php if (empty($sections)): ?>
            <div class="empty-state">
                <div class="empty-icon">📂</div>
                <h3>No Sections Yet</h3>
                <p>Start by creating sections in the admin panel.</p>
                <a href="<?= SITE_URL ?>/?route=admin&action=section-form" class="btn btn-primary">Create Section</a>
            </div>
        <?php else: ?>
            <div class="sections-grid">
                <?php foreach ($sections as $s): ?>
                    <a href="<?= SITE_URL ?>/?route=section&slug=<?= sanitize($s['slug']) ?>" class="section-card">
                        <img src="<?= SITE_URL ?>/assets/images/sections/<?= sanitize($s['slug']) ?>.jpg" alt="<?= sanitize($s['name']) ?>" class="section-image" onerror="this.onerror=null; this.src='<?= SITE_URL ?>/assets/images/sections/default.jpg';">
                        <div class="section-info">
                            <h3 class="section-title"><?= sanitize($s['name']) ?></h3>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
    <footer class="footer">
        <p>&copy; <?= date('Y') ?> Course Materials. All rights reserved.</p>
    </footer>
    <script src="<?= SITE_URL ?>/assets/js/main.js"></script>
</body>
</html>
