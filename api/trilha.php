<?php
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Não autorizado.']));
}

$pdo = Database::getConnection();
$user_id = (int)$_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

function ensureTrailTables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS percursos (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        track_directory_id INT UNSIGNED NOT NULL,
        position_index INT UNSIGNED NOT NULL,
        map_number INT UNSIGNED NOT NULL,
        phase_number TINYINT UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL,
        objective TEXT DEFAULT NULL,
        questions_json LONGTEXT DEFAULT NULL,
        prerequisite_positions_json LONGTEXT DEFAULT NULL,
        source VARCHAR(20) NOT NULL DEFAULT 'manual',
        is_published TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        published_at DATETIME DEFAULT NULL,
        UNIQUE KEY uniq_track_position (track_directory_id, position_index),
        INDEX idx_track_map (track_directory_id, map_number, phase_number),
        INDEX idx_track_publish (track_directory_id, is_published, position_index),
        CONSTRAINT fk_percursos_directory FOREIGN KEY (track_directory_id) REFERENCES directories(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        $pdo->exec("ALTER TABLE percursos ADD COLUMN is_published TINYINT(1) NOT NULL DEFAULT 0 AFTER source");
    } catch (Throwable $e) {}
    try {
        $pdo->exec("ALTER TABLE percursos ADD COLUMN published_at DATETIME DEFAULT NULL AFTER updated_at");
    } catch (Throwable $e) {}
    try {
        $pdo->exec("ALTER TABLE percursos ADD COLUMN prerequisite_positions_json LONGTEXT DEFAULT NULL AFTER questions_json");
    } catch (Throwable $e) {}
    try {
        $pdo->exec("CREATE INDEX idx_track_publish ON percursos (track_directory_id, is_published, position_index)");
    } catch (Throwable $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS revisoes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        track_directory_id INT UNSIGNED NOT NULL,
        current_position INT UNSIGNED NOT NULL DEFAULT 1,
        completed_positions_json LONGTEXT DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_track (user_id, track_directory_id),
        CONSTRAINT fk_track_progress_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_track_progress_directory FOREIGN KEY (track_directory_id) REFERENCES directories(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS track_generation_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        track_directory_id INT UNSIGNED NOT NULL,
        model VARCHAR(40) NOT NULL DEFAULT 'gpt-5.4',
        prompt_payload LONGTEXT DEFAULT NULL,
        response_payload LONGTEXT DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'completed',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_track_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_track_jobs_directory FOREIGN KEY (track_directory_id) REFERENCES directories(id) ON DELETE CASCADE,
        INDEX idx_track_jobs (track_directory_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS percurso_slides (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        node_id BIGINT UNSIGNED NOT NULL,
        content_json LONGTEXT DEFAULT NULL,
        model VARCHAR(40) DEFAULT NULL,
        created_by INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_node_slide (node_id),
        CONSTRAINT fk_percurso_slides_node FOREIGN KEY (node_id) REFERENCES percursos(id) ON DELETE CASCADE,
        CONSTRAINT fk_percurso_slides_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Migração de esquema legado:
    // - track_nodes -> percursos (compartilhado entre usuários)
    // - track_user_progress -> revisoes (individual por usuário)
    // - track_node_slides -> percurso_slides
    $legacyNodesExists = (bool)$pdo->query("SHOW TABLES LIKE 'track_nodes'")->fetchColumn();
    if ($legacyNodesExists) {
        $legacyCount = (int)$pdo->query("SELECT COUNT(*) FROM track_nodes")->fetchColumn();
        $newCount = (int)$pdo->query("SELECT COUNT(*) FROM percursos")->fetchColumn();
        if ($legacyCount > 0 && $newCount === 0) {
            $pdo->exec("INSERT IGNORE INTO percursos (
                id, track_directory_id, position_index, map_number, phase_number, title, objective,
                questions_json, prerequisite_positions_json, source, is_published, created_at, updated_at, published_at
            )
            SELECT
                id, track_directory_id, position_index, map_number, phase_number, title, objective,
                questions_json, prerequisite_positions_json, source, is_published, created_at, updated_at, published_at
            FROM track_nodes");
        }
    }

    $legacyReviewsExists = (bool)$pdo->query("SHOW TABLES LIKE 'track_user_progress'")->fetchColumn();
    if ($legacyReviewsExists) {
        $legacyCount = (int)$pdo->query("SELECT COUNT(*) FROM track_user_progress")->fetchColumn();
        $newCount = (int)$pdo->query("SELECT COUNT(*) FROM revisoes")->fetchColumn();
        if ($legacyCount > 0 && $newCount === 0) {
            $pdo->exec("INSERT IGNORE INTO revisoes (
                id, user_id, track_directory_id, current_position, completed_positions_json, updated_at
            )
            SELECT
                id, user_id, track_directory_id, current_position, completed_positions_json, updated_at
            FROM track_user_progress");
        }
    }

    $legacySlidesExists = (bool)$pdo->query("SHOW TABLES LIKE 'track_node_slides'")->fetchColumn();
    if ($legacySlidesExists) {
        $legacyCount = (int)$pdo->query("SELECT COUNT(*) FROM track_node_slides")->fetchColumn();
        $newCount = (int)$pdo->query("SELECT COUNT(*) FROM percurso_slides")->fetchColumn();
        if ($legacyCount > 0 && $newCount === 0) {
            $pdo->exec("INSERT IGNORE INTO percurso_slides (
                id, node_id, content_json, model, created_by, created_at, updated_at
            )
            SELECT
                id, node_id, content_json, model, created_by, created_at, updated_at
            FROM track_node_slides");
        }
    }
}

function verifyTrackOwnership(PDO $pdo, int $directory_id, int $user_id) {
    $stmt = $pdo->prepare("SELECT id, user_id, is_public, name_encrypted FROM directories WHERE id = ? AND type = 8");
    $stmt->execute([$directory_id]);
    $dir = $stmt->fetch();
    if (!$dir || (int)$dir['user_id'] !== $user_id) return null;
    return $dir;
}

function verifyTrackAccess(PDO $pdo, int $directory_id, int $user_id) {
    $stmt = $pdo->prepare("SELECT id, user_id, is_public, name_encrypted FROM directories WHERE id = ? AND type = 8");
    $stmt->execute([$directory_id]);
    $dir = $stmt->fetch();
    if (!$dir) return null;
    if ((int)$dir['user_id'] !== $user_id && (int)$dir['is_public'] !== 1) return null;
    return $dir;
}

function decodeQuestions($raw): array {
    $arr = json_decode((string)$raw, true);
    return is_array($arr) ? array_values(array_filter(array_map(fn($q) => trim((string)$q), $arr), fn($q) => $q !== '')) : [];
}

function decodePrerequisitePositions($raw): array {
    $arr = json_decode((string)$raw, true);
    if (!is_array($arr)) return [];
    $set = [];
    foreach ($arr as $pos) {
        $n = (int)$pos;
        if ($n > 0) $set[$n] = true;
    }
    $out = array_keys($set);
    sort($out);
    return $out;
}

function normalizeDependsOnPositions($raw, int $maxAllowed): array {
    if (!is_array($raw)) return [];
    $set = [];
    foreach ($raw as $pos) {
        $n = (int)$pos;
        if ($n > 0 && $n <= $maxAllowed) $set[$n] = true;
    }
    $out = array_keys($set);
    sort($out);
    return $out;
}

function openaiJsonRequest(string $url, array $payload): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_API_KEY
    ]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    return [$httpcode, $response, $curlError];
}

function buildFallbackItems(string $subject, int $startPosition): array {
    $items = [];
    for ($i = 0; $i < 10; $i++) {
        $num = $startPosition + $i;
        $items[] = [
            'title' => "{$subject} · Tópico {$num}",
            'objective' => "Compreender o tópico {$num} com exemplos práticos.",
            'depends_on' => $num > 1 ? [$num - 1] : []
        ];
    }
    return $items;
}

function generateItemsWithGPT(string $subject, array $existingTitles, int $startPosition): array {
    if (trim((string)OPENAI_API_KEY) === '') {
        return ['items' => buildFallbackItems($subject, $startPosition), 'prompt' => null, 'response' => null, 'model' => 'fallback'];
    }

    $context = implode("\n", array_slice($existingTitles, -25));
    $prompt = [
        'model' => 'gpt-5.4',
        'response_format' => ['type' => 'json_object'],
        'messages' => [
            ['role' => 'system', 'content' => 'Você cria trilhas pedagógicas sequenciais evitando granularidade ruim. Gere exatamente 10 fases novas. Se um item estiver amplo, já quebre em fases menores. Você pode inserir pré-requisitos faltantes e reordenar para manter linearidade.'],
            ['role' => 'user', 'content' => "Matéria: {$subject}.\nÚltimos itens já existentes:\n{$context}\n\nGere exatamente 10 novos itens sequenciais a partir da posição {$startPosition}.\nFormato JSON obrigatório: {\"items\":[{\"title\":\"\",\"objective\":\"\",\"depends_on\":[1,2]}]}.\nRegra do campo depends_on: array de posições absolutas que devem ser concluídas antes desta fase. Só use posições anteriores.\nIMPORTANTE: não gere perguntas, quiz, respostas ou conteúdo de slide. Aqui é só o índice/trilha de fases. Não repita itens existentes."]
        ]
    ];

    [$code, $resp, $err] = openaiJsonRequest('https://api.openai.com/v1/chat/completions', $prompt);
    if ($code !== 200 || !$resp) {
        return ['items' => buildFallbackItems($subject, $startPosition), 'prompt' => $prompt, 'response' => ['error' => $err, 'http' => $code], 'model' => 'fallback'];
    }

    $decoded = json_decode($resp, true);
    $raw = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));
    if (str_starts_with($raw, '```')) {
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/', '', $raw);
        $raw = trim($raw);
    }
    $json = json_decode($raw, true);
    $items = [];
    if (is_array($json) && isset($json['items']) && is_array($json['items'])) {
        foreach ($json['items'] as $item) {
            $title = trim((string)($item['title'] ?? ''));
            if ($title === '') continue;
            $items[] = [
                'title' => $title,
                'objective' => trim((string)($item['objective'] ?? '')),
                'depends_on' => array_values((array)($item['depends_on'] ?? []))
            ];
        }
    }

    if (count($items) < 10) {
        $items = array_merge($items, buildFallbackItems($subject, $startPosition + count($items)));
        $items = array_slice($items, 0, 10);
    } else {
        $items = array_slice($items, 0, 10);
    }

    return ['items' => $items, 'prompt' => $prompt, 'response' => $decoded, 'model' => 'gpt-5.4'];
}

function buildFallbackSlides(string $subject, string $title, string $objective): array {
    return [
        ['type' => 'intro', 'title' => $title, 'body' => "Objetivo: {$objective}"],
        ['type' => 'conceito', 'title' => 'Definição essencial', 'body' => "Explique de forma simples o conceito central de {$title} em {$subject}."],
        ['type' => 'imagem', 'title' => 'Imagem de apoio', 'body' => "[INSIRA IMAGEM REPRESENTATIVA DE {$title} AQUI]"],
        ['type' => 'aplicacao', 'title' => 'Aplicação', 'body' => "Mostre um caso prático de {$title}."],
        ['type' => 'check', 'title' => 'Checklist de domínio', 'body' => "- Consigo explicar {$title} com minhas próprias palavras?\n- Consigo resolver um exercício básico sem ajuda?\n- Sei qual pré-requisito revisar se eu travar?"]
    ];
}

function generateSlidesWithGPT(string $subject, string $title, string $objective): array {
    if (trim((string)OPENAI_API_KEY) === '') {
        return ['slides' => buildFallbackSlides($subject, $title, $objective), 'model' => 'fallback'];
    }

    $prompt = [
        'model' => 'gpt-5.4',
        'response_format' => ['type' => 'json_object'],
        'messages' => [
            ['role' => 'system', 'content' => 'Você cria conteúdo didático em slides curtos, sem consumir tokens demais. Não gere imagens reais, apenas placeholders [INSIRA IMAGEM ... AQUI].'],
            ['role' => 'user', 'content' => "Matéria: {$subject}\nFase: {$title}\nObjetivo: {$objective}\n\nRetorne JSON no formato: {\"slides\":[{\"type\":\"intro|conceito|imagem|aplicacao|check\",\"title\":\"\",\"body\":\"\"}]}. Gere de 5 a 8 slides curtos."]
        ]
    ];

    [$code, $resp] = openaiJsonRequest('https://api.openai.com/v1/chat/completions', $prompt);
    if ($code !== 200 || !$resp) {
        return ['slides' => buildFallbackSlides($subject, $title, $objective), 'model' => 'fallback'];
    }

    $decoded = json_decode($resp, true);
    $raw = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));
    if (str_starts_with($raw, '```')) {
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/', '', $raw);
        $raw = trim($raw);
    }
    $json = json_decode($raw, true);
    $slides = [];
    if (is_array($json) && isset($json['slides']) && is_array($json['slides'])) {
        foreach ($json['slides'] as $slide) {
            $titleOut = trim((string)($slide['title'] ?? ''));
            $bodyOut = trim((string)($slide['body'] ?? ''));
            if ($titleOut === '' || $bodyOut === '') continue;
            $slides[] = [
                'type' => trim((string)($slide['type'] ?? 'conceito')),
                'title' => $titleOut,
                'body' => $bodyOut
            ];
        }
    }

    if (!$slides) {
        $slides = buildFallbackSlides($subject, $title, $objective);
        return ['slides' => $slides, 'model' => 'fallback'];
    }

    return ['slides' => array_slice($slides, 0, 10), 'model' => 'gpt-5.4'];
}

ensureTrailTables($pdo);

if ($action === 'fetch_admin') {
    $directory_id = (int)($input['directory_id'] ?? 0);
    $dir = verifyTrackOwnership($pdo, $directory_id, $user_id);
    if (!$dir) die(json_encode(['status' => 'error', 'message' => 'Trilha não encontrada.']));

    $stmt = $pdo->prepare("SELECT id, position_index, map_number, phase_number, title, objective, questions_json, prerequisite_positions_json, source, is_published FROM percursos WHERE track_directory_id = ? ORDER BY position_index ASC");
    $stmt->execute([$directory_id]);
    $nodes = [];
    $pending = 0;
    foreach ($stmt->fetchAll() as $row) {
        $published = (int)($row['is_published'] ?? 0) === 1;
        if (!$published) $pending++;
        $nodes[] = [
            'id' => (int)$row['id'],
            'position_index' => (int)$row['position_index'],
            'map_number' => (int)$row['map_number'],
            'phase_number' => (int)$row['phase_number'],
            'title' => $row['title'],
            'objective' => (string)($row['objective'] ?? ''),
            'questions' => decodeQuestions($row['questions_json']),
            'depends_on_positions' => decodePrerequisitePositions($row['prerequisite_positions_json']),
            'source' => $row['source'],
            'is_published' => $published
        ];
    }

    echo json_encode(['status' => 'success', 'track' => ['id' => (int)$dir['id'], 'name' => Security::decryptData($dir['name_encrypted'])], 'pending_count' => $pending, 'nodes' => $nodes]);
}
elseif ($action === 'generate_batch') {
    $directory_id = (int)($input['directory_id'] ?? 0);
    $dir = verifyTrackOwnership($pdo, $directory_id, $user_id);
    if (!$dir) die(json_encode(['status' => 'error', 'message' => 'Trilha não encontrada.']));

    $subject = Security::decryptData($dir['name_encrypted']);
    $stmtExisting = $pdo->prepare("SELECT position_index, title FROM percursos WHERE track_directory_id = ? ORDER BY position_index ASC");
    $stmtExisting->execute([$directory_id]);
    $existing = $stmtExisting->fetchAll();
    $existingTitles = array_map(fn($r) => (string)$r['title'], $existing);
    $startPosition = count($existing) + 1;

    $generated = generateItemsWithGPT($subject, $existingTitles, $startPosition);

    $pdo->beginTransaction();
    try {
        $stmtIns = $pdo->prepare("INSERT INTO percursos (track_directory_id, position_index, map_number, phase_number, title, objective, questions_json, prerequisite_positions_json, source, is_published) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
        $inserted = [];
        foreach ($generated['items'] as $offset => $item) {
            $position = $startPosition + $offset;
            $map = (int)floor(($position - 1) / 10) + 1;
            $phase = (($position - 1) % 10) + 1;
            $dependsOn = normalizeDependsOnPositions((array)($item['depends_on'] ?? []), $position - 1);
            $stmtIns->execute([$directory_id, $position, $map, $phase, trim((string)$item['title']), trim((string)($item['objective'] ?? '')), json_encode([], JSON_UNESCAPED_UNICODE), json_encode($dependsOn, JSON_UNESCAPED_UNICODE), $generated['model'] === 'gpt-5.4' ? 'gpt' : 'fallback']);
            $inserted[] = [
                'id' => (int)$pdo->lastInsertId(),
                'position_index' => $position,
                'map_number' => $map,
                'phase_number' => $phase,
                'title' => trim((string)$item['title']),
                'objective' => trim((string)($item['objective'] ?? '')),
                'questions' => [],
                'depends_on_positions' => $dependsOn,
                'is_published' => false
            ];
        }

        $jobStmt = $pdo->prepare("INSERT INTO track_generation_jobs (user_id, track_directory_id, model, prompt_payload, response_payload, status) VALUES (?, ?, ?, ?, ?, 'completed')");
        $jobStmt->execute([$user_id, $directory_id, $generated['model'] === 'gpt-5.4' ? 'gpt-5.4' : 'fallback', json_encode($generated['prompt'], JSON_UNESCAPED_UNICODE), json_encode($generated['response'], JSON_UNESCAPED_UNICODE)]);

        $pdo->commit();
        echo json_encode(['status' => 'success', 'inserted' => $inserted, 'model' => $generated['model']]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Falha ao gerar lote de fases.']);
    }
}
elseif ($action === 'publish_pending') {
    $directory_id = (int)($input['directory_id'] ?? 0);
    $dir = verifyTrackOwnership($pdo, $directory_id, $user_id);
    if (!$dir) die(json_encode(['status' => 'error', 'message' => 'Trilha não encontrada.']));

    $stmt = $pdo->prepare("UPDATE percursos SET is_published = 1, published_at = NOW() WHERE track_directory_id = ? AND is_published = 0");
    $stmt->execute([$directory_id]);
    echo json_encode(['status' => 'success', 'published' => $stmt->rowCount()]);
}
elseif ($action === 'upsert_node') {
    $directory_id = (int)($input['directory_id'] ?? 0);
    $dir = verifyTrackOwnership($pdo, $directory_id, $user_id);
    if (!$dir) die(json_encode(['status' => 'error', 'message' => 'Trilha não encontrada.']));

    $node_id = (int)($input['node_id'] ?? 0);
    $title = trim((string)($input['title'] ?? ''));
    $objective = trim((string)($input['objective'] ?? ''));
    $questions = array_slice(array_values(array_filter(array_map(fn($q) => trim((string)$q), (array)($input['questions'] ?? [])), fn($q) => $q !== '')), 0, 10);
    $dependsOnPositions = normalizeDependsOnPositions((array)($input['depends_on_positions'] ?? []), PHP_INT_MAX);
    if ($title === '') die(json_encode(['status' => 'error', 'message' => 'Título obrigatório.']));

    if ($node_id > 0) {
        $stmt = $pdo->prepare("UPDATE percursos SET title = ?, objective = ?, questions_json = ?, prerequisite_positions_json = ?, source = 'manual', is_published = 0, published_at = NULL WHERE id = ? AND track_directory_id = ?");
        $stmt->execute([$title, $objective, json_encode($questions, JSON_UNESCAPED_UNICODE), json_encode($dependsOnPositions, JSON_UNESCAPED_UNICODE), $node_id, $directory_id]);
    }

    echo json_encode(['status' => 'success']);
}
elseif ($action === 'delete_node') {
    $directory_id = (int)($input['directory_id'] ?? 0);
    $node_id = (int)($input['node_id'] ?? 0);
    $dir = verifyTrackOwnership($pdo, $directory_id, $user_id);
    if (!$dir) die(json_encode(['status' => 'error', 'message' => 'Trilha não encontrada.']));

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM percursos WHERE id = ? AND track_directory_id = ?")->execute([$node_id, $directory_id]);
        $stmt = $pdo->prepare("SELECT id FROM percursos WHERE track_directory_id = ? ORDER BY position_index ASC");
        $stmt->execute([$directory_id]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $upd = $pdo->prepare("UPDATE percursos SET position_index = ?, map_number = ?, phase_number = ? WHERE id = ?");
        foreach ($ids as $idx => $id) {
            $position = $idx + 1;
            $upd->execute([$position, (int)floor(($position - 1) / 10) + 1, (($position - 1) % 10) + 1, $id]);
        }
        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Falha ao excluir fase.']);
    }
}
elseif ($action === 'fetch_map') {
    $directory_id = (int)($input['directory_id'] ?? 0);
    $requested_map = (int)($input['map_number'] ?? 0);
    $dir = verifyTrackAccess($pdo, $directory_id, $user_id);
    if (!$dir) die(json_encode(['status' => 'error', 'message' => 'Trilha indisponível.']));

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM percursos WHERE track_directory_id = ? AND is_published = 1");
    $stmtCount->execute([$directory_id]);
    $totalNodes = (int)$stmtCount->fetchColumn();
    $totalMaps = max(1, (int)ceil($totalNodes / 10));

    $stmtProg = $pdo->prepare("SELECT id, current_position, completed_positions_json FROM revisoes WHERE user_id = ? AND track_directory_id = ? LIMIT 1");
    $stmtProg->execute([$user_id, $directory_id]);
    $prog = $stmtProg->fetch();
    $currentPosition = $prog ? max(1, (int)$prog['current_position']) : 1;
    $completedPositions = $prog ? json_decode((string)$prog['completed_positions_json'], true) : [];
    if (!is_array($completedPositions)) $completedPositions = [];
    $completedSet = [];
    foreach ($completedPositions as $p) { $completedSet[(int)$p] = true; }

    $activeMap = $requested_map > 0 ? min($totalMaps, $requested_map) : min($totalMaps, max(1, (int)ceil($currentPosition / 10)));

    $offsetStart = (($activeMap - 1) * 10) + 1;
    $offsetEnd = $offsetStart + 9;
    $stmtNodes = $pdo->prepare("SELECT n.id, n.position_index, n.map_number, n.phase_number, n.title, n.objective, n.questions_json, n.prerequisite_positions_json, (s.id IS NOT NULL) AS has_slide FROM percursos n LEFT JOIN percurso_slides s ON s.node_id = n.id WHERE n.track_directory_id = ? AND n.is_published = 1 AND n.position_index BETWEEN ? AND ? ORDER BY n.position_index ASC");
    $stmtNodes->execute([$directory_id, $offsetStart, $offsetEnd]);

    $nodes = [];
    foreach ($stmtNodes->fetchAll() as $row) {
        $pos = (int)$row['position_index'];
        $dependsOn = decodePrerequisitePositions($row['prerequisite_positions_json']);
        $missingDepends = array_values(array_filter($dependsOn, fn($dep) => !isset($completedSet[(int)$dep])));
        $state = isset($completedSet[$pos]) ? 'done' : ($pos === $currentPosition && !$missingDepends ? 'active' : ($pos < $currentPosition && !$missingDepends ? 'active' : 'locked'));
        $nodes[] = [
            'id' => (int)$row['id'],
            'position_index' => $pos,
            'phase_number' => (int)$row['phase_number'],
            'title' => $row['title'],
            'objective' => (string)($row['objective'] ?? ''),
            'questions' => decodeQuestions($row['questions_json']),
            'depends_on_positions' => $dependsOn,
            'missing_depends_on_positions' => $missingDepends,
            'has_slide' => (int)$row['has_slide'] === 1,
            'state' => $state
        ];
    }

    echo json_encode([
        'status' => 'success',
        'track' => ['id' => (int)$dir['id'], 'name' => Security::decryptData($dir['name_encrypted'])],
        'map_number' => $activeMap,
        'total_maps' => $totalMaps,
        'current_position' => $currentPosition,
        'nodes' => $nodes
    ]);
}
elseif ($action === 'fetch_phase') {
    $directory_id = (int)($input['directory_id'] ?? 0);
    $node_id = (int)($input['node_id'] ?? 0);
    $dir = verifyTrackAccess($pdo, $directory_id, $user_id);
    if (!$dir) die(json_encode(['status' => 'error', 'message' => 'Trilha indisponível.']));

    $owner = (int)$dir['user_id'] === $user_id;
    $stmt = $pdo->prepare("SELECT id, position_index, map_number, phase_number, title, objective, questions_json, prerequisite_positions_json, is_published FROM percursos WHERE id = ? AND track_directory_id = ? LIMIT 1");
    $stmt->execute([$node_id, $directory_id]);
    $node = $stmt->fetch();
    if (!$node) die(json_encode(['status' => 'error', 'message' => 'Fase não encontrada.']));
    if (!$owner && (int)$node['is_published'] !== 1) die(json_encode(['status' => 'error', 'message' => 'Fase ainda não publicada.']));

    $slideStmt = $pdo->prepare("SELECT content_json FROM percurso_slides WHERE node_id = ? LIMIT 1");
    $slideStmt->execute([$node_id]);
    $slide = $slideStmt->fetchColumn();
    $slides = json_decode((string)$slide, true);
    if (!is_array($slides)) $slides = [];

    echo json_encode([
        'status' => 'success',
        'track' => ['id' => (int)$dir['id'], 'name' => Security::decryptData($dir['name_encrypted'])],
        'node' => [
            'id' => (int)$node['id'],
            'position_index' => (int)$node['position_index'],
            'map_number' => (int)$node['map_number'],
            'phase_number' => (int)$node['phase_number'],
            'title' => $node['title'],
            'objective' => (string)($node['objective'] ?? ''),
            'questions' => decodeQuestions($node['questions_json']),
            'depends_on_positions' => decodePrerequisitePositions($node['prerequisite_positions_json']),
            'is_published' => (int)$node['is_published'] === 1
        ],
        'is_owner' => $owner,
        'slides' => $slides
    ]);
}
elseif ($action === 'generate_phase_content') {
    $directory_id = (int)($input['directory_id'] ?? 0);
    $node_id = (int)($input['node_id'] ?? 0);
    $dir = verifyTrackOwnership($pdo, $directory_id, $user_id);
    if (!$dir) die(json_encode(['status' => 'error', 'message' => 'Sem permissão para gerar conteúdo.']));

    $stmt = $pdo->prepare("SELECT id, title, objective FROM percursos WHERE id = ? AND track_directory_id = ? LIMIT 1");
    $stmt->execute([$node_id, $directory_id]);
    $node = $stmt->fetch();
    if (!$node) die(json_encode(['status' => 'error', 'message' => 'Fase não encontrada.']));

    $subject = Security::decryptData($dir['name_encrypted']);
    $generated = generateSlidesWithGPT($subject, (string)$node['title'], (string)($node['objective'] ?? ''));

    $upsert = $pdo->prepare("INSERT INTO percurso_slides (node_id, content_json, model, created_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE content_json = VALUES(content_json), model = VALUES(model), created_by = VALUES(created_by)");
    $upsert->execute([$node_id, json_encode($generated['slides'], JSON_UNESCAPED_UNICODE), $generated['model'], $user_id]);

    echo json_encode(['status' => 'success', 'slides' => $generated['slides'], 'model' => $generated['model']]);
}
elseif ($action === 'save_phase_content') {
    $directory_id = (int)($input['directory_id'] ?? 0);
    $node_id = (int)($input['node_id'] ?? 0);
    $dir = verifyTrackOwnership($pdo, $directory_id, $user_id);
    if (!$dir) die(json_encode(['status' => 'error', 'message' => 'Sem permissão para salvar conteúdo.']));

    $slides = (array)($input['slides'] ?? []);
    $norm = [];
    foreach ($slides as $s) {
        $title = trim((string)($s['title'] ?? ''));
        $body = trim((string)($s['body'] ?? ''));
        if ($title === '' || $body === '') continue;
        $norm[] = [
            'type' => trim((string)($s['type'] ?? 'conceito')),
            'title' => $title,
            'body' => $body
        ];
    }
    if (!$norm) die(json_encode(['status' => 'error', 'message' => 'Envie ao menos 1 slide válido.']));

    $upsert = $pdo->prepare("INSERT INTO percurso_slides (node_id, content_json, model, created_by) VALUES (?, ?, 'manual', ?) ON DUPLICATE KEY UPDATE content_json = VALUES(content_json), model = 'manual', created_by = VALUES(created_by)");
    $upsert->execute([$node_id, json_encode(array_slice($norm, 0, 20), JSON_UNESCAPED_UNICODE), $user_id]);

    echo json_encode(['status' => 'success']);
}
elseif ($action === 'complete_phase') {
    $directory_id = (int)($input['directory_id'] ?? 0);
    $node_id = (int)($input['node_id'] ?? 0);
    $dir = verifyTrackAccess($pdo, $directory_id, $user_id);
    if (!$dir) die(json_encode(['status' => 'error', 'message' => 'Trilha indisponível.']));

    $stmtNode = $pdo->prepare("SELECT position_index, prerequisite_positions_json FROM percursos WHERE id = ? AND track_directory_id = ? AND is_published = 1");
    $stmtNode->execute([$node_id, $directory_id]);
    $nodeRow = $stmtNode->fetch();
    $nodePos = $nodeRow ? (int)$nodeRow['position_index'] : 0;
    if ($nodePos <= 0) die(json_encode(['status' => 'error', 'message' => 'Fase inválida.']));

    $pdo->beginTransaction();
    try {
        $stmtProg = $pdo->prepare("SELECT id, current_position, completed_positions_json FROM revisoes WHERE user_id = ? AND track_directory_id = ? LIMIT 1 FOR UPDATE");
        $stmtProg->execute([$user_id, $directory_id]);
        $prog = $stmtProg->fetch();

        $currentPosition = $prog ? max(1, (int)$prog['current_position']) : 1;
        $completed = $prog ? json_decode((string)$prog['completed_positions_json'], true) : [];
        if (!is_array($completed)) $completed = [];
        $completedSet = [];
        foreach ($completed as $p) $completedSet[(int)$p] = true;

        if ($nodePos > $currentPosition) {
            throw new RuntimeException('Fase bloqueada.');
        }
        $dependsOn = decodePrerequisitePositions($nodeRow['prerequisite_positions_json'] ?? null);
        foreach ($dependsOn as $depPos) {
            if (!isset($completedSet[(int)$depPos])) {
                throw new RuntimeException('Conclua os pré-requisitos desta fase antes de continuar.');
            }
        }

        $completedSet[$nodePos] = true;
        while (isset($completedSet[$currentPosition])) {
            $currentPosition++;
        }

        $completedOut = array_keys($completedSet);
        sort($completedOut);

        if ($prog) {
            $upd = $pdo->prepare("UPDATE revisoes SET current_position = ?, completed_positions_json = ? WHERE id = ?");
            $upd->execute([$currentPosition, json_encode($completedOut), $prog['id']]);
        } else {
            $ins = $pdo->prepare("INSERT INTO revisoes (user_id, track_directory_id, current_position, completed_positions_json) VALUES (?, ?, ?, ?)");
            $ins->execute([$user_id, $directory_id, $currentPosition, json_encode($completedOut)]);
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'current_position' => $currentPosition]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Falha ao atualizar progresso.']);
    }
}
else {
    echo json_encode(['status' => 'error', 'message' => 'Ação inválida.']);
}
