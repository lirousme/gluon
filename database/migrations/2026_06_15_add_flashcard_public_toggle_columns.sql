ALTER TABLE flashcards
  ADD COLUMN created_by_user_id INT NULL AFTER directory_id,
  ADD COLUMN private_directory_id INT NULL AFTER created_by_user_id;
