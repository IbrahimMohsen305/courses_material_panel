-- Materials Management System Database Schema
-- Run this SQL in your MySQL database

CREATE DATABASE IF NOT EXISTS courses_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE courses_db;

-- Sections table
CREATE TABLE IF NOT EXISTS sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    thumbnail VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Materials table
CREATE TABLE IF NOT EXISTS materials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    file_type ENUM('gdrive_pdf', 'gdrive_word', 'image', 'youtube') NOT NULL,
    file_url VARCHAR(500) DEFAULT NULL,
    image_path VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    INDEX idx_section (section_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample data (optional - remove if not needed)
INSERT INTO sections (name, slug) VALUES 
    ('Getting Started', 'getting-started'),
    ('Advanced Topics', 'advanced-topics'),
    ('Resources', 'resources');

INSERT INTO materials (section_id, title, description, file_type, file_url) VALUES
    (1, 'Introduction Guide', 'A comprehensive introduction to the platform.', 'gdrive_pdf', 'https://drive.google.com/file/d/EXAMPLE_ID/view'),
    (1, 'Quick Start Document', 'Get up and running in minutes.', 'gdrive_word', 'https://docs.google.com/document/d/EXAMPLE_ID/edit'),
    (2, 'Deep Dive Tutorial', 'Advanced concepts explained in detail.', 'gdrive_pdf', 'https://drive.google.com/file/d/EXAMPLE_ID2/view');
