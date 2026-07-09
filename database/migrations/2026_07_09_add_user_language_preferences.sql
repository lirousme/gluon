ALTER TABLE users
    ADD COLUMN native_language VARCHAR(20) NOT NULL DEFAULT 'Português' AFTER source_directory_id,
    ADD COLUMN learning_language VARCHAR(20) NOT NULL DEFAULT 'Inglês' AFTER native_language;
