ALTER TABLE flashcards
    ADD COLUMN dynamic_text_type INT NOT NULL DEFAULT 0 AFTER info_type;
