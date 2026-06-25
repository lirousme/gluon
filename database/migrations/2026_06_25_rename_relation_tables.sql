SET @has_old_relation_type_table := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tipo_de_relacao'
);
SET @has_new_relation_type_table := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tipos_de_relacoes'
);
SET @rename_relation_type_sql := IF(
  @has_old_relation_type_table = 1 AND @has_new_relation_type_table = 0,
  'RENAME TABLE tipo_de_relacao TO tipos_de_relacoes',
  'SELECT 1'
);
PREPARE rename_relation_type_stmt FROM @rename_relation_type_sql;
EXECUTE rename_relation_type_stmt;
DEALLOCATE PREPARE rename_relation_type_stmt;

SET @has_old_tag_family_table := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tag_family'
);
SET @has_new_tag_family_table := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'relacoes_taguineas'
);
SET @rename_tag_family_sql := IF(
  @has_old_tag_family_table = 1 AND @has_new_tag_family_table = 0,
  'RENAME TABLE tag_family TO relacoes_taguineas',
  'SELECT 1'
);
PREPARE rename_tag_family_stmt FROM @rename_tag_family_sql;
EXECUTE rename_tag_family_stmt;
DEALLOCATE PREPARE rename_tag_family_stmt;
