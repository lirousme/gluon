ALTER TABLE chats
    ADD COLUMN preserve_on_parent_delete TINYINT(1) NOT NULL DEFAULT 0 AFTER parent_chat_id;

-- Mark drills created before this column existed. Their third and fourth
-- messages are the first two messages shared from the source chat.
UPDATE chats AS drill
INNER JOIN chat_mensagens AS drill_source_pt
    ON drill_source_pt.chat_id = drill.id AND drill_source_pt.position = 3
INNER JOIN chat_mensagens AS drill_source_en
    ON drill_source_en.chat_id = drill.id AND drill_source_en.position = 4
INNER JOIN chat_mensagens AS source_pt
    ON source_pt.chat_id = drill.parent_chat_id AND source_pt.position = 1
INNER JOIN chat_mensagens AS source_en
    ON source_en.chat_id = drill.parent_chat_id AND source_en.position = 2
SET drill.preserve_on_parent_delete = 1
WHERE drill_source_pt.mensagem_id = source_pt.mensagem_id
  AND drill_source_en.mensagem_id = source_en.mensagem_id;
