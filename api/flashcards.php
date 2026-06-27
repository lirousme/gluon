<?php
// Arquivo: flashcards.php
// Diretório: public_html/gluon/api/flashcards.php

/**
 * MICRO-API DE FLASHCARDS
 * Pilar: Seguro, Rápido e Escalável.
 * Gerencia CRUD, Criptografia, Repetição Espaçada, Modos de Deck e Geração de Áudio (TTS).
 * Atualização: Suporte para salvamento e deleção de imagens do Flashcard com criptografia.
 */

require_once __DIR__ . '/../config/database.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json; charset=utf-8');

set_exception_handler(function (Throwable $e) {
    error_log('[flashcards][uncaught_exception] ' . $e->getMessage());
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erro interno da API de flashcards.']);
});

register_shutdown_function(function () {
    $lastError = error_get_last();
    if ($lastError && in_array($lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[flashcards][fatal] ' . ($lastError['message'] ?? 'fatal') . ' @ ' . ($lastError['file'] ?? '-') . ':' . ($lastError['line'] ?? 0));
        if (!headers_sent()) http_response_code(500);
        if (!ob_get_length()) {
            echo json_encode(['status' => 'error', 'message' => 'Falha fatal na API de flashcards.']);
        }
    }
});

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Não autorizado. Faça login.']));
}

$pdo = Database::getConnection();
ensureFlashcardTagsNumericMetadataSchema($pdo);
ensureFlashcardTagsCreatorSchema($pdo);
ensureTagFamilyOrderSchema($pdo);
ensureFlashcardsPublicToggleSchema($pdo);
ensureFlashcardsDynamicTextTypeSchema($pdo);
ensureFlashcardsQuestionAnswerSchema($pdo);
$user_id = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? ($_GET['action'] ?? '');

/**
 * Normaliza destinos de retorno recebidos do cliente para impedir redirects externos.
 */
function normalizeReturnTarget(?string $rawTarget): string {
    $target = trim((string)$rawTarget);
    if ($target === '') return '';

    for ($i = 0; $i < 2; $i += 1) {
        $decoded = rawurldecode($target);
        if ($decoded === $target) break;
        $target = $decoded;
    }

    $parts = parse_url($target);
    if ($parts === false) return '';

    if (isset($parts['scheme']) || isset($parts['host'])) {
        $requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $targetHost = strtolower((string)($parts['host'] ?? ''));
        if ($targetHost === '' || $requestHost === '' || $targetHost !== $requestHost) return '';
    }

    $path = (string)($parts['path'] ?? '');
    if ($path === '' || $path[0] !== '/') return '';
    if (str_starts_with($path, '//')) return '';

    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
    $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';
    return $path . $query . $fragment;
}

/**
 * Função sanitizeTagIds: Normaliza uma lista de IDs de tags recebida na requisição, mantendo apenas inteiros positivos únicos.
 */
function sanitizeInfoType($value): int {
    $type = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['default' => 2]]);
    return in_array($type, [0, 1, 2, 3, 4, 5], true) ? $type : 2;
}

function sanitizeQuestionAnswer($value, int $infoType): ?int {
    if ($infoType !== 2) return null;
    if ($value === null || $value === '') return null;
    $answer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['default' => null]]);
    return in_array($answer, [0, 1], true) ? $answer : null;
}

function sanitizeTagIds($rawTagIds): array {
    if (is_string($rawTagIds)) {
        $rawTagIds = preg_split('/[\s,]+/', $rawTagIds, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    } elseif (!is_array($rawTagIds)) {
        $rawTagIds = [$rawTagIds];
    }
    return array_values(array_unique(array_filter(array_map('intval', $rawTagIds), static fn($id) => $id > 0)));
}

function sanitizeDynamicTextType($value): string {
    $type = trim((string)$value);
    return in_array($type, ['none', 'subject', 'object', 'verb'], true) ? $type : 'none';
}

function dynamicTextTypeToInt(string $type): int {
    return ['none' => 0, 'subject' => 1, 'object' => 2, 'verb' => 3][$type] ?? 0;
}

function dynamicTextTypeFromInt($value): string {
    $type = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['default' => 0]]);
    return [0 => 'none', 1 => 'subject', 2 => 'object', 3 => 'verb'][$type] ?? 'none';
}

function getDynamicSubjectMotherTags(PDO $pdo, int $user_id, array $subjectTagIds): array {
    $subjectTagIds = array_values(array_unique(array_filter(array_map('intval', $subjectTagIds), static fn($id) => $id > 0)));
    if (empty($subjectTagIds)) return [];

    $placeholders = implode(',', array_fill(0, count($subjectTagIds), '?'));
    $stmt = $pdo->prepare("
        SELECT DISTINCT t.id, t.name_encrypted, t.name_pt_br_encrypted, t.numero, t.sigla_simbolo
        FROM relacoes_taguineas r
        INNER JOIN flashcard_tags t ON t.id = r.id_tag_mother
        WHERE r.tipo_de_relacao = 24
          AND r.id_tag_child IN ($placeholders)
          AND r.id_user IN (?, 5)
          AND t.user_id IN (?, 5)
        ORDER BY t.id ASC
    ");
    $stmt->execute(array_merge($subjectTagIds, [$user_id, $user_id]));

    $mothers = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $label = '';
        if (!empty($row['name_encrypted'])) $label = trim((string)Security::decryptData((string)$row['name_encrypted']));
        if ($label === '' && !empty($row['name_pt_br_encrypted'])) $label = trim((string)Security::decryptData((string)$row['name_pt_br_encrypted']));
        if ($label === '' && normalizeNullableTagMetadataText($row['numero'] ?? null) !== null) $label = (string)normalizeNullableTagMetadataText($row['numero'] ?? null);
        if ($label === '' && normalizeNullableTagMetadataText($row['sigla_simbolo'] ?? null) !== null) $label = (string)normalizeNullableTagMetadataText($row['sigla_simbolo'] ?? null);
        if ($label === '') continue;
        $mothers[] = ['id' => (int)$row['id'], 'label' => $label];
    }
    return $mothers;
}

function renderDynamicSubjectFront(string $frontTemplate, string $subjectLabel): string {
    $tokens = ['$sujeitoDinamico', '{{sujeitoDinamico}}', '{sujeitoDinamico}'];
    foreach ($tokens as $token) {
        if (str_contains($frontTemplate, $token)) {
            return str_replace($token, $subjectLabel, $frontTemplate);
        }
    }
    return $frontTemplate;
}

function sanitizeGraphInfoTypes($rawTypes): array {
    if (is_string($rawTypes)) {
        $rawTypes = preg_split('/[\s,]+/', $rawTypes, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    } elseif (!is_array($rawTypes)) {
        $rawTypes = $rawTypes === null ? [] : [$rawTypes];
    }
    return array_values(array_unique(array_filter(array_map('intval', $rawTypes), static fn($type) => in_array($type, [0, 1, 2, 3, 4, 5], true))));
}

function sanitizeGraphTagLinkTypes($rawTypes): array {
    $allowedTypes = ['subject', 'object', 'lexical_chunk'];
    if (is_string($rawTypes)) {
        $rawTypes = preg_split('/[\s,]+/', $rawTypes, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    } elseif (!is_array($rawTypes)) {
        $rawTypes = $rawTypes === null ? [] : [$rawTypes];
    }
    $types = array_values(array_unique(array_filter(array_map(static fn($type) => trim((string)$type), $rawTypes), static fn($type) => in_array($type, $allowedTypes, true))));
    return $types;
}

function getGraphTagLinkColumnsByType(): array {
    return [
        'subject' => ['subjects_links' => ['tag_id']],
        'object' => ['objects_links' => ['tag_id']],
        'lexical_chunk' => ['lexical_chunks_links' => ['tag_id']],
    ];
}

function getGraphTagLinkColumnsForTypes(array $types): array {
    $columnsByType = getGraphTagLinkColumnsByType();
    $columnsByTable = [];
    foreach ($types as $type) {
        foreach (($columnsByType[$type] ?? []) as $table => $columns) {
            if (!isset($columnsByTable[$table])) $columnsByTable[$table] = [];
            $columnsByTable[$table] = array_values(array_unique(array_merge($columnsByTable[$table], $columns)));
        }
    }
    return $columnsByTable;
}

/**
 * Garante que nomes criptografados de tipos de relação não sejam truncados.
 *
 * O AES-256-GCM é salvo em base64 e cresce conforme o tamanho do texto original;
 * campos VARCHAR curtos conseguem armazenar nomes pequenos (ex.: "Documentação"),
 * mas truncam nomes maiores com parênteses/listas, tornando a descriptografia impossível.
 */
function ensureRelationTypeEncryptedNameCapacity(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM tipos_de_relacoes LIKE 'nome'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        $type = strtolower((string)($column['Type'] ?? ''));

        $needsAlter = true;
        if (preg_match('/^(?:var)?char\((\d+)\)/', $type, $matches)) {
            $needsAlter = (int)$matches[1] < 512;
        } elseif (preg_match('/(?:text|blob|json|mediumtext|longtext)/', $type)) {
            $needsAlter = false;
        }

        if ($needsAlter) {
            $pdo->exec('ALTER TABLE tipos_de_relacoes MODIFY nome TEXT NOT NULL');
        }
    } catch (Throwable $e) {
        error_log('[flashcards][relation_types_schema] ' . $e->getMessage());
    }
}

function looksLikeEncryptedRelationTypeName(string $value): bool
{
    $trimmed = trim($value);
    if ($trimmed === '' || preg_match('/^[A-Za-z0-9+\/=\s]+$/', $trimmed) !== 1) return false;

    $decoded = base64_decode(str_replace(' ', '+', $trimmed), true);
    $ivLength = openssl_cipher_iv_length('aes-256-gcm');

    return $decoded !== false && strlen($decoded) > ($ivLength + 16);
}

const CUSTOM_RULE_AUTO_TAG_FAMILY_ON_TAG_CREATE = 1;

/**
 * Executor de Regras Customizadas para eventos de tags.
 *
 * Esta é a abstração central para regras identificadas por `numero_da_regra`:
 * novas regras devem ganhar uma constante, uma função handler e uma entrada no
 * dispatcher do evento correspondente (ex.: criação de tag).
 */
function executeTagCreationCustomRules(PDO $pdo, int $userId, int $newTagId): void
{
    if ($userId <= 0 || $newTagId <= 0) return;

    applyAutoTagFamilyCustomRulesForTags($pdo, $userId, [$newTagId]);
}

function executeTagFamilyRelationCustomRules(PDO $pdo, int $userId, array $candidateTagIds): void
{
    if ($userId <= 0) return;

    applyAutoTagFamilyCustomRulesForTags($pdo, $userId, $candidateTagIds);
}

function fetchUserCustomRuleIds(PDO $pdo, int $userId, int $ruleNumber): array
{
    if ($userId <= 0 || $ruleNumber <= 0) return [];

    $ruleStmt = $pdo->prepare("
        SELECT id
        FROM regras_customizadas
        WHERE id_user = ?
          AND numero_da_regra = ?
        ORDER BY id ASC
    ");
    $ruleStmt->execute([$userId, $ruleNumber]);

    return array_values(array_unique(array_filter(array_map('intval', $ruleStmt->fetchAll(PDO::FETCH_COLUMN)), static fn($id) => $id > 0)));
}

function fetchUserCustomRuleId(PDO $pdo, int $userId, int $ruleNumber): int
{
    $ruleIds = fetchUserCustomRuleIds($pdo, $userId, $ruleNumber);
    return (int)($ruleIds[0] ?? 0);
}

function applyAutoTagFamilyCustomRulesForTags(PDO $pdo, int $userId, array $candidateTagIds): void
{
    if ($userId <= 0) return;

    $candidateTagIds = array_values(array_unique(array_filter(array_map('intval', $candidateTagIds), static fn($id) => $id > 0)));
    if (!$candidateTagIds) return;

    foreach (fetchUserCustomRuleIds($pdo, $userId, CUSTOM_RULE_AUTO_TAG_FAMILY_ON_TAG_CREATE) as $customRuleId) {
        foreach ($candidateTagIds as $candidateTagId) {
            applyAutoTagFamilyCustomRule($pdo, $userId, $candidateTagId, $customRuleId);
        }
    }
}

function candidateMatchesAutoTagFamilyParameters(PDO $pdo, int $userId, int $candidateTagId, array $parameterRuleTags): bool
{
    if ($userId <= 0 || $candidateTagId <= 0 || !$parameterRuleTags) return false;

    $existsStmt = $pdo->prepare("
        SELECT 1
        FROM relacoes_taguineas
        WHERE id_user IN (?, 5)
          AND id_tag_child = ?
          AND id_tag_mother = ?
          AND tipo_de_relacao = ?
        LIMIT 1
    ");

    foreach ($parameterRuleTags as $ruleTag) {
        $parameterTagId = (int)($ruleTag['id_tag'] ?? 0);
        $relationTypeId = (int)($ruleTag['id_tipo_de_relacao'] ?? 0);
        $kinship = (int)($ruleTag['parentesco'] ?? 0);
        if ($parameterTagId <= 0 || $relationTypeId <= 0 || $parameterTagId === $candidateTagId) return false;

        if ($kinship === 1) {
            $childTagId = $parameterTagId;
            $motherTagId = $candidateTagId;
        } else {
            $childTagId = $candidateTagId;
            $motherTagId = $parameterTagId;
        }

        $existsStmt->execute([$userId, $childTagId, $motherTagId, $relationTypeId]);
        if (!$existsStmt->fetchColumn()) return false;
    }

    return true;
}

function nextTagFamilyOrder(PDO $pdo, int $userId, int $motherTagId, int $relationTypeId): int
{
    if ($userId <= 0 || $motherTagId <= 0 || $relationTypeId <= 0) return 0;

    $typeStmt = $pdo->prepare("SELECT hierarquia FROM tipos_de_relacoes WHERE id = ? AND id_user IN (?, 5) LIMIT 1");
    $typeStmt->execute([$relationTypeId, $userId]);
    if ((int)($typeStmt->fetchColumn() ?: 0) !== 2) return 0;

    $orderStmt = $pdo->prepare("SELECT COALESCE(MAX(ordem), 0) + 1 FROM relacoes_taguineas WHERE id_user = ? AND id_tag_mother = ? AND tipo_de_relacao = ?");
    $orderStmt->execute([$userId, $motherTagId, $relationTypeId]);
    return (int)($orderStmt->fetchColumn() ?: 1);
}

function fetchTagFamilyConnectedComponent(PDO $pdo, int $userId, int $relationTypeId, array $seedTagIds): array
{
    if ($userId <= 0 || $relationTypeId <= 0) return [];

    $seedTagIds = array_values(array_unique(array_filter(array_map('intval', $seedTagIds), static fn($id) => $id > 0)));
    if (!$seedTagIds) return [];

    $graphStmt = $pdo->prepare("SELECT id_tag_child, id_tag_mother FROM relacoes_taguineas WHERE id_user IN (?, 5) AND tipo_de_relacao = ?");
    $graphStmt->execute([$userId, $relationTypeId]);

    $adjacency = [];
    foreach ($graphStmt->fetchAll(PDO::FETCH_ASSOC) as $edge) {
        $childTagId = (int)($edge['id_tag_child'] ?? 0);
        $motherTagId = (int)($edge['id_tag_mother'] ?? 0);
        if ($childTagId <= 0 || $motherTagId <= 0 || $childTagId === $motherTagId) continue;
        $adjacency[$childTagId][$motherTagId] = true;
        $adjacency[$motherTagId][$childTagId] = true;
    }

    $visited = [];
    $queue = $seedTagIds;
    foreach ($seedTagIds as $tagId) $visited[$tagId] = true;

    while (!empty($queue)) {
        $currentTagId = array_shift($queue);
        foreach (array_keys($adjacency[$currentTagId] ?? []) as $neighborTagId) {
            $neighborTagId = (int)$neighborTagId;
            if ($neighborTagId <= 0 || isset($visited[$neighborTagId])) continue;
            $visited[$neighborTagId] = true;
            $queue[] = $neighborTagId;
        }
    }

    return array_values(array_map('intval', array_keys($visited)));
}

function replicateTagFamilyRelations(PDO $pdo, int $userId, int $relationTypeId, array $tagIds): void
{
    if ($userId <= 0 || $relationTypeId <= 0) return;

    $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds), static fn($id) => $id > 0)));
    if (count($tagIds) < 2) return;

    $insertStmt = $pdo->prepare("
        INSERT IGNORE INTO relacoes_taguineas (id_user, id_tag_child, id_tag_mother, tipo_de_relacao, ordem)
        VALUES (?, ?, ?, ?, 0)
    ");

    foreach ($tagIds as $childTagId) {
        foreach ($tagIds as $motherTagId) {
            if ($childTagId === $motherTagId) continue;
            $insertStmt->execute([$userId, $childTagId, $motherTagId, $relationTypeId]);
        }
    }
}

function applyAutoTagFamilyCustomRule(PDO $pdo, int $userId, int $candidateTagId, int $customRuleId): void
{
    if ($userId <= 0 || $candidateTagId <= 0 || $customRuleId <= 0) return;

    $ruleTagStmt = $pdo->prepare("
        SELECT id_regra, id_tag, id_tipo_de_relacao, destino, parentesco
        FROM regras_tags
        WHERE id_regra = ?
        ORDER BY id ASC
    ");
    $ruleTagStmt->execute([$customRuleId]);

    $parameterRuleTags = [];
    $destinationRuleTags = [];
    foreach ($ruleTagStmt->fetchAll(PDO::FETCH_ASSOC) as $ruleTag) {
        if ((int)($ruleTag['id_regra'] ?? 0) !== $customRuleId) continue;

        $destination = (int)($ruleTag['destino'] ?? 0);
        if ($destination === 1) {
            $destinationRuleTags[] = $ruleTag;
        } else {
            $parameterRuleTags[] = $ruleTag;
        }
    }

    if (!$destinationRuleTags || !candidateMatchesAutoTagFamilyParameters($pdo, $userId, $candidateTagId, $parameterRuleTags)) return;

    $insertStmt = $pdo->prepare("
        INSERT IGNORE INTO relacoes_taguineas (id_user, id_tag_child, id_tag_mother, tipo_de_relacao, ordem)
        VALUES (?, ?, ?, ?, ?)
    ");
    $reverseStmt = $pdo->prepare("
        SELECT 1
        FROM relacoes_taguineas
        WHERE id_user IN (?, 5)
          AND id_tag_child = ?
          AND id_tag_mother = ?
          AND tipo_de_relacao = ?
        LIMIT 1
    ");

    foreach ($destinationRuleTags as $ruleTag) {
        $relatedTagId = (int)($ruleTag['id_tag'] ?? 0);
        $relationTypeId = (int)($ruleTag['id_tipo_de_relacao'] ?? 0);
        $kinship = (int)($ruleTag['parentesco'] ?? 0);
        if ($relatedTagId <= 0 || $relationTypeId <= 0 || $relatedTagId === $candidateTagId) continue;

        if ($kinship === 1) {
            $childTagId = $relatedTagId;
            $motherTagId = $candidateTagId;
        } else {
            $childTagId = $candidateTagId;
            $motherTagId = $relatedTagId;
        }

        $reverseStmt->execute([$userId, $motherTagId, $childTagId, $relationTypeId]);
        if ($reverseStmt->fetchColumn()) continue;

        $insertStmt->execute([$userId, $childTagId, $motherTagId, $relationTypeId, nextTagFamilyOrder($pdo, $userId, $motherTagId, $relationTypeId)]);
    }
}

function ensureFlashcardTagsNumericMetadataSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $numeroColumn = $pdo->query("SHOW COLUMNS FROM flashcard_tags LIKE 'numero'")->fetch(PDO::FETCH_ASSOC) ?: [];
        $numeroType = strtolower((string)($numeroColumn['Type'] ?? ''));
        if ($numeroType !== '' && !preg_match('/(?:char|text|blob|json)/', $numeroType)) {
            $pdo->exec('ALTER TABLE flashcard_tags MODIFY numero VARCHAR(191) NULL');
        }
        $siglaColumn = $pdo->query("SHOW COLUMNS FROM flashcard_tags LIKE 'sigla_simbolo'")->fetch(PDO::FETCH_ASSOC);
        if (!$siglaColumn) $pdo->exec('ALTER TABLE flashcard_tags ADD COLUMN sigla_simbolo VARCHAR(191) NULL AFTER numero');
    } catch (Throwable $e) {
        error_log('[flashcards][flashcard_tags_numeric_metadata_schema] ' . $e->getMessage());
    }
}

function ensureFlashcardTagsCreatorSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $creatorColumn = $pdo->query("SHOW COLUMNS FROM flashcard_tags LIKE 'created_by_user_id'")->fetch(PDO::FETCH_ASSOC);
        if (!$creatorColumn) {
            $pdo->exec('ALTER TABLE flashcard_tags ADD COLUMN created_by_user_id INT NULL AFTER user_id');
        }
        $pdo->exec('UPDATE flashcard_tags SET created_by_user_id = user_id WHERE created_by_user_id IS NULL');
    } catch (Throwable $e) {
        error_log('[flashcards][flashcard_tags_creator_schema] ' . $e->getMessage());
    }
}

function ensureTagFamilyOrderSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $orderColumn = $pdo->query("SHOW COLUMNS FROM relacoes_taguineas LIKE 'ordem'")->fetch(PDO::FETCH_ASSOC);
        if (!$orderColumn) {
            $pdo->exec('ALTER TABLE relacoes_taguineas ADD COLUMN ordem INT NOT NULL DEFAULT 0 AFTER tipo_de_relacao');
        }
    } catch (Throwable $e) {
        error_log('[flashcards][tag_family_order_schema] ' . $e->getMessage());
    }
}

function ensureFlashcardsDynamicTextTypeSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $dynamicTextTypeColumn = $pdo->query("SHOW COLUMNS FROM flashcards LIKE 'dynamic_text_type'")->fetch(PDO::FETCH_ASSOC);
        if (!$dynamicTextTypeColumn) {
            $pdo->exec('ALTER TABLE flashcards ADD COLUMN dynamic_text_type INT NOT NULL DEFAULT 0 AFTER info_type');
        }
        $idColumn = $pdo->query("SHOW COLUMNS FROM flashcards LIKE 'id'")->fetch(PDO::FETCH_ASSOC);
        $flashcardIdType = strtolower((string)($idColumn['Type'] ?? 'int unsigned'));
        if (!preg_match('/^[a-z0-9() ]+$/', $flashcardIdType)) {
            $flashcardIdType = 'int unsigned';
        }

        $dynamicParentColumn = $pdo->query("SHOW COLUMNS FROM flashcards LIKE 'dynamic_parent_flashcard_id'")->fetch(PDO::FETCH_ASSOC);
        if (!$dynamicParentColumn) {
            $pdo->exec("ALTER TABLE flashcards ADD COLUMN dynamic_parent_flashcard_id {$flashcardIdType} NULL DEFAULT NULL AFTER dynamic_text_type");
        } elseif (strtolower((string)($dynamicParentColumn['Type'] ?? '')) !== $flashcardIdType) {
            $pdo->exec("ALTER TABLE flashcards MODIFY COLUMN dynamic_parent_flashcard_id {$flashcardIdType} NULL DEFAULT NULL");
        }

        $dynamicParentIndex = $pdo->query("SHOW INDEX FROM flashcards WHERE Key_name = 'idx_flashcards_dynamic_parent_flashcard_id'")->fetch(PDO::FETCH_ASSOC);
        if (!$dynamicParentIndex) {
            $pdo->exec('CREATE INDEX idx_flashcards_dynamic_parent_flashcard_id ON flashcards (dynamic_parent_flashcard_id)');
        }

        $fkStmt = $pdo->prepare("
            SELECT kcu.CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE kcu
            WHERE kcu.TABLE_SCHEMA = DATABASE()
              AND kcu.TABLE_NAME = 'flashcards'
              AND (
                  kcu.CONSTRAINT_NAME = 'fk_flashcards_dynamic_card_mother'
                  OR (
                      kcu.COLUMN_NAME = 'dynamic_parent_flashcard_id'
                      AND kcu.REFERENCED_TABLE_NAME = 'flashcards'
                      AND kcu.REFERENCED_COLUMN_NAME = 'id'
                  )
              )
            LIMIT 1
        ");
        $fkStmt->execute();
        if (!$fkStmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec('ALTER TABLE flashcards ADD CONSTRAINT fk_flashcards_dynamic_card_mother FOREIGN KEY (dynamic_parent_flashcard_id) REFERENCES flashcards(id) ON DELETE CASCADE');
        }
    } catch (Throwable $e) {
        error_log('[flashcards][dynamic_text_type_schema] ' . $e->getMessage());
    }
}

function ensureFlashcardsQuestionAnswerSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $questionAnswerColumn = $pdo->query("SHOW COLUMNS FROM flashcards LIKE 'question_answer'")->fetch(PDO::FETCH_ASSOC);
        if (!$questionAnswerColumn) {
            $pdo->exec('ALTER TABLE flashcards ADD COLUMN question_answer TINYINT NULL DEFAULT NULL AFTER info_type');
        }
    } catch (Throwable $e) {
        error_log('[flashcards][question_answer_schema] ' . $e->getMessage());
    }
}

function ensureFlashcardsPublicToggleSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $creatorColumn = $pdo->query("SHOW COLUMNS FROM flashcards LIKE 'created_by_user_id'")->fetch(PDO::FETCH_ASSOC);
        if (!$creatorColumn) {
            $pdo->exec('ALTER TABLE flashcards ADD COLUMN created_by_user_id INT NULL AFTER directory_id');
        }

        $privateDirectoryColumn = $pdo->query("SHOW COLUMNS FROM flashcards LIKE 'private_directory_id'")->fetch(PDO::FETCH_ASSOC);
        if (!$privateDirectoryColumn) {
            $pdo->exec('ALTER TABLE flashcards ADD COLUMN private_directory_id INT NULL AFTER created_by_user_id');
        }
    } catch (Throwable $e) {
        error_log('[flashcards][public_toggle_schema] ' . $e->getMessage());
    }
}

function normalizeNullableTagMetadataText($value): ?string
{
    if ($value === null) return null;
    $text = preg_replace('/\s+/u', ' ', trim((string)$value));
    return $text === '' ? null : $text;
}

function looksLikeQuantifiedNumberTag(string $value): bool
{
    $value = trim($value);
    if ($value === '') return false;
    return preg_match('/^(?:[Rr]\$|[Uu]\$|US\$|\$|€|£)?\s*[+-]?\s*\d[\d\s.,]*(?:st|nd|rd|th)?(?:\s*(?:mg|kg|cm|mm|km|m|g|°\s*C|°\s*F|%))?$/iu', $value) === 1;
}

function normalizeExtractedNumberLiteral(string $value): string
{
    $value = preg_replace('/\s+/u', ' ', trim($value));
    $value = trim((string)$value, " \t\n\r\0\x0B.,;:!?()[]{}<>\"'“”‘’");
    return preg_replace('/\s+/u', '', (string)$value);
}

function extractQuantifiedNumberLiteralsFromText(string $text): array
{
    $text = trim($text);
    if ($text === '') return [];

    preg_match_all('/(?<![\pL\pN])(?:[Rr]\$|[Uu]\$|US\$|\$|€|£)?\s*[+-]?\s*\d[\d\s.,]*(?:st|nd|rd|th)?(?:\s*(?:mg|kg|cm|mm|km|m|g|°\s*C|°\s*F|%))?(?![\pL\pN])/iu', $text, $matches);
    $literals = [];
    foreach (($matches[0] ?? []) as $match) {
        $literal = normalizeExtractedNumberLiteral((string)$match);
        if ($literal === '' || !looksLikeQuantifiedNumberTag($literal)) continue;
        $key = function_exists('mb_strtolower') ? mb_strtolower($literal, 'UTF-8') : strtolower($literal);
        $literals[$key] = $literal;
    }
    return array_values($literals);
}

function buildNumberTagCandidatesFromText(string ...$texts): array
{
    $candidates = [];
    foreach ($texts as $text) {
        foreach (extractQuantifiedNumberLiteralsFromText($text) as $literal) {
            $key = function_exists('mb_strtolower') ? mb_strtolower($literal, 'UTF-8') : strtolower($literal);
            if (isset($candidates[$key])) continue;
            $words = tagNumberToWords($literal);
            $candidates[$key] = [
                'en' => $words['en'],
                'pt_br' => $words['pt_br'],
                'numero' => $literal,
                'sigla_simbolo' => null,
                'kind' => 'other',
            ];
        }
    }
    return array_values($candidates);
}


function generatedTagLooksVerbLike(array $tag): bool
{
    $fields = ['type', 'role', 'part_of_speech', 'pos', 'category', 'kind'];
    foreach ($fields as $field) {
        if (!array_key_exists($field, $tag)) continue;
        $value = $tag[$field];
        if (is_array($value)) $value = implode(' ', array_map('strval', $value));
        $value = function_exists('mb_strtolower') ? mb_strtolower((string)$value, 'UTF-8') : strtolower((string)$value);
        if (preg_match('/\b(verb|verbo|verb_phrase|phrasal_verb|action|ação|acao)\b/u', $value)) {
            return true;
        }
    }
    return !empty($tag['is_verb']) || !empty($tag['verb']);
}


function generatedTagTextMatchesSelected(array $tag, string $selectedEn, string $selectedPtBr): bool
{
    $tagEn = (string)($tag['en'] ?? $tag['english'] ?? $tag['name'] ?? '');
    $tagPtBr = (string)($tag['pt_br'] ?? $tag['ptBr'] ?? $tag['translation'] ?? $tag['name_pt_br'] ?? '');
    if ($tagEn === '' || $tagPtBr === '') return false;

    return normalizeLexicalChunkLookupValue($tagEn) === normalizeLexicalChunkLookupValue($selectedEn)
        && normalizeLexicalChunkLookupValue($tagPtBr) === normalizeLexicalChunkLookupValue($selectedPtBr);
}


function generatedTagListContainsSelectedTranslation(array $subjectRaw, array $objectsRaw, array $chunksRaw, string $selectedEn, string $selectedPtBr): bool
{
    if (!empty($subjectRaw) && generatedTagTextMatchesSelected($subjectRaw, $selectedEn, $selectedPtBr)) {
        return true;
    }

    foreach ($objectsRaw as $objectRaw) {
        if (is_array($objectRaw) && generatedTagTextMatchesSelected($objectRaw, $selectedEn, $selectedPtBr)) {
            return true;
        }
    }

    foreach ($chunksRaw as $chunkRaw) {
        if (is_array($chunkRaw) && generatedTagTextMatchesSelected($chunkRaw, $selectedEn, $selectedPtBr)) {
            return true;
        }
    }

    return false;
}

function normalizeSelectedGeneratedTagRole($role): string
{
    $role = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$role), 'UTF-8') : strtolower(trim((string)$role));
    $role = str_replace(['-', ' '], '_', $role);
    if (in_array($role, ['subject', 'sujeito'], true)) return 'subject';
    if (in_array($role, ['object', 'objects', 'objeto', 'objetos', 'noun', 'substantivo'], true)) return 'object';
    if (in_array($role, ['chunk', 'chunks', 'lexical_chunk', 'lexical_chunks', 'lexical', 'expressao', 'expressão', 'verb', 'verbo', 'verb_phrase', 'phrasal_verb'], true)) return 'chunk';
    return '';
}

function determineSelectedGeneratedTagRole(array $exampleRaw, array $subjectRaw, array $objectsRaw, array $chunksRaw, string $selectedEn, string $selectedPtBr): string
{
    $declaredRole = normalizeSelectedGeneratedTagRole($exampleRaw['selected_tag_role'] ?? $exampleRaw['tag_role'] ?? $exampleRaw['selected_role'] ?? '');
    if ($declaredRole !== '') return $declaredRole;

    foreach ($chunksRaw as $chunkRaw) {
        if (is_array($chunkRaw) && generatedTagTextMatchesSelected($chunkRaw, $selectedEn, $selectedPtBr)) return 'chunk';
    }
    foreach ($objectsRaw as $objectRaw) {
        if (is_array($objectRaw) && generatedTagTextMatchesSelected($objectRaw, $selectedEn, $selectedPtBr)) return generatedTagLooksVerbLike($objectRaw) ? 'chunk' : 'object';
    }
    if (!empty($subjectRaw) && generatedTagTextMatchesSelected($subjectRaw, $selectedEn, $selectedPtBr)) {
        return generatedTagLooksVerbLike($subjectRaw) ? 'chunk' : 'subject';
    }

    return '';
}

function normalizeGeneratedTagMetadata(string $rawName, string $rawNamePtBr, $rawNumero = null, $rawSiglaSimbolo = null): array
{
    $numero = normalizeNullableTagMetadataText($rawNumero);
    $numberCameFromTagText = false;
    if ($numero === null) {
        if (looksLikeQuantifiedNumberTag($rawName)) {
            $numero = trim($rawName);
            $numberCameFromTagText = true;
        } elseif (looksLikeQuantifiedNumberTag($rawNamePtBr)) {
            $numero = trim($rawNamePtBr);
            $numberCameFromTagText = true;
        } else {
            $embeddedNumbers = buildNumberTagCandidatesFromText($rawName, $rawNamePtBr);
            if (count($embeddedNumbers) === 1) {
                $numero = $embeddedNumbers[0]['numero'];
                $numberCameFromTagText = true;
            }
        }
    }
    $siglaSimbolo = normalizeNullableTagMetadataText($rawSiglaSimbolo);
    if ($numero !== null) {
        $words = tagNumberToWords($numero);
        $rawName = $words['en'];
        $rawNamePtBr = $words['pt_br'];
    }
    return ['name'=>$rawName, 'name_pt_br'=>$rawNamePtBr, 'numero'=>$numero, 'sigla_simbolo'=>$siglaSimbolo];
}


function normalizeDateLexicalChunkTexts(string $en, string $ptBr = ''): array
{
    $originalEn = preg_replace('/\s+/u', ' ', trim($en));
    $originalPtBr = preg_replace('/\s+/u', ' ', trim($ptBr));
    if ($originalEn === '') return [$originalEn, $originalPtBr];

    $monthMap = [
        'january' => 'janeiro', 'jan' => 'janeiro',
        'february' => 'fevereiro', 'feb' => 'fevereiro',
        'march' => 'março', 'mar' => 'março',
        'april' => 'abril', 'apr' => 'abril',
        'may' => 'maio',
        'june' => 'junho', 'jun' => 'junho',
        'july' => 'julho', 'jul' => 'julho',
        'august' => 'agosto', 'aug' => 'agosto',
        'september' => 'setembro', 'sep' => 'setembro', 'sept' => 'setembro',
        'october' => 'outubro', 'oct' => 'outubro',
        'november' => 'novembro', 'nov' => 'novembro',
        'december' => 'dezembro', 'dec' => 'dezembro',
    ];
    $months = 'January|Jan|February|Feb|March|Mar|April|Apr|May|June|Jun|July|Jul|August|Aug|September|Sept|Sep|October|Oct|November|Nov|December|Dec';

    if (preg_match('/^(?:(on|in)\s+)?(' . $months . ')\s+(\d{1,2})(st|nd|rd|th)?(?:,?\s+\d{4})?$/iu', $originalEn, $m)) {
        $preposition = strtolower((string)($m[1] ?? ''));
        $month = (string)$m[2];
        $suffix = strtolower((string)($m[4] ?? ''));
        if ($suffix === '') $suffix = 'th';
        $normalizedEn = ($preposition !== '' ? $preposition . ' ' : '') . $month . ' ...' . $suffix;
        $monthKey = function_exists('mb_strtolower') ? mb_strtolower($month, 'UTF-8') : strtolower($month);
        $ptMonth = $monthMap[$monthKey] ?? $monthKey;
        $normalizedPtBr = ($preposition !== '' ? 'em ' : '') . $ptMonth . ' ...';
        return [$normalizedEn, $normalizedPtBr];
    }

    if (preg_match('/^(?:(on|in)\s+)?(' . $months . ')\s+\d{4}$/iu', $originalEn, $m)) {
        $preposition = strtolower((string)($m[1] ?? ''));
        $month = (string)$m[2];
        $normalizedEn = ($preposition !== '' ? $preposition . ' ' : '') . $month;
        $monthKey = function_exists('mb_strtolower') ? mb_strtolower($month, 'UTF-8') : strtolower($month);
        $ptMonth = $monthMap[$monthKey] ?? $monthKey;
        $normalizedPtBr = ($preposition !== '' ? 'em ' : '') . $ptMonth;
        return [$normalizedEn, $normalizedPtBr];
    }

    return [$originalEn, $originalPtBr];
}

function containsUnrealisticFutureYear(string $text, array $allowedYearLiterals = []): bool
{
    $currentYear = (int)date('Y');
    $allowed = [];
    foreach ($allowedYearLiterals as $year) {
        $year = trim((string)$year);
        if (preg_match('/^\d{4}$/', $year)) $allowed[$year] = true;
    }
    preg_match_all('/(?<!\d)(\d{4})(?!\d)/', $text, $matches);
    foreach (($matches[1] ?? []) as $yearText) {
        $year = (int)$yearText;
        if ($year > $currentYear && !isset($allowed[$yearText])) return true;
    }
    return false;
}

function tagNumberToWords(string $raw): array
{
    $compact = preg_replace('/\s+/u', '', trim($raw));
    $lower = function_exists('mb_strtolower') ? mb_strtolower($compact, 'UTF-8') : strtolower($compact);
    if (preg_match('/^([+-]?)(?:R\$)(\d[\d.]*)(?:,(\d+))?$/iu', $compact, $m)) return tagCurrencyToWords($m[1] ?? '', $m[2] ?? '0', $m[3] ?? '', 'real');
    if (preg_match('/^([+-]?)(?:U\$|US\$|\$)(\d[\d,]*)(?:[,.](\d+))?$/iu', $compact, $m)) return tagCurrencyToWords($m[1] ?? '', $m[2] ?? '0', $m[3] ?? '', 'dollar');
    if (preg_match('/^(\d+)(st|nd|rd|th)$/iu', $compact, $m)) return tagOrdinalToWords($m[1] ?? '0');
    if (preg_match('/^([+-]?)(\d[\d.,]*)(kg|g|mg|km|cm|mm|m|%|°c|°f)$/iu', $lower, $m)) return tagMeasurementToWords($m[1] ?? '', $m[2] ?? '0', $m[3] ?? '');
    if (preg_match('/^[+-]?\d[\d.,]*$/u', $compact)) return tagGenericNumberToWords($compact);
    return ['en' => 'number ' . $raw, 'pt_br' => 'número ' . $raw];
}

function tagIntegerPartsFromLocalizedNumber(string $number): array
{
    $number = preg_replace('/\s+/u', '', trim($number));
    $negative = str_starts_with($number, '-');
    $number = ltrim($number, '+-');
    $decimal = '';
    if (preg_match('/[,\.]\d{1,}$/', $number, $m) && !preg_match('/^\d{1,3}(?:[\.,]\d{3})+$/', $number)) {
        $decimal = substr($m[0], 1);
        $number = substr($number, 0, -strlen($m[0]));
    }
    $integer = preg_replace('/\D/u', '', $number);
    $integer = ltrim((string)$integer, '0');
    return [$negative, $integer === '' ? '0' : $integer, $decimal];
}

function tagOrdinalToWords(string $digits): array
{
    $n = (int)ltrim($digits, '0');
    $enMap = [1=>'first',2=>'second',3=>'third',4=>'fourth',5=>'fifth',6=>'sixth',7=>'seventh',8=>'eighth',9=>'ninth',10=>'tenth',11=>'eleventh',12=>'twelfth',13=>'thirteenth',14=>'fourteenth',15=>'fifteenth',16=>'sixteenth',17=>'seventeenth',18=>'eighteenth',19=>'nineteenth',20=>'twentieth',21=>'twenty-first',22=>'twenty-second',23=>'twenty-third',24=>'twenty-fourth',25=>'twenty-fifth',26=>'twenty-sixth',27=>'twenty-seventh',28=>'twenty-eighth',29=>'twenty-ninth',30=>'thirtieth',31=>'thirty-first'];
    $ptMap = [1=>'primeiro',2=>'segundo',3=>'terceiro',4=>'quarto',5=>'quinto',6=>'sexto',7=>'sétimo',8=>'oitavo',9=>'nono',10=>'décimo',11=>'décimo primeiro',12=>'décimo segundo',13=>'décimo terceiro',14=>'décimo quarto',15=>'décimo quinto',16=>'décimo sexto',17=>'décimo sétimo',18=>'décimo oitavo',19=>'décimo nono',20=>'vigésimo',21=>'vigésimo primeiro',22=>'vigésimo segundo',23=>'vigésimo terceiro',24=>'vigésimo quarto',25=>'vigésimo quinto',26=>'vigésimo sexto',27=>'vigésimo sétimo',28=>'vigésimo oitavo',29=>'vigésimo nono',30=>'trigésimo',31=>'trigésimo primeiro'];
    if (isset($enMap[$n], $ptMap[$n])) return ['en' => $enMap[$n], 'pt_br' => $ptMap[$n]];

    $cardinal = tagGenericNumberToWords($digits);
    return ['en' => 'ordinal ' . $cardinal['en'], 'pt_br' => $cardinal['pt_br'] . ' ordinal'];
}

function tagGenericNumberToWords(string $number): array
{
    [$negative, $integer, $decimal] = tagIntegerPartsFromLocalizedNumber($number);
    $en = tagIntegerToEnglishWords($integer);
    $pt = tagIntegerToPortugueseWords($integer);
    if ($negative) { $en = 'negative ' . $en; $pt = 'menos ' . $pt; }
    if ($decimal !== '') {
        $en .= ' point ' . tagDigitsToWords($decimal, 'en');
        $pt .= ' vírgula ' . tagDigitsToWords($decimal, 'pt');
    }
    return ['en'=>$en, 'pt_br'=>$pt];
}

function tagCurrencyToWords(string $sign, string $integerRaw, string $decimal, string $currency): array
{
    $integer = ltrim(preg_replace('/\D/u', '', $integerRaw), '0') ?: '0';
    $negativeEn = $sign === '-' ? 'negative ' : '';
    $negativePt = $sign === '-' ? 'menos ' : '';
    $enCurrency = $currency === 'real' ? ((int)$integer === 1 ? 'real' : 'reais') : ((int)$integer === 1 ? 'dollar' : 'dollars');
    $ptCurrency = $currency === 'real' ? ((int)$integer === 1 ? 'real' : 'reais') : ((int)$integer === 1 ? 'dólar' : 'dólares');
    $en = $negativeEn . tagIntegerToEnglishWords($integer) . ' ' . $enCurrency;
    $pt = $negativePt . tagIntegerToPortugueseWords($integer) . ' ' . $ptCurrency;
    if ($decimal !== '') {
        $cents = substr(str_pad(preg_replace('/\D/u', '', $decimal), 2, '0'), 0, 2);
        $centInt = (int)$cents;
        if ($centInt > 0) {
            $en .= ' and ' . tagIntegerToEnglishWords((string)$centInt) . ($centInt === 1 ? ' cent' : ' cents');
            $pt .= ' e ' . tagIntegerToPortugueseWords((string)$centInt) . ($centInt === 1 ? ' centavo' : ' centavos');
        }
    }
    return ['en'=>$en, 'pt_br'=>$pt];
}

function tagMeasurementToWords(string $sign, string $numberRaw, string $unit): array
{
    $words = tagGenericNumberToWords(($sign === '-' ? '-' : '') . $numberRaw);
    $unit = str_replace(' ', '', function_exists('mb_strtolower') ? mb_strtolower($unit, 'UTF-8') : strtolower($unit));
    $map = [
        'kg'=>['kilograms','quilos'], 'g'=>['grams','gramas'], 'mg'=>['milligrams','miligramas'],
        'km'=>['kilometers','quilômetros'], 'cm'=>['centimeters','centímetros'], 'mm'=>['millimeters','milímetros'],
        'm'=>['meters','metros'], '%'=>['percent','por cento'], '°c'=>['degrees Celsius','graus Celsius'], '°f'=>['degrees Fahrenheit','graus Fahrenheit'],
    ];
    $labels = $map[$unit] ?? [$unit, $unit];
    return ['en'=>$words['en'] . ' ' . $labels[0], 'pt_br'=>$words['pt_br'] . ' ' . $labels[1]];
}

function tagDigitsToWords(string $digits, string $language): string
{
    $en = ['zero','one','two','three','four','five','six','seven','eight','nine'];
    $pt = ['zero','um','dois','três','quatro','cinco','seis','sete','oito','nove'];
    $map = $language === 'pt' ? $pt : $en;
    return implode(' ', array_map(static fn($d) => $map[(int)$d] ?? $d, str_split($digits)));
}

function tagIntegerToEnglishWords(string $digits): string
{
    $digits = ltrim($digits, '0') ?: '0';
    if ($digits === '0') return 'zero';
    $ones = ['', 'one','two','three','four','five','six','seven','eight','nine','ten','eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen','eighteen','nineteen'];
    $tens = ['', '', 'twenty','thirty','forty','fifty','sixty','seventy','eighty','ninety'];
    $under1000 = function(int $n) use ($ones, $tens): string {
        $parts = [];
        if ($n >= 100) { $parts[] = $ones[intdiv($n,100)] . ' hundred'; $n %= 100; }
        if ($n >= 20) { $parts[] = $tens[intdiv($n,10)] . (($n % 10) ? '-' . $ones[$n % 10] : ''); }
        elseif ($n > 0) { $parts[] = $ones[$n]; }
        return implode(' ', $parts);
    };
    $scales = ['', 'thousand', 'million', 'billion', 'trillion', 'quadrillion', 'quintillion'];
    $groups = [];
    while ($digits !== '') { array_unshift($groups, (int)substr($digits, -3)); $digits = substr($digits, 0, -3); }
    $parts = [];
    $count = count($groups);
    foreach ($groups as $i => $group) {
        if ($group === 0) continue;
        $scaleIndex = $count - $i - 1;
        $scale = $scales[$scaleIndex] ?? ('10^' . ($scaleIndex * 3));
        $parts[] = trim($under1000($group) . ' ' . $scale);
    }
    $text = implode(' ', $parts);
    $lastGroup = end($groups);
    if (count($parts) > 1 && is_int($lastGroup) && $lastGroup > 0 && $lastGroup < 100) {
        $lastPart = array_pop($parts);
        $text = implode(' ', $parts) . ' and ' . $lastPart;
    }
    return $text;
}

function tagIntegerToPortugueseWords(string $digits): string
{
    $digits = ltrim($digits, '0') ?: '0';
    if ($digits === '0') return 'zero';

    $under1000 = static function(int $n): string {
        $ones = ['', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove'];
        $teens = [10 => 'dez', 11 => 'onze', 12 => 'doze', 13 => 'treze', 14 => 'catorze', 15 => 'quinze', 16 => 'dezesseis', 17 => 'dezessete', 18 => 'dezoito', 19 => 'dezenove'];
        $tens = ['', '', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];
        $hundreds = ['', 'cento', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos'];
        if ($n === 100) return 'cem';
        $parts = [];
        if ($n >= 100) {
            $parts[] = $hundreds[intdiv($n, 100)];
            $n %= 100;
        }
        if ($n >= 20) {
            $ten = $tens[intdiv($n, 10)];
            $unit = $n % 10;
            $parts[] = $unit ? $ten . ' e ' . $ones[$unit] : $ten;
        } elseif ($n >= 10) {
            $parts[] = $teens[$n];
        } elseif ($n > 0) {
            $parts[] = $ones[$n];
        }
        return implode(' e ', $parts);
    };

    $scaleSingular = ['', 'mil', 'milhão', 'bilhão', 'trilhão', 'quadrilhão', 'quintilhão'];
    $scalePlural = ['', 'mil', 'milhões', 'bilhões', 'trilhões', 'quadrilhões', 'quintilhões'];
    $groups = [];
    while ($digits !== '') { array_unshift($groups, (int)substr($digits, -3)); $digits = substr($digits, 0, -3); }
    $parts = [];
    $count = count($groups);
    foreach ($groups as $i => $group) {
        if ($group === 0) continue;
        $scaleIndex = $count - $i - 1;
        if ($scaleIndex === 1 && $group === 1) {
            $parts[] = 'mil';
            continue;
        }
        $scale = $group === 1 ? ($scaleSingular[$scaleIndex] ?? ('10^' . ($scaleIndex * 3))) : ($scalePlural[$scaleIndex] ?? ('10^' . ($scaleIndex * 3)));
        $parts[] = trim($under1000($group) . ' ' . $scale);
    }
    if (count($parts) > 1) {
        $lastGroup = end($groups);
        $joiner = (is_int($lastGroup) && ($lastGroup < 100 || $lastGroup % 100 === 0)) ? ' e ' : ' ';
        $lastPart = array_pop($parts);
        return implode(' ', $parts) . $joiner . $lastPart;
    }
    return implode(' ', $parts);
}


function tagCombinationAlreadyExists(PDO $pdo, int $userId, string $name, ?string $namePtBr, ?string $numero, ?string $siglaSimbolo = null, ?int $excludeTagId = null): bool
{
    $sql = "
        SELECT id, name_encrypted, name_pt_br_encrypted, numero, sigla_simbolo
        FROM flashcard_tags
        WHERE (user_id = ? OR created_by_user_id = ?)
    ";
    $params = [$userId, $userId];
    if ($excludeTagId !== null) {
        $sql .= " AND id <> ?";
        $params[] = $excludeTagId;
    }
    $sql .= " ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rowName = '';
        if (!empty($row['name_encrypted'])) {
            $decrypted = Security::decryptData((string)$row['name_encrypted']);
            $rowName = $decrypted !== false ? (string)$decrypted : '';
        } else {
            $rowName = '';
        }

        $rowNamePtBr = null;
        if (!empty($row['name_pt_br_encrypted'])) {
            $decryptedPtBr = Security::decryptData((string)$row['name_pt_br_encrypted']);
            $rowNamePtBr = $decryptedPtBr !== false ? (string)$decryptedPtBr : null;
        } else {
            $rowNamePtBr = null;
        }

        $rowName = trim($rowName);
        $rowNamePtBr = $rowNamePtBr === null ? null : trim($rowNamePtBr);
        if ($rowNamePtBr === '') $rowNamePtBr = null;
        $rowNumero = normalizeNullableTagMetadataText($row['numero'] ?? null);
        $rowSiglaSimbolo = normalizeNullableTagMetadataText($row['sigla_simbolo'] ?? null);

        if ($rowName === $name && $rowNamePtBr === $namePtBr && $rowNumero === $numero && $rowSiglaSimbolo === $siglaSimbolo) {
            return true;
        }
    }
    return false;
}

/**
 * Função getAllowedCardTagLinkTables: Retorna a lista branca das tabelas de vínculo entre cards e tags permitidas para consultas e escrita.
 */



function sentenceLooksInterrogative(string $value): bool
{
    $value = trim($value);
    if ($value === '') return false;
    if (preg_match('/[?？]["\'”’\)\]}>]*$/u', $value)) return true;

    $value = preg_replace('/(["\'”’\)\]}>]+)$/u', '', $value);
    $value = preg_replace('/[\s\.。!！?？]+$/u', '', (string)$value);
    $value = trim((string)$value);
    if ($value === '') return false;

    $lower = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);

    if (preg_match('/^(who|what|when|where|why|how|which|whose|whom)\b/u', $lower)) return true;
    if (preg_match('/^(quem|o que|quando|onde|aonde|por que|por quê|porque|como|qual|quais|quanto|quantos|quanta|quantas)\b/u', $lower)) return true;

    if (preg_match('/^(am|are|is|was|were|do|does|did|have|has|had|can|could|would|shall|should|must)\b/u', $lower)) return true;
    if (preg_match('/^(é|são|foi|foram|era|eram|está|estão|estava|estavam|pode|podem|poderia|poderiam|deve|devem)\b/u', $lower)) return true;

    return false;
}

function ensureSentenceEndsWithTerminalPunctuation(string $value): string
{
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);
    $value = preg_replace('/\s+/u', ' ', trim((string)$value));
    if ($value === '') return '';

    // Example-card sentences must finish with sentence punctuation, while lexical-chunk
    // tag cleaning remains stricter and removes punctuation separately.
    $closing = '';
    if (preg_match('/(["\'”’\)\]}>]+)$/u', $value, $matches)) {
        $closing = $matches[1];
        $value = substr($value, 0, -strlen($closing));
        $value = rtrim($value);
    }

    $punctuation = sentenceLooksInterrogative($value) ? '?' : '.';
    $value = preg_replace('/[\s\.。!！?？]+$/u', '', (string)$value);
    return trim((string)$value) . $punctuation . $closing;
}

function ensureSentenceEndsWithPeriod(string $value): string
{
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);
    $value = preg_replace('/\s+/u', ' ', trim((string)$value));
    if ($value === '') return '';

    // Example-card sentences must finish with a period, while lexical-chunk tag
    // cleaning remains stricter and removes punctuation separately.
    $closing = '';
    if (preg_match('/(["\'”’\)\]}>]+)$/u', $value, $matches)) {
        $closing = $matches[1];
        $value = substr($value, 0, -strlen($closing));
        $value = rtrim($value);
    }
    $value = preg_replace('/[\s\.。!！?？]+$/u', '', (string)$value);
    return trim((string)$value) . '.' . $closing;
}

function contractEnglishSentenceText(string $value): string
{
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);
    $value = preg_replace('/\s+/u', ' ', trim((string)$value));
    if ($value === '') return '';

    $subjectContractions = [
        '/\b(I)\s+am\b/iu' => '$1\'m',
        '/\b(I|you|we|they)\s+are\b/iu' => '$1\'re',
        '/\b(he|she|it|that|there|here|what|who|where|when|why|how)\s+is\b/iu' => '$1\'s',
        '/\b(I|you|we|they|he|she|it|that|there|here|what|who|where|when|why|how)\s+will\b/iu' => '$1\'ll',
        '/\b(I|you|we|they|he|she|it)\s+would\b/iu' => '$1\'d',
        '/\b(I|you|we|they|he|she|it)\s+had\b/iu' => '$1\'d',
        '/\b(I|you|we|they)\s+have\b/iu' => '$1\'ve',
        '/\b(he|she|it)\s+has\b/iu' => '$1\'s',
    ];
    $value = preg_replace(array_keys($subjectContractions), array_values($subjectContractions), $value);

    $notContractions = [
        '/\bcan\s+not\b/iu' => "can't",
        '/\bwill\s+not\b/iu' => "won't",
        '/\bshall\s+not\b/iu' => "shan't",
        '/\b(are|is|was|were|do|does|did|have|has|had|would|should|could|must|need|dare)\s+not\b/iu' => '$1n\'t',
    ];
    $value = preg_replace(array_keys($notContractions), array_values($notContractions), (string)$value);

    return preg_replace('/\s+/u', ' ', trim((string)$value));
}

function expandEnglishContractionsForLexicalChunkTag(string $value): string
{
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);
    $value = preg_replace('/[’`´]/u', "'", (string)$value);
    $value = preg_replace('/\s+/u', ' ', trim((string)$value));
    if ($value === '') return '';

    $value = preg_replace('/\b(can)\'t\b/iu', '$1 not', (string)$value);
    $value = preg_replace('/\b(won)\'t\b/iu', 'will not', (string)$value);
    $value = preg_replace('/\b(shan)\'t\b/iu', 'shall not', (string)$value);
    $value = preg_replace('/\b([A-Za-z]+)n\'t\b/u', '$1 not', (string)$value);

    $apostropheExpansions = [
        "'ve" => ' have',
        "'re" => ' are',
        "'m" => ' am',
        "'ll" => ' will',
    ];
    $value = str_ireplace(array_keys($apostropheExpansions), array_values($apostropheExpansions), (string)$value);

    // The prompt only requests decontracting grammar tags. Keep possessive nouns
    // intact as much as possible; expand 's/'d where they follow common pronouns
    // and sentence placeholders that Gemini typically contracts.
    $value = preg_replace('/\b(I|you|we|they|he|she|it|that|there|here|what|who|where|when|why|how)\'s\b/iu', '$1 is', (string)$value);
    $value = preg_replace('/\b(I|you|we|they|he|she|it)\'d\b/iu', '$1 would', (string)$value);

    return preg_replace('/\s+/u', ' ', trim((string)$value));
}

function cleanLexicalChunkTagText(string $value): string
{
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);
    $value = preg_replace('/[“”„«»]/u', '"', (string)$value);
    $value = preg_replace("/[‘’`´]/u", "'", (string)$value);
    $value = preg_replace('/\s+/u', ' ', trim((string)$value));
    if ($value === '') return '';

    $ellipsisToken = '__GLUON_ELLIPSIS__';
    $value = str_replace('…', '...', $value);
    $value = preg_replace('/\.{3,}/u', $ellipsisToken, (string)$value);

    // Lexical-chunk tags should not carry sentence punctuation copied from Gemini output.
    // Preserve apostrophes for contractions and preserve ellipsis placeholders such as
    // "Whether ... or ...", but remove commas and sentence-ending punctuation.
    $value = preg_replace('/[,;:!?]+/u', '', (string)$value);
    $value = str_replace('.', '', (string)$value);
    $value = str_replace($ellipsisToken, '...', (string)$value);
    $value = preg_replace('/\s+([.]{3})/u', ' $1', (string)$value);
    $value = preg_replace('/([.]{3})\s*/u', '$1 ', (string)$value);
    $value = preg_replace('/\s+/u', ' ', trim((string)$value));
    $value = trim((string)$value, " \t\n\r\0\x0B\"()[]{}<>/\\|-–—");
    return preg_replace('/\s+/u', ' ', trim((string)$value));
}

function normalizeLexicalChunkLookupValue(string $value): string
{
    $value = cleanLexicalChunkTagText($value);
    return function_exists('mb_strtolower') ? mb_strtolower($value ?? '', 'UTF-8') : strtolower($value ?? '');
}

function findOrCreateLexicalChunkTag(PDO $pdo, int $userId, string $name, string $namePtBr, ?string $numero = null, ?string $siglaSimbolo = null): array
{
    $name = cleanLexicalChunkTagText($name);
    $namePtBr = cleanLexicalChunkTagText($namePtBr);
    $numero = normalizeNullableTagMetadataText($numero);
    $siglaSimbolo = normalizeNullableTagMetadataText($siglaSimbolo);
    if ($name === '' || $namePtBr === '') {
        throw new InvalidArgumentException('Lexical chunk inválido.');
    }

    $stmt = $pdo->prepare("\n        SELECT id, user_id, name_encrypted, name_pt_br_encrypted, numero, sigla_simbolo, is_lexical_chunk\n        FROM flashcard_tags\n        WHERE user_id IN (?, 5)\n        ORDER BY
            CASE WHEN is_lexical_chunk = 1 THEN 0 ELSE 1 END,
            CASE WHEN user_id = ? THEN 0 ELSE 1 END,
            id ASC\n    ");
    $stmt->execute([$userId, $userId]);
    $targetName = normalizeLexicalChunkLookupValue($name);
    $targetPtBr = normalizeLexicalChunkLookupValue($namePtBr);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rowName = !empty($row['name_encrypted']) ? Security::decryptData((string)$row['name_encrypted']) : '';
        $rowPtBr = !empty($row['name_pt_br_encrypted']) ? Security::decryptData((string)$row['name_pt_br_encrypted']) : '';
        if (normalizeLexicalChunkLookupValue((string)$rowName) === $targetName && normalizeLexicalChunkLookupValue((string)$rowPtBr) === $targetPtBr && normalizeNullableTagMetadataText($row['numero'] ?? null) === $numero && normalizeNullableTagMetadataText($row['sigla_simbolo'] ?? null) === $siglaSimbolo) {
            return [
                'tag_id' => (int)$row['id'],
                'en' => trim((string)$rowName),
                'pt_br' => trim((string)$rowPtBr),
                'numero' => normalizeNullableTagMetadataText($row['numero'] ?? null),
                'sigla_simbolo' => normalizeNullableTagMetadataText($row['sigla_simbolo'] ?? null),
                'created' => false,
            ];
        }
    }

    $color = resolveTagColorByCategory(['is_lexical_chunk' => 1]);
    $nameEnc = Security::encryptData($name);
    $namePtBrEnc = Security::encryptData($namePtBr);
    $insert = $pdo->prepare("\n        INSERT INTO flashcard_tags\n            (user_id, name_encrypted, name_pt_br_encrypted, numero, sigla_simbolo, color, is_book, is_verb_tense, is_sentence_type, is_lexical_chunk, is_relation_type, is_word, is_month, is_day, is_year)\n        VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, 1, 0, 0, 0, 0, 0)\n    ");

    $tagId = 0;
    try {
        $pdo->beginTransaction();
        $insert->execute([$userId, $nameEnc, $namePtBrEnc, $numero, $siglaSimbolo, $color]);
        $tagId = (int)$pdo->lastInsertId();
        executeTagCreationCustomRules($pdo, $userId, $tagId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[flashcards][create_lexical_chunk_tag] ' . $e->getMessage());
        throw $e;
    }

    return [
        'tag_id' => $tagId,
        'en' => $name,
        'pt_br' => $namePtBr,
        'numero' => $numero,
        'sigla_simbolo' => $siglaSimbolo,
        'created' => true,
    ];
}




function findOrCreateLexicalChunkTagForOwner(PDO $pdo, int $ownerUserId, string $name, string $namePtBr, ?string $numero = null, ?string $siglaSimbolo = null): array
{
    $ownerUserId = $ownerUserId === 5 ? 5 : $ownerUserId;
    $name = cleanLexicalChunkTagText(expandEnglishContractionsForLexicalChunkTag($name));
    $namePtBr = cleanLexicalChunkTagText($namePtBr);
    $numero = normalizeNullableTagMetadataText($numero);
    $siglaSimbolo = normalizeNullableTagMetadataText($siglaSimbolo);
    if ($ownerUserId <= 0 || $name === '' || $namePtBr === '') {
        throw new InvalidArgumentException('Lexical chunk inválido.');
    }

    $stmt = $pdo->prepare("
        SELECT id, user_id, name_encrypted, name_pt_br_encrypted, numero, sigla_simbolo, is_lexical_chunk
        FROM flashcard_tags
        WHERE user_id = ?
        ORDER BY CASE WHEN is_lexical_chunk = 1 THEN 0 ELSE 1 END, id ASC
    ");
    $stmt->execute([$ownerUserId]);
    $targetName = normalizeLexicalChunkLookupValue($name);
    $targetPtBr = normalizeLexicalChunkLookupValue($namePtBr);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rowName = !empty($row['name_encrypted']) ? Security::decryptData((string)$row['name_encrypted']) : '';
        $rowPtBr = !empty($row['name_pt_br_encrypted']) ? Security::decryptData((string)$row['name_pt_br_encrypted']) : '';
        if (normalizeLexicalChunkLookupValue((string)$rowName) === $targetName && normalizeLexicalChunkLookupValue((string)$rowPtBr) === $targetPtBr && normalizeNullableTagMetadataText($row['numero'] ?? null) === $numero && normalizeNullableTagMetadataText($row['sigla_simbolo'] ?? null) === $siglaSimbolo) {
            return [
                'tag_id' => (int)$row['id'],
                'en' => trim((string)$rowName),
                'pt_br' => trim((string)$rowPtBr),
                'numero' => normalizeNullableTagMetadataText($row['numero'] ?? null),
                'sigla_simbolo' => normalizeNullableTagMetadataText($row['sigla_simbolo'] ?? null),
                'user_id' => (int)$row['user_id'],
                'created' => false,
            ];
        }
    }

    $color = resolveTagColorByCategory(['is_lexical_chunk' => 1]);
    $insert = $pdo->prepare("
        INSERT INTO flashcard_tags
            (user_id, name_encrypted, name_pt_br_encrypted, numero, sigla_simbolo, color, is_book, is_verb_tense, is_sentence_type, is_lexical_chunk, is_relation_type, is_word, is_month, is_day, is_year)
        VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, 1, 0, 0, 0, 0, 0)
    ");

    try {
        $pdo->beginTransaction();
        $insert->execute([$ownerUserId, Security::encryptData($name), Security::encryptData($namePtBr), $numero, $siglaSimbolo, $color]);
        $tagId = (int)$pdo->lastInsertId();
        executeTagCreationCustomRules($pdo, $ownerUserId, $tagId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[flashcards][create_lexical_chunk_tag_for_owner] ' . $e->getMessage());
        throw $e;
    }

    return [
        'tag_id' => $tagId,
        'en' => $name,
        'pt_br' => $namePtBr,
        'numero' => $numero,
        'sigla_simbolo' => $siglaSimbolo,
        'user_id' => $ownerUserId,
        'created' => true,
    ];
}

function removeLeadingArticlesFromWordTagText(string $value, string $language = 'en'): string
{
    $value = preg_replace('/\s+/u', ' ', trim((string)$value));
    if ($value === '') return '';

    if ($language === 'pt') {
        $value = preg_replace('/^(o|a|os|as|um|uma|uns|umas)\s+/iu', '', (string)$value);
    } else {
        $value = preg_replace('/^(the|a|an)\s+/iu', '', (string)$value);
    }

    return preg_replace('/\s+/u', ' ', trim((string)$value));
}

function cleanWordTagText(string $value): string
{
    return removeLeadingArticlesFromWordTagText(cleanLexicalChunkTagText(expandEnglishContractionsForLexicalChunkTag($value)), 'en');
}

function normalizeWordTagLookupValue(string $value): string
{
    $value = cleanWordTagText($value);
    return function_exists('mb_strtolower') ? mb_strtolower($value ?? '', 'UTF-8') : strtolower($value ?? '');
}

function findOrCreateWordTag(PDO $pdo, int $userId, string $name, string $namePtBr, ?string $numero = null, ?string $siglaSimbolo = null): array
{
    $name = cleanWordTagText($name);
    $namePtBr = removeLeadingArticlesFromWordTagText(cleanLexicalChunkTagText($namePtBr), 'pt');
    $numero = normalizeNullableTagMetadataText($numero);
    $siglaSimbolo = normalizeNullableTagMetadataText($siglaSimbolo);
    if ($name === '' || $namePtBr === '') {
        throw new InvalidArgumentException('Tag de palavra inválida.');
    }

    $stmt = $pdo->prepare("
        SELECT id, user_id, name_encrypted, name_pt_br_encrypted, numero, sigla_simbolo, is_word
        FROM flashcard_tags
        WHERE user_id IN (?, 5)
        ORDER BY
            CASE WHEN is_word = 1 THEN 0 ELSE 1 END,
            CASE WHEN user_id = ? THEN 0 ELSE 1 END,
            id ASC
    ");
    $stmt->execute([$userId, $userId]);
    $targetName = normalizeWordTagLookupValue($name);
    $targetPtBr = normalizeLexicalChunkLookupValue($namePtBr);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rowName = !empty($row['name_encrypted']) ? Security::decryptData((string)$row['name_encrypted']) : '';
        $rowPtBr = !empty($row['name_pt_br_encrypted']) ? Security::decryptData((string)$row['name_pt_br_encrypted']) : '';
        if (normalizeWordTagLookupValue((string)$rowName) === $targetName && normalizeLexicalChunkLookupValue((string)$rowPtBr) === $targetPtBr && normalizeNullableTagMetadataText($row['numero'] ?? null) === $numero && normalizeNullableTagMetadataText($row['sigla_simbolo'] ?? null) === $siglaSimbolo) {
            return [
                'tag_id' => (int)$row['id'],
                'en' => trim((string)$rowName),
                'pt_br' => trim((string)$rowPtBr),
                'numero' => normalizeNullableTagMetadataText($row['numero'] ?? null),
                'sigla_simbolo' => normalizeNullableTagMetadataText($row['sigla_simbolo'] ?? null),
                'created' => false,
            ];
        }
    }

    $color = resolveTagColorByCategory(['is_word' => 1]);
    $insert = $pdo->prepare("
        INSERT INTO flashcard_tags
            (user_id, name_encrypted, name_pt_br_encrypted, numero, sigla_simbolo, color, is_book, is_verb_tense, is_sentence_type, is_lexical_chunk, is_relation_type, is_word, is_month, is_day, is_year)
        VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, 1, 0, 0, 0)
    ");

    try {
        $pdo->beginTransaction();
        $insert->execute([$userId, Security::encryptData($name), Security::encryptData($namePtBr), $numero, $siglaSimbolo, $color]);
        $tagId = (int)$pdo->lastInsertId();
        executeTagCreationCustomRules($pdo, $userId, $tagId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[flashcards][create_word_tag] ' . $e->getMessage());
        throw $e;
    }

    return [
        'tag_id' => $tagId,
        'en' => $name,
        'pt_br' => $namePtBr,
        'numero' => $numero,
        'sigla_simbolo' => $siglaSimbolo,
        'created' => true,
    ];
}


function findOrCreateWordTagForOwner(PDO $pdo, int $ownerUserId, string $name, string $namePtBr, ?string $numero = null, ?string $siglaSimbolo = null): array
{
    $ownerUserId = $ownerUserId === 5 ? 5 : $ownerUserId;
    $name = cleanWordTagText($name);
    $namePtBr = removeLeadingArticlesFromWordTagText(cleanLexicalChunkTagText($namePtBr), 'pt');
    $numero = normalizeNullableTagMetadataText($numero);
    $siglaSimbolo = normalizeNullableTagMetadataText($siglaSimbolo);
    if ($ownerUserId <= 0 || $name === '' || $namePtBr === '') {
        throw new InvalidArgumentException('Tag de palavra inválida.');
    }

    $stmt = $pdo->prepare("
        SELECT id, user_id, name_encrypted, name_pt_br_encrypted, numero, sigla_simbolo, is_word
        FROM flashcard_tags
        WHERE user_id = ?
        ORDER BY CASE WHEN is_word = 1 THEN 0 ELSE 1 END, id ASC
    ");
    $stmt->execute([$ownerUserId]);
    $targetName = normalizeWordTagLookupValue($name);
    $targetPtBr = normalizeLexicalChunkLookupValue($namePtBr);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rowName = !empty($row['name_encrypted']) ? Security::decryptData((string)$row['name_encrypted']) : '';
        $rowPtBr = !empty($row['name_pt_br_encrypted']) ? Security::decryptData((string)$row['name_pt_br_encrypted']) : '';
        if (normalizeWordTagLookupValue((string)$rowName) === $targetName && normalizeLexicalChunkLookupValue((string)$rowPtBr) === $targetPtBr && normalizeNullableTagMetadataText($row['numero'] ?? null) === $numero && normalizeNullableTagMetadataText($row['sigla_simbolo'] ?? null) === $siglaSimbolo) {
            return [
                'tag_id' => (int)$row['id'],
                'en' => trim((string)$rowName),
                'pt_br' => trim((string)$rowPtBr),
                'numero' => normalizeNullableTagMetadataText($row['numero'] ?? null),
                'sigla_simbolo' => normalizeNullableTagMetadataText($row['sigla_simbolo'] ?? null),
                'user_id' => (int)$row['user_id'],
                'created' => false,
            ];
        }
    }

    $color = resolveTagColorByCategory(['is_word' => 1]);
    $insert = $pdo->prepare("
        INSERT INTO flashcard_tags
            (user_id, name_encrypted, name_pt_br_encrypted, numero, sigla_simbolo, color, is_book, is_verb_tense, is_sentence_type, is_lexical_chunk, is_relation_type, is_word, is_month, is_day, is_year)
        VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, 1, 0, 0, 0)
    ");

    try {
        $pdo->beginTransaction();
        $insert->execute([$ownerUserId, Security::encryptData($name), Security::encryptData($namePtBr), $numero, $siglaSimbolo, $color]);
        $tagId = (int)$pdo->lastInsertId();
        executeTagCreationCustomRules($pdo, $ownerUserId, $tagId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[flashcards][create_word_tag_for_owner] ' . $e->getMessage());
        throw $e;
    }

    return [
        'tag_id' => $tagId,
        'en' => $name,
        'pt_br' => $namePtBr,
        'numero' => $numero,
        'sigla_simbolo' => $siglaSimbolo,
        'user_id' => $ownerUserId,
        'created' => true,
    ];
}

function normalizeGeminiTagKind(string $kind): string
{
    $kind = strtolower(trim($kind));
    return in_array($kind, ['common_noun', 'proper_noun', 'verb', 'other'], true) ? $kind : 'common_noun';
}


function normalizeTranslatedEnglishFrontSentence($value): string
{
    $text = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\r", "\n", "\t"], ' ', $text);
    $text = preg_replace('/\s+/u', ' ', trim((string)$text));
    if ($text === '') return '';
    return contractEnglishSentenceText(ensureSentenceEndsWithPeriod($text));
}

function normalizeFrontSentenceTagCandidate(array $raw, string $panel): ?array
{
    $metadata = normalizeGeneratedTagMetadata(
        (string)($raw['en'] ?? $raw['english'] ?? $raw['name'] ?? ''),
        (string)($raw['pt_br'] ?? $raw['ptBr'] ?? $raw['translation'] ?? $raw['name_pt_br'] ?? ''),
        $raw['numero'] ?? $raw['number'] ?? null,
        $raw['sigla_simbolo'] ?? $raw['symbol'] ?? $raw['abbreviation'] ?? null
    );
    $en = cleanWordTagText((string)$metadata['name']);
    $ptBr = removeLeadingArticlesFromWordTagText(cleanLexicalChunkTagText((string)$metadata['name_pt_br']), 'pt');
    if ($en === '' || $ptBr === '') return null;

    return [
        'panel' => in_array($panel, ['subject', 'object'], true) ? $panel : 'object',
        'en' => $en,
        'pt_br' => $ptBr,
        'numero' => $metadata['numero'],
        'sigla_simbolo' => $metadata['sigla_simbolo'],
        'kind' => normalizeGeminiTagKind((string)($raw['kind'] ?? $raw['type'] ?? 'common_noun')),
    ];
}


function getProperNounOwnerChoiceKey(array $candidate): string
{
    return hash('sha256', implode('|', [
        normalizeWordTagLookupValue((string)($candidate['en'] ?? '')),
        normalizeLexicalChunkLookupValue((string)($candidate['pt_br'] ?? '')),
        normalizeNullableTagMetadataText($candidate['numero'] ?? null),
        normalizeNullableTagMetadataText($candidate['sigla_simbolo'] ?? null),
    ]));
}


function getEnglishLexicalChunkFunctionWords(): array
{
    return [
        'prepositions' => array_fill_keys(['aboard','about','above','across','after','against','along','alongside','amid','among','around','as','at','before','behind','below','beneath','beside','besides','between','beyond','by','despite','down','during','except','for','from','in','inside','into','like','near','of','off','on','onto','opposite','out','outside','over','past','per','since','through','throughout','to','toward','towards','under','underneath','unlike','until','up','upon','versus','via','with','within','without'], true),
        'conjunctions' => array_fill_keys(['and','although','as','because','before','but','how','if','nor','once','or','provided','since','so','than','that','though','unless','until','when','whenever','where','whereas','wherever','whether','while','why','yet'], true),
        'particles' => array_fill_keys(['away','back','down','in','off','on','out','over','through','up'], true),
        'auxiliaries' => array_fill_keys(['am','are','be','been','being','can','could','did','do','does','had','has','have','is','may','might','must','shall','should','was','were','will','would'], true),
        'adverbs' => array_fill_keys(['again','almost','also','always','already','away','back','ever','even','far','here','how','just','never','not','now','often','only','rather','really','still','then','there','too','usually','very','well','when','where','why'], true),
        // Keep relative/subordinating markers such as "that" available for chunks
        // like "that carry". "That" is still listed as a determiner so the
        // sanitizer can decide from the chunk kind whether it is functional.
        'pronouns' => array_fill_keys(['i','me','my','mine','myself','you','your','yours','yourself','yourselves','he','him','his','himself','she','her','hers','herself','it','its','itself','we','us','our','ours','ourselves','they','them','their','theirs','themselves','who','whom','whose','which','what','whatever','whoever','whomever','someone','somebody','something','anyone','anybody','anything','everyone','everybody','everything','noone','nobody','nothing'], true),
        'determiners' => array_fill_keys(['a','an','the','this','that','these','those','another','any','each','either','every','many','much','neither','no','some','such'], true),
    ];
}

function normalizeEnglishLexicalChunkKind($kind): string
{
    $kind = strtolower(trim((string)$kind));
    $kind = str_replace(['-', ' '], '_', $kind);
    return $kind;
}

function lexicalChunkKindIsAllowedForStrictTags($kind): bool
{
    $kind = normalizeEnglishLexicalChunkKind($kind);
    return in_array($kind, [
        'verb', 'verb_phrase', 'phrasal_verb', 'verbal_phrase',
        'preposition', 'prepositional_phrase', 'preposition_combination', 'prepositions',
        'conjunction', 'conjunctive_phrase', 'conjunction_phrase', 'subordinating_conjunction',
        'coordinating_conjunction', 'particle', 'auxiliary', 'modal'
    ], true);
}

function sanitizeStrictEnglishLexicalChunkText(string $value, string $kind = ''): string
{
    $words = getEnglishLexicalChunkFunctionWords();
    $normalizedKind = normalizeEnglishLexicalChunkKind($kind);
    $value = cleanLexicalChunkTagText(expandEnglishContractionsForLexicalChunkTag($value));
    if ($value === '') return '';

    $parts = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) return '';

    $result = [];
    $replaceContentAfterDeterminer = false;
    foreach ($parts as $part) {
        $token = trim($part);
        if ($token === '') continue;
        if ($token === '...' || $token === '…') {
            $result[] = '...';
            $replaceContentAfterDeterminer = false;
            continue;
        }
        $lookup = strtolower(trim($token, " \t\n\r\0\x0B\"'()[]{}<>/\\|-–—"));
        if ($lookup === '') continue;
        if (isset($words['determiners'][$lookup])) {
            // In lexical chunks, explicit relative/subordinating "that" must be
            // preserved when Gemini labels the chunk as a conjunction/verb phrase
            // (e.g. "cards that carry..." => "that carry"), instead of
            // being treated like a demonstrative determiner.
            if ($lookup === 'that' && lexicalChunkKindIsAllowedForStrictTags($normalizedKind)) {
                $result[] = $token;
                $replaceContentAfterDeterminer = false;
                continue;
            }
            $replaceContentAfterDeterminer = true;
            continue;
        }
        if (isset($words['pronouns'][$lookup]) || $replaceContentAfterDeterminer) {
            $result[] = '...';
            $replaceContentAfterDeterminer = false;
            continue;
        }
        $result[] = $token;
    }

    $value = preg_replace('/(?:\.\.\.\s*){2,}/u', '... ', implode(' ', $result));
    return preg_replace('/\s+/u', ' ', trim((string)$value));
}

function isStrictAllowedEnglishLexicalChunk(string $en, string $kind = ''): bool
{
    $words = getEnglishLexicalChunkFunctionWords();
    $en = sanitizeStrictEnglishLexicalChunkText($en, $kind);
    if ($en === '') return false;
    $tokens = preg_split('/\s+/u', strtolower($en), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $meaningful = array_values(array_filter($tokens, static fn($token) => $token !== '...'));
    if (empty($meaningful)) return false;

    foreach ($meaningful as $token) {
        $lookup = trim($token, " \t\n\r\0\x0B\"'()[]{}<>/\\|-–—");
        if ($lookup === '') return false;
        if (isset($words['pronouns'][$lookup])) return false;
        if (isset($words['determiners'][$lookup]) && !($lookup === 'that' && lexicalChunkKindIsAllowedForStrictTags($kind))) return false;
    }

    if (count($meaningful) === 1) {
        $token = $meaningful[0];
        if (isset($words['prepositions'][$token]) || isset($words['conjunctions'][$token]) || isset($words['auxiliaries'][$token]) || lexicalChunkKindIsAllowedForStrictTags($kind)) return true;
    }

    $hasVerbSignal = lexicalChunkKindIsAllowedForStrictTags($kind);
    $hasFunctionSignal = false;
    foreach ($meaningful as $token) {
        if (isset($words['prepositions'][$token]) || isset($words['conjunctions'][$token]) || isset($words['particles'][$token]) || isset($words['auxiliaries'][$token]) || isset($words['adverbs'][$token])) {
            $hasFunctionSignal = true;
            if (isset($words['auxiliaries'][$token])) $hasVerbSignal = true;
            continue;
        }
        if (preg_match('/(?:ed|ing)$/iu', $token)) {
            $hasVerbSignal = true;
            continue;
        }
        if (!$hasVerbSignal) return false;
    }

    return $hasFunctionSignal || $hasVerbSignal;
}

function normalizeFrontSentenceChunkCandidate(array $raw): ?array
{
    [$rawEn, $rawPtBr] = normalizeDateLexicalChunkTexts(
        expandEnglishContractionsForLexicalChunkTag((string)($raw['en'] ?? $raw['english'] ?? $raw['name'] ?? '')),
        (string)($raw['pt_br'] ?? $raw['ptBr'] ?? $raw['translation'] ?? $raw['name_pt_br'] ?? '')
    );
    $metadata = normalizeGeneratedTagMetadata(
        $rawEn,
        $rawPtBr,
        $raw['numero'] ?? $raw['number'] ?? null,
        $raw['sigla_simbolo'] ?? $raw['symbol'] ?? $raw['abbreviation'] ?? null
    );
    $kind = normalizeEnglishLexicalChunkKind($raw['kind'] ?? $raw['type'] ?? '');
    $en = sanitizeStrictEnglishLexicalChunkText((string)$metadata['name'], $kind);
    $ptBr = cleanLexicalChunkTagText((string)$metadata['name_pt_br']);
    if ($en === '' || $ptBr === '' || !isStrictAllowedEnglishLexicalChunk($en, $kind)) return null;
    return ['en' => $en, 'pt_br' => $ptBr, 'numero' => $metadata['numero'], 'sigla_simbolo' => $metadata['sigla_simbolo'], 'kind' => $kind];
}

function dedupeFrontSentenceCandidates(array $candidates, string $type): array
{
    $seen = [];
    $deduped = [];
    foreach ($candidates as $candidate) {
        $key = ($candidate['panel'] ?? $type) . '|' . normalizeLexicalChunkLookupValue((string)($candidate['en'] ?? '')) . '|' . normalizeLexicalChunkLookupValue((string)($candidate['pt_br'] ?? '')) . '|' . normalizeNullableTagMetadataText($candidate['numero'] ?? null) . '|' . normalizeNullableTagMetadataText($candidate['sigla_simbolo'] ?? null);
        if ($key === '||' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $deduped[] = $candidate;
    }
    return $deduped;
}

function resolveTagColorByCategory(array $tagFlags): string {
    if (($tagFlags['is_book'] ?? 0) === 1) return '#f59e0b';
    if (($tagFlags['is_verb_tense'] ?? 0) === 1) return '#38bdf8';
    if (($tagFlags['is_sentence_type'] ?? 0) === 1) return '#fb7185';
    if (($tagFlags['is_lexical_chunk'] ?? 0) === 1) return '#d946ef';
    if (($tagFlags['is_relation_type'] ?? 0) === 1) return '#22d3ee';
    if (($tagFlags['is_word'] ?? 0) === 1) return '#84cc16';
    if (($tagFlags['is_month'] ?? 0) === 1) return '#14b8a6';
    if (($tagFlags['is_day'] ?? 0) === 1) return '#6366f1';
    if (($tagFlags['is_year'] ?? 0) === 1) return '#a855f7';
    return '#334155';
}

function getAllowedCardTagLinkTables(): array {
    return array_keys(getCardTagLinkColumnsByTable());
}

function getCardTagLinkColumnsByTable(): array {
    return [
        'flashcard_tag_links' => ['tag_id'],
        'subjects_links' => ['tag_id'],
        'objects_links' => ['tag_id'],
        'tipo_frasal_links' => ['tag_id'],
        'tense_links' => ['tag_id'],
        'lexical_chunks_links' => ['tag_id'],
        'relation_links' => ['tag_id'],
        'words_links' => ['tag_id'],
        'idiomas_links' => ['tag_id', 'segundo_idioma_tag_id'],
    ];
}

function getSubjectObjectLexicalChunkCardCountSubquery(string $alias = 'subjects_count'): string {
    return "
        SELECT linked.tag_id, COUNT(DISTINCT linked.flashcard_id) AS {$alias}
        FROM (
            SELECT tag_id, flashcard_id FROM subjects_links
            UNION ALL
            SELECT tag_id, flashcard_id FROM objects_links
            UNION ALL
            SELECT tag_id, flashcard_id FROM lexical_chunks_links
        ) linked
        INNER JOIN flashcards f ON f.id = linked.flashcard_id
        INNER JOIN directories d ON d.id = f.directory_id
        WHERE d.user_id IN (?, 5)
        GROUP BY linked.tag_id
    ";
}

function getTagCardCountSubqueryForLinkTable(string $linkTable, string $alias): string {
    $allowedTables = ['subjects_links', 'objects_links', 'lexical_chunks_links'];
    if (!in_array($linkTable, $allowedTables, true)) {
        throw new InvalidArgumentException('Tabela de contagem de tags inválida.');
    }

    return "
        SELECT l.tag_id, COUNT(DISTINCT l.flashcard_id) AS {$alias}
        FROM {$linkTable} l
        INNER JOIN flashcards f ON f.id = l.flashcard_id
        INNER JOIN directories d ON d.id = f.directory_id
        WHERE d.user_id IN (?, 5)
        GROUP BY l.tag_id
    ";
}

function ensureFlashcardTagScoresTable(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS flashcard_tag_scores (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            tag_id INT UNSIGNED NOT NULL,
            score INT UNSIGNED NOT NULL DEFAULT 0,
            last_reviewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_tag (user_id, tag_id),
            INDEX idx_user_tag_score (user_id, tag_id, score),
            CONSTRAINT fk_flashcard_tag_scores_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_flashcard_tag_scores_tag FOREIGN KEY (tag_id) REFERENCES flashcard_tags(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function collectTagIdsFromTagGroups(array ...$tagGroupsByCardList): array {
    $tagIds = [];
    foreach ($tagGroupsByCardList as $tagGroupsByCard) {
        foreach ($tagGroupsByCard as $tags) {
            foreach ($tags as $tag) {
                $tagId = (int)($tag['id'] ?? 0);
                if ($tagId > 0) $tagIds[$tagId] = true;
            }
        }
    }
    return array_keys($tagIds);
}

function fetchFlashcardTagScores(PDO $pdo, int $userId, array $tagIds): array {
    $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds), static fn($id) => $id > 0)));
    if ($userId <= 0 || empty($tagIds)) return [];

    ensureFlashcardTagScoresTable($pdo);
    $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
    $stmt = $pdo->prepare("
        SELECT tag_id, score
        FROM flashcard_tag_scores
        WHERE user_id = ? AND tag_id IN ($placeholders)
    ");
    $stmt->execute(array_merge([$userId], $tagIds));

    $scores = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $scores[(int)$row['tag_id']] = (int)$row['score'];
    }
    return $scores;
}

function cardHasLinkedTag(PDO $pdo, int $cardId, int $tagId, int $userId): bool {
    if ($cardId <= 0 || $tagId <= 0 || $userId <= 0) return false;

    $existsStmt = $pdo->prepare("
        SELECT id
        FROM flashcard_tags
        WHERE id = ? AND user_id IN (?, 5)
        LIMIT 1
    ");
    $existsStmt->execute([$tagId, $userId]);
    if (!$existsStmt->fetchColumn()) return false;

    foreach (getCardTagLinkColumnsByTable() as $table => $columns) {
        foreach ($columns as $column) {
            $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE flashcard_id = ? AND {$column} = ? LIMIT 1");
            $stmt->execute([$cardId, $tagId]);
            if ($stmt->fetchColumn()) return true;
        }
    }
    return false;
}

function incrementFlashcardTagScore(PDO $pdo, int $userId, int $cardId, int $tagId): void {
    if (!cardHasLinkedTag($pdo, $cardId, $tagId, $userId)) return;

    ensureFlashcardTagScoresTable($pdo);
    $stmt = $pdo->prepare("
        INSERT INTO flashcard_tag_scores (user_id, tag_id, score)
        VALUES (?, ?, 1)
        ON DUPLICATE KEY UPDATE
            score = score + 1,
            last_reviewed_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$userId, $tagId]);
}

function findSubjectCardIdsOrphanedByTagDeletion(PDO $pdo, int $tagId, int $userId, int $sampleLimit = 10): array {
    $countStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT sl.flashcard_id)
        FROM subjects_links sl
        INNER JOIN flashcards f ON f.id = sl.flashcard_id
        INNER JOIN directories d ON d.id = f.directory_id
        WHERE sl.tag_id = ?
          AND d.user_id = ?
          AND NOT EXISTS (
              SELECT 1
              FROM subjects_links other_sl
              WHERE other_sl.flashcard_id = sl.flashcard_id
                AND other_sl.tag_id <> ?
              LIMIT 1
          )
    ");
    $countStmt->execute([$tagId, $userId, $tagId]);
    $orphanedCount = (int)$countStmt->fetchColumn();

    if ($orphanedCount <= 0) {
        return ['count' => 0, 'sample_ids' => []];
    }

    $limit = max(0, $sampleLimit);
    if ($limit === 0) {
        return ['count' => $orphanedCount, 'sample_ids' => []];
    }

    $sampleStmt = $pdo->prepare("
        SELECT DISTINCT sl.flashcard_id
        FROM subjects_links sl
        INNER JOIN flashcards f ON f.id = sl.flashcard_id
        INNER JOIN directories d ON d.id = f.directory_id
        WHERE sl.tag_id = ?
          AND d.user_id = ?
          AND NOT EXISTS (
              SELECT 1
              FROM subjects_links other_sl
              WHERE other_sl.flashcard_id = sl.flashcard_id
                AND other_sl.tag_id <> ?
              LIMIT 1
          )
        ORDER BY sl.flashcard_id ASC
        LIMIT {$limit}
    ");
    $sampleStmt->execute([$tagId, $userId, $tagId]);
    $sampleIds = array_values(array_map('intval', $sampleStmt->fetchAll(PDO::FETCH_COLUMN)));

    return [
        'count' => $orphanedCount,
        'sample_ids' => $sampleIds,
    ];
}

/**
 * Função fetchLinkedTagsByCard: Busca as tags vinculadas a vários flashcards em uma tabela de ligação e devolve agrupado por card.
 */
function fetchLinkedTagsByCard(PDO $pdo, string $linkTable, array $cardIds, int $user_id): array {
    $allowedTables = getAllowedCardTagLinkTables();
    if (!in_array($linkTable, $allowedTables, true) || empty($cardIds)) return [];

    $tagPlaceholders = implode(',', array_fill(0, count($cardIds), '?'));
    $stmtTags = $pdo->prepare("
        SELECT l.flashcard_id, t.id AS tag_id, t.user_id AS tag_user_id, t.name_encrypted, t.name_pt_br_encrypted, t.numero, t.sigla_simbolo, t.color
        FROM {$linkTable} l
        JOIN flashcard_tags t ON t.id = l.tag_id
        WHERE l.flashcard_id IN ($tagPlaceholders) AND t.user_id IN (?, 5)
        ORDER BY t.id ASC
    ");
    $stmtTags->execute(array_merge($cardIds, [$user_id]));

    $linkedTagsByCard = [];
    foreach ($stmtTags->fetchAll() as $tagRow) {
        $flashcardId = (int)$tagRow['flashcard_id'];
        if (!isset($linkedTagsByCard[$flashcardId])) $linkedTagsByCard[$flashcardId] = [];
        $linkedTagsByCard[$flashcardId][] = [
            'id' => (int)$tagRow['tag_id'],
            'user_id' => (int)$tagRow['tag_user_id'],
            'is_user_owned' => ((int)$tagRow['tag_user_id'] === $user_id),
            'name' => !empty($tagRow['name_encrypted']) ? Security::decryptData($tagRow['name_encrypted']) : '',
            'name_pt_br' => !empty($tagRow['name_pt_br_encrypted']) ? Security::decryptData($tagRow['name_pt_br_encrypted']) : null,
            'numero' => normalizeNullableTagMetadataText($tagRow['numero'] ?? null),
            'sigla_simbolo' => normalizeNullableTagMetadataText($tagRow['sigla_simbolo'] ?? null),
            'color' => $tagRow['color']
        ];
    }
    return $linkedTagsByCard;
}

/**
 * Função fetchLinkedTagsByCardColumn: Busca tags vinculadas aos cards usando uma coluna específica de tag (principal ou secundária).
 */
function fetchLinkedTagsByCardColumn(PDO $pdo, string $linkTable, string $tagColumn, array $cardIds, int $user_id): array {
    $allowedTables = getAllowedCardTagLinkTables();
    $allowedColumns = ['tag_id', 'segundo_idioma_tag_id'];
    if (!in_array($linkTable, $allowedTables, true) || !in_array($tagColumn, $allowedColumns, true) || empty($cardIds)) return [];

    $tagPlaceholders = implode(',', array_fill(0, count($cardIds), '?'));
    $stmtTags = $pdo->prepare("
        SELECT l.flashcard_id, t.id AS tag_id, t.user_id AS tag_user_id, t.name_encrypted, t.name_pt_br_encrypted, t.numero, t.sigla_simbolo, t.color
        FROM {$linkTable} l
        JOIN flashcard_tags t ON t.id = l.{$tagColumn}
        WHERE l.flashcard_id IN ($tagPlaceholders) AND t.user_id IN (?, 5)
        ORDER BY t.id ASC
    ");
    $stmtTags->execute(array_merge($cardIds, [$user_id]));

    $linkedTagsByCard = [];
    foreach ($stmtTags->fetchAll() as $tagRow) {
        $flashcardId = (int)$tagRow['flashcard_id'];
        if (!isset($linkedTagsByCard[$flashcardId])) $linkedTagsByCard[$flashcardId] = [];
        $linkedTagsByCard[$flashcardId][] = [
            'id' => (int)$tagRow['tag_id'],
            'user_id' => (int)$tagRow['tag_user_id'],
            'is_user_owned' => ((int)$tagRow['tag_user_id'] === $user_id),
            'name' => !empty($tagRow['name_encrypted']) ? Security::decryptData($tagRow['name_encrypted']) : '',
            'name_pt_br' => !empty($tagRow['name_pt_br_encrypted']) ? Security::decryptData($tagRow['name_pt_br_encrypted']) : null,
            'numero' => normalizeNullableTagMetadataText($tagRow['numero'] ?? null),
            'sigla_simbolo' => normalizeNullableTagMetadataText($tagRow['sigla_simbolo'] ?? null),
            'color' => $tagRow['color']
        ];
    }
    return $linkedTagsByCard;
}

/**
 * Função syncCardIdiomaLinks: Sincroniza o vínculo de idiomas de um card, mantendo no máximo um idioma principal e um secundário válidos.
 */
function syncCardIdiomaLinks(PDO $pdo, int $card_id, array $principalTagIds, array $secundarioTagIds, int $user_id): void {
    $pdo->prepare("DELETE l FROM idiomas_links l JOIN flashcards f ON f.id = l.flashcard_id JOIN directories d ON d.id = f.directory_id WHERE l.flashcard_id = ? AND d.user_id = ?")
        ->execute([$card_id, $user_id]);

    $principalTagId = (int)($principalTagIds[0] ?? 0);
    if ($principalTagId <= 0) return;

    $secundarioTagId = (int)($secundarioTagIds[0] ?? 0);
    $stmtPrincipal = $pdo->prepare("SELECT id FROM flashcard_tags WHERE id = ? AND user_id IN (?, 5)");
    $stmtPrincipal->execute([$principalTagId, $user_id]);
    if (!$stmtPrincipal->fetchColumn()) return;

    $secundarioValue = null;
    if ($secundarioTagId > 0) {
        $stmtSecundario = $pdo->prepare("SELECT id FROM flashcard_tags WHERE id = ? AND user_id IN (?, 5)");
        $stmtSecundario->execute([$secundarioTagId, $user_id]);
        if ($stmtSecundario->fetchColumn()) $secundarioValue = $secundarioTagId;
    }

    $stmtInsert = $pdo->prepare("
        INSERT INTO idiomas_links (flashcard_id, tag_id, segundo_idioma_tag_id)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE segundo_idioma_tag_id = VALUES(segundo_idioma_tag_id)
    ");
    $stmtInsert->execute([$card_id, $principalTagId, $secundarioValue]);
}


function fetchTagFamilyDescendantIds(PDO $pdo, int $user_id, int $relationType, array $seedTagIds): array {
    $seeds = array_values(array_unique(array_filter(array_map('intval', $seedTagIds), fn($id) => $id > 0)));
    if (empty($seeds)) return [];

    $stmt = $pdo->prepare("SELECT id_tag_mother, id_tag_child FROM relacoes_taguineas WHERE id_user IN (?, 5) AND tipo_de_relacao = ?");
    $stmt->execute([$user_id, $relationType]);

    $childrenByMother = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $motherId = (int)($row['id_tag_mother'] ?? 0);
        $childId = (int)($row['id_tag_child'] ?? 0);
        if ($motherId <= 0 || $childId <= 0) continue;
        if (!isset($childrenByMother[$motherId])) $childrenByMother[$motherId] = [];
        $childrenByMother[$motherId][$childId] = true;
    }

    $found = [];
    $visitedMothers = [];
    $queue = $seeds;
    while (!empty($queue)) {
        $motherId = (int)array_shift($queue);
        if ($motherId <= 0 || isset($visitedMothers[$motherId])) continue;
        $visitedMothers[$motherId] = true;

        foreach (array_keys($childrenByMother[$motherId] ?? []) as $childId) {
            $childId = (int)$childId;
            if ($childId <= 0) continue;
            if (!isset($found[$childId])) {
                $found[$childId] = true;
                $queue[] = $childId;
            }
        }
    }

    return array_values(array_map('intval', array_keys($found)));
}

function insertCardTagLinks(PDO $pdo, string $linkTable, int $card_id, array $tagIds, int $user_id): void {
    $allowedTables = getAllowedCardTagLinkTables();
    if (!in_array($linkTable, $allowedTables, true)) return;

    $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds), fn($id) => $id > 0)));
    if (empty($tagIds)) return;

    $stmtInsertTag = $pdo->prepare("
        INSERT IGNORE INTO {$linkTable} (flashcard_id, tag_id)
        SELECT ?, t.id FROM flashcard_tags t WHERE t.id = ? AND t.user_id IN (?, 5)
    ");
    foreach ($tagIds as $tag_id) {
        $stmtInsertTag->execute([$card_id, $tag_id, $user_id]);
    }
}

function fetchCardSeedTagIds(PDO $pdo, int $cardId, array $linkTables): array {
    $allowedTables = getAllowedCardTagLinkTables();
    $tagIds = [];
    foreach ($linkTables as $linkTable) {
        if (!in_array($linkTable, $allowedTables, true)) continue;
        $stmt = $pdo->prepare("SELECT tag_id FROM {$linkTable} WHERE flashcard_id = ?");
        $stmt->execute([$cardId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $tagId) {
            $tagId = (int)$tagId;
            if ($tagId > 0) $tagIds[$tagId] = true;
        }
    }
    return array_values(array_map('intval', array_keys($tagIds)));
}

function fetchAffectedCardIdsForTag(PDO $pdo, int $user_id, int $tagId, array $linkTables): array {
    if ($tagId <= 0) return [];
    $allowedTables = getAllowedCardTagLinkTables();
    $selects = [];
    $params = [];
    foreach ($linkTables as $linkTable) {
        if (!in_array($linkTable, $allowedTables, true)) continue;
        $selects[] = "SELECT flashcard_id FROM {$linkTable} WHERE tag_id = ?";
        $params[] = $tagId;
    }
    if (empty($selects)) return [];

    $ownerCondition = $user_id === 5 ? '1 = 1' : '(d.user_id = ? OR f.created_by_user_id = ?)';
    $sql = "
        SELECT DISTINCT f.id
        FROM (" . implode(' UNION ', $selects) . ") linked
        INNER JOIN flashcards f ON f.id = linked.flashcard_id
        INNER JOIN directories d ON d.id = f.directory_id
        WHERE {$ownerCondition}
    ";
    if ($user_id !== 5) {
        $params[] = $user_id;
        $params[] = $user_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
}

function autoUpdateExistingCardsForTagFamilyRelation(PDO $pdo, int $user_id, int $relationType, int $motherTagId): int {
    $motherTagId = (int)$motherTagId;
    if ($motherTagId <= 0) return 0;

    if ($relationType === 19) {
        $cardIds = fetchAffectedCardIdsForTag($pdo, $user_id, $motherTagId, ['subjects_links', 'objects_links']);
        $updated = 0;
        foreach ($cardIds as $cardId) {
            $seedTagIds = fetchCardSeedTagIds($pdo, $cardId, ['subjects_links', 'objects_links']);
            $descendantTagIds = fetchTagFamilyDescendantIds($pdo, $user_id, 19, $seedTagIds);
            if (empty($descendantTagIds)) continue;
            insertCardTagLinks($pdo, 'objects_links', $cardId, $descendantTagIds, $user_id);
            $updated++;
        }
        return $updated;
    }

    if ($relationType === 21) {
        $cardIds = fetchAffectedCardIdsForTag($pdo, $user_id, $motherTagId, ['lexical_chunks_links']);
        $childTagIds = fetchTagFamilyDescendantIds($pdo, $user_id, 21, [$motherTagId]);
        if (empty($childTagIds)) return 0;
        foreach ($cardIds as $cardId) {
            insertCardTagLinks($pdo, 'lexical_chunks_links', $cardId, $childTagIds, $user_id);
        }
        return count($cardIds);
    }

    return 0;
}

/**
 * Função syncCardTagLinks: Sincroniza os vínculos de tags de um card em uma tabela de ligação, removendo antigos e inserindo os novos válidos.
 */
function syncCardTagLinks(PDO $pdo, string $linkTable, int $card_id, array $tagIds, int $user_id): void {
    $allowedTables = getAllowedCardTagLinkTables();
    if (!in_array($linkTable, $allowedTables, true)) return;

    $pdo->prepare("DELETE l FROM {$linkTable} l JOIN flashcards f ON f.id = l.flashcard_id JOIN directories d ON d.id = f.directory_id WHERE l.flashcard_id = ? AND d.user_id = ?")
        ->execute([$card_id, $user_id]);

    if (empty($tagIds)) return;

    $stmtInsertTag = $pdo->prepare("
        INSERT IGNORE INTO {$linkTable} (flashcard_id, tag_id)
        SELECT ?, t.id FROM flashcard_tags t WHERE t.id = ? AND t.user_id IN (?, 5)
    ");
    foreach ($tagIds as $tag_id) {
        $stmtInsertTag->execute([$card_id, $tag_id, $user_id]);
    }
}

// Função auxiliar para verificar se o usuário é dono do deck (Segurança IDOR)
/**
 * Função isFlashcardDeckDirectoryType: Verifica se o tipo de diretório representa um deck de flashcards aceito (tradicional ou fase).
 */
function isFlashcardDeckDirectoryType($type): bool {
    $type = (int)$type;
    return $type === 4 || $type === 10; // Deck tradicional ou Fase (deck de fase)
}

/**
 * Função verifyDeckOwnership: Confere se o deck pertence ao usuário e retorna seus metadados de configuração.
 */
function verifyDeckOwnership($pdo, $deck_id, $user_id, bool $allowPublicUserFive = false) {
    if ($allowPublicUserFive) {
        $stmt = $pdo->prepare("SELECT id, type, name_encrypted, deck_mode, deck_front_language, deck_back_language, deck_structure, id_tag_inicial, deck_generation_base_prompt FROM directories WHERE id = ? AND user_id IN (?, 5) AND type IN (4, 10)");
        $stmt->execute([$deck_id, $user_id]);
        return $stmt->fetch();
    }
    $stmt = $pdo->prepare("SELECT id, type, name_encrypted, deck_mode, deck_front_language, deck_back_language, deck_structure, id_tag_inicial, deck_generation_base_prompt FROM directories WHERE id = ? AND user_id = ? AND type IN (4, 10)");
    $stmt->execute([$deck_id, $user_id]);
    return $stmt->fetch();
}

/**
 * Função validatePhaseDeckUnlock: Valida se uma fase pode ser acessada, exigindo que a fase anterior esteja sem revisões pendentes.
 */
function validatePhaseDeckUnlock($pdo, $deck_id, $user_id): ?string {
    $stmtPhase = $pdo->prepare("
        SELECT p.id AS phase_id, p.parent_id AS map_id, m.parent_id AS track_id
        FROM directories p
        INNER JOIN directories m ON m.id = p.parent_id AND m.user_id = p.user_id
        WHERE p.id = ? AND p.user_id = ? AND p.type = 10 AND m.type = 9
        LIMIT 1
    ");
    $stmtPhase->execute([$deck_id, $user_id]);
    $phase = $stmtPhase->fetch();
    if (!$phase) return null;

    $track_id = isset($phase['track_id']) ? (int)$phase['track_id'] : 0;
    if ($track_id <= 0) return null;

    $stmtOrderedPhases = $pdo->prepare("
        SELECT p.id
        FROM directories m
        INNER JOIN directories p ON p.parent_id = m.id AND p.user_id = m.user_id AND p.type = 10
        WHERE m.user_id = ? AND m.type = 9 AND m.parent_id = ?
        ORDER BY m.sort_order ASC, m.id ASC, p.sort_order ASC, p.id ASC
    ");
    $stmtOrderedPhases->execute([$user_id, $track_id]);
    $orderedPhaseIds = array_map('intval', $stmtOrderedPhases->fetchAll(PDO::FETCH_COLUMN));

    $currentIndex = array_search((int)$deck_id, $orderedPhaseIds, true);
    if ($currentIndex === false || $currentIndex === 0) return null;

    $previousPhaseId = (int)$orderedPhaseIds[$currentIndex - 1];
    $stmtPrevStats = $pdo->prepare("
        SELECT
            COUNT(f.id) AS total_cards,
            COALESCE(SUM(CASE WHEN fs.next_review_at IS NULL OR fs.next_review_at <= NOW() THEN 1 ELSE 0 END), 0) AS due_cards
        FROM flashcards f
        LEFT JOIN flashcard_scores fs ON fs.flashcard_id = f.id AND fs.user_id = ?
        WHERE f.directory_id = ?
    ");
    $stmtPrevStats->execute([$user_id, $previousPhaseId]);
    $stats = $stmtPrevStats->fetch();

    $totalCards = (int)($stats['total_cards'] ?? 0);
    $dueCards = (int)($stats['due_cards'] ?? 0);
    if ($totalCards <= 0) {
        return 'Esta fase está bloqueada. A fase anterior ainda não possui flashcards para revisão.';
    }
    if ($dueCards > 0) {
        return 'Esta fase está bloqueada. Revise todos os flashcards da fase anterior para desbloquear.';
    }
    return null;
}


/**
 * Função buildGraphCardsSequence: Monta uma sequência balanceada de cards por tags e score para estudo em lotes.
 */
function buildGraphCardsSequence(array $cards, array $subjectTagsByCard, array $objectTagsByCard, array $lexicalChunksTagsByCard, ?int $initialTagId = null, array $tagScoreByTag = []): array
{
    if (empty($cards)) return [];

    $scoreByCard = [];
    foreach ($cards as $card) {
        $scoreByCard[(int)$card['id']] = (int)($card['score'] ?? 0);
    }

    $cardIdsBySubjectTag = buildGraphTagCardIndex($subjectTagsByCard);
    $cardIdsByObjectTag = buildGraphTagCardIndex($objectTagsByCard);
    $baseCardId = pickGraphBaseCardId(
        $cards,
        $subjectTagsByCard,
        $objectTagsByCard,
        $lexicalChunksTagsByCard,
        $cardIdsBySubjectTag,
        $scoreByCard,
        $initialTagId,
        $tagScoreByTag
    );
    if ($baseCardId === null) return [];

    $pickSubjectDecisionTag = static function (int $cardId, ?int $preferredTagId = null) use (&$subjectTagsByCard, &$tagScoreByTag): ?int {
        $subjectTags = sortGraphCardTagsByUserScore($subjectTagsByCard[$cardId] ?? [], $tagScoreByTag);
        if (empty($subjectTags)) return null;

        if ($preferredTagId !== null && $preferredTagId > 0) {
            foreach ($subjectTags as $tag) {
                if ((int)($tag['id'] ?? 0) === $preferredTagId) return $preferredTagId;
            }
        }

        $tagId = (int)($subjectTags[0]['id'] ?? 0);
        return $tagId > 0 ? $tagId : null;
    };

    $pickNextGraphCardId = static function (int $tagId, array $candidateIndex, array $usedCards) use (&$scoreByCard): ?int {
        if ($tagId <= 0) return null;

        $candidateIds = array_values(array_unique(array_map('intval', $candidateIndex[$tagId] ?? [])));
        usort($candidateIds, static function (int $a, int $b) use (&$scoreByCard): int {
            return ((int)($scoreByCard[$a] ?? 0) <=> (int)($scoreByCard[$b] ?? 0)) ?: ($a <=> $b);
        });

        foreach ($candidateIds as $candidateId) {
            if ($candidateId > 0 && !isset($usedCards[$candidateId])) return $candidateId;
        }

        return null;
    };

    $firstDecisionTagId = $pickSubjectDecisionTag($baseCardId, $initialTagId);
    if ($firstDecisionTagId === null) return [[
        'card_id' => $baseCardId,
        'decision_tag' => null,
    ]];

    $chosen = [[
        'card_id' => $baseCardId,
        'decision_tag' => $firstDecisionTagId,
    ]];
    $usedCards = [$baseCardId => true];

    // Depois do primeiro card, o Subject selecionado nele fica fixo para toda a consulta.
    // As posições pares buscam cards inéditos que tenham essa tag fixa como Object.
    // As posições ímpares buscam cards inéditos que tenham essa tag fixa como Subject.
    for ($position = 2; $position <= count($cards); $position++) {
        $candidateIndex = ($position % 2 === 0) ? $cardIdsByObjectTag : $cardIdsBySubjectTag;
        $nextCardId = $pickNextGraphCardId($firstDecisionTagId, $candidateIndex, $usedCards);
        if ($nextCardId === null) break;

        $chosen[] = [
            'card_id' => $nextCardId,
            'decision_tag' => $firstDecisionTagId,
        ];

        $usedCards[$nextCardId] = true;
        $scoreByCard[$nextCardId] = (int)($scoreByCard[$nextCardId] ?? 0) + 1;
    }

    return $chosen;
}

function buildGraphTagCardIndex(array $tagsByCard): array
{
    $index = [];
    foreach ($tagsByCard as $cardId => $tags) {
        $cardId = (int)$cardId;
        if ($cardId <= 0) continue;

        foreach ($tags as $tag) {
            $tagId = (int)($tag['id'] ?? 0);
            if ($tagId <= 0) continue;
            if (!isset($index[$tagId])) $index[$tagId] = [];
            $index[$tagId][] = $cardId;
        }
    }

    foreach ($index as $tagId => $cardIds) {
        $index[$tagId] = array_values(array_unique(array_map('intval', $cardIds)));
    }

    return $index;
}

function sortGraphCardTagsByUserScore(array $tags, array $tagScoreByTag): array
{
    $uniqueTags = [];
    foreach ($tags as $tag) {
        $tagId = (int)($tag['id'] ?? 0);
        if ($tagId <= 0 || isset($uniqueTags[$tagId])) continue;
        $uniqueTags[$tagId] = $tag;
    }

    $tags = array_values($uniqueTags);
    usort($tags, static function (array $a, array $b) use (&$tagScoreByTag): int {
        $tagA = (int)($a['id'] ?? 0);
        $tagB = (int)($b['id'] ?? 0);
        $tagAHasScore = array_key_exists($tagA, $tagScoreByTag);
        $tagBHasScore = array_key_exists($tagB, $tagScoreByTag);

        if ($tagAHasScore !== $tagBHasScore) {
            return $tagAHasScore <=> $tagBHasScore;
        }

        return ((int)($tagScoreByTag[$tagA] ?? 0) <=> (int)($tagScoreByTag[$tagB] ?? 0)) ?: ($tagA <=> $tagB);
    });

    return $tags;
}

function pickGraphBaseCardId(array $cards, array $subjectTagsByCard, array $objectTagsByCard, array $lexicalChunksTagsByCard, array $cardIdsBySubjectTag, array $scoreByCard, ?int $initialTagId, array $tagScoreByTag): ?int
{
    $cardsById = [];
    foreach ($cards as $card) {
        $cardId = (int)($card['id'] ?? 0);
        if ($cardId > 0) $cardsById[$cardId] = $card;
    }

    $pickWeakestCard = static function (array $candidateIds) use (&$scoreByCard): ?int {
        $candidateIds = array_values(array_unique(array_filter(array_map('intval', $candidateIds), static fn($id) => $id > 0)));
        if (empty($candidateIds)) return null;

        usort($candidateIds, static function (int $a, int $b) use (&$scoreByCard): int {
            return ((int)($scoreByCard[$a] ?? 0) <=> (int)($scoreByCard[$b] ?? 0)) ?: ($a <=> $b);
        });

        return $candidateIds[0] ?? null;
    };

    if ($initialTagId !== null && $initialTagId > 0) {
        $candidateIds = $cardIdsBySubjectTag[$initialTagId] ?? [];
        $initialCardId = $pickWeakestCard($candidateIds);
        if ($initialCardId !== null && isset($cardsById[$initialCardId])) return $initialCardId;
    }

    if (!empty($cardIdsBySubjectTag)) {
        $subjectTags = [];
        $userOwnedSubjectTags = [];
        foreach ($subjectTagsByCard as $tags) {
            foreach ($tags as $tag) {
                $tagId = (int)($tag['id'] ?? 0);
                if ($tagId <= 0 || isset($subjectTags[$tagId])) continue;
                $subjectTags[$tagId] = $tag;
                if (!empty($tag['is_user_owned'])) $userOwnedSubjectTags[$tagId] = $tag;
            }
        }

        $sortedTags = sortGraphCardTagsByUserScore(!empty($userOwnedSubjectTags) ? array_values($userOwnedSubjectTags) : array_values($subjectTags), $tagScoreByTag);
        foreach ($sortedTags as $tag) {
            $tagId = (int)($tag['id'] ?? 0);
            $cardId = $pickWeakestCard($cardIdsBySubjectTag[$tagId] ?? []);
            if ($cardId !== null && isset($cardsById[$cardId])) return $cardId;
        }
    }

    usort($cards, static function (array $a, array $b): int {
        return ((int)($a['score'] ?? 0) <=> (int)($b['score'] ?? 0)) ?: ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
    });

    $fallbackCardId = (int)($cards[0]['id'] ?? 0);
    return $fallbackCardId > 0 ? $fallbackCardId : null;
}


/**
 * Função verifyDirectoryOwnership: Confere se um diretório pertence ao usuário autenticado.
 */
function verifyDirectoryOwnership($pdo, $directory_id, $user_id) {
    $stmt = $pdo->prepare("SELECT id, type, name_encrypted FROM directories WHERE id = ? AND user_id = ?");
    $stmt->execute([$directory_id, $user_id]);
    return $stmt->fetch();
}

/**
 * Função collectDecksFromDirectoryTree: Percorre a árvore de diretórios a partir de uma raiz e coleta todos os decks de flashcards encontrados.
 */
function collectDecksFromDirectoryTree($pdo, $root_directory_id, $user_id) {
    $decks = [];
    $visited = [];

    $stmtRoot = $pdo->prepare("SELECT id, type, deck_front_language, deck_back_language, deck_structure FROM directories WHERE id = ? AND user_id = ?");
    $stmtRoot->execute([$root_directory_id, $user_id]);
    $root = $stmtRoot->fetch();
    if (!$root) {
        return $decks;
    }

    $queue = [(int)$root['id']];
    if (isFlashcardDeckDirectoryType($root['type'])) {
        $decks[] = $root;
    }

    while (!empty($queue)) {
        $pending = [];
        foreach ($queue as $directory_id) {
            if (!isset($visited[$directory_id])) {
                $visited[$directory_id] = true;
                $pending[] = $directory_id;
            }
        }

        if (empty($pending)) {
            break;
        }

        $placeholders = implode(',', array_fill(0, count($pending), '?'));
        $params = array_merge([$user_id], $pending);
        $stmtChildren = $pdo->prepare("SELECT id, type, deck_front_language, deck_back_language, deck_structure FROM directories WHERE user_id = ? AND parent_id IN ($placeholders)");
        $stmtChildren->execute($params);
        $children = $stmtChildren->fetchAll();

        $queue = [];
        foreach ($children as $child) {
            $child_id = (int)$child['id'];
            $queue[] = $child_id;
            if (isFlashcardDeckDirectoryType($child['type'])) {
                $decks[] = $child;
            }
        }
    }

    return $decks;
}

/**
 * Função countPendingAudiosForDeck: Conta quantos lados de cards de um deck ainda precisam ter áudio gerado.
 */
function countPendingAudiosForDeck($pdo, $deck_id) {
    $pending = 0;
    $stmtCards = $pdo->prepare("SELECT front_encrypted, back_encrypted, has_audio_front, has_audio_back FROM flashcards WHERE directory_id = ?");
    $stmtCards->execute([$deck_id]);
    $cards = $stmtCards->fetchAll();

    foreach ($cards as $card) {
        if ((int)$card['has_audio_front'] === 0) {
            $front_text = !empty($card['front_encrypted']) ? trim(strip_tags(Security::decryptData($card['front_encrypted']))) : '';
            if ($front_text !== '' && !cardTextContainsMathNotation($front_text)) {
                $pending++;
            }
        }

        if ((int)$card['has_audio_back'] === 0) {
            $back_text = !empty($card['back_encrypted']) ? trim(strip_tags(Security::decryptData($card['back_encrypted']))) : '';
            if ($back_text !== '' && !cardTextContainsMathNotation($back_text)) {
                $pending++;
            }
        }
    }

    return $pending;
}

/**
 * Função findNextPendingAudioJobForDeck: Localiza o próximo lado de card pendente de áudio e retorna os dados necessários para geração.
 */
function findNextPendingAudioJobForDeck($pdo, $deck_id, $front_language, $back_language) {
    $stmtCards = $pdo->prepare("SELECT id, front_encrypted, back_encrypted, has_audio_front, has_audio_back FROM flashcards WHERE directory_id = ? AND (has_audio_front = 0 OR has_audio_back = 0) ORDER BY sort_order ASC, id ASC");
    $stmtCards->execute([$deck_id]);
    $cards = $stmtCards->fetchAll();

    foreach ($cards as $card) {
        if ((int)$card['has_audio_front'] === 0) {
            $front_text = !empty($card['front_encrypted']) ? trim(strip_tags(Security::decryptData($card['front_encrypted']))) : '';
            if ($front_text !== '' && !cardTextContainsMathNotation($front_text)) {
                return [
                    'card_id' => (int)$card['id'],
                    'side' => 'front',
                    'text' => $front_text,
                    'language' => $front_language
                ];
            }
        }

        if ((int)$card['has_audio_back'] === 0) {
            $back_text = !empty($card['back_encrypted']) ? trim(strip_tags(Security::decryptData($card['back_encrypted']))) : '';
            if ($back_text !== '' && !cardTextContainsMathNotation($back_text)) {
                return [
                    'card_id' => (int)$card['id'],
                    'side' => 'back',
                    'text' => $back_text,
                    'language' => $back_language
                ];
            }
        }
    }

    return null;
}

// Função auxiliar para verificar a propriedade de um card unitário
/**
 * Função verifyCardOwnership: Confere se um card pertence ao usuário por meio do deck ao qual ele está associado.
 */
function verifyCardOwnership($pdo, $card_id, $user_id, bool $allowPublicUserFive = false) {
    if ($allowPublicUserFive) {
        $stmt = $pdo->prepare("SELECT f.id, f.directory_id, f.created_by_user_id, f.private_directory_id FROM flashcards f JOIN directories d ON f.directory_id = d.id WHERE f.id = ? AND (d.user_id IN (?, 5) OR f.created_by_user_id = ?)");
        $stmt->execute([$card_id, $user_id, $user_id]);
        return $stmt->fetch();
    }
    $stmt = $pdo->prepare("SELECT f.id, f.directory_id, f.created_by_user_id, f.private_directory_id FROM flashcards f JOIN directories d ON f.directory_id = d.id WHERE f.id = ? AND (d.user_id = ? OR f.created_by_user_id = ?)");
    $stmt->execute([$card_id, $user_id, $user_id]);
    return $stmt->fetch();
}

/**
 * Função para ajustar a pronúncia do TTS (Text-to-Speech).
 * Substitui siglas e palavras estrangeiras pela sua fonética correspondente em português.
 * Pilar: Fácil Manutenção (Basta adicionar novas siglas no array $replacements).
 */
/**
 * Função normalizeDeckLanguage: Normaliza o idioma do deck para um valor permitido, aplicando fallback padrão.
 */
function normalizeDeckLanguage($value, $default = 'pt-BR') {
    $allowed = ['pt-BR', 'en-US', 'en-GB'];
    return in_array($value, $allowed, true) ? $value : $default;
}

/**
 * Função normalizeDeckStructure: Normaliza a estrutura do deck para um tipo permitido, aplicando fallback padrão.
 */
function normalizeDeckStructure($value, $default = 'traducoes') {
    $allowed = ['fatos', 'perguntas', 'traducoes', 'parafrases', 'ingles'];
    return in_array($value, $allowed, true) ? $value : $default;
}

/**
 * Função getFishReferenceIdByLanguage: Retorna o reference_id da Fish Audio conforme o idioma selecionado.
 */
function getFishReferenceIdByLanguage($language) {
    switch ($language) {
        case 'pt-BR': return FISH_REFERENCE_ID_PT_BR;
        case 'en-US': return FISH_REFERENCE_ID_EN_US;
        case 'en-GB': return FISH_REFERENCE_ID_EN_GB;
        default: return FISH_REFERENCE_ID_BACK;
    }
}

/**
 * Função getGoogleTtsVoiceByLanguage: Retorna a voz padrão do Google TTS para o idioma informado.
 */
function getGoogleTtsVoiceByLanguage($language) {
    switch ($language) {
        case 'pt-BR': return 'pt-BR-Chirp3-HD-Algieba'; //Algenib* //Charon //Enceladus** //Fenrir** //Iapetus //Vindemiatrix*FEMME //Pulcherrima*FEMME
        case 'en-US': return 'en-US-Chirp3-HD-Algieba';
        case 'en-GB': return 'en-GB-Chirp3-HD-Algieba';
        default: return 'en-US-Chirp3-HD-Algieba';
    }
}

/**
 * Função getGoogleTtsAlternateVoiceByLanguage: Retorna uma voz alternativa do Google TTS para evitar repetição entre frente e verso.
 */
function getGoogleTtsAlternateVoiceByLanguage($language) {
    switch ($language) {
        case 'pt-BR': return 'pt-BR-Chirp3-HD-Algieba';
        case 'en-US': return 'en-US-Chirp3-HD-Enceladus';
        case 'en-GB': return 'en-GB-Chirp3-HD-Algieba';
        default: return 'en-US-Chirp3-HD-Enceladus';
    }
}

/**
 * Função getGoogleTtsVoiceForDeckContext: Escolhe a voz do Google TTS considerando lado do card, idiomas e estrutura do deck.
 */
function getGoogleTtsVoiceForDeckContext($side, $language, $deck_structure, $front_language, $back_language) {
    $normalized_structure = normalizeDeckStructure($deck_structure, 'traducoes');
    $normalized_front = normalizeDeckLanguage($front_language, 'pt-BR');
    $normalized_back = normalizeDeckLanguage($back_language, 'en-GB');

    if (
        $normalized_structure === 'traducoes'
        && $normalized_front === 'pt-BR'
        && $normalized_back === 'en-GB'
    ) {
        if ($side === 'front') {
            return 'pt-BR-Chirp3-HD-Algieba';
        }

        if ($side === 'back') {
            return 'en-GB-Chirp3-HD-Algieba';
        }
    }

    if (
        $normalized_structure === 'perguntas'
        && $normalized_front === 'pt-BR'
        && $normalized_back === 'pt-BR'
    ) {
        if ($side === 'front') {
            return 'pt-BR-Chirp3-HD-Algieba'; //pt-BR-Chirp3-HD-Rasalgethi
        }

        if ($side === 'back') {
            return 'pt-BR-Chirp3-HD-Algieba';//pt-BR-Chirp3-HD-Algenib //pt-BR-Chirp3-HD-Zubenelgenubi
        }
    }

    if ($side === 'back') {
        $front_default_voice = getGoogleTtsVoiceByLanguage($normalized_front);
        $back_default_voice = getGoogleTtsVoiceByLanguage($normalized_back);

        if ($front_default_voice === $back_default_voice) {
            return getGoogleTtsAlternateVoiceByLanguage($normalized_back);
        }

        return $back_default_voice;
    }

    return getGoogleTtsVoiceByLanguage($normalized_front);
}

/**
 * Função getLanguageLabel: Converte código de idioma em rótulo legível para exibição.
 */
function getLanguageLabel($language) {
    $map = [
        'pt-BR' => 'Português Brasileiro',
        'en-US' => 'Inglês Americano',
        'en-GB' => 'Inglês Britânico'
    ];
    return $map[$language] ?? $language;
}

/**
 * Função adjustPronunciationForTTS: Aplica substituições fonéticas cadastradas para melhorar a pronúncia do TTS.
 */
function adjustPronunciationForTTS($pdo, $text, $language) {
    $allowed = ['pt-BR', 'en-US', 'en-GB'];
    if (!in_array($language, $allowed, true)) {
        return $text;
    }

    $stmt = $pdo->prepare("SELECT source_text, target_text FROM pronuncias WHERE language = ? ORDER BY CHAR_LENGTH(source_text) DESC");
    $stmt->execute([$language]);
    $replacements = $stmt->fetchAll();

    if (!$replacements) {
        return $text;
    }

    foreach ($replacements as $item) {
        $source = trim((string)$item['source_text']);
        $target = (string)$item['target_text'];
        if ($source === '') {
            continue;
        }

        $pattern = '/\\b' . preg_quote($source, '/') . '\\b/iu';
        $text = preg_replace($pattern, $target, $text);
    }

    return $text;
}

/**
 * Função cardTextContainsMathNotation: Detecta se o texto contém notação matemática/LaTeX para evitar geração indevida de áudio.
 */
function cardTextContainsMathNotation($text) {
    $value = trim((string)$text);
    if ($value === '') {
        return false;
    }

    $patterns = [
        '/\\\\\(|\\\\\)|\\\\\[|\\\\\]/u',
        '/\$\$[^$]+\$\$|\$[^$]+\$/u',
        '/\\\\(frac|sqrt|sum|int|prod|lim|cdot|times|pm|mp|neq|leq|geq|left|right|alpha|beta|gamma|delta|theta|lambda|mu|pi|sigma|omega)\b/iu',
        '/[∑∫√≈≠≤≥∞πΔθλμ±÷×]/u',
        '/[²³¹⁰⁴⁵⁶⁷⁸⁹⁻⁺₀₁₂₃₄₅₆₇₈₉]/u',
        '/[A-Za-z0-9\)\]]\s*=\s*[A-Za-z0-9\(\[\\-]/u',
        '/\d+\s*[+\-*/^]\s*\d+/u',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $value) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Função decodeTtsAudioBinaryFromJsonPayload: Extrai e decodifica áudio binário de diferentes formatos de payload JSON de provedores TTS.
 */
function decodeTtsAudioBinaryFromJsonPayload($payload) {
    if (!is_array($payload)) {
        return null;
    }

    $candidateKeys = ['audio', 'audio_base64', 'audioContent', 'base64', 'data', 'result'];

    foreach ($candidateKeys as $key) {
        if (!array_key_exists($key, $payload)) {
            continue;
        }

        $value = $payload[$key];
        if (is_array($value)) {
            $nested = decodeTtsAudioBinaryFromJsonPayload($value);
            if ($nested !== null) {
                return $nested;
            }
            continue;
        }

        if (!is_string($value)) {
            continue;
        }

        $raw = trim($value);
        if ($raw === '') {
            continue;
        }

        if (preg_match('#^data:audio/[^;]+;base64,#i', $raw) === 1) {
            $parts = explode(',', $raw, 2);
            $raw = $parts[1] ?? '';
        }

        $decoded = base64_decode($raw, true);
        if ($decoded !== false && $decoded !== '') {
            return $decoded;
        }
    }

    return null;
}

/**
 * Função normalizeStoredAudioToBinary: Normaliza áudio salvo (base64 ou data URI) para conteúdo binário utilizável.
 */
function normalizeStoredAudioToBinary($audioValue) {
    if (!is_string($audioValue) || $audioValue === '') {
        return null;
    }

    $raw = trim($audioValue);
    if (preg_match('#^data:audio/[^;]+;base64,#i', $raw) === 1) {
        $parts = explode(',', $raw, 2);
        $raw = $parts[1] ?? '';
    }

    $decoded = base64_decode($raw, true);
    if ($decoded !== false && $decoded !== '') {
        return $decoded;
    }

    return $audioValue;
}

/**
 * Função normalizeTtsProvider: Normaliza o provedor de TTS para valores suportados, com fallback.
 */
function normalizeTtsProvider($value, $default = 'fishaudio') {
    $allowed = ['fishaudio', 'openai', 'google'];
    return in_array($value, $allowed, true) ? $value : $default;
}

/**
 * Função getUserTtsProvider: Obtém do banco o provedor de TTS preferido do usuário, já normalizado.
 */
function getUserTtsProvider($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT tts_provider FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $provider = $stmt->fetchColumn();
    return normalizeTtsProvider((string)$provider, 'fishaudio');
}

/**
 * Função buildTtsProviderErrorDetails: Monta uma mensagem de erro detalhada e padronizada para falhas de provedores TTS.
 */
function buildTtsProviderErrorDetails($provider, $httpcode, $curlError, $responseBody = null) {
    $providerLabel = strtoupper((string)$provider);
    $parts = ["Provider {$providerLabel}"];

    if ($httpcode > 0) {
        $parts[] = "HTTP {$httpcode}";
    }

    if (is_string($curlError) && trim($curlError) !== '') {
        $parts[] = 'cURL: ' . trim($curlError);
    }

    if (is_string($responseBody) && trim($responseBody) !== '') {
        $decoded = json_decode($responseBody, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $apiMessage = null;
            if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                $apiMessage = $decoded['error']['message'];
            } elseif (isset($decoded['message']) && is_string($decoded['message'])) {
                $apiMessage = $decoded['message'];
            }

            if ($apiMessage !== null && trim($apiMessage) !== '') {
                $parts[] = 'API: ' . trim($apiMessage);
            }
        }
    }

    return implode(' | ', $parts);
}

/**
 * Função requestFishAudioTts: Solicita áudio ao provedor Fish Audio e retorna o binário gerado.
 */
function requestFishAudioTts($text_to_speech, $language, &$error_details = null) {
    if (trim((string)FISH_API_KEY) === '') {
        $error_details = 'Chave FISH_API_KEY não configurada.';
        return null;
    }

    $reference_id = getFishReferenceIdByLanguage($language);
    $ch = curl_init('https://api.fish.audio/v1/tts');
    $payload = json_encode([
        "text" => $text_to_speech,
        "reference_id" => $reference_id,
        "format" => "mp3"
    ]);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . FISH_API_KEY,
        "Content-Type: application/json",
        "model: s2"
    ]);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpcode !== 200 || !$response) {
        $error_details = buildTtsProviderErrorDetails('fishaudio', (int)$httpcode, $curlError, is_string($response) ? $response : null);
        return null;
    }

    $audio_binary = null;
    $decodedResponse = json_decode($response, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedResponse)) {
        $audio_binary = decodeTtsAudioBinaryFromJsonPayload($decodedResponse);
    }

    if ($audio_binary === null) {
        $audio_binary = $response;
    }

    $error_details = null;

    return is_string($audio_binary) && $audio_binary !== '' ? $audio_binary : null;
}

/**
 * Função requestOpenAITts: Solicita áudio ao endpoint de TTS da OpenAI e retorna o binário MP3.
 */
function requestOpenAITts($text_to_speech, &$error_details = null) {
    if (trim((string)OPENAI_API_KEY) === '') {
        $error_details = 'Chave OPENAI_API_KEY não configurada.';
        return null;
    }

    $ch = curl_init('https://api.openai.com/v1/audio/speech');
    $payload = json_encode([
        'model' => 'gpt-5.4',
        'voice' => 'alloy',
        'input' => $text_to_speech,
        'format' => 'mp3'
    ]);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . OPENAI_API_KEY,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpcode !== 200 || !$response) {
        $error_details = buildTtsProviderErrorDetails('openai', (int)$httpcode, $curlError, is_string($response) ? $response : null);
        return null;
    }

    $error_details = null;

    return is_string($response) && $response !== '' ? $response : null;
}


/**
 * Função requestGoogleCloudTts: Solicita áudio ao Google Cloud TTS com voz contextual do deck e retorna o binário.
 */
function requestGoogleCloudTts($text_to_speech, $language, $side = null, $deck_structure = 'traducoes', $front_language = 'pt-BR', $back_language = 'en-GB', &$error_details = null) {
    if (trim((string)GOOGLE_CLOUD_API_KEY) === '') {
        $error_details = 'Chave GOOGLE_CLOUD_API_KEY não configurada.';
        return null;
    }

    $voice_name = getGoogleTtsVoiceForDeckContext($side, $language, $deck_structure, $front_language, $back_language);
    $ch = curl_init('https://texttospeech.googleapis.com/v1/text:synthesize?key=' . rawurlencode(GOOGLE_CLOUD_API_KEY));
    $payload = json_encode([
        'input' => ['text' => $text_to_speech],
        'voice' => [
            'languageCode' => $language,
            'name' => $voice_name
        ],
        'audioConfig' => [
            'audioEncoding' => 'MP3'
        ]
    ]);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpcode !== 200 || !$response) {
        $error_details = buildTtsProviderErrorDetails('google', (int)$httpcode, $curlError, is_string($response) ? $response : null);
        return null;
    }

    $decodedResponse = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decodedResponse)) {
        $error_details = 'Provider GOOGLE | resposta inválida ao decodificar JSON.';
        return null;
    }

    $audio_binary = decodeTtsAudioBinaryFromJsonPayload($decodedResponse);
    if (!is_string($audio_binary) || $audio_binary === '') {
        $error_details = buildTtsProviderErrorDetails('google', (int)$httpcode, '', $response);
        return null;
    }

    $error_details = null;
    return is_string($audio_binary) && $audio_binary !== '' ? $audio_binary : null;
}



/**
 * Função generateAndPersistCardAudio: Gera áudio para um lado do card com o provedor configurado e persiste no banco criptografado.
 */
function generateAndPersistCardAudio($pdo, $user_id, $card_id, $side, $text, $language, $deck_structure = 'traducoes', $front_language = 'pt-BR', $back_language = 'en-GB', &$error_details = null) {
    $text_to_speech = adjustPronunciationForTTS($pdo, $text, $language);
    $provider = getUserTtsProvider($pdo, (int)$user_id);
    $provider_error = null;

    if ($provider === 'openai') {
        $audio_binary = requestOpenAITts($text_to_speech, $provider_error);
    } elseif ($provider === 'google') {
        $audio_binary = requestGoogleCloudTts($text_to_speech, $language, $side, $deck_structure, $front_language, $back_language, $provider_error);
    } else {
        $audio_binary = requestFishAudioTts($text_to_speech, $language, $provider_error);
    }

    if (!is_string($audio_binary) || $audio_binary === '') {
        $error_details = $provider_error ?: ('Falha ao gerar áudio com o provider ' . strtoupper($provider) . '.');
        return false;
    }

    $audio_b64 = base64_encode($audio_binary);
    $audio_encrypted = Security::encryptData($audio_b64);

    $audioCol = $side === 'front' ? 'audio_front_encrypted' : 'audio_back_encrypted';
    $col = $side === 'front' ? 'has_audio_front' : 'has_audio_back';
    $stmt = $pdo->prepare("UPDATE flashcards SET $col = 1, $audioCol = ? WHERE id = ?");
    $stmt->execute([$audio_encrypted, $card_id]);

    $error_details = null;

    return true;
}

/**
 * Função buildDefaultBasePromptByStructure: Monta o prompt base padrão de geração conforme a estrutura do deck.
 */
function buildDefaultBasePromptByStructure($deck_name, $deck_structure) {
    $basePrompt = '';
    if ($deck_structure === 'fatos') {
        $basePrompt = 'Me dê informações sobre o assunto "$deck_name", em uma tabela de uma única coluna, onde cada linha é uma informação. As informações devem ser de fácil compreensão. As informações devem ser óbvias evidentes e de rápida assimilação e de preferência curtas. As informações devem ser sequenciais, uma sendo continuação da outra, igual em um livro, em um texto grande. Dê exemplo ou faça analogias, quando cabível, para o aluno sair um pouco da abstração e conseguir entender tanto a parte abstrata quanto enxergar o abstrato no mundo concreto.';
    } elseif ($deck_structure === 'perguntas') {
        $basePrompt = 'Me dê perguntas que induzam conhecimento fundamental, elementar, essencial, indispensável sobre o assunto "$deck_name" coisas elementares e ontológicas sobre, hermenêutica, em uma tabela de duas colunas, onde cada linha é uma pergunta, a primeira coluna é a pergunta, e a segunda é a resposta. As perguntas e respostas devem ser óbvias evidentes e de rápida assimilação. Use linguagem simples e de fácil assimilação para pessoas de qualquer nível intelectual. As pessoas devem conseguir decodificar a informação codificada nas perguntas e respostas de forma assustadoramente fácil. As perguntas devem ser simples e de preferência curtas. Nenhuma pergunta pode ser igual as informações anteriores (salvo em paráfrases, uso de sinônimos e oposição ex. frase negativa e frase afirmativa) que já fiz. O objetivo é construir aprendizado progressivo sem redundância. Público alvo são crianças de 10 à 17 anos.';
    } elseif ($deck_structure === 'parafrases') {
        $basePrompt = 'gere 5 paráfrases dessa: "$deck_name".';
    } elseif ($deck_structure === 'ingles') {
        $basePrompt = '
        Explique o termo/expressão "$deck_name" e quais todas as formas de usar ele em frases em inglês, semânticamente, morfologicamente, sintática e pragmática.
        Perguntas assustadoramente simples de entender e respostas também. Qualquer leigo deve ter extrema facilidade assustadora de compreender o texto da pergunta e da resposta.
        Quando cabível apenas: Abranja todas as polissemias, homonímias, flexões de gênero, flexões de número, flexões de grau, flexões para adequar a pronomes, flexões de tempo, flexões de modo.
        Quando cabível apenas: Abranja também verbos frasais.
        Quando não cabível e não houver variação morfológica no termo, não precisa perguntas sobre essa flexão, só faça perguntas de flexão, quando houver variação morfológica no termo.
        Também faça perguntas sobre se pode usar esse termo em determinada posição na fase ou não.
        Caso a expressão seja um verbo, faça perguntas sobre a morfologia e semântica adequada para cada pessoa e para cada tempo. Explique a semântica de acordo com o tempo, não precisa falar o nome do tempo, quando for fala de flexões temporais de um verbo, referencie essa flexão pela semântica dela e não pelo nome do tempo verbal.
        Lembre-se, o aluno não é professor de gramática, então ele não sabe o significado do glossário gramatical que coloquei nesse prompt, logo, não use ele.
        Finalize todas as explicações com exemplo e tradução.
        Caso o termo for verbo, lembre-se das flexões de tempo nos 12 tempos verbais.
        Caso o termo for verbo, lembre-se das variações em verbos frasais.
        Os textos serão lidos por TTS, então evite sinais e setas, pode usar vígulas, aspas, pontos finais e de interrogação.
        Gere 100 cards.';
    } else {
        $basePrompt = 'Crie 10 frases ordinárias em inglês que tenha dentro da usa estrutura sintática exatamente esse bloco lexical "$deck_name". Front/frente do card a frase traduzida para português brasileiro e back/verso do card a frase original em inglês.';
    }
    return $basePrompt;
}

/**
 * Função applyGenerationPromptTemplateVariables: Substitui variáveis de template (ex.: $deck_name) no prompt de geração.
 */
function applyGenerationPromptTemplateVariables($prompt, $deck_name) {
    $normalizedPrompt = (string)$prompt;
    $normalizedDeckName = trim((string)$deck_name);
    return str_replace('$deck_name', $normalizedDeckName, $normalizedPrompt);
}

/**
 * Função normalizeGenerationBasePromptInput: Sanitiza e limita o tamanho do prompt base personalizado antes do uso.
 */
function normalizeGenerationBasePromptInput($value) {
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }
    return function_exists('mb_substr') ? mb_substr($text, 0, 5000) : substr($text, 0, 5000);
}

/**
 * Função buildFlashcardsGenerationPayload: Monta o payload completo para gerar flashcards via modelo, com schema JSON estrito.
 */
function buildFlashcardsGenerationPayload($deck_name, $deck_structure, $historyText, $model = 'gpt-5.4', $customBasePrompt = '') {
    $basePrompt = normalizeGenerationBasePromptInput($customBasePrompt);
    if ($basePrompt === '') {
        $basePrompt = buildDefaultBasePromptByStructure($deck_name, $deck_structure);
    }
    $basePrompt = applyGenerationPromptTemplateVariables($basePrompt, $deck_name);

    $systemPrompt = 'Você é um gerador de flashcards para estudo. Retorne APENAS JSON válido no formato {"cards":[{"front":"...","back":"..."}]}. Não use markdown. Nunca deixe "front" vazio. Para estruturas perguntas, traducoes e ingles, nunca deixe "back" vazio. Para estruturas fatos e parafrases, deixe back vazio. Preserve exatamente caracteres Unicode.';

    $userPrompt = $basePrompt
        . "

REGRAS DE LIMPEZA DE SAÍDA:
Nunca inclua menus, botões, placeholders, atalhos de teclado, termos de interface ou listas de símbolos soltas. Retorne apenas conteúdo pedagógico dos cards.";

    if ($deck_structure === 'parafrases') {
        $userPrompt .= "

Não considere cards anteriores deste deck para gerar a resposta.";
    } else {
        $userPrompt .= "

CARDS JÁ EXISTENTES NESTE DECK:
" . $historyText
            . "

Gere novos cards sem repetição de conteúdo com o histórico.";
    }

    $requiresBack = in_array($deck_structure, ['perguntas', 'traducoes', 'ingles'], true);
    $backSchema = $requiresBack ? ['type' => 'string', 'minLength' => 1] : ['type' => 'string'];

    return [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ],
        'response_format' => [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'cards_preview_response',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'cards' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'front' => ['type' => 'string', 'minLength' => 1],
                                    'back' => $backSchema
                                ],
                                'required' => ['front', 'back'],
                                'additionalProperties' => false
                            ]
                        ]
                    ],
                    'required' => ['cards'],
                    'additionalProperties' => false
                ]
            ]
        ]
    ];
}

/**
 * Função sanitizeGeneratedCards: Valida e higieniza o JSON de cards gerado, removendo itens inválidos.
 */
function sanitizeGeneratedCards($rawContent, $deck_structure) {
    $raw = trim((string)$rawContent);
    if ($raw !== '' && str_starts_with($raw, '```')) {
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/', '', $raw);
        $raw = trim((string)$raw);
    }
    $json = json_decode($raw, true);
    if (!is_array($json) || !isset($json['cards']) || !is_array($json['cards'])) {
        return [];
    }

    $cards = [];
    foreach ($json['cards'] as $card) {
        $front = trim((string)($card['front'] ?? ''));
        $back = trim((string)($card['back'] ?? ''));
        if ($front === '') continue;
        if (in_array($deck_structure, ['fatos', 'parafrases'], true)) {
            $back = '';
        } elseif ($back === '') {
            continue;
        }
        $cards[] = ['front' => $front, 'back' => $back];
    }
    return $cards;
}

/**
 * Função openaiJsonRequest: Executa requisição POST JSON para a OpenAI e retorna HTTP code, resposta e erro cURL.
 */
function openaiJsonRequest($url, $payload) {
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


/**
 * Função geminiJsonRequest: Executa requisição POST JSON para a API do Gemini e retorna HTTP code, resposta e erro cURL.
 */
function geminiJsonRequest($model, $payload) {
    $safe_model = preg_replace('/[^a-zA-Z0-9._-]/', '', (string)$model);
    if ($safe_model === '') {
        return [0, '', 'Modelo Gemini inválido.'];
    }

    $base_url = rtrim(GEMINI_API_URL, '/');
    $url = $base_url . '/' . rawurlencode($safe_model) . ':generateContent';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-goog-api-key: ' . GEMINI_API_KEY
    ]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    return [$httpcode, $response, $curlError];
}

/**
 * Função extractGeminiText: Extrai o texto concatenado das partes retornadas pelo Gemini.
 */
function extractGeminiText($decoded) {
    if (!is_array($decoded)) return '';

    $parts = $decoded['candidates'][0]['content']['parts'] ?? [];
    if (!is_array($parts)) return '';

    $texts = [];
    foreach ($parts as $part) {
        $text = trim((string)($part['text'] ?? ''));
        if ($text !== '') $texts[] = $text;
    }

    return trim(implode("\n", $texts));
}

/**
 * Normaliza frases para detectar duplicatas ignorando caixa, pontuação e espaços extras.
 */

function normalizeTextForExactPhraseMatch(string $text): string
{
    $normalized = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $normalized = function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower($normalized);
    $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string)$normalized);
    return trim(preg_replace('/\s+/u', ' ', (string)$normalized));
}

function textContainsExactNormalizedPhrase(string $text, string $phrase): bool
{
    $normalizedText = normalizeTextForExactPhraseMatch($text);
    $normalizedPhrase = normalizeTextForExactPhraseMatch($phrase);
    if ($normalizedPhrase === '') return true;
    if ($normalizedText === '') return false;
    return preg_match('/(?:^|\s)' . preg_quote($normalizedPhrase, '/') . '(?:\s|$)/u', $normalizedText) === 1;
}

function normalizeSentenceForDuplicateCheck(string $text): string {
    $normalized = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $normalized = function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower($normalized);
    $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized);
    return trim(preg_replace('/\s+/u', ' ', (string)$normalized));
}

/**
 * Localiza tags equivalentes do usuário público 5 pelo mesmo texto inglês/pt-BR da tag escolhida.
 * Isso permite evitar frases públicas repetidas mesmo quando o usuário seleciona a própria cópia da tag.
 */
function findEquivalentUserFiveTagIds(PDO $pdo, int $tag_id, string $tag_text_en, string $tag_text_pt_br): array {
    $ids = [];

    $stmtSelected = $pdo->prepare("SELECT user_id FROM flashcard_tags WHERE id = ? LIMIT 1");
    $stmtSelected->execute([$tag_id]);
    if ((int)$stmtSelected->fetchColumn() === 5) {
        $ids[] = $tag_id;
    }

    $targetName = normalizeLexicalChunkLookupValue($tag_text_en);
    $targetPtBr = normalizeLexicalChunkLookupValue($tag_text_pt_br);
    if ($targetName === '' && $targetPtBr === '') {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    $stmt = $pdo->prepare("
        SELECT id, name_encrypted, name_pt_br_encrypted
        FROM flashcard_tags
        WHERE user_id = 5
    ");
    $stmt->execute();

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rowName = !empty($row['name_encrypted']) ? Security::decryptData((string)$row['name_encrypted']) : '';
        $rowPtBr = !empty($row['name_pt_br_encrypted']) ? Security::decryptData((string)$row['name_pt_br_encrypted']) : '';
        if (normalizeLexicalChunkLookupValue((string)$rowName) === $targetName
            && normalizeLexicalChunkLookupValue((string)$rowPtBr) === $targetPtBr) {
            $ids[] = (int)$row['id'];
        }
    }

    return array_values(array_unique(array_filter(array_map('intval', $ids))));
}

/**
 * Busca somente frases de cards do usuário público 5 para evitar enviar dados privados de outros usuários ao Gemini.
 */
function fetchUserFiveSubjectCardSentences(PDO $pdo, $tag_ids): array {
    $tagIds = is_array($tag_ids) ? $tag_ids : [$tag_ids];
    $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds))));
    if (empty($tagIds)) return [];

    $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
    $stmt = $pdo->prepare("
        SELECT DISTINCT f.front_encrypted, f.id
        FROM subjects_links sl
        INNER JOIN flashcards f ON f.id = sl.flashcard_id
        INNER JOIN directories d ON d.id = f.directory_id
        WHERE sl.tag_id IN ($placeholders)
          AND d.user_id = 5
        ORDER BY f.id DESC
    ");
    $stmt->execute($tagIds);

    $sentences = [];
    $seen = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $card) {
        $sentence = !empty($card['front_encrypted']) ? trim(strip_tags(Security::decryptData($card['front_encrypted']))) : '';
        $normalized = normalizeSentenceForDuplicateCheck($sentence);
        if ($sentence === '' || $normalized === '' || isset($seen[$normalized])) continue;
        $seen[$normalized] = true;
        $sentences[] = $sentence;
    }

    return $sentences;
}

/**
 * Função openaiGetRequest: Executa requisição GET para a OpenAI e retorna HTTP code, resposta e erro cURL.
 */
function openaiGetRequest($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . OPENAI_API_KEY
    ]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    return [$httpcode, $response, $curlError];
}


/**
 * Função normalizeDictionarySentence: Normaliza frase para uso em dicionário, limpando espaços e formatação básica.
 */
function normalizeDictionarySentence($value) {
    $text = trim((string)$value);
    if ($text === '') return '';
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim((string)$text);
}

/**
 * Função normalizeDictionarySentenceKey: Gera chave normalizada da frase para deduplicação no dicionário de sentenças.
 */
function normalizeDictionarySentenceKey($value) {
    $normalized = normalizeDictionarySentence($value);
    if ($normalized === '') return '';
    return mb_strtolower($normalized, 'UTF-8');
}

/**
 * Função extractDictionaryCandidatesFromGpt: Extrai e valida candidatos de sentenças retornados pelo GPT para alimentar o dicionário.
 */
function extractDictionaryCandidatesFromGpt($text) {
    $inputText = trim((string)$text);
    if ($inputText === '') {
        return ['ok' => false, 'error' => 'Texto vazio para análise.', 'candidates' => []];
    }
    if (OPENAI_API_KEY === '') {
        return ['ok' => false, 'error' => 'OPENAI_API_KEY não configurada no .env.', 'candidates' => []];
    }

    $payload = [
        'model' => 'gpt-5.4',
        'temperature' => 0,
        'response_format' => [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'dictionary_candidates',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'generated_sentences' => ['type' => 'array', 'items' => ['type' => 'string']]
                    ],
                    'required' => ['generated_sentences'],
                    'additionalProperties' => false
                ]
            ]
        ],
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Você retorna apenas JSON válido seguindo o schema.'
            ],
            [
                'role' => 'user',
                'content' => 'split this sentence into lexical chuncks, then create one sentence for each lexical chunk, replacing that chunk with [LEXICAL CHUNK]. Return ONLY JSON with key generated_sentences. Sentence: "' . $inputText . '"'
            ]
        ]
    ];

    list($httpcode, $response, $curlError) = openaiJsonRequest('https://api.openai.com/v1/chat/completions', $payload);
    if ($httpcode !== 200 || !$response) {
        $details = trim((string)$curlError);
        $decodedErr = json_decode((string)$response, true);
        if (!$details && is_array($decodedErr)) {
            $details = (string)($decodedErr['error']['message'] ?? '');
        }
        return ['ok' => false, 'error' => 'Erro ao analisar frase com OpenAI.' . ($details !== '' ? (' Detalhes: ' . $details) : ''), 'candidates' => []];
    }

    $decoded = json_decode($response, true);
    $content = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));
    if ($content === '') {
        return ['ok' => false, 'error' => 'OpenAI não retornou conteúdo analisável.', 'candidates' => []];
    }

    $parsed = json_decode($content, true);
    if (!is_array($parsed)) {
        return ['ok' => false, 'error' => 'OpenAI retornou JSON inválido para candidatos.', 'candidates' => []];
    }

    $candidatesByKey = [];
    foreach (($parsed['generated_sentences'] ?? []) as $item) {
        $sentence = normalizeDictionarySentence($item);
        $sentenceKey = normalizeDictionarySentenceKey($sentence);
        if ($sentence !== '' && $sentenceKey !== '' && !isset($candidatesByKey[$sentenceKey])) {
            $candidatesByKey[$sentenceKey] = $sentence;
        }
    }

    return ['ok' => true, 'error' => '', 'candidates' => array_values($candidatesByKey)];
}

/**
 * Função syncBatchJobWithOpenAI: Sincroniza o status de um job em lote com a OpenAI e persiste progresso/resultado no banco.
 */
function syncBatchJobWithOpenAI($pdo, $job) {
    $openaiBatchId = trim((string)($job['openai_batch_id'] ?? ''));
    if ($openaiBatchId === '') {
        return ['ok' => false, 'error' => 'Job sem batch_id da OpenAI.', 'job' => null];
    }

    list($statusCode, $statusResponse, $statusErr) = openaiGetRequest('https://api.openai.com/v1/batches/' . rawurlencode($openaiBatchId));
    if ($statusCode !== 200 || !$statusResponse) {
        $details = trim($statusErr);
        $decodedErr = json_decode((string)$statusResponse, true);
        if (!$details && is_array($decodedErr)) $details = (string)($decodedErr['error']['message'] ?? '');
        return ['ok' => false, 'error' => 'Falha ao consultar status na OpenAI.' . ($details ? (' Detalhes: ' . $details) : ''), 'job' => null];
    }

    $statusDecoded = json_decode($statusResponse, true);
    $newStatus = trim((string)($statusDecoded['status'] ?? $job['status']));
    $outputFileId = trim((string)($statusDecoded['output_file_id'] ?? ''));
    $errorFileId = trim((string)($statusDecoded['error_file_id'] ?? ''));

    $cardsJsonToStore = $job['result_cards_json'];
    $errorMessage = $job['error_message'];
    $completedAt = $job['completed_at'];

    if ($newStatus === 'completed' && $outputFileId !== '') {
        list($fileCode, $fileContent, $fileErr) = openaiGetRequest('https://api.openai.com/v1/files/' . rawurlencode($outputFileId) . '/content');
        if ($fileCode === 200 && $fileContent) {
            $cards = [];
            $lines = preg_split('/\r\n|\r|\n/', (string)$fileContent);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $lineDecoded = json_decode($line, true);
                $content = (string)($lineDecoded['response']['body']['choices'][0]['message']['content'] ?? '');
                if ($content === '') continue;
                $cards = sanitizeGeneratedCards($content, $job['deck_structure']);
                if (!empty($cards)) break;
            }
            if (!empty($cards)) {
                $cardsJsonToStore = json_encode($cards, JSON_UNESCAPED_UNICODE);
                $errorMessage = null;
            } else {
                $errorMessage = 'Batch concluído, mas o conteúdo retornou vazio ou fora do formato esperado.';
            }
            $completedAt = date('Y-m-d H:i:s');
        } else {
            $errorMessage = 'Batch concluído, porém não foi possível baixar o arquivo de saída.' . ($fileErr ? (' Detalhes: ' . $fileErr) : '');
        }
    } elseif (in_array($newStatus, ['failed', 'cancelled', 'expired'], true)) {
        $errorMessage = 'O batch terminou com status: ' . $newStatus;
        $completedAt = date('Y-m-d H:i:s');
    }

    $upd = $pdo->prepare("UPDATE flashcard_batch_jobs SET status = ?, openai_output_file_id = ?, openai_error_file_id = ?, error_message = ?, result_cards_json = ?, completed_at = ? WHERE id = ?");
    $upd->execute([$newStatus, $outputFileId !== '' ? $outputFileId : null, $errorFileId !== '' ? $errorFileId : null, $errorMessage, $cardsJsonToStore, $completedAt, (int)$job['id']]);

    return [
        'ok' => true,
        'error' => null,
        'job' => [
            'id' => (int)$job['id'],
            'openai_batch_id' => $openaiBatchId,
            'status' => $newStatus,
            'openai_output_file_id' => $outputFileId,
            'has_result' => !empty($cardsJsonToStore),
            'error_message' => $errorMessage
        ]
    ];
}

/**
 * Função fetchDeckHistoryText: Monta texto histórico dos cards do deck para evitar repetição em novas gerações.
 */
function fetchDeckHistoryText($pdo, $deck_id) {
    $stmt = $pdo->prepare("SELECT front_encrypted, back_encrypted FROM flashcards WHERE directory_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$deck_id]);
    $existing_cards = $stmt->fetchAll();

    $history_lines = [];
    foreach ($existing_cards as $c) {
        $front = trim(!empty($c['front_encrypted']) ? Security::decryptData($c['front_encrypted']) : '');
        $back = trim(!empty($c['back_encrypted']) ? Security::decryptData($c['back_encrypted']) : '');
        if ($front === '' && $back === '') continue;
        $history_lines[] = "Frente: {$front} | Verso: {$back}";
    }
    return !empty($history_lines) ? implode("
", $history_lines) : 'Sem cards prévios no deck.';
}

if ($action === 'fetch') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    $directory_id = (int)($input['directory_id'] ?? 0);
    $study_scope = (string)($input['study_scope'] ?? '');

    if ($study_scope === 'directory_random') {
        if ($directory_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do diretório inválido.']));

        $directory = verifyDirectoryOwnership($pdo, $directory_id, $user_id);
        if (!$directory) {
            die(json_encode(['status' => 'error', 'message' => 'Diretório não encontrado ou sem permissão.']));
        }

        $decks = collectDecksFromDirectoryTree($pdo, $directory_id, $user_id);
        $deck_ids = [];
        foreach ($decks as $deckEntry) {
            $deck_ids[] = (int)$deckEntry['id'];
        }

        if (empty($deck_ids)) {
            echo json_encode([
                'status' => 'success',
                'deck_name' => 'Revisão da Pasta • ' . Security::decryptData($directory['name_encrypted']),
                'deck_mode' => 'aleatorio',
                'deck_front_language' => 'pt-BR',
                'deck_back_language' => 'en-GB',
                'deck_structure' => 'traducoes',
                'generation_base_prompt_default' => '',
                'deck_percentage' => 0,
                'book_completed_reads' => 0,
                'book_completed_reads_max' => 3,
                'book_next_review_at' => null,
                'total_cards' => 0,
                'current_index' => 0,
                'data' => []
            ]);
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($deck_ids), '?'));

        $stmtCards = $pdo->prepare("
            SELECT f.id, f.front_encrypted, f.back_encrypted, f.image_front_encrypted, f.image_back_encrypted, f.info_type, f.question_answer, f.created_at, f.has_audio_front, f.has_audio_back, COALESCE(f.created_by_user_id, d.user_id) AS card_owner_user_id, COALESCE(fs.score, 0) as score
            FROM flashcards f
            JOIN directories d ON d.id = f.directory_id
            LEFT JOIN flashcard_scores fs ON fs.flashcard_id = f.id AND fs.user_id = ?
            WHERE f.directory_id IN ($placeholders)
              AND f.has_audio_front = 1
              AND f.has_audio_back = 1
              AND (
                (fs.id IS NOT NULL AND fs.next_review_at IS NOT NULL AND fs.next_review_at <= NOW())
                OR fs.id IS NULL
                OR COALESCE(fs.score, 0) = 0
              )
            ORDER BY
                CASE
                    WHEN fs.id IS NOT NULL AND fs.next_review_at IS NOT NULL AND fs.next_review_at <= NOW() THEN 0
                    WHEN fs.id IS NULL OR COALESCE(fs.score, 0) = 0 THEN 1
                    ELSE 2
                END,
                RAND()
        ");
        $stmtCards->execute(array_merge([$user_id], $deck_ids));
        $cards = $stmtCards->fetchAll();

        $stmtTotal = $pdo->prepare("SELECT COUNT(id) FROM flashcards WHERE directory_id IN ($placeholders)");
        $stmtTotal->execute($deck_ids);
        $total_cards_in_directory = (int)$stmtTotal->fetchColumn();

        $stmtScore = $pdo->prepare("
            SELECT COALESCE(SUM(fs.score), 0)
            FROM flashcard_scores fs
            JOIN flashcards f ON fs.flashcard_id = f.id
            WHERE fs.user_id = ? AND f.directory_id IN ($placeholders)
        ");
        $stmtScore->execute(array_merge([$user_id], $deck_ids));
        $total_score_directory = (int)$stmtScore->fetchColumn();
        $max_possible_score = $total_cards_in_directory * 20;
        $deck_percentage = $max_possible_score > 0 ? round(($total_score_directory / $max_possible_score) * 100) : 0;
        
        //$cardIds são todos os cards (do deck) sem discriminação no caso de grafos, e com filtro de vencimento no caso de aleatório
        $cardIds = array_map(static fn($card) => (int)$card['id'], $cards);
        //$subjectTagsByCard todas as tags da subcategoria "subject" dos cards de $cardIds
        $subjectTagsByCard = fetchLinkedTagsByCard($pdo, 'subjects_links', $cardIds, $user_id);
        $objectTagsByCard = fetchLinkedTagsByCard($pdo, 'objects_links', $cardIds, $user_id);
        $tipoFrasalTagsByCard = fetchLinkedTagsByCard($pdo, 'tipo_frasal_links', $cardIds, $user_id);
        $tenseTagsByCard = fetchLinkedTagsByCard($pdo, 'tense_links', $cardIds, $user_id);
        $lexicalChunksTagsByCard = fetchLinkedTagsByCard($pdo, 'lexical_chunks_links', $cardIds, $user_id);
        $relationTagsByCard = fetchLinkedTagsByCard($pdo, 'relation_links', $cardIds, $user_id);
        $wordsTagsByCard = fetchLinkedTagsByCard($pdo, 'words_links', $cardIds, $user_id);
        $idiomaPrincipalTagsByCard = fetchLinkedTagsByCardColumn($pdo, 'idiomas_links', 'tag_id', $cardIds, $user_id);
        $idiomaSecundarioTagsByCard = fetchLinkedTagsByCardColumn($pdo, 'idiomas_links', 'segundo_idioma_tag_id', $cardIds, $user_id);

        $response = [];
        foreach ($cards as $card) {
            $response[] = [
                'id' => $card['id'],
                'front' => !empty($card['front_encrypted']) ? Security::decryptData($card['front_encrypted']) : '',
                'back' => !empty($card['back_encrypted']) ? Security::decryptData($card['back_encrypted']) : '',
                'image_front' => !empty($card['image_front_encrypted']) ? Security::decryptData($card['image_front_encrypted']) : null,
                'image_back' => !empty($card['image_back_encrypted']) ? Security::decryptData($card['image_back_encrypted']) : null,
                'info_type' => sanitizeInfoType($card['info_type'] ?? 2),
                'question_answer' => $card['question_answer'] === null ? null : (int)$card['question_answer'],
                'created_at' => $card['created_at'] ?? null,
                'has_audio_front' => (int)$card['has_audio_front'],
                'has_audio_back' => (int)$card['has_audio_back'],
                'score' => (int)$card['score'],
                'card_owner_user_id' => isset($card['card_owner_user_id']) ? (int)$card['card_owner_user_id'] : $user_id,
                'is_public_card' => (isset($card['card_owner_user_id']) && (int)$card['card_owner_user_id'] !== (int)$user_id) ? 1 : 0,
                'subject_tags' => $subjectTagsByCard[(int)$card['id']] ?? [],
                'object_tags' => $objectTagsByCard[(int)$card['id']] ?? [],
                'tipo_frasal_tags' => $tipoFrasalTagsByCard[(int)$card['id']] ?? [],
                'tense_tags' => $tenseTagsByCard[(int)$card['id']] ?? [],
                'lexical_chunks_tags' => $lexicalChunksTagsByCard[(int)$card['id']] ?? [],
                'relation_tags' => $relationTagsByCard[(int)$card['id']] ?? [],
                'words_tags' => $wordsTagsByCard[(int)$card['id']] ?? [],
                'idioma_principal_tags' => $idiomaPrincipalTagsByCard[(int)$card['id']] ?? [],
                'idioma_secundario_tags' => $idiomaSecundarioTagsByCard[(int)$card['id']] ?? [],
                'idiomas_tags' => $idiomaPrincipalTagsByCard[(int)$card['id']] ?? []
            ];
        }

        echo json_encode([
            'status' => 'success',
            'deck_name' => 'Revisão da Pasta • ' . Security::decryptData($directory['name_encrypted']),
            'deck_mode' => 'aleatorio',
            'deck_front_language' => 'pt-BR',
            'deck_back_language' => 'en-GB',
            'deck_structure' => 'traducoes',
            'generation_base_prompt_default' => '',
            'deck_percentage' => $deck_percentage,
            'book_completed_reads' => 0,
            'book_completed_reads_max' => 3,
            'book_next_review_at' => null,
            'total_cards' => $total_cards_in_directory,
            'current_index' => 0,
            'data' => $response
        ]);
        exit;
    }

    if ($deck_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do deck inválido.']));

    $deck = verifyDeckOwnership($pdo, $deck_id, $user_id, true);
    if (!$deck) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));
    }
    $unlockError = validatePhaseDeckUnlock($pdo, $deck_id, $user_id);
    if ($unlockError !== null) {
        die(json_encode(['status' => 'error', 'message' => $unlockError]));
    }

    $deck_mode = $deck['deck_mode'] ?? 'aleatorio';
    $current_index = 0;
    $book_completed_reads = 0;

    $book_next_review_at = null;
    $random_next_review_at = null;

    if (in_array($deck_mode, ['aleatorio', 'grafo'], true)) {
        $orderClause = $deck_mode === 'grafo' ? 'ORDER BY f.id ASC' : 'ORDER BY RAND()';

        if ($deck_mode === 'grafo') {
            // No modo grafo, busca cards de qualquer deck do usuário
            // e também dos decks pertencentes ao usuário de id 5.
            $stmt = $pdo->prepare("
                SELECT f.id, f.front_encrypted, f.back_encrypted, f.image_front_encrypted, f.image_back_encrypted, f.info_type, f.question_answer, f.created_at, f.has_audio_front, f.has_audio_back, COALESCE(f.created_by_user_id, d.user_id) AS card_owner_user_id, COALESCE(fs.score, 0) as score
                FROM flashcards f
                JOIN directories d ON d.id = f.directory_id
                LEFT JOIN flashcard_scores fs ON fs.flashcard_id = f.id AND fs.user_id = ?
                WHERE d.user_id IN (?, 5)
                {$orderClause}
            ");
            $stmt->execute([$user_id, $user_id]);
        } else {
            // No modo aleatório, mantém filtro por deck e cards vencidos.
            $stmt = $pdo->prepare("
                SELECT f.id, f.front_encrypted, f.back_encrypted, f.image_front_encrypted, f.image_back_encrypted, f.info_type, f.question_answer, f.created_at, f.has_audio_front, f.has_audio_back, COALESCE(f.created_by_user_id, d.user_id) AS card_owner_user_id, COALESCE(fs.score, 0) as score
                FROM flashcards f
                JOIN directories d ON d.id = f.directory_id
                LEFT JOIN flashcard_scores fs ON fs.flashcard_id = f.id AND fs.user_id = ?
                WHERE f.directory_id = ?
                  AND (fs.next_review_at IS NULL OR fs.next_review_at <= NOW())
                {$orderClause}
            ");
            $stmt->execute([$user_id, $deck_id]);
        }
        
        //Caso não tiver nenhum card disponível, verifica qual data e hora o próximo ficará disponível (vale para o Modo Aleatório)
        $stmtNextRandomReview = $pdo->prepare("
            SELECT MIN(fs.next_review_at)
            FROM flashcard_scores fs
            JOIN flashcards f ON f.id = fs.flashcard_id
            WHERE f.directory_id = ?
              AND fs.user_id = ?
              AND fs.next_review_at IS NOT NULL
              AND fs.next_review_at > NOW()
        ");
        $stmtNextRandomReview->execute([$deck_id, $user_id]);
        $random_next_review_at = $stmtNextRandomReview->fetchColumn() ?: null;
    } else {
        $stmt = $pdo->prepare("
            SELECT f.id, f.front_encrypted, f.back_encrypted, f.image_front_encrypted, f.image_back_encrypted, f.info_type, f.question_answer, f.created_at, f.has_audio_front, f.has_audio_back, COALESCE(f.created_by_user_id, d.user_id) AS card_owner_user_id, 0 as score
            FROM flashcards f
            JOIN directories d ON d.id = f.directory_id
            WHERE f.directory_id = ? 
            ORDER BY f.sort_order ASC, f.id ASC
        ");
        $stmt->execute([$deck_id]);

        $stmtProg = $pdo->prepare("SELECT current_index, completed_reads, next_review_at FROM flashcard_book_progress WHERE user_id = ? AND directory_id = ?");
        $stmtProg->execute([$user_id, $deck_id]);
        $progressData = $stmtProg->fetch();
        if ($progressData) {
            $current_index = (int)($progressData['current_index'] ?? 0);
            $book_completed_reads = min(3, (int)($progressData['completed_reads'] ?? 0));
            $book_next_review_at = $progressData['next_review_at'] ?? null;
        }
    }
    
    $cards = $stmt->fetchAll();

    if ($deck_mode === 'livro' && !empty($book_next_review_at) && strtotime($book_next_review_at) > time()) {
        $cards = [];
        $current_index = 0;
    }

    $stmtTotal = $pdo->prepare("SELECT COUNT(id) FROM flashcards WHERE directory_id = ?");
    $stmtTotal->execute([$deck_id]);
    $total_cards_in_deck = (int)$stmtTotal->fetchColumn();

    if ($deck_mode === 'livro') {
        $deck_percentage = (int)round(($book_completed_reads / 3) * 100);
    } else {
        $stmtScore = $pdo->prepare("
            SELECT SUM(score) FROM flashcard_scores fs 
            JOIN flashcards f ON fs.flashcard_id = f.id 
            WHERE f.directory_id = ? AND fs.user_id = ?
        ");
        $stmtScore->execute([$deck_id, $user_id]);
        $total_score_deck = (int)$stmtScore->fetchColumn();

        $max_possible_score = $total_cards_in_deck * 20;
        $deck_percentage = $max_possible_score > 0 ? round(($total_score_deck / $max_possible_score) * 100) : 0;
    }

    $cardIds = array_map(static fn($card) => (int)$card['id'], $cards);
    $subjectTagsByCard = [];
    $objectTagsByCard = [];
    $tipoFrasalTagsByCard = [];
    $tenseTagsByCard = [];
    $lexicalChunksTagsByCard = [];
    $relationTagsByCard = [];
    $wordsTagsByCard = [];
    $idiomaPrincipalTagsByCard = [];
    $idiomaSecundarioTagsByCard = [];

    $graphDecisionByCardId = [];
    if ($deck_mode === 'grafo') {
        $subjectTagsByCard = fetchLinkedTagsByCard($pdo, 'subjects_links', $cardIds, $user_id);
        $objectTagsByCard = fetchLinkedTagsByCard($pdo, 'objects_links', $cardIds, $user_id);
        $tipoFrasalTagsByCard = fetchLinkedTagsByCard($pdo, 'tipo_frasal_links', $cardIds, $user_id);
        $tenseTagsByCard = fetchLinkedTagsByCard($pdo, 'tense_links', $cardIds, $user_id);
        $lexicalChunksTagsByCard = fetchLinkedTagsByCard($pdo, 'lexical_chunks_links', $cardIds, $user_id);
        $relationTagsByCard = fetchLinkedTagsByCard($pdo, 'relation_links', $cardIds, $user_id);
        $wordsTagsByCard = fetchLinkedTagsByCard($pdo, 'words_links', $cardIds, $user_id);
        $idiomaPrincipalTagsByCard = fetchLinkedTagsByCardColumn($pdo, 'idiomas_links', 'tag_id', $cardIds, $user_id);
        $idiomaSecundarioTagsByCard = fetchLinkedTagsByCardColumn($pdo, 'idiomas_links', 'segundo_idioma_tag_id', $cardIds, $user_id);
        
        $graphTagIds = collectTagIdsFromTagGroups(
            $subjectTagsByCard,
            $objectTagsByCard,
            $tipoFrasalTagsByCard,
            $tenseTagsByCard,
            $lexicalChunksTagsByCard,
            $relationTagsByCard,
            $idiomaPrincipalTagsByCard,
            $idiomaSecundarioTagsByCard
        );
        $graphTagScoresByTag = fetchFlashcardTagScores($pdo, $user_id, $graphTagIds);

        $graphCards = buildGraphCardsSequence(
            $cards,
            $subjectTagsByCard,
            $objectTagsByCard,
            $lexicalChunksTagsByCard,
            isset($deck['id_tag_inicial']) && (int)$deck['id_tag_inicial'] > 0 ? (int)$deck['id_tag_inicial'] : null,
            $graphTagScoresByTag
        );
        if (!empty($graphCards)) {
            $cardsById = [];
            foreach ($cards as $cardRow) $cardsById[(int)$cardRow['id']] = $cardRow;

            $orderedCards = [];
            foreach ($graphCards as $entry) {
                $cid = (int)($entry['card_id'] ?? 0);
                if ($cid <= 0 || !isset($cardsById[$cid])) continue;
                $orderedCards[] = $cardsById[$cid];
                $graphDecisionByCardId[] = isset($entry['decision_tag']) ? (int)$entry['decision_tag'] : null;
            }
            $cards = $orderedCards;
        } else {
            $cards = [];
        }
    }

    $response = [];
    foreach ($cards as $idx => $card) {
        $response[] = [
            'id' => $card['id'],
            'front' => !empty($card['front_encrypted']) ? Security::decryptData($card['front_encrypted']) : '',
            'back' => !empty($card['back_encrypted']) ? Security::decryptData($card['back_encrypted']) : '',
            'image_front' => !empty($card['image_front_encrypted']) ? Security::decryptData($card['image_front_encrypted']) : null,
            'image_back' => !empty($card['image_back_encrypted']) ? Security::decryptData($card['image_back_encrypted']) : null,
            'info_type' => sanitizeInfoType($card['info_type'] ?? 2),
            'question_answer' => $card['question_answer'] === null ? null : (int)$card['question_answer'],
            'created_at' => $card['created_at'] ?? null,
            'has_audio_front' => (int)$card['has_audio_front'],
            'has_audio_back' => (int)$card['has_audio_back'],
            'score' => (int)$card['score'],
            'card_owner_user_id' => isset($card['card_owner_user_id']) ? (int)$card['card_owner_user_id'] : $user_id,
            'is_public_card' => (isset($card['card_owner_user_id']) && (int)$card['card_owner_user_id'] !== (int)$user_id) ? 1 : 0,
            'subject_tags' => $subjectTagsByCard[(int)$card['id']] ?? [],
            'object_tags' => $objectTagsByCard[(int)$card['id']] ?? [],
            'tipo_frasal_tags' => $tipoFrasalTagsByCard[(int)$card['id']] ?? [],
            'tense_tags' => $tenseTagsByCard[(int)$card['id']] ?? [],
            'lexical_chunks_tags' => $lexicalChunksTagsByCard[(int)$card['id']] ?? [],
            'relation_tags' => $relationTagsByCard[(int)$card['id']] ?? [],
            'words_tags' => $wordsTagsByCard[(int)$card['id']] ?? [],
            'idioma_principal_tags' => $idiomaPrincipalTagsByCard[(int)$card['id']] ?? [],
            'idioma_secundario_tags' => $idiomaSecundarioTagsByCard[(int)$card['id']] ?? [],
            'idiomas_tags' => $idiomaPrincipalTagsByCard[(int)$card['id']] ?? [],
            'graph_decision_tag_id' => ($deck_mode === 'grafo' ? ($graphDecisionByCardId[$idx] ?? null) : ($graphDecisionByCardId[(int)$card['id']] ?? null))
        ];
    }

    $stored_base_prompt = trim((string)($deck['deck_generation_base_prompt'] ?? ''));
    if ($stored_base_prompt === '') {
        $stored_base_prompt = buildDefaultBasePromptByStructure(
            Security::decryptData($deck['name_encrypted']),
            normalizeDeckStructure($deck['deck_structure'] ?? 'traducoes', 'traducoes')
        );
    }

    echo json_encode([
        'status' => 'success', 
        'deck_name' => Security::decryptData($deck['name_encrypted']),
        'deck_mode' => $deck_mode,
        'deck_front_language' => normalizeDeckLanguage($deck['deck_front_language'] ?? 'pt-BR', 'pt-BR'),
        'deck_back_language' => normalizeDeckLanguage($deck['deck_back_language'] ?? 'en-GB', 'en-GB'),
        'deck_structure' => normalizeDeckStructure($deck['deck_structure'] ?? 'traducoes', 'traducoes'),
        'id_tag_inicial' => isset($deck['id_tag_inicial']) && (int)$deck['id_tag_inicial'] > 0 ? (int)$deck['id_tag_inicial'] : null,
        'generation_base_prompt_default' => $stored_base_prompt,
        'deck_percentage' => $deck_percentage,
        'book_completed_reads' => $book_completed_reads,
        'book_completed_reads_max' => 3,
        'book_next_review_at' => $book_next_review_at,
        'random_next_review_at' => $random_next_review_at,
        'total_cards' => $total_cards_in_deck,
        'current_index' => $current_index,
        'data' => $response
    ]);
}

// ==== Streaming de áudio do card via conteúdo criptografado no banco ====
elseif ($action === 'get_audio') {
    $card_id = (int)($_GET['card_id'] ?? 0);
    $side = ($_GET['side'] ?? '') === 'back' ? 'back' : 'front';

    if ($card_id === 0) {
        http_response_code(400);
        die('Card inválido');
    }

    if (!verifyCardOwnership($pdo, $card_id, $user_id, true)) {
        http_response_code(403);
        die('Acesso negado');
    }

    $audioCol = $side === 'front' ? 'audio_front_encrypted' : 'audio_back_encrypted';
    $hasAudioCol = $side === 'front' ? 'has_audio_front' : 'has_audio_back';
    $stmt = $pdo->prepare("SELECT $audioCol AS audio_encrypted, $hasAudioCol AS has_audio FROM flashcards WHERE id = ? LIMIT 1");
    $stmt->execute([$card_id]);
    $card = $stmt->fetch();

    if (!$card || (int)$card['has_audio'] !== 1 || empty($card['audio_encrypted'])) {
        http_response_code(404);
        die('Áudio não encontrado');
    }

    $audio_decrypted = Security::decryptData($card['audio_encrypted']);
    $audio_binary = normalizeStoredAudioToBinary($audio_decrypted);

    if ($audio_binary === null || $audio_binary === '') {
        http_response_code(500);
        die('Falha ao ler áudio');
    }

    header('Content-Type: audio/mpeg');
    header('Content-Length: ' . strlen($audio_binary));
    echo $audio_binary;
    exit;
}

// ==== Exportar todos os cards para Excel/CSV ====
elseif ($action === 'get_all_cards') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    if ($deck_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do deck inválido.']));

    // Verifica posse do deck usando a sua função de segurança
    $deck = verifyDeckOwnership($pdo, $deck_id, $user_id);
    if (!$deck) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));
    }
    $unlockError = validatePhaseDeckUnlock($pdo, $deck_id, $user_id);
    if ($unlockError !== null) {
        die(json_encode(['status' => 'error', 'message' => $unlockError]));
    }

    // Busca ABSOLUTAMENTE TODOS os cards do deck
    $stmt = $pdo->prepare("
        SELECT id, front_encrypted, back_encrypted 
        FROM flashcards 
        WHERE directory_id = ? 
        ORDER BY sort_order ASC, id ASC
    ");
    $stmt->execute([$deck_id]);
    
    $cards = $stmt->fetchAll();
    $response = [];
    
    foreach ($cards as $card) {
        $response[] = [
            'id' => $card['id'],
            'front' => !empty($card['front_encrypted']) ? Security::decryptData($card['front_encrypted']) : '',
            'back' => !empty($card['back_encrypted']) ? Security::decryptData($card['back_encrypted']) : ''
        ];
    }

    echo json_encode([
        'status' => 'success',
        'data' => $response
    ]);
}

elseif ($action === 'generate_audio') {
    $card_id = (int)($input['card_id'] ?? 0);
    $side = $input['side'] ?? 'back'; // 'front' ou 'back'

    if ($card_id === 0 || !in_array($side, ['front', 'back'])) {
        die(json_encode(['status' => 'error', 'message' => 'Parâmetros inválidos.']));
    }

    $stmt = $pdo->prepare("SELECT f.front_encrypted, f.back_encrypted, d.user_id, d.deck_front_language, d.deck_back_language, d.deck_structure FROM flashcards f JOIN directories d ON f.directory_id = d.id WHERE f.id = ?");
    $stmt->execute([$card_id]);
    $card = $stmt->fetch();

    if (!$card || $card['user_id'] != $user_id) {
        die(json_encode(['status' => 'error', 'message' => 'Acesso negado.']));
    }

    $text_encrypted = $side === 'front' ? $card['front_encrypted'] : $card['back_encrypted'];
    $clean_text = trim(strip_tags(Security::decryptData($text_encrypted)));

    if (empty($clean_text)) {
        die(json_encode(['status' => 'error', 'message' => 'O lado selecionado deste card não possui texto.']));
    }

    if (cardTextContainsMathNotation($clean_text)) {
        die(json_encode(['status' => 'error', 'message' => 'Este conteúdo possui notação matemática e não pode ter áudio gerado.']));
    }

    $front_language = normalizeDeckLanguage($card['deck_front_language'] ?? 'pt-BR', 'pt-BR');
    $back_language = normalizeDeckLanguage($card['deck_back_language'] ?? 'en-GB', 'en-GB');
    $deck_structure = normalizeDeckStructure($card['deck_structure'] ?? 'traducoes', 'traducoes');
    $side_language = $side === 'front' ? $front_language : $back_language;

    $tts_error_details = null;
    $ok = generateAndPersistCardAudio($pdo, $user_id, $card_id, $side, $clean_text, $side_language, $deck_structure, $front_language, $back_language, $tts_error_details);
    if (!$ok) {
        die(json_encode([
            'status' => 'error',
            'message' => 'Erro ao comunicar com a API de voz. O serviço pode estar indisponível.',
            'details' => $tts_error_details ?: 'Sem detalhes adicionais retornados pelo provider.'
        ]));
    }

    echo json_encode(['status' => 'success', 'message' => 'Áudio gerado e salvo com sucesso!']);
}

elseif ($action === 'generate_missing_audios_from_directory') {
    $directory_id = (int)($input['directory_id'] ?? 0);
    if ($directory_id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID do diretório inválido.']));
    }

    $directory = verifyDirectoryOwnership($pdo, $directory_id, $user_id);
    if (!$directory) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));
    }

    $decks = collectDecksFromDirectoryTree($pdo, $directory_id, $user_id);

    $generated_count = 0;
    $skipped_count = 0;
    $failed_count = 0;

    $stmtCards = $pdo->prepare("SELECT id, front_encrypted, back_encrypted, has_audio_front, has_audio_back FROM flashcards WHERE directory_id = ? ORDER BY sort_order ASC, id ASC");
    foreach ($decks as $deck) {
        $deck_id = (int)$deck['id'];
        $front_language = normalizeDeckLanguage($deck['deck_front_language'] ?? 'pt-BR', 'pt-BR');
        $back_language = normalizeDeckLanguage($deck['deck_back_language'] ?? 'en-GB', 'en-GB');
        $deck_structure = normalizeDeckStructure($deck['deck_structure'] ?? 'traducoes', 'traducoes');

        $stmtCards->execute([$deck_id]);
        $cards = $stmtCards->fetchAll();

        foreach ($cards as $card) {
            $front_text = !empty($card['front_encrypted']) ? trim(strip_tags(Security::decryptData($card['front_encrypted']))) : '';
            $back_text = !empty($card['back_encrypted']) ? trim(strip_tags(Security::decryptData($card['back_encrypted']))) : '';

            $jobs = [
                [
                    'side' => 'front',
                    'has_audio' => (int)$card['has_audio_front'] === 1,
                    'text' => $front_text,
                    'language' => $front_language
                ],
                [
                    'side' => 'back',
                    'has_audio' => (int)$card['has_audio_back'] === 1,
                    'text' => $back_text,
                    'language' => $back_language
                ]
            ];

            foreach ($jobs as $job) {
                if ($job['has_audio']) {
                    $skipped_count++;
                    continue;
                }

                if ($job['text'] === '') {
                    $skipped_count++;
                    continue;
                }

                if (cardTextContainsMathNotation($job['text'])) {
                    $skipped_count++;
                    continue;
                }

                $ok = generateAndPersistCardAudio($pdo, $user_id, (int)$card['id'], $job['side'], $job['text'], $job['language'], $deck_structure, $front_language, $back_language);
                if ($ok) {
                    $generated_count++;
                } else {
                    $failed_count++;
                }
            }
        }
    }

    $baseMessage = 'Geração em fila concluída.';
    if ($generated_count === 0 && $failed_count === 0) {
        $baseMessage = 'Nenhum áudio pendente com texto encontrado neste diretório e subdiretórios.';
    } elseif ($failed_count > 0) {
        $baseMessage = 'Processo concluído com falhas em alguns cards.';
    }

    echo json_encode([
        'status' => 'success',
        'message' => $baseMessage,
        'data' => [
            'generated_count' => $generated_count,
            'skipped_count' => $skipped_count,
            'failed_count' => $failed_count
        ]
    ]);
}

elseif ($action === 'count_missing_audios_from_directory') {
    $directory_id = (int)($input['directory_id'] ?? 0);
    if ($directory_id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID do diretório inválido.']));
    }

    $directory = verifyDirectoryOwnership($pdo, $directory_id, $user_id);
    if (!$directory) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));
    }

    $decks = collectDecksFromDirectoryTree($pdo, $directory_id, $user_id);
    $total_pending = 0;

    foreach ($decks as $deck) {
        $total_pending += countPendingAudiosForDeck($pdo, (int)$deck['id']);
    }

    echo json_encode([
        'status' => 'success',
        'message' => $total_pending > 0 ? 'Áudios pendentes encontrados.' : 'Nenhum áudio pendente encontrado.',
        'data' => [
            'total_pending' => $total_pending
        ]
    ]);
}

elseif ($action === 'count_missing_audios_from_deck') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    if ($deck_id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID do deck inválido.']));
    }

    $deck = verifyDeckOwnership($pdo, $deck_id, $user_id);
    if (!$deck) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));
    }

    $unlockError = validatePhaseDeckUnlock($pdo, $deck_id, $user_id);
    if ($unlockError !== null) {
        die(json_encode(['status' => 'error', 'message' => $unlockError]));
    }

    $total_pending = countPendingAudiosForDeck($pdo, $deck_id);

    echo json_encode([
        'status' => 'success',
        'message' => $total_pending > 0 ? 'Áudios pendentes encontrados.' : 'Nenhum áudio pendente encontrado.',
        'data' => [
            'total_pending' => $total_pending
        ]
    ]);
}

elseif ($action === 'generate_next_missing_audio_from_directory') {
    $directory_id = (int)($input['directory_id'] ?? 0);
    if ($directory_id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID do diretório inválido.']));
    }

    $directory = verifyDirectoryOwnership($pdo, $directory_id, $user_id);
    if (!$directory) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));
    }

    $decks = collectDecksFromDirectoryTree($pdo, $directory_id, $user_id);
    $next_job = null;

    foreach ($decks as $deck) {
        $front_language = normalizeDeckLanguage($deck['deck_front_language'] ?? 'pt-BR', 'pt-BR');
        $back_language = normalizeDeckLanguage($deck['deck_back_language'] ?? 'en-GB', 'en-GB');
        $deck_structure = normalizeDeckStructure($deck['deck_structure'] ?? 'traducoes', 'traducoes');
        $next_job = findNextPendingAudioJobForDeck($pdo, (int)$deck['id'], $front_language, $back_language);
        if ($next_job) {
            $next_job['deck_structure'] = $deck_structure;
            $next_job['front_language'] = $front_language;
            $next_job['back_language'] = $back_language;
            break;
        }
    }

    if (!$next_job) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Nenhum áudio pendente restante.',
            'data' => [
                'done' => true,
                'generated_count' => 0,
                'failed_count' => 0,
                'remaining_pending' => 0
            ]
        ]);
        exit;
    }

    $ok = generateAndPersistCardAudio(
        $pdo,
        $user_id,
        $next_job['card_id'],
        $next_job['side'],
        $next_job['text'],
        $next_job['language'],
        $next_job['deck_structure'] ?? 'traducoes',
        $next_job['front_language'] ?? 'pt-BR',
        $next_job['back_language'] ?? 'en-GB'
    );
    $remaining_pending = 0;
    foreach ($decks as $deck) {
        $remaining_pending += countPendingAudiosForDeck($pdo, (int)$deck['id']);
    }

    echo json_encode([
        'status' => 'success',
        'message' => $ok ? 'Áudio gerado com sucesso.' : 'Falha ao gerar áudio do card atual.',
        'data' => [
            'done' => $remaining_pending === 0,
            'generated_count' => $ok ? 1 : 0,
            'failed_count' => $ok ? 0 : 1,
            'remaining_pending' => $remaining_pending,
            'card_id' => $next_job['card_id'],
            'side' => $next_job['side']
        ]
    ]);
}

elseif ($action === 'generate_next_missing_audio_from_deck') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    if ($deck_id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID do deck inválido.']));
    }

    $deck = verifyDeckOwnership($pdo, $deck_id, $user_id);
    if (!$deck) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));
    }

    $unlockError = validatePhaseDeckUnlock($pdo, $deck_id, $user_id);
    if ($unlockError !== null) {
        die(json_encode(['status' => 'error', 'message' => $unlockError]));
    }

    $front_language = normalizeDeckLanguage($deck['deck_front_language'] ?? 'pt-BR', 'pt-BR');
    $back_language = normalizeDeckLanguage($deck['deck_back_language'] ?? 'en-GB', 'en-GB');
    $deck_structure = normalizeDeckStructure($deck['deck_structure'] ?? 'traducoes', 'traducoes');

    $next_job = findNextPendingAudioJobForDeck($pdo, $deck_id, $front_language, $back_language);
    if (!$next_job) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Nenhum áudio pendente restante.',
            'data' => [
                'done' => true,
                'generated_count' => 0,
                'failed_count' => 0,
                'remaining_pending' => 0
            ]
        ]);
        exit;
    }

    $ok = generateAndPersistCardAudio(
        $pdo,
        $user_id,
        $next_job['card_id'],
        $next_job['side'],
        $next_job['text'],
        $next_job['language'],
        $deck_structure,
        $front_language,
        $back_language
    );

    $remaining_pending = countPendingAudiosForDeck($pdo, $deck_id);

    echo json_encode([
        'status' => 'success',
        'message' => $ok ? 'Áudio gerado com sucesso.' : 'Falha ao gerar áudio do card atual.',
        'data' => [
            'done' => $remaining_pending === 0,
            'generated_count' => $ok ? 1 : 0,
            'failed_count' => $ok ? 0 : 1,
            'remaining_pending' => $remaining_pending,
            'card_id' => $next_job['card_id'],
            'side' => $next_job['side']
        ]
    ]);
}

elseif ($action === 'translate_text') {
    $text = trim($input['text'] ?? '');
    $source_language = normalizeDeckLanguage($input['source_language'] ?? 'pt-BR', 'pt-BR');
    $target_language = normalizeDeckLanguage($input['target_language'] ?? 'en-GB', 'en-GB');

    if ($text === '') {
        die(json_encode(['status' => 'error', 'message' => 'Texto inválido para tradução.']));
    }

    if ($source_language === $target_language) {
        echo json_encode(['status' => 'success', 'translation' => $text]);
        exit;
    }

    if (OPENAI_API_KEY === '') {
        die(json_encode(['status' => 'error', 'message' => 'OPENAI_API_KEY não configurada no .env.']));
    }

    $systemPrompt = sprintf(
        'Você é um tradutor automático direto e focado. Traduza de %s para %s e retorne EXCLUSIVAMENTE a tradução.',
        getLanguageLabel($source_language),
        getLanguageLabel($target_language)
    );

    $payload = json_encode([
        'model' => 'gpt-5.4',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $text]
        ],
        'temperature' => 0.3
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_API_KEY
    ]);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode !== 200 || !$response) {
        die(json_encode(['status' => 'error', 'message' => 'Erro ao traduzir com a OpenAI.']));
    }

    $decoded = json_decode($response, true);
    $translation = trim($decoded['choices'][0]['message']['content'] ?? '');

    if ($translation === '') {
        die(json_encode(['status' => 'error', 'message' => 'A API não retornou tradução válida.']));
    }

    echo json_encode(['status' => 'success', 'translation' => $translation]);
}
elseif ($action === 'translate_text_gemini') {
    $text = trim($input['text'] ?? '');
    $auto_detect_opposite = !empty($input['auto_detect_opposite']);
    $source_language = normalizeDeckLanguage($input['source_language'] ?? 'pt-BR', 'pt-BR');
    $target_language = normalizeDeckLanguage($input['target_language'] ?? 'en-GB', 'en-GB');

    if ($text === '') {
        die(json_encode(['status' => 'error', 'message' => 'Texto inválido para tradução.']));
    }

    if (!$auto_detect_opposite && $source_language === $target_language) {
        echo json_encode(['status' => 'success', 'translation' => $text]);
        exit;
    }

    if (GEMINI_API_KEY === '') {
        die(json_encode(['status' => 'error', 'message' => 'GEMINI_API_KEY não configurada no .env.']));
    }

    if ($auto_detect_opposite) {
        $prompt = sprintf(
            "Identifique se o texto está principalmente em português brasileiro ou em inglês. Se estiver em português brasileiro, traduza para inglês natural. Se estiver em inglês, traduza para português brasileiro natural. Retorne exclusivamente o texto traduzido, sem comentários, sem markdown, sem rótulos de idioma e sem aspas extras.

Texto:
%s",
            $text
        );
    } else {
        $prompt = sprintf(
            "Traduza de %s para %s. Retorne exclusivamente a tradução, sem comentários, sem markdown e sem aspas extras.

Texto:
%s",
            getLanguageLabel($source_language),
            getLanguageLabel($target_language),
            $text
        );
    }

    $payload = [
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.2
        ]
    ];

    [$httpcode, $response, $curlError] = geminiJsonRequest(GEMINI_TRANSLATION_MODEL, $payload);

    if ($httpcode !== 200 || !$response) {
        $decoded_error = $response ? json_decode($response, true) : null;
        $api_error = is_array($decoded_error) ? trim((string)($decoded_error['error']['message'] ?? '')) : '';
        $details = $api_error !== '' ? $api_error : trim((string)$curlError);
        die(json_encode(['status' => 'error', 'message' => 'Erro ao traduzir com o Gemini.' . ($details !== '' ? (' Detalhes: ' . $details) : '')]));
    }

    $decoded = json_decode($response, true);
    $translation = extractGeminiText($decoded);

    if ($translation === '') {
        $finish_reason = trim((string)($decoded['candidates'][0]['finishReason'] ?? ''));
        die(json_encode(['status' => 'error', 'message' => 'A API do Gemini não retornou tradução válida.' . ($finish_reason !== '' ? (' Motivo: ' . $finish_reason) : '')]));
    }

    echo json_encode(['status' => 'success', 'translation' => $translation]);
}


elseif ($action === 'generate_sentence_lexical_chunks_gemini') {
    $tag_id = (int)($input['tag_id'] ?? 0);
    $tag_text_en = trim((string)($input['tag_text_en'] ?? ''));
    $tag_text_pt_br = trim((string)($input['tag_text_pt_br'] ?? ''));
    $requested_count = (int)($input['count'] ?? 1);
    $example_count = max(1, min(10, $requested_count));
    $create_cards = !empty($input['create_cards']);
    $deck_id = (int)($input['deck_id'] ?? 0);
    $info_type = sanitizeInfoType($input['info_type'] ?? 2);
    $selected_tag_usage_role = trim((string)($input['selected_tag_usage_role'] ?? 'semantic_block'));
    if (!in_array($selected_tag_usage_role, ['semantic_block', 'subject', 'object'], true)) {
        $selected_tag_usage_role = 'semantic_block';
    }

    if ($tag_id <= 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID da tag inválido para gerar frase e lexical chunks.']));
    }

    if ($tag_text_en === '' || $tag_text_pt_br === '') {
        die(json_encode(['status' => 'error', 'message' => 'Texto da tag inválido para gerar frase e lexical chunks.']));
    }

    $stmtTag = $pdo->prepare("SELECT id, name_encrypted, name_pt_br_encrypted FROM flashcard_tags WHERE id = ? AND user_id IN (?, 5) LIMIT 1");
    $stmtTag->execute([$tag_id, $user_id]);
    $selectedTagRow = $stmtTag->fetch(PDO::FETCH_ASSOC);
    if (!$selectedTagRow) {
        die(json_encode(['status' => 'error', 'message' => 'Tag não encontrada ou sem permissão.']));
    }

    $stored_tag_text_en = !empty($selectedTagRow['name_encrypted']) ? trim((string)Security::decryptData((string)$selectedTagRow['name_encrypted'])) : '';
    $stored_tag_text_pt_br = !empty($selectedTagRow['name_pt_br_encrypted']) ? trim((string)Security::decryptData((string)$selectedTagRow['name_pt_br_encrypted'])) : '';
    if ($stored_tag_text_en !== '') $tag_text_en = $stored_tag_text_en;
    if ($stored_tag_text_pt_br !== '') $tag_text_pt_br = $stored_tag_text_pt_br;

    if ($tag_text_en === '' || $tag_text_pt_br === '') {
        die(json_encode(['status' => 'error', 'message' => 'A tag selecionada precisa ter texto em inglês e em pt-BR.']));
    }

    if ($create_cards) {
        if ($deck_id <= 0) {
            die(json_encode(['status' => 'error', 'message' => 'Deck inválido para criar as frases de exemplo.']));
        }
        if (!verifyDeckOwnership($pdo, $deck_id, $user_id)) {
            die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado para criar as frases de exemplo.']));
        }
    }

    if (GEMINI_API_KEY === '') {
        die(json_encode(['status' => 'error', 'message' => 'GEMINI_API_KEY não configurada no .env.']));
    }

    $existing_sentences = fetchUserFiveSubjectCardSentences($pdo, findEquivalentUserFiveTagIds($pdo, $tag_id, $tag_text_en, $tag_text_pt_br));
    $existing_sentences_block = '';
    if (!empty($existing_sentences)) {
        $existing_sentences_block = "\n\nFrases em inglês que já existem para essa tag nos cards do usuário 5 (não gere nenhuma igual nem quase idêntica):\n";
        foreach ($existing_sentences as $index => $sentence) {
            $existing_sentences_block .= ($index + 1) . '. ' . $sentence . "\n";
        }
    }

    $current_date_for_prompt = gmdate('F j, Y');
    $selected_tag_role_instruction = '';
    if ($selected_tag_usage_role === 'subject') {
        $selected_tag_role_instruction = "

Regra obrigatória de papel da tag selecionada: em todas as frases, use a tag solicitada como o sujeito gramatical principal real. No texto completo em inglês, você pode colocar um artigo definido ou indefinido imediatamente antes do sujeito quando isso soar natural (ex.: \"the\", \"a\", \"an\"), mas esse artigo não deve fazer parte do campo subject. O campo subject deve ser exatamente essa tag sem artigo inicial, selected_tag_role deve ser \"subject\", e a tag não deve ser tratada como object nem como chunk. Quando a tag estiver como sujeito, dê preferência máxima a fatos históricos, curiosidades verdadeiras e/ou fatos super óbvios de senso ultra-comum diretamente sobre esse sujeito.";
    } elseif ($selected_tag_usage_role === 'object') {
        $selected_tag_role_instruction = "

Regra obrigatória de papel da tag selecionada: em todas as frases, use a tag solicitada como objeto ou substantivo relevante que não seja o sujeito. Inclua essa tag em objects, selected_tag_role deve ser \"object\", e o sujeito gramatical principal deve ser outra coisa.";
    } else {
        $selected_tag_role_instruction = sprintf("

Regra obrigatória de papel da tag selecionada: em todas as frases, use a tag solicitada como o bloco semântico central que conduz o sentido da frase inteira, não como uma palavra incidental. A frase deve depender semanticamente desse bloco: se a tag for removida, o sentido principal deve mudar. Classifique a tag solicitada conforme sua função real: use selected_tag_role=\"chunk\" somente se ela for verbo isolado, locução verbal, locução conjuntiva, locução prepositiva, acúmulo/combinação de preposições, preposição isolada ou conjunção isolada; se ela for substantivo ou pronome, nunca a coloque em chunks e classifique-a como subject ou object. Se a tradução em pt-BR precisar ser flexionada/conjugada naturalmente na frase (ex.: \"lidar\" vira \"lidam\"), mantenha pt_br=\"%s\" exatamente no campo estruturado correspondente e use a forma natural na tradução completa da frase.", $tag_text_pt_br);
    }

    $prompt = sprintf(<<<'PROMPT'
Gere %d frases naturais em inglês para estudante de inglês. Cada frase deve conter exatamente a tag em inglês "%s" e essa ocorrência deve ser traduzida exatamente como "%s" na frase em pt-BR. Gere também a tradução exata de cada frase em pt-BR.%s%s

Data atual de referência: %s.

A tradução "%s" define o sentido obrigatório da tag "%s". Ignore outros sentidos possíveis da mesma palavra em inglês: se a mesma palavra puder ter várias traduções, use somente o sentido indicado por "%s".

Para cada frase, identifique o sujeito gramatical real, grupos nominais relevantes da frase em objects (exceto o sujeito) e poucos lexical chunks sucintos. Também classifique onde a tag solicitada foi usada em selected_tag_role: "subject", "object" ou "chunk".

Retorne exclusivamente JSON válido neste formato:
{"examples":[{"english":"frase em inglês","pt_br":"tradução exata em pt-BR","selected_tag_role":"subject|object|chunk","subject":{"en":"texto por extenso em inglês","pt_br":"texto por extenso em pt-BR","numero":"valor numérico opcional","sigla_simbolo":"sigla ou símbolo opcional","kind":"common_noun|proper_noun|pronoun|verb|expression|other"},"objects":[{"en":"grupo nominal da frase que não é sujeito","pt_br":"texto por extenso em pt-BR","numero":"valor numérico opcional","sigla_simbolo":"sigla ou símbolo opcional","kind":"common_noun|proper_noun|number|other"}],"chunks":[{"en":"lexical chunk curto e útil ou texto por extenso em inglês","pt_br":"tradução ou texto por extenso em pt-BR","numero":"valor numérico opcional","sigla_simbolo":"sigla ou símbolo opcional","kind":"verb|verb_phrase|phrasal_verb|prepositional_phrase|expression|other"}]}]}

Regras:
- Retorne exatamente %d item(ns) em examples.
- Cada frase deve ser diferente das outras e diferente das frases existentes listadas.
- Dê preferência forte a frases de curiosidades, atuais, históricas, geografia, ciência, cultura, esportes, instituições ou fatos cotidianos verdadeiros.
- Não invente eventos, estatísticas, cargos, datas, relações familiares, obras, descobertas, recordes, falas ou conquistas. Se não tiver certeza de um detalhe factual, troque por um fato estável, amplamente conhecido e mais fácil de verificar.
- Para acontecimentos atuais, use a data de referência informada acima: prefira fatos ainda válidos nessa data ou fatos recentes amplamente conhecidos; evite notícias de última hora, previsões e alegações instáveis.
- Quando a tag dificultar uma frase factual, ainda gere uma frase natural, mas mantenha cenário, sujeito, objetos e contexto plausíveis, sem transformar pessoas públicas ou fatos históricos em ficção.
- Cada frase deve conter a tag solicitada "%s" em inglês e a tradução deve usar o sentido de "%s" para esse trecho. Se a frase em pt-BR exigir flexão natural, a frase completa pode usar a forma flexionada, mas o campo estruturado correspondente (subject, objects ou chunks) deve manter pt_br exatamente como "%s".
- Na tradução completa em pt-BR, escreva uma frase natural e completa. As preposições e conjunções devem seguir a própria frase em pt-BR: mantenha explícito o que ficar explícito na tradução natural e deixe oculto apenas o que ficar naturalmente oculto em português; não copie omissões do inglês nem force conectores que não aparecem na frase em pt-BR.
- Todas as frases em inglês e em pt-BR devem terminar com pontuação final: use interrogação quando a frase for interrogativa e ponto final nos demais casos. Não termine frases com exclamação ou sem pontuação.
- Para cada frase, preencha subject com o sujeito gramatical principal real da frase, sem artigos iniciais. Exemplo: em "The dog barked at the cat on the avenue", subject é "dog", não "the dog".
- A regra obrigatória de papel da tag selecionada informada antes da data prevalece sobre as regras gerais de classificação abaixo.
- Nunca coloque a tag solicitada em subject só porque ela foi a tag inicial escolhida. Se a tag solicitada for verbo isolado, locução verbal, locução conjuntiva, locução prepositiva, acúmulo/combinação de preposições, preposição isolada ou conjunção isolada, ela deve ir para chunks e selected_tag_role deve ser "chunk"; se ela for substantivo ou pronome, nunca a coloque em chunks: use subject somente quando for o sujeito gramatical real, caso contrário use object.
- Para cada frase, preencha objects com 1 a 4 grupos nominais relevantes que aparecem na frase e não são o sujeito: objetos diretos, substantivos essenciais de complementos ou substantivos de expressões preposicionais importantes. Objects nunca deve ficar vazio. Sem verbos, sem sujeito e sem artigos iniciais. NÃO reduza grupos nominais ao substantivo isolado: preserve adjetivos, advérbios modificadores, numerais e substantivos compostos que aparecem junto do núcleo. Exemplo: em "obsolete information", gere object en="obsolete information" e pt_br="informação obsoleta", não apenas "information". Em "highly obsolete information", preserve "highly obsolete information". Em "The dog chased the cat on the avenue", objects inclui "cat" e pode incluir "avenue", não "chased" nem "dog". Em "The dog barked at the cat on the avenue", objects inclui "cat" e/ou "avenue" porque são substantivos da frase que não são o sujeito.
- Em chunks, gere somente verbos isolados úteis, locuções verbais, locuções conjuntivas, locuções prepositivas, acúmulos/combinações de preposições, preposições isoladas ou conjunções isoladas.
- Em chunks, prefira trechos curtos como "chased", "barked", "at", "in", "because of ...", "whether ... or ...". Todo verbo principal que antes iria para objects deve ir para chunks. Não inclua sujeitos, pronomes nem substantivos como lexical chunks.
- Em cada chunk, a tag en deve acompanhar somente os conectores que estão explícitos na frase em inglês, e a tag pt_br deve acompanhar somente os conectores que estão explícitos na frase em pt-BR. Se "that" estiver explícito no inglês, mantenha "that" na tag en; se "that" estiver oculto no inglês, não invente "that" na tag en. Se "que" estiver explícito no pt-BR, mantenha "que" na tag pt_br; se "que" estiver oculto no pt-BR, não invente "que" na tag pt_br. A mesma regra vale para preposições: cada idioma segue a explicitude/ocultação da sua própria frase, sem copiar a omissão ou a explicitude do outro idioma. Exemplo obrigatório: em "cards that carry obsolete information", o chunk correto é en="that carry" ou en="... that carry" (conforme o escopo), nunca en="... carry"; em pt-BR, use "que carregam" se o "que" aparecer na frase pt-BR.
- Cada frase deve ter de 2 a 4 chunks no máximo. Gere menos chunks, desde que sejam os mais úteis e naturais da frase.
- Quando uma locução candidata tiver substantivo ou pronome, substitua essa parte por reticências para manter apenas a estrutura funcional: por exemplo, "how you'll handle" deve virar "how ... will handle", "on the avenue" deve virar "on ..." e "because of the rain" deve virar "because of ...".
- Inclua a tag "%s" em chunks somente quando ela obedecer estritamente às categorias permitidas de chunks. Nunca inclua a tag em chunks se ela for substantivo ou pronome.
- Não repita o mesmo chunk com o mesmo par de texto em inglês e tradução pt-BR dentro dos chunks da mesma frase nem gere variações quase iguais.
- No texto das frases em inglês, prefira frases ordinárias, cotidianas e naturais; não adicione auxiliares, modais, negativas ou estruturas com have/had/not/has/is/am/are/would/will/shall apenas para criar contrações.
- Quando uma contração for realmente natural e necessária para a frase escolhida, use a forma contraída: have > 've, had > 'd, not > n't, has > 's, is > 's, am > 'm, are > 're, would > 'd, will > 'll, shall > 'll.
- Repare na regra acima, se couber contração na frase, é muito importante que contráia, se não couber, não precisa contrair, mas se for natural para um americano falar aquela frase contraindo, então a faça contraída.
- Nos textos de tags de subject, objects e chunks, faça o oposto: use forma descontraída/expandida (have, had, not, has, is, am, are, would, will, shall) e não use as contrações 've, 'd, n't, 's, 'm, 're, 'll.
- Faça cada frase em um tempo verbal, explore versões negativas, afirmativas fala em um diálogo comum, afirmativa estilo notícia de jornal, interrogativas, condicionais.
- Repare na regra acima, frases com tempos verbais diferentes entre elas.
- Não coloque vírgula, ponto final ou outra pontuação de frase dentro dos textos das tags.
- Não use artigos iniciais nas tags de subject e nas tags de objetos diretos em objects: use "dog", "cat", não "the dog", "the cat".
- A única exceção para pontos em tags é quando o próprio lexical chunk for um padrão com reticências, como "Whether ... or ..." ("Seja ... ou ..."). Use reticências apenas nos chunks, nunca em subject ou objects.
- Use traduções curtas para as tags e mantenha a ordem natural dos chunks. Não omita no pt_br preposições/conjunções que estejam explícitas na frase pt-BR correspondente, mas também não force no pt_br conectores que ficaram naturalmente ocultos nessa frase.
- Não gere frases com anos futuros aleatórios ou distantes (ex.: 2034, 2040). Só use ano futuro se o próprio lexical chunk solicitado for exatamente esse ano; caso contrário, prefira anos realistas até o ano atual.
- Para datas completas em chunks, não inclua o ano nem o número literal do dia dentro do chunk: em vez de "on June 8th 2026", use "on June ...th". O ano e o dia devem aparecer como tags numéricas isoladas.
- Quando a tag for um número, valor, medida ou quantidade (ex.: "2010", "R$42,40", "U$10,15", "1,60m", "49kg", "1.000.000.000"), coloque o valor literal em numero e coloque os textos por extenso em en e pt_br.
- Para tags numéricas, nunca mantenha preposições ou contexto junto do texto da tag: em uma frase como "I went to the zoo in 2014", a tag do número deve ser en="two thousand and fourteen", pt_br="dois mil e catorze", numero="2014", não en="in 2014" nem pt_br="em 2014".
- Se qualquer frase gerada tiver ano, número, valor, medida ou quantidade, crie obrigatoriamente uma tag isolada para cada valor numérico literal encontrado, mesmo que o número também apareça dentro de um chunk maior como "in 2014".
- Quando existir sigla ou símbolo conhecido (ex.: "°C", "π", "TCU"), coloque-o em sigla_simbolo. Caso não exista, use null ou omita.
- Não use markdown, comentários ou texto fora do JSON.
PROMPT, $example_count, $tag_text_en, $tag_text_pt_br, $existing_sentences_block, $selected_tag_role_instruction, $current_date_for_prompt, $tag_text_pt_br, $tag_text_en, $tag_text_pt_br, $example_count, $tag_text_en, $tag_text_pt_br, $tag_text_pt_br, $tag_text_en);

    $payload = [
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.35,
            'responseMimeType' => 'application/json'
        ]
    ];

    [$httpcode, $response, $curlError] = geminiJsonRequest(GEMINI_TRANSLATION_MODEL, $payload);

    if ($httpcode !== 200 || !$response) {
        $decoded_error = $response ? json_decode($response, true) : null;
        $api_error = is_array($decoded_error) ? trim((string)($decoded_error['error']['message'] ?? '')) : '';
        $details = $api_error !== '' ? $api_error : trim((string)$curlError);
        die(json_encode([
            'status' => 'error',
            'message' => 'Erro ao gerar lexical chunks com o Gemini.' . ($details !== '' ? (' Detalhes: ' . $details) : ''),
            'gemini_debug' => [
                'http_code' => $httpcode,
                'curl_error' => $curlError,
                'raw_response' => $response,
                'parsed_json' => $decoded_error,
                'extracted_text' => ''
            ]
        ]));
    }

    $decoded = json_decode($response, true);
    $chunk_json = extractGeminiText($decoded);
    $chunk_data = json_decode($chunk_json, true);

    if (!is_array($chunk_data)) {
        $json_start = strpos($chunk_json, '{');
        $json_end = strrpos($chunk_json, '}');
        if ($json_start !== false && $json_end !== false && $json_end > $json_start) {
            $chunk_data = json_decode(substr($chunk_json, $json_start, $json_end - $json_start + 1), true);
        }
    }

    $examples_raw = [];
    if (is_array($chunk_data) && isset($chunk_data['examples']) && is_array($chunk_data['examples'])) {
        $examples_raw = $chunk_data['examples'];
    } elseif (is_array($chunk_data) && isset($chunk_data['english'], $chunk_data['pt_br'], $chunk_data['chunks'])) {
        $examples_raw = [$chunk_data];
    }

    $gemini_debug = [
        'http_code' => $httpcode,
        'finish_reason' => trim((string)($decoded['candidates'][0]['finishReason'] ?? '')),
        'raw_response' => $response,
        'extracted_text' => $chunk_json,
        'parsed_json' => $chunk_data,
        'examples_returned_count' => count($examples_raw)
    ];

    if (empty($examples_raw)) {
        $finish_reason = trim((string)($decoded['candidates'][0]['finishReason'] ?? ''));
        die(json_encode([
            'status' => 'error',
            'message' => 'A API do Gemini não retornou frases e lexical chunks válidos.' . ($finish_reason !== '' ? (' Motivo: ' . $finish_reason) : ''),
            'gemini_debug' => $gemini_debug
        ]));
    }

    $examples = [];
    $seen_sentences = [];
    $allowedFutureYears = extractQuantifiedNumberLiteralsFromText($tag_text_en . ' ' . $tag_text_pt_br);
    foreach ($examples_raw as $example_raw) {
        if (!is_array($example_raw)) continue;
        $english = ensureSentenceEndsWithTerminalPunctuation((string)($example_raw['english'] ?? ''));
        $pt_br = ensureSentenceEndsWithTerminalPunctuation((string)($example_raw['pt_br'] ?? ''));
        $chunks_raw = isset($example_raw['chunks']) && is_array($example_raw['chunks']) ? $example_raw['chunks'] : [];
        $subject_raw = isset($example_raw['subject']) && is_array($example_raw['subject']) ? $example_raw['subject'] : [];
        $objects_raw = isset($example_raw['objects']) && is_array($example_raw['objects']) ? $example_raw['objects'] : [];
        $selected_tag_role = determineSelectedGeneratedTagRole($example_raw, $subject_raw, $objects_raw, $chunks_raw, $tag_text_en, $tag_text_pt_br);
        if ($english === '' || $pt_br === '' || empty($subject_raw) || empty($objects_raw)) continue;
        if ($selected_tag_usage_role !== 'semantic_block' && ($selected_tag_role === '' || empty($chunks_raw))) continue;
        if ($selected_tag_usage_role === 'subject' && $selected_tag_role !== 'subject') continue;
        if ($selected_tag_usage_role === 'object' && $selected_tag_role !== 'object') continue;
        // No modo semantic_block, Gemini pode declarar a tag como object/subject/chunk.
        // Não rejeite cedo, mas preserve a regra estrita: substantivos e pronomes
        // nunca são transformados em lexical chunks.
        $selectedTagAppearsInStructuredTags = generatedTagListContainsSelectedTranslation($subject_raw, $objects_raw, $chunks_raw, $tag_text_en, $tag_text_pt_br);
        if (!textContainsExactNormalizedPhrase($english, $tag_text_en)) continue;
        if (!textContainsExactNormalizedPhrase($pt_br, $tag_text_pt_br) && !$selectedTagAppearsInStructuredTags) continue;
        if (containsUnrealisticFutureYear($english . ' ' . $pt_br, $allowedFutureYears)) continue;

        $normalized_english = normalizeSentenceForDuplicateCheck($english);
        if ($normalized_english === '' || isset($seen_sentences[$normalized_english])) continue;

        foreach ($existing_sentences as $existing_sentence) {
            if ($normalized_english === normalizeSentenceForDuplicateCheck($existing_sentence)) {
                continue 2;
            }
        }

        try {
            $subjectMetadata = normalizeGeneratedTagMetadata(
                (string)($subject_raw['en'] ?? $subject_raw['english'] ?? $subject_raw['name'] ?? ''),
                (string)($subject_raw['pt_br'] ?? $subject_raw['ptBr'] ?? $subject_raw['translation'] ?? $subject_raw['name_pt_br'] ?? ''),
                $subject_raw['numero'] ?? $subject_raw['number'] ?? null,
                $subject_raw['sigla_simbolo'] ?? $subject_raw['symbol'] ?? $subject_raw['abbreviation'] ?? null
            );
            $subjectMatchesSelectedTag = generatedTagTextMatchesSelected([
                'en' => (string)$subjectMetadata['name'],
                'pt_br' => (string)$subjectMetadata['name_pt_br'],
            ], $tag_text_en, $tag_text_pt_br);
            if ($selected_tag_usage_role !== 'semantic_block' && $selected_tag_role !== 'subject' && $subjectMatchesSelectedTag) {
                continue;
            }
            if ($selected_tag_usage_role === 'subject' && !$subjectMatchesSelectedTag) {
                continue;
            }
            $subject = findOrCreateWordTagForOwner($pdo, 5, (string)$subjectMetadata['name'], (string)$subjectMetadata['name_pt_br'], $subjectMetadata['numero'], $subjectMetadata['sigla_simbolo']);
        } catch (Throwable $e) {
            continue;
        }

        $objects = [];
        $seen_objects = [];
        $selectedObjectFound = false;
        foreach ($objects_raw as $object_raw) {
            if (!is_array($object_raw)) continue;
            if (generatedTagLooksVerbLike($object_raw)) {
                if (generatedTagTextMatchesSelected($object_raw, $tag_text_en, $tag_text_pt_br)) {
                    $selected_tag_role = 'chunk';
                }
                array_unshift($chunks_raw, $object_raw);
                continue;
            }
            $objectMetadata = normalizeGeneratedTagMetadata(
                (string)($object_raw['en'] ?? $object_raw['english'] ?? $object_raw['name'] ?? ''),
                (string)($object_raw['pt_br'] ?? $object_raw['ptBr'] ?? $object_raw['translation'] ?? $object_raw['name_pt_br'] ?? ''),
                $object_raw['numero'] ?? $object_raw['number'] ?? null,
                $object_raw['sigla_simbolo'] ?? $object_raw['symbol'] ?? $object_raw['abbreviation'] ?? null
            );
            $object_en = (string)$objectMetadata['name'];
            $object_pt_br = (string)$objectMetadata['name_pt_br'];
            if (normalizeWordTagLookupValue($object_en) === normalizeWordTagLookupValue((string)$subjectMetadata['name'])) continue;
            $objectMatchesSelectedTag = generatedTagTextMatchesSelected([
                'en' => $object_en,
                'pt_br' => $object_pt_br,
            ], $tag_text_en, $tag_text_pt_br);
            if ($objectMatchesSelectedTag && $selected_tag_usage_role === 'semantic_block') {
                $selected_tag_role = 'object';
            }
            if ($objectMatchesSelectedTag) {
                $selectedObjectFound = true;
            }
            $object_key = normalizeWordTagLookupValue($object_en) . '|' . normalizeLexicalChunkLookupValue($object_pt_br) . '|' . normalizeNullableTagMetadataText($objectMetadata['numero']) . '|' . normalizeNullableTagMetadataText($objectMetadata['sigla_simbolo']);
            if ($object_key === '|' || isset($seen_objects[$object_key])) continue;
            $seen_objects[$object_key] = true;
            try {
                $objects[] = findOrCreateWordTagForOwner($pdo, 5, $object_en, $object_pt_br, $objectMetadata['numero'], $objectMetadata['sigla_simbolo']);
            } catch (Throwable $e) {
                continue;
            }
        }

        if (empty($objects)) continue;
        if ($selected_tag_usage_role === 'object' && !$selectedObjectFound) continue;
        // No modo semantic_block, a tag selecionada pode ser subject/object/chunk,
        // mas chunks continuam restritos: nunca promovemos substantivos/pronomes para chunks.

        foreach (buildNumberTagCandidatesFromText($english) as $numberCandidate) {
            $numberMetadata = normalizeGeneratedTagMetadata(
                (string)$numberCandidate['en'],
                (string)$numberCandidate['pt_br'],
                $numberCandidate['numero'] ?? null,
                $numberCandidate['sigla_simbolo'] ?? null
            );
            $number_en = (string)$numberMetadata['name'];
            $number_pt_br = (string)$numberMetadata['name_pt_br'];
            $number_key = normalizeWordTagLookupValue($number_en) . '|' . normalizeLexicalChunkLookupValue($number_pt_br) . '|' . normalizeNullableTagMetadataText($numberMetadata['numero']) . '|' . normalizeNullableTagMetadataText($numberMetadata['sigla_simbolo']);
            if ($number_key === '|' || isset($seen_objects[$number_key])) continue;
            $seen_objects[$number_key] = true;
            try {
                $objects[] = findOrCreateWordTagForOwner($pdo, 5, $number_en, $number_pt_br, $numberMetadata['numero'], $numberMetadata['sigla_simbolo']);
            } catch (Throwable $e) {
                continue;
            }
        }

        $chunks = [];
        $seen_chunks = [];
        $selectedChunkFound = false;
        foreach ($chunks_raw as $chunk) {
            if (!is_array($chunk)) continue;
            [$rawChunkEn, $rawChunkPtBr] = normalizeDateLexicalChunkTexts(
                expandEnglishContractionsForLexicalChunkTag((string)($chunk['en'] ?? $chunk['english'] ?? $chunk['name'] ?? '')),
                (string)($chunk['pt_br'] ?? $chunk['ptBr'] ?? $chunk['translation'] ?? $chunk['name_pt_br'] ?? '')
            );
            $chunkMetadata = normalizeGeneratedTagMetadata(
                $rawChunkEn,
                $rawChunkPtBr,
                $chunk['numero'] ?? $chunk['number'] ?? null,
                $chunk['sigla_simbolo'] ?? $chunk['symbol'] ?? $chunk['abbreviation'] ?? null
            );
            $chunk_kind = normalizeEnglishLexicalChunkKind($chunk['kind'] ?? $chunk['type'] ?? '');
            $chunk_en = sanitizeStrictEnglishLexicalChunkText((string)$chunkMetadata['name'], $chunk_kind);
            $chunk_pt_br = cleanLexicalChunkTagText((string)$chunkMetadata['name_pt_br']);
            if ($chunk_en === '' || $chunk_pt_br === '' || !isStrictAllowedEnglishLexicalChunk($chunk_en, $chunk_kind)) continue;
            if (generatedTagTextMatchesSelected([
                'en' => $chunk_en,
                'pt_br' => $chunk_pt_br,
            ], $tag_text_en, $tag_text_pt_br)) {
                $selectedChunkFound = true;
            }
            $dedupe_key = normalizeLexicalChunkLookupValue($chunk_en) . '|' . normalizeLexicalChunkLookupValue($chunk_pt_br) . '|' . normalizeNullableTagMetadataText($chunkMetadata['numero']) . '|' . normalizeNullableTagMetadataText($chunkMetadata['sigla_simbolo']);
            if (isset($seen_chunks[$dedupe_key])) continue;
            $seen_chunks[$dedupe_key] = true;
            try {
                $chunks[] = findOrCreateLexicalChunkTagForOwner($pdo, 5, $chunk_en, $chunk_pt_br, $chunkMetadata['numero'], $chunkMetadata['sigla_simbolo']);
                if (count($chunks) >= 4) break;
            } catch (Throwable $e) {
                die(json_encode(['status' => 'error', 'message' => 'Erro ao criar tag de lexical chunk: ' . $chunk_en]));
            }
        }

        if (empty($chunks)) continue;
        if ($selected_tag_role === 'chunk' && !$selectedChunkFound) continue;
        $seen_sentences[$normalized_english] = true;
        $examples[] = [
            'english' => $english,
            'pt_br' => $pt_br,
            'selected_tag_id' => $tag_id,
            'selected_tag_role' => $selected_tag_role,
            'subject' => $subject,
            'objects' => $objects,
            'chunks' => $chunks
        ];
        if (count($examples) >= $example_count) break;
    }

    $gemini_debug['examples_accepted_count'] = count($examples);

    if (empty($examples)) {
        die(json_encode([
            'status' => 'error',
            'message' => 'Nenhuma frase com lexical chunks válidos e com a tradução exata da tag selecionada foi retornada pelo Gemini.',
            'gemini_debug' => $gemini_debug
        ]));
    }

    if (count($examples) < $example_count) {
        die(json_encode([
            'status' => 'error',
            'message' => 'O Gemini retornou apenas ' . count($examples) . ' frase(s) válida(s), mas eram necessárias ' . $example_count . '. Tente gerar novamente; algumas frases podem ter sido descartadas por não usar a tradução exata da tag selecionada.',
            'gemini_debug' => $gemini_debug
        ]));
    }

    if ($create_cards) {
        $stmtInsertCard = $pdo->prepare("INSERT INTO flashcards (directory_id, created_by_user_id, private_directory_id, front_encrypted, back_encrypted, image_front_encrypted, image_back_encrypted, info_type, has_audio_front, has_audio_back) VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, 0, 0)");
        foreach ($examples as &$example) {
            $front_enc = Security::encryptData($example['english']);
            $back_enc = Security::encryptData($example['pt_br']);
            if (!$stmtInsertCard->execute([$deck_id, $user_id, $deck_id, $front_enc, $back_enc, $info_type])) {
                die(json_encode(['status' => 'error', 'message' => 'Erro ao criar card de frase de exemplo.']));
            }
            $new_card_id = (int)$pdo->lastInsertId();
            $chunk_tag_ids = array_values(array_filter(array_map(static fn($chunk) => (int)($chunk['tag_id'] ?? 0), $example['chunks'])));
            $subject_tag_ids = array_values(array_filter([(int)($example['subject']['tag_id'] ?? 0)]));
            $object_tag_ids = array_values(array_filter(array_map(static fn($object) => (int)($object['tag_id'] ?? 0), $example['objects'] ?? [])));
            if (($example['selected_tag_role'] ?? '') === 'subject') {
                $subject_tag_ids[] = $tag_id;
            } elseif (($example['selected_tag_role'] ?? '') === 'object') {
                $object_tag_ids[] = $tag_id;
            } elseif (($example['selected_tag_role'] ?? '') === 'chunk') {
                $chunk_tag_ids[] = $tag_id;
            }
            syncCardTagLinks($pdo, 'flashcard_tag_links', $new_card_id, [], $user_id);
            syncCardTagLinks($pdo, 'subjects_links', $new_card_id, array_values(array_unique($subject_tag_ids)), $user_id);
            syncCardTagLinks($pdo, 'objects_links', $new_card_id, array_values(array_unique($object_tag_ids)), $user_id);
            syncCardTagLinks($pdo, 'lexical_chunks_links', $new_card_id, array_values(array_unique($chunk_tag_ids)), $user_id);
            $example['card_id'] = $new_card_id;
        }
        unset($example);
    }

    $first_example = $examples[0];
    $created_cards_count = count(array_filter($examples, static fn($example) => !empty($example['card_id'])));
    echo json_encode([
        'status' => 'success',
        'english' => $first_example['english'],
        'pt_br' => $first_example['pt_br'],
        'chunks' => $first_example['chunks'],
        'selected_tag_id' => $tag_id,
        'selected_tag_role' => $first_example['selected_tag_role'] ?? '',
        'examples' => $examples,
        'created_cards_count' => $created_cards_count,
        'existing_sentences_count' => count($existing_sentences),
        'gemini_debug' => $gemini_debug
    ]);
}




elseif ($action === 'generate_front_sentence_tags_gemini') {
    $sentence = trim((string)($input['sentence'] ?? ''));
    $create = !empty($input['create']);
    $candidatesInput = $input['candidates'] ?? null;
    $properOwner = (string)($input['proper_noun_owner'] ?? '');
    $properOwnerChoices = isset($input['proper_noun_owners']) && is_array($input['proper_noun_owners']) ? $input['proper_noun_owners'] : [];

    if ($sentence === '') {
        die(json_encode(['status' => 'error', 'message' => 'Preencha a frente do card com uma frase para gerar as tags.']));
    }

    $wordCandidates = [];
    $chunkCandidates = [];
    $englishSentence = '';

    if ($create && is_array($candidatesInput)) {
        $rawSubjects = isset($candidatesInput['subjects']) && is_array($candidatesInput['subjects']) ? $candidatesInput['subjects'] : [];
        $rawObjects = isset($candidatesInput['objects']) && is_array($candidatesInput['objects']) ? $candidatesInput['objects'] : [];
        $rawChunks = isset($candidatesInput['chunks']) && is_array($candidatesInput['chunks']) ? $candidatesInput['chunks'] : [];
        foreach ($rawSubjects as $raw) {
            if (is_array($raw) && ($candidate = normalizeFrontSentenceTagCandidate($raw, 'subject'))) $wordCandidates[] = $candidate;
        }
        foreach ($rawObjects as $raw) {
            if (is_array($raw) && ($candidate = normalizeFrontSentenceTagCandidate($raw, 'object'))) $wordCandidates[] = $candidate;
        }
        foreach ($rawChunks as $raw) {
            if (is_array($raw) && ($candidate = normalizeFrontSentenceChunkCandidate($raw))) $chunkCandidates[] = $candidate;
        }
    } else {
        if (GEMINI_API_KEY === '') {
            die(json_encode(['status' => 'error', 'message' => 'GEMINI_API_KEY não configurada no .env.']));
        }

        $prompt = sprintf(<<<'PROMPT'
Analise a frase abaixo e extraia tags para um flashcard. A frase pode estar em qualquer idioma; se não estiver em inglês, traduza para uma frase natural em inglês antes de identificar subjects, objects e chunks, e retorne essa tradução no campo english_sentence.

Frase:
%s

Retorne somente JSON válido neste formato:
{"english_sentence":"frase completa e natural em inglês","subjects":[{"en":"texto por extenso em inglês","pt_br":"texto por extenso em pt-BR","numero":"valor numérico opcional","sigla_simbolo":"sigla ou símbolo opcional","kind":"common_noun|proper_noun|verb|other"}],"objects":[{"en":"grupo nominal por extenso em inglês","pt_br":"grupo nominal por extenso em pt-BR","numero":"valor numérico opcional","sigla_simbolo":"sigla ou símbolo opcional","kind":"common_noun|proper_noun|verb|other"}],"chunks":[{"en":"lexical chunk curto ou texto por extenso em inglês","pt_br":"tradução curta ou texto por extenso em pt-BR","numero":"valor numérico opcional","sigla_simbolo":"sigla ou símbolo opcional","kind":"verb|verb_phrase|phrasal_verb|preposition|prepositional_phrase|conjunction|conjunctive_phrase"}]}

Regras:
- english_sentence deve ser sempre uma frase completa, natural e idiomática em inglês. Se a frase original já estiver em inglês, retorne a própria frase revisada apenas o necessário. Se estiver em outro idioma, traduza para inglês e use essa tradução como base para todas as tags.
- Nas tags, especialmente chunks, preposições e conjunções devem acompanhar a frase do próprio idioma. A tag en deve incluir somente conectores explícitos na frase em inglês; a tag pt_br deve incluir somente conectores explícitos na frase em pt-BR. Não copie para o pt_br uma omissão do inglês quando o português explicitou o conector, e não force no pt_br um conector que a frase em português deixou naturalmente oculto.
- Se a frase original estiver em outro idioma, extraia os chunks a partir da tradução natural em inglês, não a partir da ordem literal do idioma original.
- subjects deve conter o sujeito gramatical principal da frase, sem artigos iniciais.
- objects deve conter somente grupos nominais relevantes que não são sujeito. Substantivos devem vir sem artigos iniciais, mas preserve adjetivos, advérbios modificadores, numerais e substantivos compostos do próprio grupo nominal. Nunca reduza "obsolete information" para "information": use en="obsolete information" e pt_br="informação obsoleta". Verbos principais úteis devem ir para chunks como verbos isolados, nunca para objects.
- Use kind="proper_noun" para nomes próprios de pessoas, empresas, marcas, lugares, sistemas, produtos, obras e instituições.
- Use kind="common_noun" para substantivos comuns.
- Use kind="verb" para verbos.
- chunks deve conter de 2 a 6 lexical chunks curtos e reutilizáveis: verbos isolados úteis, locuções verbais, locuções conjuntivas, locuções prepositivas, acúmulos/combinações de preposições, preposições isoladas ou conjunções isoladas.
- Em cada chunk, preserve preposições e conjunções como elas aparecem na frase do próprio idioma: em en, se a frase em inglês usa "that", "to", "for", "in", etc., inclua esse conector na tag en; se deixou oculto, deixe oculto na tag en. Em pt_br, se a frase usa "para ...", "de ...", "a ...", "que", "se", "quando", "porque" ou equivalente, inclua esse conector na tag pt_br; se deixou oculto, deixe oculto na tag pt_br. Exemplo obrigatório: em "cards that carry obsolete information", não apague "that"; gere en="that carry" ou en="... that carry".
- Nunca inclua sujeito, substantivo ou pronome como lexical chunk. Se uma locução tiver substantivo ou pronome, substitua essa parte por reticências: "how you'll handle" vira "how ... will handle", "on the avenue" vira "on ...".
- Nas tags, remova pontuação de frase e expanda contrações: use "do not", "is", "are", "will", etc.
- Para datas completas em chunks, não inclua o ano nem o número literal do dia dentro do chunk: em vez de "on June 8th 2026", use "on June ...th". O ano e o dia devem aparecer como tags numéricas isoladas.
- Quando a tag for um número, valor, medida ou quantidade (ex.: "2010", "R$42,40", "U$10,15", "1,60m", "49kg", "1.000.000.000"), coloque o valor literal em numero e coloque os textos por extenso em en e pt_br.
- Para tags numéricas, nunca mantenha preposições ou contexto junto do texto da tag: em uma frase como "I went to the zoo in 2014", a tag do número deve ser en="two thousand and fourteen", pt_br="dois mil e catorze", numero="2014", não en="in 2014" nem pt_br="em 2014".
- Se qualquer frase gerada tiver ano, número, valor, medida ou quantidade, crie obrigatoriamente uma tag isolada para cada valor numérico literal encontrado, mesmo que o número também apareça dentro de um chunk maior como "in 2014".
- Quando existir sigla ou símbolo conhecido (ex.: "°C", "π", "TCU"), coloque-o em sigla_simbolo. Caso não exista, use null ou omita.
- Não use markdown, comentários ou texto fora do JSON.
PROMPT, $sentence);

        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $prompt]]
            ]],
            'generationConfig' => [
                'temperature' => 0.25,
                'responseMimeType' => 'application/json'
            ]
        ];

        [$httpcode, $response, $curlError] = geminiJsonRequest(GEMINI_TRANSLATION_MODEL, $payload);
        if ($httpcode !== 200 || !$response) {
            $decoded_error = $response ? json_decode($response, true) : null;
            $api_error = is_array($decoded_error) ? trim((string)($decoded_error['error']['message'] ?? '')) : '';
            $details = $api_error !== '' ? $api_error : trim((string)$curlError);
            die(json_encode(['status' => 'error', 'message' => 'Erro ao gerar tags com o Gemini.' . ($details !== '' ? (' Detalhes: ' . $details) : '')]));
        }

        $decoded = json_decode($response, true);
        $tagJson = extractGeminiText($decoded);
        $tagData = json_decode($tagJson, true);
        if (!is_array($tagData)) {
            $json_start = strpos($tagJson, '{');
            $json_end = strrpos($tagJson, '}');
            if ($json_start !== false && $json_end !== false && $json_end > $json_start) {
                $tagData = json_decode(substr($tagJson, $json_start, $json_end - $json_start + 1), true);
            }
        }
        if (!is_array($tagData)) {
            $finish_reason = trim((string)($decoded['candidates'][0]['finishReason'] ?? ''));
            die(json_encode(['status' => 'error', 'message' => 'A API do Gemini não retornou tags válidas.' . ($finish_reason !== '' ? (' Motivo: ' . $finish_reason) : '')]));
        }

        $englishSentence = normalizeTranslatedEnglishFrontSentence($tagData['english_sentence'] ?? $tagData['translated_sentence'] ?? $tagData['translation_en'] ?? '');

        foreach (($tagData['subjects'] ?? []) as $raw) {
            if (is_array($raw) && ($candidate = normalizeFrontSentenceTagCandidate($raw, 'subject'))) $wordCandidates[] = $candidate;
        }
        foreach (($tagData['objects'] ?? []) as $raw) {
            if (is_array($raw) && ($candidate = normalizeFrontSentenceTagCandidate($raw, 'object'))) $wordCandidates[] = $candidate;
        }
        foreach (($tagData['chunks'] ?? []) as $raw) {
            if (is_array($raw) && ($candidate = normalizeFrontSentenceChunkCandidate($raw))) $chunkCandidates[] = $candidate;
        }
    }

    $numberSourceTexts = [$sentence];
    if ($englishSentence !== '') $numberSourceTexts[] = $englishSentence;
    foreach (buildNumberTagCandidatesFromText(...$numberSourceTexts) as $numberCandidate) {
        $numberCandidate['panel'] = 'object';
        $wordCandidates[] = $numberCandidate;
    }

    $wordCandidates = dedupeFrontSentenceCandidates($wordCandidates, 'word');
    $chunkCandidates = dedupeFrontSentenceCandidates($chunkCandidates, 'chunk');

    if (empty($wordCandidates) && empty($chunkCandidates)) {
        die(json_encode(['status' => 'error', 'message' => 'Nenhuma tag válida foi encontrada para a frase.']));
    }

    $properNouns = array_values(array_filter($wordCandidates, static fn($candidate) => ($candidate['kind'] ?? '') === 'proper_noun'));
    foreach ($properNouns as &$properNoun) {
        $properNoun['choice_key'] = getProperNounOwnerChoiceKey($properNoun);
    }
    unset($properNoun);
    if (!$create) {
        echo json_encode([
            'status' => 'success',
            'needs_ownership_choice' => !empty($properNouns),
            'proper_nouns' => $properNouns,
            'english_sentence' => $englishSentence,
            'candidates' => [
                'subjects' => array_values(array_filter($wordCandidates, static fn($candidate) => ($candidate['panel'] ?? '') === 'subject')),
                'objects' => array_values(array_filter($wordCandidates, static fn($candidate) => ($candidate['panel'] ?? '') === 'object')),
                'chunks' => $chunkCandidates,
            ],
        ]);
        exit;
    }

    if (!empty($properNouns)) {
        $hasPerTagChoices = !empty($properOwnerChoices);
        if (!$hasPerTagChoices && !in_array($properOwner, ['user', 'public'], true)) {
            die(json_encode(['status' => 'error', 'message' => 'Escolha o dono de cada nome próprio antes de criar as tags.']));
        }
        if ($hasPerTagChoices) {
            foreach ($properNouns as $properNoun) {
                $choiceKey = getProperNounOwnerChoiceKey($properNoun);
                if (!in_array((string)($properOwnerChoices[$choiceKey] ?? ''), ['user', 'public'], true)) {
                    die(json_encode(['status' => 'error', 'message' => 'Escolha o dono de todos os nomes próprios antes de criar as tags.']));
                }
            }
        }
    }

    $properUserCount = 0;
    $properPublicCount = 0;

    $createdSubjects = [];
    $createdObjects = [];
    $createdChunks = [];

    foreach ($wordCandidates as $candidate) {
        $ownerId = 5;
        if (($candidate['kind'] ?? '') === 'proper_noun') {
            $choiceKey = getProperNounOwnerChoiceKey($candidate);
            $ownerChoice = (string)($properOwnerChoices[$choiceKey] ?? $properOwner);
            $ownerId = $ownerChoice === 'user' ? (int)$user_id : 5;
            if ($ownerId === (int)$user_id) $properUserCount++;
            else $properPublicCount++;
        }
        try {
            $tag = findOrCreateWordTagForOwner($pdo, $ownerId, (string)$candidate['en'], (string)$candidate['pt_br'], normalizeNullableTagMetadataText($candidate['numero'] ?? null), normalizeNullableTagMetadataText($candidate['sigla_simbolo'] ?? null));
            $tag['kind'] = $candidate['kind'];
            if (($candidate['panel'] ?? '') === 'subject') $createdSubjects[] = $tag;
            else $createdObjects[] = $tag;
        } catch (Throwable $e) {
            die(json_encode(['status' => 'error', 'message' => 'Erro ao criar tag de palavra: ' . (string)$candidate['en']]));
        }
    }

    foreach ($chunkCandidates as $candidate) {
        try {
            $createdChunks[] = findOrCreateLexicalChunkTagForOwner($pdo, 5, (string)$candidate['en'], (string)$candidate['pt_br'], normalizeNullableTagMetadataText($candidate['numero'] ?? null), normalizeNullableTagMetadataText($candidate['sigla_simbolo'] ?? null));
        } catch (Throwable $e) {
            die(json_encode(['status' => 'error', 'message' => 'Erro ao criar tag de lexical chunk: ' . (string)$candidate['en']]));
        }
    }

    echo json_encode([
        'status' => 'success',
        'subjects' => $createdSubjects,
        'objects' => $createdObjects,
        'chunks' => $createdChunks,
        'created_count' => count(array_filter(array_merge($createdSubjects, $createdObjects, $createdChunks), static fn($tag) => !empty($tag['created']))),
        'existing_count' => count(array_filter(array_merge($createdSubjects, $createdObjects, $createdChunks), static fn($tag) => empty($tag['created']))),
        'proper_user_count' => $properUserCount,
        'proper_public_count' => $properPublicCount,
    ]);
}



elseif ($action === 'answer_question_gemini') {
    $question = trim($input['question'] ?? '');
    $answer_language = normalizeDeckLanguage($input['answer_language'] ?? 'en-US', 'en-US');

    if ($question === '') {
        die(json_encode(['status' => 'error', 'message' => 'Texto inválido para pergunta.']));
    }

    if (GEMINI_API_KEY === '') {
        die(json_encode(['status' => 'error', 'message' => 'GEMINI_API_KEY não configurada no .env.']));
    }

    $prompt = sprintf(
        "Considere o texto abaixo como uma pergunta de flashcard. Responda em %s de forma direta, clara e útil para o verso do card. Retorne exclusivamente a resposta, sem comentários, sem markdown e sem aspas extras.

Pergunta:
%s",
        getLanguageLabel($answer_language),
        $question
    );

    $payload = [
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.2
        ]
    ];

    [$httpcode, $response, $curlError] = geminiJsonRequest(GEMINI_TRANSLATION_MODEL, $payload);

    if ($httpcode !== 200 || !$response) {
        $decoded_error = $response ? json_decode($response, true) : null;
        $api_error = is_array($decoded_error) ? trim((string)($decoded_error['error']['message'] ?? '')) : '';
        $details = $api_error !== '' ? $api_error : trim((string)$curlError);
        die(json_encode(['status' => 'error', 'message' => 'Erro ao responder com o Gemini.' . ($details !== '' ? (' Detalhes: ' . $details) : '')]));
    }

    $decoded = json_decode($response, true);
    $answer = extractGeminiText($decoded);

    if ($answer === '') {
        $finish_reason = trim((string)($decoded['candidates'][0]['finishReason'] ?? ''));
        die(json_encode(['status' => 'error', 'message' => 'A API do Gemini não retornou resposta válida.' . ($finish_reason !== '' ? (' Motivo: ' . $finish_reason) : '')]));
    }

    echo json_encode(['status' => 'success', 'answer' => $answer]);
}


elseif ($action === 'sync_back_phrase_dictionary') {
    $text = trim((string)($input['text'] ?? ''));
    $dictionary_parent_id = (int)($input['dictionary_parent_id'] ?? 6223);

    if ($text === '') {
        die(json_encode(['status' => 'error', 'message' => 'Texto do verso está vazio.']));
    }

    $stmtParent = $pdo->prepare("SELECT id FROM directories WHERE id = ? AND user_id = ? LIMIT 1");
    $stmtParent->execute([$dictionary_parent_id, $user_id]);
    if (!$stmtParent->fetch()) {
        die(json_encode(['status' => 'error', 'message' => 'Diretório base do dicionário não encontrado para este usuário.']));
    }

    $analysis = extractDictionaryCandidatesFromGpt($text);
    if (!$analysis['ok']) {
        die(json_encode(['status' => 'error', 'message' => $analysis['error'] ?: 'Falha ao analisar frase.']));
    }

    $candidates = $analysis['candidates'];
    if (empty($candidates)) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Nenhum candidato novo foi retornado pelo GPT.',
            'candidate_count' => 0,
            'created_count' => 0,
            'skipped_count' => 0,
            'created_items' => []
        ]);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmtChildren = $pdo->prepare("SELECT id, name_encrypted FROM directories WHERE user_id = ? AND parent_id = ?");
        $stmtChildren->execute([$user_id, $dictionary_parent_id]);
        $children = $stmtChildren->fetchAll();

        $existingByName = [];
        foreach ($children as $child) {
            $name = normalizeDictionarySentence(Security::decryptData($child['name_encrypted'] ?? ''));
            $nameKey = normalizeDictionarySentenceKey($name);
            if ($nameKey !== '') {
                $existingByName[$nameKey] = true;
            }
        }

        $stmtSort = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) FROM directories WHERE user_id = ? AND parent_id = ?");
        $stmtSort->execute([$user_id, $dictionary_parent_id]);
        $nextSort = (int)$stmtSort->fetchColumn() + 1;

        $stmtInsert = $pdo->prepare("INSERT INTO directories (user_id, parent_id, type, name_encrypted, default_view, open_mode, new_item_position, sort_order, icon, icon_color_from, icon_color_to, deck_front_language, deck_back_language, deck_structure, child_default_type, child_default_view) VALUES (?, ?, 10, ?, 'grid', 'fullscreen', 'end', ?, 'fa-layer-group', '#3b82f6', '#6366f1', 'pt-BR', 'en-GB', 'traducoes', 0, 'grid')");

        $createdItems = [];
        $createdCount = 0;
        foreach ($candidates as $candidate) {
            $candidateSentence = normalizeDictionarySentence($candidate);
            $candidateKey = normalizeDictionarySentenceKey($candidateSentence);
            if ($candidateSentence === '' || $candidateKey === '' || isset($existingByName[$candidateKey])) {
                continue;
            }

            $stmtInsert->execute([$user_id, $dictionary_parent_id, Security::encryptData($candidateSentence), $nextSort]);
            $nextSort++;
            $createdCount++;
            $createdItems[] = $candidateSentence;
            $existingByName[$candidateKey] = true;
        }

        $pdo->commit();

        echo json_encode([
            'status' => 'success',
            'message' => 'Sincronização do dicionário concluída.',
            'candidate_count' => count($candidates),
            'created_count' => $createdCount,
            'skipped_count' => count($candidates) - $createdCount,
            'created_items' => $createdItems
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        die(json_encode(['status' => 'error', 'message' => 'Erro ao sincronizar diretórios de dicionário.']));
    }
}




elseif ($action === 'increment_tag_score') {
    $card_id = (int)($input['card_id'] ?? 0);
    $decision_tag_id = (int)($input['decision_tag_id'] ?? 0);

    if ($card_id <= 0 || $decision_tag_id <= 0) {
        die(json_encode(['status' => 'error', 'message' => 'Card ou tag de decisão inválidos.']));
    }

    if (!verifyCardOwnership($pdo, $card_id, $user_id, true)) {
        die(json_encode(['status' => 'error', 'message' => 'Acesso negado.']));
    }

    incrementFlashcardTagScore($pdo, $user_id, $card_id, $decision_tag_id);
    echo json_encode(['status' => 'success']);
}


elseif ($action === 'update_score') {
    $card_id = (int)($input['card_id'] ?? 0);
    $decision_tag_id = (int)($input['decision_tag_id'] ?? 0);
    $reset_score = !empty($input['reset_score']);
    if ($card_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do card inválido.']));

    if (!verifyCardOwnership($pdo, $card_id, $user_id, true)) {
        die(json_encode(['status' => 'error', 'message' => 'Acesso negado.']));
    }

    if ($reset_score) {
        $stmt = $pdo->prepare("
            INSERT INTO flashcard_scores (user_id, flashcard_id, score, next_review_at)
            VALUES (?, ?, 0, NULL)
            ON DUPLICATE KEY UPDATE
                score = 0,
                last_reviewed_at = CURRENT_TIMESTAMP,
                next_review_at = NULL
        ");
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO flashcard_scores (user_id, flashcard_id, score, next_review_at)
            VALUES (?, ?, 1, DATE_ADD(NOW(), INTERVAL 24 HOUR))
            ON DUPLICATE KEY UPDATE
                score = LEAST(score + 1, 20),
                last_reviewed_at = CURRENT_TIMESTAMP,
                next_review_at = DATE_ADD(NOW(), INTERVAL (LEAST(score + 1, 20) * 24) HOUR)
        ");
    }
    
    if ($stmt->execute([$user_id, $card_id])) {
        if (!$reset_score && $decision_tag_id > 0) {
            incrementFlashcardTagScore($pdo, $user_id, $card_id, $decision_tag_id);
        }
        echo json_encode(['status' => 'success']);
    } else echo json_encode(['status' => 'error']);
}

elseif ($action === 'update_progress') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    $index = (int)($input['index'] ?? 0);

    $stmt = $pdo->prepare("
        INSERT INTO flashcard_book_progress (user_id, directory_id, current_index) 
        VALUES (?, ?, ?) 
        ON DUPLICATE KEY UPDATE current_index = ?
    ");
    $stmt->execute([$user_id, $deck_id, $index, $index]);
    echo json_encode(['status' => 'success']);
}

elseif ($action === 'increment_book_score') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    if ($deck_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do deck inválido.']));

    $deck = verifyDeckOwnership($pdo, $deck_id, $user_id);
    if (!$deck || ($deck['deck_mode'] ?? 'aleatorio') !== 'livro') {
        die(json_encode(['status' => 'error', 'message' => 'Pontuação disponível apenas para decks no modo livro.']));
    }

    $stmt = $pdo->prepare("
        INSERT INTO flashcard_book_progress (user_id, directory_id, current_index, completed_reads, next_review_at)
        VALUES (?, ?, 0, 1, DATE_ADD(NOW(), INTERVAL 24 HOUR))
        ON DUPLICATE KEY UPDATE
            current_index = 0,
            completed_reads = LEAST(completed_reads + 1, 3),
            next_review_at = DATE_ADD(NOW(), INTERVAL (LEAST(completed_reads + 1, 20) * 24) HOUR)
    ");
    $stmt->execute([$user_id, $deck_id]);
    echo json_encode(['status' => 'success']);
}

elseif ($action === 'reset_book_score') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    if ($deck_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do deck inválido.']));

    $deck = verifyDeckOwnership($pdo, $deck_id, $user_id);
    if (!$deck) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado.']));
    }

    $deckMode = $deck['deck_mode'] ?? 'aleatorio';
    $isBookMode = $deckMode === 'livro';

    if ($isBookMode) {
        $stmt = $pdo->prepare("
            INSERT INTO flashcard_book_progress (user_id, directory_id, current_index, completed_reads, next_review_at)
            VALUES (?, ?, 0, 0, NULL) 
            ON DUPLICATE KEY UPDATE current_index = 0, completed_reads = 0, next_review_at = NULL
        ");
        $stmt->execute([$user_id, $deck_id]);
        echo json_encode(['status' => 'success', 'message' => 'Pontuação do livro zerada.']);
    } else {
        if ($deckMode === 'grafo') {
            $stmt = $pdo->prepare("
                DELETE fs FROM flashcard_scores fs
                INNER JOIN flashcards f ON f.id = fs.flashcard_id
                INNER JOIN directories d ON d.id = f.directory_id
                WHERE fs.user_id = ?
                  AND d.type = 4
                  AND d.deck_mode = 'grafo'
            ");
            $stmt->execute([$user_id]);
            ensureFlashcardTagScoresTable($pdo);
            $pdo->prepare("DELETE FROM flashcard_tag_scores WHERE user_id = ?")->execute([$user_id]);
            echo json_encode(['status' => 'success', 'message' => 'Pontuação de todos os cards e tags em modo grafo foi zerada.']);
        } else {
            $stmt = $pdo->prepare("
                DELETE fs FROM flashcard_scores fs
                INNER JOIN flashcards f ON f.id = fs.flashcard_id
                WHERE fs.user_id = ? AND f.directory_id = ?
            ");
            $stmt->execute([$user_id, $deck_id]);
            echo json_encode(['status' => 'success', 'message' => 'Pontuação do deck zerada.']);
        }
    }
}

elseif ($action === 'update_settings') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    $allowed_modes = ['aleatorio', 'livro', 'grafo'];
    $mode = in_array(($input['deck_mode'] ?? ''), $allowed_modes, true) ? $input['deck_mode'] : 'aleatorio';
    $front_language = normalizeDeckLanguage($input['deck_front_language'] ?? 'pt-BR', 'pt-BR');
    $back_language = normalizeDeckLanguage($input['deck_back_language'] ?? 'en-GB', 'en-GB');
    $deck_structure = normalizeDeckStructure($input['deck_structure'] ?? 'traducoes', 'traducoes');
    $initial_tag_id = isset($input['id_tag_inicial']) && $input['id_tag_inicial'] !== null && $input['id_tag_inicial'] !== ''
        ? (int)$input['id_tag_inicial']
        : null;

    if (!verifyDeckOwnership($pdo, $deck_id, $user_id)) die(json_encode(['status' => 'error', 'message' => 'Acesso negado.']));

    if ($initial_tag_id !== null) {
        if ($initial_tag_id <= 0) {
            $initial_tag_id = null;
        } else {
            $stmtTag = $pdo->prepare("SELECT id FROM flashcard_tags WHERE id = ? AND user_id IN (?, 5) LIMIT 1");
            $stmtTag->execute([$initial_tag_id, $user_id]);
            if (!$stmtTag->fetchColumn()) die(json_encode(['status' => 'error', 'message' => 'Tag inicial inválida ou sem permissão.']));
        }
    }

    $stmt = $pdo->prepare("UPDATE directories SET deck_mode = ?, deck_front_language = ?, deck_back_language = ?, deck_structure = ?, id_tag_inicial = ? WHERE id = ?");
    if ($stmt->execute([$mode, $front_language, $back_language, $deck_structure, $initial_tag_id, $deck_id])) echo json_encode(['status' => 'success', 'message' => 'Configurações atualizadas.']);
    else echo json_encode(['status' => 'error', 'message' => 'Erro ao salvar.']);
}

// ==== Adicionar Novo Card ====
elseif ($action === 'add_single') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    $front = trim($input['front'] ?? '');
    $back = trim($input['back'] ?? '');
    $image_front = $input['image_front'] ?? null;
    $image_back = $input['image_back'] ?? null; 
    $tag_ids = [];
    $subject_tag_ids = sanitizeTagIds($input['subject_tag_ids'] ?? []);
    $object_tag_ids = sanitizeTagIds($input['object_tag_ids'] ?? []);
    $lexical_chunk_tag_ids = sanitizeTagIds($input['lexical_chunk_tag_ids'] ?? []);
    $info_type = sanitizeInfoType($input['info_type'] ?? 2);
    $dynamic_text_type = sanitizeDynamicTextType($input['dynamic_text_type'] ?? 'none');
    $question_answer = sanitizeQuestionAnswer($input['question_answer'] ?? null, $info_type);

    $has_front = !empty($front) || !empty($image_front);
    $has_back = !empty($back) || !empty($image_back);

    if ($deck_id === 0 || (!$has_front && !$has_back)) {
        die(json_encode(['status' => 'error', 'message' => 'Preencha pelo menos um lado do card com texto ou imagem.']));
    }
    if (empty($subject_tag_ids)) {
        die(json_encode(['status' => 'error', 'message' => 'Selecione ao menos 1 tag na categoria Subject para salvar o card.']));
    }
    $deck = verifyDeckOwnership($pdo, $deck_id, $user_id);
    if (!$deck) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado.']));
    }

    $dynamicSubjectMothers = $dynamic_text_type === 'subject' ? getDynamicSubjectMotherTags($pdo, $user_id, $subject_tag_ids) : [];
    if ($dynamic_text_type === 'subject' && empty($dynamicSubjectMothers)) {
        die(json_encode(['status' => 'error', 'message' => 'Nenhuma tag mãe com tipo_de_relacao 24 foi encontrada para o sujeito selecionado como tag filha.']));
    }
    if ($dynamic_text_type === 'subject' && !str_contains($front, '$sujeitoDinamico') && !str_contains($front, '{{sujeitoDinamico}}') && !str_contains($front, '{sujeitoDinamico}')) {
        die(json_encode(['status' => 'error', 'message' => 'Use $sujeitoDinamico, {{sujeitoDinamico}} ou {sujeitoDinamico} no texto da frente para criar sujeito dinâmico.']));
    }

    $stmt = $pdo->prepare("INSERT INTO flashcards (directory_id, created_by_user_id, private_directory_id, front_encrypted, back_encrypted, image_front_encrypted, image_back_encrypted, info_type, question_answer, dynamic_text_type, dynamic_parent_flashcard_id, has_audio_front, has_audio_back) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)");
    $created_card_ids = [];
    $template_card_id = null;
    $templateDynamicTextType = dynamicTextTypeToInt($dynamic_text_type);
    $cardsToCreate = [['id' => null, 'label' => null, 'dynamic_text_type' => $templateDynamicTextType]];
    if ($dynamic_text_type === 'subject') {
        foreach ($dynamicSubjectMothers as $dynamicSubjectMother) {
            $cardsToCreate[] = [
                'id' => (int)$dynamicSubjectMother['id'],
                'label' => (string)$dynamicSubjectMother['label'],
                'dynamic_text_type' => 0
            ];
        }
    }

    foreach ($cardsToCreate as $dynamicSubject) {
        $isGeneratedDynamicSubjectCard = $dynamic_text_type === 'subject' && $dynamicSubject['id'] !== null;
        $cardFront = $isGeneratedDynamicSubjectCard ? renderDynamicSubjectFront($front, (string)$dynamicSubject['label']) : $front;
        $cardSubjectTagIds = $isGeneratedDynamicSubjectCard ? [(int)$dynamicSubject['id']] : $subject_tag_ids;
        $cardDynamicTextType = (int)($dynamicSubject['dynamic_text_type'] ?? 0);
        $dynamicParentFlashcardId = $isGeneratedDynamicSubjectCard ? $template_card_id : null;
        $front_enc = !empty($cardFront) ? Security::encryptData($cardFront) : null;
        $back_enc = !empty($back) ? Security::encryptData($back) : null;
        $img_front_enc = !empty($image_front) ? Security::encryptData($image_front) : null;
        $img_back_enc = !empty($image_back) ? Security::encryptData($image_back) : null;

        if (!$stmt->execute([$deck_id, $user_id, $deck_id, $front_enc, $back_enc, $img_front_enc, $img_back_enc, $info_type, $question_answer, $cardDynamicTextType, $dynamicParentFlashcardId])) {
            echo json_encode(['status' => 'error', 'message' => 'Erro ao adicionar card.']);
            return;
        }

        $new_card_id = (int)$pdo->lastInsertId();
        if (!$isGeneratedDynamicSubjectCard) {
            $template_card_id = $new_card_id;
        }
        $created_card_ids[] = $new_card_id;
        syncCardTagLinks($pdo, 'flashcard_tag_links', $new_card_id, $tag_ids, $user_id);
        syncCardTagLinks($pdo, 'subjects_links', $new_card_id, $cardSubjectTagIds, $user_id);
        syncCardTagLinks($pdo, 'objects_links', $new_card_id, $object_tag_ids, $user_id);
        syncCardTagLinks($pdo, 'lexical_chunks_links', $new_card_id, $lexical_chunk_tag_ids, $user_id);
    }

    $createdCount = count($created_card_ids);
    echo json_encode([
        'status' => 'success',
        'message' => $dynamic_text_type === 'subject' ? "{$createdCount} cards adicionados (incluindo o modelo dinâmico)." : 'Card adicionado.',
        'card_id' => $created_card_ids[0] ?? null,
        'card_ids' => $created_card_ids,
        'created_count' => $createdCount,
        'redirect_url' => normalizeReturnTarget($input['return_to'] ?? '')
    ]);
}

// ==== Buscar Card para Edição ====
elseif ($action === 'get_card_for_edit') {
    $card_id = (int)($input['card_id'] ?? 0);
    if ($card_id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID do card inválido.']));
    }

    $cardOwnership = verifyCardOwnership($pdo, $card_id, $user_id);
    if (!$cardOwnership) {
        die(json_encode(['status' => 'error', 'message' => 'Acesso negado.']));
    }

    $stmt = $pdo->prepare("
        SELECT id, directory_id, created_by_user_id, private_directory_id, front_encrypted, back_encrypted, image_front_encrypted, image_back_encrypted, info_type, question_answer, dynamic_text_type, dynamic_parent_flashcard_id, has_audio_front, has_audio_back
        FROM flashcards
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$card_id]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$card) {
        die(json_encode(['status' => 'error', 'message' => 'Card não encontrado para edição.']));
    }

    $subjectTagsByCard = fetchLinkedTagsByCard($pdo, 'subjects_links', [$card_id], $user_id);
    $objectTagsByCard = fetchLinkedTagsByCard($pdo, 'objects_links', [$card_id], $user_id);
    $lexicalChunksTagsByCard = fetchLinkedTagsByCard($pdo, 'lexical_chunks_links', [$card_id], $user_id);

    echo json_encode([
        'status' => 'success',
        'data' => [
            'id' => (int)$card['id'],
            'directory_id' => (int)$card['directory_id'],
            'created_by_user_id' => (int)($card['created_by_user_id'] ?? 0),
            'private_directory_id' => (int)($card['private_directory_id'] ?? 0),
            'is_public' => (int)$card['directory_id'] === 6452 ? 1 : 0,
            'front' => !empty($card['front_encrypted']) ? Security::decryptData($card['front_encrypted']) : '',
            'back' => !empty($card['back_encrypted']) ? Security::decryptData($card['back_encrypted']) : '',
            'image_front' => !empty($card['image_front_encrypted']) ? Security::decryptData($card['image_front_encrypted']) : null,
            'image_back' => !empty($card['image_back_encrypted']) ? Security::decryptData($card['image_back_encrypted']) : null,
            'info_type' => sanitizeInfoType($card['info_type'] ?? 2),
            'question_answer' => $card['question_answer'] === null ? null : (int)$card['question_answer'],
            'dynamic_text_type' => dynamicTextTypeFromInt($card['dynamic_text_type'] ?? 0),
            'dynamic_parent_flashcard_id' => !empty($card['dynamic_parent_flashcard_id']) ? (int)$card['dynamic_parent_flashcard_id'] : null,
            'has_audio_front' => (int)$card['has_audio_front'],
            'has_audio_back' => (int)$card['has_audio_back'],
            'subject_tags' => $subjectTagsByCard[$card_id] ?? [],
            'object_tags' => $objectTagsByCard[$card_id] ?? [],
            'lexical_chunks_tags' => $lexicalChunksTagsByCard[$card_id] ?? []
        ]
    ]);
}

// ==== Alternar visibilidade pública do card ====
elseif ($action === 'toggle_card_public') {
    $publicDirectoryId = 6452;
    $card_id = (int)($input['card_id'] ?? 0);
    if ($card_id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID do card inválido.']));
    }

    $card = verifyCardOwnership($pdo, $card_id, $user_id);
    if (!$card) {
        die(json_encode(['status' => 'error', 'message' => 'Acesso negado.']));
    }

    $currentDirectoryId = (int)$card['directory_id'];
    $storedPrivateDirectoryId = (int)($card['private_directory_id'] ?? 0);
    if ($currentDirectoryId === $publicDirectoryId) {
        if ($storedPrivateDirectoryId <= 0 || !verifyDeckOwnership($pdo, $storedPrivateDirectoryId, $user_id)) {
            die(json_encode(['status' => 'error', 'message' => 'Não foi possível encontrar o diretório privado original deste card.']));
        }
        $stmt = $pdo->prepare('UPDATE flashcards SET directory_id = ?, created_by_user_id = COALESCE(created_by_user_id, ?), private_directory_id = ? WHERE id = ?');
        $stmt->execute([$storedPrivateDirectoryId, $user_id, $storedPrivateDirectoryId, $card_id]);
        echo json_encode(['status' => 'success', 'message' => 'Card tornou-se privado novamente.', 'data' => ['is_public' => 0, 'directory_id' => $storedPrivateDirectoryId, 'private_directory_id' => $storedPrivateDirectoryId]]);
    } else {
        if (!verifyDeckOwnership($pdo, $currentDirectoryId, $user_id)) {
            die(json_encode(['status' => 'error', 'message' => 'Acesso negado.']));
        }
        $stmt = $pdo->prepare('UPDATE flashcards SET directory_id = ?, created_by_user_id = COALESCE(created_by_user_id, ?), private_directory_id = ? WHERE id = ?');
        $stmt->execute([$publicDirectoryId, $user_id, $currentDirectoryId, $card_id]);
        echo json_encode(['status' => 'success', 'message' => 'Card publicado no diretório público.', 'data' => ['is_public' => 1, 'directory_id' => $publicDirectoryId, 'private_directory_id' => $currentDirectoryId]]);
    }
}

// ==== Editar Card Existente ====
elseif ($action === 'update_card') {
    $card_id = (int)($input['card_id'] ?? 0);
    $front = trim($input['front'] ?? '');
    $back = trim($input['back'] ?? '');
    $image_front = $input['image_front'] ?? null;
    $image_back = $input['image_back'] ?? null;
    $tag_ids = [];
    $subject_tag_ids = sanitizeTagIds($input['subject_tag_ids'] ?? []);
    $object_tag_ids = sanitizeTagIds($input['object_tag_ids'] ?? []);
    $lexical_chunk_tag_ids = sanitizeTagIds($input['lexical_chunk_tag_ids'] ?? []);
    $info_type = sanitizeInfoType($input['info_type'] ?? 2);
    $dynamic_text_type = sanitizeDynamicTextType($input['dynamic_text_type'] ?? 'none');
    $question_answer = sanitizeQuestionAnswer($input['question_answer'] ?? null, $info_type);
    $dynamic_text_type_id = dynamicTextTypeToInt($dynamic_text_type);

    $has_front = !empty($front) || !empty($image_front);
    $has_back = !empty($back) || !empty($image_back);

    if ($card_id === 0 || (!$has_front && !$has_back)) {
        die(json_encode(['status' => 'error', 'message' => 'Dados inválidos. Preencha pelo menos um lado do card com texto ou imagem.']));
    }
    if (empty($subject_tag_ids)) {
        die(json_encode(['status' => 'error', 'message' => 'Selecione ao menos 1 tag na categoria Subject para salvar o card.']));
    }

    if (!verifyCardOwnership($pdo, $card_id, $user_id)) {
        die(json_encode(['status' => 'error', 'message' => 'Acesso negado.']));
    }

    $front_enc = !empty($front) ? Security::encryptData($front) : null;
    $back_enc = !empty($back) ? Security::encryptData($back) : null;
    $img_front_enc = !empty($image_front) ? Security::encryptData($image_front) : null;
    $img_back_enc = !empty($image_back) ? Security::encryptData($image_back) : null;

    // Mantém os áudios existentes. Eles só devem ser alterados quando o usuário solicitar nova geração.
    $stmt = $pdo->prepare("UPDATE flashcards SET front_encrypted = ?, back_encrypted = ?, image_front_encrypted = ?, image_back_encrypted = ?, info_type = ?, question_answer = ?, dynamic_text_type = ? WHERE id = ?");
    
    if ($stmt->execute([$front_enc, $back_enc, $img_front_enc, $img_back_enc, $info_type, $question_answer, $dynamic_text_type_id, $card_id])) {
        syncCardTagLinks($pdo, 'flashcard_tag_links', $card_id, $tag_ids, $user_id);
        syncCardTagLinks($pdo, 'subjects_links', $card_id, $subject_tag_ids, $user_id);
        syncCardTagLinks($pdo, 'objects_links', $card_id, $object_tag_ids, $user_id);
        syncCardTagLinks($pdo, 'tipo_frasal_links', $card_id, [], $user_id);
        syncCardTagLinks($pdo, 'tense_links', $card_id, [], $user_id);
        syncCardTagLinks($pdo, 'lexical_chunks_links', $card_id, $lexical_chunk_tag_ids, $user_id);
        syncCardTagLinks($pdo, 'relation_links', $card_id, [], $user_id);
        syncCardTagLinks($pdo, 'words_links', $card_id, [], $user_id);
        syncCardIdiomaLinks($pdo, $card_id, [], [], $user_id);
        echo json_encode(['status' => 'success', 'message' => 'Card atualizado.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao atualizar card.']);
    }
}

// ==== Deletar Card ====
elseif ($action === 'delete_card') {
    $card_id = (int)($input['card_id'] ?? 0);

    if ($card_id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID do card inválido.']));
    }

    if (!verifyCardOwnership($pdo, $card_id, $user_id)) {
        die(json_encode(['status' => 'error', 'message' => 'Acesso negado.']));
    }

    $cardStmt = $pdo->prepare("
        SELECT f.id, f.dynamic_text_type, f.dynamic_parent_flashcard_id
        FROM flashcards f
        WHERE f.id = ?
        LIMIT 1
    ");
    $cardStmt->execute([$card_id]);
    $cardToDelete = $cardStmt->fetch(PDO::FETCH_ASSOC);
    if (!$cardToDelete) {
        die(json_encode(['status' => 'error', 'message' => 'Card não encontrado.']));
    }

    try {
        $pdo->beginTransaction();

        $deletedDerivedCount = 0;
        $isDynamicTemplateCard = (int)($cardToDelete['dynamic_text_type'] ?? 0) === 1;
        if ($isDynamicTemplateCard) {
            $deleteDerivedStmt = $pdo->prepare("
                DELETE child
                FROM flashcards child
                INNER JOIN directories child_directory ON child_directory.id = child.directory_id
                WHERE child.dynamic_parent_flashcard_id = ?
                  AND (child_directory.user_id = ? OR child.created_by_user_id = ?)
            ");
            $deleteDerivedStmt->execute([$card_id, $user_id, $user_id]);
            $deletedDerivedCount = $deleteDerivedStmt->rowCount();
        }

        $stmt = $pdo->prepare("DELETE FROM flashcards WHERE id = ?");
        $stmt->execute([$card_id]);
        $deletedTemplateCount = $stmt->rowCount();

        if ($deletedTemplateCount < 1) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Erro interno ao excluir card.']);
            return;
        }

        $pdo->commit();
        $message = $deletedDerivedCount > 0
            ? "Card excluído com sucesso. {$deletedDerivedCount} card(s) derivado(s) também foram excluídos."
            : 'Card excluído com sucesso.';
        echo json_encode(['status' => 'success', 'message' => $message]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[flashcards][delete_card] ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Erro interno ao excluir card.']);
    }
}



elseif ($action === 'list_graph_cards_for_user') {
    $rawPage = is_array($input) ? ($input['page'] ?? ($_GET['page'] ?? 1)) : ($_GET['page'] ?? 1);
    $rawTagIds = is_array($input) ? ($input['tag_ids'] ?? ($_GET['tag_ids'] ?? null)) : ($_GET['tag_ids'] ?? null);
    $rawTagId = is_array($input) ? ($input['tag_id'] ?? ($_GET['tag_id'] ?? 0)) : ($_GET['tag_id'] ?? 0);
    $rawTagLinkTypes = is_array($input) ? ($input['tag_link_types'] ?? ($_GET['tag_link_types'] ?? ['subject', 'object', 'lexical_chunk'])) : ($_GET['tag_link_types'] ?? ['subject', 'object', 'lexical_chunk']);
    $rawWithoutTags = is_array($input) ? ($input['without_tags'] ?? ($_GET['without_tags'] ?? 0)) : ($_GET['without_tags'] ?? 0);
    $rawInfoTypes = is_array($input) ? ($input['info_types'] ?? ($_GET['info_types'] ?? [0, 1, 2, 3, 4, 5])) : ($_GET['info_types'] ?? [0, 1, 2, 3, 4, 5]);
    $withoutTagsOnly = filter_var($rawWithoutTags, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    $withoutTagsOnly = $withoutTagsOnly === null ? ((string)$rawWithoutTags === '1') : $withoutTagsOnly;
    $page = filter_var($rawPage, FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
    $tagIds = sanitizeTagIds($rawTagIds ?? $rawTagId);
    $tagLinkTypes = sanitizeGraphTagLinkTypes($rawTagLinkTypes);
    $infoTypes = sanitizeGraphInfoTypes($rawInfoTypes);
    $tagLinkColumnsByTable = getGraphTagLinkColumnsForTypes($tagLinkTypes);
    $perPage = 20;
    $offset = ($page - 1) * $perPage;
    $tagFilterSql = '';
    $tagFilterParams = [];
    $infoTypeFilterSql = '';
    $infoTypeFilterParams = [];
    if (!empty($infoTypes) && count($infoTypes) < 6) {
        $infoTypePlaceholders = implode(',', array_fill(0, count($infoTypes), '?'));
        $infoTypeFilterSql = " AND f.info_type IN ({$infoTypePlaceholders})";
        $infoTypeFilterParams = $infoTypes;
    } elseif (empty($infoTypes)) {
        $infoTypeFilterSql = ' AND 0 = 1';
    }

    if ($withoutTagsOnly) {
        $withoutTagClauses = [];
        foreach ($tagLinkColumnsByTable as $linkTable => $columns) {
            foreach ($columns as $column) {
                $withoutTagClauses[] = "NOT EXISTS (SELECT 1 FROM {$linkTable} l WHERE l.flashcard_id = f.id AND l.{$column} IS NOT NULL)";
            }
        }
        $tagFilterSql = !empty($withoutTagClauses) ? ' AND ' . implode(' AND ', $withoutTagClauses) : ' AND 0 = 1';
        $tagIds = [];
    } elseif (!empty($tagIds)) {
        $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
        $stmtTag = $pdo->prepare("SELECT id FROM flashcard_tags WHERE id IN ($placeholders) AND user_id IN (?, 5)");
        $stmtTag->execute(array_merge($tagIds, [$user_id]));
        $allowedTagIds = array_map('intval', $stmtTag->fetchAll(PDO::FETCH_COLUMN));
        if (count($allowedTagIds) !== count($tagIds)) {
            die(json_encode(['status' => 'error', 'message' => 'Uma ou mais tags não foram encontradas ou estão sem permissão.']));
        }

        $tagFilterSqlParts = [];
        foreach ($tagIds as $tagId) {
            $tagExistsClauses = [];
            foreach ($tagLinkColumnsByTable as $linkTable => $columns) {
                foreach ($columns as $column) {
                    $tagExistsClauses[] = "EXISTS (SELECT 1 FROM {$linkTable} l WHERE l.flashcard_id = f.id AND l.{$column} = ?)";
                    $tagFilterParams[] = $tagId;
                }
            }
            $tagFilterSqlParts[] = !empty($tagExistsClauses) ? '(' . implode(' OR ', $tagExistsClauses) . ')' : '0 = 1';
        }
        $tagFilterSql = ' AND ' . implode(' AND ', $tagFilterSqlParts);
    }

    $stmtTotal = $pdo->prepare("
        SELECT COUNT(f.id)
        FROM flashcards f
        INNER JOIN directories d ON d.id = f.directory_id
        WHERE d.user_id = ?
          AND d.deck_mode = 'grafo'
          AND d.type IN (4, 10)
          {$tagFilterSql}
          {$infoTypeFilterSql}
    ");
    $stmtTotal->execute(array_merge([$user_id], $tagFilterParams, $infoTypeFilterParams));
    $totalCards = (int)$stmtTotal->fetchColumn();
    $totalPages = max(1, (int)ceil($totalCards / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $stmt = $pdo->prepare("
        SELECT
            f.id,
            f.directory_id,
            f.front_encrypted,
            f.back_encrypted,
            f.image_front_encrypted,
            f.image_back_encrypted,
            f.info_type,
            f.question_answer,
            f.dynamic_text_type,
            f.dynamic_parent_flashcard_id,
            f.has_audio_front,
            f.has_audio_back,
            d.name_encrypted AS directory_name_encrypted,
            COALESCE(fs.score, 0) AS score
        FROM flashcards f
        INNER JOIN directories d ON d.id = f.directory_id
        LEFT JOIN flashcard_scores fs ON fs.flashcard_id = f.id AND fs.user_id = ?
        WHERE d.user_id = ?
          AND d.deck_mode = 'grafo'
          AND d.type IN (4, 10)
          {$tagFilterSql}
          {$infoTypeFilterSql}
        ORDER BY f.id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute(array_merge([$user_id, $user_id], $tagFilterParams, $infoTypeFilterParams));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $cardIds = array_values(array_map(static fn($card) => (int)$card['id'], $rows));
    $tagsByCard = [];
    foreach (getCardTagLinkColumnsByTable() as $linkTable => $columns) {
        foreach ($columns as $column) {
            $linkedTags = fetchLinkedTagsByCardColumn($pdo, $linkTable, $column, $cardIds, $user_id);
            foreach ($linkedTags as $flashcardId => $tags) {
                if (!isset($tagsByCard[$flashcardId])) $tagsByCard[$flashcardId] = [];
                foreach ($tags as $tag) {
                    $tagId = (int)($tag['id'] ?? 0);
                    if ($tagId <= 0 || isset($tagsByCard[$flashcardId][$tagId])) continue;
                    $tagsByCard[$flashcardId][$tagId] = $tag;
                }
            }
        }
    }

    $cards = [];
    foreach ($rows as $card) {
        $cardId = (int)$card['id'];
        $cards[] = [
            'id' => $cardId,
            'directory_id' => (int)$card['directory_id'],
            'directory_name' => !empty($card['directory_name_encrypted']) ? Security::decryptData($card['directory_name_encrypted']) : '',
            'front' => !empty($card['front_encrypted']) ? Security::decryptData($card['front_encrypted']) : '',
            'back' => !empty($card['back_encrypted']) ? Security::decryptData($card['back_encrypted']) : '',
            'image_front' => !empty($card['image_front_encrypted']) ? Security::decryptData($card['image_front_encrypted']) : null,
            'image_back' => !empty($card['image_back_encrypted']) ? Security::decryptData($card['image_back_encrypted']) : null,
            'info_type' => sanitizeInfoType($card['info_type'] ?? 2),
            'question_answer' => $card['question_answer'] === null ? null : (int)$card['question_answer'],
            'dynamic_text_type' => dynamicTextTypeFromInt($card['dynamic_text_type'] ?? 0),
            'dynamic_parent_flashcard_id' => !empty($card['dynamic_parent_flashcard_id']) ? (int)$card['dynamic_parent_flashcard_id'] : null,
            'has_audio_front' => (int)$card['has_audio_front'],
            'has_audio_back' => (int)$card['has_audio_back'],
            'score' => (int)$card['score'],
            'tags' => array_values($tagsByCard[$cardId] ?? []),
        ];
    }

    echo json_encode([
        'status' => 'success',
        'data' => $cards,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total_cards' => $totalCards,
            'total_pages' => $totalPages,
        ],
    ]);
}

elseif ($action === 'list_subject_cards_by_tag') {
    $tag_id = (int)($input['tag_id'] ?? 0);
    if ($tag_id <= 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID da tag inválido.']));
    }

    $stmtTag = $pdo->prepare("SELECT id FROM flashcard_tags WHERE id = ? AND user_id IN (?, 5) LIMIT 1");
    $stmtTag->execute([$tag_id, $user_id]);
    if (!$stmtTag->fetchColumn()) {
        die(json_encode(['status' => 'error', 'message' => 'Tag não encontrada ou sem permissão.']));
    }

    $stmt = $pdo->prepare("
        SELECT DISTINCT
            f.id,
            f.directory_id,
            f.front_encrypted,
            f.back_encrypted,
            f.image_front_encrypted,
            f.image_back_encrypted,
            d.user_id AS directory_user_id,
            d.name_encrypted AS directory_name_encrypted
        FROM (
            SELECT flashcard_id FROM subjects_links WHERE tag_id = ?
            UNION
            SELECT flashcard_id FROM objects_links WHERE tag_id = ?
            UNION
            SELECT flashcard_id FROM lexical_chunks_links WHERE tag_id = ?
        ) linked
        INNER JOIN flashcards f ON f.id = linked.flashcard_id
        INNER JOIN directories d ON d.id = f.directory_id
        WHERE d.user_id IN (?, 5)
        ORDER BY f.id DESC
    ");
    $stmt->execute([$tag_id, $tag_id, $tag_id, $user_id]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $cardIds = array_map(static fn($card) => (int)$card['id'], $rows);
    $subjectTagsByCard = fetchLinkedTagsByCard($pdo, 'subjects_links', $cardIds, $user_id);
    $objectTagsByCard = fetchLinkedTagsByCard($pdo, 'objects_links', $cardIds, $user_id);
    $lexicalChunksTagsByCard = fetchLinkedTagsByCard($pdo, 'lexical_chunks_links', $cardIds, $user_id);

    $cards = [];
    foreach ($rows as $card) {
        $cardId = (int)$card['id'];
        $cards[] = [
            'id' => (int)$card['id'],
            'directory_id' => (int)$card['directory_id'],
            'directory_name' => !empty($card['directory_name_encrypted']) ? Security::decryptData($card['directory_name_encrypted']) : '',
            'front' => !empty($card['front_encrypted']) ? Security::decryptData($card['front_encrypted']) : '',
            'back' => !empty($card['back_encrypted']) ? Security::decryptData($card['back_encrypted']) : '',
            'image_front' => !empty($card['image_front_encrypted']) ? Security::decryptData($card['image_front_encrypted']) : null,
            'image_back' => !empty($card['image_back_encrypted']) ? Security::decryptData($card['image_back_encrypted']) : null,
            'can_edit' => (int)$card['directory_user_id'] === (int)$user_id ? 1 : 0,
            'subject_tags' => $subjectTagsByCard[$cardId] ?? [],
            'object_tags' => $objectTagsByCard[$cardId] ?? [],
            'lexical_chunks_tags' => $lexicalChunksTagsByCard[$cardId] ?? []
        ];
    }

    echo json_encode(['status' => 'success', 'data' => $cards]);
}

elseif ($action === 'list_cards_for_tag_filtering') {
    $stmt = $pdo->prepare("
        SELECT f.id, f.front_encrypted, f.back_encrypted
        FROM flashcards f
        JOIN directories d ON d.id = f.directory_id
        WHERE d.user_id IN (?, 5)
        ORDER BY f.id DESC
        LIMIT 2000
    ");
    $stmt->execute([$user_id]);
    $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cardIds = array_map(static fn($card) => (int)$card['id'], $cards);
    $allTagsByCard = fetchLinkedTagsByCard($pdo, 'flashcard_tag_links', $cardIds, $user_id);
    $subjectTagsByCard = fetchLinkedTagsByCard($pdo, 'subjects_links', $cardIds, $user_id);
    $objectTagsByCard = fetchLinkedTagsByCard($pdo, 'objects_links', $cardIds, $user_id);
    $tipoFrasalTagsByCard = fetchLinkedTagsByCard($pdo, 'tipo_frasal_links', $cardIds, $user_id);
    $tenseTagsByCard = fetchLinkedTagsByCard($pdo, 'tense_links', $cardIds, $user_id);
    $lexicalChunksTagsByCard = fetchLinkedTagsByCard($pdo, 'lexical_chunks_links', $cardIds, $user_id);
    $relationTagsByCard = fetchLinkedTagsByCard($pdo, 'relation_links', $cardIds, $user_id);
    $wordsTagsByCard = fetchLinkedTagsByCard($pdo, 'words_links', $cardIds, $user_id);
    $idiomaPrincipalTagsByCard = fetchLinkedTagsByCardColumn($pdo, 'idiomas_links', 'tag_id', $cardIds, $user_id);
    $idiomaSecundarioTagsByCard = fetchLinkedTagsByCardColumn($pdo, 'idiomas_links', 'segundo_idioma_tag_id', $cardIds, $user_id);

    $response = [];
    foreach ($cards as $card) {
        $cardId = (int)$card['id'];
        $response[] = [
            'id' => $cardId,
            'front' => !empty($card['front_encrypted']) ? Security::decryptData($card['front_encrypted']) : '',
            'back' => !empty($card['back_encrypted']) ? Security::decryptData($card['back_encrypted']) : '',
            'all_tags' => $allTagsByCard[$cardId] ?? [],
            'subject_tags' => $subjectTagsByCard[$cardId] ?? [],
            'object_tags' => $objectTagsByCard[$cardId] ?? [],
            'tipo_frasal_tags' => $tipoFrasalTagsByCard[$cardId] ?? [],
            'tense_tags' => $tenseTagsByCard[$cardId] ?? [],
            'lexical_chunks_tags' => $lexicalChunksTagsByCard[$cardId] ?? [],
            'relation_tags' => $relationTagsByCard[$cardId] ?? [],
            'words_tags' => $wordsTagsByCard[$cardId] ?? [],
            'idioma_principal_tags' => $idiomaPrincipalTagsByCard[$cardId] ?? [],
            'idioma_secundario_tags' => $idiomaSecundarioTagsByCard[$cardId] ?? []
        ];
    }

    echo json_encode(['status' => 'success', 'data' => $response]);
}

elseif ($action === 'list_tags') {
    $stmt = $pdo->prepare("
        SELECT
            t.id,
            t.user_id,
            t.created_by_user_id,
            t.name_encrypted,
            t.name_pt_br_encrypted,
            t.numero,
            t.sigla_simbolo,
            t.color,
            t.is_book,
            t.is_verb_tense,
            t.is_sentence_type,
            t.is_lexical_chunk,
            t.is_relation_type,
            t.is_word,
            t.is_month,
            t.is_day,
            t.is_year,
            COALESCE(subject_counts.subjects_count, 0) AS subjects_count,
            COALESCE(subject_card_counts.subject_cards_count, 0) AS subject_cards_count,
            COALESCE(object_card_counts.object_cards_count, 0) AS object_cards_count,
            COALESCE(lexical_chunk_card_counts.lexical_chunk_cards_count, 0) AS lexical_chunk_cards_count
        FROM flashcard_tags t
        LEFT JOIN (
" . getSubjectObjectLexicalChunkCardCountSubquery() . "
        ) subject_counts ON subject_counts.tag_id = t.id
        LEFT JOIN (
" . getTagCardCountSubqueryForLinkTable('subjects_links', 'subject_cards_count') . "
        ) subject_card_counts ON subject_card_counts.tag_id = t.id
        LEFT JOIN (
" . getTagCardCountSubqueryForLinkTable('objects_links', 'object_cards_count') . "
        ) object_card_counts ON object_card_counts.tag_id = t.id
        LEFT JOIN (
" . getTagCardCountSubqueryForLinkTable('lexical_chunks_links', 'lexical_chunk_cards_count') . "
        ) lexical_chunk_card_counts ON lexical_chunk_card_counts.tag_id = t.id
        WHERE t.user_id IN (?, 5)
        ORDER BY t.id ASC
    ");
    $stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id]);
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $parsed = [];
    foreach ($tags as $tag) {
        $tag['name'] = !empty($tag['name_encrypted']) ? Security::decryptData($tag['name_encrypted']) : '';
        $tag['name_pt_br'] = !empty($tag['name_pt_br_encrypted']) ? Security::decryptData($tag['name_pt_br_encrypted']) : null;
        $tag['subjects_count'] = (int)($tag['subjects_count'] ?? 0);
        $tag['subject_cards_count'] = (int)($tag['subject_cards_count'] ?? 0);
        $tag['object_cards_count'] = (int)($tag['object_cards_count'] ?? 0);
        $tag['lexical_chunk_cards_count'] = (int)($tag['lexical_chunk_cards_count'] ?? 0);
        unset($tag['name_encrypted'], $tag['name_pt_br_encrypted']);
        $parsed[] = $tag;
    }
    echo json_encode(['status' => 'success', 'data' => $parsed]);
}



elseif ($action === 'list_user_tags_by_subject_card_count' || $action === 'list_orphan_user_tags') {
    $page = max(1, (int)($input['page'] ?? ($_GET['page'] ?? 1)));
    $perPage = (int)($input['per_page'] ?? ($_GET['per_page'] ?? 10));
    if ($perPage < 1) $perPage = 10;
    if ($perPage > 50) $perPage = 50;
    $offset = ($page - 1) * $perPage;

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM flashcard_tags WHERE user_id = ?");
    $stmtCount->execute([$user_id]);
    $total = (int)$stmtCount->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $sql = "
        SELECT
            t.id,
            t.user_id,
            t.name_encrypted,
            t.name_pt_br_encrypted,
            t.numero,
            t.sigla_simbolo,
            t.color,
            COALESCE(subject_counts.subjects_count, 0) AS subjects_count
        FROM flashcard_tags t
        LEFT JOIN (
" . getSubjectObjectLexicalChunkCardCountSubquery() . "
        ) subject_counts ON subject_counts.tag_id = t.id
        WHERE t.user_id = ?
        ORDER BY subjects_count ASC, t.id ASC
        LIMIT ? OFFSET ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(1, $user_id, PDO::PARAM_INT);
    $stmt->bindValue(2, $user_id, PDO::PARAM_INT);
    $stmt->bindValue(3, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(4, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $parsed = [];
    foreach ($tags as $tag) {
        $tag['name'] = !empty($tag['name_encrypted']) ? Security::decryptData($tag['name_encrypted']) : '';
        $tag['name_pt_br'] = !empty($tag['name_pt_br_encrypted']) ? Security::decryptData($tag['name_pt_br_encrypted']) : null;
        $tag['subjects_count'] = (int)($tag['subjects_count'] ?? 0);
        unset($tag['name_encrypted'], $tag['name_pt_br_encrypted']);
        $parsed[] = $tag;
    }
    echo json_encode([
        'status' => 'success',
        'count' => count($parsed),
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
        'data' => $parsed
    ]);
}

elseif ($action === 'list_tag_family_relations') {
    $stmt = $pdo->prepare("SELECT id_tag_child, id_tag_mother, tipo_de_relacao FROM relacoes_taguineas WHERE id_user IN (?, 5)");
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $parsed = array_map(static function ($row) {
        return [
            'id_tag_child' => (int)$row['id_tag_child'],
            'id_tag_mother' => (int)$row['id_tag_mother'],
            'tipo_de_relacao' => (int)$row['tipo_de_relacao']
        ];
    }, $rows);
    echo json_encode(['status' => 'success', 'data' => $parsed]);
}

elseif ($action === 'list_saved_filters') {
    $stmt = $pdo->prepare("
        SELECT
            f.id,
            f.id_tag,
            f.ativo,
            t.user_id,
            t.created_by_user_id,
            t.name_encrypted,
            t.name_pt_br_encrypted,
            t.numero,
            t.sigla_simbolo,
            t.color,
            COALESCE(subject_counts.subjects_count, 0) AS subjects_count
        FROM filtros f
        INNER JOIN flashcard_tags t ON t.id = f.id_tag
        LEFT JOIN (
" . getSubjectObjectLexicalChunkCardCountSubquery() . "
        ) subject_counts ON subject_counts.tag_id = t.id
        WHERE f.id_user = ? AND f.ativo = 1 AND t.user_id IN (?, 5)
        ORDER BY f.id DESC
    ");
    $stmt->execute([$user_id, $user_id, $user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $parsed = [];
    foreach ($rows as $row) {
        $row['name'] = !empty($row['name_encrypted']) ? Security::decryptData($row['name_encrypted']) : '';
        $row['name_pt_br'] = !empty($row['name_pt_br_encrypted']) ? Security::decryptData($row['name_pt_br_encrypted']) : null;
        $row['ativo'] = (int)$row['ativo'];
        $row['id_tag'] = (int)$row['id_tag'];
        $row['id'] = (int)$row['id'];
        $row['subjects_count'] = (int)($row['subjects_count'] ?? 0);
        unset($row['name_encrypted'], $row['name_pt_br_encrypted']);
        $parsed[] = $row;
    }
    echo json_encode(['status' => 'success', 'data' => $parsed]);
}

elseif ($action === 'toggle_saved_filter') {
    $tag_id = (int)($input['tag_id'] ?? 0);
    if ($tag_id <= 0) die(json_encode(['status' => 'error', 'message' => 'Tag inválida.']));
    $checkTag = $pdo->prepare("SELECT id FROM flashcard_tags WHERE id = ? AND user_id IN (?, 5) LIMIT 1");
    $checkTag->execute([$tag_id, $user_id]);
    if (!$checkTag->fetchColumn()) die(json_encode(['status' => 'error', 'message' => 'Tag não encontrada.']));

    $find = $pdo->prepare("SELECT id, ativo FROM filtros WHERE id_user = ? AND id_tag = ? LIMIT 1");
    $find->execute([$user_id, $tag_id]);
    $existing = $find->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $nextAtivo = (int)$existing['ativo'] === 1 ? 0 : 1;
        $upd = $pdo->prepare("UPDATE filtros SET ativo = ? WHERE id = ?");
        $upd->execute([$nextAtivo, (int)$existing['id']]);
        echo json_encode(['status' => 'success', 'message' => $nextAtivo ? 'Tag salva.' : 'Tag des-salva.', 'saved' => $nextAtivo === 1, 'ativo' => $nextAtivo]);
    } else {
        $ins = $pdo->prepare("INSERT INTO filtros (id_user, id_tag, ativo) VALUES (?, ?, 1)");
        $ins->execute([$user_id, $tag_id]);
        echo json_encode(['status' => 'success', 'message' => 'Tag salva.', 'saved' => true, 'ativo' => 1]);
    }
}

elseif ($action === 'set_saved_filter_active') {
    $tag_id = (int)($input['tag_id'] ?? 0);
    $ativo = (int)($input['ativo'] ?? 0) === 1 ? 1 : 0;
    if ($tag_id <= 0) die(json_encode(['status' => 'error', 'message' => 'Tag inválida.']));
    $stmt = $pdo->prepare("UPDATE filtros SET ativo = ? WHERE id_user = ? AND id_tag = ?");
    $stmt->execute([$ativo, $user_id, $tag_id]);
    if ($stmt->rowCount() === 0) {
        die(json_encode(['status' => 'error', 'message' => 'Tag não está salva para este usuário.']));
    }
    echo json_encode(['status' => 'success', 'message' => 'Status atualizado.', 'ativo' => $ativo]);
}

elseif ($action === 'remove_saved_filter') {
    $tag_id = (int)($input['tag_id'] ?? 0);
    if ($tag_id <= 0) die(json_encode(['status' => 'error', 'message' => 'Tag inválida.']));
    $stmt = $pdo->prepare("DELETE FROM filtros WHERE id_user = ? AND id_tag = ?");
    $stmt->execute([$user_id, $tag_id]);
    echo json_encode(['status' => 'success', 'message' => 'Tag desfixada.']);
}

elseif ($action === 'create_tag') {
    $name = trim((string)($input['name'] ?? ''));
    $name_pt_br = trim((string)($input['name_pt_br'] ?? ''));
    $numero = normalizeNullableTagMetadataText($input['numero'] ?? null);
    $siglaSimbolo = normalizeNullableTagMetadataText($input['sigla_simbolo'] ?? null);
    $is_book = !empty($input['is_book']) ? 1 : 0;
    $is_verb_tense = !empty($input['is_verb_tense']) ? 1 : 0;
    $is_sentence_type = !empty($input['is_sentence_type']) ? 1 : 0;
    $is_lexical_chunk = !empty($input['is_lexical_chunk']) ? 1 : 0;
    $is_relation_type = !empty($input['is_relation_type']) ? 1 : 0;
    $is_word = !empty($input['is_word']) ? 1 : 0;
    $is_month = !empty($input['is_month']) ? 1 : 0;
    $is_day = !empty($input['is_day']) ? 1 : 0;
    $is_year = !empty($input['is_year']) ? 1 : 0;
    if ($name === '') die(json_encode(['status' => 'error', 'message' => 'Nome da tag é obrigatório.']));
    if ($name_pt_br === '') $name_pt_br = null;
    if (tagCombinationAlreadyExists($pdo, $user_id, $name, $name_pt_br, $numero, $siglaSimbolo)) {
        die(json_encode(['status' => 'error', 'message' => 'Já existe uma tag com essa combinação de nome, nome pt-br e número.']));
    }

    $color = resolveTagColorByCategory([
        'is_book' => $is_book,
        'is_verb_tense' => $is_verb_tense,
        'is_sentence_type' => $is_sentence_type,
        'is_lexical_chunk' => $is_lexical_chunk,
        'is_relation_type' => $is_relation_type,
        'is_word' => $is_word,
        'is_month' => $is_month,
        'is_day' => $is_day,
        'is_year' => $is_year,
    ]);

    $name_enc = Security::encryptData($name);
    $name_pt_br_enc = $name_pt_br !== null ? Security::encryptData($name_pt_br) : null;
    $stmt = $pdo->prepare("INSERT INTO flashcard_tags (user_id, created_by_user_id, name_encrypted, name_pt_br_encrypted, numero, sigla_simbolo, color, is_book, is_verb_tense, is_sentence_type, is_lexical_chunk, is_relation_type, is_word, is_month, is_day, is_year) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $tagId = 0;
    try {
        $pdo->beginTransaction();
        $stmt->execute([$user_id, $user_id, $name_enc, $name_pt_br_enc, $numero, $siglaSimbolo, $color, $is_book, $is_verb_tense, $is_sentence_type, $is_lexical_chunk, $is_relation_type, $is_word, $is_month, $is_day, $is_year]);
        $tagId = (int)$pdo->lastInsertId();
        executeTagCreationCustomRules($pdo, $user_id, $tagId);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($tagId <= 0) {
            die(json_encode(['status' => 'error', 'message' => 'Já existe uma tag com esse nome.']));
        }
        error_log('[flashcards][create_tag_custom_rule] ' . $e->getMessage());
        die(json_encode(['status' => 'error', 'message' => 'Erro ao criar relações automáticas da tag.']));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[flashcards][create_tag_custom_rule] ' . $e->getMessage());
        die(json_encode(['status' => 'error', 'message' => 'Erro ao criar relações automáticas da tag.']));
    }
    echo json_encode(['status' => 'success', 'message' => 'Tag criada com sucesso.', 'tag_id' => $tagId]);
}

elseif ($action === 'update_tag') {
    $tag_id = (int)($input['id'] ?? 0);
    $name = trim((string)($input['name'] ?? ''));
    $name_pt_br = trim((string)($input['name_pt_br'] ?? ''));
    $numero = normalizeNullableTagMetadataText($input['numero'] ?? null);
    $siglaSimbolo = normalizeNullableTagMetadataText($input['sigla_simbolo'] ?? null);
    if (!array_key_exists('sigla_simbolo', $input) && $tag_id > 0) {
        $currentMetaStmt = $pdo->prepare('SELECT sigla_simbolo FROM flashcard_tags WHERE id = ? AND (user_id = ? OR created_by_user_id = ?) LIMIT 1');
        $currentMetaStmt->execute([$tag_id, $user_id, $user_id]);
        $siglaSimbolo = normalizeNullableTagMetadataText($currentMetaStmt->fetchColumn() ?: null);
    }
    $name = preg_replace('/\s+/u', ' ', $name);
    $name_pt_br = preg_replace('/\s+/u', ' ', $name_pt_br);
    $is_book = !empty($input['is_book']) ? 1 : 0;
    $is_verb_tense = !empty($input['is_verb_tense']) ? 1 : 0;
    $is_sentence_type = !empty($input['is_sentence_type']) ? 1 : 0;
    $is_lexical_chunk = !empty($input['is_lexical_chunk']) ? 1 : 0;
    $is_relation_type = !empty($input['is_relation_type']) ? 1 : 0;
    $is_word = !empty($input['is_word']) ? 1 : 0;
    $is_month = !empty($input['is_month']) ? 1 : 0;
    $is_day = !empty($input['is_day']) ? 1 : 0;
    $is_year = !empty($input['is_year']) ? 1 : 0;

    if ($tag_id <= 0 || $name === '') {
        die(json_encode(['status' => 'error', 'message' => 'Dados da tag inválidos.']));
    }
    if ($name_pt_br === '') $name_pt_br = null;
    if (tagCombinationAlreadyExists($pdo, $user_id, $name, $name_pt_br, $numero, $siglaSimbolo, $tag_id)) {
        die(json_encode(['status' => 'error', 'message' => 'Já existe uma tag com essa combinação de nome, nome pt-br e número.']));
    }

    $name_enc = Security::encryptData($name);
    $name_pt_br_enc = $name_pt_br !== null ? Security::encryptData($name_pt_br) : null;
    $color = resolveTagColorByCategory([
        'is_book' => $is_book,
        'is_verb_tense' => $is_verb_tense,
        'is_sentence_type' => $is_sentence_type,
        'is_lexical_chunk' => $is_lexical_chunk,
        'is_relation_type' => $is_relation_type,
        'is_word' => $is_word,
        'is_month' => $is_month,
        'is_day' => $is_day,
        'is_year' => $is_year
    ]);
    $stmt = $pdo->prepare("UPDATE flashcard_tags SET name_encrypted = ?, name_pt_br_encrypted = ?, numero = ?, sigla_simbolo = ?, color = ?, is_book = ?, is_verb_tense = ?, is_sentence_type = ?, is_lexical_chunk = ?, is_relation_type = ?, is_word = ?, is_month = ?, is_day = ?, is_year = ? WHERE id = ? AND (user_id = ? OR created_by_user_id = ?)");
    try {
        $stmt->execute([$name_enc, $name_pt_br_enc, $numero, $siglaSimbolo, $color, $is_book, $is_verb_tense, $is_sentence_type, $is_lexical_chunk, $is_relation_type, $is_word, $is_month, $is_day, $is_year, $tag_id, $user_id, $user_id]);
    } catch (PDOException $e) {
        die(json_encode(['status' => 'error', 'message' => 'Já existe uma tag com esse nome.']));
    }

    if ($stmt->rowCount() === 0) {
        $checkStmt = $pdo->prepare("SELECT id FROM flashcard_tags WHERE id = ? AND (user_id = ? OR created_by_user_id = ?) LIMIT 1");
        $checkStmt->execute([$tag_id, $user_id, $user_id]);
        if (!$checkStmt->fetchColumn()) {
            die(json_encode(['status' => 'error', 'message' => 'Tag não encontrada.']));
        }
    }

    echo json_encode(['status' => 'success', 'message' => 'Tag atualizada com sucesso.']);
}

elseif ($action === 'toggle_tag_public_visibility') {
    $tag_id = (int)($input['id'] ?? 0);
    if ($tag_id <= 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID da tag inválido.']));
    }

    try {
        $pdo->beginTransaction();
        $checkStmt = $pdo->prepare("SELECT id, user_id, created_by_user_id FROM flashcard_tags WHERE id = ? LIMIT 1 FOR UPDATE");
        $checkStmt->execute([$tag_id]);
        $tag = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$tag) {
            $pdo->rollBack();
            die(json_encode(['status' => 'error', 'message' => 'Tag não encontrada.']));
        }

        $creatorId = (int)($tag['created_by_user_id'] ?: $tag['user_id']);
        if ($creatorId !== (int)$user_id) {
            $pdo->rollBack();
            die(json_encode(['status' => 'error', 'message' => 'Você não tem permissão para alterar a visibilidade desta tag.']));
        }

        $currentOwnerId = (int)$tag['user_id'];
        $nextOwnerId = $currentOwnerId === 5 ? $creatorId : 5;
        $message = $nextOwnerId === 5 ? 'Tag tornada pública.' : 'Tag tornada privada.';
        $update = $pdo->prepare("UPDATE flashcard_tags SET user_id = ?, created_by_user_id = ? WHERE id = ? LIMIT 1");
        $update->execute([$nextOwnerId, $creatorId, $tag_id]);
        $pdo->commit();

        echo json_encode([
            'status' => 'success',
            'message' => $message,
            'user_id' => $nextOwnerId,
            'created_by_user_id' => $creatorId,
            'is_public' => $nextOwnerId === 5,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[flashcards][toggle_tag_public_visibility] ' . $e->getMessage());
        die(json_encode(['status' => 'error', 'message' => 'Erro interno ao alterar visibilidade da tag.']));
    }
}


elseif ($action === 'set_tags_public_visibility') {
    $tagIds = array_values(array_unique(array_filter(array_map('intval', (array)($input['ids'] ?? [])), fn($id) => $id > 0)));
    $makePublic = !empty($input['is_public']);
    if (!$tagIds) {
        die(json_encode(['status' => 'error', 'message' => 'Nenhuma tag válida foi selecionada.']));
    }

    try {
        $pdo->beginTransaction();
        $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
        $checkStmt = $pdo->prepare("SELECT id, user_id, created_by_user_id FROM flashcard_tags WHERE id IN ($placeholders) FOR UPDATE");
        $checkStmt->execute($tagIds);
        $tags = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($tags) !== count($tagIds)) {
            $pdo->rollBack();
            die(json_encode(['status' => 'error', 'message' => 'Uma ou mais tags selecionadas não foram encontradas.']));
        }

        $update = $pdo->prepare('UPDATE flashcard_tags SET user_id = ?, created_by_user_id = ? WHERE id = ? LIMIT 1');
        foreach ($tags as $tag) {
            $creatorId = (int)($tag['created_by_user_id'] ?: $tag['user_id']);
            if ($creatorId !== (int)$user_id) {
                $pdo->rollBack();
                die(json_encode(['status' => 'error', 'message' => 'Apenas o dono da tag pode alterar estas configurações.']));
            }
            $nextOwnerId = $makePublic ? 5 : $creatorId;
            $update->execute([$nextOwnerId, $creatorId, (int)$tag['id']]);
        }
        $pdo->commit();

        echo json_encode([
            'status' => 'success',
            'message' => count($tagIds) === 1
                ? ($makePublic ? 'Tag tornada pública.' : 'Tag tornada privada.')
                : ($makePublic ? 'Tags tornadas públicas.' : 'Tags tornadas privadas.'),
            'updated_ids' => $tagIds,
            'is_public' => $makePublic,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[flashcards][set_tags_public_visibility] ' . $e->getMessage());
        die(json_encode(['status' => 'error', 'message' => 'Erro interno ao alterar visibilidade das tags.']));
    }
}


elseif ($action === 'toggle_selected_owned_tags_visibility') {
    $tagIds = array_values(array_unique(array_filter(array_map('intval', (array)($input['ids'] ?? [])), fn($id) => $id > 0)));
    if (!$tagIds) {
        die(json_encode(['status' => 'error', 'message' => 'Nenhuma tag válida foi selecionada.']));
    }

    try {
        $pdo->beginTransaction();
        $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
        $checkStmt = $pdo->prepare("SELECT id, user_id, created_by_user_id FROM flashcard_tags WHERE id IN ($placeholders) FOR UPDATE");
        $checkStmt->execute($tagIds);
        $tags = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$tags) {
            $pdo->rollBack();
            die(json_encode(['status' => 'error', 'message' => 'Nenhuma tag selecionada foi encontrada.']));
        }

        $update = $pdo->prepare('UPDATE flashcard_tags SET user_id = ?, created_by_user_id = ? WHERE id = ? LIMIT 1');
        $updatedTags = [];
        foreach ($tags as $tag) {
            $creatorId = (int)($tag['created_by_user_id'] ?: $tag['user_id']);
            if ($creatorId !== (int)$user_id) continue;

            $currentOwnerId = (int)$tag['user_id'];
            $nextOwnerId = $currentOwnerId === 5 ? $creatorId : 5;
            $update->execute([$nextOwnerId, $creatorId, (int)$tag['id']]);
            $updatedTags[] = [
                'id' => (int)$tag['id'],
                'user_id' => $nextOwnerId,
                'created_by_user_id' => $creatorId,
                'is_public' => $nextOwnerId === 5,
            ];
        }

        if (!$updatedTags) {
            $pdo->rollBack();
            die(json_encode(['status' => 'error', 'message' => 'Você não é dono de nenhuma das tags selecionadas.']));
        }

        $pdo->commit();
        echo json_encode([
            'status' => 'success',
            'message' => count($updatedTags) === 1 ? 'Visibilidade da tag alterada.' : 'Visibilidade das tags alterada.',
            'updated_tags' => $updatedTags,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[flashcards][toggle_selected_owned_tags_visibility] ' . $e->getMessage());
        die(json_encode(['status' => 'error', 'message' => 'Erro interno ao alterar visibilidade das tags.']));
    }
}

elseif ($action === 'delete_tag') {
    $tag_id = (int)($input['id'] ?? 0);

    if ($tag_id <= 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID da tag inválido.']));
    }

    try {
        $pdo->beginTransaction();

        $checkStmt = $pdo->prepare("SELECT id, user_id FROM flashcard_tags WHERE id = ? LIMIT 1 FOR UPDATE");
        $checkStmt->execute([$tag_id]);
        $tag = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$tag) {
            $pdo->rollBack();
            die(json_encode(['status' => 'error', 'message' => 'Tag não encontrada.']));
        }
        if ((int)$tag['user_id'] !== (int)$user_id) {
            $pdo->rollBack();
            die(json_encode(['status' => 'error', 'message' => 'Você não tem permissão para excluir esta tag.']));
        }

        $orphanCheck = findSubjectCardIdsOrphanedByTagDeletion($pdo, $tag_id, (int)$user_id);
        if ((int)$orphanCheck['count'] > 0) {
            $pdo->rollBack();
            die(json_encode([
                'status' => 'error',
                'message' => 'Esta tag não pode ser excluída porque deixaria cards sem nenhuma tag em subjects_links. Adicione outra tag de subject a esses cards antes de excluir.',
                'orphan_cards_count' => (int)$orphanCheck['count'],
                'orphan_card_ids' => $orphanCheck['sample_ids'],
            ]));
        }

        $stmt = $pdo->prepare("DELETE FROM flashcard_tags WHERE id = ? AND user_id = ?");
        $stmt->execute([$tag_id, $user_id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[flashcards][delete_tag] ' . $e->getMessage());
        die(json_encode(['status' => 'error', 'message' => 'Erro interno ao excluir tag.']));
    }

    echo json_encode(['status' => 'success', 'message' => 'Tag excluída com sucesso.']);
}




elseif ($action === 'list_relation_types') {
    ensureRelationTypeEncryptedNameCapacity($pdo);

    $stmt = $pdo->prepare("SELECT id, id_user, nome, hierarquia FROM tipos_de_relacoes WHERE id_user IN (?, 5) ORDER BY id_user = 5 ASC, id ASC");
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $merged = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) continue;
        if (!isset($merged[$id])) {
            $nomeRaw = (string)($row['nome'] ?? '');
            $nomeDec = $nomeRaw !== '' ? Security::decryptData($nomeRaw) : '';
            $merged[$id] = [
                'id' => $id,
                'nome' => $nomeDec !== false ? (string)$nomeDec : (looksLikeEncryptedRelationTypeName($nomeRaw) ? '' : $nomeRaw),
                'user_id' => (int)($row['id_user'] ?? 0),
                'hierarquia' => (int)($row['hierarquia'] ?? 0)
            ];
        }
    }

    echo json_encode(['status' => 'success', 'data' => array_values($merged)]);
}


elseif ($action === 'create_custom_rule') {
    $numeroDaRegra = (int)($input['numero_da_regra'] ?? 0);
    $idTipoDeRelacao = (int)($input['id_tipo_de_relacao'] ?? 0);
    $parameterParentTagIds = sanitizeTagIds($input['parameter_parent_tag_ids'] ?? ($input['parameter_tag_ids'] ?? []));
    $parameterChildTagIds = sanitizeTagIds($input['parameter_child_tag_ids'] ?? []);
    $relatedParentTagIds = sanitizeTagIds($input['related_parent_tag_ids'] ?? ($input['related_tag_ids'] ?? []));
    $relatedChildTagIds = sanitizeTagIds($input['related_child_tag_ids'] ?? []);
    $allTagIds = array_values(array_unique(array_merge(
        $parameterParentTagIds,
        $parameterChildTagIds,
        $relatedParentTagIds,
        $relatedChildTagIds
    )));

    if ($numeroDaRegra <= 0) {
        http_response_code(422);
        die(json_encode(['status' => 'error', 'message' => 'Selecione uma regra válida.']));
    }
    if ($idTipoDeRelacao <= 0) {
        http_response_code(422);
        die(json_encode(['status' => 'error', 'message' => 'Selecione um tipo de relação válido.']));
    }
    if (!$allTagIds) {
        http_response_code(422);
        die(json_encode(['status' => 'error', 'message' => 'Selecione ao menos uma tag.']));
    }

    $relationTypeStmt = $pdo->prepare("SELECT id FROM tipos_de_relacoes WHERE id = ? AND id_user IN (?, 5) LIMIT 1");
    $relationTypeStmt->execute([$idTipoDeRelacao, $user_id]);
    if (!$relationTypeStmt->fetchColumn()) {
        http_response_code(403);
        die(json_encode(['status' => 'error', 'message' => 'Tipo de relação indisponível para este usuário.']));
    }

    $tagPlaceholders = implode(',', array_fill(0, count($allTagIds), '?'));
    $tagStmt = $pdo->prepare("SELECT id FROM flashcard_tags WHERE id IN ($tagPlaceholders) AND user_id IN (?, 5)");
    $tagStmt->execute(array_merge($allTagIds, [$user_id]));
    $allowedTagIds = array_map('intval', $tagStmt->fetchAll(PDO::FETCH_COLUMN));
    $missingTagIds = array_diff($allTagIds, $allowedTagIds);
    if ($missingTagIds) {
        http_response_code(403);
        die(json_encode(['status' => 'error', 'message' => 'Uma ou mais tags selecionadas não estão disponíveis para este usuário.']));
    }

    try {
        $pdo->beginTransaction();

        $ruleStmt = $pdo->prepare("INSERT INTO regras_customizadas (id_user, numero_da_regra) VALUES (?, ?)");
        $ruleStmt->execute([$user_id, $numeroDaRegra]);
        $customRuleId = (int)$pdo->lastInsertId();

        $ruleTagStmt = $pdo->prepare("INSERT INTO regras_tags (id_regra, id_tag, id_tipo_de_relacao, destino, parentesco) VALUES (?, ?, ?, ?, ?)");
        $insertRuleTags = static function (array $tagIds, int $destino, int $parentesco) use ($ruleTagStmt, $customRuleId, $idTipoDeRelacao): void {
            foreach ($tagIds as $tagId) {
                $ruleTagStmt->execute([$customRuleId, $tagId, $idTipoDeRelacao, $destino, $parentesco]);
            }
        };

        $insertRuleTags($parameterParentTagIds, 0, 0);
        $insertRuleTags($parameterChildTagIds, 0, 1);
        $insertRuleTags($relatedParentTagIds, 1, 0);
        $insertRuleTags($relatedChildTagIds, 1, 1);

        $pdo->commit();
        echo json_encode([
            'status' => 'success',
            'message' => 'Regra criada com sucesso.',
            'data' => [
                'id' => $customRuleId,
                'numero_da_regra' => $numeroDaRegra,
                'id_tipo_de_relacao' => $idTipoDeRelacao,
                'parameter_parent_tag_ids' => $parameterParentTagIds,
                'parameter_child_tag_ids' => $parameterChildTagIds,
                'related_parent_tag_ids' => $relatedParentTagIds,
                'related_child_tag_ids' => $relatedChildTagIds
            ]
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[flashcards][create_custom_rule] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Erro ao criar regra customizada.']);
    }
}


elseif ($action === 'get_user_system_deck') {
    $stmt = $pdo->prepare("
        SELECT id
        FROM directories
        WHERE user_id = ?
          AND type = 4
          AND deck_system = 1
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $deck_id = (int)($stmt->fetchColumn() ?: 0);

    if ($deck_id <= 0) {
        die(json_encode(['status' => 'error', 'message' => 'Deck de sistema não encontrado para este usuário.']));
    }

    echo json_encode(['status' => 'success', 'deck_id' => $deck_id, 'data' => ['deck_id' => $deck_id]]);
}

elseif ($action === 'create_relation_type') {
    ensureRelationTypeEncryptedNameCapacity($pdo);

    $nome = trim((string)($input['nome'] ?? ''));
    $hierarquia = (int)($input['hierarquia'] ?? -1);

    if ($nome === '') die(json_encode(['status'=>'error','message'=>'Nome é obrigatório.']));
    if (!in_array($hierarquia, [0,1,2,3,4], true)) die(json_encode(['status'=>'error','message'=>'Hierarquia inválida.']));

    $nomeEnc = Security::encryptData($nome);
    $stmt = $pdo->prepare("INSERT INTO tipos_de_relacoes (id_user, nome, hierarquia) VALUES (?, ?, ?)");
    $stmt->execute([$user_id, $nomeEnc, $hierarquia]);

    $relationTypeId = (int)$pdo->lastInsertId();
    $stmtCheck = $pdo->prepare("SELECT nome FROM tipos_de_relacoes WHERE id = ? AND id_user = ? LIMIT 1");
    $stmtCheck->execute([$relationTypeId, $user_id]);
    $storedNome = (string)($stmtCheck->fetchColumn() ?: '');
    if (Security::decryptData($storedNome) !== $nome) {
        $stmtCleanup = $pdo->prepare("DELETE FROM tipos_de_relacoes WHERE id = ? AND id_user = ? LIMIT 1");
        $stmtCleanup->execute([$relationTypeId, $user_id]);
        http_response_code(500);
        die(json_encode(['status'=>'error','message'=>'Não foi possível salvar o nome criptografado completo do tipo de relação. Verifique o tamanho da coluna tipo_de_relacao.nome.']));
    }

    echo json_encode(['status'=>'success','message'=>'Tipo de relação criado com sucesso.', 'id'=>$relationTypeId]);
}

elseif ($action === 'update_relation_type') {
    ensureRelationTypeEncryptedNameCapacity($pdo);

    $relationTypeId = (int)($input['id'] ?? 0);
    $nome = trim((string)($input['nome'] ?? ''));
    $nome = preg_replace('/\s+/u', ' ', $nome);

    if ($relationTypeId <= 0) die(json_encode(['status'=>'error','message'=>'Sessão inválida.']));
    if ($nome === '') die(json_encode(['status'=>'error','message'=>'Nome é obrigatório.']));

    $nomeEnc = Security::encryptData($nome);
    $stmt = $pdo->prepare("UPDATE tipos_de_relacoes SET nome = ? WHERE id = ? AND id_user = ?");
    $stmt->execute([$nomeEnc, $relationTypeId, $user_id]);

    if ($stmt->rowCount() === 0) {
        $checkStmt = $pdo->prepare("SELECT id FROM tipos_de_relacoes WHERE id = ? AND id_user = ? LIMIT 1");
        $checkStmt->execute([$relationTypeId, $user_id]);
        if (!$checkStmt->fetchColumn()) {
            die(json_encode(['status'=>'error','message'=>'Sessão não encontrada ou sem permissão.']));
        }
    }

    $stmtCheck = $pdo->prepare("SELECT nome FROM tipos_de_relacoes WHERE id = ? AND id_user = ? LIMIT 1");
    $stmtCheck->execute([$relationTypeId, $user_id]);
    $storedNome = (string)($stmtCheck->fetchColumn() ?: '');
    if (Security::decryptData($storedNome) !== $nome) {
        http_response_code(500);
        die(json_encode(['status'=>'error','message'=>'Não foi possível salvar o nome criptografado completo da sessão. Verifique o tamanho da coluna tipo_de_relacao.nome.']));
    }

    echo json_encode(['status'=>'success','message'=>'Sessão atualizada com sucesso.']);
}

elseif ($action === 'delete_relation_type') {
    $relationTypeId = (int)($input['id'] ?? 0);
    if ($relationTypeId <= 0) die(json_encode(['status'=>'error','message'=>'Sessão inválida.']));

    try {
        $pdo->beginTransaction();

        $checkStmt = $pdo->prepare("SELECT id FROM tipos_de_relacoes WHERE id = ? AND id_user = ? LIMIT 1 FOR UPDATE");
        $checkStmt->execute([$relationTypeId, $user_id]);
        if (!$checkStmt->fetchColumn()) {
            $pdo->rollBack();
            die(json_encode(['status'=>'error','message'=>'Sessão não encontrada ou sem permissão.']));
        }

        $deleteRelations = $pdo->prepare("DELETE FROM relacoes_taguineas WHERE id_user = ? AND tipo_de_relacao = ?");
        $deleteRelations->execute([$user_id, $relationTypeId]);

        $deleteType = $pdo->prepare("DELETE FROM tipos_de_relacoes WHERE id = ? AND id_user = ? LIMIT 1");
        $deleteType->execute([$relationTypeId, $user_id]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[flashcards][delete_relation_type] ' . $e->getMessage());
        die(json_encode(['status'=>'error','message'=>'Erro interno ao excluir sessão.']));
    }

    echo json_encode(['status'=>'success','message'=>'Sessão excluída com sucesso.']);
}

elseif ($action === 'get_tag_family') {
    $tag_id = (int)($input['tag_id'] ?? ($_GET['tag_id'] ?? 0));
    if ($tag_id <= 0) die(json_encode(['status'=>'error','message'=>'Tag inválida.']));
    $check = $pdo->prepare("SELECT id FROM flashcard_tags WHERE id = ? AND user_id IN (?, 5) LIMIT 1");
    $check->execute([$tag_id, $user_id]);
    if (!$check->fetchColumn()) die(json_encode(['status'=>'error','message'=>'Sem permissão para esta tag.']));

    $stmtRelationTypes = $pdo->prepare("SELECT id, hierarquia FROM tipos_de_relacoes WHERE id_user IN (?, 5)");
    $stmtRelationTypes->execute([$user_id]);
    $relationTypeHierarchy = [];
    foreach ($stmtRelationTypes->fetchAll(PDO::FETCH_ASSOC) as $typeRow) {
        $typeId = (int)($typeRow['id'] ?? 0);
        if ($typeId <= 0 || isset($relationTypeHierarchy[$typeId])) continue;
        $relationTypeHierarchy[$typeId] = (int)($typeRow['hierarquia'] ?? 0);
    }

    $stmtChildren = $pdo->prepare("SELECT t.id, t.user_id, t.name_encrypted, t.name_pt_br_encrypted, t.numero, t.sigla_simbolo, t.color, tf.tipo_de_relacao, tf.id_user AS family_user_id, tf.ordem FROM relacoes_taguineas tf INNER JOIN flashcard_tags t ON t.id = tf.id_tag_child LEFT JOIN tipos_de_relacoes tr ON tr.id = tf.tipo_de_relacao WHERE tf.id_user IN (?, 5) AND tf.id_tag_mother = ? AND t.user_id IN (?,5) ORDER BY (tf.id_user = ?) DESC, CASE WHEN COALESCE(tr.hierarquia, 0) = 2 THEN tf.ordem ELSE 0 END ASC, t.id ASC");
    $stmtChildren->execute([$user_id, $tag_id, $user_id, $user_id]);
    $children = $stmtChildren->fetchAll(PDO::FETCH_ASSOC);

    $stmtMothers = $pdo->prepare("SELECT t.id, t.user_id, t.name_encrypted, t.name_pt_br_encrypted, t.numero, t.sigla_simbolo, t.color, tf.tipo_de_relacao, tf.id_user AS family_user_id, tf.ordem FROM relacoes_taguineas tf INNER JOIN flashcard_tags t ON t.id = tf.id_tag_mother WHERE tf.id_user IN (?, 5) AND tf.id_tag_child = ? AND t.user_id IN (?,5) ORDER BY (tf.id_user = ?) DESC, t.id ASC");
    $stmtMothers->execute([$user_id, $tag_id, $user_id, $user_id]);
    $mothers = $stmtMothers->fetchAll(PDO::FETCH_ASSOC);

    $hierarquiaTresTypes = array_keys(array_filter($relationTypeHierarchy, function($hierarquia){ return (int)$hierarquia === 3; }));
    if (!empty($hierarquiaTresTypes)) {
        $placeholders = implode(',', array_fill(0, count($hierarquiaTresTypes), '?'));
        $graphStmt = $pdo->prepare("SELECT id_tag_child, id_tag_mother, tipo_de_relacao, id_user AS family_user_id, ordem FROM relacoes_taguineas WHERE id_user IN (?, 5) AND tipo_de_relacao IN ($placeholders)");
        $graphStmt->execute(array_merge([$user_id], $hierarquiaTresTypes));
        $graphRows = $graphStmt->fetchAll(PDO::FETCH_ASSOC);

        $adjacency = [];
        foreach ($graphRows as $edge) {
            $type = (int)($edge['tipo_de_relacao'] ?? 0);
            $a = (int)($edge['id_tag_child'] ?? 0);
            $b = (int)($edge['id_tag_mother'] ?? 0);
            if ($type <= 0 || $a <= 0 || $b <= 0 || $a === $b) continue;
            if (!isset($adjacency[$type])) $adjacency[$type] = [];
            if (!isset($adjacency[$type][$a])) $adjacency[$type][$a] = [];
            if (!isset($adjacency[$type][$b])) $adjacency[$type][$b] = [];
            $adjacency[$type][$a][$b] = true;
            $adjacency[$type][$b][$a] = true;
        }

        foreach ($hierarquiaTresTypes as $relationTypeId) {
            $relationTypeId = (int)$relationTypeId;
            if (!isset($adjacency[$relationTypeId][$tag_id])) continue;
            $visited = [$tag_id => true];
            $queue = [$tag_id];
            while (!empty($queue)) {
                $current = array_shift($queue);
                foreach (array_keys($adjacency[$relationTypeId][$current] ?? []) as $neighbor) {
                    $neighbor = (int)$neighbor;
                    if ($neighbor <= 0 || isset($visited[$neighbor])) continue;
                    $visited[$neighbor] = true;
                    $queue[] = $neighbor;
                }
            }

            $connectedIds = array_values(array_filter(array_map('intval', array_keys($visited)), function($id) use ($tag_id){ return $id !== $tag_id; }));
            if (empty($connectedIds)) continue;

            $idPlaceholders = implode(',', array_fill(0, count($connectedIds), '?'));
            $tagStmt = $pdo->prepare("SELECT id, user_id, name_encrypted, name_pt_br_encrypted, numero, sigla_simbolo, color FROM flashcard_tags WHERE id IN ($idPlaceholders) AND user_id IN (?,5)");
            $tagStmt->execute(array_merge($connectedIds, [$user_id]));
            foreach ($tagStmt->fetchAll(PDO::FETCH_ASSOC) as $connectedTag) {
                $connectedTag['tipo_de_relacao'] = $relationTypeId;
                $children[] = $connectedTag;
            }
        }
    }

    $decode = function(array $rows) {
        $out = [];
        $seen = [];
        foreach ($rows as $tag) {
            $tagId = (int)($tag['id'] ?? 0);
            $typeId = (int)($tag['tipo_de_relacao'] ?? 0);
            $seenKey = $tagId . ':' . $typeId;
            if ($tagId <= 0 || isset($seen[$seenKey])) continue;
            $seen[$seenKey] = true;
            $tag['name'] = !empty($tag['name_encrypted']) ? Security::decryptData($tag['name_encrypted']) : '';
            $tag['name_pt_br'] = !empty($tag['name_pt_br_encrypted']) ? Security::decryptData($tag['name_pt_br_encrypted']) : null;
            unset($tag['name_encrypted'], $tag['name_pt_br_encrypted']);
            $out[] = $tag;
        }
        return $out;
    };
    echo json_encode(['status'=>'success','data'=>['children'=>$decode($children),'mothers'=>$decode($mothers)]]);
}

elseif ($action === 'add_tag_family_relation') {
    $tag_id = (int)($input['tag_id'] ?? 0);
    $other_tag_id = (int)($input['other_tag_id'] ?? 0);
    $mode = (string)($input['mode'] ?? 'child');
    $relation_type = (int)($input['tipo_de_relacao'] ?? 0);
    $typeStmt = $pdo->prepare("SELECT id, hierarquia FROM tipos_de_relacoes WHERE id = ? AND id_user IN (?, 5) LIMIT 1");
    $typeStmt->execute([$relation_type, $user_id]);
    $relationTypeRow = $typeStmt->fetch(PDO::FETCH_ASSOC);
    if (!$relationTypeRow) die(json_encode(['status'=>'error','message'=>'Tipo de relação inválido.']));
    $relation_hierarchy = (int)($relationTypeRow['hierarquia'] ?? 0);
    if ($tag_id <= 0 || $other_tag_id <= 0 || $tag_id === $other_tag_id) die(json_encode(['status'=>'error','message'=>'Relação inválida.']));

    $valid = $pdo->prepare("SELECT id FROM flashcard_tags WHERE id IN (?, ?) AND user_id IN (?, 5)");
    $valid->execute([$tag_id, $other_tag_id, $user_id]);
    if (count($valid->fetchAll(PDO::FETCH_COLUMN)) !== 2) die(json_encode(['status'=>'error','message'=>'Sem permissão para uma das tags.']));

    $child = $mode === 'mother' ? $tag_id : $other_tag_id;
    $mother = $mode === 'mother' ? $other_tag_id : $tag_id;

    if ($relation_hierarchy !== 4) {
        $reverseStmt = $pdo->prepare("SELECT 1 FROM relacoes_taguineas WHERE id_user IN (?, 5) AND id_tag_child = ? AND id_tag_mother = ? AND tipo_de_relacao = ? LIMIT 1");
        $reverseStmt->execute([$user_id, $mother, $child, $relation_type]);
        if ($reverseStmt->fetchColumn()) {
            die(json_encode(['status'=>'error','message'=>'Já existe essa relação invertida para esse tipo de relacionamento.']));
        }
    }

    if ($relation_hierarchy === 3) {
        $graphStmt = $pdo->prepare("SELECT id_tag_child, id_tag_mother FROM relacoes_taguineas WHERE id_user IN (?, 5) AND tipo_de_relacao = ?");
        $graphStmt->execute([$user_id, $relation_type]);
        $adjacency = [];
        foreach ($graphStmt->fetchAll(PDO::FETCH_ASSOC) as $edge) {
            $a = (int)($edge['id_tag_child'] ?? 0);
            $b = (int)($edge['id_tag_mother'] ?? 0);
            if ($a <= 0 || $b <= 0 || $a === $b) continue;
            if (!isset($adjacency[$a])) $adjacency[$a] = [];
            if (!isset($adjacency[$b])) $adjacency[$b] = [];
            $adjacency[$a][$b] = true;
            $adjacency[$b][$a] = true;
        }

        if (isset($adjacency[$child]) && isset($adjacency[$mother])) {
            $visited = [$child => true];
            $queue = [$child];
            while (!empty($queue)) {
                $current = array_shift($queue);
                foreach (array_keys($adjacency[$current] ?? []) as $neighbor) {
                    $neighbor = (int)$neighbor;
                    if ($neighbor <= 0 || isset($visited[$neighbor])) continue;
                    $visited[$neighbor] = true;
                    $queue[] = $neighbor;
                }
            }

            if (isset($visited[$mother])) {
                die(json_encode(['status'=>'error','message'=>'Essas tags já pertencem ao mesmo grupo desse tipo de relacionamento.']));
            }
        }
    }

    $nextOrder = 0;
    if ($relation_hierarchy === 2) {
        $orderStmt = $pdo->prepare("SELECT COALESCE(MAX(ordem), 0) + 1 FROM relacoes_taguineas WHERE id_user = ? AND id_tag_mother = ? AND tipo_de_relacao = ?");
        $orderStmt->execute([$user_id, $mother, $relation_type]);
        $nextOrder = (int)($orderStmt->fetchColumn() ?: 1);
    }

    if ($relation_hierarchy === 4) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT IGNORE INTO relacoes_taguineas (id_user, id_tag_child, id_tag_mother, tipo_de_relacao, ordem) VALUES (?, ?, ?, ?, 0)");
            $stmt->execute([$user_id, $child, $mother, $relation_type]);
            $familyTagIds = fetchTagFamilyConnectedComponent($pdo, $user_id, $relation_type, [$child, $mother]);
            replicateTagFamilyRelations($pdo, $user_id, $relation_type, $familyTagIds);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[flashcards][replicate_tag_family_relations] ' . $e->getMessage());
            die(json_encode(['status'=>'error','message'=>'Erro interno ao replicar família.']));
        }
    } else {
        $stmt = $pdo->prepare("INSERT IGNORE INTO relacoes_taguineas (id_user, id_tag_child, id_tag_mother, tipo_de_relacao, ordem) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $child, $mother, $relation_type, $nextOrder]);
    }
    executeTagFamilyRelationCustomRules($pdo, $user_id, [$child, $mother]);
    $updatedCards = 0;
    if (in_array($relation_type, [19, 21], true)) {
        try {
            $updatedCards = autoUpdateExistingCardsForTagFamilyRelation($pdo, $user_id, $relation_type, $mother);
        } catch (Throwable $e) {
            error_log('[flashcards][auto_update_existing_cards_for_tag_family_relation] ' . $e->getMessage());
            die(json_encode(['status'=>'error','message'=>'Relação salva, mas ocorreu erro ao atualizar cards já existentes.']));
        }
    }
    echo json_encode(['status'=>'success','message'=>'Relação salva com sucesso.','updated_cards'=>$updatedCards]);
}


elseif ($action === 'reorder_tag_family_sequence') {
    $tag_id = (int)($input['tag_id'] ?? 0);
    $relation_type = (int)($input['tipo_de_relacao'] ?? 0);
    $ordered_tag_ids = sanitizeTagIds($input['ordered_tag_ids'] ?? []);
    if ($tag_id <= 0 || $relation_type <= 0 || empty($ordered_tag_ids)) die(json_encode(['status'=>'error','message'=>'Ordem inválida.']));

    $typeStmt = $pdo->prepare("SELECT id FROM tipos_de_relacoes WHERE id = ? AND id_user IN (?, 5) AND hierarquia = 2 LIMIT 1");
    $typeStmt->execute([$relation_type, $user_id]);
    if (!$typeStmt->fetchColumn()) die(json_encode(['status'=>'error','message'=>'Esta sessão não é do tipo Sequência.']));

    $placeholders = implode(',', array_fill(0, count($ordered_tag_ids), '?'));
    $currentStmt = $pdo->prepare("SELECT id_tag_child FROM relacoes_taguineas WHERE id_user = ? AND id_tag_mother = ? AND tipo_de_relacao = ? AND id_tag_child IN ($placeholders)");
    $currentStmt->execute(array_merge([$user_id, $tag_id, $relation_type], $ordered_tag_ids));
    $currentIds = array_map('intval', $currentStmt->fetchAll(PDO::FETCH_COLUMN));
    sort($currentIds);
    $expectedIds = $ordered_tag_ids;
    sort($expectedIds);
    if ($currentIds !== $expectedIds) die(json_encode(['status'=>'error','message'=>'A ordem contém tags que não pertencem a esta sequência ou sem permissão.']));

    try {
        $pdo->beginTransaction();
        $updateStmt = $pdo->prepare("UPDATE relacoes_taguineas SET ordem = ? WHERE id_user = ? AND id_tag_mother = ? AND tipo_de_relacao = ? AND id_tag_child = ? LIMIT 1");
        foreach ($ordered_tag_ids as $index => $childId) {
            $updateStmt->execute([$index + 1, $user_id, $tag_id, $relation_type, $childId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[flashcards][reorder_tag_family_sequence] ' . $e->getMessage());
        die(json_encode(['status'=>'error','message'=>'Erro interno ao reordenar sequência.']));
    }

    echo json_encode(['status'=>'success','message'=>'Sequência reordenada com sucesso.']);
}


elseif ($action === 'remove_tag_family_relation') {
    $tag_id = (int)($input['tag_id'] ?? 0);
    $other_tag_id = (int)($input['other_tag_id'] ?? 0);
    $relation_type = (string)($input['relation_type'] ?? 'child');
    if ($tag_id <= 0 || $other_tag_id <= 0 || $tag_id === $other_tag_id) die(json_encode(['status'=>'error','message'=>'Relação inválida.']));

    $child = $relation_type === 'mother' ? $tag_id : $other_tag_id;
    $mother = $relation_type === 'mother' ? $other_tag_id : $tag_id;

    $stmt = $pdo->prepare("DELETE FROM relacoes_taguineas WHERE id_user = ? AND id_tag_child = ? AND id_tag_mother = ? AND tipo_de_relacao = ? LIMIT 1");
    $stmt->execute([$user_id, $child, $mother, (int)($input['tipo_de_relacao'] ?? 0)]);
    echo json_encode(['status'=>'success','message'=>'Relação removida com sucesso.']);
}

elseif ($action === 'create_batch_generation') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    if ($deck_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do deck inválido.']));

    $deck = verifyDeckOwnership($pdo, $deck_id, $user_id);
    if (!$deck) die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));
    if (OPENAI_API_KEY === '') die(json_encode(['status' => 'error', 'message' => 'OPENAI_API_KEY não configurada no .env.']));

    $deck_name = Security::decryptData($deck['name_encrypted']);
    $topic_input = trim((string)($input['topic'] ?? ''));
    if ($topic_input !== '') {
        $deck_name = function_exists('mb_substr') ? mb_substr($topic_input, 0, 200) : substr($topic_input, 0, 200);
    }
    $deck_structure = normalizeDeckStructure($deck['deck_structure'] ?? 'traducoes', 'traducoes');
    $custom_base_prompt = normalizeGenerationBasePromptInput($input['base_prompt'] ?? '');
    if ($custom_base_prompt !== '') {
        $savePromptStmt = $pdo->prepare("UPDATE directories SET deck_generation_base_prompt = ? WHERE id = ? AND user_id = ? LIMIT 1");
        $savePromptStmt->execute([$custom_base_prompt, $deck_id, $user_id]);
    }
    $historyText = $deck_structure === 'parafrases' ? '' : fetchDeckHistoryText($pdo, $deck_id);
    $chatPayload = buildFlashcardsGenerationPayload($deck_name, $deck_structure, $historyText, 'gpt-5.4', $custom_base_prompt);

    $jsonlLine = json_encode([
        'custom_id' => 'deck_' . $deck_id . '_user_' . $user_id . '_' . time(),
        'method' => 'POST',
        'url' => '/v1/chat/completions',
        'body' => $chatPayload
    ], JSON_UNESCAPED_UNICODE);

    if ($jsonlLine === false) {
        die(json_encode(['status' => 'error', 'message' => 'Falha ao montar payload JSONL.']));
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'gluon_batch_');
    file_put_contents($tmpFile, $jsonlLine . "
");

    $ch = curl_init('https://api.openai.com/v1/files');
    $postFields = [
        'purpose' => 'batch',
        'file' => new CURLFile($tmpFile, 'application/jsonl', 'flashcards_batch.jsonl')
    ];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . OPENAI_API_KEY]);
    $uploadResponse = curl_exec($ch);
    $uploadCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $uploadErr = curl_error($ch);
    curl_close($ch);
    @unlink($tmpFile);

    if ($uploadCode !== 200 || !$uploadResponse) {
        $details = trim($uploadErr);
        $decodedErr = json_decode((string)$uploadResponse, true);
        if (!$details && is_array($decodedErr)) $details = (string)($decodedErr['error']['message'] ?? '');
        die(json_encode(['status' => 'error', 'message' => 'Erro ao enviar arquivo batch para OpenAI.' . ($details ? (' Detalhes: ' . $details) : '')]));
    }

    $uploadDecoded = json_decode($uploadResponse, true);
    $inputFileId = trim((string)($uploadDecoded['id'] ?? ''));
    if ($inputFileId === '') die(json_encode(['status' => 'error', 'message' => 'OpenAI não retornou input_file_id.']));

    list($batchCode, $batchResponse, $batchErr) = openaiJsonRequest('https://api.openai.com/v1/batches', [
        'input_file_id' => $inputFileId,
        'endpoint' => '/v1/chat/completions',
        'completion_window' => '24h',
        'metadata' => [
            'app' => 'gluon',
            'feature' => 'flashcards_batch',
            'user_id' => (string)$user_id,
            'deck_id' => (string)$deck_id
        ]
    ]);

    if ($batchCode !== 200 || !$batchResponse) {
        $details = trim($batchErr);
        $decodedErr = json_decode((string)$batchResponse, true);
        if (!$details && is_array($decodedErr)) $details = (string)($decodedErr['error']['message'] ?? '');
        die(json_encode(['status' => 'error', 'message' => 'Erro ao criar job batch na OpenAI.' . ($details ? (' Detalhes: ' . $details) : '')]));
    }

    $batchDecoded = json_decode($batchResponse, true);
    $openaiBatchId = trim((string)($batchDecoded['id'] ?? ''));
    $status = trim((string)($batchDecoded['status'] ?? 'submitted'));
    if ($openaiBatchId === '') die(json_encode(['status' => 'error', 'message' => 'OpenAI não retornou batch_id.']));

    $stmt = $pdo->prepare("INSERT INTO flashcard_batch_jobs (user_id, directory_id, topic, deck_structure, openai_input_file_id, openai_batch_id, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $deck_id, $topic_input !== '' ? $topic_input : null, $deck_structure, $inputFileId, $openaiBatchId, $status]);
    $jobId = (int)$pdo->lastInsertId();

    echo json_encode([
        'status' => 'success',
        'message' => 'Batch enviado com sucesso para OpenAI.',
        'job' => [
            'id' => $jobId,
            'openai_batch_id' => $openaiBatchId,
            'openai_input_file_id' => $inputFileId,
            'status' => $status
        ]
    ]);
}

elseif ($action === 'list_batch_generations') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    if ($deck_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do deck inválido.']));
    if (!verifyDeckOwnership($pdo, $deck_id, $user_id)) die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));

    $stmt = $pdo->prepare("SELECT id, topic, deck_structure, openai_batch_id, openai_input_file_id, openai_output_file_id, status, error_message, result_cards_json, created_at, updated_at, completed_at FROM flashcard_batch_jobs WHERE user_id = ? AND directory_id = ? ORDER BY id DESC LIMIT 30");
    $stmt->execute([$user_id, $deck_id]);
    $rows = $stmt->fetchAll();

    if (OPENAI_API_KEY !== '') {
        foreach ($rows as $idx => $row) {
            if (!in_array($row['status'], ['submitted', 'validating', 'in_progress', 'finalizing'], true)) continue;
            $synced = syncBatchJobWithOpenAI($pdo, $row);
            if (!$synced['ok'] || empty($synced['job'])) continue;

            $rows[$idx]['status'] = $synced['job']['status'];
            $rows[$idx]['openai_output_file_id'] = $synced['job']['openai_output_file_id'] ?: null;
            $rows[$idx]['error_message'] = $synced['job']['error_message'];
            if ($synced['job']['has_result']) {
                $rows[$idx]['result_cards_json'] = '__HAS_RESULT__';
            }
        }
    }

    $jobs = [];
    foreach ($rows as $r) {
        $jobs[] = [
            'id' => (int)$r['id'],
            'topic' => $r['topic'] ?? '',
            'deck_structure' => $r['deck_structure'],
            'openai_batch_id' => $r['openai_batch_id'],
            'openai_input_file_id' => $r['openai_input_file_id'],
            'openai_output_file_id' => $r['openai_output_file_id'],
            'status' => $r['status'],
            'error_message' => $r['error_message'],
            'has_result' => !empty($r['result_cards_json']),
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
            'completed_at' => $r['completed_at']
        ];
    }

    echo json_encode(['status' => 'success', 'jobs' => $jobs]);
}

elseif ($action === 'delete_batch_generation') {
    $job_id = (int)($input['job_id'] ?? 0);
    if ($job_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do job inválido.']));

    $stmt = $pdo->prepare("SELECT j.id, j.directory_id, d.user_id as owner_id FROM flashcard_batch_jobs j JOIN directories d ON d.id = j.directory_id WHERE j.id = ? LIMIT 1");
    $stmt->execute([$job_id]);
    $job = $stmt->fetch();
    if (!$job || (int)$job['owner_id'] !== (int)$user_id) die(json_encode(['status' => 'error', 'message' => 'Job não encontrado ou sem permissão.']));

    $deleteStmt = $pdo->prepare("DELETE FROM flashcard_batch_jobs WHERE id = ? LIMIT 1");
    if (!$deleteStmt->execute([$job_id])) {
        die(json_encode(['status' => 'error', 'message' => 'Erro ao excluir batch.']));
    }

    echo json_encode(['status' => 'success', 'message' => 'Batch excluído do histórico com sucesso.']);
}

elseif ($action === 'refresh_batch_generation') {
    $job_id = (int)($input['job_id'] ?? 0);
    if ($job_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do job inválido.']));
    if (OPENAI_API_KEY === '') die(json_encode(['status' => 'error', 'message' => 'OPENAI_API_KEY não configurada no .env.']));

    $stmt = $pdo->prepare("SELECT j.*, d.user_id as owner_id FROM flashcard_batch_jobs j JOIN directories d ON d.id = j.directory_id WHERE j.id = ? LIMIT 1");
    $stmt->execute([$job_id]);
    $job = $stmt->fetch();
    if (!$job || (int)$job['owner_id'] !== (int)$user_id) die(json_encode(['status' => 'error', 'message' => 'Job não encontrado ou sem permissão.']));

    $synced = syncBatchJobWithOpenAI($pdo, $job);
    if (!$synced['ok']) {
        die(json_encode(['status' => 'error', 'message' => $synced['error'] ?: 'Falha ao sincronizar batch.']));
    }

    echo json_encode(['status' => 'success', 'job' => $synced['job']]);
}

elseif ($action === 'get_batch_generation_result') {
    $job_id = (int)($input['job_id'] ?? 0);
    if ($job_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do job inválido.']));

    $stmt = $pdo->prepare("SELECT j.result_cards_json, j.status, j.error_message, d.user_id as owner_id FROM flashcard_batch_jobs j JOIN directories d ON d.id = j.directory_id WHERE j.id = ? LIMIT 1");
    $stmt->execute([$job_id]);
    $job = $stmt->fetch();
    if (!$job || (int)$job['owner_id'] !== (int)$user_id) die(json_encode(['status' => 'error', 'message' => 'Job não encontrado ou sem permissão.']));

    $cards = json_decode((string)($job['result_cards_json'] ?? ''), true);
    if (!is_array($cards) || empty($cards)) {
        die(json_encode(['status' => 'error', 'message' => 'Este job ainda não possui resultado pronto. Status atual: ' . ($job['status'] ?? 'desconhecido')]));
    }

    echo json_encode(['status' => 'success', 'cards' => $cards]);
}

elseif ($action === 'generate_cards_preview') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    if ($deck_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do deck inválido.']));

    $deck = verifyDeckOwnership($pdo, $deck_id, $user_id);
    if (!$deck) die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));
    if (OPENAI_API_KEY === '') die(json_encode(['status' => 'error', 'message' => 'OPENAI_API_KEY não configurada no .env.']));

    $deck_name = Security::decryptData($deck['name_encrypted']);
    $topic_input = trim((string)($input['topic'] ?? ''));
    if ($topic_input !== '') {
        $deck_name = function_exists('mb_substr') ? mb_substr($topic_input, 0, 200) : substr($topic_input, 0, 200);
    }
    $deck_structure = normalizeDeckStructure($deck['deck_structure'] ?? 'traducoes', 'traducoes');
    $custom_base_prompt = normalizeGenerationBasePromptInput($input['base_prompt'] ?? '');

    $historyText = $deck_structure === 'parafrases' ? '' : fetchDeckHistoryText($pdo, $deck_id);
    $payloadBase = buildFlashcardsGenerationPayload($deck_name, $deck_structure, $historyText, 'gpt-5.4', $custom_base_prompt);

    $cards = [];
    $openai_debug_response = null;
    $lastErrorMessage = '';

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $payloadData = $payloadBase;
        if ($attempt > 1) {
            $payloadData['messages'][] = [
                'role' => 'user',
                'content' => 'Sua resposta anterior não seguiu o formato esperado. Regere e valide internamente antes de responder. Nunca deixe campos vazios que sejam obrigatórios para esta estrutura.'
            ];
        }

        list($httpcode, $response, $curlError) = openaiJsonRequest('https://api.openai.com/v1/chat/completions', $payloadData);

        if ($httpcode !== 200 || !$response) {
            $apiError = '';
            if (!empty($response)) {
                $errorDecoded = json_decode($response, true);
                $apiError = trim((string)($errorDecoded['error']['message'] ?? ''));
            }
            $details = trim($apiError !== '' ? $apiError : $curlError);
            $lastErrorMessage = 'Erro ao gerar cards com a OpenAI.' . ($details !== '' ? (' Detalhes: ' . $details) : '');
            continue;
        }

        $decoded = json_decode($response, true);
        $openai_debug_response = $decoded;
        $raw = (string)($decoded['choices'][0]['message']['content'] ?? '');
        $cards = sanitizeGeneratedCards($raw, $deck_structure);
        if (!empty($cards)) break;
        $lastErrorMessage = 'A API retornou cards sem preenchimento obrigatório.';
    }

    if (empty($cards)) {
        $message = $lastErrorMessage !== '' ? $lastErrorMessage : 'Não foi possível gerar cards válidos no formato esperado.';
        die(json_encode(['status' => 'error', 'message' => $message]));
    }

    echo json_encode([
        'status' => 'success',
        'mode' => 'realtime',
        'cards' => $cards,
        'debug_openai_response' => $openai_debug_response
    ]);
}

elseif ($action === 'create_generated_cards') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    $cards = $input['cards'] ?? [];
    $batch_job_id = (int)($input['batch_job_id'] ?? 0);

    if ($deck_id === 0 || !is_array($cards) || count($cards) === 0) die(json_encode(['status' => 'error', 'message' => 'Dados inválidos.']));
    if (!verifyDeckOwnership($pdo, $deck_id, $user_id)) die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado.']));

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO flashcards (directory_id, created_by_user_id, private_directory_id, front_encrypted, back_encrypted, has_audio_front, has_audio_back) VALUES (?, ?, ?, ?, ?, 0, 0)");
        $count = 0;
        foreach ($cards as $card) {
            $front = trim((string)($card['front'] ?? ''));
            $back = trim((string)($card['back'] ?? ''));
            if ($front === '') continue;
            $stmt->execute([$deck_id, $user_id, $deck_id, Security::encryptData($front), $back !== '' ? Security::encryptData($back) : null]);
            $count++;
        }

        if ($batch_job_id > 0) {
            $delStmt = $pdo->prepare("DELETE FROM flashcard_batch_jobs WHERE id = ? AND user_id = ? AND directory_id = ? AND result_cards_json IS NOT NULL");
            $delStmt->execute([$batch_job_id, $user_id, $deck_id]);
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => "$count cards criados com sucesso!"]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Erro interno ao criar cards.']);
    }
}


elseif ($action === 'list_noun_tokens') {
    $query = trim((string)($input['query'] ?? ''));

    $stmt = $pdo->prepare("SELECT id, noun_group_id, language_id, gender_id, number_id, noun_form_id, noun_text FROM nouns WHERE id_user = ? ORDER BY id DESC LIMIT 1200");
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $tokens = [];
    foreach ($rows as $row) {
        $value = trim((string)($row['noun_text'] ?? ''));
        if ($value === '') continue;
        if ($query !== '' && stripos($value, $query) === false) continue;

        $tokens[] = [
            'id' => (int)$row['id'],
            'noun_group_id' => (int)($row['noun_group_id'] ?? 0),
            'language_id' => (int)($row['language_id'] ?? 0),
            'gender_id' => (int)($row['gender_id'] ?? 0),
            'number_id' => (int)($row['number_id'] ?? 0),
            'noun_form_id' => (int)($row['noun_form_id'] ?? 0),
            'value' => $value
        ];
    }

    usort($tokens, function ($a, $b) {
        return strcasecmp((string)$a['value'], (string)$b['value']);
    });

    echo json_encode(['status' => 'success', 'tokens' => $tokens]);
}

elseif ($action === 'list_verb_tokens') {
    $query = trim((string)($input['query'] ?? ''));

    $stmt = $pdo->prepare("SELECT id, verb_group_id, language_id, pronoun_id, verb_form_id, verb_text FROM verbs WHERE id_user = ? ORDER BY id DESC LIMIT 1200");
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $tokens = [];
    foreach ($rows as $row) {
        $value = trim((string)($row['verb_text'] ?? ''));
        if ($value === '') continue;
        if ($query !== '' && stripos($value, $query) === false) continue;

        $tokens[] = [
            'id' => (int)$row['id'],
            'verb_group_id' => (int)($row['verb_group_id'] ?? 0),
            'language_id' => (int)($row['language_id'] ?? 0),
            'pronoun_id' => (int)($row['pronoun_id'] ?? 0),
            'verb_form_id' => (int)($row['verb_form_id'] ?? 0),
            'value' => $value
        ];
    }

    usort($tokens, function ($a, $b) {
        return strcasecmp((string)$a['value'], (string)$b['value']);
    });

    echo json_encode(['status' => 'success', 'tokens' => $tokens]);
}

elseif ($action === 'list_adjective_tokens') {
    $query = trim((string)($input['query'] ?? ''));

    $stmt = $pdo->prepare("SELECT id, adjective_group_id, language_id, gender_id, number_id, adjective_form_id, adjective_text FROM adjectives WHERE id_user = ? ORDER BY id DESC LIMIT 1200");
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $tokens = [];
    foreach ($rows as $row) {
        $value = trim((string)($row['adjective_text'] ?? ''));
        if ($value === '') continue;
        if ($query !== '' && stripos($value, $query) === false) continue;

        $tokens[] = [
            'id' => (int)$row['id'],
            'adjective_group_id' => (int)($row['adjective_group_id'] ?? 0),
            'language_id' => (int)($row['language_id'] ?? 0),
            'gender_id' => (int)($row['gender_id'] ?? 0),
            'number_id' => (int)($row['number_id'] ?? 0),
            'adjective_form_id' => (int)($row['adjective_form_id'] ?? 0),
            'value' => $value
        ];
    }

    usort($tokens, function ($a, $b) {
        return strcasecmp((string)$a['value'], (string)$b['value']);
    });

    echo json_encode(['status' => 'success', 'tokens' => $tokens]);
}
elseif ($action === 'list_preposition_tokens') {
    $stmt = $pdo->prepare("SELECT id, preposition_group_id, language_id, preposition_form_id, preposition_text FROM prepositions WHERE id_user = ? ORDER BY id DESC LIMIT 1200");
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $tokens = [];
    foreach ($rows as $row) {
        $value = trim((string)($row['preposition_text'] ?? ''));
        if ($value === '') continue;
        $tokens[] = [
            'id' => (int)$row['id'],
            'preposition_group_id' => (int)($row['preposition_group_id'] ?? 0),
            'language_id' => (int)($row['language_id'] ?? 0),
            'preposition_form_id' => (int)($row['preposition_form_id'] ?? 0),
            'value' => $value
        ];
    }
    usort($tokens, fn($a, $b) => strcasecmp((string)$a['value'], (string)$b['value']));
    echo json_encode(['status' => 'success', 'tokens' => $tokens]);
}
elseif ($action === 'list_conjunction_tokens') {
    $stmt = $pdo->prepare("SELECT id, conjunction_group_id, language_id, conjunction_type_id, conjunction_text FROM conjunctions WHERE id_user = ? ORDER BY id DESC LIMIT 1200");
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $tokens = [];
    foreach ($rows as $row) {
        $value = trim((string)($row['conjunction_text'] ?? ''));
        if ($value === '') continue;
        $tokens[] = [
            'id' => (int)$row['id'],
            'conjunction_group_id' => (int)($row['conjunction_group_id'] ?? 0),
            'language_id' => (int)($row['language_id'] ?? 0),
            'conjunction_type_id' => (int)($row['conjunction_type_id'] ?? 0),
            'value' => $value
        ];
    }
    usort($tokens, fn($a, $b) => strcasecmp((string)$a['value'], (string)$b['value']));
    echo json_encode(['status' => 'success', 'tokens' => $tokens]);
}
elseif ($action === 'list_numeral_tokens') {
    $stmt = $pdo->prepare("SELECT id, numeral_group_id, language_id, gender_id, number_id, numeral_type_id, numeral_value, numeral_text FROM numerals WHERE id_user = ? ORDER BY id DESC LIMIT 1200");
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $tokens = [];
    foreach ($rows as $row) {
        $value = trim((string)($row['numeral_text'] ?? ''));
        if ($value === '') continue;
        $tokens[] = [
            'id' => (int)$row['id'],
            'numeral_group_id' => (int)($row['numeral_group_id'] ?? 0),
            'language_id' => (int)($row['language_id'] ?? 0),
            'gender_id' => (int)($row['gender_id'] ?? 0),
            'number_id' => (int)($row['number_id'] ?? 0),
            'numeral_type_id' => (int)($row['numeral_type_id'] ?? 0),
            'numeral_value' => $row['numeral_value'],
            'value' => $value
        ];
    }
    usort($tokens, fn($a, $b) => strcasecmp((string)$a['value'], (string)$b['value']));
    echo json_encode(['status' => 'success', 'tokens' => $tokens]);
}
elseif ($action === 'list_adverb_tokens') {
    $stmt = $pdo->prepare("SELECT id, adverb_group_id, language_id, adverb_type_id, adverb_form_id, adverb_text FROM adverbs WHERE id_user = ? ORDER BY id DESC LIMIT 1200");
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $tokens = [];
    foreach ($rows as $row) {
        $value = trim((string)($row['adverb_text'] ?? ''));
        if ($value === '') continue;
        $tokens[] = [
            'id' => (int)$row['id'],
            'adverb_group_id' => (int)($row['adverb_group_id'] ?? 0),
            'language_id' => (int)($row['language_id'] ?? 0),
            'adverb_type_id' => (int)($row['adverb_type_id'] ?? 0),
            'adverb_form_id' => (int)($row['adverb_form_id'] ?? 0),
            'value' => $value
        ];
    }
    usort($tokens, fn($a, $b) => strcasecmp((string)$a['value'], (string)$b['value']));
    echo json_encode(['status' => 'success', 'tokens' => $tokens]);
}


elseif ($action === 'add_grammar_entry') {
    $table = trim((string)($input['table'] ?? ''));
    $allowed = [
        'nouns' => ['gender_id','normal_singular_pt_br','normal_singular_en_us','normal_plural_pt_br','normal_plural_en_us','diminutive_singular_pt_br','diminutive_singular_en_us','diminutive_plural_pt_br','diminutive_plural_en_us','augmentative_singular_pt_br','augmentative_singular_en_us','augmentative_plural_pt_br','augmentative_plural_en_us'],
        'verbs' => ['simple_present_p1_pt_br','simple_present_p1_en_us','simple_present_p2_pt_br','simple_present_p2_en_us','simple_present_p3_pt_br','simple_present_p3_en_us','simple_present_p4_pt_br','simple_present_p4_en_us','simple_present_p5_pt_br','simple_present_p5_en_us','simple_present_p6_pt_br','simple_present_p6_en_us','simple_past_p1_pt_br','simple_past_p1_en_us','simple_past_p2_pt_br','simple_past_p2_en_us','simple_past_p3_pt_br','simple_past_p3_en_us','simple_past_p4_pt_br','simple_past_p4_en_us','simple_past_p5_pt_br','simple_past_p5_en_us','simple_past_p6_pt_br','simple_past_p6_en_us','simple_future_p1_pt_br','simple_future_p1_en_us','simple_future_p2_pt_br','simple_future_p2_en_us','simple_future_p3_pt_br','simple_future_p3_en_us','simple_future_p4_pt_br','simple_future_p4_en_us','simple_future_p5_pt_br','simple_future_p5_en_us','simple_future_p6_pt_br','simple_future_p6_en_us','present_continuous_p1_pt_br','present_continuous_p1_en_us','present_continuous_p2_pt_br','present_continuous_p2_en_us','present_continuous_p3_pt_br','present_continuous_p3_en_us','present_continuous_p4_pt_br','present_continuous_p4_en_us','present_continuous_p5_pt_br','present_continuous_p5_en_us','present_continuous_p6_pt_br','present_continuous_p6_en_us','past_continuous_p1_pt_br','past_continuous_p1_en_us','past_continuous_p2_pt_br','past_continuous_p2_en_us','past_continuous_p3_pt_br','past_continuous_p3_en_us','past_continuous_p4_pt_br','past_continuous_p4_en_us','past_continuous_p5_pt_br','past_continuous_p5_en_us','past_continuous_p6_pt_br','past_continuous_p6_en_us','future_continuous_p1_pt_br','future_continuous_p1_en_us','future_continuous_p2_pt_br','future_continuous_p2_en_us','future_continuous_p3_pt_br','future_continuous_p3_en_us','future_continuous_p4_pt_br','future_continuous_p4_en_us','future_continuous_p5_pt_br','future_continuous_p5_en_us','future_continuous_p6_pt_br','future_continuous_p6_en_us','present_perfect_p1_pt_br','present_perfect_p1_en_us','present_perfect_p2_pt_br','present_perfect_p2_en_us','present_perfect_p3_pt_br','present_perfect_p3_en_us','present_perfect_p4_pt_br','present_perfect_p4_en_us','present_perfect_p5_pt_br','present_perfect_p5_en_us','present_perfect_p6_pt_br','present_perfect_p6_en_us','past_perfect_p1_pt_br','past_perfect_p1_en_us','past_perfect_p2_pt_br','past_perfect_p2_en_us','past_perfect_p3_pt_br','past_perfect_p3_en_us','past_perfect_p4_pt_br','past_perfect_p4_en_us','past_perfect_p5_pt_br','past_perfect_p5_en_us','past_perfect_p6_pt_br','past_perfect_p6_en_us','future_perfect_p1_pt_br','future_perfect_p1_en_us','future_perfect_p2_pt_br','future_perfect_p2_en_us','future_perfect_p3_pt_br','future_perfect_p3_en_us','future_perfect_p4_pt_br','future_perfect_p4_en_us','future_perfect_p5_pt_br','future_perfect_p5_en_us','future_perfect_p6_pt_br','future_perfect_p6_en_us','present_perfect_continuous_p1_pt_br','present_perfect_continuous_p1_en_us','present_perfect_continuous_p2_pt_br','present_perfect_continuous_p2_en_us','present_perfect_continuous_p3_pt_br','present_perfect_continuous_p3_en_us','present_perfect_continuous_p4_pt_br','present_perfect_continuous_p4_en_us','present_perfect_continuous_p5_pt_br','present_perfect_continuous_p5_en_us','present_perfect_continuous_p6_pt_br','present_perfect_continuous_p6_en_us','past_perfect_continuous_p1_pt_br','past_perfect_continuous_p1_en_us','past_perfect_continuous_p2_pt_br','past_perfect_continuous_p2_en_us','past_perfect_continuous_p3_pt_br','past_perfect_continuous_p3_en_us','past_perfect_continuous_p4_pt_br','past_perfect_continuous_p4_en_us','past_perfect_continuous_p5_pt_br','past_perfect_continuous_p5_en_us','past_perfect_continuous_p6_pt_br','past_perfect_continuous_p6_en_us','future_perfect_continuous_p1_pt_br','future_perfect_continuous_p1_en_us','future_perfect_continuous_p2_pt_br','future_perfect_continuous_p2_en_us','future_perfect_continuous_p3_pt_br','future_perfect_continuous_p3_en_us','future_perfect_continuous_p4_pt_br','future_perfect_continuous_p4_en_us','future_perfect_continuous_p5_pt_br','future_perfect_continuous_p5_en_us','future_perfect_continuous_p6_pt_br','future_perfect_continuous_p6_en_us','infinitive_pt_br','infinitive_en_us','gerund_pt_br','gerund_en_us','participle_pt_br','participle_en_us'],
        'adjectives' => ['normal_singular_masculine_pt_br','normal_singular_masculine_en_us','normal_singular_feminine_pt_br','normal_singular_feminine_en_us','normal_plural_masculine_pt_br','normal_plural_masculine_en_us','normal_plural_feminine_pt_br','normal_plural_feminine_en_us','comparative_singular_masculine_pt_br','comparative_singular_masculine_en_us','comparative_singular_feminine_pt_br','comparative_singular_feminine_en_us','comparative_plural_masculine_pt_br','comparative_plural_masculine_en_us','comparative_plural_feminine_pt_br','comparative_plural_feminine_en_us','superlative_singular_masculine_pt_br','superlative_singular_masculine_en_us','superlative_singular_feminine_pt_br','superlative_singular_feminine_en_us','superlative_plural_masculine_pt_br','superlative_plural_masculine_en_us','superlative_plural_feminine_pt_br','superlative_plural_feminine_en_us','diminutive_singular_masculine_pt_br','diminutive_singular_masculine_en_us','diminutive_singular_feminine_pt_br','diminutive_singular_feminine_en_us','diminutive_plural_masculine_pt_br','diminutive_plural_masculine_en_us','diminutive_plural_feminine_pt_br','diminutive_plural_feminine_en_us','augmentative_singular_masculine_pt_br','augmentative_singular_masculine_en_us','augmentative_singular_feminine_pt_br','augmentative_singular_feminine_en_us','augmentative_plural_masculine_pt_br','augmentative_plural_masculine_en_us','augmentative_plural_feminine_pt_br','augmentative_plural_feminine_en_us'],
        'adverbs' => ['manner_normal_pt_br','manner_normal_en_us','manner_comparative_pt_br','manner_comparative_en_us','manner_superlative_pt_br','manner_superlative_en_us','manner_diminutive_pt_br','manner_diminutive_en_us','manner_augmentative_pt_br','manner_augmentative_en_us','time_normal_pt_br','time_normal_en_us','time_comparative_pt_br','time_comparative_en_us','time_superlative_pt_br','time_superlative_en_us','time_diminutive_pt_br','time_diminutive_en_us','time_augmentative_pt_br','time_augmentative_en_us','place_normal_pt_br','place_normal_en_us','place_comparative_pt_br','place_comparative_en_us','place_superlative_pt_br','place_superlative_en_us','place_diminutive_pt_br','place_diminutive_en_us','place_augmentative_pt_br','place_augmentative_en_us','intensity_normal_pt_br','intensity_normal_en_us','intensity_comparative_pt_br','intensity_comparative_en_us','intensity_superlative_pt_br','intensity_superlative_en_us','intensity_diminutive_pt_br','intensity_diminutive_en_us','intensity_augmentative_pt_br','intensity_augmentative_en_us','affirmation_normal_pt_br','affirmation_normal_en_us','affirmation_comparative_pt_br','affirmation_comparative_en_us','affirmation_superlative_pt_br','affirmation_superlative_en_us','affirmation_diminutive_pt_br','affirmation_diminutive_en_us','affirmation_augmentative_pt_br','affirmation_augmentative_en_us','negation_normal_pt_br','negation_normal_en_us','negation_comparative_pt_br','negation_comparative_en_us','negation_superlative_pt_br','negation_superlative_en_us','negation_diminutive_pt_br','negation_diminutive_en_us','negation_augmentative_pt_br','negation_augmentative_en_us','doubt_normal_pt_br','doubt_normal_en_us','doubt_comparative_pt_br','doubt_comparative_en_us','doubt_superlative_pt_br','doubt_superlative_en_us','doubt_diminutive_pt_br','doubt_diminutive_en_us','doubt_augmentative_pt_br','doubt_augmentative_en_us','frequency_normal_pt_br','frequency_normal_en_us','frequency_comparative_pt_br','frequency_comparative_en_us','frequency_superlative_pt_br','frequency_superlative_en_us','frequency_diminutive_pt_br','frequency_diminutive_en_us','frequency_augmentative_pt_br','frequency_augmentative_en_us','order_normal_pt_br','order_normal_en_us','order_comparative_pt_br','order_comparative_en_us','order_superlative_pt_br','order_superlative_en_us','order_diminutive_pt_br','order_diminutive_en_us','order_augmentative_pt_br','order_augmentative_en_us'],
        'prepositions' => ['neuter_singular_pt_br','neuter_singular_en_us','masculine_singular_pt_br','masculine_singular_en_us','feminine_singular_pt_br','feminine_singular_en_us','masculine_plural_pt_br','masculine_plural_en_us','feminine_plural_pt_br','feminine_plural_en_us'],
        'numerals' => ['numeral_value','cardinal_singular_masculine_pt_br','cardinal_singular_masculine_en_us','cardinal_singular_feminine_pt_br','cardinal_singular_feminine_en_us','cardinal_plural_masculine_pt_br','cardinal_plural_masculine_en_us','cardinal_plural_feminine_pt_br','cardinal_plural_feminine_en_us','ordinal_singular_masculine_pt_br','ordinal_singular_masculine_en_us','ordinal_singular_feminine_pt_br','ordinal_singular_feminine_en_us','ordinal_plural_masculine_pt_br','ordinal_plural_masculine_en_us','ordinal_plural_feminine_pt_br','ordinal_plural_feminine_en_us','multiplicative_singular_masculine_pt_br','multiplicative_singular_masculine_en_us','multiplicative_singular_feminine_pt_br','multiplicative_singular_feminine_en_us','multiplicative_plural_masculine_pt_br','multiplicative_plural_masculine_en_us','multiplicative_plural_feminine_pt_br','multiplicative_plural_feminine_en_us','fractional_singular_masculine_pt_br','fractional_singular_masculine_en_us','fractional_singular_feminine_pt_br','fractional_singular_feminine_en_us','fractional_plural_masculine_pt_br','fractional_plural_masculine_en_us','fractional_plural_feminine_pt_br','fractional_plural_feminine_en_us'],
        'conjunctions' => ['coordinating_additive_pt_br','coordinating_additive_en_us','coordinating_adversative_pt_br','coordinating_adversative_en_us','coordinating_alternative_pt_br','coordinating_alternative_en_us','coordinating_conclusive_pt_br','coordinating_conclusive_en_us','coordinating_explanatory_pt_br','coordinating_explanatory_en_us','subordinating_causal_pt_br','subordinating_causal_en_us','subordinating_conditional_pt_br','subordinating_conditional_en_us','subordinating_concessive_pt_br','subordinating_concessive_en_us','subordinating_temporal_pt_br','subordinating_temporal_en_us','subordinating_final_pt_br','subordinating_final_en_us','subordinating_comparative_pt_br','subordinating_comparative_en_us','subordinating_consecutive_pt_br','subordinating_consecutive_en_us','subordinating_integral_pt_br','subordinating_integral_en_us'],
    ];

    if (!isset($allowed[$table])) {
        die(json_encode(['status' => 'error', 'message' => 'Tabela inválida.']));
    }

    $supportedTables = ['nouns', 'verbs', 'prepositions', 'numerals'];
    if (!in_array($table, $supportedTables, true)) {
        die(json_encode(['status' => 'error', 'message' => 'Classe gramatical temporariamente indisponível neste formulário.']));
    }

    try {
        $stmtColumns = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmtColumns->execute([$table]);
        $existingColumns = array_column($stmtColumns->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');
        $existingColumns = array_flip($existingColumns);

        $columns = ['id_user'];
        $values = [$user_id];
        foreach ($allowed[$table] as $column) {
            if (!isset($existingColumns[$column])) continue;
            $raw = $input[$column] ?? null;
            if (is_string($raw)) $raw = trim($raw);
            if ($column === 'numeral_value' && is_string($raw)) {
                $raw = str_replace(',', '.', $raw);
            }
            if ($raw === '') $raw = null;
            if ($column === 'gender_id' && $raw === null) {
                $raw = 0;
            }
            if ($raw === null) continue;
            $columns[] = $column;
            $values[] = $raw;
        }

        if (count($columns) === 1) {
            throw new RuntimeException('Nenhuma coluna compatível encontrada para inserção em ' . $table);
        }

        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $cols = implode(',', $columns);
        $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        echo json_encode(['status' => 'success', 'message' => 'Registro adicionado com sucesso.']);
    } catch (Throwable $e) {
        error_log('[flashcards][add_grammar_entry] ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Não foi possível salvar o registro.']);
    }
}


elseif ($action === 'add_bulk') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    $cards = $input['cards'] ?? [];

    if ($deck_id === 0 || !is_array($cards) || count($cards) === 0) die(json_encode(['status' => 'error', 'message' => 'Dados inválidos.']));
    if (!verifyDeckOwnership($pdo, $deck_id, $user_id)) die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado.']));

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO flashcards (directory_id, created_by_user_id, private_directory_id, front_encrypted, back_encrypted, has_audio_front, has_audio_back) VALUES (?, ?, ?, ?, ?, 0, 0)");
        
        $count = 0;
        foreach ($cards as $card) {
            $front = trim($card['front'] ?? '');
            $back = trim($card['back'] ?? '');
            
            if (!empty($front)) {
                $front_enc = Security::encryptData($front);
                $back_enc = !empty($back) ? Security::encryptData($back) : null;
                $stmt->execute([$deck_id, $user_id, $deck_id, $front_enc, $back_enc]);
                $count++;
            }
        }
        
        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => "$count cards importados!"]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Erro interno ao importar cards.']);
    }
}
?>
