ALTER TABLE chats
    ADD COLUMN chat_type INT NOT NULL DEFAULT 0 AFTER titulo;

ALTER TABLE chat_mensagens
    ADD COLUMN is_response TINYINT(1) NOT NULL DEFAULT 0 AFTER position;

