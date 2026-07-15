SET @has_flashcards_status_informacional_column := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'flashcards'
      AND COLUMN_NAME = 'status_informacional'
);

SET @sql := IF(@has_flashcards_status_informacional_column = 0,
    'ALTER TABLE flashcards ADD COLUMN status_informacional TINYINT NOT NULL DEFAULT 0 AFTER id_frequencia_informacional',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
