-- Branches that share messages with their source must survive source deletion.
UPDATE chats AS branch
INNER JOIN chat_mensagens AS branch_message
    ON branch_message.chat_id = branch.id
INNER JOIN chat_mensagens AS source_message
    ON source_message.chat_id = branch.parent_chat_id
   AND source_message.mensagem_id = branch_message.mensagem_id
SET branch.preserve_on_parent_delete = 1
WHERE branch.parent_chat_id IS NOT NULL;
