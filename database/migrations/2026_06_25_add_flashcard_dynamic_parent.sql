SET @flashcards_id_type := (
  SELECT COLUMN_TYPE
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'flashcards'
    AND COLUMN_NAME = 'id'
  LIMIT 1
);

SET @dynamic_parent_column_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'flashcards'
    AND COLUMN_NAME = 'dynamic_parent_flashcard_id'
);

SET @add_dynamic_parent_sql := IF(
  @dynamic_parent_column_exists = 0,
  CONCAT('ALTER TABLE flashcards ADD COLUMN dynamic_parent_flashcard_id ', COALESCE(@flashcards_id_type, 'int unsigned'), ' NULL DEFAULT NULL AFTER dynamic_text_type'),
  CONCAT('ALTER TABLE flashcards MODIFY COLUMN dynamic_parent_flashcard_id ', COALESCE(@flashcards_id_type, 'int unsigned'), ' NULL DEFAULT NULL')
);
PREPARE add_dynamic_parent_stmt FROM @add_dynamic_parent_sql;
EXECUTE add_dynamic_parent_stmt;
DEALLOCATE PREPARE add_dynamic_parent_stmt;

SET @dynamic_parent_index_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'flashcards'
    AND INDEX_NAME = 'idx_flashcards_dynamic_parent_flashcard_id'
);
SET @add_dynamic_parent_index_sql := IF(
  @dynamic_parent_index_exists = 0,
  'CREATE INDEX idx_flashcards_dynamic_parent_flashcard_id ON flashcards (dynamic_parent_flashcard_id)',
  'SELECT 1'
);
PREPARE add_dynamic_parent_index_stmt FROM @add_dynamic_parent_index_sql;
EXECUTE add_dynamic_parent_index_stmt;
DEALLOCATE PREPARE add_dynamic_parent_index_stmt;

SET @dynamic_parent_fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'flashcards'
    AND (
      CONSTRAINT_NAME = 'fk_flashcards_dynamic_card_mother'
      OR (
        COLUMN_NAME = 'dynamic_parent_flashcard_id'
        AND REFERENCED_TABLE_NAME = 'flashcards'
        AND REFERENCED_COLUMN_NAME = 'id'
      )
    )
);
SET @add_dynamic_parent_fk_sql := IF(
  @dynamic_parent_fk_exists = 0,
  'ALTER TABLE flashcards ADD CONSTRAINT fk_flashcards_dynamic_card_mother FOREIGN KEY (dynamic_parent_flashcard_id) REFERENCES flashcards(id) ON DELETE CASCADE',
  'SELECT 1'
);
PREPARE add_dynamic_parent_fk_stmt FROM @add_dynamic_parent_fk_sql;
EXECUTE add_dynamic_parent_fk_stmt;
DEALLOCATE PREPARE add_dynamic_parent_fk_stmt;
