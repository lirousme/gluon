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

try {
    if ($method === 'GET') {
        $chatId = isset($_GET['chat_id']) ? (int)$_GET['chat_id'] : 0;
        if ($chatId > 0) {
            $chat = branchChatsFind($pdo, $chatId, $userId);
            $stmt = $pdo->prepare(
                'SELECT m.id, m.texto, m.imagem_path, m.created_at
                 FROM chat_mensagens cm
                 INNER JOIN mensagens m ON m.id = cm.mensagem_id
                 WHERE cm.chat_id = :chat_id AND m.user_id = :user_id
                 ORDER BY cm.position ASC'
            );
            $stmt->execute([':chat_id' => $chatId, ':user_id' => $userId]);
            branchChatsRespond(['status' => 'success', 'data' => ['chat' => $chat, 'mensagens' => $stmt->fetchAll()]]);
        }

        $stmt = $pdo->prepare(
            'SELECT c.id, c.parent_chat_id, c.titulo, c.created_at, c.updated_at,
                    (SELECT m.texto FROM chat_mensagens cm INNER JOIN mensagens m ON m.id = cm.mensagem_id WHERE cm.chat_id = c.id ORDER BY cm.position DESC LIMIT 1) AS ultima_mensagem,
                    (SELECT COUNT(*) FROM chat_mensagens cm WHERE cm.chat_id = c.id) AS total_mensagens,
                    (SELECT COUNT(*) FROM chats child WHERE child.parent_chat_id = c.id AND child.user_id = c.user_id) AS total_branches
             FROM chats c WHERE c.user_id = :user_id
             ORDER BY c.updated_at DESC, c.id DESC'
        );
        $stmt->execute([':user_id' => $userId]);
        branchChatsRespond(['status' => 'success', 'data' => $stmt->fetchAll()]);
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

    $stmt = $pdo->prepare('INSERT INTO mensagens (user_id, texto, imagem_path) VALUES (:user_id, :texto, :imagem_path)');
    $stmt->execute([':user_id' => $userId, ':texto' => $text !== '' ? $text : null, ':imagem_path' => $imagePath]);
    $messageId = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('INSERT INTO chat_mensagens (chat_id, mensagem_id, position) SELECT :chat_id, :message_id, COALESCE(MAX(position), 0) + 1 FROM chat_mensagens WHERE chat_id = :position_chat_id');
    $stmt->execute([':chat_id' => $targetChatId, ':message_id' => $messageId, ':position_chat_id' => $targetChatId]);
    $pdo->prepare('UPDATE chats SET updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute([':id' => $targetChatId]);
    $stmt = $pdo->prepare('SELECT id, texto, imagem_path, created_at FROM mensagens WHERE id = :id');
    $stmt->execute([':id' => $messageId]);
    $message = $stmt->fetch();
    $pdo->commit();
    branchChatsRespond(['status' => 'success', 'data' => ['message' => $message, 'chat_id' => $targetChatId, 'branched' => $createBranch]], 201);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    branchChatsRespond(['status' => 'error', 'message' => 'Erro interno ao processar o chat.'], 500);
}
