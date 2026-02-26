<?php
// Arquivo: editor.php
// Diretório: public_html/gluon/api/editor.php

/**
 * API DO EDITOR DE CÓDIGO
 * Pilar: Seguro, Modular e Separação de Responsabilidades.
 * Lida exclusivamente com salvar e buscar o texto criptografado.
 */

require_once BASE_PATH . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Não autorizado. Faça login.']));
}

$pdo = Database::getConnection();
$user_id = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

if ($action === 'get_content') {
    $dir_id = (int)($input['id'] ?? 0);
    if ($dir_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID inválido.']));

    // 1. Validar propriedade (IDOR Check) e garantir que é do tipo 1 (Arquivo)
    $stmt = $pdo->prepare("SELECT name_encrypted FROM directories WHERE id = ? AND user_id = ? AND type = 1");
    $stmt->execute([$dir_id, $user_id]);
    $dir = $stmt->fetch();

    if (!$dir) {
        die(json_encode(['status' => 'error', 'message' => 'Arquivo não encontrado ou sem permissão.']));
    }

    // 2. Buscar o conteúdo na tabela files_code
    $stmt = $pdo->prepare("SELECT content_encrypted, language FROM files_code WHERE directory_id = ?");
    $stmt->execute([$dir_id]);
    $file = $stmt->fetch();

    $content = '';
    $language = 'javascript';

    if ($file && !empty($file['content_encrypted'])) {
        $content = Security::decryptData($file['content_encrypted']);
        $language = $file['language'] ?? 'javascript';
    }

    echo json_encode([
        'status' => 'success', 
        'data' => [
            'name' => Security::decryptData($dir['name_encrypted']),
            'content' => $content,
            'language' => $language
        ]
    ]);
}

elseif ($action === 'save_content') {
    $dir_id = (int)($input['id'] ?? 0);
    $content = $input['content'] ?? '';
    $language = $input['language'] ?? 'javascript';

    if ($dir_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID inválido.']));

    // 1. Validar propriedade (IDOR Check)
    $stmt = $pdo->prepare("SELECT id FROM directories WHERE id = ? AND user_id = ? AND type = 1");
    $stmt->execute([$dir_id, $user_id]);
    if (!$stmt->fetch()) {
        die(json_encode(['status' => 'error', 'message' => 'Arquivo não encontrado ou sem permissão.']));
    }

    // 2. Criptografar código puro
    $encrypted_content = Security::encryptData($content);

    // 3. Upsert (Inserir se não existir, Atualizar se existir)
    $stmt = $pdo->prepare("SELECT id FROM files_code WHERE directory_id = ?");
    $stmt->execute([$dir_id]);
    
    if ($stmt->fetch()) {
        // Atualiza
        $update = $pdo->prepare("UPDATE files_code SET content_encrypted = ?, language = ? WHERE directory_id = ?");
        $update->execute([$encrypted_content, $language, $dir_id]);
    } else {
        // Insere novo
        $insert = $pdo->prepare("INSERT INTO files_code (directory_id, content_encrypted, language) VALUES (?, ?, ?)");
        $insert->execute([$dir_id, $encrypted_content, $language]);
    }

    echo json_encode(['status' => 'success', 'message' => 'Código salvo com segurança.']);
}

else {
    echo json_encode(['status' => 'error', 'message' => 'Ação inválida.']);
}
?>
