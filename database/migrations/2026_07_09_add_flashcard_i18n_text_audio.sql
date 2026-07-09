ALTER TABLE flashcards
  ADD COLUMN IF NOT EXISTS front_translations_encrypted LONGTEXT NULL AFTER back_encrypted,
  ADD COLUMN IF NOT EXISTS back_translations_encrypted LONGTEXT NULL AFTER front_translations_encrypted,
  ADD COLUMN IF NOT EXISTS audio_front_translations_encrypted LONGTEXT NULL AFTER audio_back_encrypted,
  ADD COLUMN IF NOT EXISTS audio_back_translations_encrypted LONGTEXT NULL AFTER audio_front_translations_encrypted;
