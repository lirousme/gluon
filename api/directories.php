<?php
// Arquivo: directories.php
// Diretório: public_html/gluon/api/directories.php

/**
 * API DE DIRETÓRIOS
 * Pilar: Seguro e Rápido.
 * Nomes criptografados + Gerenciamento individual de Views + Ordenação (Sort)
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
        $stmt = $pdo->prepare("SELECT id, name_encrypted, parent_id, default_view, sort_order FROM directories WHERE user_id = ? AND parent_id IS NULL");
        $stmt->execute([$user_id]);
    } else {
        $stmt = $pdo->prepare("SELECT id, name_encrypted, parent_id, default_view, sort_order FROM directories WHERE user_id = ? AND parent_id = ?");
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
            'name' => Security::decryptData($dir['name_encrypted'])
        ];
    }

    // Ordena primeiro pela ordem escolhida pelo usuário, depois alfabeticamente
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

    if (empty($name)) {
        die(json_encode(['status' => 'error', 'message' => 'O nome não pode ser vazio.']));
    }

    $name_encrypted = Security::encryptData($name);

    // Coloca a nova pasta por último por padrão
    $stmtMax = $pdo->prepare("SELECT MAX(sort_order) as max_order FROM directories WHERE user_id = ? AND (parent_id = ? OR (parent_id IS NULL AND ? IS NULL))");
    $stmtMax->execute([$user_id, $parent_id, $parent_id]);
    $maxOrder = (int)$stmtMax->fetchColumn();
    $newOrder = $maxOrder + 1;

    $stmt = $pdo->prepare("INSERT INTO directories (user_id, parent_id, name_encrypted, default_view, sort_order) VALUES (?, ?, ?, ?, ?)");
    if ($stmt->execute([$user_id, $parent_id, $name_encrypted, $view, $newOrder])) {
        echo json_encode(['status' => 'success', 'message' => 'Diretório criado.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao criar diretório.']);
    }
}

elseif ($action === 'update') {
    $id = (int)($input['id'] ?? 0);
    $name = trim($input['name'] ?? '');
    $view = in_array($input['view'] ?? '', ['grid', 'list', 'kanban']) ? $input['view'] : 'grid';

    if (empty($name) || $id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'Dados inválidos.']));
    }

    $name_encrypted = Security::encryptData($name);
    
    $stmt = $pdo->prepare("UPDATE directories SET name_encrypted = ?, default_view = ? WHERE id = ? AND user_id = ?");
    if ($stmt->execute([$name_encrypted, $view, $id, $user_id])) {
        echo json_encode(['status' => 'success', 'message' => 'Diretório atualizado.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao atualizar.']);
    }
}

elseif ($action === 'reorder') {
    // Ação ultra-rápida para salvar a nova ordem no banco
    $order = $input['order'] ?? [];
    if (!is_array($order)) {
        die(json_encode(['status' => 'error', 'message' => 'Formato de ordem inválido.']));
    }

    try {
        // Usa transação para garantir integridade e velocidade máxima
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE directories SET sort_order = ? WHERE id = ? AND user_id = ?");
        
        foreach ($order as $index => $id) {
            $stmt->execute([$index, (int)$id, $user_id]);
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
