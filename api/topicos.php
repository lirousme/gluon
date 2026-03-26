<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Não autorizado.']));
}
if ((int)$_SESSION['user_id'] !== 1) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Acesso restrito ao administrador.']));
}

$pdo = Database::getConnection();
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($input['action'] ?? '');

$pdo->exec("CREATE TABLE IF NOT EXISTS materias (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_materia_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS materia_subtopicos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    materia_id INT UNSIGNED NOT NULL,
    titulo VARCHAR(255) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 1,
    parent_subtopico_id BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (materia_id) REFERENCES materias(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_subtopico_id) REFERENCES materia_subtopicos(id) ON DELETE SET NULL,
    INDEX idx_materia_sort (materia_id, sort_order),
    INDEX idx_materia_parent (materia_id, parent_subtopico_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

try { $pdo->exec("ALTER TABLE materia_subtopicos ADD COLUMN parent_subtopico_id BIGINT UNSIGNED DEFAULT NULL AFTER sort_order"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE materia_subtopicos ADD CONSTRAINT fk_materia_subtopicos_parent FOREIGN KEY (parent_subtopico_id) REFERENCES materia_subtopicos(id) ON DELETE SET NULL"); } catch (Throwable $e) {}
try { $pdo->exec("CREATE INDEX idx_materia_parent ON materia_subtopicos (materia_id, parent_subtopico_id)"); } catch (Throwable $e) {}

function openaiRequest(array $payload): ?array {
    if (trim((string)OPENAI_API_KEY) === '') return null;
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_API_KEY
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$resp) return null;
    return json_decode($resp, true);
}

function normalizeSubtopics(array $items, string $fallbackBase): array {
    $out = [];
    foreach ($items as $item) {
        $title = trim((string)($item['titulo'] ?? $item['title'] ?? $item['nome'] ?? ''));
        if ($title === '') continue;
        $out[] = $title;
    }
    if (count($out) < 5) {
        for ($i = count($out) + 1; $i <= 5; $i++) {
            $out[] = $fallbackBase . ' · Sub-matéria ' . $i;
        }
    }
    return array_slice($out, 0, 5);
}

function generateSubtopics(string $materia, array $orderedList, int $insertAfterPosition, string $contextTitle): array {
    $orderedText = [];
    foreach ($orderedList as $idx => $name) {
        $orderedText[] = ($idx + 1) . '. ' . $name;
    }
    $prompt = [
        'model' => 'gpt-5.4',
        'response_format' => ['type' => 'json_object'],
        'messages' => [
            ['role' => 'system', 'content' => 'Você é um arquiteto curricular. Gere conteúdo progressivo sem repetição. Retorne JSON válido.'],
            ['role' => 'user', 'content' => "Matéria principal: {$materia}\nObjetivo: criar o curso mais detalhado do mundo sobre essa matéria principal, porém em lotes de 5 itens por vez para revisão humana.\n\nLista atual completa e ordenada:\n" . implode("\n", $orderedText) . "\n\nVou inserir 5 novos itens imediatamente após a posição {$insertAfterPosition}.\nContexto do nó pai clicado: {$contextTitle}\nAs novas posições serão: " . ($insertAfterPosition + 1) . ', ' . ($insertAfterPosition + 2) . ', ' . ($insertAfterPosition + 3) . ', ' . ($insertAfterPosition + 4) . ', ' . ($insertAfterPosition + 5) . ".\n\nRegras: não repetir itens já existentes, manter sequência lógica e granularidade fina.\nRetorne SOMENTE JSON no formato: {\"subtopicos\":[{\"titulo\":\"...\"},{\"titulo\":\"...\"},{\"titulo\":\"...\"},{\"titulo\":\"...\"},{\"titulo\":\"...\"}]}"
            ]
        ]
    ];

    $resp = openaiRequest($prompt);
    if (!$resp) {
        return [
            "{$contextTitle} · Fundamentos",
            "{$contextTitle} · Conceitos-chave",
            "{$contextTitle} · Aplicações práticas",
            "{$contextTitle} · Casos avançados",
            "{$contextTitle} · Revisão e domínio"
        ];
    }

    $raw = trim((string)($resp['choices'][0]['message']['content'] ?? ''));
    if (str_starts_with($raw, '```')) {
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/', '', $raw);
        $raw = trim($raw);
    }
    $json = json_decode($raw, true);
    if (!is_array($json) || !isset($json['subtopicos']) || !is_array($json['subtopicos'])) {
        return [
            "{$contextTitle} · Base 1",
            "{$contextTitle} · Base 2",
            "{$contextTitle} · Base 3",
            "{$contextTitle} · Base 4",
            "{$contextTitle} · Base 5"
        ];
    }

    return normalizeSubtopics($json['subtopicos'], $contextTitle);
}

if ($action === 'list_materias') {
    $rows = $pdo->query('SELECT id, nome FROM materias ORDER BY id DESC')->fetchAll();
    echo json_encode(['status' => 'success', 'materias' => $rows]);
    exit;
}

if ($action === 'create_materia') {
    $nome = trim((string)($input['nome'] ?? ''));
    if ($nome === '') die(json_encode(['status' => 'error', 'message' => 'Nome obrigatório.']));
    $stmt = $pdo->prepare('INSERT INTO materias (nome) VALUES (?)');
    $stmt->execute([$nome]);
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'update_materia') {
    $id = (int)($input['id'] ?? 0);
    $nome = trim((string)($input['nome'] ?? ''));
    if ($id <= 0 || $nome === '') die(json_encode(['status' => 'error', 'message' => 'Dados inválidos.']));
    $stmt = $pdo->prepare('UPDATE materias SET nome = ? WHERE id = ?');
    $stmt->execute([$nome, $id]);
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'delete_materia') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) die(json_encode(['status' => 'error', 'message' => 'ID inválido.']));
    $stmt = $pdo->prepare('DELETE FROM materias WHERE id = ?');
    $stmt->execute([$id]);
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'list_subtopicos') {
    $materia_id = (int)($input['materia_id'] ?? 0);
    if ($materia_id <= 0) die(json_encode(['status' => 'error', 'message' => 'Matéria inválida.']));

    $m = $pdo->prepare('SELECT id, nome FROM materias WHERE id = ?');
    $m->execute([$materia_id]);
    $materia = $m->fetch();
    if (!$materia) die(json_encode(['status' => 'error', 'message' => 'Matéria não encontrada.']));

    $stmt = $pdo->prepare('SELECT id, titulo, sort_order, parent_subtopico_id FROM materia_subtopicos WHERE materia_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$materia_id]);
    echo json_encode(['status' => 'success', 'materia' => $materia, 'subtopicos' => $stmt->fetchAll()]);
    exit;
}

if ($action === 'reorder_subtopicos') {
    $materia_id = (int)($input['materia_id'] ?? 0);
    $order = $input['order'] ?? [];
    if ($materia_id <= 0 || !is_array($order) || count($order) === 0) {
        die(json_encode(['status' => 'error', 'message' => 'Dados inválidos.']));
    }
    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare('UPDATE materia_subtopicos SET sort_order = ? WHERE id = ? AND materia_id = ?');
        $pos = 1;
        foreach ($order as $id) {
            $upd->execute([$pos, (int)$id, $materia_id]);
            $pos++;
        }
        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Erro ao salvar ordem.']);
    }
    exit;
}

if ($action === 'generate_subtopicos') {
    $materia_id = (int)($input['materia_id'] ?? 0);
    $parent_subtopico_id = isset($input['parent_subtopico_id']) ? (int)$input['parent_subtopico_id'] : null;
    $manual_seed = trim((string)($input['manual_seed'] ?? ''));
    if ($materia_id <= 0) die(json_encode(['status' => 'error', 'message' => 'Matéria inválida.']));

    $m = $pdo->prepare('SELECT id, nome FROM materias WHERE id = ?');
    $m->execute([$materia_id]);
    $materia = $m->fetch();
    if (!$materia) die(json_encode(['status' => 'error', 'message' => 'Matéria não encontrada.']));

    $stmt = $pdo->prepare('SELECT id, titulo, sort_order FROM materia_subtopicos WHERE materia_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$materia_id]);
    $all = $stmt->fetchAll();
    $orderedTitles = array_map(fn($r) => (string)$r['titulo'], $all);

    $insertAfterPos = count($all);
    $contextTitle = $manual_seed !== '' ? $manual_seed : (string)$materia['nome'];
    if ($parent_subtopico_id) {
        foreach ($all as $row) {
            if ((int)$row['id'] === $parent_subtopico_id) {
                $insertAfterPos = (int)$row['sort_order'];
                $contextTitle = (string)$row['titulo'];
                break;
            }
        }
    }

    $newTitles = generateSubtopics((string)$materia['nome'], $orderedTitles, $insertAfterPos, $contextTitle);

    $pdo->beginTransaction();
    try {
        if ($insertAfterPos < count($all)) {
            $shift = $pdo->prepare('UPDATE materia_subtopicos SET sort_order = sort_order + 5 WHERE materia_id = ? AND sort_order > ?');
            $shift->execute([$materia_id, $insertAfterPos]);
        }

        $ins = $pdo->prepare('INSERT INTO materia_subtopicos (materia_id, titulo, sort_order, parent_subtopico_id) VALUES (?, ?, ?, ?)');
        for ($i = 0; $i < 5; $i++) {
            $ins->execute([$materia_id, $newTitles[$i], $insertAfterPos + $i + 1, $parent_subtopico_id]);
        }
        $pdo->commit();

        echo json_encode(['status' => 'success']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Falha ao gerar sub-matérias.']);
    }
    exit;
}

if ($action === 'update_subtopico') {
    $id = (int)($input['id'] ?? 0);
    $titulo = trim((string)($input['titulo'] ?? ''));
    if ($id <= 0 || $titulo === '') die(json_encode(['status' => 'error', 'message' => 'Dados inválidos.']));
    $stmt = $pdo->prepare('UPDATE materia_subtopicos SET titulo = ? WHERE id = ?');
    $stmt->execute([$titulo, $id]);
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'delete_subtopico') {
    $id = (int)($input['id'] ?? 0);
    $materia_id = (int)($input['materia_id'] ?? 0);
    if ($id <= 0 || $materia_id <= 0) die(json_encode(['status' => 'error', 'message' => 'Dados inválidos.']));
    $pdo->beginTransaction();
    try {
        $posStmt = $pdo->prepare('SELECT sort_order FROM materia_subtopicos WHERE id = ? AND materia_id = ?');
        $posStmt->execute([$id, $materia_id]);
        $row = $posStmt->fetch();
        if (!$row) throw new RuntimeException('Not found');
        $pos = (int)$row['sort_order'];

        $pdo->prepare('DELETE FROM materia_subtopicos WHERE id = ? AND materia_id = ?')->execute([$id, $materia_id]);
        $pdo->prepare('UPDATE materia_subtopicos SET sort_order = sort_order - 1 WHERE materia_id = ? AND sort_order > ?')->execute([$materia_id, $pos]);

        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Erro ao excluir.']);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Ação inválida.']);
