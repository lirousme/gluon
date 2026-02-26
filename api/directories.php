<?php
// Arquivo: directories.php
// Diretório: public_html/gluon/api/directories.php

/**
 * API DE DIRETÓRIOS E ARQUIVOS
 * Pilar: Seguro, Rápido e Escalável.
 * Suporta Árvores de Pastas, Código, Agendas e Portais (Atalhos Dinâmicos).
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

// =========================================================================
// FUNÇÃO HELPER: DUPLICAR ÁRVORE DE DIRETÓRIOS
// =========================================================================
function duplicateDirectoryTree($source_id, $target_parent_id, $user_id, $pdo, $is_top_level = true) {
    $stmt = $pdo->prepare("SELECT * FROM directories WHERE id = ? AND user_id = ?");
    $stmt->execute([$source_id, $user_id]);
    $sourceDir = $stmt->fetch();
    if (!$sourceDir) return false;

    $newNameEnc = $sourceDir['name_encrypted'];
    
    if ($is_top_level) {
        $decryptedName = Security::decryptData($newNameEnc);
        $newNameEnc = Security::encryptData($decryptedName . " (Cópia)");
    }

    $stmtMax = $pdo->prepare("SELECT MAX(sort_order) FROM directories WHERE user_id = ? AND (parent_id = ? OR (parent_id IS NULL AND ? IS NULL))");
    $stmtMax->execute([$user_id, $target_parent_id, $target_parent_id]);
    $maxOrder = $stmtMax->fetchColumn();
    $newOrder = ($maxOrder !== null) ? (int)$maxOrder + 1 : 0;

    // Atualizado para suportar target_id ao clonar portais
    $stmtInsert = $pdo->prepare("INSERT INTO directories (user_id, parent_id, target_id, type, name_encrypted, default_view, new_item_position, sort_order, icon, icon_color_from, icon_color_to, cover_url_encrypted, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtInsert->execute([
        $user_id,
        $target_parent_id,
        $sourceDir['target_id'],
        $sourceDir['type'],
        $newNameEnc,
        $sourceDir['default_view'],
        $sourceDir['new_item_position'],
        $newOrder,
        $sourceDir['icon'],
        $sourceDir['icon_color_from'],
        $sourceDir['icon_color_to'],
        $sourceDir['cover_url_encrypted'],
        $sourceDir['start_date'],
        $sourceDir['end_date']
    ]);
    
    $newDirId = $pdo->lastInsertId();

    if ((int)$sourceDir['type'] === 1) {
        $stmtCode = $pdo->prepare("SELECT language, content_encrypted FROM files_code WHERE directory_id = ?");
        $stmtCode->execute([$source_id]);
        $codeData = $stmtCode->fetch();
        if ($codeData) {
            $stmtInsertCode = $pdo->prepare("INSERT INTO files_code (directory_id, language, content_encrypted) VALUES (?, ?, ?)");
            $stmtInsertCode->execute([$newDirId, $codeData['language'], $codeData['content_encrypted']]);
        }
    }

    $stmtChildren = $pdo->prepare("SELECT id FROM directories WHERE parent_id = ? AND user_id = ?");
    $stmtChildren->execute([$source_id, $user_id]);
    $children = $stmtChildren->fetchAll();

    foreach ($children as $child) {
        duplicateDirectoryTree($child['id'], $newDirId, $user_id, $pdo, false);
    }

    return true;
}
// =========================================================================


if ($action === 'fetch') {
    $parent_id = isset($input['parent_id']) && $input['parent_id'] !== null ? (int)$input['parent_id'] : null;

    if ($parent_id === null) {
        $stmt = $pdo->prepare("SELECT id, type, target_id, name_encrypted, parent_id, default_view, new_item_position, sort_order, icon, icon_color_from, icon_color_to, cover_url_encrypted, start_date, end_date FROM directories WHERE user_id = ? AND parent_id IS NULL");
        $stmt->execute([$user_id]);
    } else {
        $stmt = $pdo->prepare("SELECT id, type, target_id, name_encrypted, parent_id, default_view, new_item_position, sort_order, icon, icon_color_from, icon_color_to, cover_url_encrypted, start_date, end_date FROM directories WHERE user_id = ? AND parent_id = ?");
        $stmt->execute([$user_id, $parent_id]);
    }
    
    $directories = $stmt->fetchAll();
    
    $response = [];
    foreach ($directories as $dir) {
        $response[] = [
            'id' => $dir['id'],
            'type' => (int)($dir['type'] ?? 0),
            'target_id' => $dir['target_id'],
            'parent_id' => $dir['parent_id'],
            'view' => $dir['default_view'] ?? 'grid',
            'new_item_position' => $dir['new_item_position'] ?? 'end',
            'sort_order' => (int)($dir['sort_order'] ?? 0),
            'name' => Security::decryptData($dir['name_encrypted']),
            'icon' => $dir['icon'] ?? 'fa-folder',
            'color_from' => $dir['icon_color_from'] ?? '#3b82f6',
            'color_to' => $dir['icon_color_to'] ?? '#6366f1',
            'cover_url' => !empty($dir['cover_url_encrypted']) ? Security::decryptData($dir['cover_url_encrypted']) : '',
            'start_date' => $dir['start_date'],
            'end_date' => $dir['end_date']
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

// NOVO: Resolve a trilha completa de breadcrumbs até um diretório (Para viagem via Portal)
elseif ($action === 'get_path') {
    $dir_id = isset($input['id']) && $input['id'] !== null ? (int)$input['id'] : null;
    $path = [];
    $curr = $dir_id;
    
    while ($curr !== null) {
        $stmt = $pdo->prepare("SELECT id, name_encrypted, default_view, parent_id FROM directories WHERE id = ? AND user_id = ?");
        $stmt->execute([$curr, $user_id]);
        $dir = $stmt->fetch();
        
        if ($dir) {
            array_unshift($path, [
                'id' => $dir['id'],
                'name' => Security::decryptData($dir['name_encrypted']),
                'view' => $dir['default_view']
            ]);
            $curr = $dir['parent_id'];
        } else {
            break;
        }
    }
    
    echo json_encode(['status' => 'success', 'data' => $path]);
}

elseif ($action === 'create') {
    $name = trim($input['name'] ?? '');
    $parent_id = isset($input['parent_id']) && $input['parent_id'] !== null ? (int)$input['parent_id'] : null;
    $type = isset($input['type']) ? (int)$input['type'] : 0; 
    $view = in_array($input['view'] ?? '', ['grid', 'list', 'kanban']) ? $input['view'] : 'grid';
    $new_item_position = in_array($input['new_item_position'] ?? '', ['start', 'end']) ? $input['new_item_position'] : 'end';
    
    $default_icon = 'fa-folder';
    if($type === 1) $default_icon = 'fa-file-code';
    if($type === 2) $default_icon = 'fa-calendar-days';

    $icon = preg_match('/^fa-[a-z0-9-]+$/', $input['icon'] ?? '') ? $input['icon'] : $default_icon;
    $color_from = preg_match('/^#[a-fA-F0-9]{6}$/', $input['color_from'] ?? '') ? $input['color_from'] : '#3b82f6';
    $color_to = preg_match('/^#[a-fA-F0-9]{6}$/', $input['color_to'] ?? '') ? $input['color_to'] : '#6366f1';
    $cover_url = trim($input['cover_url'] ?? '');

    $start_date = !empty($input['start_date']) ? $input['start_date'] : null;
    $end_date = !empty($input['end_date']) ? $input['end_date'] : null;

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

    $stmt = $pdo->prepare("INSERT INTO directories (user_id, parent_id, type, name_encrypted, default_view, new_item_position, sort_order, icon, icon_color_from, icon_color_to, cover_url_encrypted, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmt->execute([$user_id, $parent_id, $type, $name_encrypted, $view, $new_item_position, $newOrder, $icon, $color_from, $color_to, $cover_url_encrypted, $start_date, $end_date])) {
        echo json_encode(['status' => 'success', 'message' => 'Item criado com sucesso.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao criar item.']);
    }
}

elseif ($action === 'create_portal') {
    $target_parent_id = isset($input['target_parent_id']) && $input['target_parent_id'] !== null ? (int)$input['target_parent_id'] : null;

    // Pega o ID alvo (Diretório que foi "Copiado" para atalho)
    $stmtUser = $pdo->prepare("SELECT copied_directory_id FROM users WHERE id = ?");
    $stmtUser->execute([$user_id]);
    $target_id = $stmtUser->fetchColumn();

    if (!$target_id) {
        die(json_encode(['status' => 'error', 'message' => 'Nenhum diretório alvo selecionado. Use "Copiar Diretório" antes.']));
    }

    // Pega nome do diretório alvo para criar o nome do portal
    $stmtOrig = $pdo->prepare("SELECT name_encrypted, icon_color_from, icon_color_to FROM directories WHERE id = ? AND user_id = ?");
    $stmtOrig->execute([$target_id, $user_id]);
    $original = $stmtOrig->fetch();

    if (!$original) {
        die(json_encode(['status' => 'error', 'message' => 'O diretório alvo não existe mais ou sem permissão.']));
    }

    $decryptedName = Security::decryptData($original['name_encrypted']);
    $newNameEnc = Security::encryptData($decryptedName);

    // Define ordem no final da lista
    $stmtMax = $pdo->prepare("SELECT MAX(sort_order) FROM directories WHERE user_id = ? AND (parent_id = ? OR (parent_id IS NULL AND ? IS NULL))");
    $stmtMax->execute([$user_id, $target_parent_id, $target_parent_id]);
    $maxOrder = $stmtMax->fetchColumn();
    $newOrder = ($maxOrder !== null) ? (int)$maxOrder + 1 : 0;

    $stmtInsert = $pdo->prepare("INSERT INTO directories (user_id, parent_id, target_id, type, name_encrypted, sort_order, icon, icon_color_from, icon_color_to) VALUES (?, ?, ?, 3, ?, ?, 'fa-door-open', ?, ?)");
    if ($stmtInsert->execute([$user_id, $target_parent_id, $target_id, $newNameEnc, $newOrder, $original['icon_color_from'], $original['icon_color_to']])) {
        
        // Limpa a memória após criar o portal
        $stmtClear = $pdo->prepare("UPDATE users SET copied_directory_id = NULL WHERE id = ?");
        $stmtClear->execute([$user_id]);

        echo json_encode(['status' => 'success', 'message' => 'Portal criado com sucesso!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro interno ao criar portal.']);
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

    $start_date = !empty($input['start_date']) ? $input['start_date'] : null;
    $end_date = !empty($input['end_date']) ? $input['end_date'] : null;

    if (empty($name) || $id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'Dados inválidos.']));
    }

    // TRAVA DE SEGURANÇA: Se for portal (type 3), força o ícone a ser sempre a porta.
    $stmtType = $pdo->prepare("SELECT type FROM directories WHERE id = ? AND user_id = ?");
    $stmtType->execute([$id, $user_id]);
    if ($stmtType->fetchColumn() == 3) {
        $icon = 'fa-door-open';
    }

    $name_encrypted = Security::encryptData($name);
    $cover_url_encrypted = !empty($cover_url) ? Security::encryptData($cover_url) : null;
    
    $stmt = $pdo->prepare("UPDATE directories SET name_encrypted = ?, default_view = ?, new_item_position = ?, icon = ?, icon_color_from = ?, icon_color_to = ?, cover_url_encrypted = ?, start_date = ?, end_date = ? WHERE id = ? AND user_id = ?");
    if ($stmt->execute([$name_encrypted, $view, $new_item_position, $icon, $color_from, $color_to, $cover_url_encrypted, $start_date, $end_date, $id, $user_id])) {
        echo json_encode(['status' => 'success', 'message' => 'Item atualizado.']);
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

    $stmt = $pdo->prepare("DELETE FROM directories WHERE id = ? AND user_id = ?");
    if ($stmt->execute([$id, $user_id])) {
        echo json_encode(['status' => 'success', 'message' => 'Excluído com sucesso.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao excluir.']);
    }
}

elseif ($action === 'paste') {
    $target_parent_id = isset($input['target_parent_id']) && $input['target_parent_id'] !== null ? (int)$input['target_parent_id'] : null;

    $stmtUser = $pdo->prepare("SELECT copied_directory_id FROM users WHERE id = ?");
    $stmtUser->execute([$user_id]);
    $copied_id = $stmtUser->fetchColumn();

    if (!$copied_id) {
        die(json_encode(['status' => 'error', 'message' => 'Nenhum diretório na área de transferência.']));
    }

    $currTarget = $target_parent_id;
    while ($currTarget !== null) {
        if ($currTarget == $copied_id) {
            die(json_encode(['status' => 'error', 'message' => 'Erro: Não é possível colar um diretório dentro dele mesmo.']));
        }
        $stmtCheck = $pdo->prepare("SELECT parent_id FROM directories WHERE id = ? AND user_id = ?");
        $stmtCheck->execute([$currTarget, $user_id]);
        $parent = $stmtCheck->fetchColumn();
        $currTarget = $parent ? $parent : null;
    }

    try {
        $pdo->beginTransaction();

        if (duplicateDirectoryTree($copied_id, $target_parent_id, $user_id, $pdo, true)) {
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Diretório colado com sucesso!']);
        } else {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Erro ao colar: Diretório original não encontrado.']);
        }

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Erro interno na base de dados ao colar o diretório.']);
    }
}

elseif ($action === 'move') {
    $target_parent_id = isset($input['target_parent_id']) && $input['target_parent_id'] !== null ? (int)$input['target_parent_id'] : null;

    $stmtUser = $pdo->prepare("SELECT copied_directory_id FROM users WHERE id = ?");
    $stmtUser->execute([$user_id]);
    $copied_id = $stmtUser->fetchColumn();

    if (!$copied_id) {
        die(json_encode(['status' => 'error', 'message' => 'Nenhum diretório selecionado para mover.']));
    }

    $currTarget = $target_parent_id;
    while ($currTarget !== null) {
        if ($currTarget == $copied_id) {
            die(json_encode(['status' => 'error', 'message' => 'Erro: Não é possível mover um diretório para dentro dele mesmo ou de seus subdiretórios.']));
        }
        $stmtCheck = $pdo->prepare("SELECT parent_id FROM directories WHERE id = ? AND user_id = ?");
        $stmtCheck->execute([$currTarget, $user_id]);
        $parent = $stmtCheck->fetchColumn();
        $currTarget = $parent ? $parent : null;
    }

    try {
        $pdo->beginTransaction();

        $stmtMax = $pdo->prepare("SELECT MAX(sort_order) FROM directories WHERE user_id = ? AND (parent_id = ? OR (parent_id IS NULL AND ? IS NULL))");
        $stmtMax->execute([$user_id, $target_parent_id, $target_parent_id]);
        $maxOrder = $stmtMax->fetchColumn();
        $newOrder = ($maxOrder !== null) ? (int)$maxOrder + 1 : 0;

        $stmtMove = $pdo->prepare("UPDATE directories SET parent_id = ?, sort_order = ? WHERE id = ? AND user_id = ?");
        $stmtMove->execute([$target_parent_id, $newOrder, $copied_id, $user_id]);

        $stmtClear = $pdo->prepare("UPDATE users SET copied_directory_id = NULL WHERE id = ?");
        $stmtClear->execute([$user_id]);

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Diretório movido com sucesso!']);

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Erro interno na base de dados ao mover o diretório.']);
    }
}

else {
    echo json_encode(['status' => 'error', 'message' => 'Ação inválida.']);
}
?>
