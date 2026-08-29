ALTER TABLE chats
    ADD COLUMN is_open INT NOT NULL DEFAULT 1 CHECK (is_open IN (0, 1)) AFTER read_marker_message_id;
