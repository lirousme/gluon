<?php
// Arquivo: adjacency.php
// Diretório: public_html/gluon/api/adjacency.php

require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Não autorizado.']));
}

$pdo = Database::getConnection();
$user_id = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// Função auxiliar de segurança
function verifyOwnership($pdo, $dir_id, $user_id) {
    $stmt = $pdo->prepare("SELECT id, name_encrypted FROM directories WHERE id = ? AND user_id = ? AND type = 5");
    $stmt->execute([$dir_id, $user_id]);
    return $stmt->fetch();
}

if ($action === 'fetch') {
    $dir_id = (int)($input['directory_id'] ?? 0);
    $dir = verifyOwnership($pdo, $dir_id, $user_id);
    
    if (!$dir) die(json_encode(['status' => 'error', 'message' => 'Lista não encontrada.']));

    $stmt = $pdo->prepare("SELECT * FROM adjacency_items WHERE directory_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$dir_id]);
    
    echo json_encode([
        'status' => 'success', 
        'name' => Security::decryptData($dir['name_encrypted']),
        'data' => $stmt->fetchAll()
    ]);
}

elseif ($action === 'add') {
    $dir_id = (int)($input['directory_id'] ?? 0);
    $parent_id = isset($input['parent_id']) && $input['parent_id'] !== '' ? (int)$input['parent_id'] : null;
    $label = trim($input['label'] ?? '');
    $division_type = trim($input['division_type'] ?? '');

    if (!verifyOwnership($pdo, $dir_id, $user_id) || empty($label)) {
        die(json_encode(['status' => 'error']));
    }

    $stmt = $pdo->prepare("INSERT INTO adjacency_items (directory_id, parent_id, label, division_type) VALUES (?, ?, ?, ?)");
    $stmt->execute([$dir_id, $parent_id, $label, $division_type]);
    
    echo json_encode(['status' => 'success', 'id' => $pdo->lastInsertId()]);
}

elseif ($action === 'toggle') {
    $dir_id = (int)($input['directory_id'] ?? 0);
    $item_id = (int)($input['item_id'] ?? 0);
    $is_completed = (int)($input['is_completed'] ?? 0);

    if (!verifyOwnership($pdo, $dir_id, $user_id)) die(json_encode(['status' => 'error']));

    $stmt = $pdo->prepare("UPDATE adjacency_items SET is_completed = ? WHERE id = ? AND directory_id = ?");
    $stmt->execute([$is_completed, $item_id, $dir_id]);
    
    echo json_encode(['status' => 'success']);
}

elseif ($action === 'delete') {
    $dir_id = (int)($input['directory_id'] ?? 0);
    $item_id = (int)($input['item_id'] ?? 0);

    if (!verifyOwnership($pdo, $dir_id, $user_id)) die(json_encode(['status' => 'error']));

    $stmt = $pdo->prepare("DELETE FROM adjacency_items WHERE id = ? AND directory_id = ?");
    $stmt->execute([$item_id, $dir_id]);
    
    echo json_encode(['status' => 'success']);
}
?>
