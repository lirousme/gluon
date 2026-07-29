<?php

require_once BASE_PATH . '/config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Não autorizado.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$pdo = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function branchChatsRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function branchChatsFind(PDO $pdo, int $chatId, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT c.id, c.parent_chat_id, c.titulo, c.created_at, c.updated_at,
                (SELECT COUNT(*) FROM chats child WHERE child.parent_chat_id = c.id AND child.user_id = c.user_id) AS total_branches
         FROM chats c WHERE c.id = :id AND c.user_id = :user_id LIMIT 1'
    );
    $stmt->execute([':id' => $chatId, ':user_id' => $userId]);
    $chat = $stmt->fetch();
    if (!$chat) {
        branchChatsRespond(['status' => 'error', 'message' => 'Chat não encontrado.'], 404);
    }
    return $chat;
}

/**
 * Descriptografa o texto de uma mensagem. O fallback temporário para texto puro
 * permite que registros criados antes da migração sejam lidos e recriptografados.
 */
function branchChatsDecryptMessageText(?string $encryptedText): ?string
{
    if ($encryptedText === null) {
        return null;
    }

    $decrypted = Security::decryptData($encryptedText);
    return $decrypted !== false ? $decrypted : $encryptedText;
}

function branchChatsDecryptMessages(PDO $pdo, array $messages): array
{
    $update = null;
    foreach ($messages as &$message) {
        $storedText = $message['texto_encrypted'] ?? null;
        $message['texto'] = branchChatsDecryptMessageText($storedText);
        unset($message['texto_encrypted']);

        // Migração gradual dos registros antigos, sem manter texto puro no banco.
        if ($storedText !== null && Security::decryptData($storedText) === false) {
            $update ??= $pdo->prepare(
                'UPDATE mensagens SET texto_encrypted = :texto_encrypted WHERE id = :id AND texto_encrypted = :texto_plain'
            );
            $update->execute([
                ':texto_encrypted' => Security::encryptData($storedText),
                ':id' => $message['id'],
                ':texto_plain' => $storedText,
            ]);
        }
    }
    unset($message);

    return $messages;
}

try {
    if ($method === 'GET') {
        $chatId = isset($_GET['chat_id']) ? (int)$_GET['chat_id'] : 0;
        if ($chatId > 0) {
            $chat = branchChatsFind($pdo, $chatId, $userId);
            $stmt = $pdo->prepare(
                'SELECT m.id, m.texto_encrypted, m.imagem_path, m.created_at
                 FROM chat_mensagens cm
                 INNER JOIN mensagens m ON m.id = cm.mensagem_id
                 WHERE cm.chat_id = :chat_id AND m.user_id = :user_id
                 ORDER BY cm.position ASC'
            );
            $stmt->execute([':chat_id' => $chatId, ':user_id' => $userId]);
            $messages = branchChatsDecryptMessages($pdo, $stmt->fetchAll());
            branchChatsRespond(['status' => 'success', 'data' => ['chat' => $chat, 'mensagens' => $messages]]);
        }

        $stmt = $pdo->prepare(
            'SELECT c.id, c.parent_chat_id, c.titulo, c.created_at, c.updated_at,
                    (SELECT m.texto_encrypted FROM chat_mensagens cm INNER JOIN mensagens m ON m.id = cm.mensagem_id WHERE cm.chat_id = c.id ORDER BY cm.position DESC LIMIT 1) AS ultima_mensagem_encrypted,
                    (SELECT COUNT(*) FROM chat_mensagens cm WHERE cm.chat_id = c.id) AS total_mensagens,
                    (SELECT COUNT(*) FROM chats child WHERE child.parent_chat_id = c.id AND child.user_id = c.user_id) AS total_branches
             FROM chats c WHERE c.user_id = :user_id
             ORDER BY c.updated_at DESC, c.id DESC'
        );
        $stmt->execute([':user_id' => $userId]);
        $chats = $stmt->fetchAll();
        foreach ($chats as &$chat) {
            $chat['ultima_mensagem'] = branchChatsDecryptMessageText($chat['ultima_mensagem_encrypted'] ?? null);
            unset($chat['ultima_mensagem_encrypted']);
        }
        unset($chat);
        branchChatsRespond(['status' => 'success', 'data' => $chats]);
    }

    if ($method !== 'POST') {
        branchChatsRespond(['status' => 'error', 'message' => 'Método não permitido.'], 405);
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = strpos($contentType, 'application/json') !== false
        ? (json_decode(file_get_contents('php://input'), true) ?: [])
        : $_POST;
    $action = trim((string)($input['action'] ?? ''));

    if ($action === 'create_chat') {
        $stmt = $pdo->prepare("INSERT INTO chats (user_id, titulo) VALUES (:user_id, DATE_FORMAT(CURRENT_TIMESTAMP, '%d/%m/%Y %H:%i'))");
        $stmt->execute([':user_id' => $userId]);
        $chatId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT titulo FROM chats WHERE id = :id');
        $stmt->execute([':id' => $chatId]);
        branchChatsRespond(['status' => 'success', 'data' => ['id' => $chatId, 'titulo' => $stmt->fetchColumn()]], 201);
    }

    if ($action === 'branch_message') {
        $sourceChatId = (int)($input['chat_id'] ?? 0);
        $messageId = (int)($input['message_id'] ?? 0);
        $branchMode = (string)($input['branch_mode'] ?? '');
        branchChatsFind($pdo, $sourceChatId, $userId);

        if (!in_array($branchMode, ['single', 'through'], true) || $messageId < 1) {
            branchChatsRespond(['status' => 'error', 'message' => 'Opção de branch inválida.'], 422);
        }

        $stmt = $pdo->prepare(
            'SELECT cm.position
             FROM chat_mensagens cm
             INNER JOIN mensagens m ON m.id = cm.mensagem_id
             WHERE cm.chat_id = :chat_id AND cm.mensagem_id = :message_id AND m.user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([':chat_id' => $sourceChatId, ':message_id' => $messageId, ':user_id' => $userId]);
        $selectedPosition = $stmt->fetchColumn();
        if ($selectedPosition === false) {
            branchChatsRespond(['status' => 'error', 'message' => 'Mensagem não encontrada neste chat.'], 404);
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO chats (user_id, parent_chat_id, titulo) VALUES (:user_id, :parent_id, DATE_FORMAT(CURRENT_TIMESTAMP, '%d/%m/%Y %H:%i'))");
        $stmt->execute([':user_id' => $userId, ':parent_id' => $sourceChatId]);
        $targetChatId = (int)$pdo->lastInsertId();

        if ($branchMode === 'single') {
            $stmt = $pdo->prepare('INSERT INTO chat_mensagens (chat_id, mensagem_id, position) VALUES (:chat_id, :message_id, 1)');
            $stmt->execute([':chat_id' => $targetChatId, ':message_id' => $messageId]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO chat_mensagens (chat_id, mensagem_id, position)
                 SELECT :target_id, mensagem_id, position
                 FROM chat_mensagens
                 WHERE chat_id = :source_id AND position <= :selected_position
                 ORDER BY position'
            );
            $stmt->execute([':target_id' => $targetChatId, ':source_id' => $sourceChatId, ':selected_position' => $selectedPosition]);
        }

        $pdo->commit();
        branchChatsRespond(['status' => 'success', 'data' => ['chat_id' => $targetChatId, 'branch_mode' => $branchMode]], 201);
    }

    if ($action !== 'send_message') {
        branchChatsRespond(['status' => 'error', 'message' => 'Ação inválida.'], 422);
    }

    $sourceChatId = (int)($input['chat_id'] ?? 0);
    branchChatsFind($pdo, $sourceChatId, $userId);
    $createBranch = filter_var($input['create_branch'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $text = trim((string)($input['texto'] ?? ''));
    if (mb_strlen($text) > 10000) {
        branchChatsRespond(['status' => 'error', 'message' => 'A mensagem deve ter no máximo 10.000 caracteres.'], 422);
    }

    $imagePath = null;
    $image = $_FILES['imagem'] ?? null;
    if ($image && ($image['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ($image['error'] !== UPLOAD_ERR_OK || $image['size'] > 8 * 1024 * 1024) {
            branchChatsRespond(['status' => 'error', 'message' => 'Não foi possível enviar a imagem (limite de 8 MB).'], 422);
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($image['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime])) {
            branchChatsRespond(['status' => 'error', 'message' => 'Formato inválido. Use JPG, PNG, GIF ou WebP.'], 422);
        }
        $relativeDirectory = 'uploads/branch_chats/' . $userId;
        $directory = BASE_PATH . '/' . $relativeDirectory;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível preparar o diretório de imagens.');
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        if (!move_uploaded_file($image['tmp_name'], $directory . '/' . $filename)) {
            throw new RuntimeException('Não foi possível salvar a imagem.');
        }
        $imagePath = '/' . $relativeDirectory . '/' . $filename;
    }

    if ($text === '' && $imagePath === null) {
        branchChatsRespond(['status' => 'error', 'message' => 'Escreva uma mensagem ou adicione uma imagem.'], 422);
    }

    $pdo->beginTransaction();
    $targetChatId = $sourceChatId;
    if ($createBranch) {
        $stmt = $pdo->prepare("INSERT INTO chats (user_id, parent_chat_id, titulo) VALUES (:user_id, :parent_id, DATE_FORMAT(CURRENT_TIMESTAMP, '%d/%m/%Y %H:%i'))");
        $stmt->execute([':user_id' => $userId, ':parent_id' => $sourceChatId]);
        $targetChatId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('INSERT INTO chat_mensagens (chat_id, mensagem_id, position) SELECT :target_id, mensagem_id, position FROM chat_mensagens WHERE chat_id = :source_id');
        $stmt->execute([':target_id' => $targetChatId, ':source_id' => $sourceChatId]);
    }

    $encryptedText = $text !== '' ? Security::encryptData($text) : null;
    $stmt = $pdo->prepare('INSERT INTO mensagens (user_id, texto_encrypted, imagem_path) VALUES (:user_id, :texto_encrypted, :imagem_path)');
    $stmt->execute([':user_id' => $userId, ':texto_encrypted' => $encryptedText, ':imagem_path' => $imagePath]);
    $messageId = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('INSERT INTO chat_mensagens (chat_id, mensagem_id, position) SELECT :chat_id, :message_id, COALESCE(MAX(position), 0) + 1 FROM chat_mensagens WHERE chat_id = :position_chat_id');
    $stmt->execute([':chat_id' => $targetChatId, ':message_id' => $messageId, ':position_chat_id' => $targetChatId]);
    $pdo->prepare('UPDATE chats SET updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute([':id' => $targetChatId]);
    $stmt = $pdo->prepare('SELECT id, imagem_path, created_at FROM mensagens WHERE id = :id');
    $stmt->execute([':id' => $messageId]);
    $message = $stmt->fetch();
    $message['texto'] = $text !== '' ? $text : null;
    $pdo->commit();
    branchChatsRespond(['status' => 'success', 'data' => ['message' => $message, 'chat_id' => $targetChatId, 'branched' => $createBranch]], 201);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    branchChatsRespond(['status' => 'error', 'message' => 'Erro interno ao processar o chat.'], 500);
}
