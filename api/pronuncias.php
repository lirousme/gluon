<?php
// Arquivo: pronuncias.php
// Diretório: public_html/gluon/api/pronuncias.php

require_once BASE_PATH . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Não autorizado. Faça login.']));
}

if ((int)$_SESSION['user_id'] !== 1) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Acesso restrito ao administrador.']));
}

$pdo = Database::getConnection();
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pronuncias (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        language VARCHAR(10) NOT NULL,
        source_text VARCHAR(255) NOT NULL,
        target_text VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_language_source (language, source_text),
        INDEX idx_language (language)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['status' => 'error', 'message' => 'Falha ao garantir estrutura da tabela pronuncias.']));
}

function normalizePronunciationLanguage($value) {
    $allowed = ['pt-BR', 'en-US', 'en-GB'];
    return in_array($value, $allowed, true) ? $value : null;
}

if ($action === 'list') {
    $stmt = $pdo->query("SELECT id, language, source_text, target_text, created_at, updated_at FROM pronuncias ORDER BY language ASC, source_text ASC");
    echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
    exit;
}

if ($action === 'create') {
    $language = normalizePronunciationLanguage($input['language'] ?? '');
    $source = trim($input['source_text'] ?? '');
    $target = trim($input['target_text'] ?? '');

    if (!$language || $source === '' || $target === '') {
        die(json_encode(['status' => 'error', 'message' => 'Dados inválidos.']));
    }

    $stmt = $pdo->prepare("INSERT INTO pronuncias (language, source_text, target_text) VALUES (?, ?, ?)");
    $stmt->execute([$language, $source, $target]);
    echo json_encode(['status' => 'success', 'message' => 'Pronúncia cadastrada com sucesso.']);
    exit;
}

if ($action === 'update') {
    $id = (int)($input['id'] ?? 0);
    $language = normalizePronunciationLanguage($input['language'] ?? '');
    $source = trim($input['source_text'] ?? '');
    $target = trim($input['target_text'] ?? '');

    if ($id <= 0 || !$language || $source === '' || $target === '') {
        die(json_encode(['status' => 'error', 'message' => 'Dados inválidos.']));
    }

    $stmt = $pdo->prepare("UPDATE pronuncias SET language = ?, source_text = ?, target_text = ? WHERE id = ?");
    $stmt->execute([$language, $source, $target, $id]);
    echo json_encode(['status' => 'success', 'message' => 'Pronúncia atualizada com sucesso.']);
    exit;
}

if ($action === 'delete') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID inválido.']));
    }

    $stmt = $pdo->prepare("DELETE FROM pronuncias WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['status' => 'success', 'message' => 'Pronúncia removida com sucesso.']);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Ação inválida.']);
