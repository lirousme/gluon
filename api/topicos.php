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
$user_id = (int)$_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($input['action'] ?? '');

function decryptDirectoryName(array $row): string {
    return Security::decryptData((string)$row['name_encrypted']);
}

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
        return [
            'new_titles' => $fallbackNew,
            'final_titles' => buildFinalListWithInsertion($orderedList, $fallbackNew, $insertAfterPosition)
        ];
    }

    $rawTitles = [];
    foreach (($json['novos_subtopicos'] ?? $json['subtopicos'] ?? []) as $item) {
        $rawTitles[] = (string)($item['titulo'] ?? $item['title'] ?? $item['nome'] ?? '');
    }

    $uniqueAtomic = buildUniqueAtomicTitles($rawTitles, $orderedList, $contextTitle, 5);
    if (count($uniqueAtomic) < 5) $uniqueAtomic = normalizeSubtopics($json['novos_subtopicos'] ?? [], $contextTitle);
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
    $existingCounter = listToMap($orderedList);
    $replaced = 0;
    foreach ($finalAdjusted as $idx => $title) {
        $k = mb_strtolower(cleanGeneratedTitle((string)$title));
        if (($existingCounter[$k] ?? 0) > 0) {
            $existingCounter[$k]--;
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

function getTrailById(PDO $pdo, int $user_id, int $trail_id): ?array {
    $stmt = $pdo->prepare('SELECT id, name_encrypted FROM directories WHERE id = ? AND user_id = ? AND type = 8 LIMIT 1');
    $stmt->execute([$trail_id, $user_id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function listPhases(PDO $pdo, int $user_id, int $trail_id): array {
    $stmt = $pdo->prepare('SELECT id, name_encrypted, sort_order FROM directories WHERE user_id = ? AND parent_id = ? AND type = 10 ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$user_id, $trail_id]);
    return $stmt->fetchAll() ?: [];
}

function normalizePhaseSortOrder(PDO $pdo, int $user_id, int $trail_id): void {
    $rows = listPhases($pdo, $user_id, $trail_id);
    $upd = $pdo->prepare('UPDATE directories SET sort_order = ? WHERE id = ? AND user_id = ?');
    foreach ($rows as $idx => $row) {
        if ((int)$row['sort_order'] !== ($idx + 1)) {
            $upd->execute([$idx + 1, (int)$row['id'], $user_id]);
        }
    }
}

if ($action === 'list_materias') {
    $stmt = $pdo->prepare('SELECT id, name_encrypted FROM directories WHERE user_id = ? AND type = 8 ORDER BY sort_order ASC, id DESC');
    $stmt->execute([$user_id]);
    $materias = [];
    foreach ($stmt->fetchAll() as $row) {
        $materias[] = ['id' => (int)$row['id'], 'nome' => decryptDirectoryName($row)];
    }
    echo json_encode(['status' => 'success', 'materias' => $materias]);
    exit;
}

if ($action === 'create_materia') {
    $nome = trim((string)($input['nome'] ?? ''));
    if ($nome === '') die(json_encode(['status' => 'error', 'message' => 'Nome obrigatório.']));

    $stmtMax = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM directories WHERE user_id = ? AND parent_id IS NULL');
    $stmtMax->execute([$user_id]);
    $nextOrder = ((int)$stmtMax->fetchColumn()) + 1;

    $stmt = $pdo->prepare('INSERT INTO directories (user_id, parent_id, type, name_encrypted, default_view, open_mode, new_item_position, sort_order, icon, icon_color_from, icon_color_to, child_default_type, child_default_view) VALUES (?, NULL, 8, ?, "grid", "fullscreen", "end", ?, "fa-map-location-dot", "#3b82f6", "#6366f1", 10, "grid")');
    $stmt->execute([$user_id, Security::encryptData($nome), $nextOrder]);

    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'update_materia') {
    $id = (int)($input['id'] ?? 0);
    $nome = trim((string)($input['nome'] ?? ''));
    if ($id <= 0 || $nome === '') die(json_encode(['status' => 'error', 'message' => 'Dados inválidos.']));

    $stmt = $pdo->prepare('UPDATE directories SET name_encrypted = ? WHERE id = ? AND user_id = ? AND type = 8');
    $stmt->execute([Security::encryptData($nome), $id, $user_id]);
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'delete_materia') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) die(json_encode(['status' => 'error', 'message' => 'ID inválido.']));

    $stmt = $pdo->prepare('DELETE FROM directories WHERE id = ? AND user_id = ? AND type = 8');
    $stmt->execute([$id, $user_id]);
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'list_subtopicos') {
    $materia_id = (int)($input['materia_id'] ?? 0);
    if ($materia_id <= 0) die(json_encode(['status' => 'error', 'message' => 'Matéria inválida.']));

    $materia = getTrailById($pdo, $user_id, $materia_id);
    if (!$materia) die(json_encode(['status' => 'error', 'message' => 'Matéria não encontrada.']));

    normalizePhaseSortOrder($pdo, $user_id, $materia_id);
    $phases = listPhases($pdo, $user_id, $materia_id);
    $subtopicos = [];
    foreach ($phases as $row) {
        $subtopicos[] = [
            'id' => (int)$row['id'],
            'titulo' => decryptDirectoryName($row),
            'sort_order' => (int)$row['sort_order']
        ];
    }

    echo json_encode([
        'status' => 'success',
        'materia' => ['id' => (int)$materia['id'], 'nome' => decryptDirectoryName($materia)],
        'subtopicos' => $subtopicos
    ]);
    exit;
}

if ($action === 'reorder_subtopicos') {
    $materia_id = (int)($input['materia_id'] ?? 0);
    $order = $input['order'] ?? [];
    if ($materia_id <= 0 || !is_array($order) || count($order) === 0) {
        die(json_encode(['status' => 'error', 'message' => 'Dados inválidos.']));
    }

    $materia = getTrailById($pdo, $user_id, $materia_id);
    if (!$materia) die(json_encode(['status' => 'error', 'message' => 'Matéria inválida.']));

    $rows = listPhases($pdo, $user_id, $materia_id);
    if (count($rows) !== count($order)) {
        die(json_encode(['status' => 'error', 'message' => 'Lista incompleta para reordenação.']));
    }

    $validIds = array_map(fn($r) => (int)$r['id'], $rows);
    $orderIds = array_map(fn($id) => (int)$id, $order);
    sort($validIds);
    $orderSorted = $orderIds;
    sort($orderSorted);
    if ($validIds !== $orderSorted) {
        die(json_encode(['status' => 'error', 'message' => 'Ordem inválida.']));
    }

    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare('UPDATE directories SET sort_order = ? WHERE id = ? AND user_id = ? AND parent_id = ? AND type = 10');
        foreach ($orderIds as $idx => $id) {
            $upd->execute([$idx + 1, $id, $user_id, $materia_id]);
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

    $materia = getTrailById($pdo, $user_id, $materia_id);
    if (!$materia) die(json_encode(['status' => 'error', 'message' => 'Matéria não encontrada.']));

    normalizePhaseSortOrder($pdo, $user_id, $materia_id);
    $all = listPhases($pdo, $user_id, $materia_id);
    $orderedTitles = array_map(fn($r) => decryptDirectoryName($r), $all);

    $insertAfterPos = count($all);
    $contextTitle = $manual_seed !== '' ? $manual_seed : decryptDirectoryName($materia);
    if ($parent_subtopico_id) {
        foreach ($all as $idx => $row) {
            if ((int)$row['id'] === $parent_subtopico_id) {
                $insertAfterPos = $idx + 1;
                $contextTitle = decryptDirectoryName($row);
                break;
            }
        }
    }

    $generated = generateSubtopics(decryptDirectoryName($materia), $orderedTitles, $insertAfterPos, $contextTitle);
    $newTitles = $generated['new_titles'] ?? [];
    $finalTitles = $generated['final_titles'] ?? [];
    if (count($newTitles) !== 5 || count($finalTitles) !== count($orderedTitles) + 5) {
        die(json_encode(['status' => 'error', 'message' => 'Falha ao montar novas sub-matérias.']));
    }

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('INSERT INTO directories (user_id, parent_id, type, name_encrypted, default_view, open_mode, new_item_position, sort_order, icon, icon_color_from, icon_color_to) VALUES (?, ?, 10, ?, "grid", "fullscreen", "end", 0, "fa-layer-group", "#f59e0b", "#d97706")');
        for ($i = 0; $i < 5; $i++) {
            $ins->execute([$user_id, $materia_id, Security::encryptData($newTitles[$i])]);
        }

        $rows = listPhases($pdo, $user_id, $materia_id);
        $remainingByTitle = [];
        foreach ($rows as $row) {
            $decoded = decryptDirectoryName($row);
            $k = mb_strtolower(cleanGeneratedTitle($decoded));
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

        $upd = $pdo->prepare('UPDATE directories SET sort_order = ? WHERE id = ? AND user_id = ? AND parent_id = ? AND type = 10');
        foreach ($resolvedIds as $idx => $id) {
            $upd->execute([$idx + 1, $id, $user_id, $materia_id]);
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

    $stmt = $pdo->prepare('UPDATE directories SET name_encrypted = ? WHERE id = ? AND user_id = ? AND type = 10');
    $stmt->execute([Security::encryptData($titulo), $id, $user_id]);
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'delete_subtopico') {
    $id = (int)($input['id'] ?? 0);
    $materia_id = (int)($input['materia_id'] ?? 0);
    if ($id <= 0 || $materia_id <= 0) die(json_encode(['status' => 'error', 'message' => 'Dados inválidos.']));

    $materia = getTrailById($pdo, $user_id, $materia_id);
    if (!$materia) die(json_encode(['status' => 'error', 'message' => 'Matéria inválida.']));

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM directories WHERE id = ? AND user_id = ? AND parent_id = ? AND type = 10')->execute([$id, $user_id, $materia_id]);
        normalizePhaseSortOrder($pdo, $user_id, $materia_id);
        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Erro ao excluir.']);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Ação inválida.']);
