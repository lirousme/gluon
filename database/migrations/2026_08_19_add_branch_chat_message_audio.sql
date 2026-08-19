ALTER TABLE mensagens
    ADD COLUMN audio_encrypted LONGTEXT NULL AFTER imagem_encrypted,
    ADD COLUMN has_audio TINYINT(1) NOT NULL DEFAULT 0 AFTER audio_encrypted,
    ADD COLUMN audio_language VARCHAR(10) NULL AFTER has_audio,
    ADD COLUMN audio_variant VARCHAR(12) NULL AFTER audio_language,
    ADD COLUMN color_variant VARCHAR(12) NOT NULL DEFAULT 'green' AFTER is_recipient;
