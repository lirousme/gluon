ALTER TABLE flashcard_tags
  MODIFY numero VARCHAR(191) NULL;

ALTER TABLE flashcard_tags
  ADD COLUMN IF NOT EXISTS sigla_simbolo VARCHAR(191) NULL AFTER numero;
