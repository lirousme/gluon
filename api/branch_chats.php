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
    $stmt = $pdo->prepare('SELECT id, titulo, created_at, updated_at FROM chats WHERE id = :id AND user_id = :user_id LIMIT 1');
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
                'SELECT id, chat_id, texto, imagem_path, created_at
                 FROM mensagens
                 WHERE chat_id = :chat_id AND user_id = :user_id
                 ORDER BY created_at ASC, id ASC'
            );
            $stmt->execute([':chat_id' => $chatId, ':user_id' => $userId]);
            branchChatsRespond(['status' => 'success', 'data' => ['chat' => $chat, 'mensagens' => $stmt->fetchAll()]]);
        }

        $stmt = $pdo->prepare(
            'SELECT c.id, c.titulo, c.created_at, c.updated_at,
                    (SELECT m.texto FROM mensagens m WHERE m.chat_id = c.id ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS ultima_mensagem,
                    (SELECT COUNT(*) FROM mensagens m WHERE m.chat_id = c.id) AS total_mensagens
             FROM chats c
             WHERE c.user_id = :user_id
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
        $title = trim((string)($input['titulo'] ?? ''));
        if ($title === '') {
            $title = 'Novo chat';
        }
        if (mb_strlen($title) > 120) {
            branchChatsRespond(['status' => 'error', 'message' => 'O título deve ter no máximo 120 caracteres.'], 422);
        }
        $stmt = $pdo->prepare('INSERT INTO chats (user_id, titulo) VALUES (:user_id, :titulo)');
        $stmt->execute([':user_id' => $userId, ':titulo' => $title]);
        branchChatsRespond(['status' => 'success', 'data' => ['id' => (int)$pdo->lastInsertId(), 'titulo' => $title]], 201);
    }

    if ($action !== 'send_message') {
        branchChatsRespond(['status' => 'error', 'message' => 'Ação inválida.'], 422);
    }

    $chatId = (int)($input['chat_id'] ?? 0);
    branchChatsFind($pdo, $chatId, $userId);
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
    $stmt = $pdo->prepare('INSERT INTO mensagens (chat_id, user_id, texto, imagem_path) VALUES (:chat_id, :user_id, :texto, :imagem_path)');
    $stmt->execute([
        ':chat_id' => $chatId,
        ':user_id' => $userId,
        ':texto' => $text !== '' ? $text : null,
        ':imagem_path' => $imagePath
    ]);
    $messageId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE chats SET updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id')
        ->execute([':id' => $chatId, ':user_id' => $userId]);
    $stmt = $pdo->prepare('SELECT id, chat_id, texto, imagem_path, created_at FROM mensagens WHERE id = :id');
    $stmt->execute([':id' => $messageId]);
    $message = $stmt->fetch();
    $pdo->commit();
    branchChatsRespond(['status' => 'success', 'data' => $message], 201);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    branchChatsRespond(['status' => 'error', 'message' => 'Erro interno ao processar o chat.'], 500);
}
