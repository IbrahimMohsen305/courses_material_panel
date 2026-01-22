
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

define('SITE_URL', 'http://localhost/onef');
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
    <style>

    /* 
    * Materials Management System - Styles
    * Matching existing platform design - Dark Navy Theme
    */

    /* ============ CSS Variables ============ */
    :root {
        --primary: #3b82f6;
        --primary-dark: #2563eb;
        --primary-light: #60a5fa;

        --bg-body: #0a1628;
        --bg-card: #111d32;
        --bg-card-hover: #1a2a45;
        --bg-header: #0d1829;
        --bg-input: #0a1628;

        --text-primary: #e2e8f0;
        --text-secondary: #94a3b8;
        --text-muted: #64748b;

        --border: #1e3a5f;
        --border-light: #2d4a6f;

        --success: #22c55e;
        --danger: #ef4444;
        --warning: #f59e0b;

        --radius: 12px;
        --radius-sm: 8px;
        --shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        --transition: all 0.3s ease;
    }

    /* ============ Reset & Base ============ */
    *,
    *::before,
    *::after {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    body {
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        background: var(--bg-body);
        color: var(--text-primary);
        line-height: 1.6;
        min-height: 100vh;
    }

    a {
        color: var(--primary-light);
        text-decoration: none;
        transition: var(--transition);
    }

    a:hover {
        color: var(--primary);
    }

    /* ============ Layout ============ */
    .container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0 1.5rem;
    }

    /* ============ Header ============ */
    .header {
        background: var(--bg-header);
        padding: 1rem 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid var(--border);
        position: sticky;
        top: 0;
        z-index: 100;
    }

    .logo {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--text-primary);
    }

    .nav {
        display: flex;
        gap: 0.5rem;
    }

    .nav-link {
        color: var(--text-secondary);
        font-size: 0.875rem;
        font-weight: 500;
        padding: 0.5rem 1rem;
        border-radius: var(--radius-sm);
        background: var(--bg-card);
        border: 1px solid var(--border);
        transition: var(--transition);
    }

    .nav-link:hover,
    .nav-link.active {
        color: var(--text-primary);
        background: var(--bg-card-hover);
        border-color: var(--border-light);
    }

    /* ============ Main Content ============ */
    .main {
        padding: 2rem;
    }

    .page-title {
        font-size: 1.5rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 1.5rem;
    }

    /* ============ Sections Grid (Image Cards) ============ */
    .sections-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 1.25rem;
    }

    .section-card {
        background: var(--bg-card);
        border-radius: var(--radius);
        overflow: hidden;
        transition: var(--transition);
        border: 1px solid transparent;
    }

    .section-card:hover {
        transform: translateY(-4px);
        border-color: var(--primary);
        box-shadow: var(--shadow);
    }

    .section-image {
        width: 100%;
        height: 160px;
        object-fit: cover;
        background: var(--bg-card-hover);
        display: block;
    }

    .section-image-placeholder {
        width: 100%;
        height: 160px;
        background: linear-gradient(135deg, var(--bg-card-hover) 0%, var(--border) 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 3rem;
    }

    .section-info {
        padding: 1rem;
    }

    .section-title {
        font-size: 0.95rem;
        font-weight: 500;
        color: var(--primary-light);
        margin: 0;
    }

    .section-meta {
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-top: 0.25rem;
    }

    /* ============ Materials Grid ============ */
    .materials-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 1.25rem;
    }

    .material-card {
        background: var(--bg-card);
        border-radius: var(--radius);
        overflow: hidden;
        transition: var(--transition);
        border: 1px solid transparent;
        display: flex;
        flex-direction: column;
    }

    .material-card:hover {
        transform: translateY(-4px);
        border-color: var(--primary);
        box-shadow: var(--shadow);
    }

    .material-thumb {
        width: 100%;
        height: 140px;
        object-fit: cover;
        background: var(--bg-card-hover);
    }

    .material-thumb-placeholder {
        width: 100%;
        height: 140px;
        background: linear-gradient(135deg, var(--bg-card-hover) 0%, var(--border) 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2.5rem;
    }

    .material-content {
        padding: 1rem;
        flex: 1;
    }

    .material-title {
        font-size: 1rem;
        font-weight: 500;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }

    .material-desc {
        font-size: 0.875rem;
        color: var(--text-secondary);
        margin-bottom: 0.75rem;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .material-badge {
        display: inline-block;
        background: var(--bg-body);
        color: var(--primary-light);
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 500;
        border: 1px solid var(--border);
    }

    /* ============ Breadcrumb ============ */
    .breadcrumb {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.875rem;
        max-width: 900px;
        margin: 0 auto 1.5rem auto;
    }

    .breadcrumb a {
        color: var(--text-muted);
    }

    .breadcrumb a:hover {
        color: var(--primary-light);
    }

    .breadcrumb .separator {
        color: var(--text-muted);
    }

    .breadcrumb .current {
        color: var(--text-secondary);
    }

    /* ============ Material Detail ============ */
    .material-detail {
        background: var(--bg-card);
        border-radius: var(--radius);
        padding: 2rem;
        max-width: 900px;
        margin: 0 auto;
    }

    .material-header {
        display: flex;
        align-items: flex-start;
        gap: 1.5rem;
        margin-bottom: 2rem;
        padding-bottom: 1.5rem;
        border-bottom: 1px solid var(--border);
    }

    .material-type-large {
        font-size: 3rem;
    }

    .material-info h1 {
        font-size: 1.5rem;
        margin-bottom: 0.5rem;
        color: var(--text-primary);
    }

    .material-badge.large {
        font-size: 0.875rem;
    }

    .material-description {
        margin-bottom: 2rem;
    }

    .material-description h3 {
        font-size: 0.875rem;
        color: var(--text-muted);
        margin-bottom: 0.5rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .material-description p {
        color: var(--text-secondary);
        line-height: 1.8;
    }

    .material-preview {
        margin-bottom: 2rem;
    }

    .image-preview img {
        max-width: 100%;
        height: auto;
        border-radius: var(--radius);
    }

    .gdrive-preview iframe {
        width: 100%;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: #fff;
    }

    .gdrive-actions {
        margin-top: 1rem;
    }

    .material-footer {
        padding-top: 1.5rem;
        border-top: 1px solid var(--border);
    }

    /* ============ Empty & Error States ============ */
    .empty-state,
    .error-state {
        text-align: center;
        padding: 4rem 2rem;
        background: var(--bg-card);
        border-radius: var(--radius);
    }

    .empty-icon,
    .error-icon {
        font-size: 4rem;
        margin-bottom: 1rem;
        opacity: 0.5;
    }

    .empty-state h3,
    .error-state h2 {
        font-size: 1.25rem;
        margin-bottom: 0.5rem;
        color: var(--text-primary);
    }

    .empty-state p,
    .error-state p {
        color: var(--text-secondary);
        margin-bottom: 1.5rem;
    }

    /* ============ Buttons ============ */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.625rem 1.25rem;
        border: none;
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        transition: var(--transition);
        text-decoration: none;
    }

    .btn-primary {
        background: var(--primary);
        color: white;
    }

    .btn-primary:hover {
        background: var(--primary-dark);
        color: white;
    }

    .btn-secondary {
        background: var(--bg-card);
        color: var(--text-primary);
        border: 1px solid var(--border);
    }

    .btn-secondary:hover {
        background: var(--bg-card-hover);
        color: var(--text-primary);
    }

    .btn-outline {
        background: transparent;
        border: 1px solid var(--border);
        color: var(--text-primary);
    }

    .btn-outline:hover {
        background: var(--bg-card);
        color: var(--text-primary);
    }

    /* ============ Footer ============ */
    .footer {
        text-align: center;
        padding: 2rem;
        color: var(--text-muted);
        font-size: 0.875rem;
        border-top: 1px solid var(--border);
        margin-top: 2rem;
    }

    /* ============ Admin Styles ============ */
    .admin-body {
        background: var(--bg-body);
    }

    .admin-container {
        display: flex;
        min-height: 100vh;
    }

    .admin-sidebar {
        width: 220px;
        background: var(--bg-header);
        border-right: 1px solid var(--border);
        padding: 1.5rem 1rem;
        position: fixed;
        height: 100vh;
        overflow-y: auto;
    }

    .admin-logo {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 2rem;
        padding: 0 0.5rem;
    }

    .admin-nav {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .admin-link {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem 1rem;
        color: var(--text-secondary);
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        transition: var(--transition);
    }

    .admin-link:hover,
    .admin-link.active {
        background: var(--bg-card);
        color: var(--text-primary);
    }

    .admin-divider {
        border: none;
        border-top: 1px solid var(--border);
        margin: 1rem 0;
    }

    .admin-main {
        flex: 1;
        margin-left: 220px;
        padding: 2rem;
    }

    .admin-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }

    .admin-header h1 {
        font-size: 1.5rem;
        font-weight: 600;
    }

    /* ============ Stats Grid ============ */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }

    .stat-card {
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 1.5rem;
        text-align: center;
    }

    .stat-icon {
        font-size: 1.5rem;
        margin-bottom: 0.5rem;
    }

    .stat-number {
        font-size: 2rem;
        font-weight: 700;
        color: var(--primary-light);
    }

    .stat-label {
        color: var(--text-muted);
        font-size: 0.875rem;
    }

    /* ============ Quick Actions ============ */
    .quick-actions h2 {
        font-size: 1rem;
        font-weight: 600;
        margin-bottom: 1rem;
        color: var(--text-secondary);
    }

    .actions-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
    }

    .action-card {
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 1.25rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.5rem;
        color: var(--text-primary);
        font-size: 0.875rem;
        transition: var(--transition);
    }

    .action-card:hover {
        border-color: var(--primary);
        background: var(--bg-card-hover);
        color: var(--text-primary);
    }

    .action-icon {
        font-size: 1.25rem;
    }

    /* ============ Admin Table ============ */
    .admin-table-wrapper {
        overflow-x: auto;
        background: var(--bg-card);
        border-radius: var(--radius);
        border: 1px solid var(--border);
    }

    .admin-table {
        width: 100%;
        border-collapse: collapse;
    }

    .admin-table th,
    .admin-table td {
        padding: 1rem;
        text-align: left;
        border-bottom: 1px solid var(--border);
    }

    .admin-table th {
        background: var(--bg-header);
        color: var(--text-muted);
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .admin-table tr:last-child td {
        border-bottom: none;
    }

    .admin-table tr:hover td {
        background: var(--bg-card-hover);
    }

    .admin-table code {
        background: var(--bg-body);
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        font-size: 0.875rem;
        color: var(--primary-light);
    }

    .admin-table .actions {
        white-space: nowrap;
    }

    .btn-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 6px;
        transition: var(--transition);
    }

    .btn-icon:hover {
        background: var(--bg-body);
    }

    .btn-icon.delete:hover {
        background: rgba(239, 68, 68, 0.2);
    }

    .badge {
        display: inline-block;
        background: var(--bg-body);
        color: var(--primary-light);
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 500;
    }

    /* ============ Filter Bar ============ */
    .filter-bar {
        margin-bottom: 1rem;
    }

    .filter-bar select {
        background: var(--bg-card);
        border: 1px solid var(--border);
        color: var(--text-primary);
        padding: 0.5rem 1rem;
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        cursor: pointer;
    }

    .filter-bar select:focus {
        outline: none;
        border-color: var(--primary);
    }

    /* ============ Forms ============ */
    .admin-form {
        max-width: 600px;
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 2rem;
    }

    .form-group {
        margin-bottom: 1.5rem;
    }

    .form-group label {
        display: block;
        margin-bottom: 0.5rem;
        color: var(--text-primary);
        font-weight: 500;
        font-size: 0.875rem;
    }

    .form-group input[type="text"],
    .form-group input[type="url"],
    .form-group input[type="file"],
    .form-group select,
    .form-group textarea {
        width: 100%;
        background: var(--bg-input);
        border: 1px solid var(--border);
        color: var(--text-primary);
        padding: 0.75rem 1rem;
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        transition: var(--transition);
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
    }

    .form-group input::placeholder,
    .form-group textarea::placeholder {
        color: var(--text-muted);
    }

    .form-group small {
        display: block;
        margin-top: 0.5rem;
        color: var(--text-muted);
        font-size: 0.8rem;
    }

    .form-group textarea {
        resize: vertical;
        min-height: 100px;
    }

    .current-image {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1rem;
        padding: 1rem;
        background: var(--bg-body);
        border-radius: var(--radius-sm);
    }

    .current-image img {
        border-radius: 4px;
    }

    .current-image span {
        color: var(--text-secondary);
        font-size: 0.875rem;
    }

    .form-actions {
        display: flex;
        gap: 1rem;
        padding-top: 1rem;
        border-top: 1px solid var(--border);
    }

    /* ============ Alerts ============ */
    .alert {
        padding: 1rem 1.25rem;
        border-radius: var(--radius-sm);
        margin-bottom: 1.5rem;
        font-size: 0.875rem;
        font-weight: 500;
    }

    .alert-success {
        background: rgba(34, 197, 94, 0.1);
        border: 1px solid var(--success);
        color: var(--success);
    }

    .alert-error {
        background: rgba(239, 68, 68, 0.1);
        border: 1px solid var(--danger);
        color: var(--danger);
    }

    .alert-warning {
        background: rgba(245, 158, 11, 0.1);
        border: 1px solid var(--warning);
        color: var(--warning);
    }

    .alert-warning a {
        color: var(--warning);
        text-decoration: underline;
    }

    /* ============ Responsive ============ */
    @media (max-width: 768px) {
        .header {
            padding: 1rem;
        }

        .nav {
            gap: 0.25rem;
        }

        .nav-link {
            padding: 0.375rem 0.75rem;
            font-size: 0.8rem;
        }

        .main {
            padding: 1rem;
        }

        .sections-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .section-image,
        .section-image-placeholder {
            height: 120px;
        }

        .material-header {
            flex-direction: column;
            text-align: center;
        }

        /* Admin responsive */
        .admin-sidebar {
            position: static;
            width: 100%;
            height: auto;
            border-right: none;
            border-bottom: 1px solid var(--border);
        }

        .admin-nav {
            flex-direction: row;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .admin-main {
            margin-left: 0;
            padding: 1rem;
        }

        .admin-container {
            flex-direction: column;
        }

        .admin-header {
            flex-direction: column;
            gap: 1rem;
            align-items: flex-start;
        }

        .form-actions {
            flex-direction: column;
        }
    }

    @media (max-width: 480px) {
        .sections-grid {
            grid-template-columns: 1fr;
        }
    }

</style>
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
    <script>/**
 * Materials Management System - JavaScript
 * Minimal enhancements for better UX
 */

document.addEventListener('DOMContentLoaded', function() {
    // Auto-generate slug from section name
    const nameInput = document.getElementById('name');
    const slugInput = document.getElementById('slug');
    
    if (nameInput && slugInput) {
        nameInput.addEventListener('input', function() {
            // Only auto-generate if slug is empty or was auto-generated
            if (!slugInput.dataset.manual) {
                slugInput.value = generateSlug(this.value);
            }
        });
        
        slugInput.addEventListener('input', function() {
            // Mark as manually edited
            this.dataset.manual = this.value !== generateSlug(nameInput.value);
        });
    }
    
    // Confirm delete actions
    document.querySelectorAll('.delete').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to delete this item?')) {
                e.preventDefault();
            }
        });
    });
    
    // Auto-hide alerts after 5 seconds
    document.querySelectorAll('.alert').forEach(function(alert) {
        setTimeout(function() {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(function() {
                alert.remove();
            }, 300);
        }, 5000);
    });
    
    // Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });
});

/**
 * Generate SEO-friendly slug from text
 */
function generateSlug(text) {
    return text
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/[\s-]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

/**
 * Toggle file fields based on file type selection
 * Called from admin.php inline script
 */
// function toggleFileFields() {
//     const type = document.getElementById('file_type');
//     const gdriveField = document.getElementById('gdrive-field');
//     const imageField = document.getElementById('image-field');
    
//     if (!type || !gdriveField || !imageField) return;
    
//     const value = type.value;
//     gdriveField.style.display = (value === 'gdrive_pdf' || value === 'gdrive_word') ? 'block' : 'none';
//     imageField.style.display = value === 'image' ? 'block' : 'none';
// }

/**
 * Preview uploaded image before submit
 */
function previewImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            let preview = input.parentElement.querySelector('.preview-img');
            if (!preview) {
                preview = document.createElement('img');
                preview.className = 'preview-img';
                preview.style.cssText = 'max-width: 200px; margin-top: 1rem; border-radius: 8px;';
                input.parentElement.appendChild(preview);
            }
            preview.src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Add image preview listener if image input exists
document.addEventListener('DOMContentLoaded', function() {
    const imageInput = document.getElementById('image');
    if (imageInput) {
        imageInput.addEventListener('change', function() {
            previewImage(this);
        });
    }
});
</script>
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
    <style>

    /* 
    * Materials Management System - Styles
    * Matching existing platform design - Dark Navy Theme
    */

    /* ============ CSS Variables ============ */
    :root {
        --primary: #3b82f6;
        --primary-dark: #2563eb;
        --primary-light: #60a5fa;

        --bg-body: #0a1628;
        --bg-card: #111d32;
        --bg-card-hover: #1a2a45;
        --bg-header: #0d1829;
        --bg-input: #0a1628;

        --text-primary: #e2e8f0;
        --text-secondary: #94a3b8;
        --text-muted: #64748b;

        --border: #1e3a5f;
        --border-light: #2d4a6f;

        --success: #22c55e;
        --danger: #ef4444;
        --warning: #f59e0b;

        --radius: 12px;
        --radius-sm: 8px;
        --shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        --transition: all 0.3s ease;
    }

    /* ============ Reset & Base ============ */
    *,
    *::before,
    *::after {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    body {
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        background: var(--bg-body);
        color: var(--text-primary);
        line-height: 1.6;
        min-height: 100vh;
    }

    a {
        color: var(--primary-light);
        text-decoration: none;
        transition: var(--transition);
    }

    a:hover {
        color: var(--primary);
    }

    /* ============ Layout ============ */
    .container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0 1.5rem;
    }

    /* ============ Header ============ */
    .header {
        background: var(--bg-header);
        padding: 1rem 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid var(--border);
        position: sticky;
        top: 0;
        z-index: 100;
    }

    .logo {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--text-primary);
    }

    .nav {
        display: flex;
        gap: 0.5rem;
    }

    .nav-link {
        color: var(--text-secondary);
        font-size: 0.875rem;
        font-weight: 500;
        padding: 0.5rem 1rem;
        border-radius: var(--radius-sm);
        background: var(--bg-card);
        border: 1px solid var(--border);
        transition: var(--transition);
    }

    .nav-link:hover,
    .nav-link.active {
        color: var(--text-primary);
        background: var(--bg-card-hover);
        border-color: var(--border-light);
    }

    /* ============ Main Content ============ */
    .main {
        padding: 2rem;
    }

    .page-title {
        font-size: 1.5rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 1.5rem;
    }

    /* ============ Sections Grid (Image Cards) ============ */
    .sections-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 1.25rem;
    }

    .section-card {
        background: var(--bg-card);
        border-radius: var(--radius);
        overflow: hidden;
        transition: var(--transition);
        border: 1px solid transparent;
    }

    .section-card:hover {
        transform: translateY(-4px);
        border-color: var(--primary);
        box-shadow: var(--shadow);
    }

    .section-image {
        width: 100%;
        height: 160px;
        object-fit: cover;
        background: var(--bg-card-hover);
        display: block;
    }

    .section-image-placeholder {
        width: 100%;
        height: 160px;
        background: linear-gradient(135deg, var(--bg-card-hover) 0%, var(--border) 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 3rem;
    }

    .section-info {
        padding: 1rem;
    }

    .section-title {
        font-size: 0.95rem;
        font-weight: 500;
        color: var(--primary-light);
        margin: 0;
    }

    .section-meta {
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-top: 0.25rem;
    }

    /* ============ Materials Grid ============ */
    .materials-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 1.25rem;
    }

    .material-card {
        background: var(--bg-card);
        border-radius: var(--radius);
        overflow: hidden;
        transition: var(--transition);
        border: 1px solid transparent;
        display: flex;
        flex-direction: column;
    }

    .material-card:hover {
        transform: translateY(-4px);
        border-color: var(--primary);
        box-shadow: var(--shadow);
    }

    .material-thumb {
        width: 100%;
        height: 140px;
        object-fit: cover;
        background: var(--bg-card-hover);
    }

    .material-thumb-placeholder {
        width: 100%;
        height: 140px;
        background: linear-gradient(135deg, var(--bg-card-hover) 0%, var(--border) 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2.5rem;
    }

    .material-content {
        padding: 1rem;
        flex: 1;
    }

    .material-title {
        font-size: 1rem;
        font-weight: 500;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }

    .material-desc {
        font-size: 0.875rem;
        color: var(--text-secondary);
        margin-bottom: 0.75rem;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .material-badge {
        display: inline-block;
        background: var(--bg-body);
        color: var(--primary-light);
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 500;
        border: 1px solid var(--border);
    }

    /* ============ Breadcrumb ============ */
    .breadcrumb {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.875rem;
        max-width: 900px;
        margin: 0 auto 1.5rem auto;
    }

    .breadcrumb a {
        color: var(--text-muted);
    }

    .breadcrumb a:hover {
        color: var(--primary-light);
    }

    .breadcrumb .separator {
        color: var(--text-muted);
    }

    .breadcrumb .current {
        color: var(--text-secondary);
    }

    /* ============ Material Detail ============ */
    .material-detail {
        background: var(--bg-card);
        border-radius: var(--radius);
        padding: 2rem;
        max-width: 900px;
        margin: 0 auto;
    }

    .material-header {
        display: flex;
        align-items: flex-start;
        gap: 1.5rem;
        margin-bottom: 2rem;
        padding-bottom: 1.5rem;
        border-bottom: 1px solid var(--border);
    }

    .material-type-large {
        font-size: 3rem;
    }

    .material-info h1 {
        font-size: 1.5rem;
        margin-bottom: 0.5rem;
        color: var(--text-primary);
    }

    .material-badge.large {
        font-size: 0.875rem;
    }

    .material-description {
        margin-bottom: 2rem;
    }

    .material-description h3 {
        font-size: 0.875rem;
        color: var(--text-muted);
        margin-bottom: 0.5rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .material-description p {
        color: var(--text-secondary);
        line-height: 1.8;
    }

    .material-preview {
        margin-bottom: 2rem;
    }

    .image-preview img {
        max-width: 100%;
        height: auto;
        border-radius: var(--radius);
    }

    .gdrive-preview iframe {
        width: 100%;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: #fff;
    }

    .gdrive-actions {
        margin-top: 1rem;
    }

    .material-footer {
        padding-top: 1.5rem;
        border-top: 1px solid var(--border);
    }

    /* ============ Empty & Error States ============ */
    .empty-state,
    .error-state {
        text-align: center;
        padding: 4rem 2rem;
        background: var(--bg-card);
        border-radius: var(--radius);
    }

    .empty-icon,
    .error-icon {
        font-size: 4rem;
        margin-bottom: 1rem;
        opacity: 0.5;
    }

    .empty-state h3,
    .error-state h2 {
        font-size: 1.25rem;
        margin-bottom: 0.5rem;
        color: var(--text-primary);
    }

    .empty-state p,
    .error-state p {
        color: var(--text-secondary);
        margin-bottom: 1.5rem;
    }

    /* ============ Buttons ============ */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.625rem 1.25rem;
        border: none;
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        transition: var(--transition);
        text-decoration: none;
    }

    .btn-primary {
        background: var(--primary);
        color: white;
    }

    .btn-primary:hover {
        background: var(--primary-dark);
        color: white;
    }

    .btn-secondary {
        background: var(--bg-card);
        color: var(--text-primary);
        border: 1px solid var(--border);
    }

    .btn-secondary:hover {
        background: var(--bg-card-hover);
        color: var(--text-primary);
    }

    .btn-outline {
        background: transparent;
        border: 1px solid var(--border);
        color: var(--text-primary);
    }

    .btn-outline:hover {
        background: var(--bg-card);
        color: var(--text-primary);
    }

    /* ============ Footer ============ */
    .footer {
        text-align: center;
        padding: 2rem;
        color: var(--text-muted);
        font-size: 0.875rem;
        border-top: 1px solid var(--border);
        margin-top: 2rem;
    }

    /* ============ Admin Styles ============ */
    .admin-body {
        background: var(--bg-body);
    }

    .admin-container {
        display: flex;
        min-height: 100vh;
    }

    .admin-sidebar {
        width: 220px;
        background: var(--bg-header);
        border-right: 1px solid var(--border);
        padding: 1.5rem 1rem;
        position: fixed;
        height: 100vh;
        overflow-y: auto;
    }

    .admin-logo {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 2rem;
        padding: 0 0.5rem;
    }

    .admin-nav {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .admin-link {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem 1rem;
        color: var(--text-secondary);
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        transition: var(--transition);
    }

    .admin-link:hover,
    .admin-link.active {
        background: var(--bg-card);
        color: var(--text-primary);
    }

    .admin-divider {
        border: none;
        border-top: 1px solid var(--border);
        margin: 1rem 0;
    }

    .admin-main {
        flex: 1;
        margin-left: 220px;
        padding: 2rem;
    }

    .admin-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }

    .admin-header h1 {
        font-size: 1.5rem;
        font-weight: 600;
    }

    /* ============ Stats Grid ============ */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }

    .stat-card {
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 1.5rem;
        text-align: center;
    }

    .stat-icon {
        font-size: 1.5rem;
        margin-bottom: 0.5rem;
    }

    .stat-number {
        font-size: 2rem;
        font-weight: 700;
        color: var(--primary-light);
    }

    .stat-label {
        color: var(--text-muted);
        font-size: 0.875rem;
    }

    /* ============ Quick Actions ============ */
    .quick-actions h2 {
        font-size: 1rem;
        font-weight: 600;
        margin-bottom: 1rem;
        color: var(--text-secondary);
    }

    .actions-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
    }

    .action-card {
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 1.25rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.5rem;
        color: var(--text-primary);
        font-size: 0.875rem;
        transition: var(--transition);
    }

    .action-card:hover {
        border-color: var(--primary);
        background: var(--bg-card-hover);
        color: var(--text-primary);
    }

    .action-icon {
        font-size: 1.25rem;
    }

    /* ============ Admin Table ============ */
    .admin-table-wrapper {
        overflow-x: auto;
        background: var(--bg-card);
        border-radius: var(--radius);
        border: 1px solid var(--border);
    }

    .admin-table {
        width: 100%;
        border-collapse: collapse;
    }

    .admin-table th,
    .admin-table td {
        padding: 1rem;
        text-align: left;
        border-bottom: 1px solid var(--border);
    }

    .admin-table th {
        background: var(--bg-header);
        color: var(--text-muted);
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .admin-table tr:last-child td {
        border-bottom: none;
    }

    .admin-table tr:hover td {
        background: var(--bg-card-hover);
    }

    .admin-table code {
        background: var(--bg-body);
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        font-size: 0.875rem;
        color: var(--primary-light);
    }

    .admin-table .actions {
        white-space: nowrap;
    }

    .btn-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 6px;
        transition: var(--transition);
    }

    .btn-icon:hover {
        background: var(--bg-body);
    }

    .btn-icon.delete:hover {
        background: rgba(239, 68, 68, 0.2);
    }

    .badge {
        display: inline-block;
        background: var(--bg-body);
        color: var(--primary-light);
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 500;
    }

    /* ============ Filter Bar ============ */
    .filter-bar {
        margin-bottom: 1rem;
    }

    .filter-bar select {
        background: var(--bg-card);
        border: 1px solid var(--border);
        color: var(--text-primary);
        padding: 0.5rem 1rem;
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        cursor: pointer;
    }

    .filter-bar select:focus {
        outline: none;
        border-color: var(--primary);
    }

    /* ============ Forms ============ */
    .admin-form {
        max-width: 600px;
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 2rem;
    }

    .form-group {
        margin-bottom: 1.5rem;
    }

    .form-group label {
        display: block;
        margin-bottom: 0.5rem;
        color: var(--text-primary);
        font-weight: 500;
        font-size: 0.875rem;
    }

    .form-group input[type="text"],
    .form-group input[type="url"],
    .form-group input[type="file"],
    .form-group select,
    .form-group textarea {
        width: 100%;
        background: var(--bg-input);
        border: 1px solid var(--border);
        color: var(--text-primary);
        padding: 0.75rem 1rem;
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        transition: var(--transition);
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
    }

    .form-group input::placeholder,
    .form-group textarea::placeholder {
        color: var(--text-muted);
    }

    .form-group small {
        display: block;
        margin-top: 0.5rem;
        color: var(--text-muted);
        font-size: 0.8rem;
    }

    .form-group textarea {
        resize: vertical;
        min-height: 100px;
    }

    .current-image {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1rem;
        padding: 1rem;
        background: var(--bg-body);
        border-radius: var(--radius-sm);
    }

    .current-image img {
        border-radius: 4px;
    }

    .current-image span {
        color: var(--text-secondary);
        font-size: 0.875rem;
    }

    .form-actions {
        display: flex;
        gap: 1rem;
        padding-top: 1rem;
        border-top: 1px solid var(--border);
    }

    /* ============ Alerts ============ */
    .alert {
        padding: 1rem 1.25rem;
        border-radius: var(--radius-sm);
        margin-bottom: 1.5rem;
        font-size: 0.875rem;
        font-weight: 500;
    }

    .alert-success {
        background: rgba(34, 197, 94, 0.1);
        border: 1px solid var(--success);
        color: var(--success);
    }

    .alert-error {
        background: rgba(239, 68, 68, 0.1);
        border: 1px solid var(--danger);
        color: var(--danger);
    }

    .alert-warning {
        background: rgba(245, 158, 11, 0.1);
        border: 1px solid var(--warning);
        color: var(--warning);
    }

    .alert-warning a {
        color: var(--warning);
        text-decoration: underline;
    }

    /* ============ Responsive ============ */
    @media (max-width: 768px) {
        .header {
            padding: 1rem;
        }

        .nav {
            gap: 0.25rem;
        }

        .nav-link {
            padding: 0.375rem 0.75rem;
            font-size: 0.8rem;
        }

        .main {
            padding: 1rem;
        }

        .sections-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .section-image,
        .section-image-placeholder {
            height: 120px;
        }

        .material-header {
            flex-direction: column;
            text-align: center;
        }

        /* Admin responsive */
        .admin-sidebar {
            position: static;
            width: 100%;
            height: auto;
            border-right: none;
            border-bottom: 1px solid var(--border);
        }

        .admin-nav {
            flex-direction: row;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .admin-main {
            margin-left: 0;
            padding: 1rem;
        }

        .admin-container {
            flex-direction: column;
        }

        .admin-header {
            flex-direction: column;
            gap: 1rem;
            align-items: flex-start;
        }

        .form-actions {
            flex-direction: column;
        }
    }

    @media (max-width: 480px) {
        .sections-grid {
            grid-template-columns: 1fr;
        }
    }

</style>
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
    <script>/**
 * Materials Management System - JavaScript
 * Minimal enhancements for better UX
 */

document.addEventListener('DOMContentLoaded', function() {
    // Auto-generate slug from section name
    const nameInput = document.getElementById('name');
    const slugInput = document.getElementById('slug');
    
    if (nameInput && slugInput) {
        nameInput.addEventListener('input', function() {
            // Only auto-generate if slug is empty or was auto-generated
            if (!slugInput.dataset.manual) {
                slugInput.value = generateSlug(this.value);
            }
        });
        
        slugInput.addEventListener('input', function() {
            // Mark as manually edited
            this.dataset.manual = this.value !== generateSlug(nameInput.value);
        });
    }
    
    // Confirm delete actions
    document.querySelectorAll('.delete').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to delete this item?')) {
                e.preventDefault();
            }
        });
    });
    
    // Auto-hide alerts after 5 seconds
    document.querySelectorAll('.alert').forEach(function(alert) {
        setTimeout(function() {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(function() {
                alert.remove();
            }, 300);
        }, 5000);
    });
    
    // Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });
});

/**
 * Generate SEO-friendly slug from text
 */
function generateSlug(text) {
    return text
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/[\s-]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

/**
 * Toggle file fields based on file type selection
 * Called from admin.php inline script
 */
// function toggleFileFields() {
//     const type = document.getElementById('file_type');
//     const gdriveField = document.getElementById('gdrive-field');
//     const imageField = document.getElementById('image-field');
    
//     if (!type || !gdriveField || !imageField) return;
    
//     const value = type.value;
//     gdriveField.style.display = (value === 'gdrive_pdf' || value === 'gdrive_word') ? 'block' : 'none';
//     imageField.style.display = value === 'image' ? 'block' : 'none';
// }

/**
 * Preview uploaded image before submit
 */
function previewImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            let preview = input.parentElement.querySelector('.preview-img');
            if (!preview) {
                preview = document.createElement('img');
                preview.className = 'preview-img';
                preview.style.cssText = 'max-width: 200px; margin-top: 1rem; border-radius: 8px;';
                input.parentElement.appendChild(preview);
            }
            preview.src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Add image preview listener if image input exists
document.addEventListener('DOMContentLoaded', function() {
    const imageInput = document.getElementById('image');
    if (imageInput) {
        imageInput.addEventListener('change', function() {
            previewImage(this);
        });
    }
});
</script>
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
    <style>

    /* 
    * Materials Management System - Styles
    * Matching existing platform design - Dark Navy Theme
    */

    /* ============ CSS Variables ============ */
    :root {
        --primary: #3b82f6;
        --primary-dark: #2563eb;
        --primary-light: #60a5fa;

        --bg-body: #0a1628;
        --bg-card: #111d32;
        --bg-card-hover: #1a2a45;
        --bg-header: #0d1829;
        --bg-input: #0a1628;

        --text-primary: #e2e8f0;
        --text-secondary: #94a3b8;
        --text-muted: #64748b;

        --border: #1e3a5f;
        --border-light: #2d4a6f;

        --success: #22c55e;
        --danger: #ef4444;
        --warning: #f59e0b;

        --radius: 12px;
        --radius-sm: 8px;
        --shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        --transition: all 0.3s ease;
    }

    /* ============ Reset & Base ============ */
    *,
    *::before,
    *::after {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    body {
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        background: var(--bg-body);
        color: var(--text-primary);
        line-height: 1.6;
        min-height: 100vh;
    }

    a {
        color: var(--primary-light);
        text-decoration: none;
        transition: var(--transition);
    }

    a:hover {
        color: var(--primary);
    }

    /* ============ Layout ============ */
    .container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0 1.5rem;
    }

    /* ============ Header ============ */
    .header {
        background: var(--bg-header);
        padding: 1rem 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid var(--border);
        position: sticky;
        top: 0;
        z-index: 100;
    }

    .logo {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--text-primary);
    }

    .nav {
        display: flex;
        gap: 0.5rem;
    }

    .nav-link {
        color: var(--text-secondary);
        font-size: 0.875rem;
        font-weight: 500;
        padding: 0.5rem 1rem;
        border-radius: var(--radius-sm);
        background: var(--bg-card);
        border: 1px solid var(--border);
        transition: var(--transition);
    }

    .nav-link:hover,
    .nav-link.active {
        color: var(--text-primary);
        background: var(--bg-card-hover);
        border-color: var(--border-light);
    }

    /* ============ Main Content ============ */
    .main {
        padding: 2rem;
    }

    .page-title {
        font-size: 1.5rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 1.5rem;
    }

    /* ============ Sections Grid (Image Cards) ============ */
    .sections-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 1.25rem;
    }

    .section-card {
        background: var(--bg-card);
        border-radius: var(--radius);
        overflow: hidden;
        transition: var(--transition);
        border: 1px solid transparent;
    }

    .section-card:hover {
        transform: translateY(-4px);
        border-color: var(--primary);
        box-shadow: var(--shadow);
    }

    .section-image {
        width: 100%;
        height: 160px;
        object-fit: cover;
        background: var(--bg-card-hover);
        display: block;
    }

    .section-image-placeholder {
        width: 100%;
        height: 160px;
        background: linear-gradient(135deg, var(--bg-card-hover) 0%, var(--border) 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 3rem;
    }

    .section-info {
        padding: 1rem;
    }

    .section-title {
        font-size: 0.95rem;
        font-weight: 500;
        color: var(--primary-light);
        margin: 0;
    }

    .section-meta {
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-top: 0.25rem;
    }

    /* ============ Materials Grid ============ */
    .materials-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 1.25rem;
    }

    .material-card {
        background: var(--bg-card);
        border-radius: var(--radius);
        overflow: hidden;
        transition: var(--transition);
        border: 1px solid transparent;
        display: flex;
        flex-direction: column;
    }

    .material-card:hover {
        transform: translateY(-4px);
        border-color: var(--primary);
        box-shadow: var(--shadow);
    }

    .material-thumb {
        width: 100%;
        height: 140px;
        object-fit: cover;
        background: var(--bg-card-hover);
    }

    .material-thumb-placeholder {
        width: 100%;
        height: 140px;
        background: linear-gradient(135deg, var(--bg-card-hover) 0%, var(--border) 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2.5rem;
    }

    .material-content {
        padding: 1rem;
        flex: 1;
    }

    .material-title {
        font-size: 1rem;
        font-weight: 500;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }

    .material-desc {
        font-size: 0.875rem;
        color: var(--text-secondary);
        margin-bottom: 0.75rem;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .material-badge {
        display: inline-block;
        background: var(--bg-body);
        color: var(--primary-light);
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 500;
        border: 1px solid var(--border);
    }

    /* ============ Breadcrumb ============ */
    .breadcrumb {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.875rem;
        max-width: 900px;
        margin: 0 auto 1.5rem auto;
    }

    .breadcrumb a {
        color: var(--text-muted);
    }

    .breadcrumb a:hover {
        color: var(--primary-light);
    }

    .breadcrumb .separator {
        color: var(--text-muted);
    }

    .breadcrumb .current {
        color: var(--text-secondary);
    }

    /* ============ Material Detail ============ */
    .material-detail {
        background: var(--bg-card);
        border-radius: var(--radius);
        padding: 2rem;
        max-width: 900px;
        margin: 0 auto;
    }

    .material-header {
        display: flex;
        align-items: flex-start;
        gap: 1.5rem;
        margin-bottom: 2rem;
        padding-bottom: 1.5rem;
        border-bottom: 1px solid var(--border);
    }

    .material-type-large {
        font-size: 3rem;
    }

    .material-info h1 {
        font-size: 1.5rem;
        margin-bottom: 0.5rem;
        color: var(--text-primary);
    }

    .material-badge.large {
        font-size: 0.875rem;
    }

    .material-description {
        margin-bottom: 2rem;
    }

    .material-description h3 {
        font-size: 0.875rem;
        color: var(--text-muted);
        margin-bottom: 0.5rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .material-description p {
        color: var(--text-secondary);
        line-height: 1.8;
    }

    .material-preview {
        margin-bottom: 2rem;
    }

    .image-preview img {
        max-width: 100%;
        height: auto;
        border-radius: var(--radius);
    }

    .gdrive-preview iframe {
        width: 100%;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: #fff;
    }

    .gdrive-actions {
        margin-top: 1rem;
    }

    .material-footer {
        padding-top: 1.5rem;
        border-top: 1px solid var(--border);
    }

    /* ============ Empty & Error States ============ */
    .empty-state,
    .error-state {
        text-align: center;
        padding: 4rem 2rem;
        background: var(--bg-card);
        border-radius: var(--radius);
    }

    .empty-icon,
    .error-icon {
        font-size: 4rem;
        margin-bottom: 1rem;
        opacity: 0.5;
    }

    .empty-state h3,
    .error-state h2 {
        font-size: 1.25rem;
        margin-bottom: 0.5rem;
        color: var(--text-primary);
    }

    .empty-state p,
    .error-state p {
        color: var(--text-secondary);
        margin-bottom: 1.5rem;
    }

    /* ============ Buttons ============ */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.625rem 1.25rem;
        border: none;
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        transition: var(--transition);
        text-decoration: none;
    }

    .btn-primary {
        background: var(--primary);
        color: white;
    }

    .btn-primary:hover {
        background: var(--primary-dark);
        color: white;
    }

    .btn-secondary {
        background: var(--bg-card);
        color: var(--text-primary);
        border: 1px solid var(--border);
    }

    .btn-secondary:hover {
        background: var(--bg-card-hover);
        color: var(--text-primary);
    }

    .btn-outline {
        background: transparent;
        border: 1px solid var(--border);
        color: var(--text-primary);
    }

    .btn-outline:hover {
        background: var(--bg-card);
        color: var(--text-primary);
    }

    /* ============ Footer ============ */
    .footer {
        text-align: center;
        padding: 2rem;
        color: var(--text-muted);
        font-size: 0.875rem;
        border-top: 1px solid var(--border);
        margin-top: 2rem;
    }

    /* ============ Admin Styles ============ */
    .admin-body {
        background: var(--bg-body);
    }

    .admin-container {
        display: flex;
        min-height: 100vh;
    }

    .admin-sidebar {
        width: 220px;
        background: var(--bg-header);
        border-right: 1px solid var(--border);
        padding: 1.5rem 1rem;
        position: fixed;
        height: 100vh;
        overflow-y: auto;
    }

    .admin-logo {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 2rem;
        padding: 0 0.5rem;
    }

    .admin-nav {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .admin-link {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem 1rem;
        color: var(--text-secondary);
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        transition: var(--transition);
    }

    .admin-link:hover,
    .admin-link.active {
        background: var(--bg-card);
        color: var(--text-primary);
    }

    .admin-divider {
        border: none;
        border-top: 1px solid var(--border);
        margin: 1rem 0;
    }

    .admin-main {
        flex: 1;
        margin-left: 220px;
        padding: 2rem;
    }

    .admin-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }

    .admin-header h1 {
        font-size: 1.5rem;
        font-weight: 600;
    }

    /* ============ Stats Grid ============ */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }

    .stat-card {
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 1.5rem;
        text-align: center;
    }

    .stat-icon {
        font-size: 1.5rem;
        margin-bottom: 0.5rem;
    }

    .stat-number {
        font-size: 2rem;
        font-weight: 700;
        color: var(--primary-light);
    }

    .stat-label {
        color: var(--text-muted);
        font-size: 0.875rem;
    }

    /* ============ Quick Actions ============ */
    .quick-actions h2 {
        font-size: 1rem;
        font-weight: 600;
        margin-bottom: 1rem;
        color: var(--text-secondary);
    }

    .actions-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
    }

    .action-card {
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 1.25rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.5rem;
        color: var(--text-primary);
        font-size: 0.875rem;
        transition: var(--transition);
    }

    .action-card:hover {
        border-color: var(--primary);
        background: var(--bg-card-hover);
        color: var(--text-primary);
    }

    .action-icon {
        font-size: 1.25rem;
    }

    /* ============ Admin Table ============ */
    .admin-table-wrapper {
        overflow-x: auto;
        background: var(--bg-card);
        border-radius: var(--radius);
        border: 1px solid var(--border);
    }

    .admin-table {
        width: 100%;
        border-collapse: collapse;
    }

    .admin-table th,
    .admin-table td {
        padding: 1rem;
        text-align: left;
        border-bottom: 1px solid var(--border);
    }

    .admin-table th {
        background: var(--bg-header);
        color: var(--text-muted);
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .admin-table tr:last-child td {
        border-bottom: none;
    }

    .admin-table tr:hover td {
        background: var(--bg-card-hover);
    }

    .admin-table code {
        background: var(--bg-body);
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        font-size: 0.875rem;
        color: var(--primary-light);
    }

    .admin-table .actions {
        white-space: nowrap;
    }

    .btn-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 6px;
        transition: var(--transition);
    }

    .btn-icon:hover {
        background: var(--bg-body);
    }

    .btn-icon.delete:hover {
        background: rgba(239, 68, 68, 0.2);
    }

    .badge {
        display: inline-block;
        background: var(--bg-body);
        color: var(--primary-light);
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 500;
    }

    /* ============ Filter Bar ============ */
    .filter-bar {
        margin-bottom: 1rem;
    }

    .filter-bar select {
        background: var(--bg-card);
        border: 1px solid var(--border);
        color: var(--text-primary);
        padding: 0.5rem 1rem;
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        cursor: pointer;
    }

    .filter-bar select:focus {
        outline: none;
        border-color: var(--primary);
    }

    /* ============ Forms ============ */
    .admin-form {
        max-width: 600px;
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 2rem;
    }

    .form-group {
        margin-bottom: 1.5rem;
    }

    .form-group label {
        display: block;
        margin-bottom: 0.5rem;
        color: var(--text-primary);
        font-weight: 500;
        font-size: 0.875rem;
    }

    .form-group input[type="text"],
    .form-group input[type="url"],
    .form-group input[type="file"],
    .form-group select,
    .form-group textarea {
        width: 100%;
        background: var(--bg-input);
        border: 1px solid var(--border);
        color: var(--text-primary);
        padding: 0.75rem 1rem;
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        transition: var(--transition);
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
    }

    .form-group input::placeholder,
    .form-group textarea::placeholder {
        color: var(--text-muted);
    }

    .form-group small {
        display: block;
        margin-top: 0.5rem;
        color: var(--text-muted);
        font-size: 0.8rem;
    }

    .form-group textarea {
        resize: vertical;
        min-height: 100px;
    }

    .current-image {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1rem;
        padding: 1rem;
        background: var(--bg-body);
        border-radius: var(--radius-sm);
    }

    .current-image img {
        border-radius: 4px;
    }

    .current-image span {
        color: var(--text-secondary);
        font-size: 0.875rem;
    }

    .form-actions {
        display: flex;
        gap: 1rem;
        padding-top: 1rem;
        border-top: 1px solid var(--border);
    }

    /* ============ Alerts ============ */
    .alert {
        padding: 1rem 1.25rem;
        border-radius: var(--radius-sm);
        margin-bottom: 1.5rem;
        font-size: 0.875rem;
        font-weight: 500;
    }

    .alert-success {
        background: rgba(34, 197, 94, 0.1);
        border: 1px solid var(--success);
        color: var(--success);
    }

    .alert-error {
        background: rgba(239, 68, 68, 0.1);
        border: 1px solid var(--danger);
        color: var(--danger);
    }

    .alert-warning {
        background: rgba(245, 158, 11, 0.1);
        border: 1px solid var(--warning);
        color: var(--warning);
    }

    .alert-warning a {
        color: var(--warning);
        text-decoration: underline;
    }

    /* ============ Responsive ============ */
    @media (max-width: 768px) {
        .header {
            padding: 1rem;
        }

        .nav {
            gap: 0.25rem;
        }

        .nav-link {
            padding: 0.375rem 0.75rem;
            font-size: 0.8rem;
        }

        .main {
            padding: 1rem;
        }

        .sections-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .section-image,
        .section-image-placeholder {
            height: 120px;
        }

        .material-header {
            flex-direction: column;
            text-align: center;
        }

        /* Admin responsive */
        .admin-sidebar {
            position: static;
            width: 100%;
            height: auto;
            border-right: none;
            border-bottom: 1px solid var(--border);
        }

        .admin-nav {
            flex-direction: row;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .admin-main {
            margin-left: 0;
            padding: 1rem;
        }

        .admin-container {
            flex-direction: column;
        }

        .admin-header {
            flex-direction: column;
            gap: 1rem;
            align-items: flex-start;
        }

        .form-actions {
            flex-direction: column;
        }
    }

    @media (max-width: 480px) {
        .sections-grid {
            grid-template-columns: 1fr;
        }
    }

</style>
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
    <script>/**
 * Materials Management System - JavaScript
 * Minimal enhancements for better UX
 */

document.addEventListener('DOMContentLoaded', function() {
    // Auto-generate slug from section name
    const nameInput = document.getElementById('name');
    const slugInput = document.getElementById('slug');
    
    if (nameInput && slugInput) {
        nameInput.addEventListener('input', function() {
            // Only auto-generate if slug is empty or was auto-generated
            if (!slugInput.dataset.manual) {
                slugInput.value = generateSlug(this.value);
            }
        });
        
        slugInput.addEventListener('input', function() {
            // Mark as manually edited
            this.dataset.manual = this.value !== generateSlug(nameInput.value);
        });
    }
    
    // Confirm delete actions
    document.querySelectorAll('.delete').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to delete this item?')) {
                e.preventDefault();
            }
        });
    });
    
    // Auto-hide alerts after 5 seconds
    document.querySelectorAll('.alert').forEach(function(alert) {
        setTimeout(function() {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(function() {
                alert.remove();
            }, 300);
        }, 5000);
    });
    
    // Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });
});

/**
 * Generate SEO-friendly slug from text
 */
function generateSlug(text) {
    return text
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/[\s-]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

/**
 * Toggle file fields based on file type selection
 * Called from admin.php inline script
 */
// function toggleFileFields() {
//     const type = document.getElementById('file_type');
//     const gdriveField = document.getElementById('gdrive-field');
//     const imageField = document.getElementById('image-field');
    
//     if (!type || !gdriveField || !imageField) return;
    
//     const value = type.value;
//     gdriveField.style.display = (value === 'gdrive_pdf' || value === 'gdrive_word') ? 'block' : 'none';
//     imageField.style.display = value === 'image' ? 'block' : 'none';
// }

/**
 * Preview uploaded image before submit
 */
function previewImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            let preview = input.parentElement.querySelector('.preview-img');
            if (!preview) {
                preview = document.createElement('img');
                preview.className = 'preview-img';
                preview.style.cssText = 'max-width: 200px; margin-top: 1rem; border-radius: 8px;';
                input.parentElement.appendChild(preview);
            }
            preview.src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Add image preview listener if image input exists
document.addEventListener('DOMContentLoaded', function() {
    const imageInput = document.getElementById('image');
    if (imageInput) {
        imageInput.addEventListener('change', function() {
            previewImage(this);
        });
    }
});
</script>
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
    <style>

    /* 
    * Materials Management System - Styles
    * Matching existing platform design - Dark Navy Theme
    */

    /* ============ CSS Variables ============ */
    :root {
        --primary: #3b82f6;
        --primary-dark: #2563eb;
        --primary-light: #60a5fa;

        --bg-body: #0a1628;
        --bg-card: #111d32;
        --bg-card-hover: #1a2a45;
        --bg-header: #0d1829;
        --bg-input: #0a1628;

        --text-primary: #e2e8f0;
        --text-secondary: #94a3b8;
        --text-muted: #64748b;

        --border: #1e3a5f;
        --border-light: #2d4a6f;

        --success: #22c55e;
        --danger: #ef4444;
        --warning: #f59e0b;

        --radius: 12px;
        --radius-sm: 8px;
        --shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        --transition: all 0.3s ease;
    }

    /* ============ Reset & Base ============ */
    *,
    *::before,
    *::after {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    body {
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        background: var(--bg-body);
        color: var(--text-primary);
        line-height: 1.6;
        min-height: 100vh;
    }

    a {
        color: var(--primary-light);
        text-decoration: none;
        transition: var(--transition);
    }

    a:hover {
        color: var(--primary);
    }

    /* ============ Layout ============ */
    .container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0 1.5rem;
    }

    /* ============ Header ============ */
    .header {
        background: var(--bg-header);
        padding: 1rem 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid var(--border);
        position: sticky;
        top: 0;
        z-index: 100;
    }

    .logo {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--text-primary);
    }

    .nav {
        display: flex;
        gap: 0.5rem;
    }

    .nav-link {
        color: var(--text-secondary);
        font-size: 0.875rem;
        font-weight: 500;
        padding: 0.5rem 1rem;
        border-radius: var(--radius-sm);
        background: var(--bg-card);
        border: 1px solid var(--border);
        transition: var(--transition);
    }

    .nav-link:hover,
    .nav-link.active {
        color: var(--text-primary);
        background: var(--bg-card-hover);
        border-color: var(--border-light);
    }

    /* ============ Main Content ============ */
    .main {
        padding: 2rem;
    }

    .page-title {
        font-size: 1.5rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 1.5rem;
    }

    /* ============ Sections Grid (Image Cards) ============ */
    .sections-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 1.25rem;
    }

    .section-card {
        background: var(--bg-card);
        border-radius: var(--radius);
        overflow: hidden;
        transition: var(--transition);
        border: 1px solid transparent;
    }

    .section-card:hover {
        transform: translateY(-4px);
        border-color: var(--primary);
        box-shadow: var(--shadow);
    }

    .section-image {
        width: 100%;
        height: 160px;
        object-fit: cover;
        background: var(--bg-card-hover);
        display: block;
    }

    .section-image-placeholder {
        width: 100%;
        height: 160px;
        background: linear-gradient(135deg, var(--bg-card-hover) 0%, var(--border) 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 3rem;
    }

    .section-info {
        padding: 1rem;
    }

    .section-title {
        font-size: 0.95rem;
        font-weight: 500;
        color: var(--primary-light);
        margin: 0;
    }

    .section-meta {
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-top: 0.25rem;
    }

    /* ============ Materials Grid ============ */
    .materials-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 1.25rem;
    }

    .material-card {
        background: var(--bg-card);
        border-radius: var(--radius);
        overflow: hidden;
        transition: var(--transition);
        border: 1px solid transparent;
        display: flex;
        flex-direction: column;
    }

    .material-card:hover {
        transform: translateY(-4px);
        border-color: var(--primary);
        box-shadow: var(--shadow);
    }

    .material-thumb {
        width: 100%;
        height: 140px;
        object-fit: cover;
        background: var(--bg-card-hover);
    }

    .material-thumb-placeholder {
        width: 100%;
        height: 140px;
        background: linear-gradient(135deg, var(--bg-card-hover) 0%, var(--border) 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2.5rem;
    }

    .material-content {
        padding: 1rem;
        flex: 1;
    }

    .material-title {
        font-size: 1rem;
        font-weight: 500;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }

    .material-desc {
        font-size: 0.875rem;
        color: var(--text-secondary);
        margin-bottom: 0.75rem;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .material-badge {
        display: inline-block;
        background: var(--bg-body);
        color: var(--primary-light);
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 500;
        border: 1px solid var(--border);
    }

    /* ============ Breadcrumb ============ */
    .breadcrumb {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.875rem;
        max-width: 900px;
        margin: 0 auto 1.5rem auto;
    }

    .breadcrumb a {
        color: var(--text-muted);
    }

    .breadcrumb a:hover {
        color: var(--primary-light);
    }

    .breadcrumb .separator {
        color: var(--text-muted);
    }

    .breadcrumb .current {
        color: var(--text-secondary);
    }

    /* ============ Material Detail ============ */
    .material-detail {
        background: var(--bg-card);
        border-radius: var(--radius);
        padding: 2rem;
        max-width: 900px;
        margin: 0 auto;
    }

    .material-header {
        display: flex;
        align-items: flex-start;
        gap: 1.5rem;
        margin-bottom: 2rem;
        padding-bottom: 1.5rem;
        border-bottom: 1px solid var(--border);
    }

    .material-type-large {
        font-size: 3rem;
    }

    .material-info h1 {
        font-size: 1.5rem;
        margin-bottom: 0.5rem;
        color: var(--text-primary);
    }

    .material-badge.large {
        font-size: 0.875rem;
    }

    .material-description {
        margin-bottom: 2rem;
    }

    .material-description h3 {
        font-size: 0.875rem;
        color: var(--text-muted);
        margin-bottom: 0.5rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .material-description p {
        color: var(--text-secondary);
        line-height: 1.8;
    }

    .material-preview {
        margin-bottom: 2rem;
    }

    .image-preview img {
        max-width: 100%;
        height: auto;
        border-radius: var(--radius);
    }

    .gdrive-preview iframe {
        width: 100%;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: #fff;
    }

    .gdrive-actions {
        margin-top: 1rem;
    }

    .material-footer {
        padding-top: 1.5rem;
        border-top: 1px solid var(--border);
    }

    /* ============ Empty & Error States ============ */
    .empty-state,
    .error-state {
        text-align: center;
        padding: 4rem 2rem;
        background: var(--bg-card);
        border-radius: var(--radius);
    }

    .empty-icon,
    .error-icon {
        font-size: 4rem;
        margin-bottom: 1rem;
        opacity: 0.5;
    }

    .empty-state h3,
    .error-state h2 {
        font-size: 1.25rem;
        margin-bottom: 0.5rem;
        color: var(--text-primary);
    }

    .empty-state p,
    .error-state p {
        color: var(--text-secondary);
        margin-bottom: 1.5rem;
    }

    /* ============ Buttons ============ */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.625rem 1.25rem;
        border: none;
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        transition: var(--transition);
        text-decoration: none;
    }

    .btn-primary {
        background: var(--primary);
        color: white;
    }

    .btn-primary:hover {
        background: var(--primary-dark);
        color: white;
    }

    .btn-secondary {
        background: var(--bg-card);
        color: var(--text-primary);
        border: 1px solid var(--border);
    }

    .btn-secondary:hover {
        background: var(--bg-card-hover);
        color: var(--text-primary);
    }

    .btn-outline {
        background: transparent;
        border: 1px solid var(--border);
        color: var(--text-primary);
    }

    .btn-outline:hover {
        background: var(--bg-card);
        color: var(--text-primary);
    }

    /* ============ Footer ============ */
    .footer {
        text-align: center;
        padding: 2rem;
        color: var(--text-muted);
        font-size: 0.875rem;
        border-top: 1px solid var(--border);
        margin-top: 2rem;
    }

    /* ============ Admin Styles ============ */
    .admin-body {
        background: var(--bg-body);
    }

    .admin-container {
        display: flex;
        min-height: 100vh;
    }

    .admin-sidebar {
        width: 220px;
        background: var(--bg-header);
        border-right: 1px solid var(--border);
        padding: 1.5rem 1rem;
        position: fixed;
        height: 100vh;
        overflow-y: auto;
    }

    .admin-logo {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 2rem;
        padding: 0 0.5rem;
    }

    .admin-nav {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .admin-link {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem 1rem;
        color: var(--text-secondary);
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        transition: var(--transition);
    }

    .admin-link:hover,
    .admin-link.active {
        background: var(--bg-card);
        color: var(--text-primary);
    }

    .admin-divider {
        border: none;
        border-top: 1px solid var(--border);
        margin: 1rem 0;
    }

    .admin-main {
        flex: 1;
        margin-left: 220px;
        padding: 2rem;
    }

    .admin-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }

    .admin-header h1 {
        font-size: 1.5rem;
        font-weight: 600;
    }

    /* ============ Stats Grid ============ */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }

    .stat-card {
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 1.5rem;
        text-align: center;
    }

    .stat-icon {
        font-size: 1.5rem;
        margin-bottom: 0.5rem;
    }

    .stat-number {
        font-size: 2rem;
        font-weight: 700;
        color: var(--primary-light);
    }

    .stat-label {
        color: var(--text-muted);
        font-size: 0.875rem;
    }

    /* ============ Quick Actions ============ */
    .quick-actions h2 {
        font-size: 1rem;
        font-weight: 600;
        margin-bottom: 1rem;
        color: var(--text-secondary);
    }

    .actions-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
    }

    .action-card {
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 1.25rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.5rem;
        color: var(--text-primary);
        font-size: 0.875rem;
        transition: var(--transition);
    }

    .action-card:hover {
        border-color: var(--primary);
        background: var(--bg-card-hover);
        color: var(--text-primary);
    }

    .action-icon {
        font-size: 1.25rem;
    }

    /* ============ Admin Table ============ */
    .admin-table-wrapper {
        overflow-x: auto;
        background: var(--bg-card);
        border-radius: var(--radius);
        border: 1px solid var(--border);
    }

    .admin-table {
        width: 100%;
        border-collapse: collapse;
    }

    .admin-table th,
    .admin-table td {
        padding: 1rem;
        text-align: left;
        border-bottom: 1px solid var(--border);
    }

    .admin-table th {
        background: var(--bg-header);
        color: var(--text-muted);
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .admin-table tr:last-child td {
        border-bottom: none;
    }

    .admin-table tr:hover td {
        background: var(--bg-card-hover);
    }

    .admin-table code {
        background: var(--bg-body);
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        font-size: 0.875rem;
        color: var(--primary-light);
    }

    .admin-table .actions {
        white-space: nowrap;
    }

    .btn-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 6px;
        transition: var(--transition);
    }

    .btn-icon:hover {
        background: var(--bg-body);
    }

    .btn-icon.delete:hover {
        background: rgba(239, 68, 68, 0.2);
    }

    .badge {
        display: inline-block;
        background: var(--bg-body);
        color: var(--primary-light);
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 500;
    }

    /* ============ Filter Bar ============ */
    .filter-bar {
        margin-bottom: 1rem;
    }

    .filter-bar select {
        background: var(--bg-card);
        border: 1px solid var(--border);
        color: var(--text-primary);
        padding: 0.5rem 1rem;
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        cursor: pointer;
    }

    .filter-bar select:focus {
        outline: none;
        border-color: var(--primary);
    }

    /* ============ Forms ============ */
    .admin-form {
        max-width: 600px;
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 2rem;
    }

    .form-group {
        margin-bottom: 1.5rem;
    }

    .form-group label {
        display: block;
        margin-bottom: 0.5rem;
        color: var(--text-primary);
        font-weight: 500;
        font-size: 0.875rem;
    }

    .form-group input[type="text"],
    .form-group input[type="url"],
    .form-group input[type="file"],
    .form-group select,
    .form-group textarea {
        width: 100%;
        background: var(--bg-input);
        border: 1px solid var(--border);
        color: var(--text-primary);
        padding: 0.75rem 1rem;
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        transition: var(--transition);
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
    }

    .form-group input::placeholder,
    .form-group textarea::placeholder {
        color: var(--text-muted);
    }

    .form-group small {
        display: block;
        margin-top: 0.5rem;
        color: var(--text-muted);
        font-size: 0.8rem;
    }

    .form-group textarea {
        resize: vertical;
        min-height: 100px;
    }

    .current-image {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1rem;
        padding: 1rem;
        background: var(--bg-body);
        border-radius: var(--radius-sm);
    }

    .current-image img {
        border-radius: 4px;
    }

    .current-image span {
        color: var(--text-secondary);
        font-size: 0.875rem;
    }

    .form-actions {
        display: flex;
        gap: 1rem;
        padding-top: 1rem;
        border-top: 1px solid var(--border);
    }

    /* ============ Alerts ============ */
    .alert {
        padding: 1rem 1.25rem;
        border-radius: var(--radius-sm);
        margin-bottom: 1.5rem;
        font-size: 0.875rem;
        font-weight: 500;
    }

    .alert-success {
        background: rgba(34, 197, 94, 0.1);
        border: 1px solid var(--success);
        color: var(--success);
    }

    .alert-error {
        background: rgba(239, 68, 68, 0.1);
        border: 1px solid var(--danger);
        color: var(--danger);
    }

    .alert-warning {
        background: rgba(245, 158, 11, 0.1);
        border: 1px solid var(--warning);
        color: var(--warning);
    }

    .alert-warning a {
        color: var(--warning);
        text-decoration: underline;
    }

    /* ============ Responsive ============ */
    @media (max-width: 768px) {
        .header {
            padding: 1rem;
        }

        .nav {
            gap: 0.25rem;
        }

        .nav-link {
            padding: 0.375rem 0.75rem;
            font-size: 0.8rem;
        }

        .main {
            padding: 1rem;
        }

        .sections-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .section-image,
        .section-image-placeholder {
            height: 120px;
        }

        .material-header {
            flex-direction: column;
            text-align: center;
        }

        /* Admin responsive */
        .admin-sidebar {
            position: static;
            width: 100%;
            height: auto;
            border-right: none;
            border-bottom: 1px solid var(--border);
        }

        .admin-nav {
            flex-direction: row;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .admin-main {
            margin-left: 0;
            padding: 1rem;
        }

        .admin-container {
            flex-direction: column;
        }

        .admin-header {
            flex-direction: column;
            gap: 1rem;
            align-items: flex-start;
        }

        .form-actions {
            flex-direction: column;
        }
    }

    @media (max-width: 480px) {
        .sections-grid {
            grid-template-columns: 1fr;
        }
    }

</style>
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
                        <img src="<?= SITE_URL ?>/assets/images/sections/<?= sanitize($s['slug']) ?>.jpg" alt="<?= sanitize($s['name']) ?>" class="section-image" onerror="this.onerror=null; this.src='<?= SITE_URL ?>/default.jpg';">
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
    <script>/**
 * Materials Management System - JavaScript
 * Minimal enhancements for better UX
 */

document.addEventListener('DOMContentLoaded', function() {
    // Auto-generate slug from section name
    const nameInput = document.getElementById('name');
    const slugInput = document.getElementById('slug');
    
    if (nameInput && slugInput) {
        nameInput.addEventListener('input', function() {
            // Only auto-generate if slug is empty or was auto-generated
            if (!slugInput.dataset.manual) {
                slugInput.value = generateSlug(this.value);
            }
        });
        
        slugInput.addEventListener('input', function() {
            // Mark as manually edited
            this.dataset.manual = this.value !== generateSlug(nameInput.value);
        });
    }
    
    // Confirm delete actions
    document.querySelectorAll('.delete').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to delete this item?')) {
                e.preventDefault();
            }
        });
    });
    
    // Auto-hide alerts after 5 seconds
    document.querySelectorAll('.alert').forEach(function(alert) {
        setTimeout(function() {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(function() {
                alert.remove();
            }, 300);
        }, 5000);
    });
    
    // Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });
});

/**
 * Generate SEO-friendly slug from text
 */
function generateSlug(text) {
    return text
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/[\s-]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

/**
 * Toggle file fields based on file type selection
 * Called from admin.php inline script
 */
// function toggleFileFields() {
//     const type = document.getElementById('file_type');
//     const gdriveField = document.getElementById('gdrive-field');
//     const imageField = document.getElementById('image-field');
    
//     if (!type || !gdriveField || !imageField) return;
    
//     const value = type.value;
//     gdriveField.style.display = (value === 'gdrive_pdf' || value === 'gdrive_word') ? 'block' : 'none';
//     imageField.style.display = value === 'image' ? 'block' : 'none';
// }

/**
 * Preview uploaded image before submit
 */
function previewImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            let preview = input.parentElement.querySelector('.preview-img');
            if (!preview) {
                preview = document.createElement('img');
                preview.className = 'preview-img';
                preview.style.cssText = 'max-width: 200px; margin-top: 1rem; border-radius: 8px;';
                input.parentElement.appendChild(preview);
            }
            preview.src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Add image preview listener if image input exists
document.addEventListener('DOMContentLoaded', function() {
    const imageInput = document.getElementById('image');
    if (imageInput) {
        imageInput.addEventListener('change', function() {
            previewImage(this);
        });
    }
});
</script>
</body>
</html>
