<?php
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Não autorizado.']));
}

$pdo = Database::getConnection();
$user_id = (int)$_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

ensureQuantifiersTable($pdo);
ensureQuantifiersOrderColumn($pdo);

function ensureQuantifiersTable($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS quantifiers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        id_user INT NOT NULL,
        id_quantifier_father INT UNSIGNED NULL DEFAULT NULL,
        title VARCHAR(255) NOT NULL DEFAULT 'Novo quantificador',
        maximum_quantity INT UNSIGNED NOT NULL DEFAULT 0,
        current_quantity INT UNSIGNED NOT NULL DEFAULT 0,
        derivative_quantities TINYINT(1) NOT NULL DEFAULT 0,
        is_completed TINYINT(1) NOT NULL DEFAULT 0,
        order_position INT UNSIGNED NOT NULL DEFAULT 0,
        completed_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_quantifiers_user (id_user),
        INDEX idx_quantifiers_father (id_quantifier_father),
        INDEX idx_quantifiers_order (id_user, id_quantifier_father, order_position),
        CONSTRAINT fk_quantifiers_father FOREIGN KEY (id_quantifier_father) REFERENCES quantifiers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}


function ensureQuantifiersOrderColumn($pdo) {
    $stmt = $pdo->query("SHOW COLUMNS FROM quantifiers LIKE 'order_position'");
    if (!$stmt->fetch()) {
        $pdo->exec('ALTER TABLE quantifiers ADD COLUMN order_position INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_completed');
    }
    try {
        $pdo->exec('ALTER TABLE quantifiers ADD INDEX idx_quantifiers_order (id_user, id_quantifier_father, order_position)');
    } catch (Exception $e) {
        // Index already exists or database driver does not support this ALTER variant.
    }
}

function normalizeSiblingOrder($pdo, $parent_id, $user_id) {
    if ($parent_id) {
        $stmt = $pdo->prepare('SELECT id FROM quantifiers WHERE id_user = ? AND id_quantifier_father = ? ORDER BY order_position, id');
        $stmt->execute([(int)$user_id, (int)$parent_id]);
    } else {
        $stmt = $pdo->prepare('SELECT id FROM quantifiers WHERE id_user = ? AND id_quantifier_father IS NULL ORDER BY order_position, id');
        $stmt->execute([(int)$user_id]);
    }
    $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
    foreach ($ids as $position => $id) {
        $pdo->prepare('UPDATE quantifiers SET order_position = ? WHERE id = ? AND id_user = ?')->execute([$position, $id, (int)$user_id]);
    }
}

function nextOrderPosition($pdo, $parent_id, $user_id) {
    if ($parent_id) {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(order_position), -1) + 1 FROM quantifiers WHERE id_user = ? AND id_quantifier_father = ?');
        $stmt->execute([(int)$user_id, (int)$parent_id]);
    } else {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(order_position), -1) + 1 FROM quantifiers WHERE id_user = ? AND id_quantifier_father IS NULL');
        $stmt->execute([(int)$user_id]);
    }
    return (int)$stmt->fetchColumn();
}

function wouldCreateCycle($pdo, $id, $new_parent_id, $user_id) {
    $cursor = $new_parent_id ? quantifierBelongsToUser($pdo, $new_parent_id, $user_id) : null;
    while ($cursor) {
        if ((int)$cursor['id'] === (int)$id) {
            return true;
        }
        $cursor = !empty($cursor['id_quantifier_father']) ? quantifierBelongsToUser($pdo, (int)$cursor['id_quantifier_father'], $user_id) : null;
    }
    return false;
}


function normalizeQuantifierParentInput($input, $key = 'id_quantifier_father') {
    if (!array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
        return null;
    }
    $parent_id = (int)$input[$key];
    return $parent_id > 0 ? $parent_id : null;
}

function quantifierBelongsToUser($pdo, $id, $user_id) {
    if (!$id) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM quantifiers WHERE id = ? AND id_user = ?');
    $stmt->execute([(int)$id, (int)$user_id]);
    return $stmt->fetch();
}

function descendantsIds($pdo, $root_id, $user_id) {
    $ids = [];
    $queue = [(int)$root_id];
    while ($queue) {
        $placeholders = implode(',', array_fill(0, count($queue), '?'));
        $params = array_merge($queue, [(int)$user_id]);
        $stmt = $pdo->prepare("SELECT id FROM quantifiers WHERE id_quantifier_father IN ($placeholders) AND id_user = ?");
        $stmt->execute($params);
        $queue = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        foreach ($queue as $id) {
            $ids[] = $id;
        }
    }
    return $ids;
}

function recalculateParentMaximum($pdo, $parent_id, $user_id) {
    if (!$parent_id) {
        return;
    }
    $parent = quantifierBelongsToUser($pdo, $parent_id, $user_id);
    if (!$parent || (int)$parent['derivative_quantities'] !== 1) {
        return;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM quantifiers WHERE id_quantifier_father = ? AND id_user = ?');
    $stmt->execute([(int)$parent_id, (int)$user_id]);
    $count = (int)$stmt->fetchColumn();
    $pdo->prepare('UPDATE quantifiers SET maximum_quantity = ? WHERE id = ? AND id_user = ?')->execute([$count, (int)$parent_id, (int)$user_id]);
}

try {
    if ($action === 'fetch') {
        $stmt = $pdo->prepare('SELECT * FROM quantifiers WHERE id_user = ? ORDER BY id_quantifier_father IS NOT NULL, id_quantifier_father, order_position, id');
        $stmt->execute([$user_id]);
        echo json_encode(['status' => 'success', 'quantifiers' => $stmt->fetchAll()]);
        exit;
    }

    if ($action === 'create') {
        $father_id = normalizeQuantifierParentInput($input);
        if ($father_id && !quantifierBelongsToUser($pdo, $father_id, $user_id)) {
            throw new Exception('Quantificador pai inválido.');
        }
        $derivative = !empty($input['derivative_quantities']) ? 1 : 0;
        $max = max(0, (int)($input['maximum_quantity'] ?? 0));
        if ($derivative === 1) {
            $max = 0;
        }
        $title = trim((string)($input['title'] ?? '')) ?: 'Novo quantificador';
        $position = nextOrderPosition($pdo, $father_id, $user_id);
        $stmt = $pdo->prepare('INSERT INTO quantifiers (id_user, id_quantifier_father, title, maximum_quantity, current_quantity, derivative_quantities, order_position) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$user_id, $father_id, $title, $max, max(0, (int)($input['current_quantity'] ?? 0)), $derivative, $position]);
        recalculateParentMaximum($pdo, $father_id, $user_id);
        echo json_encode(['status' => 'success', 'id' => (int)$pdo->lastInsertId()]);
        exit;
    }

    if ($action === 'update') {
        $id = (int)($input['id'] ?? 0);
        $current = quantifierBelongsToUser($pdo, $id, $user_id);
        if (!$current) {
            throw new Exception('Quantificador não encontrado.');
        }
        $father_id = !empty($current['id_quantifier_father']) ? (int)$current['id_quantifier_father'] : null;
        $derivative = !empty($input['derivative_quantities']) ? 1 : 0;
        $max = $derivative ? (int)$current['maximum_quantity'] : max(0, (int)($input['maximum_quantity'] ?? 0));
        $title = trim((string)($input['title'] ?? '')) ?: 'Novo quantificador';
        $pdo->prepare('UPDATE quantifiers SET title = ?, maximum_quantity = ?, current_quantity = ?, derivative_quantities = ? WHERE id = ? AND id_user = ?')
            ->execute([$title, $max, max(0, (int)($input['current_quantity'] ?? 0)), $derivative, $id, $user_id]);
        if ($derivative) {
            recalculateParentMaximum($pdo, $id, $user_id);
        }
        recalculateParentMaximum($pdo, $father_id, $user_id);
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'complete') {
        $id = (int)($input['id'] ?? 0);
        $q = quantifierBelongsToUser($pdo, $id, $user_id);
        if (!$q) {
            throw new Exception('Quantificador não encontrado.');
        }
        if ((int)$q['is_completed'] !== 1) {
            $pdo->prepare('UPDATE quantifiers SET is_completed = 1, completed_at = CURRENT_TIMESTAMP WHERE id = ? AND id_user = ?')->execute([$id, $user_id]);
            if (!empty($q['id_quantifier_father'])) {
                $pdo->prepare('UPDATE quantifiers SET current_quantity = current_quantity + 1 WHERE id = ? AND id_user = ?')->execute([(int)$q['id_quantifier_father'], $user_id]);
            }
        }
        echo json_encode(['status' => 'success']);
        exit;
    }


    if ($action === 'move') {
        $id = (int)($input['id'] ?? 0);
        $q = quantifierBelongsToUser($pdo, $id, $user_id);
        if (!$q) {
            throw new Exception('Quantificador não encontrado.');
        }
        $old_parent_id = !empty($q['id_quantifier_father']) ? (int)$q['id_quantifier_father'] : null;
        $force_root = !empty($input['force_root']);
        $new_parent_id = $force_root ? null : normalizeQuantifierParentInput($input);
        if ($new_parent_id && !quantifierBelongsToUser($pdo, $new_parent_id, $user_id)) {
            throw new Exception('Quantificador pai inválido.');
        }
        if ($new_parent_id === $id || wouldCreateCycle($pdo, $id, $new_parent_id, $user_id)) {
            throw new Exception('Não é possível mover um quantificador para dentro da própria descendência.');
        }
        $new_index = max(0, (int)($input['position'] ?? 0));
        $pdo->beginTransaction();
        $update = $pdo->prepare('UPDATE quantifiers SET id_quantifier_father = :parent_id, order_position = 999999 WHERE id = :id AND id_user = :user_id');
        if ($new_parent_id === null) {
            $update->bindValue(':parent_id', null, PDO::PARAM_NULL);
        } else {
            $update->bindValue(':parent_id', $new_parent_id, PDO::PARAM_INT);
        }
        $update->bindValue(':id', $id, PDO::PARAM_INT);
        $update->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $update->execute();
        normalizeSiblingOrder($pdo, $old_parent_id, $user_id);
        if ($new_parent_id) {
            $stmt = $pdo->prepare('SELECT id FROM quantifiers WHERE id_user = ? AND id_quantifier_father = ? AND id <> ? ORDER BY order_position, id');
            $stmt->execute([$user_id, $new_parent_id, $id]);
        } else {
            $stmt = $pdo->prepare('SELECT id FROM quantifiers WHERE id_user = ? AND id_quantifier_father IS NULL AND id <> ? ORDER BY order_position, id');
            $stmt->execute([$user_id, $id]);
        }
        $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        array_splice($ids, min($new_index, count($ids)), 0, [$id]);
        foreach ($ids as $position => $sibling_id) {
            $pdo->prepare('UPDATE quantifiers SET order_position = ? WHERE id = ? AND id_user = ?')->execute([$position, $sibling_id, $user_id]);
        }
        recalculateParentMaximum($pdo, $old_parent_id, $user_id);
        recalculateParentMaximum($pdo, $new_parent_id, $user_id);
        $pdo->commit();
        $moved = quantifierBelongsToUser($pdo, $id, $user_id);
        echo json_encode(['status' => 'success', 'quantifier' => $moved]);
        exit;
    }

    if ($action === 'delete_descendants') {
        $id = (int)($input['id'] ?? 0);
        $q = quantifierBelongsToUser($pdo, $id, $user_id);
        if (!$q) {
            throw new Exception('Quantificador não encontrado.');
        }
        $ids = descendantsIds($pdo, $id, $user_id);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM quantifiers WHERE id IN ($placeholders) AND id_user = ?")->execute(array_merge($ids, [$user_id]));
        }
        recalculateParentMaximum($pdo, $id, $user_id);
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        $q = quantifierBelongsToUser($pdo, $id, $user_id);
        if (!$q) {
            throw new Exception('Quantificador não encontrado.');
        }
        $pdo->prepare('DELETE FROM quantifiers WHERE id = ? AND id_user = ?')->execute([$id, $user_id]);
        recalculateParentMaximum($pdo, $q['id_quantifier_father'], $user_id);
        echo json_encode(['status' => 'success']);
        exit;
    }

    throw new Exception('Ação inválida.');
} catch (Exception $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
