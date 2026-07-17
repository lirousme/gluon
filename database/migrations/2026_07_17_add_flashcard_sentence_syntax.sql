CREATE TABLE IF NOT EXISTS flashcard_frases (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    flashcard_id INT NOT NULL,
    ordem INT UNSIGNED NOT NULL,
    id_frequencia_informacional INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_flashcard_frases_ordem (flashcard_id, ordem),
    KEY idx_flashcard_frases_frequencia (id_frequencia_informacional),
    CONSTRAINT fk_flashcard_frases_card FOREIGN KEY (flashcard_id) REFERENCES flashcards(id) ON DELETE CASCADE,
    CONSTRAINT fk_flashcard_frases_frequencia FOREIGN KEY (id_frequencia_informacional) REFERENCES frequencias_informacionais(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A coluna antiga em flashcards permanece apenas para compatibilidade com filtros legados.
-- A frequência por frase passa a ser armazenada em flashcard_frases.
-- As tabelas de elementos sintáticos também precisam de id_frase para que elementos
-- iguais em frases distintas não sejam mesclados; id_sujeito_relativo identifica o sujeito.
