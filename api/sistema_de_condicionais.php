<?php
// Arquivo: sistema_de_condicionais.php
// Diretório: public_html/gluon/api/sistema_de_condicionais.php

require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Não autorizado.']));
}

$pdo = Database::getConnection();
$user_id = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

function verifyConditionalDirectoryOwnership($pdo, $dir_id, $user_id) {
    $stmt = $pdo->prepare("SELECT id, name_encrypted FROM directories WHERE id = ? AND user_id = ? AND type = 6");
    $stmt->execute([$dir_id, $user_id]);
    return $stmt->fetch();
}

function canToggleConditionalItem($pdo, $item_id) {
    $stmt = $pdo->prepare("SELECT conditional_item_id FROM conditional_items WHERE id = ?");
    $stmt->execute([$item_id]);
    $conditionalId = $stmt->fetchColumn();

    if (!$conditionalId) {
        return [true, null];
    }

    $stmtCond = $pdo->prepare("SELECT is_completed, label FROM conditional_items WHERE id = ?");
    $stmtCond->execute([$conditionalId]);
    $cond = $stmtCond->fetch();

    if (!$cond) {
        return [true, null];
    }

    if ((int)$cond['is_completed'] === 1) {
        return [true, null];
    }

    return [false, $cond['label']];
}

if ($action === 'fetch') {
    $dir_id = (int)($input['directory_id'] ?? 0);
    $dir = verifyConditionalDirectoryOwnership($pdo, $dir_id, $user_id);

    if (!$dir) die(json_encode(['status' => 'error', 'message' => 'Sistema de condicional não encontrado.']));

    $stmt = $pdo->prepare("SELECT * FROM conditional_items WHERE directory_id = ? ORDER BY sort_order ASC, id ASC");
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

    if (!verifyConditionalDirectoryOwnership($pdo, $dir_id, $user_id) || empty($label)) {
        die(json_encode(['status' => 'error', 'message' => 'Dados inválidos.']));
    }

    $stmtOrder = $pdo->prepare("SELECT MAX(sort_order) FROM conditional_items WHERE directory_id = ? AND (parent_id = ? OR (parent_id IS NULL AND ? IS NULL))");
    $stmtOrder->execute([$dir_id, $parent_id, $parent_id]);
    $maxOrder = $stmtOrder->fetchColumn();
    $newOrder = ($maxOrder !== null) ? (int)$maxOrder + 1 : 0;

    $stmt = $pdo->prepare("INSERT INTO conditional_items (directory_id, parent_id, label, sort_order) VALUES (?, ?, ?, ?)");
    $stmt->execute([$dir_id, $parent_id, $label, $newOrder]);

    echo json_encode(['status' => 'success', 'id' => $pdo->lastInsertId()]);
}

elseif ($action === 'toggle') {
    $dir_id = (int)($input['directory_id'] ?? 0);
    $item_id = (int)($input['item_id'] ?? 0);
    $is_completed = (int)($input['is_completed'] ?? 0);

    if (!verifyConditionalDirectoryOwnership($pdo, $dir_id, $user_id)) die(json_encode(['status' => 'error']));

    if ($is_completed === 1) {
        [$canToggle, $conditionalLabel] = canToggleConditionalItem($pdo, $item_id);
        if (!$canToggle) {
            die(json_encode([
                'status' => 'error',
                'message' => 'Conclua primeiro a condicional: "' . $conditionalLabel . '".'
            ]));
        }
    }

    $stmt = $pdo->prepare("UPDATE conditional_items SET is_completed = ? WHERE id = ? AND directory_id = ?");
    $stmt->execute([$is_completed, $item_id, $dir_id]);

    echo json_encode(['status' => 'success']);
}

elseif ($action === 'set_conditional') {
    $dir_id = (int)($input['directory_id'] ?? 0);
    $item_id = (int)($input['item_id'] ?? 0);
    $conditional_item_id = isset($input['conditional_item_id']) && $input['conditional_item_id'] !== ''
        ? (int)$input['conditional_item_id']
        : null;

    if (!verifyConditionalDirectoryOwnership($pdo, $dir_id, $user_id)) {
        die(json_encode(['status' => 'error', 'message' => 'Sem acesso.']));
    }

    if ($conditional_item_id === $item_id) {
        die(json_encode(['status' => 'error', 'message' => 'Um item não pode ser condicional de si mesmo.']));
    }

    if ($conditional_item_id !== null) {
        $stmtCheck = $pdo->prepare("SELECT id FROM conditional_items WHERE id = ? AND directory_id = ?");
        $stmtCheck->execute([$conditional_item_id, $dir_id]);
        if (!$stmtCheck->fetchColumn()) {
            die(json_encode(['status' => 'error', 'message' => 'Condicional inválida.']));
        }
    }

    $stmtCheckItem = $pdo->prepare("SELECT id FROM conditional_items WHERE id = ? AND directory_id = ?");
    $stmtCheckItem->execute([$item_id, $dir_id]);
    if (!$stmtCheckItem->fetchColumn()) {
        die(json_encode(['status' => 'error', 'message' => 'Item inválido.']));
    }

    $stmt = $pdo->prepare("UPDATE conditional_items SET conditional_item_id = ? WHERE id = ? AND directory_id = ?");
    $stmt->execute([$conditional_item_id, $item_id, $dir_id]);

    echo json_encode(['status' => 'success']);
}

elseif ($action === 'reorder') {
    $dir_id = (int)($input['directory_id'] ?? 0);
    $items = $input['items'] ?? [];

    if (!verifyConditionalDirectoryOwnership($pdo, $dir_id, $user_id) || empty($items)) {
        die(json_encode(['status' => 'error']));
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("UPDATE conditional_items SET parent_id = ?, sort_order = ? WHERE id = ? AND directory_id = ?");

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

    if (!verifyConditionalDirectoryOwnership($pdo, $dir_id, $user_id)) die(json_encode(['status' => 'error']));

    $pdo->prepare("UPDATE conditional_items SET conditional_item_id = NULL WHERE conditional_item_id = ? AND directory_id = ?")
        ->execute([$item_id, $dir_id]);

    $stmt = $pdo->prepare("DELETE FROM conditional_items WHERE id = ? AND directory_id = ?");
    $stmt->execute([$item_id, $dir_id]);

    echo json_encode(['status' => 'success']);
}
else {
    echo json_encode(['status' => 'error', 'message' => 'Ação inválida.']);
}
?>
