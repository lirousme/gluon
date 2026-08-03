ALTER TABLE mensagens
    ADD COLUMN is_recipient TINYINT(1) NOT NULL DEFAULT 0 AFTER imagem_encrypted;
