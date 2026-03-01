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

    // Calcula o sort_order para colocar no final
    $stmtOrder = $pdo->prepare("SELECT MAX(sort_order) FROM adjacency_items WHERE directory_id = ? AND (parent_id = ? OR (parent_id IS NULL AND ? IS NULL))");
    $stmtOrder->execute([$dir_id, $parent_id, $parent_id]);
    $maxOrder = $stmtOrder->fetchColumn();
    $newOrder = ($maxOrder !== null) ? (int)$maxOrder + 1 : 0;

    $stmt = $pdo->prepare("INSERT INTO adjacency_items (directory_id, parent_id, label, division_type, sort_order) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$dir_id, $parent_id, $label, $division_type, $newOrder]);
    
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

elseif ($action === 'reorder') {
    $dir_id = (int)($input['directory_id'] ?? 0);
    $items = $input['items'] ?? [];

    if (!verifyOwnership($pdo, $dir_id, $user_id) || empty($items)) {
        die(json_encode(['status' => 'error']));
    }

    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("UPDATE adjacency_items SET parent_id = ?, sort_order = ? WHERE id = ? AND directory_id = ?");
        
        foreach ($items as $item) {
            $item_id = (int)$item['id'];
            $parent_id = isset($item['parent_id']) && $item['parent_id'] !== '' && $item['parent_id'] !== null ? (int)$item['parent_id'] : null;
            $sort_order = (int)$item['sort_order'];
            
            $stmt->execute([$parent_id, $sort_order, $item_id, $dir_id]);
        }
        
        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Erro interno ao reordenar.']);
    }
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
