CREATE TABLE IF NOT EXISTS frequencias_informacionais (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    nome VARCHAR(160) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_frequencias_informacionais_user_nome (user_id, nome),
    KEY idx_frequencias_informacionais_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_flashcards_frequencia_column := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flashcards' AND COLUMN_NAME = 'id_frequencia_informacional'
);
SET @sql := IF(@has_flashcards_frequencia_column = 0,
    'ALTER TABLE flashcards ADD COLUMN id_frequencia_informacional INT UNSIGNED NULL DEFAULT NULL AFTER dynamic_parent_flashcard_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_flashcards_frequencia_index := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flashcards' AND INDEX_NAME = 'idx_flashcards_id_frequencia_informacional'
);
SET @sql := IF(@has_flashcards_frequencia_index = 0,
    'CREATE INDEX idx_flashcards_id_frequencia_informacional ON flashcards (id_frequencia_informacional)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_flashcards_frequencia_fk := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'flashcards'
      AND CONSTRAINT_NAME = 'fk_flashcards_frequencia_informacional'
);
SET @sql := IF(@has_flashcards_frequencia_fk = 0,
    'ALTER TABLE flashcards ADD CONSTRAINT fk_flashcards_frequencia_informacional FOREIGN KEY (id_frequencia_informacional) REFERENCES frequencias_informacionais(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
