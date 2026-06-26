ALTER TABLE flashcards
  ADD COLUMN question_answer TINYINT NULL DEFAULT NULL AFTER info_type;
