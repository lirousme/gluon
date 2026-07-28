ALTER TABLE chats
    ADD COLUMN parent_chat_id INT UNSIGNED NULL AFTER user_id,
    ADD INDEX idx_chats_parent (parent_chat_id),
    ADD CONSTRAINT fk_chats_parent FOREIGN KEY (parent_chat_id) REFERENCES chats(id) ON DELETE SET NULL;

CREATE TABLE chat_mensagens (
    chat_id INT UNSIGNED NOT NULL,
    mensagem_id BIGINT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    PRIMARY KEY (chat_id, mensagem_id),
    UNIQUE KEY uq_chat_mensagens_position (chat_id, position),
    CONSTRAINT fk_chat_mensagens_chat FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_mensagens_mensagem FOREIGN KEY (mensagem_id) REFERENCES mensagens(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO chat_mensagens (chat_id, mensagem_id, position)
SELECT chat_id, id, ROW_NUMBER() OVER (PARTITION BY chat_id ORDER BY created_at, id)
FROM mensagens;

ALTER TABLE mensagens
    DROP FOREIGN KEY fk_mensagens_chat,
    DROP INDEX idx_mensagens_chat_created,
    DROP COLUMN chat_id;
