<?php
/**
 * CRUD Classes for Sections and Materials
 * Materials Management System
 */

require_once __DIR__ . '/config.php';

/**
 * Section Model - CRUD operations for sections
 */
class Section
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get all sections
     */
    public function getAll(): array
    {
        $stmt = $this->db->query("SELECT * FROM sections ORDER BY created_at DESC");
        return $stmt->fetchAll();
    }

    /**
     * Get section by ID
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM sections WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get section by slug
     */
    public function getBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM sections WHERE slug = ?");
        $stmt->execute([$slug]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Create new section
     */
    public function create(string $name, string $slug): int
    {
        $stmt = $this->db->prepare("INSERT INTO sections (name, slug) VALUES (?, ?)");
        $stmt->execute([$name, $slug]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update section
     */
    public function update(int $id, string $name, string $slug): bool
    {
        $stmt = $this->db->prepare("UPDATE sections SET name = ?, slug = ? WHERE id = ?");
        return $stmt->execute([$name, $slug, $id]);
    }

    /**
     * Delete section (materials will cascade delete)
     */
    public function delete(int $id): bool
    {
        // Delete associated material images
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

    /**
     * Get material count for section
     */
    public function getMaterialCount(int $id): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM materials WHERE section_id = ?");
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Check if slug exists (for validation)
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
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

/**
 * Material Model - CRUD operations for materials
 */
class Material
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get all materials with section info
     */
    public function getAll(): array
    {
        $stmt = $this->db->query("
            SELECT m.*, s.name as section_name, s.slug as section_slug 
            FROM materials m 
            JOIN sections s ON m.section_id = s.id 
            ORDER BY m.created_at DESC
        ");
        return $stmt->fetchAll();
    }

    /**
     * Get materials by section ID
     */
    public function getBySectionId(int $sectionId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM materials WHERE section_id = ? ORDER BY created_at DESC");
        $stmt->execute([$sectionId]);
        return $stmt->fetchAll();
    }

    /**
     * Get material by ID with section info
     */
    public function getById(int $id): ?array
    {
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

    /**
     * Create new material
     */
    public function create(array $data): int
    {
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

    /**
     * Update material
     */
    public function update(int $id, array $data): bool
    {
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

    /**
     * Delete material and its image
     */
    public function delete(int $id): bool
    {
        // Get material to delete image
        $material = $this->getById($id);
        if ($material && $material['image_path']) {
            deleteImage($material['image_path']);
        }

        $stmt = $this->db->prepare("DELETE FROM materials WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Get total count
     */
    public function getCount(): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) FROM materials");
        return (int) $stmt->fetchColumn();
    }
}
