ALTER TABLE flashcard_frases
    ADD COLUMN frequencia_last_m DATETIME NULL DEFAULT NULL AFTER id_frequencia_informacional,
    ADD KEY idx_flashcard_frases_maintenance (flashcard_id, frequencia_last_m);
