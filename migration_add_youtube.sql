    -- Migration: Add YouTube support to materials table
    -- Run this SQL if you already have an existing database

    USE courses_db;

    -- Modify the file_type ENUM to include 'youtube'
    ALTER TABLE materials 
    MODIFY COLUMN file_type ENUM('gdrive_pdf', 'gdrive_word', 'image', 'youtube') NOT NULL;

