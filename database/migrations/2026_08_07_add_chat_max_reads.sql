ALTER TABLE chats
    ADD COLUMN `max` INT NULL DEFAULT 20 AFTER chat_type,
    DROP FOREIGN KEY fk_chats_parent,
    ADD CONSTRAINT fk_chats_parent FOREIGN KEY (parent_chat_id) REFERENCES chats(id) ON DELETE CASCADE;
