<?php
// Arquivo: directories.php
// Diretório: public_html/gluon/api/directories.php

/**
 * API DE DIRETÓRIOS E ARQUIVOS
 * Pilar: Seguro e Rápido.
 * Atualizado para suportar o campo 'type' (0 = Pasta, 1 = Arquivo).
 */

require_once BASE_PATH . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Não autorizado. Faça login.']));
}

$pdo = Database::getConnection();
$user_id = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

if ($action === 'fetch') {
    $parent_id = isset($input['parent_id']) && $input['parent_id'] !== null ? (int)$input['parent_id'] : null;

    if ($parent_id === null) {
        $stmt = $pdo->prepare("SELECT id, type, name_encrypted, parent_id, default_view, new_item_position, sort_order, icon, icon_color_from, icon_color_to, cover_url_encrypted FROM directories WHERE user_id = ? AND parent_id IS NULL");
        $stmt->execute([$user_id]);
    } else {
        $stmt = $pdo->prepare("SELECT id, type, name_encrypted, parent_id, default_view, new_item_position, sort_order, icon, icon_color_from, icon_color_to, cover_url_encrypted FROM directories WHERE user_id = ? AND parent_id = ?");
        $stmt->execute([$user_id, $parent_id]);
    }
    
    $directories = $stmt->fetchAll();
    
    $response = [];
    foreach ($directories as $dir) {
        $response[] = [
            'id' => $dir['id'],
            'type' => (int)($dir['type'] ?? 0), // 0: Pasta, 1: Arquivo
            'parent_id' => $dir['parent_id'],
            'view' => $dir['default_view'] ?? 'grid',
            'new_item_position' => $dir['new_item_position'] ?? 'end',
            'sort_order' => (int)($dir['sort_order'] ?? 0),
            'name' => Security::decryptData($dir['name_encrypted']),
            'icon' => $dir['icon'] ?? 'fa-folder',
            'color_from' => $dir['icon_color_from'] ?? '#3b82f6',
            'color_to' => $dir['icon_color_to'] ?? '#6366f1',
            'cover_url' => !empty($dir['cover_url_encrypted']) ? Security::decryptData($dir['cover_url_encrypted']) : ''
        ];
    }

    usort($response, function($a, $b) {
        if ($a['sort_order'] === $b['sort_order']) {
            return strcasecmp($a['name'], $b['name']);
        }
        return $a['sort_order'] <=> $b['sort_order'];
    });

    echo json_encode(['status' => 'success', 'data' => $response]);
} 

elseif ($action === 'create') {
    $name = trim($input['name'] ?? '');
    $parent_id = isset($input['parent_id']) && $input['parent_id'] !== null ? (int)$input['parent_id'] : null;
    $type = isset($input['type']) ? (int)$input['type'] : 0; // Novo campo
    $view = in_array($input['view'] ?? '', ['grid', 'list', 'kanban']) ? $input['view'] : 'grid';
    $new_item_position = in_array($input['new_item_position'] ?? '', ['start', 'end']) ? $input['new_item_position'] : 'end';
    
    $icon = preg_match('/^fa-[a-z0-9-]+$/', $input['icon'] ?? '') ? $input['icon'] : ($type === 1 ? 'fa-file-code' : 'fa-folder');
    $color_from = preg_match('/^#[a-fA-F0-9]{6}$/', $input['color_from'] ?? '') ? $input['color_from'] : '#3b82f6';
    $color_to = preg_match('/^#[a-fA-F0-9]{6}$/', $input['color_to'] ?? '') ? $input['color_to'] : '#6366f1';
    $cover_url = trim($input['cover_url'] ?? '');

    if (empty($name)) {
        die(json_encode(['status' => 'error', 'message' => 'O nome não pode ser vazio.']));
    }

    $name_encrypted = Security::encryptData($name);
    $cover_url_encrypted = !empty($cover_url) ? Security::encryptData($cover_url) : null;

    if ($parent_id === null) {
        $stmtPref = $pdo->prepare("SELECT root_new_item_position FROM users WHERE id = ?");
        $stmtPref->execute([$user_id]);
        $parentPref = $stmtPref->fetchColumn() ?: 'end';
    } else {
        $stmtPref = $pdo->prepare("SELECT new_item_position FROM directories WHERE id = ? AND user_id = ?");
        $stmtPref->execute([$parent_id, $user_id]);
        $parentPref = $stmtPref->fetchColumn() ?: 'end';
    }

    if ($parentPref === 'start') {
        $stmtMin = $pdo->prepare("SELECT MIN(sort_order) FROM directories WHERE user_id = ? AND (parent_id = ? OR (parent_id IS NULL AND ? IS NULL))");
        $stmtMin->execute([$user_id, $parent_id, $parent_id]);
        $minOrder = $stmtMin->fetchColumn();
        $newOrder = ($minOrder !== null) ? (int)$minOrder - 1 : 0;
    } else {
        $stmtMax = $pdo->prepare("SELECT MAX(sort_order) FROM directories WHERE user_id = ? AND (parent_id = ? OR (parent_id IS NULL AND ? IS NULL))");
        $stmtMax->execute([$user_id, $parent_id, $parent_id]);
        $maxOrder = $stmtMax->fetchColumn();
        $newOrder = ($maxOrder !== null) ? (int)$maxOrder + 1 : 0;
    }

    $stmt = $pdo->prepare("INSERT INTO directories (user_id, parent_id, type, name_encrypted, default_view, new_item_position, sort_order, icon, icon_color_from, icon_color_to, cover_url_encrypted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmt->execute([$user_id, $parent_id, $type, $name_encrypted, $view, $new_item_position, $newOrder, $icon, $color_from, $color_to, $cover_url_encrypted])) {
        echo json_encode(['status' => 'success', 'message' => $type === 1 ? 'Arquivo criado.' : 'Diretório criado.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao criar item.']);
    }
}

elseif ($action === 'update') {
    $id = (int)($input['id'] ?? 0);
    $name = trim($input['name'] ?? '');
    $view = in_array($input['view'] ?? '', ['grid', 'list', 'kanban']) ? $input['view'] : 'grid';
    $new_item_position = in_array($input['new_item_position'] ?? '', ['start', 'end']) ? $input['new_item_position'] : 'end';
    
    $icon = preg_match('/^fa-[a-z0-9-]+$/', $input['icon'] ?? '') ? $input['icon'] : 'fa-folder';
    $color_from = preg_match('/^#[a-fA-F0-9]{6}$/', $input['color_from'] ?? '') ? $input['color_from'] : '#3b82f6';
    $color_to = preg_match('/^#[a-fA-F0-9]{6}$/', $input['color_to'] ?? '') ? $input['color_to'] : '#6366f1';
    $cover_url = trim($input['cover_url'] ?? '');

    if (empty($name) || $id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'Dados inválidos.']));
    }

    $name_encrypted = Security::encryptData($name);
    $cover_url_encrypted = !empty($cover_url) ? Security::encryptData($cover_url) : null;
    
    $stmt = $pdo->prepare("UPDATE directories SET name_encrypted = ?, default_view = ?, new_item_position = ?, icon = ?, icon_color_from = ?, icon_color_to = ?, cover_url_encrypted = ? WHERE id = ? AND user_id = ?");
    if ($stmt->execute([$name_encrypted, $view, $new_item_position, $icon, $color_from, $color_to, $cover_url_encrypted, $id, $user_id])) {
        echo json_encode(['status' => 'success', 'message' => 'Item atualizado.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao atualizar.']);
    }
}

elseif ($action === 'reorder') {
    // Mantido sem alterações lógicas (Otimizado)
    $order = $input['order'] ?? [];
    $has_parent_id = array_key_exists('parent_id', $input);
    $new_parent_id = $has_parent_id ? $input['parent_id'] : null;

    if (!is_array($order)) {
        die(json_encode(['status' => 'error', 'message' => 'Formato de ordem inválido.']));
    }

    try {
        $pdo->beginTransaction();
        if ($has_parent_id) {
            $stmt = $pdo->prepare("UPDATE directories SET sort_order = ?, parent_id = ? WHERE id = ? AND user_id = ?");
            foreach ($order as $index => $id) { $stmt->execute([$index, $new_parent_id, (int)$id, $user_id]); }
        } else {
            $stmt = $pdo->prepare("UPDATE directories SET sort_order = ? WHERE id = ? AND user_id = ?");
            foreach ($order as $index => $id) { $stmt->execute([$index, (int)$id, $user_id]); }
        }
        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Ordem atualizada.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Erro ao reordenar.']);
    }
}

elseif ($action === 'delete') {
    $id = (int)($input['id'] ?? 0);
    if ($id === 0) die(json_encode(['status' => 'error', 'message' => 'ID inválido.']));

    // Devido ao ON DELETE CASCADE na tabela files_code e diretorios filhos, 
    // deletar aqui apaga todo o rastro automaticamente no BD.
    $stmt = $pdo->prepare("DELETE FROM directories WHERE id = ? AND user_id = ?");
    if ($stmt->execute([$id, $user_id])) {
        echo json_encode(['status' => 'success', 'message' => 'Excluído com sucesso.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao excluir.']);
    }
}

else {
    echo json_encode(['status' => 'error', 'message' => 'Ação inválida.']);
}
?>
