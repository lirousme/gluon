-- Groups for Branch Chats.
-- tipo 1 = grupo de grupos; tipo 2 = grupo de mensagens.
CREATE TABLE IF NOT EXISTS grupo_chats (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    parent_id INT UNSIGNED NULL,
    nome VARCHAR(120) NOT NULL,
    tipo TINYINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_grupo_chats_user_parent (user_id, parent_id),
    INDEX idx_grupo_chats_user_tipo (user_id, tipo),
    CONSTRAINT fk_grupo_chats_parent FOREIGN KEY (parent_id) REFERENCES grupo_chats(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE chats
    ADD COLUMN id_grupo INT UNSIGNED NULL AFTER user_id,
    ADD INDEX idx_chats_id_grupo (id_grupo),
    ADD CONSTRAINT fk_chats_grupo FOREIGN KEY (id_grupo) REFERENCES grupo_chats(id) ON DELETE RESTRICT;

-- Existing chats are assigned by the API to the user's default "Chats" group
-- the first time Branch Chats is opened after this migration.
