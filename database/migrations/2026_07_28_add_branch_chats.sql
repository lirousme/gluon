CREATE TABLE IF NOT EXISTS chats (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    titulo VARCHAR(120) NOT NULL DEFAULT 'Novo chat',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_chats_user_updated (user_id, updated_at),
    CONSTRAINT fk_chats_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mensagens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    chat_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    texto TEXT NULL,
    imagem_path VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_mensagens_chat_created (chat_id, created_at, id),
    INDEX idx_mensagens_user (user_id),
    CONSTRAINT fk_mensagens_chat FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE,
    CONSTRAINT fk_mensagens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_mensagens_conteudo CHECK (texto IS NOT NULL OR imagem_path IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
