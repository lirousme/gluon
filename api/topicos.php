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

function cleanGeneratedTitle(string $title): string {
    $title = trim($title);
    $title = preg_replace('/^\d+[\)\.\-\:]\s*/u', '', $title);
    $title = trim($title, " \t\n\r\0\x0B\"'`");
    $title = preg_replace('/\s+/u', ' ', $title);
    return trim((string)$title);
}

function hasAtomicTitleFormat(string $title): bool {
    if ($title === '') return false;
    if (mb_strlen($title) < 4 || mb_strlen($title) > 90) return false;
    if (preg_match('/[,;:\/\|\(\)]/u', $title)) return false;
    if (preg_match('/\s+e\s+/iu', $title)) return false;
    return true;
}

function buildUniqueAtomicTitles(
    array $candidateTitles,
    array $existingOrderedTitles,
    string $contextTitle,
    int $count = 5
): array {
    $existingMap = [];
    foreach ($existingOrderedTitles as $existing) {
        $k = mb_strtolower(cleanGeneratedTitle((string)$existing));
        if ($k !== '') $existingMap[$k] = true;
    }

    $out = [];
    $seen = [];
    foreach ($candidateTitles as $candidate) {
        $clean = cleanGeneratedTitle((string)$candidate);
        if (!hasAtomicTitleFormat($clean)) continue;

        $k = mb_strtolower($clean);
        if (isset($existingMap[$k]) || isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $clean;
        if (count($out) >= $count) break;
    }

    if (count($out) < $count) {
        $fallbackSuffixes = ['Fundamentos', 'Conceitos essenciais', 'Métodos', 'Prática guiada', 'Aprofundamento'];
        foreach ($fallbackSuffixes as $suffix) {
            $candidate = cleanGeneratedTitle($contextTitle . ' - ' . $suffix);
            $k = mb_strtolower($candidate);
            if (!hasAtomicTitleFormat($candidate)) continue;
            if (isset($existingMap[$k]) || isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = $candidate;
            if (count($out) >= $count) break;
        }
    }

    return array_slice($out, 0, $count);
}

function listToMap(array $titles): array {
    $map = [];
    foreach ($titles as $title) {
        $k = mb_strtolower(cleanGeneratedTitle((string)$title));
        if ($k === '') continue;
        $map[$k] = ($map[$k] ?? 0) + 1;
    }
    return $map;
}

function canPreserveAllExisting(array $existingMap, array $finalMap): bool {
    foreach ($existingMap as $k => $count) {
        if (($finalMap[$k] ?? 0) < $count) return false;
    }
    return true;
}

function computeNewTitlesFromFinalList(array $existingTitles, array $finalTitles): array {
    $existingCount = listToMap($existingTitles);
    $newTitles = [];

    foreach ($finalTitles as $title) {
        $clean = cleanGeneratedTitle((string)$title);
        if ($clean === '') continue;
        $k = mb_strtolower($clean);
        if (($existingCount[$k] ?? 0) > 0) {
            $existingCount[$k]--;
            continue;
        }
        $newTitles[] = $clean;
    }

    return $newTitles;
}

function buildFinalListWithInsertion(array $orderedList, array $newTitles, int $insertAfterPosition): array {
    $before = array_slice($orderedList, 0, $insertAfterPosition);
    $after = array_slice($orderedList, $insertAfterPosition);
    return array_values(array_merge($before, $newTitles, $after));
}

function normalizeTitleListFromJson(array $items): array {
    $out = [];
    foreach ($items as $item) {
        if (is_array($item)) {
            $out[] = (string)($item['titulo'] ?? $item['title'] ?? $item['nome'] ?? '');
            continue;
        }
        $out[] = (string)$item;
    }
    return array_map(fn($t) => cleanGeneratedTitle((string)$t), $out);
}

function generateSubtopics(string $materia, array $orderedList, int $insertAfterPosition, string $contextTitle): array {
    $orderedText = [];
    foreach ($orderedList as $idx => $name) {
        $orderedText[] = ($idx + 1) . '. ' . $name;
    }
    $plannedPositions = [
        $insertAfterPosition + 1,
        $insertAfterPosition + 2,
        $insertAfterPosition + 3,
        $insertAfterPosition + 4,
        $insertAfterPosition + 5
    ];
    $orderedListText = implode("\n", $orderedText);
    $positionsText = implode(', ', $plannedPositions);

    $prompt = [
        'model' => 'gpt-5.4',
        'response_format' => ['type' => 'json_object'],
        'messages' => [
            ['role' => 'system', 'content' => 'Você é um arquiteto curricular especialista em decomposição progressiva de conhecimento. Sempre retorna JSON válido e sem texto extra.'],
            ['role' => 'user', 'content' => "Matéria principal: {$materia}\nObjetivo: criar o curso mais detalhado do mundo sobre a matéria principal. Como o usuário precisa revisar, a expansão deve ocorrer em lotes de 5 por vez.\n\nLista atual completa e ordenada:\n{$orderedListText}\n\nContexto clicado para expansão: {$contextTitle}\nInserção de novos itens logo após a posição {$insertAfterPosition}.\nPosições-alvo inicialmente reservadas para os novos itens: {$positionsText}.\n\nRegras obrigatórias:\n1) Você nunca pode excluir itens já existentes.\n2) Você deve criar exatamente 5 novos títulos.\n3) Você pode reordenar a lista completa para melhorar a progressão lógica.\n4) Títulos atômicos: um assunto por título.\n5) Não usar vírgula, ponto e vírgula, dois pontos, barra, parênteses nem a conjunção ' e '.\n6) Não repetir títulos existentes nem repetir entre os novos.\n7) Cada título precisa ter de 4 a 90 caracteres.\n\nRetorne SOMENTE JSON no formato:\n{\"novos_subtopicos\":[{\"titulo\":\"...\"},{\"titulo\":\"...\"},{\"titulo\":\"...\"},{\"titulo\":\"...\"},{\"titulo\":\"...\"}],\"lista_final\":[{\"titulo\":\"...\"}]}\n\n\"lista_final\" deve conter TODOS os tópicos antigos + 5 novos, em ordem final."
            ]
        ]
    ];

    $resp = openaiRequest($prompt);
    $fallbackNew = [
        "{$contextTitle} · Fundamentos",
        "{$contextTitle} · Conceitos-chave",
        "{$contextTitle} · Aplicações práticas",
        "{$contextTitle} · Casos avançados",
        "{$contextTitle} · Revisão e domínio"
    ];
    if (!$resp) return [
        'new_titles' => $fallbackNew,
        'final_titles' => buildFinalListWithInsertion($orderedList, $fallbackNew, $insertAfterPosition)
    ];

    $raw = trim((string)($resp['choices'][0]['message']['content'] ?? ''));
    if (str_starts_with($raw, '```')) {
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/', '', $raw);
        $raw = trim($raw);
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        $baseFallback = [
            "{$contextTitle} · Base 1",
            "{$contextTitle} · Base 2",
            "{$contextTitle} · Base 3",
            "{$contextTitle} · Base 4",
            "{$contextTitle} · Base 5"
        ];
        return [
            'new_titles' => $baseFallback,
            'final_titles' => buildFinalListWithInsertion($orderedList, $baseFallback, $insertAfterPosition)
        ];
    }

    $rawTitles = [];
    foreach (($json['novos_subtopicos'] ?? $json['subtopicos'] ?? []) as $item) {
        $rawTitles[] = (string)($item['titulo'] ?? $item['title'] ?? $item['nome'] ?? '');
    }

    $uniqueAtomic = buildUniqueAtomicTitles($rawTitles, $orderedList, $contextTitle, 5);
    if (count($uniqueAtomic) < 5 && isset($json['novos_subtopicos']) && is_array($json['novos_subtopicos'])) {
        $uniqueAtomic = normalizeSubtopics($json['novos_subtopicos'], $contextTitle);
    }
    if (count($uniqueAtomic) < 5) $uniqueAtomic = normalizeSubtopics($json['subtopicos'] ?? [], $contextTitle);

    $fallbackFinal = buildFinalListWithInsertion($orderedList, $uniqueAtomic, $insertAfterPosition);

    if (!isset($json['lista_final']) || !is_array($json['lista_final'])) {
        return ['new_titles' => $uniqueAtomic, 'final_titles' => $fallbackFinal];
    }

    $finalTitles = array_values(array_filter(normalizeTitleListFromJson($json['lista_final']), fn($t) => $t !== ''));
    if (count($finalTitles) !== count($orderedList) + 5) {
        return ['new_titles' => $uniqueAtomic, 'final_titles' => $fallbackFinal];
    }

    $existingMap = listToMap($orderedList);
    $finalMap = listToMap($finalTitles);
    if (!canPreserveAllExisting($existingMap, $finalMap)) {
        return ['new_titles' => $uniqueAtomic, 'final_titles' => $fallbackFinal];
    }

    $newFromFinal = computeNewTitlesFromFinalList($orderedList, $finalTitles);
    $validatedNew = buildUniqueAtomicTitles($newFromFinal, $orderedList, $contextTitle, 5);
    if (count($validatedNew) < 5) {
        return ['new_titles' => $uniqueAtomic, 'final_titles' => $fallbackFinal];
    }

    $finalAdjusted = $finalTitles;
    $replaced = 0;
    foreach ($finalAdjusted as $idx => $title) {
        $k = mb_strtolower(cleanGeneratedTitle((string)$title));
        if (($existingMap[$k] ?? 0) > 0) {
            $existingMap[$k]--;
            continue;
        }
        if ($replaced < 5) {
            $finalAdjusted[$idx] = $validatedNew[$replaced];
            $replaced++;
            continue;
        }
        unset($finalAdjusted[$idx]);
    }
    if ($replaced !== 5) {
        return ['new_titles' => $uniqueAtomic, 'final_titles' => $fallbackFinal];
    }

    return ['new_titles' => $validatedNew, 'final_titles' => array_values($finalAdjusted)];
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

    $generated = generateSubtopics((string)$materia['nome'], $orderedTitles, $insertAfterPos, $contextTitle);
    $newTitles = $generated['new_titles'] ?? [];
    $finalTitles = $generated['final_titles'] ?? [];
    if (count($newTitles) !== 5 || count($finalTitles) !== count($orderedTitles) + 5) {
        die(json_encode(['status' => 'error', 'message' => 'Falha ao montar novas sub-matérias.']));
    }

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('INSERT INTO materia_subtopicos (materia_id, titulo, sort_order, parent_subtopico_id) VALUES (?, ?, 0, ?)');
        for ($i = 0; $i < 5; $i++) {
            $ins->execute([$materia_id, $newTitles[$i], $parent_subtopico_id]);
        }

        $rowsStmt = $pdo->prepare('SELECT id, titulo FROM materia_subtopicos WHERE materia_id = ? ORDER BY sort_order ASC, id ASC');
        $rowsStmt->execute([$materia_id]);
        $rows = $rowsStmt->fetchAll();

        $remainingByTitle = [];
        foreach ($rows as $row) {
            $k = mb_strtolower(cleanGeneratedTitle((string)$row['titulo']));
            if (!isset($remainingByTitle[$k])) $remainingByTitle[$k] = [];
            $remainingByTitle[$k][] = (int)$row['id'];
        }

        $resolvedIds = [];
        foreach ($finalTitles as $title) {
            $k = mb_strtolower(cleanGeneratedTitle((string)$title));
            if (!isset($remainingByTitle[$k]) || count($remainingByTitle[$k]) === 0) {
                throw new RuntimeException('Final order inválida.');
            }
            $resolvedIds[] = array_shift($remainingByTitle[$k]);
        }

        if (count($resolvedIds) !== count($rows)) {
            throw new RuntimeException('Final order incompleta.');
        }

        $upd = $pdo->prepare('UPDATE materia_subtopicos SET sort_order = ? WHERE id = ? AND materia_id = ?');
        foreach ($resolvedIds as $idx => $id) {
            $upd->execute([$idx + 1, $id, $materia_id]);
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
