CREATE TABLE IF NOT EXISTS characters (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    character_id TINYINT UNSIGNED NOT NULL,
    language VARCHAR(10) NOT NULL,
    voice_id VARCHAR(160) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_characters_character_language (character_id, language),
    CONSTRAINT chk_characters_character_id CHECK (character_id BETWEEN 1 AND 10)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE directories
    ADD COLUMN character_id TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER deck_mode;
