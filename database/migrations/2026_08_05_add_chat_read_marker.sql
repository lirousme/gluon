ALTER TABLE chats
    ADD COLUMN read_marker_message_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER chat_type,
    ADD INDEX idx_chats_read_marker_message (read_marker_message_id),
    ADD CONSTRAINT fk_chats_read_marker_message FOREIGN KEY (read_marker_message_id) REFERENCES mensagens(id) ON DELETE SET NULL;
