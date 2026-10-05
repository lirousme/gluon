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

-- Create one root group and one message group for every existing user with chats.
INSERT INTO grupo_chats (user_id, parent_id, nome, tipo)
SELECT DISTINCT c.user_id, NULL, 'Grupos', 1
FROM chats c
WHERE NOT EXISTS (
    SELECT 1 FROM grupo_chats g
    WHERE g.user_id = c.user_id AND g.parent_id IS NULL
);

INSERT INTO grupo_chats (user_id, parent_id, nome, tipo)
SELECT root.user_id, root.id, 'Chats', 2
FROM grupo_chats root
WHERE root.parent_id IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM grupo_chats child
      WHERE child.user_id = root.user_id AND child.parent_id = root.id AND child.tipo = 2
  );

UPDATE chats c
INNER JOIN grupo_chats g ON g.user_id = c.user_id AND g.parent_id IS NOT NULL AND g.tipo = 2
SET c.id_grupo = g.id
WHERE c.id_grupo IS NULL;

ALTER TABLE chats
    MODIFY COLUMN id_grupo INT UNSIGNED NOT NULL;
