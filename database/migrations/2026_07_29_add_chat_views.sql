CREATE TABLE chat_views (
    chat_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    view_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_viewed_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (chat_id, user_id),
    INDEX idx_chat_views_user_available (user_id, last_viewed_at),
    CONSTRAINT fk_chat_views_chat FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_views_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
