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
ensureQuantifiersDatetimeColumns($pdo);

function ensureQuantifiersTable($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS quantifiers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        id_user INT NOT NULL,
        id_quantifier_father INT UNSIGNED NULL DEFAULT NULL,
        title VARCHAR(255) NOT NULL DEFAULT 'Novo quantificador',
        maximum_quantity INT UNSIGNED NOT NULL DEFAULT 0,
        current_quantity INT UNSIGNED NOT NULL DEFAULT 0,
        derivative_quantities TINYINT(1) NOT NULL DEFAULT 0,
        period_type ENUM('years', 'months', 'days') NULL DEFAULT NULL,
        start_datetime DATETIME NULL DEFAULT NULL,
        end_datetime DATETIME NULL DEFAULT NULL,
        repeat_until DATETIME NULL DEFAULT NULL,
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
function ensureQuantifiersDatetimeColumns($pdo) {
    $columns = [
        'period_type' => "ALTER TABLE quantifiers ADD COLUMN period_type ENUM('years', 'months', 'days') NULL DEFAULT NULL AFTER derivative_quantities",
        'start_datetime' => "ALTER TABLE quantifiers ADD COLUMN start_datetime DATETIME NULL DEFAULT NULL AFTER period_type",
        'end_datetime' => "ALTER TABLE quantifiers ADD COLUMN end_datetime DATETIME NULL DEFAULT NULL AFTER start_datetime",
        'repeat_until' => "ALTER TABLE quantifiers ADD COLUMN repeat_until DATETIME NULL DEFAULT NULL AFTER end_datetime",
    ];
    foreach ($columns as $column => $sql) {
        $stmt = $pdo->query("SHOW COLUMNS FROM quantifiers LIKE " . $pdo->quote($column));
        if (!$stmt->fetch()) {
            $pdo->exec($sql);
        }
    }
}

function normalizePeriodType($value) {
    $value = $value === null ? '' : (string)$value;
    return in_array($value, ['years', 'months', 'days'], true) ? $value : null;
}

function normalizeDateTimeInput($value, $endOfDay = false) {
    $value = trim((string)($value ?? ''));
    if ($value === '') {
        return null;
    }
    $dt = new DateTime($value);
    if (strlen($value) <= 10) {
        $dt->setTime($endOfDay ? 23 : 0, $endOfDay ? 59 : 0, $endOfDay ? 59 : 0);
    }
    return $dt->format('Y-m-d H:i:s');
}

function addCalendarUnitsNoOverflow(DateTime $date, $period_type) {
    $year = (int)$date->format('Y');
    $month = (int)$date->format('n');
    $day = (int)$date->format('j');
    if ($period_type === 'years') {
        $year++;
    } elseif ($period_type === 'months') {
        $month++;
        if ($month > 12) {
            $month = 1;
            $year++;
        }
    } else {
        $next = clone $date;
        $next->add(new DateInterval('P1D'));
        return $next;
    }
    $lastDay = (int)(new DateTime(sprintf('%04d-%02d-01', $year, $month)))->format('t');
    $next = clone $date;
    $next->setDate($year, $month, min($day, $lastDay));
    return $next;
}

function calculatePeriodEnd(DateTime $start, $period_type) {
    $end = addCalendarUnitsNoOverflow($start, $period_type);
    $end->sub(new DateInterval($period_type === 'days' ? 'PT1S' : 'P1D'));
    if ($period_type !== 'days') {
        $end->setTime(23, 59, 59);
    }
    return $end;
}

function nextPeriodStart(DateTime $start, $period_type) {
    return addCalendarUnitsNoOverflow($start, $period_type);
}

function createDerivedQuantifiers($pdo, $parent_id, $user_id, $title, $period_type, $start_datetime, $repeat_until, $max, $offset = 0) {
    $offset = max(0, (int)$offset);
    if (!$period_type || !$start_datetime) {
        $limit = max(0, (int)$max) - $offset;
        $created = 0;
        for ($i = $offset + 1; $created < $limit; $i++) {
            $position = nextOrderPosition($pdo, $parent_id, $user_id);
            $childTitle = $title . ' ' . $i . 'º';
            $stmt = $pdo->prepare('INSERT INTO quantifiers (id_user, id_quantifier_father, title, maximum_quantity, current_quantity, derivative_quantities, period_type, start_datetime, end_datetime, repeat_until, order_position) VALUES (?, ?, ?, 0, 0, 0, NULL, NULL, NULL, NULL, ?)');
            $stmt->execute([$user_id, $parent_id, $childTitle, $position]);
            $created++;
        }
        return $created;
    }
    $start = new DateTime($start_datetime);
    for ($skip = 0; $skip < $offset; $skip++) {
        $start = nextPeriodStart($start, $period_type);
    }
    $until = $repeat_until ? new DateTime($repeat_until) : null;
    $limit = $until ? 1000 : max(0, (int)$max) - $offset;
    $created = 0;
    for ($i = $offset + 1; $created < $limit; $i++) {
        $end = calculatePeriodEnd($start, $period_type);
        if ($until && $start > $until) break;
        if ($until && $end > $until) $end = clone $until;
        $position = nextOrderPosition($pdo, $parent_id, $user_id);
        $childTitle = $title . ' ' . $i . 'º';
        $stmt = $pdo->prepare('INSERT INTO quantifiers (id_user, id_quantifier_father, title, maximum_quantity, current_quantity, derivative_quantities, period_type, start_datetime, end_datetime, repeat_until, order_position) VALUES (?, ?, ?, 0, 0, 0, ?, ?, ?, NULL, ?)');
        $stmt->execute([$user_id, $parent_id, $childTitle, $period_type, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $position]);
        $created++;
        if ($until && $end >= $until) break;
        $start = nextPeriodStart($start, $period_type);
    }
    return $created;
}

function ensureDerivedPeriodChildren($pdo, $parent_id, $user_id, $title, $period_type, $start_datetime, $repeat_until, $max) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM quantifiers WHERE id_quantifier_father = ? AND id_user = ?');
    $stmt->execute([(int)$parent_id, (int)$user_id]);
    $existing = (int)$stmt->fetchColumn();
    if (!$repeat_until && $existing >= max(0, (int)$max)) {
        return;
    }
    createDerivedQuantifiers($pdo, $parent_id, $user_id, $title, $period_type, $start_datetime, $repeat_until, $max, $existing);
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
        $period_type = normalizePeriodType($input['period_type'] ?? null);
        $start_datetime = normalizeDateTimeInput($input['start_datetime'] ?? null);
        $repeat_until = normalizeDateTimeInput($input['repeat_until'] ?? null, true);
        $max = max(0, (int)($input['maximum_quantity'] ?? 0));
        if ($derivative === 1 && $period_type && !$start_datetime) {
            throw new Exception('Informe o datetime inicial para gerar quantificadores derivados por período.');
        }
        $title = trim((string)($input['title'] ?? '')) ?: 'Novo quantificador';
        $position = nextOrderPosition($pdo, $father_id, $user_id);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO quantifiers (id_user, id_quantifier_father, title, maximum_quantity, current_quantity, derivative_quantities, period_type, start_datetime, end_datetime, repeat_until, order_position) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)');
        $stmt->execute([$user_id, $father_id, $title, $max, max(0, (int)($input['current_quantity'] ?? 0)), $derivative, $period_type, $start_datetime, $repeat_until, $position]);
        $newId = (int)$pdo->lastInsertId();
        if ($derivative === 1) {
            createDerivedQuantifiers($pdo, $newId, $user_id, $title, $period_type, $start_datetime, $repeat_until, $max);
            recalculateParentMaximum($pdo, $newId, $user_id);
        }
        recalculateParentMaximum($pdo, $father_id, $user_id);
        $pdo->commit();
        echo json_encode(['status' => 'success', 'id' => $newId]);
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
        $period_type = normalizePeriodType($input['period_type'] ?? null);
        $start_datetime = normalizeDateTimeInput($input['start_datetime'] ?? null);
        $repeat_until = normalizeDateTimeInput($input['repeat_until'] ?? null, true);
        $max = max(0, (int)($input['maximum_quantity'] ?? 0));
        if ($derivative === 1 && $period_type && !$start_datetime) {
            throw new Exception('Informe o datetime inicial para quantificadores derivados por período.');
        }
        $title = trim((string)($input['title'] ?? '')) ?: 'Novo quantificador';
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE quantifiers SET title = ?, maximum_quantity = ?, current_quantity = ?, derivative_quantities = ?, period_type = ?, start_datetime = ?, repeat_until = ? WHERE id = ? AND id_user = ?')
            ->execute([$title, $max, max(0, (int)($input['current_quantity'] ?? 0)), $derivative, $period_type, $start_datetime, $repeat_until, $id, $user_id]);
        if ($derivative) {
            ensureDerivedPeriodChildren($pdo, $id, $user_id, $title, $period_type, $start_datetime, $repeat_until, $max);
        }
        if ($derivative) {
            recalculateParentMaximum($pdo, $id, $user_id);
        }
        recalculateParentMaximum($pdo, $father_id, $user_id);
        $pdo->commit();
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
