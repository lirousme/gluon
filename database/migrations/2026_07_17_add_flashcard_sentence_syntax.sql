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

-- A frequência passa a ser armazenada por frase; a coluna em flashcards é mantida para filtros legados.
ALTER TABLE subjects_links ADD COLUMN id_frase INT NULL AFTER tag_id;
ALTER TABLE objects_links ADD COLUMN id_frase INT NULL AFTER tag_id, ADD COLUMN id_sujeito_relativo INT NULL AFTER id_frase, ADD COLUMN tipo_elemento VARCHAR(32) NULL AFTER id_sujeito_relativo;
ALTER TABLE inflexion_type_links ADD COLUMN id_frase INT NULL AFTER tag_id, ADD COLUMN id_sujeito_relativo INT NULL AFTER id_frase, ADD COLUMN tipo_elemento VARCHAR(32) NULL AFTER id_sujeito_relativo;
ALTER TABLE verb_links ADD COLUMN id_frase INT NULL AFTER tag_id, ADD COLUMN id_sujeito_relativo INT NULL AFTER id_frase, ADD COLUMN tipo_elemento VARCHAR(32) NULL AFTER id_sujeito_relativo;
ALTER TABLE adverb_links ADD COLUMN id_frase INT NULL AFTER tag_id, ADD COLUMN id_sujeito_relativo INT NULL AFTER id_frase, ADD COLUMN tipo_elemento VARCHAR(32) NULL AFTER id_sujeito_relativo;
ALTER TABLE local_links ADD COLUMN id_frase INT NULL AFTER tag_id, ADD COLUMN id_sujeito_relativo INT NULL AFTER id_frase, ADD COLUMN tipo_elemento VARCHAR(32) NULL AFTER id_sujeito_relativo;
ALTER TABLE tempo_links ADD COLUMN id_frase INT NULL AFTER tag_id, ADD COLUMN id_sujeito_relativo INT NULL AFTER id_frase, ADD COLUMN tipo_elemento VARCHAR(32) NULL AFTER id_sujeito_relativo;

-- Relações sintáticas são específicas do card e não podem sobrescrever relações globais.
ALTER TABLE relacoes_taguineas ADD COLUMN id_card INT NULL AFTER id_user;
