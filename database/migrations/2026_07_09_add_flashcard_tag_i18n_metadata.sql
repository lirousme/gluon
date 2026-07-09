ALTER TABLE flashcard_tags
  ADD COLUMN IF NOT EXISTS name_translations_encrypted LONGTEXT NULL AFTER name_pt_br_encrypted,
  ADD COLUMN IF NOT EXISTS description_translations_encrypted LONGTEXT NULL AFTER name_translations_encrypted,
  ADD COLUMN IF NOT EXISTS sigla_simbolo_translations_encrypted LONGTEXT NULL AFTER sigla_simbolo;
