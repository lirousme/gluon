ALTER TABLE flashcard_tags
  ADD COLUMN created_by_user_id INT NULL AFTER user_id;

UPDATE flashcard_tags
SET created_by_user_id = user_id
WHERE created_by_user_id IS NULL;
