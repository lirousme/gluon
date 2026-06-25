ALTER TABLE flashcards
    ADD COLUMN dynamic_parent_flashcard_id INT UNSIGNED NULL DEFAULT NULL AFTER dynamic_text_type;
