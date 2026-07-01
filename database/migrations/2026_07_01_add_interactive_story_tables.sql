SET @users_id_type := (
  SELECT COLUMN_TYPE
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'id'
  LIMIT 1
);

SET @tags_id_type := (
  SELECT COLUMN_TYPE
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'flashcard_tags'
    AND COLUMN_NAME = 'id'
  LIMIT 1
);

SET @create_superpositions := CONCAT(
  'CREATE TABLE IF NOT EXISTS interactive_story_superpositions (',
  'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,',
  'user_id ', COALESCE(@users_id_type, 'int unsigned'), ' NOT NULL,',
  'title VARCHAR(191) NOT NULL DEFAULT ''História em Superposição'',',
  'premise MEDIUMTEXT NULL,',
  'options_json MEDIUMTEXT NULL,',
  'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,',
  'updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,',
  'UNIQUE KEY uniq_interactive_story_superpositions_user (user_id),',
  'CONSTRAINT fk_interactive_story_superpositions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
  ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
PREPARE stmt FROM @create_superpositions;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @superpositions_id_type := (
  SELECT COLUMN_TYPE
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'interactive_story_superpositions'
    AND COLUMN_NAME = 'id'
  LIMIT 1
);

SET @create_collapses := CONCAT(
  'CREATE TABLE IF NOT EXISTS interactive_story_collapses (',
  'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,',
  'user_id ', COALESCE(@users_id_type, 'int unsigned'), ' NOT NULL,',
  'superposition_id ', COALESCE(@superpositions_id_type, 'int unsigned'), ' NOT NULL,',
  'tag_id ', COALESCE(@tags_id_type, 'int unsigned'), ' NOT NULL,',
  'title VARCHAR(191) NOT NULL DEFAULT '''',',
  'path_json MEDIUMTEXT NULL,',
  'collapsed_text MEDIUMTEXT NULL,',
  'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,',
  'updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,',
  'UNIQUE KEY uniq_interactive_story_collapses_tag (tag_id),',
  'KEY idx_interactive_story_collapses_user (user_id),',
  'KEY idx_interactive_story_collapses_superposition (superposition_id),',
  'CONSTRAINT fk_interactive_story_collapses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,',
  'CONSTRAINT fk_interactive_story_collapses_superposition FOREIGN KEY (superposition_id) REFERENCES interactive_story_superpositions(id) ON DELETE CASCADE,',
  'CONSTRAINT fk_interactive_story_collapses_tag FOREIGN KEY (tag_id) REFERENCES flashcard_tags(id) ON DELETE CASCADE',
  ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
PREPARE stmt FROM @create_collapses;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
