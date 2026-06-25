ALTER TABLE flashcards
    ADD COLUMN dynamic_parent_flashcard_id INT NULL DEFAULT NULL AFTER dynamic_text_type;
