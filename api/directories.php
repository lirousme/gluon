<?php
// Arquivo: directories.php
// Diretório: public_html/gluon/api/directories.php

/**
 * API DE DIRETÓRIOS
 * Pilar: Seguro e Rápido.
 * Nomes e Capas criptografadas + Gerenciamento Estético + Ordenação (Sort)
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
        $stmt = $pdo->prepare("SELECT id, name_encrypted, parent_id, default_view, sort_order, icon, icon_color_from, icon_color_to, cover_url_encrypted FROM directories WHERE user_id = ? AND parent_id IS NULL");
        $stmt->execute([$user_id]);
    } else {
        $stmt = $pdo->prepare("SELECT id, name_encrypted, parent_id, default_view, sort_order, icon, icon_color_from, icon_color_to, cover_url_encrypted FROM directories WHERE user_id = ? AND parent_id = ?");
        $stmt->execute([$user_id, $parent_id]);
    }
    
    $directories = $stmt->fetchAll();
    
    $response = [];
    foreach ($directories as $dir) {
        $response[] = [
            'id' => $dir['id'],
            'parent_id' => $dir['parent_id'],
            'view' => $dir['default_view'] ?? 'grid',
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
    $view = in_array($input['view'] ?? '', ['grid', 'list', 'kanban']) ? $input['view'] : 'grid';
    
    // Dados Estéticos (Validação Básica)
    $icon = preg_match('/^fa-[a-z0-9-]+$/', $input['icon'] ?? '') ? $input['icon'] : 'fa-folder';
    $color_from = preg_match('/^#[a-fA-F0-9]{6}$/', $input['color_from'] ?? '') ? $input['color_from'] : '#3b82f6';
    $color_to = preg_match('/^#[a-fA-F0-9]{6}$/', $input['color_to'] ?? '') ? $input['color_to'] : '#6366f1';
    $cover_url = trim($input['cover_url'] ?? '');

    if (empty($name)) {
        die(json_encode(['status' => 'error', 'message' => 'O nome não pode ser vazio.']));
    }

    $name_encrypted = Security::encryptData($name);
    $cover_url_encrypted = !empty($cover_url) ? Security::encryptData($cover_url) : null;

    $stmtMax = $pdo->prepare("SELECT MAX(sort_order) as max_order FROM directories WHERE user_id = ? AND (parent_id = ? OR (parent_id IS NULL AND ? IS NULL))");
    $stmtMax->execute([$user_id, $parent_id, $parent_id]);
    $maxOrder = (int)$stmtMax->fetchColumn();
    $newOrder = $maxOrder + 1;

    $stmt = $pdo->prepare("INSERT INTO directories (user_id, parent_id, name_encrypted, default_view, sort_order, icon, icon_color_from, icon_color_to, cover_url_encrypted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmt->execute([$user_id, $parent_id, $name_encrypted, $view, $newOrder, $icon, $color_from, $color_to, $cover_url_encrypted])) {
        echo json_encode(['status' => 'success', 'message' => 'Diretório criado.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao criar diretório.']);
    }
}

elseif ($action === 'update') {
    $id = (int)($input['id'] ?? 0);
    $name = trim($input['name'] ?? '');
    $view = in_array($input['view'] ?? '', ['grid', 'list', 'kanban']) ? $input['view'] : 'grid';
    
    $icon = preg_match('/^fa-[a-z0-9-]+$/', $input['icon'] ?? '') ? $input['icon'] : 'fa-folder';
    $color_from = preg_match('/^#[a-fA-F0-9]{6}$/', $input['color_from'] ?? '') ? $input['color_from'] : '#3b82f6';
    $color_to = preg_match('/^#[a-fA-F0-9]{6}$/', $input['color_to'] ?? '') ? $input['color_to'] : '#6366f1';
    $cover_url = trim($input['cover_url'] ?? '');

    if (empty($name) || $id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'Dados inválidos.']));
    }

    $name_encrypted = Security::encryptData($name);
    $cover_url_encrypted = !empty($cover_url) ? Security::encryptData($cover_url) : null;
    
    $stmt = $pdo->prepare("UPDATE directories SET name_encrypted = ?, default_view = ?, icon = ?, icon_color_from = ?, icon_color_to = ?, cover_url_encrypted = ? WHERE id = ? AND user_id = ?");
    if ($stmt->execute([$name_encrypted, $view, $icon, $color_from, $color_to, $cover_url_encrypted, $id, $user_id])) {
        echo json_encode(['status' => 'success', 'message' => 'Diretório atualizado.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao atualizar.']);
    }
}

elseif ($action === 'reorder') {
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
            foreach ($order as $index => $id) {
                $stmt->execute([$index, $new_parent_id, (int)$id, $user_id]);
            }
        } else {
            $stmt = $pdo->prepare("UPDATE directories SET sort_order = ? WHERE id = ? AND user_id = ?");
            foreach ($order as $index => $id) {
                $stmt->execute([$index, (int)$id, $user_id]);
            }
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

    if ($id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID inválido.']));
    }

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
