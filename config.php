<?php
/**
 * Configuration & Database Connection
 * Materials Management System
 */

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'courses_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Site configuration
define('SITE_URL', 'http://localhost/Courses');
define('UPLOAD_PATH', __DIR__ . '/uploads/images/');
define('UPLOAD_URL', SITE_URL . '/uploads/images/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp']);

// Database connection (PDO Singleton)
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

// Helper functions
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
    // Convert Google Drive share link to embed link
    if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
        return "https://drive.google.com/file/d/{$matches[1]}/preview";
    }
    // Convert Google Docs link to embed
    if (preg_match('/\/document\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
        return "https://docs.google.com/document/d/{$matches[1]}/preview";
    }
    return $url;
}

function getYouTubeEmbedUrl(string $url): string {
    // Extract YouTube video ID from various URL formats
    $patterns = [
        // Standard YouTube URLs
        '/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/',
        // YouTube short URLs
        '/youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/',
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $matches)) {
            return "https://www.youtube.com/embed/{$matches[1]}";
        }
    }
    
    // If no match, return original URL (might already be an embed URL)
    return $url;
}

function getYouTubeVideoId(string $url): ?string {
    // Extract YouTube video ID from various URL formats
    $patterns = [
        // Standard YouTube URLs
        '/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/',
        // YouTube short URLs
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
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    
    // Validate file size
    if ($file['size'] > MAX_FILE_SIZE) {
        return null;
    }
    
    // Validate extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS)) {
        return null;
    }
    
    // Create upload directory if not exists
    if (!is_dir(UPLOAD_PATH)) {
        mkdir(UPLOAD_PATH, 0755, true);
    }
    
    // Generate unique filename
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

// CSRF Token functions
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
